<?php

declare(strict_types=1);

/**
 * وبهوک تلگرام — تنها entrypoint اصلی ربات.
 *
 * نکتهٔ امنیتی: هدر X-Telegram-Bot-Api-Secret-Token بررسی می‌شود
 * تا فقط تلگرام بتواند درخواست بفرستد.
 */

require_once __DIR__ . '/bootstrap.php';

use Pasargad\Bot\Kernel;
use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;
use Pasargad\Support\Db;
use Pasargad\Telegram\Update;

// جلوگیری از اجرای بی‌مورد روی درخواست GET (تست سلامت)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $token = Config::str('bot_token', '');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">';
    echo '<title>ربات نمایندگان پاسارگاد</title>';
    echo '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:40px">';
    echo '<h2>ربات فعال است ✅</h2>';
    echo '<p>وبهوک با موفقیت تنظیم شده است.</p>';
    echo '<p style="color:#888">برای اطلاعات بیشتر <code>README.md</code> را ببینید.</p>';
    echo '</body></html>';
    exit;
}

// بررسی توکن امنیتی وبهوک (در صورت تعریف در تنظیمات)
$configuredSecret = Config::str('webhook_secret', '');
$providedSecret   = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

if ($configuredSecret !== '' && $configuredSecret !== 'CHANGE-THIS-RANDOM-SECRET' && !hash_equals($configuredSecret, $providedSecret)) {
    Logger::warning('Rejected request with invalid webhook secret', [
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        'secret' => substr($providedSecret, 0, 8),
    ]);

    http_response_code(403);
    exit;
}

// خواندن بدنهٔ خام درخواست
$rawBody = file_get_contents('php://input') ?: '';
$update  = json_decode($rawBody, true);

header('Content-Type: application/json; charset=utf-8');

if (!is_array($update)) {
    Logger::warning('Invalid update payload', ['size' => strlen($rawBody)]);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

try {
    // مایگریشن خودکار در اولین اجرا
    $db = Db::instance();
    (new Migrator($db))->migrate();

    $kernel = new Kernel();
    $kernel->handle(new Update($update));

    // همیشه 200 برمی‌گردانیم تا تلگرام پیام را دوباره نفرستد
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    Logger::error('Webhook failed', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile() . ':' . $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
    ]);

    // پاسخ 200 تا تلگرام retry نکند؛ خطا در لاگ ثبت شده است.
    echo json_encode(['ok' => true]);
}