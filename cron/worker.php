<?php

declare(strict_types=1);

/**
 * worker کرون — اجرای صف بسته‌های خریداری‌شده.
 *
 * زمان‌بندی پیشنهادی (هر ۵ دقیقه):
 *   * * * * * php /path/cron/worker.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این اسکریپت فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Bot\SessionStore;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;

Logger::channel('worker');

/**
 * مسیر یک فایل کمکی داخل پوشهٔ لاگ (برای قفل و گزارش).
 */
function support_path(string $name): string
{
    return rtrim(\Pasargad\Support\Config::str('log.path', __DIR__ . '/data/logs'), '/\\')
        . DIRECTORY_SEPARATOR . $name;
}

$lockFile = support_path('worker.lock');
$lock     = fopen($lockFile, 'c');

// قفل: از اجرای همزمان چند نمونه جلوگیری می‌کند
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "worker دیگری در حال اجراست؛ خروج.\n";
    exit(0);
}

try {
    $db = Db::instance();
    (new Migrator($db))->migrate();

    // ۱) اجرای خودکار بسته‌های پرداخت‌شده
    $orders    = new OrderRepository($db);
    $users     = new UserRepository($db);
    $provisioner = new Provisioner(null, $orders, $users);

    $result = $provisioner->processQueue(10);

    echo 'پردازش صف: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

    // ۲) پاک‌سازی نشست‌های منقضی‌شده
    $pruned = (new SessionStore($db))->prune();
    if ($pruned > 0) {
        echo "نشست‌های منقضی پاک شدند: {$pruned}\n";
    }

    // ۳) گزارش کوتاه وضعیت
    $stats = $orders->stats();
    echo sprintf(
        "وضعیت: %d سفارش، %d اجراشده، %d ناموفق، %d در انتظار رسید\n",
        $stats['total'],
        $stats['applied'],
        $stats['failed'],
        $stats['awaiting']
    );

    Logger::info('Worker finished', $result);
    exit(0);
} catch (Throwable $e) {
    Logger::error('Worker crashed', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile() . ':' . $e->getLine(),
    ]);

    fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}