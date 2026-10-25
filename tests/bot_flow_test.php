<?php

declare(strict_types=1);

/**
 * تست جریان کامل ربات بدون تلگرام و شبکه.
 *
 * با یک FakeBotAPI، آپدیت‌های ساختگی تلگرام را از Kernel عبور می‌دهیم و
 * بررسی می‌کنیم که پیام‌ها و کیبوردها درست ساخته شده و کار از کاربر تازه تا
 * نماینده شدن و دیدن پنل کامل است.
 *
 * دو مسیر متفاوت پوشش داده می‌شود:
 *   الف) کاربر تازه **پنل می‌خرد** (بدون نیاز به اتصال قبلی).
 *   ب) ادمینی که از قبل پنل دارد با «من پنل دارم» اضافه می‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/Fixture.php';

use Pasargad\Bot\Kernel;
use Pasargad\Panel\FakePanelClient;
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

$db = TestDb::boot();
(new Migrator($db))->migrate();

// اکانت سازندهٔ پنل را فعال می‌کنیم تا بتوان پنل نمایندگی ساخت.
Config::set('panel.api_key', 'pg_key_test_global');
Config::set('store.card_number', '6037997512345678');
Config::set('store.card_owner', 'علی رضایی');

$panel    = new FakePanelClient();
$bot      = new FakeBotApi();
$settings = new Settings($db);
$packages = new PackageRepository($db);
$orders   = new OrderRepository($db);
$users    = new UserRepository($db);
$panels   = new PanelRepository($db);

$provisioner = new Provisioner($panel, $orders, $users, $settings, $panels);
$sessions    = new \Pasargad\Bot\SessionStore($db);
$payments    = new \Pasargad\Payment\PaymentService($orders, $provisioner, $settings);

$kernel = new Kernel(
    $bot,
    new \Pasargad\Bot\Notifier($bot),
    $users,
    $packages,
    $orders,
    $provisioner,
    $payments,
    $settings,
    $sessions,
    $panel,   // پنل قلابی تا تست شبکه نزند
    null,
    $panels
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
        echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/**
 * کلیک روی یک دکمه (callback query).
 */
