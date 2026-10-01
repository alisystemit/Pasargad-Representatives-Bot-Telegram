<?php

declare(strict_types=1);

/**
 * تست سوییچ‌های فعال/غیرفعال:
 *   • کل ربات + متن دلخواهٔ غیرفعالی
 *   • درگاه‌های پرداخت (مستقل از هم)
 *   • تمدید و ابزار ساخت کاربر
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Payment\CardToCardGateway;
use Pasargad\Payment\NowPaymentsGateway;
use Pasargad\Payment\PaymentService;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
(new Migrator($db))->migrate();

// پیکربندی درگاه‌ها تا هر دو «آماده» باشند و فقط سوییچ تعیین‌کننده باشد
Config::set('store.card_number', '6037999999999999');
Config::set('nowpayments.api_key', 'test-api-key');
Config::set('super_admins', [999]);

$panel    = new FakePanelClient();
$bot      = new FakeBotApi();
$settings = new Settings($db);
$flags    = new FeatureFlags($settings);
$users    = new UserRepository($db);
$packages = new PackageRepository($db);
$orders   = new OrderRepository($db);

$provisioner = new Provisioner($panel, $orders, $users, $settings);
$payments    = new PaymentService($orders, $provisioner, $settings, $flags);

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
        echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function cb(int $userId, array $payload): Update
{
    return new Update([
        'update_id'      => random_int(1, 999999),
        'callback_query' => [
            'id'            => 'cb' . random_int(1000, 99999),
            'chat_instance' => 'ci',
            'data'          => json_encode($payload),
            'from'          => ['id' => $userId],
            'message'       => ['message_id' => random_int(1, 999), 'chat' => ['id' => $userId]],
        ],
    ]);
}

function msg(int $userId, string $text): Update
{
    return new Update([
        'update_id' => random_int(1, 999999),
        'message'   => [
            'message_id' => random_int(1, 999),
            'chat'       => ['id' => $userId],
            'from'       => ['id' => $userId],
            'text'       => $text,
        ],
    ]);
}

$ADMIN = 999;
$USER  = 1111;

$users->upsertByTelegram($ADMIN, [
    'telegram_id' => $ADMIN, 'first_name' => 'سوپرادمین',
    'panel_username' => 'superadmin', 'panel_password' => Crypto::encrypt('pass'),
    'panel_status' => 'active', 'user_credit' => 1073741824 * 100,
]);
$panel->addAdmin('superadmin', ['data_limit' => 0, 'used_traffic' => 0]);

$users->upsertByTelegram($USER, [
    'telegram_id' => $USER, 'first_name' => 'مشتری',
    'panel_username' => 'customer', 'panel_password' => Crypto::encrypt('pass'),
    'panel_status' => 'active', 'user_credit' => 1073741824 * 50,
]);
$panel->addAdmin('customer', ['data_limit' => 1073741824, 'used_traffic' => 0]);

// ------------------------------------------------------------------
echo "\n▶ مقادیر پیش‌فرض\n";
// ------------------------------------------------------------------

check('ربات به‌صورت پیش‌فرض فعال است', $flags->isBotEnabled());
check('هر دو درگاه به‌صورت پیش‌فرض فعال هستند', $flags->isGatewayEnabled(CardToCardGateway::NAME) && $flags->isGatewayEnabled(NowPaymentsGateway::NAME));
check('تمدید پیش‌فرض فعال است', $flags->isRenewalEnabled());
check('ابزار کاربر پیش‌فرض فعال است', $flags->isUserToolsEnabled());
check('متن پیش‌فرض غیرفعالی برگردانده شد', str_contains($flags->disabledNotice(), 'غیرفعال'), $flags->disabledNotice());

// ------------------------------------------------------------------
echo "\n▶ خاموش کردن کل ربات\n";
// ------------------------------------------------------------------

$flags->setBotEnabled(false);
check('ربات خاموش شد', !$flags->isBotEnabled());

$bot->reset();
$kernel->handle(msg($USER, '/start'));
check('کاربر عادی پیام غیرفعالی گرفت', str_contains($bot->allText(), 'غیرفعال'), $bot->allText());
check('ربات به کاربر عادی منو نشان نداد', !str_contains($bot->allText(), 'منوی اصلی'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'shop', 'kind' => 'panel_quota']));
check('فروشگاه برای کاربر عادی بسته شد', !str_contains($bot->allText(), 'بسته‌های حجم پنل'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'user.account']));
check('حساب کاربر هم بسته شد', !str_contains($bot->allText(), 'حساب من'), $bot->allText());

// سوپرادمین همچنان دسترسی دارد تا بتواند روشن کند
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.settings']));
check('سوپرادمین همچنان به پنل دسترسی دارد', str_contains($bot->allText(), 'تنظیمات ربات'), $bot->allText());

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.flag.toggle', 'key' => Settings::BOT_ENABLED]));
check('سوپرادمین ربات را دوباره روشن کرد', $flags->isBotEnabled());

$bot->reset();
$kernel->handle(msg($USER, '/start'));
check('کاربر دوباره به منو دسترسی دارد', str_contains($bot->allText(), 'منوی اصلی'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ متن دلخواه غیرفعالی\n";
// ------------------------------------------------------------------

$custom = "⛔️ درگاه‌های ما موقتاً به دلیل تعمیرات تعطیل است.\nساعت ۱۰ صبح باز می‌شویم.\nبا پشتیبانی تماس بگیرید: @mysupport";

$flags->setBotEnabled(false);
$flags->setDisabledNotice($custom);

$bot->reset();
$kernel->handle(msg($USER, '/start'));
check('متن دلخواه نمایش داده شد', str_contains($bot->allText(), 'تعمیرات'), $bot->allText());
check('متن دلخواه کامل ارسال شد', str_contains($bot->allText(), '@mysupport'), $bot->allText());

// متن از مسیر پنل مدیریت
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.notice.edit']));
check('درخواست ویرایش متن نمایش داده شد', str_contains($bot->allText(), 'متن غیرفعالی'), $bot->allText());
check('متن فعلی در راهنما هست', str_contains($bot->allText(), 'تعمیرات'), $bot->allText());

$bot->reset();
$kernel->handle(msg($ADMIN, 'متن تازه از پنل مدیریت'));
check('متن جدید ذخیره شد', $flags->disabledNotice() === 'متن تازه از پنل مدیریت', $flags->disabledNotice());

// لغو
$kernel->handle(cb($ADMIN, ['n' => 'admin.notice.edit']));
$bot->reset();
$kernel->handle(msg($ADMIN, '/cancel'));
check('لغو ویرایش متن کار کرد', str_contains($bot->allText(), 'لغو شد'), $bot->allText());
check('متن قبلی حفظ شد', $flags->disabledNotice() === 'متن تازه از پنل مدیریت');

// بازگردانی پیش‌فرض
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.notice.reset']));
check('بازگردانی متن پیش‌فرض کار کرد', str_contains($bot->allText(), 'پیش‌فرض بازگردانی'), $bot->allText());
check('متن به حالت پیش‌فرض برگشت', $flags->disabledNotice() === Settings::DEFAULT_DISABLED_NOTICE);

// متن خالی → پیش‌فرض
$flags->setDisabledNotice('   ');
check('متن خالی به پیش‌فرض تبدیل شد', $flags->disabledNotice() === Settings::DEFAULT_DISABLED_NOTICE);

// متن خیلی بلند
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.notice.edit']));
$kernel->handle(msg($ADMIN, str_repeat('ا', 5000)));
check('متن بیش از حد طولانی رد شد', str_contains($bot->allText(), 'طولانی'), $bot->allText());

$flags->setBotEnabled(true);

// ------------------------------------------------------------------
echo "\n▶ خاموش کردن درگاه کارت‌به‌کارت\n";
// ------------------------------------------------------------------

check('هر دو درگاه در ابتدا فعال هستند', count($payments->activeGateways()) === 2);

$flags->setGatewayEnabled(CardToCardGateway::NAME, false);
check('کارت‌به‌کارت خاموش شد', !$flags->isGatewayEnabled(CardToCardGateway::NAME));
check('ارز دیجیتال همچنان فعال است', $flags->isGatewayEnabled(NowPaymentsGateway::NAME));
check('فقط یک درگاه در فهرست فعال است', count($payments->activeGateways()) === 1);

$active = array_keys($payments->activeGateways());
check('درگاه فعال همان ارز دیجیتال است', $active === [NowPaymentsGateway::NAME], implode(',', $active));

$pkgId = $packages->create([
    'title' => 'بستهٔ تست', 'kind' => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb' => 50, 'duration_days' => 30, 'price_toman' => 300000,
    'sort_order' => 1, 'is_active' => true,
]);

$order = $orders->create((int) $users->findByTelegramId($USER)['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 50,
    'duration_days' => 30, 'price_toman' => 300000,
    'status' => OrderRepository::STATUS_CREATED,
]);

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'pay', 'id' => (int) $order['id'], 'm' => 'card2card']));
check('پرداخت با درگاه خاموش رد شد', str_contains($bot->allText(), 'غیرفعال شده'), $bot->allText());

// درگاه فعال، پیام خطای «غیرفعال شده» نمی‌دهد و به مرحلهٔ بعد می‌رود
// (در این تست کلید API واقعی نیست، پس خطای درگاه طبیعی است)
$bot->reset();
$kernel->handle(cb($USER, ['n' => 'pay', 'id' => (int) $order['id'], 'm' => 'nowpayments']));
$npText = $bot->allText();
check('درگاه فعال به مرحلهٔ پرداخت رسید', !str_contains($npText, 'غیرفعال شده'), $npText);
check('خطای درگاه (کلید نامعتبر) گزارش شد', str_contains($npText, 'ناموفق بود'), $npText);

// روشن کردن دوباره
$flags->setGatewayEnabled(CardToCardGateway::NAME, true);
check('کارت‌به‌کارت دوباره فعال شد', count($payments->activeGateways()) === 2);

// ------------------------------------------------------------------
echo "\n▶ خاموش کردن هر دو درگاه\n";
// ------------------------------------------------------------------

$flags->setGatewayEnabled(CardToCardGateway::NAME, false);
$flags->setGatewayEnabled(NowPaymentsGateway::NAME, false);
check('هیچ درگاه فعالی نماند', $payments->activeGateways() === []);

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'pkg.buy', 'id' => $pkgId]));
check('پیام مناسب وقتی درگاهی فعال نیست', str_contains($bot->allText(), 'پرداختی فعال نیست'), $bot->allText());

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.gateways']));
check('هشدار نبود درگاه فعال نمایش داده شد', str_contains($bot->allText(), 'هیچ درگاه فعالی'), $bot->allText());

// هشدار خاموش کردن آخرین درگاه
$flags->setGatewayEnabled(CardToCardGateway::NAME, true);
$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.gateway.toggle', 'name' => 'card2card']));
check('هشدار آخرین درگاه نمایش داده شد', str_contains($bot->allText(), 'هیچ روش پرداختی فعال نماند'), $bot->allText());

$flags->setGatewayEnabled(NowPaymentsGateway::NAME, true);
$flags->setGatewayEnabled(CardToCardGateway::NAME, true);

// ------------------------------------------------------------------
echo "\n▶ خاموش کردن تمدید\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'user.credit']));
check('منوی ابزار کاربر باز شد', str_contains($bot->allText(), 'ابزار کاربران'), $bot->allText());
check('دکمهٔ تمدید نمایش داده شد', $bot->hasButton('تمدید کاربر'));

$flags->setRenewalEnabled(false);
check('تمدید خاموش شد', !$flags->isRenewalEnabled());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'uc.extend']));
check('شروع تمدید مسدود شد', str_contains($bot->allText(), 'تمدید کاربر موقتاً غیرفعال'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'user.credit']));
check('در منو، تمدید غیرفعال نمایش داده شد', $bot->hasButton('تمدید (غیرفعال)'));

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'uc.new']));
check('ساخت کاربر جدید همچنان کار می‌کند', str_contains($bot->allText(), 'ساخت کاربر'), $bot->allText());

// حتی با نشست باز، مرحلهٔ بعدی هم باید متوقف شود
$sessions = new SessionStore($db);
$sessions->set($USER, ['step' => 'user_credit:username', 'mode' => 'extend']);
$bot->reset();
$kernel->handle(msg($USER, 'someuser'));
check('جریان نیمه‌کارهٔ تمدید هم متوقف شد', str_contains($bot->allText(), 'تمدید کاربر موقتاً غیرفعال'), $bot->allText());

$flags->setRenewalEnabled(true);
$bot->reset();
$kernel->handle(cb($USER, ['n' => 'uc.extend']));
check('با روشن شدن تمدید، کار می‌کند', str_contains($bot->allText(), 'تمدید کاربر'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ خاموش کردن ابزار کاربر\n";
// ------------------------------------------------------------------

$flags->setUserToolsEnabled(false);
check('ابزار کاربر خاموش شد', !$flags->isUserToolsEnabled());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'uc.new']));
check('ساخت کاربر مسدود شد', str_contains($bot->allText(), 'ابزار ساخت کاربر موقتاً غیرفعال'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'uc.extend']));
check('تمدید هم مسدود شد', str_contains($bot->allText(), 'ابزار ساخت کاربر موقتاً غیرفعال'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'user.credit']));
check('منو پیام غیرفعال بودن ابزار نشان می‌دهد', str_contains($bot->allText(), 'این ابزار موقتاً غیرفعال'), $bot->allText());

$flags->setUserToolsEnabled(true);

// ------------------------------------------------------------------
echo "\n▶ صفحهٔ تنظیمات\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.settings']));
$text = $bot->allText();
check('وضعیت کل ربات نمایش داده شد', str_contains($text, 'کل ربات'), $text);
check('وضعیت درگاه‌ها نمایش داده شد', str_contains($text, 'درگاه‌های پرداخت'), $text);
check('وضعیت تمدید نمایش داده شد', str_contains($text, 'تمدید'), $text);
check('وضعیت ابزار کاربر نمایش داده شد', str_contains($text, 'ابزار ساخت/تمدید کاربر'), $text);
check('دکمهٔ متن غیرفعالی هست', $bot->hasButton('متن غیرفعالی'));
check('دکمهٔ مدیریت درگاه‌ها هست', $bot->hasButton('مدیریت درگاه‌های پرداخت'));

// ------------------------------------------------------------------
echo "\n▶ toggle عمومی\n";
// ------------------------------------------------------------------

check('کلید نامعتبر null برمی‌گرداند', $flags->toggle('nonexistent_key') === null);
check('کلید معتبر bool برمی‌گرداند', $flags->toggle(Settings::RENEWAL_ENABLED) === false);
check('تغییر واقعاً ذخیره شد', $flags->isRenewalEnabled() === false);
$flags->toggle(Settings::RENEWAL_ENABLED);
check('تغییر برگشتی ذخیره شد', $flags->isRenewalEnabled() === true);

$summary = $flags->summary();
check('خلاصهٔ وضعیت کامل است', count($summary) === 7, implode(',', array_keys($summary)));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);