<?php

declare(strict_types=1);

/**
 * 🎨 ورودی وب مینی‌اپ تلگرام.
 *
 * سه مسیر دارد:
 *   • `GET  /webapp.php`            → خودِ اپلیکیشن (HTML)
 *   • `GET  /webapp.php?asset=…`    → فایل‌های استاتیک (CSS/JS/فونت)
 *   • `POST /webapp.php`            → API با JSON در بدنه
 *
 * چرا یک فایل برای هر سه؟ چون دکمهٔ `web_app` در ربات فقط **یک** آدرس
 * می‌گیرد و تلگرام هیچ مسیری برای API جدا تعریف نمی‌کند. اگر Mini App و API
 * دو فایل جدا بودند، باید آدرس API را داخل اپ hard-code می‌کردیم و با
 * تغییر دامنه یا زیرشاخه، همه‌جا می‌شکست.
 *
 * احراز هویت: هر درخواست API باید هدر `X-Telegram-Init-Data` را داشته باشد
 * که امضای HMAC آن با توکن ربات بررسی می‌شود (`WebApp\InitData`). بدون آن
 * هیچ پاسخی جز خطای ۴۰۱ برنمی‌گردد.
 */

require_once __DIR__ . '/bootstrap.php';

use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\WebApp\Api;
use Pasargad\WebApp\InitData;

// ⚠️ هشدار PHP/نمایش هشدار نباید به خروجی نشت کند؛ پارامترهای URL کنترل
// می‌شوند و هر تبدیل نوعیِ ناشی از آن‌ها یک Warning می‌سازد که مستقیم قبل از
// JSON چاپ می‌شود و پاسخ را خراب می‌کند.
while (ob_get_level() > 0) {
    ob_end_clean();
}

/**
 * خروجی JSON و پایان اسکریپت.
 *
 * @param array<string, mixed> $payload
 */
$json = static function (int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

/**
 * صفحهٔ خطای HTML (برای مسیر غیر-API).
 */
$page = static function (int $status, string $title, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . $t . '</title>'
        . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px;color:#1b2733">'
        . '<h2>' . $t . '</h2><p style="color:#7a8894">' . $m . '</p></body></html>';
    exit;
};

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ------------------------------------------------------------------
// مسیر API
// ------------------------------------------------------------------
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';

    // سقف بدنه: اپلیکیشن هرگز بیش از این نمی‌فرستد (بزرگ‌ترینش عکس رسید است).
    if (strlen($raw) > 12 * 1024 * 1024) {
        $json(413, ['ok' => false, 'message' => 'حجم درخواست بیش از حد مجاز است.']);
    }

    $input = json_decode($raw, true);

    if (!is_array($input)) {
        $json(400, ['ok' => false, 'message' => 'بدنهٔ درخواست معتبر نیست.']);
    }

    $action = trim((string) ($input['action'] ?? ''));

    if ($action === '') {
        $json(400, ['ok' => false, 'message' => 'عملیات مشخص نشده است.']);
    }

    // ------------------------------------------------------------------
    // احراز هویت
    // ------------------------------------------------------------------
    $initRaw = (string) ($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '');

    $init = InitData::parse($initRaw);

    if (!$init['ok']) {
        Logger::warning('WebApp auth failed', [
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
            'action'  => $action,
            'message' => $init['message'],
        ]);

        $json(401, ['ok' => false, 'message' => $init['message'], 'auth' => false]);
    }

    try {
        $db = \Pasargad\Support\Db::instance();

        if (!$db->tableExists('users')) {
            $json(503, ['ok' => false, 'message' => 'سرویس هنوز آماده نیست. کمی بعد تلاش کنید.']);
        }

        // مایگریشن فقط وقتی واقعاً لازم باشد اجرا می‌شود (مقایسهٔ نسخه با
        // `migrations`؛ نه ALTER TABLE در هر درخواست).
        //
        // چرا اینجا لازم است ولی در `invoice.php` نبود؟ چون فاکتور فقط
        // **می‌خواند**، ولی مینی‌اپ به ده‌ها جدول دست می‌زند. روی دیتابیسِ
        // قدیمی (که مهاجرت ۰۰۹ اجرا نشده) اولین فراخوانی با خطای
        // «no such column: coupon_code» می‌مرد و کل اپ از کار می‌افتاد —
        // در حالی که ربات همان دیتابیس را در وبهوک خودش مهاجرت می‌داد.
        (new \Pasargad\Support\Migrator($db))->migrateWhenOutdated();

        $api = new Api();

        $auth = $api->authenticate($init);

        if (!$auth['ok']) {
            $json(403, ['ok' => false, 'message' => $auth['message'], 'auth' => false]);
        }

        unset($input['action']);

        $result = $api->handle($action, $input);

        // ۴۰۳ برای عملیات مدیریتی بدون دسترسی، تا کلاینت بتواند پیام
        // اختصاصی بدهد («مخصوص مدیریت») به‌جای خطای عمومی.
        $status = 200;

        if (($result['forbidden'] ?? false) === true) {
            $status = 403;
        }

        $json($status, $result);
    } catch (Throwable $e) {
        Logger::error('WebApp endpoint failed', [
            'action' => $action,
            'error'  => $e->getMessage(),
            'file'   => $e->getFile() . ':' . $e->getLine(),
        ]);

        $json(500, ['ok' => false, 'message' => 'خطای داخلی رخ داد. لطفاً دوباره تلاش کنید.']);
    }
}