function cb(int $userId, array $payload, int $msgId = 1, string $id = 'cb'): Update
{
    return new Update([
        'update_id'      => random_int(1, 999999),
        'callback_query' => [
            'id'       => $id . random_int(1000, 99999),
            'data'     => json_encode($payload),
            'from'     => ['id' => $userId],
            'message'  => ['message_id' => $msgId, 'chat' => ['id' => $userId]],
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

// ------------------------------------------------------------------
echo "\n▶ آماده‌سازی\n";
// ------------------------------------------------------------------

$settings->set('auto_apply', '1');
$settings->set('shop_opened', '1');

$topupId = $packages->create([
    'title'         => 'شارژ ۱۰۰ گیگ',
    'kind'          => PackageRepository::KIND_TOPUP,
    'volume_gb'     => 100,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'sort_order'    => 1,
    'is_active'     => true,
]);

$agencyId = $packages->create([
    'title'         => 'پنل نمایندگی ۵۰ گیگ',
    'kind'          => PackageRepository::KIND_AGENCY,
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 900000,
    'sort_order'    => 2,
    'is_active'     => true,
]);

check('بستهٔ شارژ ساخته شد', $topupId > 0);
check('بستهٔ پنل نمایندگی ساخته شد', $agencyId > 0);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱: کاربر تازه بدون هیچ پنلی\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(msg(5000, '/start'));

check('کاربر در دیتابیس ثبت شد', $users->findByTelegramId(5000) !== null);
check('پیام خوش‌آمد ارسال شد', str_contains($bot->allText(), 'خوش آمدید'), $bot->allText());
check('دکمهٔ خرید پنل نمایش داده شد', $bot->hasButton('خرید پنل نمایندگی'));
check('دکمهٔ «من پنل دارم» نمایش داده شد', $bot->hasButton('من پنل دارم'));

$newUser = $users->findByTelegramId(5000);
check('هنوز پنلی ندارد', $panels->countByUser((int) $newUser['id']) === 0);

// نکتهٔ کلیدی تغییر معماری: خرید پنل **نیازی به اتصال قبلی ندارد».
$bot->reset();
$kernel->handle(cb(5000, ['n' => 'shop', 'kind' => PackageRepository::KIND_AGENCY]));

check('فروشگاه پنل نمایندگی باز شد', str_contains($bot->lastText(), 'پنل نمایندگی'), $bot->lastText());
check('بستهٔ پنل در فهرست هست', $bot->hasButton('پنل نمایندگی ۵۰ گیگ'));

// شارژ برای کسی که پنل ندارد باید راهنمایی کند
$bot->reset();
$kernel->handle(cb(5000, ['n' => 'shop', 'kind' => PackageRepository::KIND_TOPUP]));
check('شارژ بدون پنل راهنمایی می‌شود', str_contains($bot->lastText(), 'پنل نمایندگی داشته باشید'), $bot->lastText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۲: «من پنل دارم» برای ادمینی که نماینده نیست\n";
// ------------------------------------------------------------------

$panel->addAdmin('existing_admin', ['data_limit' => 200 * 1073741824, 'used_traffic' => 20 * 1073741824]);

// کلید باید صریحاً به این ادمین نسبت داده شود، وگرنه فیک اولین ادمین را
// برمی‌گرداند و جریان روی پنل اشتباه ثبت می‌شود — همان رفتاری که روی پنل
// واقعی با هر کلیدِ متفاوت اتفاق می‌افتد.
$panel->apiKeyUsername = 'existing_admin';
// فقط این کلید معتبر است، تا «کلید اشتباه» واقعاً اشتباه باشد و مسیر خطا
// اجرا شود — وگرنه هر رشته‌ای کلید درست شمرده می‌شد.
$panel->validApiKeys = ['pg_key_existing'];

$bot->reset();
$kernel->handle(cb(6001, ['n' => 'panel.self']));
check('جریان «من پنل دارم» شروع شد', str_contains($bot->lastText(), 'آدرس پنل'), $bot->lastText());
check('نام کاربری دیگر پرسیده نمی‌شود', !str_contains($bot->allText(), 'نام کاربری'), $bot->allText());

$bot->reset();
$kernel->handle(msg(6001, 'https://existing.example.com'));
check('درخواست کلید داده شد', str_contains($bot->lastText(), 'کلید API'), $bot->lastText());

$bot->reset();
$kernel->handle(msg(6001, 'pg_key_existing'));
check('ثبت پنل موفق گزارش شد', str_contains($bot->allText(), 'با موفقیت وصل شد'), $bot->allText());

$selfUser  = $users->findByTelegramId(6001);
$selfPanel = $panels->findByPanelUsername('existing_admin');

check('پنل در ربات ثبت شد', $selfPanel !== null);
check('پنل به کاربر درست لینک شد', (int) $selfPanel['user_id'] === (int) $selfUser['id']);
check('منبع پنل «ثبت دستی» است', $selfPanel['source'] === PanelRepository::SOURCE_SELF);
check('حجم پنل از API خوانده شد', (int) $selfPanel['data_limit'] === 200 * 1073741824);
check(
    'کلید قابل بازیابی است',
    (new \Pasargad\Store\PanelAccess($panels))->apiKeyOf($selfPanel) === 'pg_key_existing'
);
check('کلید خام در دیتابیس نیست',
    !str_contains((string) $selfPanel['api_key'], 'pg_key_existing'));
check('هیچ رمز عبوری ذخیره نشد', (string) $selfPanel['panel_password'] === '');
check('اطلاعات کامل پنل به کاربر نشان داده شد',
    str_contains($bot->allText(), 'آدرس پنل')
    && str_contains($bot->allText(), 'نام کاربری')
    && str_contains($bot->allText(), 'کلید API'), $bot->allText());

// FloodGuard پیام‌های *کاملاً یکسان* را در پنجرهٔ چندثانیه‌ای رد می‌کند و در
// این تست همهٔ پیام‌ها در چند میلی‌ثانیه رخ می‌دهند. کاربر واقعی بعد از چند
// ثانیه دوباره همان متن را می‌فرستد، پس اینجا فقط جدول ضدتکرار خالی می‌شود تا
// «گذر زمان» شبیه‌سازی شود.
$db->run('DELETE FROM flood_guard');

// ⚠️ شمارهٔ پیام‌ها باید تازه باشد.
//
// `FloodGuard::isDuplicateUpdate()` روی **همان update_id + همان اثر انگشت**
// تکراری می‌گیرد. پیش‌فرضِ توابع `cb()` و `msg()` عدد ثابتی است، پس جریان
// دوم همان عدد را با همان محتوا می‌فرستد و ربات آن را «تلگرام دوباره فرستاده»
// رد می‌کند — در حالی که در واقع کاربر تازه‌ای دکمه زده است.
$bot->reset();
$kernel->handle(cb(6001, ['n' => 'panel.self'], 101));
$kernel->handle(msg(6001, 'https://existing.example.com', 102));
$kernel->handle(msg(6001, 'pg_key_wrong_one', 103));
check('ورودی نامعتبر در جریان ثبت پنل رد شد', str_contains($bot->allText(), 'برقرار نشد'), $bot->allText());

// پنل تکراری نباید ساخته شود
check('پنل تکراری نساخته شد', count($panels->listByUser((int) $selfUser['id'])) === 1);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۳: خرید پنل نمایندگی توسط کاربر تازه\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'pkg', 'id' => $agencyId]));
check('جزئیات بستهٔ پنل نمایش داده شد', str_contains($bot->lastText(), 'تأیید خرید'), $bot->lastText());
check('قیمت درست نمایش داده شد', str_contains($bot->allText(), '۹۰۰٬۰۰۰'), $bot->allText());
check('دکمهٔ خرید موجود است', $bot->hasButton('خرید این بسته'));

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'pkg.buy', 'id' => $agencyId]));

