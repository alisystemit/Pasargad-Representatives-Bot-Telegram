<?php

declare(strict_types=1);

/**
 * سفرِ کامل مشتری و ادمین — از زاویهٔ کاربر.
 *
 * ## تفاوت با تست‌های دیگر
 *
 * `routing_test` می‌پرسد «آیا این دکمه خطا می‌دهد؟». این تست می‌پرسد
 * **«آیا کاربر به نتیجهٔ درست می‌رسد؟»**
 *
 * مثال: کلیک روی «🛒 خرید» که بدون خطا رد شود ولی سفارش ثبت‌نکند، در آن تست
 * سبز است و در اینجا قرمز. بیشتر باگ‌هایی که در عمل دیده می‌شوند همین‌اند —
 * نه استثنا، بلکه رفتارِ اشتباهِ بی‌سروصدا.
 *
 * ## سناریوها
 *
 *   بخش ۱ — مشتریِ نو: از `/start` تا خرید و دریافت پنل
 *   بخش ۲ — ادمین سفارش را تأیید و اجرا می‌کند
 *   بخش ۳ — مشتری پنلش را می‌بیند و شارژ می‌کند
 *   بخش ۴ — کانفیگ تست، تیکت، معرفی و بقیهٔ صفحه‌ها
 *   بخش ۵ — مرزهای امنیتی
 *   بخش ۶ — پنل مدیریت
 *   بخش ۷ — ورودیِ خراب نباید ربات را بشکند
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
use Pasargad\Store\CouponRepository;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelAccess;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestConfigRepository;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Support\Str;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

const NEWBIE   = 9500;   // مشتریِ نو
const OLDTIMER = 9501;   // مشتریِ برگشتی
const ADMIN    = 9502;   // سوپرادمین
const STRANGER = 9503;   // غریبه

Config::set('bot_token', '123456:JOURNEYTESTTOKEN0123456789abcdef');
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', [ADMIN]);
Config::set('crypto_key', 'journey-key');
Config::set('panel.api_key', 'pg_key_journey_global');

$passed  = 0;
$failed  = 0;
$failures = [];

function check(string $label, mixed $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ((bool) $ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    $failures[] = $label;

    echo "  ❌ {$label}\n";

    if ($detail !== '') {
        echo '     ↳ ' . mb_substr(str_replace("\n", ' ⏎ ', $detail), 0, 260) . "\n";
    }
}

$users    = new UserRepository();
$panels   = new PanelRepository();
$orders   = new OrderRepository();
$pkgs     = new PackageRepository();
$settings = new Settings();
$flags    = new FeatureFlags($settings);
$sessions = new SessionStore();

$panel = new FakePanelClient();
$panel->ownerUsername = 'journey_owner';
$panel->addAdmin('journey_owner', [
    'id' => 700, 'status' => 'active', 'data_limit' => 1073741824,
    'role' => ['id' => 1, 'name' => 'owner', 'is_owner' => true],
]);

$prov = new Provisioner($panel, $orders, $users, $settings, $panels);
$pay  = new PaymentService($orders, $prov, $settings, $flags, $users);

$bot    = new FakeBotApi();
$kernel = new Kernel(
    $bot, new Notifier($bot), $users, $pkgs, $orders, $prov, $pay,
    $settings, $sessions, $panel, $flags
);

// شمارنده تا هر update_id یک‌بار باشد (FloodGuard تکراری می‌گیرد).
$seq = 0;

function uid(): int
{
    global $seq;
    return ++$seq + 100000;
}

function tap(int $userId, string $text): void
{
    global $kernel;

    $kernel->handle(new Update([
        'update_id' => uid(),
        'message'   => ['message_id' => uid(), 'date' => time(),
                        'chat' => ['id' => $userId, 'type' => 'private'],
                        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'U'],
                        'text' => $text],
    ]));
}

function click(int $userId, string $ns, array $params = []): void
{
    global $kernel;

    $kernel->handle(new Update([
        'update_id' => uid(),
        'callback_query' => [
            'id' => 'cb' . uid(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'U'],
            'chat_instance' => 'ci',
            'data' => json_encode(['n' => $ns] + $params, JSON_UNESCAPED_UNICODE),
            'message' => ['message_id' => 1, 'date' => time(),
                          'chat' => ['id' => $userId, 'type' => 'private']],
        ],
    ]));
}

function text(): string
{
    global $bot;
    return $bot->allText();
}

function broken(): bool
{
    return str_contains(text(), 'خطایی رخ داد');
}

/**
 * خالی‌کردنِ جدول ضدتکرار.
 *
 * ⚠️ دلیل وجود این تابع: `FloodGuard` کلیکِ تکراریِ **همان دکمه** را در پنجرهٔ
 * کوتاه رد می‌کند. این رفتارِ **درست** است (جلوی دوبار خرید را می‌گیرد)، ولی
 * تستِ خودکار همه‌چیز را در چند میلی‌ثانیه اجرا می‌کند، پس دو مرحلهٔ متوالیِ
 * مشابه نادیده گرفته می‌شوند و تست به‌اشتباه فکر می‌کند ربات خراب است.
 *
 * یک کاربر واقعی بین دو مرحله چند ثانیه فاصله دارد.
 */
