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
    $required = [
        '/api/admin/token',
        '/api/admin/{username}',
        '/api/admin/by-id/{admin_id}',
        '/api/user',
        '/api/user/{username}',
    ];

    foreach ($required as $path) {
        check("مسیر {$path} در API وجود دارد", isset($paths[$path]));
    }

    $adminModify = $paths['/api/admin/{username}']['put']['requestBody']['content']['application/json']['schema']['$ref'] ?? '';
    check('مدل AdminModify برای PUT ادمین موجود است', str_contains((string) $adminModify, 'AdminModify'), (string) $adminModify);

    $modifySchema = $openapi['components']['schemas']['AdminModify']['properties'] ?? [];
    check('AdminModify فیلد data_limit دارد', isset($modifySchema['data_limit']));
    check('AdminModify فیلد status دارد', isset($modifySchema['status']));
} else {
    echo "  ⚠️  دریافت openapi.json ممکن نشد — بررسی ساختار رد شد\n";
}

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);