$buyer = $users->findByTelegramId(5000);
check('سفارش ساخته شد', $orders->countByUser((int) $buyer['id']) === 1);
$order = $orders->listByUser((int) $buyer['id'], 1)[0];
check('کد سفارش تولید شد', str_starts_with((string) $order['code'], 'ORD-'));
check('نوع سفارش «پنل نمایندگی» است', (string) $order['kind'] === PackageRepository::KIND_AGENCY);
check('منوی روش پرداخت نمایش داده شد', str_contains($bot->lastText(), 'انتخاب روش پرداخت'), $bot->lastText());
check('گزینهٔ کارت‌به‌کارت موجود است', $bot->hasButton('کارت‌به‌کارت'));

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۴: پرداخت کارت‌به‌کارت\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'pay', 'id' => (int) $order['id'], 'm' => 'card2card']));

check('راهنمای کارت‌به‌کارت نمایش داده شد', str_contains($bot->lastText(), 'شماره کارت'), $bot->lastText());
check('شمارهٔ کارت در پیام هست', str_contains($bot->lastText(), '6037997512345678'), $bot->lastText());
check('دکمهٔ بررسی وضعیت موجود است', $bot->hasButton('بررسی وضعیت'));

$order = $orders->find((int) $order['id']);
check('سفارش awaiting_payment شد', (string) $order['status'] === OrderRepository::STATUS_AWAITING_PAYMENT);
check('روش پرداخت ثبت شد', (string) $order['payment_method'] === 'card2card');

$bot->reset();
$kernel->handle(new Update([
    'update_id' => random_int(1, 999999),
    'message'   => [
        'message_id' => 14, 'chat' => ['id' => 5000], 'from' => ['id' => 5000],
        'photo' => [['file_id' => 'PHOTO_FILE_ID', 'file_unique_id' => 'u1', 'file_size' => 100000]],
    ],
]));

