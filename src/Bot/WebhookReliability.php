<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * تلاشِ دوبارهٔ آپدیت‌هایی که پردازششان نیمه‌کاره ماند.
 *
 * ## مسئله‌ای که حل می‌کند
 *
 * تلگرام وبهوک را **فقط یک‌بار** تحویل می‌دهد. اگر پردازش یک آپدیت وسط کار
 * خطا بدهد، ربات پاسخ ۲۰۰ داده و آن آپدیت **برای همیشه از بین می‌رود**:
 * نه خطایی به کاربر می‌رسد، نه تلاشی دوباره انجام می‌شود.
 *
 * برای یک فروشگاه این یعنی: مشتری رسید می‌فرستد، ربات وسط کار می‌میرد،
 * سفارش باز می‌ماند و هیچ‌کس خبردار نمی‌شود.
 *
 * ## دو تصمیم که آگاهانه گرفته شد
 *
 * **۱) داخل درخواست هرگز `sleep()` نمی‌کنیم.**
 *
 * نسخهٔ قبلی بین تلاش‌ها ۱، ۵ و ۱۵ ثانیه می‌خوابید. در PHP این یعنی پروسه
 * تا آن لحظه زیر وب‌سرور قفل است، تلگرام تایم‌اوت می‌خورد و همان آپدیت را
 * **دوباره** می‌فرستد — یعنی به‌جای حل مشکل، دو نسخهٔ همزمان از یک پردازشِ
 * پول‌محور ساخته می‌شد.
 *
 * تلاشِ دوباره کارِ **کرون** است، نه کارِ درخواست. درخواست باید سریع برگردد.
 *
 * **۲) ردیفِ `processed_updates` در شکست پاک می‌شود.**
 *
 * `FloodGuard` شناسهٔ آپدیت را **پیش** از پردازش ثبت می‌کند تا ارسال دوبارهٔ
 * تلگرام نادیده گرفته شود. ولی اگر پردازش شکست بخورد و ردیف بماند، تلاشِ
 * دوباره هم «تکراری» تشخیص داده و **هرگز اجرا نمی‌شود**. پس در شکست، ردیف
 * حذف می‌شود — و آن‌وقت replay واقعاً کار می‌کند.
 */
final class WebhookReliability
{
    /** کلِ تلاش‌ها، شامل تحویلِ اولیهٔ تلگرام. */
    public const MAX_ATTEMPTS = 3;

    private const TABLE = 'webhook_dead_letters';

    private static ?Db $db = null;

    public static function useDb(?Db $db): void
    {
        self::$db = $db;
    }

