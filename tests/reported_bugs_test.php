<?php

declare(strict_types=1);

/**
 * تست انتها‌به‑انتهای سه باگ گزارش‌شده.
 *
 *   ۱) «دکمهٔ تنظیمات در ربات باگ دارد» → دکمه در منو هست ولی هیچ namespace‌ای
 *      پشتش نبود، پس کلیک فقط «این گزینه در دسترس نیست» می‌داد.
 *   ۲) «وب‌اپ خطای امضای دادهٔ ورودی نامعتبر است» → امضا در `InitData::parse()`
 *      درست بررسی می‌شود و `Api::authenticate()` فقط خروجیِ تأییدشده را می‌پذیرد.
 *   ۳) «در مدیریت، بخش پنل‌ها خالی است و راه ساخت اتصال نیست» → حالا دکمهٔ
 *      «اتصال پنل جدید» هم در حالت خالی و هم در حالت پُر نمایش داده می‌شود.
 *
 * این تست عمداً از مسیر واقعی `Kernel::handle()` عبور می‌کند (نه فراخوانی مستقیم
 * متدها) تا همان چیزی را بسنجد که کاربر واقعاً تجربه می‌کند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/Fixture.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\PanelConnectionManager;
use Pasargad\Bot\SessionStore;
use Pasargad\Payment\PaymentService;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;
use Pasargad\WebApp\InitData;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';

/**
 * کلیک روی یک دکمه (callback query).
 *
 * @param array<string, mixed> $payload
 */
function cb(int $userId, array $payload, int $msgId = 1, string $id = 'cb'): Update
{
    return new Update([
        'update_id'      => random_int(1, 999999),
        'callback_query' => [
            'id'      => $id . random_int(1000, 99999),
            'data'    => json_encode($payload),
            'from'    => ['id' => $userId],
            'message' => ['message_id' => $msgId, 'chat' => ['id' => $userId]],
     ],
    ]);
}

/**
 * ارسال پیام متنی.
 */
function msg(int $userId, string $text, int $msgId = 2): Update
{
    return new Update([
        'update_id' => random_int(1, 999999),
        'message'   => [
            'message_id' => $msgId,
'chat'       => ['id' => $userId],
            'from'       => ['id' => $userId, 'first_name' => 'رضا'],
       'text'       => $text,
    ],
    ]);
}

$db = TestDb::boot();
(new Migrator($db))->migrate();

Config::set('store.card_number', '6037999999999999');
Config::set('nowpayments.api_key', 'test-api-key');
Config::set('super_admins', [999]);
Config::set('panel.base_url', 'https://panel.example.com');

$panel     = new FakePanelClient();
$bot       = new FakeBotApi();
$settings  = new Settings($db);
$flags     = new FeatureFlags($settings);
$users     = new UserRepository($db);
$packages  = new PackageRepository($db);
$orders    = new OrderRepository($db);
$panels    = new PanelRepository($db);

$provisioner = new Provisioner($panel, $orders, $users, $settings, $panels);
$payments    = new PaymentService($orders, $provisioner, $settings, $flags, $users);

$kernel = new Kernel(
    $bot, new Notifier($bot), $users, $packages, $orders,
    $provisioner, $payments, $settings, new SessionStore($db), $panel, $flags
);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        echo "  ❌ {$label}\n";

        if ($detail !== '') {
            echo "     ↳ " . str_replace("\n", "\n       ", mb_substr($detail, 0, 300)) . "\n";
        }
    }
}

$USER  = 7001;
$ADMIN = 999;

// ------------------------------------------------------------------
echo "\n▶ باگ ۱: دکمهٔ «تنظیمات» در ربات\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(msg($USER, '/start'));

check('دکمهٔ تنظیمات در منوی اصلی هست', $bot->hasButton('تنظیمات'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'settings']));

check('کلیک روی تنظیمات صفحه را باز می‌کند', str_contains($bot->allText(), 'آیدی عددی شما'), $bot->allText());
check('پیام «در دسترس نیست» داده نشد', !str_contains($bot->allText(), 'این گزینه در دسترس نیست'), $bot->allText());
check('اطلاعات کاربر نمایش داده شد', str_contains($bot->allText(), 'پنل‌های فعال'), $bot->allText());
check('دکمهٔ بازگشت هست', $bot->hasButton('بازگشت به منو'));

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'settings.global']));

