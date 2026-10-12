<?php

declare(strict_types=1);

/**
 * گزارشِ خطای داخلی — هر چیزی که خراب شود، دقیق گفته می‌شود کجا.
 *
 * ## چرا این تست مهم است
 *
 * ما به سرور و تلگرام دسترسی نداریم. تنها چیزی که از یک خطای واقعی می‌بینیم،
 * پیامی است که کاربر کپی می‌کند و برایمان می‌فرستد. بنابراین «خطایی رخ داد» برای
 * ما عملاً یعنی **حدس زدن**، و «Call to undefined method X::y()» یعنی یک جست‌وجوی
 * دقیق. تفاوتِ این دو، تمامِ چیزی است که این گزارش اضافه می‌کند.
 *
 * ## دو چیزی که این تست نباید اجازه دهد
 *
 * ۱) **نشتِ اطلاعات** — کلیدِ API، توکنِ تلگرام و رمز نباید هرگز در پیامی که به
 *    کاربر می‌رود دیده شوند، چون آن پیام برای پشتیبانی کپی و در گفتگوها دست
 *    می‌چرخد.
 * ۲) **نویز** — خطاهای عادیِ کسب‌وکاری نباید گزارشِ فنی بگیرند، وگرنه گزارشِ
 *    واقعی زیرِ ده‌ها گزارشِ الکی گم می‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\ErrorHandler;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

const VICTIM = 995001;

Config::set('bot_token', '123456:ERRORREPORTTEST0123456789abcdef');
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', []);
Config::set('crypto_key', 'err-report-key');
Config::set('panel.api_key', 'pg_key_err_report');

$passed   = 0;
$failed   = 0;
$failures = [];

function check(string $label, bool $ok, string $extra = ''): void
{
    global $passed, $failed, $failures;

    if ($ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    $failures[] = $label;

    echo "  ❌ {$label}" . ($extra !== '' ? "\n      → " . $extra : '') . "\n";
}

$users    = new UserRepository();
$panels   = new PanelRepository();
$orders   = new OrderRepository();
$pkgs     = new PackageRepository();
$settings = new Settings();
$flags    = new FeatureFlags($settings);

$panel = new FakePanelClient();
$panel->ownerUsername = 'err_owner';
$panel->addAdmin('err_owner', [
    'id' => 950, 'status' => 'active', 'data_limit' => 1024,
    'role' => ['id' => 1, 'name' => 'owner', 'is_owner' => true],
]);

$prov = new Provisioner($panel, $orders, $users, $settings, $panels);
$pay  = new PaymentService($orders, $prov, $settings, $flags, $users);

$users->upsertByTelegram(VICTIM, ['first_name' => 'Victim']);

$dbx = \Pasargad\Support\Db::instance();

echo "\n" . str_repeat('═', 62) . "\n";
echo "  گزارشِ خطای داخلی\n";
echo str_repeat('═', 62) . "\n";

// ==================================================================
echo "\n▶ ۱) متنِ گزارش چه چیزهایی دارد\n";
// ==================================================================

$boom = new ErrorException('Call to undefined method FxRate::e()', 0, E_ERROR, __FILE__, 329);
$text = ErrorHandler::diagnostic($boom, 'دکمهٔ admin.settings', '⚙️ تنظیمات');

check('سربرگ دارد', str_contains($text, 'خطای داخلی ربات'), $text);
check('بخش را می‌گوید', str_contains($text, 'admin.settings'), $text);
check('ورودی کاربر را می‌گوید', str_contains($text, '⚙️ تنظیمات'), $text);
check('علت را می‌گوید', str_contains($text, 'FxRate::e()'), $text);

// ⭐️ «محل دقیق خطا» مهم‌ترین بخش است: بدون آن فقط می‌دانیم *چه* خطایی بوده، نه
// *کجا* — و پیدا کردنِ `FxRate::e()` در یک پروژهٔ هزارخطی حدس زدن است.
check('محل دقیق خطا را می‌گوید', str_contains($text, basename(__FILE__) . ':329'), $text);

check('پیام از حدِ تلگرام رد نمی‌شود', mb_strlen($text) < 4096, 'طول=' . mb_strlen($text));

// ---- مسیرِ کاملِ فایل نباید لو برود ------------------------------
check('مسیرِ مطلقِ فایل نشت نمی‌کند', !str_contains($text, dirname(__FILE__)),
    'مسیرِ کاملِ دیتابیس/سرور داخل پیام است');
check('و فقط نامِ فایل آمده', str_contains($text, basename(__FILE__)), $text);

// ---- استک‌تریس نباید به کاربر برسد -------------------------------
check('استک‌تریس نشت نمی‌کند', !str_contains($text, '#0 '), $text);

// ==================================================================
echo "\n▶ ۲) پوشاندنِ اسرار\n";
// ==================================================================

// ⭐️ این بخش از خودِ گزارش مهم‌تر است: پیامِ خطا مستقیم به کاربر می‌رود و او
// آن را برای پشتیبانی کپی می‌کند. اگر یک کلید داخلش باشد، آن کلید عملاً منتشر
// شده است.
$secretMsg = 'INSERT failed: api_key=pg_key_super_SECRET_abc123 rejected; '
    . 'token: 99887766:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw; '
    . 'password="hunter2xyz"';

$report = ErrorHandler::diagnostic(
    new ErrorException($secretMsg, 0, E_ERROR, __FILE__, 100),
    'آزمون',
    'ورودی'
);

check('کلیدِ پنل پوشانده شد', !str_contains($report, 'pg_key_super_SECRET_abc123'),
    'کلید در پیامِ کاربر دیده شد');
check('توکنِ تلگرام پوشانده شد', !str_contains($report, 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw'),
    'توکن در پیام دیده شد');
check('رمز عبور پوشانده شد', !str_contains($report, 'hunter2xyz'), 'رمز در پیام دیده شد');

check('ولی نامِ فیلدها ماند (برای عیب‌یابی)', str_contains($report, 'api_key'),
    'نام فیلد هم پاک شد و عیب‌یابی سخت‌تر می‌شود');
check('و متنِ توضیحی سالم ماند', str_contains($report, 'INSERT failed'),
    'کلِ پیام پاک شد و اطلاعات از دست رفت');

// ---- رشته‌های تصادفیِ بلند ---------------------------------------
$long = ErrorHandler::sanitize('key material: a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0 end');
check('رشتهٔ بلندِ شبیه توکن پوشانده شد',
    !str_contains($long, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0'), $long);

// ---- چیزهای بی‌خطر نباید پاک شوند -------------------------------
$benign = ErrorHandler::sanitize('سفارش ORD-5R7956 برای کاربر 12 ثبت شد');
check('متنِ بی‌خطر دست‌نخورده ماند', $benign === 'سفارش ORD-5R7956 برای کاربر 12 ثبت شد', $benign);

// ---- پیامِ خالی ---------------------------------------------------
$empty = ErrorHandler::diagnostic(new ErrorException(''), 'بخش', 'ورودی');
check('پیامِ خالی هم گزارش می‌شود',
    str_contains($empty, 'نامعلوم') || str_contains($empty, 'ناشناخته'), $empty);

// ==================================================================
echo "\n▶ ۳) HTML و طولِ ورودیِ کاربر\n";
// ==================================================================

$evil = ErrorHandler::diagnostic(
    new ErrorException('<b>bold</b> & "quote"'),
    '<script>alert(1)</script>',
    str_repeat('خ', 900)
);

check('نامِ بخش escape می‌شود', !str_contains($evil, '<script>'), $evil);
check('و علت escape می‌شود', !str_contains($evil, '<b>bold</b>'), $evil);
check('ورودیِ خیلی بلند بریده شد', mb_strlen($evil) < 4096, 'طول=' . mb_strlen($evil));
check('و بریدن واقعاً اتفاق افتاد', !str_contains($evil, str_repeat('خ', 200)),
    'ورودی کامل کپی شده است');

check('ورودیِ خالی به‌جای خالی، «—» نشان داده می‌شود',
    str_contains(ErrorHandler::diagnostic($boom, 'بخش', ''), '—'));

// ==================================================================
echo "\n▶ ۴) متن دربارهٔ تلاش مجدد صادق است\n";
// ==================================================================

// ⭐️ نسخه‌ای که می‌گفت «آپدیت علامت‌گذاری شد، تکرار نمی‌شود» در این ربات
// **دروغ** بود: آپدیت پارک می‌شود و کرون تا ۳ بار دوباره اجرایش می‌کند. متنی
// که خلافِ رفتار باشد، کاربر را به تصمیمِ غلط می‌کشاند.
check('دربارهٔ تلاشِ مجدد حرف می‌زند', str_contains($text, 'تلاش می‌شود'), $text);
check('و ادعایِ نادرست نمی‌کند', !str_contains($text, 'تکرار بی‌جهت نمی‌شود'), $text);

// ==================================================================
echo "\n▶ ۵) گزارش واقعاً از مسیرِ ربات به کاربر می‌رسد\n";
// ==================================================================

// ⭐️ چطور خطای واقعی بسازیم بدون دستکاریِ کدِ تولید؟
//
// راهِ اول (وراثی‌کردنِ کلاس و override متد) اینجا **بسته** است: همهٔ کلاس‌ها
// `final` هستند و این عمدی است. باز کردنِ `final` فقط برای یک تست یعنی ضعیف
// کردنِ یک تصمیمِ درست.
//
// راهِ درست: خطایی بسازیم که **خودِ استقرار** آن را می‌سازد — جدولی که نیست یا
// با نسخهٔ ناسازگار عوض شده. رایج‌ترین شکلِ خطای «بعد از آپلود».
//
// و **تغییر نام** به‌جای DROP: چون `Migrator` مهاجرت‌های اجراشده را ثبت می‌کند،
// DROP کردنِ جدول با `migrate()` برنمی‌گردد — نه برای این تست، نه برای هر تستی
// که بعدش در همین فایل اجرا شود.
//
// مزیتِ دیگر: هیچ دابلی در کار نیست، پس سبز شدنِ تست یعنی **کدِ واقعی**
// گزارش را فرستاده، نه یک فیک که خودش را لو نمی‌زند.
$dbx->run('ALTER TABLE referrals RENAME TO referrals_missing');

$bot    = new FakeBotApi();
$kernel = new Kernel(
    $bot, new Notifier($bot), $users, $pkgs, $orders, $prov, $pay,
    $settings, new SessionStore(), $panel, $flags
);

$dbx->run('DELETE FROM processed_updates');
$dbx->run('DELETE FROM flood_guard');

$kernel->handle(new Update([
    'update_id' => 990501,
    'callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => VICTIM, 'is_bot' => false, 'first_name' => 'Victim'],
        'chat_instance' => 'ci',
        'data' => json_encode(['n' => 'referral.my'], JSON_UNESCAPED_UNICODE),
        'message' => ['message_id' => 1, 'date' => time(),
                      'chat' => ['id' => VICTIM, 'type' => 'private']],
    ],
]));

$sent = $bot->allText();

check('کاربر پیامِ خطا را دریافت کرد', str_contains($sent, 'خطای داخلی ربات'), $sent);
check('و نامِ دکمه را دید', str_contains($sent, 'referral.my'), $sent);
check('و علتِ واقعی را دید', str_contains($sent, 'referrals'), $sent);
check('و محل دقیق را دید',
    preg_match('/محل دقیق خطا: <code>[A-Za-z]+\.php:\d+/u', $sent) === 1, $sent);
check('و پیامِ مبهمِ قدیمی جایگزینش نشد', !str_contains($sent, 'خطایی رخ داد'), $sent);

// ⭐️ و مهم‌تر: خطای داخلی **نباید** استثنا بیرون بفرستد.
//
// تلگرام در صورتِ دریافتِ 5xx همان آپدیت را تا ابد دوباره می‌فرستد. یعنی یک
// باگِ کوچک می‌شود یک طوفانِ درخواست. رسیدنِ کد به این نقطه یعنی استثنا بلعیده
// شده است.
check('استثنا از ربات بیرون نپرید (تلگرام دوباره نمی‌فرستد)', true);

// ---- آپدیت پارک شده تا کرون تلاش کند -----------------------------
$parked = (int) $dbx->count(
    'SELECT COUNT(*) FROM webhook_dead_letters WHERE event_id = ?',
    ['990501']
);
check('آپدیت برای تلاشِ بعدی پارک شد', $parked === 1, "parked={$parked}");

// ---- جدول را سرِ جایش برگردان ---------------------------------------
$dbx->run('ALTER TABLE referrals_missing RENAME TO referrals');

$restored = (int) $dbx->count(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='referrals'"
);
check('جدول برای تست‌های بعدی برگشت', $restored === 1, "restored={$restored}");

// ==================================================================
echo "\n▶ ۶) خطای کسب‌وکاری نباید گزارشِ فنی بگیرد\n";
// ==================================================================

// ⭐️ این بخش جلوی یک آسیبِ بزرگ را می‌گیرد.
//
// اگر گزارش برای **همهٔ** استثناها فرستاده شود، خطاهای عادیِ کسب‌وکاری (پنل قطع
// است، سقف خرید پر شده، فروشگاه بسته) هم به شکلِ «خطای داخلی در Kernel.php:812»
// نمایش داده می‌شوند.
//
// آن‌وقت کاربر برای یک مشکلِ موقتی می‌نویسد «ربات خراب است» و گزارشِ واقعی
// زیرِ ده‌ها گزارشِ الکی گم می‌شود — یعنی همین ابزار نویز تولید می‌کند و خودش
// از کار می‌افتد.
$dbx->run('ALTER TABLE wallet_txns RENAME TO wallet_txns_missing');
$dbx->run('DELETE FROM processed_updates');
$dbx->run('DELETE FROM flood_guard');

$bot2    = new FakeBotApi();
$kernel2 = new Kernel(
    $bot2, new Notifier($bot2), $users, $pkgs, $orders, $prov, $pay,
    $settings, new SessionStore(), $panel, $flags
);

$kernel2->handle(new Update([
    'update_id' => 990502,
    'callback_query' => [
        'id' => 'cb2',
        'from' => ['id' => VICTIM, 'is_bot' => false, 'first_name' => 'Victim'],
        'chat_instance' => 'ci',
        'data' => json_encode(['n' => 'user.wallet'], JSON_UNESCAPED_UNICODE),
        'message' => ['message_id' => 1, 'date' => time(),
                      'chat' => ['id' => VICTIM, 'type' => 'private']],
    ],
]));

$sent2 = $bot2->allText();

check('خطای کسب‌وکاری گزارشِ فنی نمی‌گیرد',
    !str_contains($sent2, 'محل دقیق خطا'), $sent2);
check('و کاربر دست‌کم یک پیامِ قابل‌فهم گرفت', trim($sent2) !== '',
    'کاربر هیچ پیامی نگرفت');

$dbx->run('ALTER TABLE wallet_txns_missing RENAME TO wallet_txns');

// ==================================================================
echo "\n▶ ۷) هیچ جدولِ آزمایشی جا نمانده\n";
// ==================================================================

$leftovers = $dbx->all(
    "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%_missing'"
);
check('جدولِ باقی‌مانده از تست‌ها نیست', $leftovers === [],
    json_encode($leftovers, JSON_UNESCAPED_UNICODE));

echo "\n";

echo str_repeat('─', 62) . "\n";
echo "  نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

if ($failures !== []) {
    echo "\n  موارد ناموفق:\n";
    foreach ($failures as $f) {
        echo "   • {$f}\n";
    }
}

exit($failed === 0 ? 0 : 1);