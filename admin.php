<?php

declare(strict_types=1);

/**
 * وبهوک ربات مدیریتی (اختیاری) — جداشده از وبهوک ربات کاربران.
 *
 * چرا ربات دوم؟
 *   • **جدا شدن ترافیک.** سوپرادمین‌ها فقط چند نفرند ولی هر کلیکشان
 *     چندین کوئری سنگین می‌زند (فهرست پنل‌ها، آمار…). جدا کردنشان یعنی
 *     صف کاربران عادی شلوغ نمی‌شود.
 *   • **بوت‌کمپ امنیتی.** اگر ربات مدیریت جدا باشد، می‌شود آن را در گروه
 *     محدود کرد، کانالش را خصوصی گذاشت، یا حتی آیدی مدیران را روی همان
 *     ربات محدود کرد. روی ربات کاربران چنین کاری ممکن نیست.
 *   • **بوت‌کمپ عملیاتی.** اگر ربات اصلی خراب شود، ربات مدیریتی دست‌نخورده
 *     می‌ماند و می‌توان همه‌چیز را مدیریت کرد.
 *
 * این وبهوک **اختیاری** است. اگر `admin_bot_token` یا `admin_webhook_secret`
 * تنظیم نشده باشد، فایل ۵۰۳ می‌دهد و هیچ اتفاقی نمی‌افتد — یعنی کسی که
 * ربات دوم نمی‌خواهد اصلاً لازم نیست کاری کند.
 *
 * نکتهٔ امنیتی: فقط کاربرانی که در `super_admins` کانفیگ هستند پذیرفته
 * می‌شوند. بقیه بی‌صدا نادیده گرفته می‌شوند تا ربات دوم منبع مزاحمت نشود.
 */

require_once __DIR__ . '/bootstrap.php';

use Pasargad\Bot\AdminWebhook;
use Pasargad\Bot\Kernel;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Update;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">';
    echo '<title>وبهوک مدیریت</title>';
    echo '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:40px">';
    echo '<h2>وبهوک مدیریتی فعال است ✅</h2>';
    echo '<p>این وبهوک فقط پیام‌های سوپرادمین‌ها را می‌پذیرد.</p>';
    echo '</body></html>';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$deny = static function (int $code, string $reason): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $reason], JSON_UNESCAPED_UNICODE);
    exit;
};

// ------------------------------------------------------------------
// ۱) ربات دوم پیکربندی شده است؟
// ------------------------------------------------------------------
$status = AdminWebhook::status();

if (!$status['ready']) {
    Logger::warning('Admin webhook refused — ' . $status['error']);
    $deny($status['status'], $status['error']);
}

// ------------------------------------------------------------------
// ۲) توکن امنیتی درست است؟
// ------------------------------------------------------------------
if (!AdminWebhook::secretMatches($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null)) {
    Logger::warning('Rejected admin webhook with invalid secret', [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $deny(403, 'forbidden');
}

$rawBody = file_get_contents('php://input') ?: '';
$update  = json_decode($rawBody, true);

if (!is_array($update)) {
    Logger::warning('Invalid admin update payload', ['size' => strlen($rawBody)]);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

try {
    $db = Db::instance();
    (new Migrator($db))->migrateWhenOutdated();

    // ------------------------------------------------------------------
    // ۳) فقط سوپرادمین‌ها
    //
    // این دروازه **قبل از** ساخت Kernel است تا کاربر عادی اصلاً در دیتابیس
    // ثبت نشود و هیچ هزینه‌ای برای ربات دوم تحمیل نشود.
    // ------------------------------------------------------------------
    $from   = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;
    $userId = (int) ($from['id'] ?? 0);

    if (!AdminWebhook::allows($userId)) {
        Logger::info('Ignored non-admin update on admin webhook', ['user_id' => $userId]);

        // ۲۰۰ تا تلگرام بی‌نهایت retry نکند
        echo json_encode(['ok' => true]);
        exit;
    }

    $bot = new BotApi(\Pasargad\Support\Config::str('admin_bot_token'));

    (new Kernel($bot))->handle(new Update($update));

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    Logger::error('Admin webhook failed', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile() . ':' . $e->getLine(),
    ]);

    echo json_encode(['ok' => true]);
}