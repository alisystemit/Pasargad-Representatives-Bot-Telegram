<?php

declare(strict_types=1);

namespace Pasargad\Support;

use Pasargad\Store\Settings;
use Pasargad\Telegram\BotApi;

/**
 * بکاپ‌گیری از دیتابیس SQLite ربات.
 *
 * سه حالت دارد (درخواستی کاربر):
 *   • همون لحظه (دستی از پنل مدیریت)
 *   • یک بار در روز
 *   • دو بار در روز
 *
 * فایل بکاپ کنار دیتابیس در پوشهٔ data/backups ذخیره و برای سوپرادمین
 * ارسال می‌شود. فقط ۱۴ بکاپ آخر نگه داشته می‌شود تا دیسک پر نشود.
 */
final class Backup
{
    /** پیش‌فرض تعداد نسخه‌های نگه‌داشته‌شده */
    public const KEEP_FILES = 14;

    /**
     * چند نسخه باید نگه داشته شود.
     *
     * از تنظیمات خوانده می‌شود تا سوپرادمین بتواند بدون دست زدن به کد
     * سیاست نگهداری را عوض کند (پروژهٔ پرترافیک شاید ۳۰ نسخه بخواهد، و پروژهٔ
     * کوچک ۷ تا که دیسک پر نشود).
     */
    public static function keepCount(?Settings $settings = null): int
    {
        try {
            $keep = ($settings ?? new Settings())->int(Settings::BACKUP_KEEP, self::KEEP_FILES);
        } catch (\Throwable $e) {
            $keep = self::KEEP_FILES;
        }

        // کمتر از ۲ یعنی «هیچ نسخهٔ قدیمی نگه ندار» که برای بکاپ خطرناک است:
        // تنها نسخهٔ موجود هم ممکن است خراب باشد.
        return max(2, min(200, $keep));
    }

    /**
     * ساخت فایل بکاپ جدید از دیتابیس فعلی.
     *
     * @return array{ok:bool, message:string, path?:string, size?:int}
     */
    public static function run(?Settings $settings = null): array
    {
        $dbPath = Config::str('db.path', '');

        if ($dbPath === '' || !is_file($dbPath)) {
            return ['ok' => false, 'message' => 'فایل دیتابیس پیدا نشد.'];
        }

        $dir = dirname($dbPath) . DIRECTORY_SEPARATOR . 'backups';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'message' => 'پوشهٔ بکاپ قابل نوشتن نیست.'];
        }

        $name = 'bot-backup-' . date('Y-m-d_H-i-s') . '.sqlite';
        $dest = $dir . DIRECTORY_SEPARATOR . $name;

        // کپی امن SQLite: اول WAL را checkpoint می‌کنیم تا همهٔ داده‌ها
        // داخل فایل اصلی باشند، بعد کپی می‌گیریم.
        try {
            $pdo = Db::instance()->pdo();
            @$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable $e) {
            // اگر نشد هم ادامه می‌دهیم — کپی بهتر از هیچی است
        }

        if (!@copy($dbPath, $dest)) {
            return ['ok' => false, 'message' => 'کپی فایل دیتابیس ناموفق بود.'];
        }

        self::prune($dir, $settings);

        try {
            ($settings ?? new Settings())->set(Settings::BACKUP_LAST_AT, (string) time());
        } catch (\Throwable $e) {
        }

        return [
            'ok'      => true,
            'message' => 'بکاپ ساخته شد.',
            'path'    => $dest,
            'size'    => is_file($dest) ? (int) filesize($dest) : 0,
        ];
    }

    /**
     * آیا الآن نوبت بکاپ خودکار است؟ (برای worker کرون)
     */
    public static function isDue(): bool
    {
        try {
            $settings = new Settings();
            $schedule = (string) $settings->get(Settings::BACKUP_SCHEDULE, 'off');
            $last = (int) $settings->get(Settings::BACKUP_LAST_AT, '0');
        } catch (\Throwable $e) {
            return false;
        }

        if ($schedule === 'off' || $schedule === '') {
            return false;
        }

        $interval = $schedule === 'twice' ? 12 * 3600 : 24 * 3600;

        return (time() - $last) >= $interval;
    }

    /**
     * ارسال فایل بکاپ به یک چت تلگرام.
     *
     * @return array{ok:bool, message:string}
     */
    public static function sendTo(int $chatId, string $filePath, ?BotApi $bot = null): array
    {
        try {
            $bot = $bot ?? new BotApi();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'توکن ربات تنظیم نشده است.'];
        }

        $caption = '💾✨ <b>بکاپ دیتابیس ربات</b> 📦' . "\n"
            . '📅 ' . date('Y-m-d H:i:s') . "\n"
            . '📦 حجم: ' . Str::formatBytes(is_file($filePath) ? (int) filesize($filePath) : 0);

        $result = $bot->sendDocument($chatId, $filePath, $caption);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($result['description'] ?? 'ارسال ناموفق بود.')];
        }

        return ['ok' => true, 'message' => 'بکاپ ارسال شد.'];
    }

    /**
     * @return array<int, string> فهرست فایل‌های بکاپ (جدیدترین اول)
     */
    public static function list(): array
    {
        $dbPath = Config::str('db.path', '');
        $dir = $dbPath !== '' ? dirname($dbPath) . DIRECTORY_SEPARATOR . 'backups' : '';

        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'bot-backup-*.sqlite') ?: [];
        rsort($files);

        return array_values($files);
    }

    private static function prune(string $dir, ?Settings $settings = null): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . 'bot-backup-*.sqlite') ?: [];
        rsort($files);

        foreach (array_slice($files, self::keepCount($settings)) as $old) {
            @unlink($old);
        }
    }

    /**
     * حذف بکاپ‌های قدیمی بدون ساختن نسخهٔ جدید (برای `cli.php backup --prune`).
     *
     * @return array{removed:int, kept:int}
     */
    public static function pruneOld(?Settings $settings = null): array
    {
        $dbPath = Config::str('db.path', '');

        if ($dbPath === '') {
            return ['removed' => 0, 'kept' => 0];
        }

        $dir = dirname($dbPath) . DIRECTORY_SEPARATOR . 'backups';

        if (!is_dir($dir)) {
            return ['removed' => 0, 'kept' => 0];
        }

        $before = count(glob($dir . DIRECTORY_SEPARATOR . 'bot-backup-*.sqlite') ?: []);
        self::prune($dir, $settings);
        $after = count(glob($dir . DIRECTORY_SEPARATOR . 'bot-backup-*.sqlite') ?: []);

        return ['removed' => max(0, $before - $after), 'kept' => $after];
    }
}