check('پیام تأیید رسید به کاربر داده شد', str_contains($bot->lastText(), 'رسید شما ثبت شد'), $bot->lastText());
$order = $orders->find((int) $order['id']);
check('رسید ذخیره شد', $order['receipt_file_id'] === 'PHOTO_FILE_ID');
check('رسید برای ادمین ارسال شد', $bot->adminGotPhoto(), 'photos=' . count($bot->sentPhotos));
check('پیام تأیید به ادمین رفت', str_contains($bot->allTextWithCaptions(), 'رسید جدید'), $bot->allTextWithCaptions());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۵: تأیید سوپرادمین و ساخت خودکار پنل\n";
// ------------------------------------------------------------------

Config::set('super_admins', [777]);

$bot->reset();
$kernel->handle(cb(777, ['n' => 'admin.review', 'id' => (int) $order['id'], 'act' => 'approve']));

$order = $orders->find((int) $order['id']);
check('سفارش applied شد', (string) $order['status'] === OrderRepository::STATUS_APPLIED, 'status=' . $order['status']);
check('حساب ادمین روی پنل ساخته شد', count($panel->createdAdmins) === 1,
    'created=' . count($panel->createdAdmins));

$newPanel = $panels->find((int) $order['panel_id']);
check('پنل به سفارش لینک شد', $newPanel !== null);
check('پنل به کاربر خریدار لینک شد', (int) $newPanel['user_id'] === (int) $buyer['id']);
check('نقش اپراتور ثبت شد', ($newPanel['panel_role'] ?? '') !== '',
    'role=' . (string) ($newPanel['panel_role'] ?? 'NULL'));
check('سقف حجم روی پنل اعمال شد', (int) $newPanel['data_limit'] === 50 * 1073741824);
check('انقضا ثبت شد', $newPanel['access_expire_at'] !== null);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۶: مشاهدهٔ پنل با اطلاعات کامل\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'panel.list']));
check('فهرست پنل‌ها نمایش داده شد', str_contains($bot->lastText(), 'پنل‌های من'), $bot->lastText());
check('نام کاربری پنل دیده می‌شود', str_contains($bot->allText(), (string) $newPanel['panel_username']));
check('دکمهٔ جزئیات پنل هست', $bot->hasButton('🖥'));

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'panel.view', 'id' => (int) $newPanel['id']]));

$detailText = $bot->lastText();
check('صفحهٔ جزئیات باز شد', str_contains($detailText, 'پنل نمایندگی'), $detailText);
check('آدرس پنل نمایش داده شد', str_contains($detailText, 'آدرس پنل'), $detailText);
check('نام کاربری نمایش داده شد', str_contains($detailText, 'نام کاربری'), $detailText);
check('رمز عبور نمایش داده شد', str_contains($detailText, 'رمز عبور'), $detailText);
check('حجم نمایش داده شد', str_contains($detailText, 'حجم کل'), $detailText);
check('اعتبار زمانی نمایش داده شد', str_contains($detailText, 'انقضا'), $detailText);
check('دکمهٔ تست کانفیگ هست', $bot->hasButton('تست کانفیگ'));
check('دکمهٔ شارژ هست', $bot->hasButton('شارژ/تمدید'));

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۷: دریافت تست کانفیگ\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'panel.test', 'id' => (int) $newPanel['id']]));
check('کانفیگ تست ساخته شد', str_contains($bot->allText(), 'کانفیگ تست ساخته شد'), $bot->allText());
check('نام کاربری تست داده شد', str_contains($bot->allText(), 't_' . (string) $newPanel['panel_username']));
check('دکمهٔ غیرفعال کردن تست هست', $bot->hasButton('غیرفعال کردن تست'));

$testConfigs = (new \Pasargad\Store\TestConfigRepository($db))->listByUser((int) $buyer['id']);
check('یک کانفیگ تست ثبت شد', count($testConfigs) === 1);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۸: شارژ یکی از پنل‌ها\n";
// ------------------------------------------------------------------