function clearFlood(): void
{
    $dbx = \Pasargad\Support\Db::instance();
    $dbx->run('DELETE FROM flood_guard');
    $dbx->run('DELETE FROM processed_updates');
}

// ---- بذرِ داده ----------------------------------------------------
//
// ⚠️ ستون فعال‌بودنِ بسته `is_active` است، نه `active`. با `active` بسته ساخته
// می‌شد ولی غیرفعال می‌ماند و فروشگاه خالی نشان داده می‌شد — یعنی تستِ
// «فروشگاه کار می‌کند» بی‌صدا شکست می‌خورد، نه واضح.
$agencyPkgId = $pkgs->create([
    'title' => 'پنل نمایندگی حرفه‌ای', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 50, 'duration_days' => 30, 'price_toman' => 900000, 'is_active' => 1,
]);
$topupPkgId = $pkgs->create([
    'title' => 'شارژ ۱۰ گیگ', 'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 10, 'duration_days' => 30, 'price_toman' => 120000, 'is_active' => 1,
]);

// یک درگاه پرداختِ فعال — بدون آن «خرید» درجا متوقف می‌شود و نیمی از سفرِ
// مشتری اصلاً آزموده نمی‌شود.
Config::set('store.card_number', '6037997512345678');
Config::set('store.card_owner', 'فروشگاه تست');
Config::set('store.card_bank', 'بانک تست');
$settings->set(Settings::GATEWAY_CARD2CARD, '1');
$settings->set(Settings::GATEWAY_NOWPAYMENTS, '0');
$settings->set(Settings::GATEWAY_AUTOCARD, '0');

if ((new CouponRepository())->findByCode('JOURNEY10') === null) {
    (new CouponRepository())->create([
        'code' => 'JOURNEY10', 'kind' => 'percent', 'value' => 10, 'max_uses' => 100,
    ]);
}

// خط پایهٔ لاگ، **قبل** از شروع سفر.
//
// ⚠️ بدون این، تست ناپایدار می‌شود: هر خطایی که تست‌های قبلی در همان فایل
// لاگ گذاشته باشند، به‌عنوان «خطای این سفر» شمرده می‌شد. فقط خط‌هایی که
// بعد از این نقطه اضافه شده‌اند بررسی می‌شوند.
$logFile = Config::str('log.path', __DIR__ . '/../data/logs') . '/' . date('Y-m-d') . '.log';
$logBaseline = is_file($logFile) ? count(@file($logFile, FILE_IGNORE_NEW_LINES) ?: []) : 0;

// ==================================================================
echo "\n▶ بخش ۱) مشتریِ نو: از /start تا انتخاب بسته\n";
// ==================================================================

tap(NEWBIE, '/start');

check('خوش‌آمدگویی داده شد', trim(text()) !== '' && !broken(), text());
check('دکمهٔ خرید در منو هست', $bot->hasButton('خرید پنل نمایندگی') || $bot->hasButton('پنل نمایندگی جدید'));
check('دکمهٔ «من پنل دارم» هست', $bot->hasButton('پنل دارم'));

$newbieId = (int) $users->findByTelegramId(NEWBIE)['id'];

check('کاربر در دیتابیس ساخته شد', $newbieId > 0);
check('کاربرِ تازه هنوز نماینده نیست', $panels->isRepresentative($newbieId) === false);

$bot->reset();
click(NEWBIE, 'shop');

check('فروشگاه باز شد', str_contains(text(), 'بسته') && !broken(), text());
check('بسته به‌صورت دکمهٔ قابل انتخاب آمد', $bot->hasButton('پنل نمایندگی حرفه‌ای'));

