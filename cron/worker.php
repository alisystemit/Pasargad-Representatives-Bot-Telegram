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
use Pasargad\Store\AlertService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;

Logger::channel('worker');

/**
 * مسیر فایل قفل در پوشهٔ داده (نه لاگ، چون پوشهٔ لاگ ممکن است پاک شود).
 */
function lock_file(): string
{
    $dataDir = dirname(\Pasargad\Support\Config::str('db.path', __DIR__ . '/../data/bot.sqlite'));

    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }

    return rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'worker.lock';
}

$lockFile = lock_file();
$lock     = @fopen($lockFile, 'c');

if ($lock === false) {
    // ناتوانی در ساخت فایل قفل نباید باعث شود کرون بی‌صدا از کار بیفتد.
    fwrite(STDERR, "هشدار: فایل قفل ساخته نشد (" . $lockFile . ") — بدون قفل ادامه می‌دهیم.\n");
    $lock = null;
} elseif (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "worker دیگری در حال اجراست؛ خروج.\n";
    exit(0);
}

try {
    $db = Db::instance();
    (new Migrator($db))->migrate();

    // اطمینان از وجود پوشهٔ لاگ (اگر نبود، لاگ‌ها بی‌صدا از دست می‌رفتند).
    $logDir = \Pasargad\Support\Config::str('log.path', __DIR__ . '/../data/logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    // ۱) اجرای خودکار بسته‌های پرداخت‌شده
    $orders    = new OrderRepository($db);
    $users     = new UserRepository($db);
    $provisioner = new Provisioner(null, $orders, $users);

    $result = $provisioner->processQueue(10);

    echo 'پردازش صف: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

    // ۲) هشدار حجم کم، اعتبار کم و نزدیک شدن انقضا
    $alerts    = (new AlertService($users))->runAll();
    $alertTotal = $alerts['low_volume'] + $alerts['low_credit'] + $alerts['expiring'] + $alerts['credit_expiring'];

    if ($alertTotal > 0) {
        echo sprintf(
            "هشدارها: %d حجم کم، %d اعتبار کم، %d انقضای بسته، %d انقضای اعتبار کاربر\n",
            $alerts['low_volume'],
            $alerts['low_credit'],
            $alerts['expiring'],
            $alerts['credit_expiring']
        );
    }

    // ۳) پاک‌سازی نشست‌های منقضی‌شده
    $pruned = (new SessionStore($db))->prune();
    if ($pruned > 0) {
        echo "نشست‌های منقضی پاک شدند: {$pruned}\n";
    }

    // ۴) گزارش کوتاه وضعیت
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
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}