check('توضیح تنظیمات مدیریتی باز شد', str_contains($bot->allText(), 'فقط برای مدیر'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ باگ ۲: امضای دادهٔ ورودی وب‌اپ\n";
// ------------------------------------------------------------------

// یک initData معتبر با همان الگوریتمی که تلگرام استفاده می‌کند می‌سازیم.
$botToken = '123456:TEST-TOKEN-FOR-WEBAPP';
Config::set('bot_token', $botToken);

// ⚠️ `$botToken` حتماً باید در `use` بیاید؛ کلوزر به متغیر بیرونی دسترسی
// ندارد مگر آن را صریح بگیرد (وگرنه `null` می‌شود و `hash_hmac()` خطا می‌دهد).
$makeInit = static function (int $userId, array $fields = [], ?int $authDate = null) use ($botToken): string {
    $params = [
        'auth_date' => $authDate ?? time(),
        'query_id'  => 'AAE-test',
        'user'      => json_encode(['id' => $userId, 'first_name' => 'تستر', 'language_code' => 'fa'], JSON_UNESCAPED_UNICODE),
    ] + $fields;

    $pairs = $params;
    ksort($pairs, SORT_STRING);

    $check = '';
    foreach ($pairs as $k => $v) {
        $check .= $k . '=' . $v . "\n";
    }
    $check = rtrim($check, "\n");

    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash   = hash_hmac('sha256', $check, $secret);

    $parts = [];
    foreach ($pairs as $k => $v) {
        $parts[] = urlencode($k) . '=' . urlencode((string) $v);
    }
    $parts[] = 'hash=' . $hash;

    return implode('&', $parts);
};

$valid = $makeInit($USER);
$parsed = InitData::parse($valid);

check('initData معتبر پذیرفته شد', ($parsed['ok'] ?? false) === true, $parsed['message'] ?? '');
check('کاربر از initData استخراج شد', (int) ($parsed['user']['id'] ?? 0) === $USER);

// دستکاری: تغییر شناسهٔ کاربر بدون دست‌زدن به hash.
$tampered = str_replace(
    urlencode((string) $USER),
    urlencode('6666666'),
    str_replace('%7B%22id%22%3A' . $USER, '%7B%22id%22%3A6666666', $valid)
);

$bad = InitData::parse($tampered);
check('دستکاری کاربر رد می‌شود', ($bad['ok'] ?? false) === false);
check('پیام خطای امضا داده می‌شود', str_contains((string) ($bad['message'] ?? ''), 'امضای داده'), (string) ($bad['message'] ?? ''));

// امضا با توکن دیگر.
$wrongToken = hash_hmac('sha256', '999:OTHER', 'WebAppData', true);
$pairs = [
    'auth_date' => (string) time(),
    'query_id'  => 'AAE-test',
    'user'      => json_encode(['id' => $USER, 'first_name' => 'تستر'], JSON_UNESCAPED_UNICODE),
];
ksort($pairs, SORT_STRING);
$check = rtrim(implode("\n", array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($pairs), $pairs)), "\n");

$parts = [];
foreach ($pairs as $k => $v) {
    $parts[] = urlencode($k) . '=' . urlencode((string) $v);
}
$parts[] = 'hash=' . hash_hmac('sha256', $check, $wrongToken);

$wrong = InitData::parse(implode('&', $parts));
check('امضا با توکن اشتباه رد می‌شود', ($wrong['ok'] ?? false) === false);

// initData کهنه (بیش از یک روز) — نشست دائمی نباید کار کند.
$stale = InitData::parse($makeInit($USER, [], time() - 90000));
check('initData کهنه رد می‌شود', ($stale['ok'] ?? false) === false);
check('پیام انقضای نشست داده می‌شود', str_contains((string) ($stale['message'] ?? ''), 'منقضی'), (string) ($stale['message'] ?? ''));

// ورودی خالی و بی‌ربط.
check('رشتهٔ خالی رد می‌شود', (InitData::parse('')['ok'] ?? false) === false);
check('رشتهٔ بی‌ربط رد می‌شود', (InitData::parse('hello world')['ok'] ?? false) === false);
check('initData بدون hash رد می‌شود', (InitData::parse('auth_date=1&user=%7B%22id%22%3A1%7D')['ok'] ?? false) === false);

// ------------------------------------------------------------------
echo "\n▶ باگ ۳: صفحهٔ پنل‌ها در پنل مدیریت\n";
// ------------------------------------------------------------------

// حالت خالی: باید دکمهٔ ساخت اتصال وجود داشته باشد.
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.panels']));

check('صفحهٔ پنل‌ها باز شد', str_contains($bot->allText(), 'پنل'), $bot->allText());
check('دکمهٔ اتصال پنل جدید در حالت خالی هست', $bot->hasButton('اتصال پنل جدید'), $bot->allText());
check('پیام خشک «یافت نشد» نیست', !str_contains($bot->allText(), 'پنلی یافت نشد'), $bot->allText());
check('روش ساخت اتصال توضیح داده شد', str_contains($bot->allText(), 'آدرس پنل'), $bot->allText());

// جریان اتصال: منوی انتخاب نوع احراز هویت.
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'panel.connect']));

