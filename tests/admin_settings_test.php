<?php

declare(strict_types=1);

/**
 * تست جریان کامل «تنظیمات ادمین» — از زدن دکمه تا دیدن مقدار ذخیره‌شده.
 *
 * ## چرا این تست لازم است
 *
 * `routing_test.php` فقط کلیک می‌کند و می‌بیند صفحه‌ای باز می‌شود؛ اما
 * نمی‌فهمد مقدار **ذخیره شده** است یا نه. باگ واقعی گزارش‌شده این بود:
 * مدیر شمارهٔ کارت را می‌فرستاد، پیام «ذخیره شد» می‌دید، ولی صفحهٔ بعدی
 * هنوز «❌ تنظیم نشده» نشان می‌داد.
 *
 * پس این‌جا هر مرحله سه چیز را می‌سنجد:
 *   ۱) مقدار در دیتابیس ذخیره می‌شود
 *   ۲) صفحهٔ بعدی همان مقدار را نشان می‌دهد (بدون نیاز به ضربهٔ اضافی)
 *   ۳) ورودی نامعتبر رد می‌شود و نشست پاک نمی‌شود
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
use Pasargad\Store\BotConfig;
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

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

const ADM = 9310;

Config::set('bot_token', '123456:ADMINSETTINGSTOKEN0123456789abcdef');
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', [ADM]);
Config::set('crypto_key', 'admin-settings-test-key');
Config::set('panel.base_url', 'https://panel.example.com');
Config::set('store.card_number', '');
Config::set('store.card_owner', '');
Config::set('store.card_bank', '');

$panel    = new FakePanelClient();
$users    = new UserRepository();
$pkgs     = new PackageRepository();
$orders   = new OrderRepository();
$settings = new Settings();
$flags    = new FeatureFlags($settings);
$panels   = new PanelRepository();
$prov     = new Provisioner($panel, $orders, $users, $settings, $panels);
$pay      = new PaymentService($orders, $prov, $settings, $flags, $users);
$sessions = new SessionStore();
$bot      = new FakeBotApi();
$kernel   = new Kernel(
    $bot, new Notifier($bot), $users, $pkgs, $orders, $prov, $pay,
    $settings, $sessions, $panel, $flags
);

$users->upsertByTelegram(ADM, ['telegram_id' => ADM, 'first_name' => 'ادمین']);

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    echo "  ❌ {$label}\n";

    if ($detail !== '') {
        echo '     ↳ ' . str_replace("\n", "\n       ", mb_substr($detail, 0, 400)) . "\n";
    }
}

/**
 * کلیک روی یک دکمه به‌عنوان سوپرادمین.
 *
 * @param array<string, mixed> $extra
 */
function click(string $ns, array $extra = []): string
{
    global $kernel, $bot, $db;

    // ضداسپم بین سناریوها باقی می‌ماند و بعد از چند پیام، بقیه را می‌بلعد.
    $db->run('DELETE FROM flood_guard');
    $bot->reset();

    $kernel->handle(new Update([
        'update_id' => random_int(1, 900000),
        'callback_query' => [
            'id'            => 'cb' . random_int(1, 999999),
            'from'          => ['id' => ADM, 'is_bot' => false, 'first_name' => 'A'],
            'chat_instance' => 'ci',
            'data'          => json_encode(['n' => $ns] + $extra, JSON_UNESCAPED_UNICODE),
            'message'       => ['message_id' => 1, 'date' => time(),
                                'chat' => ['id' => ADM, 'type' => 'private']],
        ],
    ]));

    return $bot->allText();
}

function type(string $text): string
{
    global $kernel, $bot, $db;

    $db->run('DELETE FROM flood_guard');
    $bot->reset();

    $kernel->handle(new Update([
        'update_id' => random_int(1, 900000),
        'message' => [
            'message_id' => 2,
            'date'       => time(),
            'text'       => $text,
            'from'       => ['id' => ADM, 'is_bot' => false, 'first_name' => 'A'],
            'chat'       => ['id' => ADM, 'type' => 'private'],
        ],
    ]));

    return $bot->allText();
}

/** همهٔ callback_dataهای آخرین کیبوردِ ارسال‌شده. */
function lastKeyboardData(): array
{
    global $bot;

    $message = end($bot->sent);

    if (!is_array($message)) {
        return [];
    }

    $result = [];

    foreach ($message['keyboard'] as $row) {
        foreach ($row as $button) {
            $data = (string) ($button['callback_data'] ?? '');
            if ($data !== '') {
                $result[] = $data;
            }
        }
    }

    return $result;
}