$bot->reset();
click(NEWBIE, 'pkg', ['id' => $agencyPkgId]);

check('صفحهٔ تأیید خرید آمد', str_contains(text(), 'تأیید خرید'), text());
check('قیمت در صفحهٔ تأیید آمد', str_contains(text(), '۹۰۰٬۰۰۰'), text());
check('دکمهٔ خرید موجود است', $bot->hasButton('خرید این بسته'));

// ==================================================================
echo "\n▶ بخش ۲) خرید بدون درگاه نباید سفارش یتیم بگذارد\n";
// ==================================================================

// ⚠️ این سناریو یک باگ واقعی را نگه می‌دارد.
//
// قبلاً سفارش ساخته می‌شد و بعد «هیچ روش پرداختی فعال نیست» داده می‌شد، و
// یک سفارش بی‌معنا در «سفارش‌های من» می‌ماند که کاربر نمی‌داند چرا.
$settings->set(Settings::GATEWAY_CARD2CARD, '0');

// ⚠️ کشِ `Settings` باید پاک شود، وگرنه ربات همچنان درگاه را فعال می‌بیند و
// خرید جلو می‌رود در حالی که تست فکر می‌کند درگاهی فعال نیست.
Settings::flush();

$ordersBefore = $orders->countByUser($newbieId);

$bot->reset();
click(NEWBIE, 'pkg.buy', ['id' => $agencyPkgId]);

check('پیامِ روشن داده شد', str_contains(text(), 'هیچ روش پرداختی فعال نیست'), text());
check('به کاربر گفته شد پولی گرفته نشده', str_contains(text(), 'پولی'), text());
check('⭐️ سفارشِ یتیم ساخته نشد', $orders->countByUser($newbieId) === $ordersBefore,
    'before=' . $ordersBefore . ' after=' . $orders->countByUser($newbieId));

$settings->set(Settings::GATEWAY_CARD2CARD, '1');
Settings::flush();
clearFlood();

// ==================================================================
echo "\n▶ بخش ۳) خرید واقعی\n";
// ==================================================================

$bot->reset();
click(NEWBIE, 'pkg.buy', ['id' => $agencyPkgId]);

check('پیامی به مشتری آمد', trim(text()) !== '', 'هیچ پیامی ارسال نشد');

$newbieOrder = $orders->listByUser($newbieId, 1)[0] ?? null;

check('سفارش ساخته شد', $newbieOrder !== null, text());
check('مبلغ درست است', (int) ($newbieOrder['price_toman'] ?? 0) === 900000,
    'price=' . (int) ($newbieOrder['price_toman'] ?? 0));
check('کد سفارش ساخته شد', str_starts_with((string) ($newbieOrder['code'] ?? ''), 'ORD-'),
    (string) ($newbieOrder['code'] ?? ''));

// وضعیتِ درستِ همین لحظه «ساخته‌شده» است؛ «در انتظار پرداخت» فقط بعد از
// انتخاب درگاه می‌شود.
check('وضعیت اولیه «ساخته‌شده» است',
    (string) ($newbieOrder['status'] ?? '') === OrderRepository::STATUS_CREATED,
    'status=' . (string) ($newbieOrder['status'] ?? ''));
check('کاربر روش‌های پرداخت را دید', str_contains(text(), 'پرداخت'), text());

$newbieOrderId = (int) $newbieOrder['id'];

// ---- انتخاب روش پرداخت ----
$bot->reset();
click(NEWBIE, 'pay', ['id' => $newbieOrderId, 'm' => 'card2card']);

check('پس از انتخاب درگاه، سفارش «در انتظار پرداخت» شد',
    (string) $orders->find($newbieOrderId)['status'] === OrderRepository::STATUS_AWAITING_PAYMENT,
    'status=' . (string) $orders->find($newbieOrderId)['status']);
check('شمارهٔ کارت به کاربر نشان داده شد', str_contains(text(), '6037997512345678'), text());

// ---- تأیید پرداخت توسط ادمین ----
$bot->reset();
click(ADMIN, 'admin.review', ['id' => $newbieOrderId, 'act' => 'approve']);

check('سفارش اجرا شد',
    (string) $orders->find($newbieOrderId)['status'] === OrderRepository::STATUS_APPLIED,
    'status=' . (string) $orders->find($newbieOrderId)['status']);

