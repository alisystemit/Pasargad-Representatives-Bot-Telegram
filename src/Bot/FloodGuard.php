<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Db;
use Pasargad\Support\Logger;

/**
 * 🛡️ ضدتکرار دستورات و محافظ بار سرور.
 *
 * دو لایه دارد و هر دو «fail-open» هستند (اگر دیتابیس در دسترس نباشد،
 * دستور اجرا می‌شود تا ربات مختل نشود):
 *
 * ۱) آپدیت تکراری: تلگرام گاهی یک update_id را دوباره می‌فرستد. شناسه‌های
 *    دیده‌شده ذخیره می‌شوند و تکراری‌ها بی‌صدا رد می‌شوند.
 *
 * ۲) کف فاصلهٔ اکشن‌ها: دابل‌کلیک روی دکمه‌های سنگین (خرید، پرداخت، سینک
 *    پنل، تست) که هر کدام چند درخواست شبکه/دیتابیس دارند، در پنجرهٔ چند
 *    ثانیه‌ای فقط یک‌بار اجرا می‌شود. ناوبری سبک منوها محدود نمی‌شود تا
 *    تجربهٔ کاربری خراب نشود.
 */
final class FloodGuard
{
    /** پنجرهٔ پیام‌های متنی یکسان (ثانیه) */
    public const MESSAGE_WINDOW = 2;

    /** پنجرهٔ رسیدهای تصویری یکسان (ثانیه) */
    public const RECEIPT_WINDOW = 5;

    /**
     * پنجرهٔ اختصاصی اکشن‌های سنگین (ثانیه).
     *
     * فقط همین‌ها محدود می‌شوند. ناوبری سبک (باز کردن منو، دیدن پنل، رفتن به
     * صفحهٔ بعد) عمداً **محدود نیست** چون هرکدام یک درخواست شبکه و چند
     * درخواست دیتابیس می‌زنند و محدود کردنشان فقط تجربهٔ کاربر را خراب می‌کند
     * بدون آنکه سودی داشته باشد.
     *
     * @var array<string, int>
     */
    private const HEAVY_WINDOWS = [
        'pkg.buy'       => 8,
        'pay'           => 8,
        'panel.sync'    => 10,
        'panel.refresh' => 10,
        'user.refresh'  => 10,
        'panel.test'    => 10,
        'order.check'   => 5,
        // عملیات سنگین/حساس ادمین (پخش همگانی، بکاپ، اجرای دستی، قطع دسترسی):
        // دابل‌کلیک نباید دو بار اجرا شود.
        'admin.retry'           => 10,
        'admin.backup.now'      => 30,
        'admin.broadcast.send'  => 30,
        'admin.panel.cutoff.run' => 30,
    ];

    /**
     * اکشن‌هایی که اصلاً نباید محدود شوند، حتی اگر دوبار کلیک شوند.
     *
     * @var array<int, string>
     */
    private const NEVER_THROTTLED = [
        'close',
        'noop',
        'menu',
        'help',
        'rules',
        'panel.list',
        'panel.view',
        'panel.self',
        'panel.test.list',
        'panel.test.off',
        'user.account',
        'user.wallet',
        'user.payments',
        'order.list',
        'order.view',
        'pkg',
        'shop',
        'channel.recheck',
    ];

    private Db $db;
    private bool $enabled;

    public function __construct(?Db $db = null, bool $enabled = true)
    {
        $this->db      = $db ?? Db::instance();
        $this->enabled = $enabled;
    }

