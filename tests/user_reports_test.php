<?php

declare(strict_types=1);

/**
 * تست یکپارچهٔ سه مسیری که گزارش شده:
 *   ۱) دکمهٔ «⚙️ تنظیمات» در منوی اصلی
 *   ۲) احراز هویت وب‌اپ (امضای initData)
 *   ۳) «اتصال پنل جدید» از پنل مدیریت (آدرس ← نام کاربری ← رمز/API Key)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Bot\Kernel;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Support\Config;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;
use Pasargad\WebApp\InitData;

$db = TestDb::boot();
Settings::flush();
(new Pasargad\Support\Migrator($db))->migrate();

const TOKEN = '123456:PROBE-TOKEN';

Config::set('bot_token', TOKEN);
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', [999]);
Config::set('crypto_key', 'probe-key');
Config::set('panel.base_url', 'https://panel.example.com');

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

function cb(int $tg, array $payload): Update
{
    return new Update([
        'update_id'      => 1,
        'callback_query' => [
            'id'      => 'cb1',
            'from'    => ['id' => $tg],
            'chat_instance' => 'x',
            'data'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'message' => ['message_id' => 5, 'chat' => ['id' => $tg]],
        ],
    ]);
}

function msg(int $tg, string $text): Update
{
    return new Update([
        'update_id' => 1,
        'message'   => ['message_id' => 6, 'from' => ['id' => $tg], 'chat' => ['id' => $tg], 'text' => $text],
    ]);
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  گزارش‌های کاربر — تست یکپارچه\n";
echo str_repeat('═', 62) . "\n";

// ==================================================================
echo "\n▶ ۱) دکمهٔ «⚙️ تنظیمات»\n";
// ==================================================================

$bot   = new FakeBotApi();
$panel = new Pasargad\Panel\FakePanelClient();

$users    = new Pasargad\Store\UserRepository($db);
$packages = new Pasargad\Store\PackageRepository($db);
$orders   = new Pasargad\Store\OrderRepository($db);
$settings = new Settings($db);
$flags    = new Pasargad\Store\FeatureFlags($settings);
$sessions = new Pasargad\Bot\SessionStore($db);
$panels   = new Pasargad\Store\PanelRepository($db);

$provisioner = new Pasargad\Store\Provisioner($panel, $orders, $users, $settings, $panels);
$payments    = new Pasargad\Payment\PaymentService($orders, $provisioner, $settings, $flags, $users);

$kernel = new Kernel(
    $bot,
    new Pasargad\Bot\Notifier($bot),
    $users,
    $packages,
    $orders,
    $provisioner,
    $payments,
    $settings,
    $sessions,
    $panel,
    $flags
);

$bot->reset();
$kernel->handle(msg(7001, '/start'));
check('دکمهٔ تنظیمات در منوی اصلی هست', $bot->hasButton('تنظیمات'), $bot->allText());

$bot->reset();
$kernel->handle(cb(7001, ['n' => 'settings']));
check('صفحهٔ تنظیمات باز شد', str_contains($bot->allText(), 'آیدی عددی شما'), $bot->allText());
check('پیام «در دسترس نیست» نیامد', !str_contains($bot->allText(), 'در دسترس نیست'), $bot->allText());
check('دکمهٔ بازگشت ساخته شد', $bot->hasButton('بازگشت'), $bot->allText());

// کلیک روی هر دکمهٔ داخل صفحهٔ تنظیمات نباید خطا بدهد.
foreach (['panel.list', 'coupon.apply', 'user.account', 'settings.global', 'rules', 'help'] as $ns) {
    $bot->reset();
    $kernel->handle(cb(7001, ['n' => $ns]));
    check("دکمهٔ «{$ns}» داخل تنظیمات جواب داد", !str_contains($bot->allText(), 'در دسترس نیست'), $bot->allText());
}

// ==================================================================
echo "\n▶ ۲) امضای initData وب‌اپ\n";
// ==================================================================

$initFor = static function (int $userId, ?string $token = null): string {
    $token ??= TOKEN;
    $pairs = [
        'auth_date' => (string) time(),
        'query_id'  => 'AAHQ_probe',
        'user'      => json_encode(['id' => $userId, 'first_name' => 'پ', 'username' => 'p' . $userId], JSON_UNESCAPED_UNICODE),
    ];
    ksort($pairs, SORT_STRING);
    $check = [];
    foreach ($pairs as $k => $v) {
        $check[] = $k . '=' . $v;
    }
    $pairs['hash'] = hash_hmac('sha256', implode("\n", $check), hash_hmac('sha256', 'WebAppData', $token, true));
    $out = [];
    foreach ($pairs as $k => $v) {
        $out[] = urlencode($k) . '=' . urlencode((string) $v);
    }
    return implode('&', $out);
};

$good = InitData::parse($initFor(7001));
check('initData معتبر پذیرفته شد', ($good['ok'] ?? false) === true, $good['message'] ?? '');
check('کاربر استخراج شد', (int) ($good['user']['id'] ?? 0) === 7001);

$wrongTok = InitData::parse($initFor(7001, '999:WRONG'));
check('امضا با توکن اشتباه رد شد', ($wrongTok['ok'] ?? true) === false, $wrongTok['message'] ?? '');

$tampered = str_replace('%22id%22%3A7001', '%22id%22%3A6666', $initFor(7001));
check('دستکاری شناسه رد شد', (InitData::parse($tampered)['ok'] ?? false) === false);

// token با فاصلهٔ اضافه — نباید امضا را بشکند.
// ⚠️ این باگ واقعی بود: `trim` فقط برای مقایسه با placeholder اعمال می‌شد و
// خودِ توکن بدون trim به HMAC می‌رفت؛ یک خط‌جدید انتهای `config.php` باعث
// می‌شد مینی‌اپ همیشه «امضای دادهٔ ورودی نامعتبر است» بدهد.
$padded = InitData::parse($initFor(7001), null, TOKEN);
check('توکن با فاصلهٔ ابتدا/انتها کار می‌کند', ($padded['ok'] ?? false) === true, $padded['message'] ?? '');

Config::set('bot_token', TOKEN . "\n   ");
check('توکن کانفیگ با خط‌جدید انتها هم پذیرفته می‌شود',
    (InitData::parse($initFor(7001))['ok'] ?? false) === true,
    InitData::parse($initFor(7001))['message'] ?? '');

Config::set('bot_token', '   ' . TOKEN . '  ');
check('توکن کانفیگ با فاصلهٔ ابتدا/انتها هم پذیرفته می‌شود',
    (InitData::parse($initFor(7001))['ok'] ?? false) === true,
    InitData::parse($initFor(7001))['message'] ?? '');

Config::set('bot_token', TOKEN);

// ------------------------------------------------------------------
echo "\n▶ ۴) initData کلاینت جدید تلگرام (همراه signature)\n";
// ------------------------------------------------------------------
//
// تلگرام ۸ به بعد کنار `hash`، فیلد `signature` (امضای Ed25519) را هم می‌فرستد.
// طبق مستندات رسمی، `signature` هم باید از data-check-string **حذف** شود.
//
// کد قبلی آن را نگه می‌داشت و روی هر کلاینت جدید امضا همیشه نادرست می‌شد —
// همان چیزی که کل Mini App را از کار انداخته بود.

$sigRaw = static function (int $userId, string $token = TOKEN): string {
    $pairs = [
        'auth_date' => (string) time(),
        'query_id'  => 'AAHQ_newclient',
        'user'      => json_encode(['id' => $userId, 'first_name' => 'نو', 'username' => 'new'], JSON_UNESCAPED_UNICODE),
    ];
    ksort($pairs, SORT_STRING);

    $check = [];
    foreach ($pairs as $k => $v) {
        $check[] = $k . '=' . $v;
    }

    $checkString = implode("\n", $check);

    // `signature` دقیقاً همان چیزی است که تلگرام برای اعتبارسنجی شخص‌ثالث
    // می‌فرستد (base64url). اینجا مقدار ساختگی است؛ فقط «وجود داشتنش» مهم است.
    $pairs['signature'] = rtrim(strtr(base64_encode(hash('sha256', $checkString, true)), '+/', '-_'), '=');
    $pairs['hash']      = hash_hmac('sha256', $checkString, hash_hmac('sha256', 'WebAppData', $token, true));

    $out = [];
    foreach ($pairs as $k => $v) {
        $out[] = urlencode($k) . '=' . urlencode((string) $v);
    }

    return implode('&', $out);
};

$newClient = InitData::parse($sigRaw(7002));

check('کلاینت جدید (با signature) پذیرفته شد', ($newClient['ok'] ?? false) === true, $newClient['message'] ?? '');
check('کاربر درست استخراج شد', (int) ($newClient['user']['id'] ?? 0) === 7002);

// کلاینت قدیمی (بدون signature) همچنان باید کار کند.
$oldClient = InitData::parse($initFor(7003));
// ==================================================================
echo "\n▶ ۳) اتصال پنل جدید از پنل مدیریت\n";
// ==================================================================
// ==================================================================

$bot->reset();
$kernel->handle(cb(999, ['n' => 'admin.panels']));
check('صفحهٔ پنل‌ها باز شد', str_contains($bot->allText(), 'پنل'), $bot->allText());
check('دکمهٔ «اتصال پنل جدید» در حالت خالی هست', $bot->hasButton('اتصال پنل جدید'), $bot->allText());

$bot->reset();
$kernel->handle(cb(999, ['n' => 'panel.connect']));
check('منوی انتخاب روش احراز باز شد', str_contains($bot->allText(), 'اتصال پنل'), $bot->allText());
check('گزینهٔ رمز عبور هست', $bot->hasButton('رمز عبور'));
check('گزینهٔ کلید API هست', $bot->hasButton('کلید API'));

$bot->reset();
$kernel->handle(cb(999, ['n' => 'panel.connect.password']));
check('آدرس پنل خواسته شد', str_contains($bot->allText(), 'آدرس'), $bot->allText());

$sessionsCheck = new Pasargad\Bot\SessionStore($db);
check('نشست روی گام آدرس است',
    ($sessionsCheck->get(999)['step'] ?? '') === Pasargad\Bot\PanelConnectionManager::STEP_URL,
    json_encode($sessionsCheck->get(999), JSON_UNESCAPED_UNICODE));

$bot->reset();
$kernel->handle(msg(999, 'https://newpanel.example.com'));
check('پذیرفتن آدرس و رفتن به نام کاربری',
    str_contains($bot->allText(), 'نام کاربری'), $bot->allText());

$bot->reset();
$kernel->handle(msg(999, 'admin1'));
check('پذیرفتن نام کاربری و رفتن به رمز',
    str_contains($bot->allText(), 'رمز'), $bot->allText());

$bot->reset();
// پنل ساختگی باید ادمین داشته باشد، وگرنه تستِ اتصال همیشه شکست می‌خورد.
$panel->admins['admin1'] = [
    'id'          => 77,
    'username'    => 'admin1',
    'status'      => 'active',
    'data_limit'  => 10737418240,
    'used_traffic' => 1024,
    'role'        => ['name' => 'owner', 'is_owner' => true],
];
$kernel->handle(msg(999, 'secret-pass'));
$panels = new Pasargad\Store\PanelRepository($db);
$mine  = $panels->listByUser((int) ((new Pasargad\Store\UserRepository($db))->findByTelegramId(999)['id']));

check('اتصال تست و ثبت شد', $mine !== [], $bot->allText());
check('آدرس اختصاصی پنل ذخیره شد', ($mine[0]['base_url'] ?? '') === 'https://newpanel.example.com', (string) ($mine[0]['base_url'] ?? 'NULL'));
check('رمز خام ذخیره نشد', ($mine[0]['panel_password'] ?? '') !== 'secret-pass');

// حالا پنل مدیریت باید پنل را نشان دهد
$bot->reset();
$kernel->handle(cb(999, ['n' => 'admin.panels']));
check('پنل ثبت‌شده در پنل مدیریت دیده می‌شود', str_contains($bot->allText(), 'admin1'), $bot->allText());

// مسیر کلید API نباید نام کاربری بپرسد
$bot->reset();
$kernel->handle(cb(999, ['n' => 'panel.connect.apikey']));
check('مسیر کلید API آدرس می‌خواهد', str_contains($bot->allText(), 'آدرس'), $bot->allText());
$kernel->handle(msg(999, 'https://apikey.example.com'));
check('مسیر کلید API نام کاربری نپرسید',
    str_contains($bot->allText(), 'کلید API'), $bot->allText());

echo "\n" . str_repeat('─', 62) . "\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);