$newbiePanel = $panels->listByUser($newbieId)[0] ?? [];

check('پنل برای مشتری ساخته شد', $newbiePanel !== []);
check('نام کاربریِ پنل داده شد', (string) ($newbiePanel['panel_username'] ?? '') !== '');
check('سقف حجم روی پنل نشست', (int) ($newbiePanel['data_limit'] ?? 0) === 50 * 1073741824,
    'limit=' . (int) ($newbiePanel['data_limit'] ?? 0));
check('مدت اعتبار ثبت شد', ($newbiePanel['access_expire_at'] ?? null) !== null);
check('⭐️ برای پنل نمایندگی کلید صادر شد', PanelAccess::isApiKey($newbiePanel),
    'api_key=' . var_export($newbiePanel['api_key'] ?? null, true));
check('رمز برای تحویل ذخیره شد', strlen($panels->plainPassword((array) $newbiePanel)) >= 12);

// ==================================================================
echo "\n▶ بخش ۴) مشتری پنلش را می‌بیند\n";
// ==================================================================

$bot->reset();
click(NEWBIE, 'panel.list');

check('پنل در فهرست دیده می‌شود',
    str_contains(text(), (string) ($newbiePanel['panel_username'] ?? '')), text());
check('هشدارِ «بدون کلید» نمایش داده نمی‌شود', !str_contains(text(), 'کلید API ندارد'), text());
check('حجم در فهرست دیده می‌شود', str_contains(text(), '۵۰'), text());

$bot->reset();
click(NEWBIE, 'panel.view', ['id' => (int) $newbiePanel['id']]);

check('جزئیات باز شد', str_contains(text(), (string) $newbiePanel['panel_username']), text());
check('نام کاربری نمایش داده شد', str_contains(text(), 'نام کاربری'), text());
// ⚠️ رمز با شکلِ **escape‌شده** سنجیده می‌شود.
//
// رمز تصادفی است و حدود ۱۷٪ مواقع شامل `&` یا `<` می‌شود. تلگرام HTML را
// رندر می‌کند، پس `&amp;` روی صفحه به‌درستی `&` دیده می‌شود — یعنی نمایش سالم
// است و مقایسه با متن خام، ناپایداری می‌ساخت.
//
// (بگذرانید این نشانهٔ درستیِ escape است: اگر ربات این‌ها را خام می‌فرستاد،
// متنِ HTML خراب می‌شد.)
check('⭐️ رمزِ تحویلی به کاربر نشان داده شد',
    str_contains(text(), Str::escape($panels->plainPassword((array) $newbiePanel))),
    'escaped=' . Str::escape($panels->plainPassword((array) $newbiePanel)));
check('سقف حجم نمایش داده شد', str_contains(text(), '۵۰'), text());

$bot->reset();
click(NEWBIE, 'panel.refresh', ['id' => (int) $newbiePanel['id']]);
check('بروزرسانی پیامی داد', trim(text()) !== '' && !broken(), text());

// ==================================================================
echo "\n▶ بخش ۵) مشتری شارژ می‌کند\n";
// ==================================================================

$bot->reset();
click(NEWBIE, 'shop', ['kind' => 'topup']);
check('بسته‌های شارژ نمایش داده شد',
    str_contains(text(), 'شارژ و تمدید') && $bot->hasButton('شارژ ۱۰ گیگ'), text());

$bot->reset();
click(NEWBIE, 'pkg.buy', ['id' => $topupPkgId, 'p' => (int) $newbiePanel['id']]);

$topupOrder = $orders->listByUser($newbieId, 1)[0];
$topupOrderId = (int) $topupOrder['id'];

check('سفارش شارژ ساخته شد', $topupOrderId !== $newbieOrderId && $topupOrderId > 0);
check('سفارش شارژ به پنلِ درست لینک شد',
    (int) $topupOrder['panel_id'] === (int) $newbiePanel['id'],
    'panel_id=' . (int) $topupOrder['panel_id']);

$beforeLimit = (int) $newbiePanel['data_limit'];

$bot->reset();
click(ADMIN, 'admin.review', ['id' => $topupOrderId, 'act' => 'approve']);

$afterTopup = (array) $panels->find((int) $newbiePanel['id']);

check('⭐️ شارژ اعمال شد', (int) $afterTopup['data_limit'] === $beforeLimit + 10 * 1073741824,
    'before=' . $beforeLimit . ' after=' . (int) $afterTopup['data_limit']);