check('منوی اتصال باز شد', str_contains($bot->allText(), 'اتصال پنل'), $bot->allText());
check('گزینهٔ رمز عبور هست', $bot->hasButton('رمز عبور'));
check('گزینهٔ کلید API هست', $bot->hasButton('کلید API'));
check('توضیح رمزنگاری داده شد', str_contains($bot->allText(), 'رمزنگاری'), $bot->allText());

// شروع جریان رمز ⇒ باید نشست ساخته و آدرس پرسیده شود.
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'panel.connect.password']));

$state = (new SessionStore($db))->get($ADMIN);

// ⚠️ مسیریابیِ پیام‌های نشست در `Kernel::handleSessionState()` روی کلید
// `step` انجام می‌شود (نه `mode`)، پس همین کلید را می‌سنجیم.
check(
    'نشست اتصال ساخته شد',
    is_array($state) && ($state['step'] ?? '') === PanelConnectionManager::STEP_URL,
    json_encode($state, JSON_UNESCAPED_UNICODE)
);
check('حالت رمز عبور ثبت شد', ($state['auth_mode'] ?? '') === 'password');
check('از ادمین آدرس پنل خواسته شد', str_contains($bot->allText(), 'آدرس پنل'), $bot->allText());

// حالا یک URL بفرستیم و ببینیم مرحله جلو می‌رود.
$bot->reset();
$kernel->handle(msg($ADMIN, 'https://new-panel.example.com'));

$state = (new SessionStore($db))->get($ADMIN);
check('آدرس در نشست ذخیره شد', ($state['panel_base_url'] ?? '') === 'https://new-panel.example.com', json_encode($state, JSON_UNESCAPED_UNICODE));
check('مرحله به نام کاربری رفت', ($state['step'] ?? '') === \Pasargad\Bot\PanelConnectionManager::STEP_USERNAME);
check('از ادمین نام کاربری خواسته شد', str_contains($bot->allText(), 'نام کاربری'), $bot->allText());

// نام کاربری
$bot->reset();
$kernel->handle(msg($ADMIN, 'admin_operator'));

$state = (new SessionStore($db))->get($ADMIN);
check('نام کاربری ذخیره شد', ($state['panel_username'] ?? '') === 'admin_operator');
check('مرحله به رمز رفت', ($state['step'] ?? '') === \Pasargad\Bot\PanelConnectionManager::STEP_CREDENTIAL);

// حالت پُر: بعد از ساخت پنل، دکمه باید همچنان باشد.
$panels->upsertFromPanel(
    (int) ($users->findByTelegramId($ADMIN)['id'] ?? 0) ?: 1,
    'admin_operator',
    'pass',
    ['id' => 1, 'role' => ['name' => 'owner', 'is_owner' => true]],
    \Pasargad\Store\PanelRepository::SOURCE_SELF,
    ['base_url' => 'https://new-panel.example.com']
);

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.panels']));

check('پنل در فهرست مدیریت دیده می‌شود', $bot->hasButton('admin_operator'), $bot->allText());
check('دکمهٔ اتصال پنل جدید در حالت پُر هم هست', $bot->hasButton('اتصال پنل جدید'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ باگ ۴: دکمهٔ «من پنل دارم» برای کاربر تازه\n";
// ------------------------------------------------------------------

$NEW = 7002;

$bot->reset();
$kernel->handle(msg($NEW, '/start'));

check('کاربر تازه دکمهٔ خرید پنل را می‌بیند', $bot->hasButton('خرید پنل نمایندگی'), $bot->allText());
check('کاربر تازه دکمهٔ «من پنل دارم» را می‌بیند', $bot->hasButton('من پنل دارم'), $bot->allText());

$bot->reset();
$kernel->handle(cb($NEW, ['n' => 'panel.self']));

check('جریان «من پنل دارم» شروع شد', str_contains($bot->allText(), 'نام کاربری'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ نتیجه\n";
// ------------------------------------------------------------------

echo "══════════════════════════════════════════════════════════════\n";
echo "  باگ‌های گزارش‌شده — {$passed} موفق، {$failed} ناموفق\n";
echo "══════════════════════════════════════════════════════════════\n";

exit($failed === 0 ? 0 : 1);