// کاربر حالا دو پنل دارد: rep9 ندارد، ولی پنل تازهٔ خریداری‌شده را دارد.
// برای آزمودن انتخاب پنل، یک پنل دوم هم می‌سازیم.
// کلید لازم است: شارژ با کلیدِ همین ردیف انجام می‌شود، و کلید هم به همین
// ادمین وصل است (عین پنل واقعی) وگرنه سقفِ پنل اشتباهی تغییر می‌کند.
$secondPanelId = $panels->create((int) $buyer['id'], 'second_panel', '', [
    'panel_status' => PanelRepository::STATUS_ACTIVE,
    'data_limit'   => 10 * 1073741824,
    'api_key'      => 'pg_key_second_panel',
]);
$panel->bindApiKey('pg_key_second_panel', 'second_panel');
$panel->addAdmin('second_panel', ['data_limit' => 10 * 1073741824, 'used_traffic' => 0]);
check('کاربر حالا ۲ پنل دارد', $panels->countByUser((int) $buyer['id']) === 2);

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'pkg', 'id' => $topupId]));
check('انتخاب پنل مقصد خواسته شد', str_contains($bot->lastText(), 'کدام پنل را شارژ'), $bot->lastText());

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'pkg', 'id' => $topupId, 'p' => $secondPanelId]));
check('پنل مقصد در تأیید خرید آمد', str_contains($bot->allText(), 'second_panel'), $bot->allText());

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'pkg.buy', 'id' => $topupId, 'p' => $secondPanelId]));

$topupOrder = $orders->listByUser((int) $buyer['id'], 1)[0];
check('سفارش شارژ به پنل درست لینک شد', (int) $topupOrder['panel_id'] === $secondPanelId,
    'panel_id=' . var_export($topupOrder['panel_id'], true));

$kernel->handle(cb(777, ['n' => 'admin.review', 'id' => (int) $topupOrder['id'], 'act' => 'approve']));

$secondAfter = $panels->find($secondPanelId);
$firstAfter  = $panels->find((int) $newPanel['id']);

check('پنل مقصد شارژ شد', (int) $secondAfter['data_limit'] === 110 * 1073741824,
    'limit=' . $secondAfter['data_limit']);
check('پنل دیگر دست‌نخورده ماند', (int) $firstAfter['data_limit'] === 50 * 1073741824,
    'other=' . $firstAfter['data_limit']);

// ------------------------------------------------------------------
echo "\n▶ سناریؤ ارتقا: پنلِ قدیمی که فقط رمز دارد\n";
// ------------------------------------------------------------------
//
// با حذف رمز، هر نمایندهٔ قبلی پنلی دارد که `api_key` ندارد. آن پنل دیگر
// شارژ/آمار/قطع‌دسترسی نمی‌شود، ولی نباید بی‌صدا از کار بیفتد: باید دقیقاً
// بگوید چه شده و چه کند.
//
// این سناریو برای **همهٔ** کاربرانِ فعلی رخ می‌دهد، پس باید صریح آزموده شود.

$legacyUid = (int) $users->upsertByTelegram(6105, ['telegram_id' => 6105])['id'];

$panel->addAdmin('legacy_rep', ['data_limit' => 50 * 1073741824, 'used_traffic' => 5 * 1073741824]);

// ⚠️ دقیقاً وضعیتِ ردیفِ قدیمی: رمز دارد، کلید ندارد.
$panels->create($legacyUid, 'legacy_rep', 'old-password', [
    'panel_status' => PanelRepository::STATUS_ACTIVE,
    'data_limit'   => 50 * 1073741824,
    'login_url'    => 'https://legacy.example.com',
]);

$bot->reset();
$kernel->handle(cb(6105, ['n' => 'panel.list']));
$legacyListText = $bot->allText();

check('پنل قدیمی در فهرست دیده می‌شود', str_contains($legacyListText, 'legacy_rep'), $legacyListText);
check('به کاربر گفته می‌شود کلید ندارد', str_contains($legacyListText, 'کلید API ندارد'), $legacyListText);
check('راه‌حل گفته می‌شود (ساخت کلید از داخل پنل)',
    str_contains($legacyListText, 'API Keys'), $legacyListText);