check('سفارش شارژ اجرا شد',
    (string) $orders->find($topupOrderId)['status'] === OrderRepository::STATUS_APPLIED);
check('مبلغ خریداری‌شده جمع شد', (int) $afterTopup['granted_volume'] === 60 * 1073741824,
    'granted=' . (int) $afterTopup['granted_volume']);

// ==================================================================
echo "\n▶ بخش ۶) کانفیگ تست\n";
// ==================================================================

$bot->reset();
click(NEWBIE, 'panel.test.list');
check('صفحهٔ تست کانفیگ باز شد', trim(text()) !== '' && !broken(), text());

$bot->reset();
click(NEWBIE, 'panel.test', ['id' => (int) $newbiePanel['id']]);
check('کانفیگ تست ساخته شد یا دلیلش گفته شد', !broken(), text());

$configs = (new TestConfigRepository())->listByUser($newbieId, 10);

if ($configs !== []) {
    check('یک کانفیگ در دیتابیس ثبت شد', count($configs) >= 1);

    $bot->reset();
    click(NEWBIE, 'panel.test.off', ['id' => (int) $configs[0]['id']]);
    check('غیرفعال‌کردن کانفیگ کار کرد', !broken(), text());
} else {
    check('ساخت کانفیگ نباید بی‌صدا شکست بخورد', !broken(), text());
}

// ==================================================================
echo "\n▶ بخش ۷) بقیهٔ صفحه‌های مشتری\n";
// ==================================================================

$customerPages = [
    'سفارش‌های من'     => 'order.list',
    'تاریخچه پرداخت'  => 'user.payments',
    'کیف پول'         => 'user.wallet',
    'تیکت‌های من'     => 'ticket.list',
    'ثبت تیکت جدید'   => 'ticket.new',
    'دعوت دوستان'     => 'referral.my',
    'حساب من'         => 'user.account',
    'کد تخفیف'        => 'coupon.apply',
    'کد تخفیف من'     => 'coupon.my',
    'روش پرداخت'      => 'setting.payment',
    'اعلان‌ها'        => 'setting.notifications',
    'زبان'            => 'setting.language',
    'تنظیمات'         => 'settings',
    'اتصال پنل'       => 'panel.connect',
    'خرید شارژ'      => 'shop',
];

foreach ($customerPages as $label => $ns) {
    $bot->reset();
    click(NEWBIE, $ns);

    check("صفحهٔ «{$label}» باز شد", trim(text()) !== '' && !broken(), broken() ? text() : '');
}

// ---- کد تخفیف واقعی ----
$bot->reset();
click(NEWBIE, 'coupon.apply');
tap(NEWBIE, 'JOURNEY10');

check('کد معتبر پذیرفته شد', !str_contains(text(), 'نامعتبر') && !broken(), text());

// ==================================================================
echo "\n▶ بخش ۸) مرزهای امنیتی\n";
// ==================================================================

$users->upsertByTelegram(STRANGER, ['telegram_id' => STRANGER]);

$bot->reset();
click(STRANGER, 'panel.view', ['id' => (int) $newbiePanel['id']]);
check('⭐️ غریبه پنلِ دیگری را نمی‌بیند',
    !str_contains(text(), (string) $newbiePanel['panel_username']), text());

$bot->reset();
click(STRANGER, 'order.view', ['id' => $newbieOrderId]);
check('⭐️ غریبه سفارشِ دیگری را نمی‌بیند',
    !str_contains(text(), (string) $newbieOrder['code']), text());

$bot->reset();
click(STRANGER, 'admin.orders');
check('⭐️ کاربر عادی به سفارش‌های ادمین نمی‌رسد',
    !str_contains(text(), (string) $newbieOrder['code']), text());

$bot->reset();
click(STRANGER, 'admin.home');
check('⭐️ کاربر عادی به پنل مدیریت نمی‌رسد', !str_contains(text(), '🛠'), text());

// سفارش اجراشده نباید دوباره اجرا شود حتی برای ادمین.
$panelCountBefore = count($panel->createdAdmins);

$bot->reset();
click(ADMIN, 'admin.review', ['id' => $newbieOrderId, 'act' => 'approve']);
check('⭐️ سفارش اجراشده دوباره اجرا نمی‌شود', count($panel->createdAdmins) === $panelCountBefore,
    'admins=' . count($panel->createdAdmins));

