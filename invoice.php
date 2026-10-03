<?php

declare(strict_types=1);

/**
 * صفحهٔ فاکتور خرید (نسخهٔ HTML قابل چاپ).
 *
 * دسترسی با **توکن** محافظت می‌شود نه با شمارهٔ سفارش:
 *   • لینک فاکتور بین نماینده و مشتری او دست‌به‌دست می‌شود، پس باید در
 *     اختیار همان دو نفر باشد.
 *   • حدس‌زدن شمارهٔ سفارش ممکن است (الگوی کد قابل پیش‌بینی است) و لینک
 *     فاکتور هر کسی را ممکن می‌کرد درآمد بقیه را ببیند.
 *
 * توکن ۱۲۸ بیت تصادفی است و از هیچ الگویی پیروی نمی‌کند.
 */

require_once __DIR__ . '/bootstrap.php';

use Pasargad\Store\OrderRepository;
use Pasargad\Support\Db;
use Pasargad\Support\Invoice;
use Pasargad\Support\Logger;

/**
 * خروج تمیز با کد وضعیت.
 *
 * `$e` عمداً اختیاری است تا صفحهٔ خطا مسیر فایل و شمارهٔ خط را لو ندهد.
 * این فایل روی اینترنت است و پیام خطای PHP (که مسیر مطلق سرور را در خود
 * دارد) یعنی نشت ساختار پروژه.
 */
$page = static function (int $status, string $title, string $message, ?Throwable $e = null): void {
    if ($e !== null) {
        Logger::error('Invoice page failed', [
            'status' => $status,
            'error'  => $e->getMessage(),
            'file'   => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    // ⚠️ نباید هشدار/اخطار PHP به خروجی نشت کند. پارامترهای URL کنترل
    // می‌شوند (مثلاً ?t[]=aaaa آرایه است) و هر تبدیل نوعیِ ناشی از آن‌ها
    // یک Warning می‌سازد که مستقیم قبل از HTML چاپ می‌شود — همان چیزی که
    // مسیر کامل سرور را به هر بازدیدکننده نشان می‌داد.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px;color:#1b2733">';
    echo '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
    echo '<p style="color:#7a8894">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
};

// ------------------------------------------------------------------
// اعتبارسنجی ورودی — پیش از هر تبدیل نوعی.
//
// `$_GET` همیشه می‌تواند آرایه باشد (`?t[]=x`). `(string) $array` یک Warning
// تولید می‌کند و رشتهٔ «Array» می‌دهد؛ یعنی هم نشت اطلاعات، هم کد غلط.
// ------------------------------------------------------------------
$rawToken = $_GET['t'] ?? null;

if ($rawToken === null || (is_string($rawToken) && trim($rawToken) === '')) {
    $page(400, 'فاکتور', 'لینک فاکتور ناقص است.');
}

if (!is_string($rawToken)) {
    // آرایه، عدد یا هر چیز غیررشته‌ای — هیچ‌کدام توکن معتبر نیستند
    $page(400, 'فاکتور', 'لینک فاکتور نامعتبر است.');
}

$token = trim($rawToken);

try {
    // مایگریشن لازم نیست (فایل فقط می‌خواند)، ولی اگر دیتابیس تازه ساخته شده
    // باشد نبودِ جدول orders باید پیام تمیز بدهد نه خطای ۵۰۰.
    $db = Db::instance();

    if (!$db->tableExists('orders')) {
        $page(503, 'فاکتور', 'سرویس هنوز آماده نیست. کمی بعد تلاش کنید.');
    }

    $orders = new OrderRepository($db);
    $order  = Invoice::findByToken($orders, $token);

    if ($order === null) {
        $page(404, 'فاکتور پیدا نشد', 'این لینک فاکتور معتبر نیست یا حذف شده است.');
    }

    if (!Invoice::isPayable($order)) {
        $page(402, 'فاکتور پرداخت‌نشده', 'فاکتور فقط برای سفارش‌های پرداخت‌شده صادر می‌شود.');
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    echo Invoice::html($order, $orders);
} catch (Throwable $e) {
    // پیام عمومی به کاربر + جزئیات فقط در لاگ
    $page(500, 'خطای داخلی', 'ساخت فاکتور ناموفق بود. لطفاً کمی بعد تلاش کنید.', $e);
}