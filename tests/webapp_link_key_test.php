<?php

declare(strict_types=1);

/**
 * اتصال پنل در وب‌اپ — فقط با کلید API.
 *
 * ## چرا این تست وجود دارد
 *
 * ربات مدت‌ها بود فقط **کلید API** می‌گرفت، ولی وب‌اپ هنوز فرمِ
 * «نام کاربری + رمز عبور» داشت و `getAdmin($user, $user, $pass)` صدا می‌زد.
 *
 * این یعنی دو رابط برای **یک کار** با دو قراردادِ متفاوت — و بدتر، یک
 * نماینده که در ربات با کلید وصل شده بود، در وب‌اپ می‌افتاد و باید رمزِ
 * عبوری را پیدا می‌کرد که شاید اصلاً نداشته باشد. یا بدتر: رمزش را
 * می‌داد و ما هم رمز را ذخیره می‌کردیم، در حالی که کل معماری روی «کلید تنها»
 * بنا شده بود.
 *
 * ## چرا تست ساختاری هم دارد
 *
 * ادعای «فقط کلید» یعنی **هیچ‌جای فایل‌ها نباید رمز بخواهد**. اگر فقط رفتار را
 * تست کنیم، کسی می‌تواند یک `if` برگرداند که هنوز رمز بپرسد و تست سبز بماند.
 * پس علاوه بر رفتار، خودِ سورس هم بازرسی می‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\WebApp\Api;
use Pasargad\WebApp\InitData;

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

const LINKER  = 710001;   // کاربری که پنلش را وصل می‌کند
const THIEF   = 710002;   // کاربرِ دیگر که می‌خواهد پنلِ او را بردارد
const LINKKEY = 'pg_key_webapp_link_1';

Config::set('bot_token', '123456789:TEST-TOKEN-FOR-WEBAPP-LINK');
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', []);
Config::set('bot_username', 'LinkTestBot');
Config::set('crypto_key', 'link-test-key');
Config::set('panel.base_url', 'https://panel.example.com');
Config::set('panel.api_key', 'pg_key_superadmin_global');

$passed = 0;
$failed = 0;
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

function makeInitData(int $userId, string $firstName): string
{
    $token = Config::str('bot_token');

    $pairs = [
        'auth_date' => (string) time(),
        'query_id'  => 'AAH_link_query',
        'user'      => json_encode([
            'id'         => $userId,
            'first_name' => $firstName,
            'username'   => 'l' . $userId,
        ], JSON_UNESCAPED_UNICODE),
    ];

    ksort($pairs);

    $check = [];
    foreach ($pairs as $k => $v) {
        $check[] = $k . '=' . $v;
    }

    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $pairs['hash'] = hash_hmac('sha256', implode("\n", $check), $secret);

    $query = [];
    foreach ($pairs as $k => $v) {
        $query[] = urlencode($k) . '=' . urlencode($v);
    }

    return implode('&', $query);
}

function apiFor(int $telegramId, string $name, FakePanelClient $client): Api
{
    $api = new Api(new FakeBotApi(), $client);
    $api->authenticate(InitData::parse(makeInitData($telegramId, $name)));

    return $api;
}

// ---- پنلِ ساختگی -------------------------------------------------
//
// ⚠️ `validApiKeys` را **دستی** پر می‌کنیم.
//
// خالی بودنش یعنی «هر کلیدی معتبر است» (رفتارِ راحت برای بقیهٔ تست‌ها). اگر
// همین‌طور رهایش کنیم، این تست **نمی‌تواند** کلیدِ اشتباه را بگیرد و سبز می‌ماند
// در حالی که شاید کد هر کلیدی را قبول می‌کند — دقیقاً همان تله‌ای که باعث
// می‌شود یک تست بی‌فایده فکر کند دارد چیزی را می‌سنجد.
$panel = new FakePanelClient();
$panel->addAdmin('rep_linked', [
    'id' => 900, 'status' => 'active', 'data_limit' => 5368709120,
    'role' => ['id' => 2, 'name' => 'operator', 'is_owner' => false],
]);
$panel->bindApiKey(LINKKEY, 'rep_linked');
$panel->validApiKeys = [LINKKEY];

$users   = new UserRepository();
$panels  = new PanelRepository();
$orders  = new OrderRepository();
$dbx     = \Pasargad\Support\Db::instance();

// خودِ فیک را اول می‌سنجیم: اگر این درست کار نکند، بقیهٔ تست بی‌معناست.
try {
    $panel->getAdminWithApiKey('pg_key_should_not_work');
    check('فیک هم کلیدِ ناشناخته را رد می‌کند', false,
        'فیک هر کلیدی را قبول می‌کند ⇒ تست‌های بعدی هیچ چیزی نمی‌سنجند');
} catch (\Throwable) {
    check('فیک هم کلیدِ ناشناخته را رد می‌کند', true);
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  وب‌اپ — اتصال پنل فقط با کلید API\n";
echo str_repeat('═', 62) . "\n";

// ==================================================================
echo "\n▶ ۱) اتصال موفق\n";
// ==================================================================

$api = apiFor(LINKER, 'Linker', $panel);
$res = $api->handle('panel.link', ['api_key' => LINKKEY]);

check('اتصال با کلید موفق بود', ($res['ok'] ?? false) === true, json_encode($res, JSON_UNESCAPED_UNICODE));
check('پیام موفق داد', str_contains((string) ($res['message'] ?? ''), 'اضافه شد'), (string) ($res['message'] ?? ''));

// ⭐️ نامِ ادمین باید از خودِ پنل آمده باشد (`GET /api/admin`)، نه ساخته‌شده از
// روی کلید. نامِ ساختگی در فهرست پنل‌ها برای کاربر بی‌معنی است.
$stored = $panels->findByPanelUsername('rep_linked');
check('پنل با نامِ واقعیِ ادمین ذخیره شد', $stored !== null,
    'پنلی به این نام پیدا نشد — یعنی نام از کلید ساخته شده');
check('و به همین کاربر وصل است', $stored !== null && (int) $stored['user_id'] > 0);

// ---- رمز عبور **نباید** ذخیره شود ---------------------------------
check('هیچ رمز عبوری ذخیره نشد',
    $stored !== null && (string) ($stored['panel_password'] ?? '') === '',
    'panel_password نباید خالی نباشد');
check('و رمزِ خام در دیتابیس نیست',
    $stored !== null && !str_contains(json_encode($stored, JSON_UNESCAPED_UNICODE), 'pass123'));

// ---- کلید باید رمزنگاری‌شده ذخیره شود ------------------------------
// `PanelRepository` رمزنگاری را خودش انجام می‌دهد؛ اگر کلید خام در دیتابیس
// بنشیند، یک نشتِ دیتابیس = نشتِ کلیدِ همهٔ نمایندگان.
$rawKey = (string) ($stored['api_key'] ?? '');
check('کلید در پنل ثبت شد', $rawKey !== '', 'ستون api_key خالی است');
check('و **خام** ذخیره نشده', $rawKey !== LINKKEY, 'کلید بدون رمزنگاری در دیتابیس است');

// ---- پاسخ نباید کلید را لو بدهد ------------------------------------
$json = json_encode($res, JSON_UNESCAPED_UNICODE);
check('کلید در پاسخ به کلاینت نشت نکرد', !str_contains($json, LINKKEY),
    'کلید داخل پاسخِ HTTP دیده شد');
check('رمزِ ساختگی هم نشت نکرد', !str_contains($json, 'pass123'));

// ==================================================================
echo "\n▶ ۲) کلیدِ اشتباه\n";
// ==================================================================

$api = apiFor(LINKER, 'Linker', $panel);
$bad = $api->handle('panel.link', ['api_key' => 'pg_key_totally_wrong']);

check('کلیدِ اشتباه رد شد', ($bad['ok'] ?? true) === false, json_encode($bad, JSON_UNESCAPED_UNICODE));
check('و پیامِ روشن داد', str_contains((string) ($bad['message'] ?? ''), 'کلید'),
    (string) ($bad['message'] ?? ''));
check('و هیچ پنلی ساخته نشد', (int) $dbx->count('SELECT COUNT(*) FROM panels') === 1,
    (string) (int) $dbx->count('SELECT COUNT(*) FROM panels'));

// ==================================================================
echo "\n▶ ۳) ورودیِ خالی و بدشکل\n";
// ==================================================================

$api = apiFor(LINKER, 'Linker', $panel);

foreach (['', '   ', str_repeat('k', 500), "'; DROP TABLE panels; --"] as $i => $key) {
    $r = $api->handle('panel.link', ['api_key' => $key]);
    check("ورودیِ نامعتبرِ #{$i} رد شد و چیزی نساخت",
        ($r['ok'] ?? true) === false
        && (int) $dbx->count('SELECT COUNT(*) FROM panels') === 1,
        json_encode($r, JSON_UNESCAPED_UNICODE));
}

check('جدولِ پنل‌ها سالم است', (int) $dbx->count('SELECT COUNT(*) FROM panels') >= 1);

// ==================================================================
echo "\n▶ ۴) پارامترهای قدیمی دیگر پذیرفته نمی‌شوند\n";
// ==================================================================

// ⭐️ این بخش قلبِ تست است: اگر کلاینتِ قدیمی (یا کسی که کش دارد) هنوز
// `username`/`password` بفرستد، **نباید** وصل شود — وگرنه یک مسیرِ موازیِ
// رمز-محور زنده می‌ماند که هرگز بازنگری نمی‌شود.
$api = apiFor(LINKER, 'Linker', $panel);

$legacy = $api->handle('panel.link', ['username' => 'rep_linked', 'password' => 'pass123']);
check('ارسالِ username/password قدیمی وصل نمی‌کند', ($legacy['ok'] ?? true) === false,
    json_encode($legacy, JSON_UNESCAPED_UNICODE));
check('و هیچ رمزی ذخیره نشد',
    (string) ($panels->findByPanelUsername('rep_linked')['panel_password'] ?? '') === '');
check('و کلاینتِ پنل با ورودیِ نام‌کاری صدا زده نشد', $panel->loginCalls === 0,
    'loginCalls=' . $panel->loginCalls);

// ==================================================================
echo "\n▶ ۵) مالکیت پنل ربوده نمی‌شود\n";
// ==================================================================

$thiefApi = apiFor(THIEF, 'Thief', $panel);
$steal = $thiefApi->handle('panel.link', ['api_key' => LINKKEY]);

check('کاربرِ دیگر نتوانست همان پنل را بردارد', ($steal['ok'] ?? true) === false,
    json_encode($steal, JSON_UNESCAPED_UNICODE));
check('و مالکیت عوض نشد',
    (int) $panels->findByPanelUsername('rep_linked')['user_id']
        === (int) $users->findByTelegramId(LINKER)['id']);
check('و پیامش صریح بود', str_contains((string) ($steal['message'] ?? ''), 'حساب دیگری'),
    (string) ($steal['message'] ?? ''));

// ==================================================================
echo "\n▶ ۶) اتصال دوبارهٔ همان کلید توسط همان کاربر\n";
// ==================================================================

$api = apiFor(LINKER, 'Linker', $panel);
$again = $api->handle('panel.link', ['api_key' => LINKKEY]);

check('اتصال دوباره موفق بود', ($again['ok'] ?? false) === true, json_encode($again, JSON_UNESCAPED_UNICODE));
check('و پیامش «به‌روزرسانی» بود', str_contains((string) ($again['message'] ?? ''), 'به‌روزرسانی'),
    (string) ($again['message'] ?? ''));
check('و پنلِ تکراری ساخته نشد', (int) $dbx->count('SELECT COUNT(*) FROM panels') === 1,
    'پنل‌های تکراری جمع شده‌اند');

// ==================================================================
echo "\n▶ ۷) بازرسی سورس — هیچ رمزی نباید مانده باشد\n";
// ==================================================================

// ⭐️ تست رفتاری برای «دیگر جایی رمز نمی‌پرسد» کافی نیست: یک شاخهٔ
// بازگشتی می‌تواند همچنان رمز بخواهد و تست سبز بماند. پس خودِ متن هم بازرسی
// می‌شود.

$apiSrc  = (string) file_get_contents(__DIR__ . '/../src/WebApp/Api.php');
$linkPos = strpos($apiSrc, 'private function panelLink');

check('متد panelLink پیدا شد', $linkPos !== false);

if ($linkPos !== false) {
    // تا متدِ بعدی را می‌بریم تا فقط بدنهٔ همین متد را بسنجیم.
    $next     = strpos($apiSrc, "\n    private function ", $linkPos + 10);
    $body     = substr($apiSrc, $linkPos, $next === false ? null : $next - $linkPos);

    check('panelLink رمز عبور نمی‌خواهد', !str_contains($body, '$password'),
        'بدنهٔ متد هنوز متغیر رمز دارد');
    check('panelLink نام کاربری را از کاربر نمی‌گیرد',
        !str_contains($body, "input['username']")
        && !str_contains($body, "input['password']"),
        'ورودیِ کاربر هنوز خوانده می‌شود');
    check('panelLink از مسیرِ کلید استفاده می‌کند',
        str_contains($body, 'getAdminWithApiKey'),
        'مسیر کلید صدا زده نمی‌شود');
}

// ---- و فرمِ کلاینت هم نباید فیلدِ رمز داشته باشد --------------------
$js = (string) file_get_contents(__DIR__ . '/../assets/webapp/app.js');

check('کلاینت فیلدِ رمز عبور ندارد', !str_contains($js, 'pnlPass'),
    'هنوز یک input برای رمز وجود دارد');
check('کلاینت فیلدِ نام کاربریِ پنل ندارد', !str_contains($js, 'pnlUser'),
    'هنوز یک input برای نام کاربری وجود دارد');
check('کلاینت فیلدِ کلید دارد', str_contains($js, 'pnlKey'));
check('و کلید را می‌فرستد', str_contains($js, "api_key: apiKey"));
check('و دیگر username/password نمی‌فرستد',
    !preg_match("/api\('panel\.link',\s*\{[^}]*username/s", $js),
    'کلاینت هنوز نام کاربری می‌فرستد');

// ==================================================================
echo "\n" . str_repeat('─', 62) . "\n";
echo "  نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

if ($failures !== []) {
    echo "\n  موارد ناموفق:\n";
    foreach ($failures as $f) {
        echo "   • {$f}\n";
    }
}

exit($failed === 0 ? 0 : 1);