check('دکمهٔ وصل‌کردن کلید هست', $bot->hasButton('وصل کردن کلید'), $legacyListText);
check('نگرانیِ مالی بی‌مورد ایجاد نمی‌شود',
    str_contains($legacyListText, 'هیچ پولی از شما کسر نمی‌شود'), $legacyListText);

// و مسیر شارژ هم باید **شفاف** شکست بخورد، نه مبهم.
$legacyPanel = $panels->findByPanelUsername('legacy_rep');
// و مسیر شارژ هم باید **شفاف** شکست بخورد، نه مبهم.
//
// ⚠️ `package_id` باید واقعی باشد: `orders.package_id` کلیدِ خارجی دارد و
// `0` باعث خطای SQLite می‌شد — یعنی تست هرگز به مسیرِ موردنظر نمی‌رسید.
$topupPkg = (new PackageRepository())->create([
    'title' => 'شارژ ۱۰ گیگ', 'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 10, 'duration_days' => 30, 'price_toman' => 100000, 'active' => 1,
]);

$legacyOrder = $orders->create($legacyUid, [
    'package_id' => $topupPkg, 'package_title' => 'شارژ ۱۰ گیگ',
    'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 10.0, 'duration_days' => 30, 'price_toman' => 100000,
    'panel_id'  => (int) $legacyPanel['id'],
]);
$orders->markPaid((int) $legacyOrder['id'], 'card2card', 'ref');

$legacyTopup = $provisioner->provision($orders->find((int) $legacyOrder['id']));

check('شارژ پنلِ بدون‌کلید ناموفق است', ($legacyTopup['ok'] ?? false) === false,
    (string) ($legacyTopup['message'] ?? ''));
check('پیامش می‌گوید کلید ندارد، نه «رمز اشتباه»',
    str_contains((string) ($legacyTopup['message'] ?? ''), 'کلید API'),
    (string) ($legacyTopup['message'] ?? ''));
check('پنل باطل‌شده علامت نخورد',
    (string) $panels->find((int) $legacyPanel['id'])['panel_status'] === PanelRepository::STATUS_ACTIVE,
    'status=' . (string) $panels->find((int) $legacyPanel['id'])['panel_status']);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۹: دسترسی کاربر دیگر به پنل و سفارش\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(9999, ['n' => 'panel.view', 'id' => (int) $newPanel['id']]));
check('کاربر دیگر پنل را نمی‌بیند', str_contains($bot->lastText(), 'پیدا نشد'), $bot->lastText());

$bot->reset();
$kernel->handle(cb(9999, ['n' => 'order.view', 'id' => (int) $order['id']]));
check('کاربر دیگر سفارش را نمی‌بیند', str_contains($bot->lastText(), 'پیدا نشد'), $bot->lastText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱۰: قواعد و دکمهٔ مدیریت پنل‌ها\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(5000, ['n' => 'rules']));
check('صفحهٔ قوانین نمایش داده شد', str_contains($bot->allText(), 'قوانین'), $bot->allText());

$bot->reset();
$kernel->handle(cb(777, ['n' => 'admin.panels']));
check('مدیر فهرست پنل‌ها را می‌بیند', str_contains($bot->allText(), 'پنل‌های نمایندگی'), $bot->allText());
check('نام پنل‌ها در فهرست ادمین هست',
    str_contains($bot->allText(), (string) $newPanel['panel_username']));

$bot->reset();
$kernel->handle(cb(777, ['n' => 'admin.panel.view', 'id' => (int) $newPanel['id']]));
check('جزئیات پنل برای ادمین باز شد', str_contains($bot->allText(), 'نماینده'), $bot->allText());
check('رمز پنل برای ادمین هم هست', str_contains($bot->allText(), '🔒 رمز'));
check('یوزر پنل کنار رمز برای کپی هست',
    str_contains($bot->allText(), '👤 یوزر: <code>' . (string) $newPanel['panel_username'] . '</code>'),
    $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱۱: مسدودسازی کاربر\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(777, ['n' => 'admin.user.block', 'id' => (int) $buyer['id']]));
