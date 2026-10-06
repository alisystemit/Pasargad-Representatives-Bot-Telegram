<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;

/**
 * محافظتِ تلاش‌های ورود/اعتبارسنجی — ضد brute-force و لاگ امنیتی.
 */
final class Security
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    public function isLocked(int $telegramId, string $kind): bool
    {
        $row = $this->db->first(
            'SELECT locked_until FROM login_attempts WHERE telegram_id = ? AND kind = ?',
            [$telegramId, $kind]
        );

        if ($row === null) {
            return false;
        }

        return (int) ($row['locked_until'] ?? 0) > time();
    }

    /**
     * ثبت یک تلاش ناموفق؛ در صورت رسیدن به سقف، قفل فعال می‌شود.
     *
     * @return array{locked:bool, locked_until:int, attempts:int}
     */
    public function recordFailure(int $telegramId, string $kind, int $maxAttempts, int $lockSeconds): array
    {
        $now = time();
        $row = $this->db->first(
            'SELECT attempts, locked_until FROM login_attempts WHERE telegram_id = ? AND kind = ?',
            [$telegramId, $kind]
        );

        $attempts = (is_array($row) ? (int) $row['attempts'] : 0) + 1;
        $lockedUntil = (is_array($row) ? (int) $row['locked_until'] : 0);

        if ($attempts >= $maxAttempts) {
            $lockedUntil = $now + $lockSeconds;
            $attempts = 0; // بعد از قفل، شمارنده صفر تا بعد از مهلت
        }

        $this->db->run(
            'INSERT INTO login_attempts (telegram_id, kind, attempts, locked_until, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(telegram_id, kind) DO UPDATE SET
               attempts = excluded.attempts,
               locked_until = excluded.locked_until,
               updated_at = excluded.updated_at',
            [$telegramId, $kind, $attempts, $lockedUntil, $now]
        );

        return ['locked' => $lockedUntil > $now, 'locked_until' => $lockedUntil, 'attempts' => $attempts];
    }

    /** پاک‌شدن قفل بعد از تلاش موفق/انقضای مهلت */
    public function clear(int $telegramId, string $kind): void
    {
        $this->db->run(
            'DELETE FROM login_attempts WHERE telegram_id = ? AND kind = ?',
            [$telegramId, $kind]
        );
    }
}
