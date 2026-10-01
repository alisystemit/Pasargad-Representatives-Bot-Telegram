<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Db;

/**
 * ذخیرهٔ وضعیت موقت کاربر (مثلاً مرحلهٔ ورود) با انقضای خودکار.
 *
 * از جدول settings برای نگهداری استفاده می‌کند تا نیازی به مایگریشن
 * جداگانه نباشد؛ هر کاربر یک ردیف با کلید session:{telegram_id} دارد.
 */
final class SessionStore
{
    private const TTL = 900;   // ۱۵ دقیقه

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * خواندن مرحلهٔ فعلی کاربر (یا null).
     *
     * @return array<string, mixed>|null
     */
    public function get(int $telegramId): ?array
    {
        $row = $this->db->first(
            'SELECT value, updated_at FROM settings WHERE key = ?',
            [$this->key($telegramId)]
        );

        if ($row === null) {
            return null;
        }

        if ((int) $row['updated_at'] < time() - self::TTL) {
            $this->clear($telegramId);
            return null;
        }

        $data = json_decode((string) $row['value'], true);

        return is_array($data) ? $data : null;
    }

    /**
     * ذخیرهٔ وضعیت جاری.
     *
     * @param array<string, mixed> $data
     */
    public function set(int $telegramId, array $data): void
    {
        $this->db->run(
            'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [
                'k' => $this->key($telegramId),
                'v' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
                't' => time(),
            ]
        );
    }

    /**
     * خواندن و پاک کردن وضعیت (برای مراحلی که یک‌بار مصرف می‌شوند).
     *
     * @return array<string, mixed>|null
     */
    public function pull(int $telegramId): ?array
    {
        $data = $this->get($telegramId);
        $this->clear($telegramId);

        return $data;
    }

    public function clear(int $telegramId): void
    {
        $this->db->delete('settings', ['key' => $this->key($telegramId)]);
    }

    /**
     * پاک‌سازی نشست‌های منقضی‌شده (توسط کرون).
     *
     * فقط ردیف‌های کلید session:* حذف می‌شوند تا تنظیمات فروشگاه دست‌نخورده بماند.
     */
    public function prune(): int
    {
        return $this->db->run(
            "DELETE FROM settings WHERE key LIKE 'session:%' AND updated_at < :cutoff",
            ['cutoff' => time() - self::TTL]
        )->rowCount();
    }

    private function key(int $telegramId): string
    {
        return 'session:' . $telegramId;
    }
}