// ==================================================================
echo "\n▶ ۱) کارت‌به‌کارت دستی: ذخیره و نمایش فوری\n";
// ==================================================================

$out = click('admin.settings.payment.card');
check('صفحهٔ ورود شماره کارت باز می‌شود', str_contains($out, '۱۶ رقم کارت'));

$out = type('6063731065250582');
check('شمارهٔ کارت ذخیره می‌شود', str_contains($out, 'شماره کارت ذخیره شد'));
check('مقدار در دیتابیس ثبت شده', (new BotConfig())->cardNumber() === '6063731065250582',
    'مقدار: ' . (new BotConfig())->cardNumber());
check('همان پاسخ، صفحهٔ تنظیمات با شمارهٔ ماسک‌شده را نشان می‌دهد',
    str_contains($out, '••••••••••••0582') && !str_contains($out, '6063731065250582'));

$out = click('admin.settings.payment.owner');
check('صفحهٔ ورود نام صاحب کارت باز می‌شود', str_contains($out, 'نام صاحب کارت را بفرستید'));

$out = type('علی رضایی');
check('نام صاحب کارت ذخیره می‌شود', str_contains($out, 'نام صاحب کارت ذخیره شد'));
check('صفحهٔ بعدی نام صاحب کارت را نشان می‌دهد', str_contains($out, 'علی رضایی'));

$out = click('admin.settings.payment.bank');
$out = type('بانک ملت');
check('نام بانک ذخیره می‌شود', str_contains($out, 'نام بانک ذخیره شد'));
check('صفحهٔ بعدی نام بانک را نشان می‌دهد', str_contains($out, 'بانک ملت'));

// ورودی نامعتبر نباید نشست را پاک کند و نباید ذخیره شود.
$out = click('admin.settings.payment.card');
$out = type('12345');
check('کارت کمتر از ۱۶ رقم رد می‌شود', str_contains($out, 'باید ۱۶ رقم باشد'));
check('بعد از ورودی نامعتبر، مرحلهٔ کارت باز می‌ماند',
    ($sessions->get(ADM)['step'] ?? '') === 'admin_payment_card');
check('مقدار قبلی با ورودی نامعتبر خراب نمی‌شود',
    (new BotConfig())->cardNumber() === '6063731065250582');

$out = type('/cancel');
check('/cancel داخل مرحله، نشست را می‌بندد',
    str_contains($out, 'لغو شد') && $sessions->get(ADM) === null);

// ==================================================================
echo "\n▶ ۲) /cancel بدون عملیات در جریان\n";
// ==================================================================

$out = type('/cancel');
check('/cancel بی‌مورد پاسخ محترمانه می‌گیرد', str_contains($out, 'عملیات در جریانی برای لغو وجود ندارد'),
    $out);
check('/cancel بی‌مورد «دستور ناشناخته» نمی‌گیرد', !str_contains($out, 'دستور ناشناخته'));

// ==================================================================
echo "\n▶ ۳) نقش نماینده\n";
// ==================================================================

click('admin.settings.panel.role');
$out = type('4');
check('نقش نماینده ذخیره می‌شود', (new BotConfig())->repRoleId() === 4);
check('بعد از ذخیره، منوی تنظیمات دوباره نمایش داده می‌شود', str_contains($out, 'تنظیمات Pasargad'));

// ==================================================================
echo "\n▶ ۴) آدرس پنل\n";
// ==================================================================

click('admin.settings.panel.base_url');
$out = type('https://new-panel.example.com/');
check('آدرس پنل ذخیره می‌شود', (new BotConfig())->panelBaseUrl() === 'https://new-panel.example.com');
check('پاسخ موفقیت ارسال می‌شود', str_contains($out, 'آدرس پنل به‌روزرسانی شد'));
check('بعد از ذخیره، منوی تنظیمات دوباره نمایش داده می‌شود', str_contains($out, 'تنظیمات Pasargad'));

click('admin.settings.panel.base_url');
$out = type('not-a-url');
check('آدرس نامعتبر رد می‌شود', str_contains($out, 'آدرس نامعتبر'));
check('آدرس معتبر قبلی خراب نمی‌شود',
    (new BotConfig())->panelBaseUrl() === 'https://new-panel.example.com');