if ($method !== 'GET' && $method !== 'HEAD') {
    $json(405, ['ok' => false, 'message' => 'متد درخواست پشتیبانی نمی‌شود.']);
}

// ------------------------------------------------------------------
// فایل‌های استاتیک اپلیکیشن
//
// نام فایل با allowlist انتخاب می‌شود، نه از مسیر دلخواه: بدون آن، یک
// پارامتر مثل `asset=../../config.php` کل تنظیمات و توکن ربات را لو می‌داد.
// ------------------------------------------------------------------
$asset = isset($_GET['asset']) && is_string($_GET['asset']) ? trim($_GET['asset']) : '';

if ($asset !== '') {
    // مسیر فایل و نوع MIME با هم در یک جدول می‌آیند؛ هر دو allowlist هستند.
    // فونت‌ها از `assets/fonts` سرو می‌شوند (همان فایل‌هایی که فاکتور از
    // آن‌ها استفاده می‌کند) تا یک نسخهٔ واحد روی سرور وجود داشته باشد.
    $allowed = [
        'app.css'   => ['text/css; charset=utf-8', 'assets/webapp/'],
        'app.js'    => ['application/javascript; charset=utf-8', 'assets/webapp/'],
        'fonts/Vazirmatn-Regular.woff2' => ['font/woff2', 'assets/'],
        'fonts/Vazirmatn-Bold.woff2'    => ['font/woff2', 'assets/'],
    ];

    if (!isset($allowed[$asset])) {
        $page(404, 'یافت نشد', 'این فایل وجود ندارد.');
    }

    [$mime, $base] = $allowed[$asset];

    $path = __DIR__ . '/' . $base . $asset;

    if (!is_file($path)) {
        $page(404, 'یافت نشد', 'این فایل وجود ندارد.');
    }

    header('Content-Type: ' . $mime);
    // فونت و استایل تغییر نمی‌کنند ⇒ کش طولانی. نسخه در نام فایل نیست، پس
    // به‌جای آن ETag می‌فرستیم تا یک بار دانلود شود و بعد ۳۰۴ بخورد.
    header('Cache-Control: public, max-age=604800, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    readfile($path);
    exit;
}

// ------------------------------------------------------------------
// خودِ اپلیکیشن
// ------------------------------------------------------------------
$index = __DIR__ . '/assets/webapp/index.html';

if (!is_file($index)) {
    $page(404, 'یافت نشد', 'فایل اپلیکیشن موجود نیست.');
}

// متغیرهایی که کلاینت لازم دارد و از PHP می‌آید (تا داخل JS hard-code نشوند).
$boot = json_encode([
    'api'        => 'webapp.php',
    'bot'        => ltrim(Config::str('bot_username', ''), '@'),
    'support'    => Config::str('notifications.support_link', ''),
    'store_name' => Config::str('store.name', 'ربات نمایندگان پنل'),
    'version'    => '1.0.0',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

// `__WEBAPP_BOOT__` قبل از ارسال جایگزین می‌شود؛ اگر فایل قالب عوض شد و
// نشانه نبود، اپلیکیشن باید واضح شکست بخورد نه اینکه بی‌صدا بی‌تنظیم بالا بیاید.
$html = (string) file_get_contents($index);

if (!str_contains($html, '__WEBAPP_BOOT__')) {
    $page(500, 'خطای پیکربندی', 'قالب اپلیکیشن ناقص است.');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
// 🎨 ظاهر اپ کاملاً از خودِ اپ می‌آید (themeParams تلگرام)، پس CSP سخت‌گیرانه
// فقط `connect-src 'self'` می‌خواهد. `telegram.org` برای خودِ اسکریپت
// تلگرام لازم است و CDN فونت را استفاده نمی‌کنیم (فونت خودمان داخل
// `assets/fonts` است) تا CSP بسته بماند.
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://telegram.org; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob:; "
    . "font-src 'self'; "
    . "connect-src 'self'; "
    . "frame-ancestors https://web.telegram.org https://*.telegram.org"
);

echo str_replace('__WEBAPP_BOOT__', $boot, $html);