    /**
     * نسخهٔ غیرفعال (برای تست‌ها یا مواقع خاص).
     */
    public static function disabled(?Db $db = null): self
    {
        return new self($db, false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * آیا این آپدیت قبلاً پردازش شده است؟ (در صورت تازه بودن، ثبت می‌شود)
     *
     * فقط «همان شناسه + همان محتوا» تکراری حساب می‌شود. اگر همان عدد با
     * محتوای متفاوت دیده شود (استفادهٔ مجدد عدد)، آپدیت واقعی است و اثر
     * انگشت جدید جایگزین می‌شود.
     *
     * @return bool true یعنی تکراری است و باید رد شود
     */
    public function isDuplicateUpdate(int $updateId, string $fingerprint = ''): bool
    {
        if (!$this->enabled || $updateId <= 0) {
            return false;
        }

        try {
            $row = $this->db->first(
                'SELECT fingerprint FROM processed_updates WHERE update_id = ?',
                [$updateId]
            );

            if ($row !== null) {
                $seen = (string) ($row['fingerprint'] ?? '');

                // همان محتوا → ارسال مجدد تلگرام → رد شود.
                if ($fingerprint !== '' && $seen === $fingerprint) {
                    return true;
                }

                // ردیف قدیمی بدون اثر انگشت و محتوای نامشخص → محافظه‌کارانه رد شود.
                if ($fingerprint === '' && $seen === '') {
                    return true;
                }

                // همان عدد ولی محتوای متفاوت → واقعی است؛ اثر جدید ثبت شود.
                $this->db->run(
                    'UPDATE processed_updates SET fingerprint = ?, created_at = ? WHERE update_id = ?',
                    [$fingerprint, time(), $updateId]
                );

                return false;
            }

            $this->db->run(
                'INSERT OR IGNORE INTO processed_updates (update_id, fingerprint, created_at) VALUES (?, ?, ?)',
                [$updateId, $fingerprint, time()]
            );

            return false;
        } catch (\Throwable $e) {
            Logger::debug('FloodGuard update check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * آیا همین اکشن همین کاربر در پنجرهٔ زمانی تکرار شده است؟
     * (در صورت تازه بودن، زمانش ثبت می‌شود)
     *
     * @return bool true یعنی باید رد شود
     */
    public function isThrottled(int $telegramId, string $fingerprint, int $windowSec): bool
    {
        if (!$this->enabled || $telegramId <= 0 || $windowSec <= 0 || $fingerprint === '') {
            return false;
        }

        $key = $telegramId . ':' . $fingerprint;
        $now = time();

        try {
            $row = $this->db->first(
                'SELECT updated_at FROM flood_guard WHERE key = ?',
                [$key]
            );

            if ($row !== null && ($now - (int) $row['updated_at']) < $windowSec) {
                return true;
            }

            $this->db->run(
                'INSERT INTO flood_guard (key, updated_at) VALUES (:k, :t)
                 ON CONFLICT(key) DO UPDATE SET updated_at = excluded.updated_at',
                ['k' => $key, 't' => $now]
            );

            return false;
        } catch (\Throwable $e) {
            Logger::debug('FloodGuard throttle check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * پنجرهٔ مناسب یک namespace کال‌بک.
     *
     * صفر یعنی «محدود نشود». فقط اکشن‌های سنگین پنجره دارند؛ بقیه باز می‌مانند.
     */
    public static function callbackWindow(string $namespace): int
    {
        if (in_array($namespace, self::NEVER_THROTTLED, true)) {
            return 0;
        }

        return self::HEAVY_WINDOWS[$namespace] ?? 0;
    }

    /**
     * پاک‌سازی ردیف‌های قدیمی (توسط کرون).
     *
     * @return array{flood:int, updates:int}
     */
    public function prune(): array
    {
        $out = ['flood' => 0, 'updates' => 0];

        try {
            $out['flood'] = $this->db->run(
                'DELETE FROM flood_guard WHERE updated_at < :cutoff',
                ['cutoff' => time() - 3600]
            )->rowCount();

            $out['updates'] = $this->db->run(
                'DELETE FROM processed_updates WHERE created_at < :cutoff',
                ['cutoff' => time() - 172800]
            )->rowCount();
        } catch (\Throwable $e) {
            Logger::debug('FloodGuard prune failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * محدودیت نرخ ضد اسپم: اگر کاربر بیش از N درخواست در T ثانیه بفرستد، محدود می‌شود.
     *
     * @param int $telegramId شناسهٔ تلگرام کاربر
     * @param int $maxCount  حداکثر تعداد درخواست در پنجره
     * @param int $windowSec  طول پنجره (ثانیه)
     * @return bool true یعنی محدود شود
     */
    public function isSpamRateLimited(int $telegramId, int $maxCount = 10, int $windowSec = 60): bool
    {
        if (!$this->enabled || $telegramId <= 0) {
            return false;
        }

        $key = 'spam:' . $telegramId;
        $now = time();

        try {
            $row = $this->db->first(
                'SELECT COUNT(*) AS cnt FROM flood_guard WHERE key = ? AND updated_at >= ?',
                [$key, $now - $windowSec]
            );

            $count = (int) ($row['cnt'] ?? 0);

            if ($count >= $maxCount) {
                Logger::warning('Spam rate limit triggered', [
                    'user_id' => $telegramId,
                    'count'   => $count,
                    'window'  => $windowSec,
                ]);
                return true;
            }

            $this->db->run(
                'INSERT INTO flood_guard (key, updated_at) VALUES (:k, :t)
                 ON CONFLICT(key) DO UPDATE SET updated_at = excluded.updated_at',
                ['k' => $key, 't' => $now]
            );

            return false;
        } catch (\Throwable $e) {
            Logger::debug('Spam rate limit check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * بررسی آیا کاربر به‌دلیل اسپم مسدود شده است؟
     */
    public function isBlockedForSpam(int $telegramId): bool
    {
        if (!$this->enabled || $telegramId <= 0) {
            return false;
        }

        try {
            $row = $this->db->first(
                'SELECT updated_at FROM flood_guard WHERE key = ?',
                ['blocked:' . $telegramId]
            );

            return $row !== null && (time() - (int) $row['updated_at']) < 3600;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * مسدود کردن کاربر برای یک ساعت به‌دلیل اسپم.
     */
    public function blockForSpam(int $telegramId): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->db->run(
                'INSERT INTO flood_guard (key, updated_at) VALUES (:k, :t)
                 ON CONFLICT(key) DO UPDATE SET updated_at = excluded.updated_at',
                ['k' => 'blocked:' . $telegramId, 't' => time()]
            );
        } catch (\Throwable $e) {
            Logger::debug('Block for spam failed', ['error' => $e->getMessage()]);
        }
    }
}
