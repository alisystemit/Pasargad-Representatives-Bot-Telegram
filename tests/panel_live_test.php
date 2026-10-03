<?php

declare(strict_types=1);

/**
 * تست اتصال واقعی به پنل PassingGuard (بدون نیاز به حساب معتبر).
 *
 * هدف: اطمینان از اینکه کلاینت API، مسیرها و مدیریت خطا درست کار می‌کند.
 * این تست عمداً با نام کاربری نامعتبر اجرا می‌شود تا رفتار خطا بررسی شود.
 *
 * اجرا: php tests/panel_live_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Config;

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

echo "\n▶ تست زندهٔ کلاینت پنل\n";

$panelUrl = Config::str('panel.base_url');
check('آدرس پنل تنظیم شده', $panelUrl !== '');

// ------------------------------------------------------------------
echo "\n▶ سلامت سرویس\n";
// ------------------------------------------------------------------

$panel = new PasarGuardClient();
try {
    $health = $panel->health();
    check('GET /health پاسخ داد', ($health['status'] ?? '') === 'ok', json_encode($health, JSON_UNESCAPED_UNICODE));
} catch (\Throwable $e) {
    check('GET /health پاسخ داد', false, $e->getMessage());
}

// ------------------------------------------------------------------
echo "\n▶ ورود با اطلاعات نامعتبر (بررسی مدیریت خطا)\n";
// ------------------------------------------------------------------

try {
    $panel->login('definitely-not-a-real-admin-' . random_int(1000, 9999), 'wrong-password', false);
    check('ورود ناموفق باید خطا بدهد', false, 'بدون خطا برگشت!');
} catch (PanelException $e) {
    check('خطای PanelException پرتاب شد', true);
    check('پیام انگلیسی پنل به فارسی ترجمه شد', str_contains($e->getMessage(), 'اشتباه'), $e->getMessage());
    check('خطای احراز هویت شناسایی شد', $e->isAuthError(), 'status=' . $e->httpStatus());
    check('خطای 401 قابل تلاش مجدد نیست', !$e->isRetryable());
}

// ------------------------------------------------------------------
echo "\n▶ درخواست بدون توکن معتبر\n";
// ------------------------------------------------------------------

try {
    $panel->getCurrentAdmin('nonexistent-user', 'nonexistent-password');
    // اگر خطا نداد، یعنی سرور بدون احراز هویت پاسخ داده — که نگران‌کننده است
    check('درخواست بدون توکن رد شد', false, 'سرور درخواست را پذیرفت!');
} catch (PanelException $e) {
    check('درخواست بدون توکن رد شد', $e->httpStatus() === 401 || $e->httpStatus() === 403, 'status=' . $e->httpStatus());
}

// ------------------------------------------------------------------
echo "\n▶ ساختار پاسخ API\n";
// ------------------------------------------------------------------

// بررسی اینکه مسیرهای اصلی در openapi وجود دارند
$openapi = null;
try {
    $response = \Pasargad\Support\Http::json('GET', rtrim($panelUrl, '/') . '/openapi.json', null, ['timeout' => 20]);
    if (is_array($response['data'])) {
        $openapi = $response['data'];
    }
} catch (\Throwable) {
    // بی‌اهمیت
}

if ($openapi !== null) {
    $paths = $openapi['paths'] ?? [];

    // ------------------------------------------------------------------
    // قرارداد مسیر + متد
    //
    // ⚠️ **وجود مسیر کافی نیست؛ متد هم باید باشد.**
    // باگ کشف‌شده: `GET /api/admin/{username}` در اسپک «وجود داشت» (چون
    // PUT/DELETE روی همان مسیر تعریف شده‌اند) و تست قدیمی فقط وجود مسیر را
    // می‌پرسید، پس سبز ماند — ولی کلاینت واقعی `GET` می‌زد و پنل **۴۰۵**
    // برمی‌گرداند. حالا مسیرها به‌صورت «متد ⇒ مسیر» بررسی می‌شوند.
    // ------------------------------------------------------------------

    /** @var array<int, array{0:string, 1:string, 2:string}> [method, path, note] */
    $contract = [
        ['post',   '/api/admin/token',                     'ورود'],
        ['get',    '/api/admin',                           'ادمین جاری — جایگزین getAdmin'],
        ['get',    '/api/admins',                          'فیلتر username/ids — راه خواندن ادمین دیگر'],
        ['post',   '/api/admin',                           'ساخت ادمین'],
        ['put',    '/api/admin/{username}',                'ویرایش ادمین'],
        ['delete', '/api/admin/{username}',                'حذف ادمین'],
        ['post',   '/api/admin/{username}/reset',           'صفر کردن مصرف'],
        ['post',   '/api/admin/{username}/users/disable',   'قطع گروهی کاربران'],
        ['get',    '/api/admin/{username}/usage',           'آمار مصرف'],
        ['get',    '/api/admin-roles',                     'فهرست نقش‌ها'],
        ['post',   '/api/user',                            'ساخت کاربر'],
        ['get',    '/api/user/{username}',                 'خواندن کاربر'],
        ['put',    '/api/user/{username}',                 'ویرایش کاربر'],
        ['put',    '/api/user/{username}/disabled',        'غیرفعال‌سازی (فقط یک فیلد)'],
        ['post',   '/api/user/{username}/reset',           'صفر کردن مصرف کاربر'],
        ['get',    '/api/users',                           'فهرست/شمارش کاربران'],
        ['get',    '/api/system',                          'آمار سیستم'],
    ];

    foreach ($contract as [$method, $path, $note]) {
        check(
            sprintf('%s %s در API هست (%s)', strtoupper($method), $path, $note),
            isset($paths[$path][$method])
        );
    }

    // مسیرهایی که کد ما **نباید** صدا بزند.
    //
    // اینها در اسپک «وجود دارند» ولی با متد اشتباه ۴۰۵ می‌دهند — دقیقاً
    // همان چیزی که باعث خرابی چهار مسیر حیاتی شده بود.
    $forbidden = [
        ['get',    '/api/admin/{username}',     'getAdmin قدیمی'],
        ['get',    '/api/admin/by-id/{admin_id}', 'getAdminById قدیمی'],
    ];

    foreach ($forbidden as [$method, $path, $note]) {
        check(
            sprintf('⚠️ %s %s در API نیست (%s) — پس کد نباید صدایش بزند',
                strtoupper($method), $path, $note),
            !isset($paths[$path][$method])
        );
    }

    // ------------------------------------------------------------------
    // مدل‌های داده
    // ------------------------------------------------------------------

    $schemas = $openapi['components']['schemas'] ?? [];

    $adminModify = $paths['/api/admin/{username}']['put']['requestBody']['content']['application/json']['schema']['$ref'] ?? '';
    check('مدل AdminModify برای PUT ادمین موجود است', str_contains((string) $adminModify, 'AdminModify'), (string) $adminModify);

    $modifySchema = $schemas['AdminModify']['properties'] ?? [];
    check('AdminModify فیلد data_limit دارد', isset($modifySchema['data_limit']));
    check('AdminModify فیلد status دارد', isset($modifySchema['status']));

    // `AdminModify` هیچ فیلد اجباری ندارد ⇒ `PUT` با `{}` معتبر است ولی بی‌معنی.
    // اگر روزی پنل این را اجباری کند، محافظ `modifyAdmin` باید بازبینی شود.
    check(
        'AdminModify بدون فیلد اجباری است (دلیل محافظِ بدنهٔ خالی)',
        ($schemas['AdminModify']['required'] ?? []) === []
    );

    // `AdminCreate` برخلاف Modify، `role_id` را **اجباری** کرده. اگر این عوض
    // شود و ما `role_id` نفرستیم، `POST /api/admin` خطای ۴۲۲ می‌دهد و خرید
    // «پنل نمایندگی» برای همیشه شکست می‌خورد.
    $createRequired = $schemas['AdminCreate']['required'] ?? [];
    check('AdminCreate فیلد role_id را اجباری کرده (کد باید role_id بفرستد)',
        in_array('role_id', $createRequired, true),
        'required=[' . implode(',', $createRequired) . ']');
    check('AdminCreate نام کاربری و رمز هم اجباری‌اند',
        in_array('username', $createRequired, true) && in_array('password', $createRequired, true));

    // `UserStatusToggle` باید فقط یک فیلد داشته باشد — همان چیزی که
    // `disableUser` رویش حساب می‌کند تا حجم/انقضای کاربر را پاک نکند.
    $toggleRequired = $schemas['UserStatusToggle']['required'] ?? [];
    check('UserStatusToggle فقط فیلد disabled را می‌خواهد',
        $toggleRequired === ['disabled'],
        'required=[' . implode(',', $toggleRequired) . ']');
    check('و بدنهٔ ما دقیقاً همین را می‌فرستد',
        ($paths['/api/user/{username}/disabled']['put']['requestBody']['content']['application/json']['schema']['$ref'] ?? '') !== '');

    // فیلترهای `GET /api/users` که کلاینت استفاده می‌کند باید وجود داشته باشند.
    $userParams = [];

    foreach (($paths['/api/users']['get']['parameters'] ?? []) as $param) {
        if (($param['in'] ?? '') === 'query') {
            $userParams[] = $param['name'];
        }
    }

    foreach (['limit', 'offset', 'status', 'admin', 'username', 'usernames'] as $needed) {
        check("فیلتر «{$needed}» در GET /api/users وجود دارد",
            in_array($needed, $userParams, true));
    }
} else {
    echo "  ⚠️  دریافت openapi.json ممکن نشد — بررسی ساختار رد شد\n";
}

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);