    private static function db(): ?Db
    {
        try {
            return self::$db ?? Db::instance();
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // ثبت شکست
    // ------------------------------------------------------------------

    /**
     * پارک‌کردنِ آپدیتِ شکست‌خورده برای تلاشِ بعدی.
     *
     * ⚠️ این متد **هرگز نباید** خودش خطا بدهد. در هستهٔ پردازشِ آپدیت صدا زده
     * می‌شود؛ اگر خودش استثنا بدهد، خطای اصلی گم می‌شود و آپدیت باز هم از
     * دست می‌رود — دقیقاً همان چیزی که می‌خواستیم درست کنیم.
     *
     * @param  array<string, mixed> $payload خودِ آپدیت، برای پخشِ دوباره
     */
    public static function recordFailure(int $updateId, \Throwable|string $error, array $payload = []): void
    {
        $db = self::db();

        if ($db === null || $updateId <= 0) {
            return;
        }

        $message = $error instanceof \Throwable ? $error->getMessage() : $error;

        try {
            $db->run(
                'INSERT INTO ' . self::TABLE . '
                     (event_id, method, attempts, last_error, payload, created_at, updated_at)
                 VALUES (:e, :m, 1, :err, :p, :t, :t)
                 ON CONFLICT(event_id) DO UPDATE SET
                     attempts   = webhook_dead_letters.attempts + 1,
                     last_error = :err,
                     payload    = :p,
                     updated_at = :t',
                [
                    ':e'   => (string) $updateId,
                    ':m'   => 'update',
                    ':err' => Str::truncate($message, 500),
                    // بدنه محدود می‌شود: یک آپدیتِ خیلی بزرگ نباید جدول را
                    // باد کند و کرون را کند کند.
                    ':p'   => Str::truncate((string) json_encode($payload, JSON_UNESCAPED_UNICODE), 60000),
                    ':t'   => time(),
                ]
            );

            // ⭐️ حذف ردیفِ «پردازش‌شده» — وگرنه replay همیشه تکراری می‌شود.
            $db->run('DELETE FROM processed_updates WHERE update_id = ?', [$updateId]);

            Logger::warning('Update parked for retry', [
                'update_id' => $updateId,
                'error'     => $message,
            ]);
        } catch (\Throwable $e) {
            // ناتوانی در پارک‌کردن، نباید وضعیت را بدتر کند.
            Logger::warning('Could not park failed update', [
                'update_id' => $updateId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // پخش دوباره (کارِ کرون)
    // ------------------------------------------------------------------

    /**
     * تلاشِ دوبارهٔ آپدیت‌های پارک‌شده.
     *
     * ⚠️ `$dispatch` باید همان ورودیِ `Kernel::handle()` را بگیرد.
     *
     * @param  callable(array<string, mixed>): void $dispatch
     * @return array{attempted:int, succeeded:int, failed:int, dropped:int}
     */
    public static function replay(callable $dispatch, int $limit = 50): array
    {
        $stats = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'dropped' => 0];
        $db    = self::db();

        if ($db === null) {
            return $stats;
        }

        try {
            // قدیمی‌ترین اول: ترتیبِ واقعیِ رخدادها حفظ می‌شود و مهم است،
            // چون ممکن است آپدیتِ بعدی به قبلی وابسته باشد (مثلاً «پرداخت شد»
            // بعد از «سفارش ساخته شد»).
            $rows = $db->all(
                'SELECT * FROM ' . self::TABLE . '
                  WHERE attempts < :max
                  ORDER BY id ASC
                  LIMIT :limit',
                [':max' => self::MAX_ATTEMPTS, ':limit' => max(1, min($limit, 500))]
            );
        } catch (\Throwable $e) {
            Logger::warning('Could not read parked updates', ['error' => $e->getMessage()]);
            return $stats;
        }

        foreach ($rows as $row) {
            $eventId  = (string) $row['event_id'];
            $payload  = json_decode((string) ($row['payload'] ?? ''), true);
            $payload  = is_array($payload) ? $payload : [];
            $attempts = (int) ($row['attempts'] ?? 0);
            $stats['attempted']++;

            try {
                if ($payload === []) {
                    // بدنه‌ای نداریم ⇒ پخشِ بی‌فایده. رها می‌کنیم تا بی‌نهایت
                    // در صف نماند.
                    $stats['dropped']++;
                    self::delete($db, $eventId);
                    continue;
                }

                $dispatch($payload);

                $stats['succeeded']++;
                self::delete($db, $eventId);
            } catch (\Throwable $e) {
                $stats['failed']++;

                // ⚠️ شمارشِ دقیقاً یکی برای هر شکست.
                //
                // `dispatch` معمولاً `Kernel::handle()` است و خودش هم
                // `recordFailure()` را صدا می‌زند. اگر اینجا هم یکی اضافه کنیم،
                // هر شکست **دو** شمرده می‌شود و صف زودتر از چیزی که باید خالی
                // می‌شود — یعنی آپدیتی که با یک تلاشِ دیگر اجرا می‌شد، هرگز
                // دوباره تلاش نمی‌شود.
                //
                // پس فقط وقتی یکی اضافه می‌کنیم که شمارنده را `dispatch` تکان
                // نداده باشد (مثلاً وقتی از مسیرِ `Kernel` رد نشده).
                $now = (int) (self::rowOf($db, $eventId)['attempts'] ?? $attempts);

                if ($now <= $attempts) {
                    $now = $attempts + 1;
                }

                $db->run(
                    'UPDATE ' . self::TABLE . ' SET attempts = :a, last_error = :e, updated_at = :t
                      WHERE event_id = :id',
                    [':a' => min($now, self::MAX_ATTEMPTS), ':e' => Str::truncate($e->getMessage(), 500),
                     ':t' => time(), ':id' => $eventId]
                );

                if ($now >= self::MAX_ATTEMPTS) {
                    // ⭐️ ردیفِ `processed_updates` ساخته می‌شود تا اگر کسی بعداً
                    // جدول را دستی پاک کرد، این آپدیت از سمت تلگرام هم دوباره
                    // پخش نشود. (انتخابِ خودِ `replay` هم جلویش را می‌گیرد؛ این
                    // لایهٔ دوم است.)
                    $db->run(
                        'INSERT OR IGNORE INTO processed_updates (update_id, fingerprint, created_at)
                         VALUES (?, ?, ?)',
                        [(int) $eventId, '', time()]
                    );

                    Logger::error('Update exhausted its retries and needs a human', [
                        'update_id' => $eventId,
                        'error'     => $e->getMessage(),
                    ]);
                }

                Logger::warning('Replayed update failed again', [
                    'update_id' => $eventId,
                    'attempt'   => $now,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowOf(Db $db, string $eventId): array
    {
        try {
            return $db->first(
                'SELECT attempts FROM ' . self::TABLE . ' WHERE event_id = ?',
                [$eventId]
            ) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function delete(Db $db, string $eventId): void
    {
        try {
            $db->run('DELETE FROM ' . self::TABLE . ' WHERE event_id = ?', [$eventId]);
        } catch (\Throwable $e) {
            Logger::warning('Could not clear replayed update', ['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // گزارش و پاک‌سازی
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function stats(): array
    {
        $db = self::db();

        if ($db === null) {
            return ['pending' => 0, 'total' => 0, 'exhausted' => 0, 'available' => false];
        }

        try {
            $row = $db->first(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN attempts >= :max THEN 1 ELSE 0 END) AS exhausted
                   FROM ' . self::TABLE,
                [':max' => self::MAX_ATTEMPTS]
            ) ?? [];
        } catch (\Throwable $e) {
            return ['pending' => 0, 'total' => 0, 'exhausted' => 0, 'available' => false];
        }

        $total     = (int) ($row['total'] ?? 0);
        $exhausted = (int) ($row['exhausted'] ?? 0);

        return [
            'pending'   => max(0, $total - $exhausted),
            'exhausted' => $exhausted,
            'total'     => $total,
            'available' => true,
        ];
    }

    /**
     * حذفِ رکوردهای ازکارافتاده و قدیمی.
     *
     * ⚠️ رکوردهایی که به سقفِ تلاش رسیده‌اند **حذف نمی‌شوند** مگر صریحاً بخواهیم:
     * همان‌ها هستند که یک آپدیت برای همیشه از دست رفته و ادمین باید ببیندشان.
     * فقط رکوردهای *پاک* یعنی موفق برگشتند این‌طور پاک می‌شوند، و آن‌ها در
     * مسیرِ موفق از جدول حذف شده‌اند — پس این عملاً برای داده‌های یتیمِ قدیمی است.
     */
    public static function purge(int $olderThanDays = 30): int
    {
        $db = self::db();

        if ($db === null) {
            return 0;
        }

        $cutoff = time() - max(1, $olderThanDays) * 86400;

        try {
            return $db->run(
                'DELETE FROM ' . self::TABLE . ' WHERE updated_at < ? AND attempts < ?',
                [$cutoff, self::MAX_ATTEMPTS]
            )->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}