// ==================================================================
echo "\n▶ ۵) کارت خودکار (API، کلید، کارت)\n";
// ==================================================================

click('admin.settings.autocard', ['step' => 'api_url']);
$out = type('https://autocard.example.com/api');
check('آدرس API کارت خودکار ذخیره می‌شود',
    (new BotConfig())->autocardApiUrl() === 'https://autocard.example.com/api');
check('صفحهٔ کارت خودکار بعد از ذخیره نمایش داده می‌شود', str_contains($out, 'کارت‌به‌کارت خودکار'));

click('admin.settings.autocard', ['step' => 'api_key']);
$out = type('autocard-secret-key');
check('کلید API کارت خودکار ذخیره و رمزگشایی می‌شود',
    (new BotConfig())->autocardApiKey() === 'autocard-secret-key');

$raw = (string) $settings->get('autocard_api_key', '');
check('کلید API در دیتابیس رمزنگاری‌شده است', str_starts_with($raw, 'v1:') && $raw !== 'autocard-secret-key',
    'raw length=' . strlen($raw));

click('admin.settings.autocard', ['step' => 'card']);
$out = type('6104337812345678');
check('شمارهٔ کارت خودکار ذخیره می‌شود',
    (new BotConfig())->autocardCardNumber() === '6104337812345678');

// ==================================================================
echo "\n▶ ۶) NowPayments\n";
// ==================================================================

click('admin.settings.nowpayments', ['step' => 'api_key']);
$out = type('np-api-key-123');
check('کلید API NowPayments ذخیره و رمزگشایی می‌شود',
    (new BotConfig())->nowpaymentsApiKey() === 'np-api-key-123');

click('admin.settings.nowpayments', ['step' => 'ipn']);
$out = type('np-ipn-secret-456');
check('IPN Secret ذخیره و رمزگشایی می‌شود',
    (new BotConfig())->nowpaymentsIpnSecret() === 'np-ipn-secret-456');
check('صفحهٔ NowPayments بعد از ذخیره نمایش داده می‌شود', str_contains($out, 'NowPayments'));

// ==================================================================
echo "\n▶ ۷) منوی تنظیمات Pasargad\n";
// ==================================================================

$out = click('admin.settings.pasargad');
check('وضعیت آدرس پنل نمایش داده می‌شود', str_contains($out, 'آدرس پنل'));
check('وضعیت کلید سازنده نمایش داده می‌شود', str_contains($out, 'کلید سازندهٔ پنل'));
check('وضعیت کارت خودکار نمایش داده می‌شود', str_contains($out, 'کارت خودکار'));
check('وضعیت کارت دستی نمایش داده می‌شود', str_contains($out, 'کارت دستی'));

// ==================================================================
echo "\n▶ ۸) صفحهٔ درگاه‌ها: شماره کارت نباید کامل نمایش داده شود\n";
// ==================================================================

$out = click('admin.gateways');
check('شمارهٔ کارت کامل در صفحهٔ درگاه‌ها دیده نمی‌شود', !str_contains($out, '6063731065250582'));
check('نسخهٔ ماسک‌شده نمایش داده می‌شود', str_contains($out, '0582'));

// ==================================================================
echo "\n▶ ۹) دکمه‌های تکراری در صفحهٔ تنظیمات حذف شده‌اند\n";
// ==================================================================

click('admin.settings');
$buttons = implode("\n", lastKeyboardData());

foreach (['admin.backup', 'admin.texts', 'admin.grow', 'admin.panels', 'admin.stats'] as $ns) {
    check('دکمهٔ تکراری ' . $ns . ' در تنظیمات نیست', !str_contains($buttons, '"n":"' . $ns . '"'));
}

check('دکمهٔ «مدیریت درگاه‌های پرداخت» سر جایش هست', str_contains($buttons, '"n":"admin.gateways"'));
check('دکمهٔ «تنظیمات Pasargad» سر جایش هست', str_contains($buttons, '"n":"admin.settings.pasargad"'));

// ==================================================================
echo "\n" . str_repeat('─', 56) . "\n";
echo 'نتیجه: ' . $passed . ' موفق، ' . $failed . " ناموفق\n";
echo str_repeat('─', 56) . "\n";

exit($failed === 0 ? 0 : 1);