check('کاربر مسدود شد', (int) $users->findByTelegramId(5000)['is_blocked'] === 1);

$bot->reset();
$kernel->handle(msg(5000, '/shop'));
check('کاربر مسدود پیام مسدودیت گرفت', str_contains($bot->lastText(), 'مسدود است'), $bot->lastText());

$bot->reset();
$kernel->handle(cb(777, ['n' => 'admin.user.block', 'id' => (int) $buyer['id']]));
check('رفع مسدودی کار کرد', (int) $users->findByTelegramId(5000)['is_blocked'] === 0);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱۲: ساخت بسته با پیام متنی\n";
// ------------------------------------------------------------------

$beforeCount = count($packages->allPackages(false));

$bot->reset();
$kernel->handle(msg(777, 'پنل ویژه تابستان | agency | 300 | 45 | 1500000'));
check('بسته از طریق پیام ساخته شد', str_contains($bot->allText(), 'ساخته شد'), $bot->allText());
check('تعداد بسته‌ها یکی اضافه شد', count($packages->allPackages(false)) === $beforeCount + 1);

$newPkg = null;
foreach ($packages->allPackages(false) as $p) {
    if (str_contains((string) $p['title'], 'تابستان')) {
        $newPkg = $p;
    }
}

check('بستهٔ جدید با مقادیر درست',
    $newPkg !== null
    && (float) $newPkg['volume_gb'] === 300.0
    && (int) $newPkg['price_toman'] === 1500000
    && (int) $newPkg['duration_days'] === 45);
check('نوع بسته «پنل نمایندگی» تشخیص داده شد',
    ($newPkg['kind'] ?? '') === PackageRepository::KIND_AGENCY,
    'kind=' . ($newPkg['kind'] ?? 'NULL'));

// نام فارسی نوع هم پذیرفته می‌شود
$bot->reset();
$kernel->handle(msg(777, 'بستهٔ شارژ نوروز | شارژ | 200 | 30 | 900000'));
check('نام فارسی نوع پذیرفته شد', str_contains($bot->allText(), 'ساخته شد'), $bot->allText());

// ورودی نامعتبر
$bot->reset();
$kernel->handle(msg(777, 'بسته بد | نوع_ناموجود | 100 | 30 | 500000'));
check('ورودی نامعتبر رد شد', str_contains($bot->lastText(), 'فرمت ورودی نامعتبر'), $bot->lastText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱۳: صف خودکار وقتی اجرای خودکار خاموش است\n";
// ------------------------------------------------------------------

$settings->set('auto_apply', '0');

$queueOrder = $orders->create((int) $buyer['id'], [
    'package_id'    => $topupId,
    'package_title' => 'شارژ ۱۰۰ گیگ',
    'kind'          => PackageRepository::KIND_TOPUP,
    'volume_gb'     => 100,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'panel_id'      => $secondPanelId,
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$result = $payments->applyAfterPayment((int) $queueOrder['id']);
check('با اجرای خودکار خاموش، بسته اجرا نشد', str_contains((string) $result['message'], 'غیرفعال'), (string) $result['message']);
check('سفارش در حالت paid باقی ماند', (string) $orders->find((int) $queueOrder['id'])['status'] === OrderRepository::STATUS_PAID);

$settings->set('auto_apply', '1');
$provisioner->processQueue(5);

$after = $orders->find((int) $queueOrder['id']);
check('کرون بستهٔ معلق را اجرا کرد', (string) $after['status'] === OrderRepository::STATUS_APPLIED, 'status=' . $after['status']);
check('حجم روی پنل مقصد رفت', (int) $panels->find($secondPanelId)['data_limit'] === 210 * 1073741824,
    'limit=' . $panels->find($secondPanelId)['data_limit']);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);