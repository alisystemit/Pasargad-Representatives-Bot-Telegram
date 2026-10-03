<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;

/**
 * مخزن کانفیگ‌های تست (یوزرهای رایگان ساخته‌شده روی پنل نماینده).
 *
 * «تست کانفیگ» یعنی ربات روی **پنل خودِ نماینده** یک یوزر موقت با حجم و زمان
 * کم می‌سازد تا نماینده بتواند سرویس را قبل از فروش به مشتری واقعی امتحان کند.
 *
 * این قابلیت عمداً محدود است (سقف تعداد و فاصلهٔ زمانی)، وگرنه یک ربات‌فروش
 * می‌شد: هر فراخوانی یک یوزر رایگان روی پنل می‌ساخت.
 *
 * @psalm-type TestConfigRow = array<string, mixed>
 */
final class TestConfigRepository
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_DISABLED = 'disabled';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $panelId, int $userId, string $panelUsername, array $data): int
    {
        $now = time();

        return $this->db->insert('test_configs', array_merge([
            'panel_id'       => $panelId,
            'user_id'        => $userId,
            'panel_username' => $panelUsername,
            'status'         => self::STATUS_ACTIVE,
            'issued_at'      => $now,
        ], $data));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM test_configs WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->db->first(
            'SELECT * FROM test_configs WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * کانفیگ‌های تست یک نماینده، تازه‌ترین اول.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(int $userId, int $limit = 10): array
    {
        return $this->db->all(
            'SELECT * FROM test_configs WHERE user_id = ?
             ORDER BY id DESC LIMIT ' . max(1, min($limit, 50)),
            [$userId]
        );
    }

    /**
     * کانفیگ‌های تست یک پنل مشخص.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByPanel(int $panelId): array
    {
        return $this->db->all(
            'SELECT * FROM test_configs WHERE panel_id = ? ORDER BY id DESC',
            [$panelId]
        );
    }

    /**
     * تعداد کانفیگ‌های تست **فعال** یک نماینده (سقف خرید/دریافت روی این است).
     */
    public function countActiveByUser(int $userId, ?int $now = null): int
    {
        $now = $now ?? time();

        return $this->db->count(
            "SELECT COUNT(*) FROM test_configs
             WHERE user_id = ? AND status = ? AND expire_at > ?",
            [$userId, self::STATUS_ACTIVE, $now]
        );
    }

    /**
     * آخرین زمان دریافت کانفیگ تست (برای فاصلهٔ زمانی).
     */
    public function lastIssuedAt(int $userId): int
    {
        return (int) ($this->db->value(
            'SELECT COALESCE(MAX(issued_at), 0) FROM test_configs WHERE user_id = ?',
            [$userId]
        ) ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->update('test_configs', $data, ['id' => $id]);
    }

    /**
     * غیرفعال کردن یک کانفیگ تست (فقط همین رکورد؛ حذف نمی‌شود تا سابقه بماند).
     */
    public function disable(int $id): void
    {
        $this->update($id, [
            'status'      => self::STATUS_DISABLED,
            'disabled_at' => time(),
        ]);
    }

    /**
     * علامت‌گذاری کانفیگ‌های منقضی‌شده (تمیزکاری وضعیت).
     *
     * @return int تعداد رکوردهای تغییریافته
     */
    public function markExpired(?int $now = null): int
    {
        return $this->db->run(
            "UPDATE test_configs SET status = ?
             WHERE status = ? AND expire_at <= ?",
            [self::STATUS_EXPIRED, self::STATUS_ACTIVE, $now ?? time()]
        )->rowCount();
    }

    /**
     * پاک‌سازی رکوردهای قدیمیِ کانفیگ تست (نگهداری ۹۰ روز اخیر).
     */
    public function prune(int $keepDays = 90): int
    {
        return $this->db->run(
            'DELETE FROM test_configs WHERE issued_at < :cutoff',
            ['cutoff' => time() - max(1, $keepDays) * 86400]
        )->rowCount();
    }
}