// ==================================================================
echo "\n▶ بخش ۹) پنل مدیریت\n";
// ==================================================================

$adminPages = [
    'داشبورد'     => 'admin.home',
    'سفارش‌ها'    => 'admin.orders',
    'بسته‌ها'     => 'admin.packages',
    'پنل‌ها'      => 'admin.panels',
    'کاربران'     => 'admin.users',
    'درآمد'       => 'admin.revenue',
    'تنظیمات'     => 'admin.settings',
    'کوپن‌ها'     => 'admin.coupons',
    'تیکت‌ها'     => 'admin.tickets',
    'لاگ حسابرسی' => 'admin.audit_log',
    'درگاه‌ها'    => 'admin.gateways',
    'تنظیمات پنل' => 'admin.settings.pasargad',
    'بازاریابی'   => 'admin.grow',
    'پیام همگانی' => 'admin.broadcast',
];

foreach ($adminPages as $label => $ns) {
    $bot->reset();
    click(ADMIN, $ns);

    check("صفحهٔ «{$label}» باز شد", trim(text()) !== '' && !broken(), broken() ? text() : '');
}

// ==================================================================
echo "\n▶ بخش ۱۰) ورودیِ خراب نباید ربات را بشکند\n";
// ==================================================================

$hostile = [
    'کاربر ناشناس'          => static function (): void { click(999999, 'menu'); },
    'شناسهٔ ناموجود'        => static function (): void { click(NEWBIE, 'panel.view', ['id' => 999999]); },
    'بستهٔ ناموجود'          => static function (): void { click(NEWBIE, 'pkg', ['id' => 999999]); },
    'سفارشِ ناموجود'         => static function (): void { click(NEWBIE, 'order.view', ['id' => 999999]); },
    'پیام خالی'             => static function (): void { tap(NEWBIE, ''); },
    'پیام بسیار بلند'        => static function (): void { tap(NEWBIE, str_repeat('x', 5000)); },
    'ورودیِ XSS'            => static function (): void { tap(NEWBIE, "<script>alert('x')</script>"); },
    'آیدی منفی'             => static function (): void { click(NEWBIE, 'panel.view', ['id' => -5]); },
    'آیدی صفر'              => static function (): void { click(NEWBIE, 'panel.view', ['id' => 0]); },
    'پارامترِ ناشناس'       => static function (): void { click(NEWBIE, 'pkg', ['id' => $GLOBALS['agencyPkgId'], 'p' => 'abc']); },
];

foreach ($hostile as $label => $action) {
    $bot->reset();
    $action();

    check("«{$label}» ربات را نشکست", !broken(), text());
}

// ---- XSS واقعاً خنثی شده؟ ----
$bot->reset();
tap(NEWBIE, "<script>alert('x')</script>");
check('تگِ اسکریپت به کاربر نشان داده نشد', !str_contains(text(), '<script>'), text());

// ==================================================================
echo "\n▶ بخش ۱۱) لاگِ خطا باید خالی باشد\n";
// ==================================================================
//
// یک مسیر که «پیام خطا نشان نمی‌دهد» ولی استثنا می‌اندازد، یعنی باگِ پنهان هنوز
// زنده است. پس لاگ را هم می‌سنجیم، نه فقط متنِ پیام.

$logFile = Config::str('log.path', __DIR__ . '/../data/logs') . '/' . date('Y-m-d') . '.log';

$handlerErrors = [];

if (is_file($logFile)) {
    $all = @file($logFile, FILE_IGNORE_NEW_LINES) ?: [];

    // فقط خط‌هایی که **بعد از خط پایه** اضافه شده‌اند.
    $fresh = array_slice($all, $logBaseline);

    foreach ($fresh as $line) {
        if (str_contains($line, 'Update handling failed')) {
            $handlerErrors[] = mb_substr($line, 0, 200);
        }
    }
}

check('هیچ خطای پردازشی در کل سفر نبود', $handlerErrors === [],
    implode("\n", array_slice($handlerErrors, -5)));

// ==================================================================
echo "\n" . str_repeat('═', 62) . "\n";
echo "  سفر مشتری و ادمین\n";
echo str_repeat('═', 62) . "\n";

if ($failures !== []) {
    echo "\n  موارد ناموفق:\n";

    foreach ($failures as $f) {
        echo "    ❌ {$f}\n";
    }
}

echo "\nنتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('═', 62) . "\n";

exit($failed === 0 ? 0 : 1);