<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * مخزن کاربران ربات (نمایندگان/ادمین‌های پنل).
 *
 * چون ربات باید بستهٔ خریداری‌شده را خودکار روی پنل اعمال کند، رمز عبور پنل
 * هر کاربر رمزنگاری و در همین جدول ذخیره می‌شود.
 */
final class UserRepository
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTelegramId(int $telegramId): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE telegram_id = ?', [$telegramId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPanelUsername(string $panelUsername): ?array
    {
        return $this->db->first(
            'SELECT * FROM users WHERE panel_username = ? COLLATE NOCASE ORDER BY id ASC LIMIT 1',
            [trim($panelUsername)]
        );
    }

    /**
     * ثبت یا به‌روزرسانی کاربر بر اساس آیدی تلگرام.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsertByTelegram(int $telegramId, array $data): array
    {
        $existing = $this->findByTelegramId($telegramId);
        $now      = time();

        $payload = array_merge($data, ['updated_at' => $now]);

        if ($existing === null) {
            $id = $this->db->insert('users', array_merge([
                'telegram_id' => $telegramId,
                'created_at'  => $now,
                'last_seen_at' => $now,
            ], $payload));

            $row = $this->findById($id);
        } else {
            $payload['last_seen_at'] = $now;
            $this->db->update('users', $payload, ['id' => $existing['id']]);
            $row = $this->findById((int) $existing['id']);
        }

        if ($row === null) {
            throw new \RuntimeException('کاربر پس از ذخیره‌سازی قابل بازیابی نبود.');
        }

        return $row;
    }

    /**
     * به‌روزرسانی عمومی فیلدهای کاربر.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $userId, array $data): void
    {
        $data['updated_at'] = time();
        $this->db->update('users', $data, ['id' => $userId]);
    }

    public function touch(int $userId): void
    {
        $this->db->update('users', ['last_seen_at' => time(), 'updated_at' => time()], ['id' => $userId]);
    }

    /**
     * اتصال حساب پنل به کاربر تلگرام (پس از ورود موفق).
     *
     * @param array<string, mixed> $adminDetails پاسخ GET /api/admin/{username}
     */
    public function linkPanel(int $userId, string $panelUsername, string $password, array $adminDetails): void
    {
        $status = $this->mapPanelStatus((string) ($adminDetails['status'] ?? 'active'));

        $this->db->update('users', [
            'panel_username'   => $panelUsername,
            'panel_password'   => Crypto::encrypt($password),
            'panel_user_id'    => $this->extractId($adminDetails),
            'panel_status'     => $status,
            'panel_data_limit' => (int) ($adminDetails['data_limit'] ?? 0),
            'panel_used'       => (int) ($adminDetails['used_traffic'] ?? 0),
            'panel_role'       => isset($adminDetails['role']['name']) ? (string) $adminDetails['role']['name'] : null,
            'panel_is_owner'   => !empty($adminDetails['role']['is_owner']) ? 1 : 0,
            'panel_synced_at'  => time(),
            'updated_at'       => time(),
        ], ['id' => $userId]);

        Logger::info('Panel account linked', [
            'user_id'        => $userId,
            'panel_username' => $panelUsername,
            'status'         => $status,
        ]);
    }

    /**
     * به‌روزرسانی اطلاعات پنل پس از همگام‌سازی.
     *
     * @param array<string, mixed> $adminDetails
     */
    public function syncPanelState(int $userId, array $adminDetails): void
    {
        // فقط فیلدهایی که واقعاً در پاسخ پنل آمده‌اند به‌روز می‌شوند.
        // پاسخ PUT ممکن است ناقص باشد؛ اگر کلیدی نبود نباید مقدار قبلی صفر شود
        // چون «نامحدود» تلقی می‌شود و هشدار حجم از کار می‌افتد.
        $payload = [
            'panel_synced_at' => time(),
            'updated_at'      => time(),
        ];

        if (isset($adminDetails['status'])) {
            $payload['panel_status'] = $this->mapPanelStatus((string) $adminDetails['status']);
        }
        if (isset($adminDetails['data_limit']) && is_numeric($adminDetails['data_limit'])) {
            $payload['panel_data_limit'] = (int) $adminDetails['data_limit'];
        }
        if (isset($adminDetails['used_traffic']) && is_numeric($adminDetails['used_traffic'])) {
            $payload['panel_used'] = (int) $adminDetails['used_traffic'];
        }

        $this->db->update('users', $payload, ['id' => $userId]);
    }

    public function unlinkPanel(int $userId): void
    {
        $this->db->update('users', [
            'panel_username' => null,
            'panel_password' => null,
            'panel_status'   => 'pending',
            'panel_synced_at' => null,
            'updated_at'     => time(),
        ], ['id' => $userId]);
    }

    /**
     * افزودن حجم هدیه‌شده (بایت) به حساب کاربر.
     *
     * @return array<string, mixed> کاربر به‌روزشده
     */
    public function addGrantedVolume(int $userId, int $bytes, ?int $expireAt = null): array
    {
        if ($bytes <= 0) {
            throw new \RuntimeException('مقدار حجم هدیه‌شده باید بزرگ‌تر از صفر باشد.');
        }

        if ($expireAt !== null) {
            $this->db->run(
                'UPDATE users
                 SET granted_volume    = granted_volume + :b,
                     granted_expire_at = CASE
                         WHEN granted_expire_at IS NULL OR granted_expire_at < :e THEN :e
                         ELSE granted_expire_at
                     END,
                     updated_at        = :now
                 WHERE id = :id',
                ['b' => $bytes, 'e' => $expireAt, 'now' => time(), 'id' => $userId]
            );
        } else {
            $this->db->run(
                'UPDATE users SET granted_volume = granted_volume + :b, updated_at = :now WHERE id = :id',
                ['b' => $bytes, 'now' => time(), 'id' => $userId]
            );
        }

        return $this->findById($userId) ?? [];
    }

    /**
     * کسر اعتبار ساخت کاربر (بایت) به‌صورت اتمیک.
     *
     * شرط موجودی داخل خودِ UPDATE است تا دو درخواست همزمان (مثلاً دو بار
     * زدن دکمه یا همزمانی وبهوک و کرون) نتوانند بیش از موجودی خرج کنند.
     * برمی‌گرداند true اگر کسر انجام شد.
     */
    public function consumeUserCredit(int $userId, int $bytes): bool
    {
        if ($bytes <= 0) {
            return false;
        }

        return $this->db->run(
            'UPDATE users SET user_credit = user_credit - :b, updated_at = :now
             WHERE id = :id AND user_credit >= :b',
            ['b' => $bytes, 'now' => time(), 'id' => $userId]
        )->rowCount() > 0;
    }

    /**
     * افزودن اعتبار ساخت کاربر به‌صورت اتمیک.
     */
    public function addUserCredit(int $userId, int $bytes, ?int $expireAt = null): void
    {
        if ($bytes <= 0) {
            return;
        }

        // افزایش حجم و تمدید انقضا در یک دستور تا خواندن-نوشتنِ جداگانه لازم نباشد.
        if ($expireAt !== null) {
            $this->db->run(
                'UPDATE users
                 SET user_credit        = user_credit + :b,
                     user_credit_expire  = CASE
                         WHEN user_credit_expire IS NULL OR user_credit_expire < :e THEN :e
                         ELSE user_credit_expire
                     END,
                     updated_at          = :now
                 WHERE id = :id',
                ['b' => $bytes, 'e' => $expireAt, 'now' => time(), 'id' => $userId]
            );

            return;
        }

        $this->db->run(
            'UPDATE users SET user_credit = user_credit + :b, updated_at = :now WHERE id = :id',
            ['b' => $bytes, 'now' => time(), 'id' => $userId]
        );
    }

    public function setBlocked(int $userId, bool $blocked, string $reason = ''): void
    {
        $this->db->update('users', [
            'is_blocked'     => $blocked ? 1 : 0,
            'blocked_reason' => $blocked ? Str::truncate($reason, 200) : null,
            'updated_at'     => time(),
        ], ['id' => $userId]);
    }

    /**
     * شمارش سفارش‌ها و مجموع پرداخت‌های کاربر.
     */
    public function refreshOrderStats(int $userId): void
    {
        $row = $this->db->first(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(price_toman), 0) AS total
             FROM orders WHERE user_id = ? AND status IN ('paid','applied')",
            [$userId]
        );

        $this->db->update('users', [
            'orders_count' => (int) ($row['cnt'] ?? 0),
            'total_paid'   => (int) ($row['total'] ?? 0),
            'updated_at'   => time(),
        ], ['id' => $userId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $limit = 100, int $offset = 0, string $search = ''): array
    {
        $limit  = max(1, min($limit, 500));
        $offset = max(0, $offset);

        if ($search !== '') {
            $like = '%' . $search . '%';
            return $this->db->all(
                'SELECT * FROM users
                 WHERE panel_username LIKE :like OR CAST(telegram_id AS TEXT) LIKE :like OR username LIKE :like
                 ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['like' => $like]
            );
        }

        return $this->db->all(
            'SELECT * FROM users ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
    }

    public function countAll(string $search = ''): int
    {
        if ($search !== '') {
            $like = '%' . $search . '%';
            return $this->db->count(
                'SELECT COUNT(*) FROM users
                 WHERE panel_username LIKE :like OR CAST(telegram_id AS TEXT) LIKE :like OR username LIKE :like',
                ['like' => $like]
            );
        }

        return $this->db->count('SELECT COUNT(*) FROM users');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLinkedAdmins(): array
    {
        return $this->db->all(
            "SELECT * FROM users WHERE panel_username IS NOT NULL AND panel_status IN ('active','limited')"
        );
    }

    /**
     * استخراج شناسهٔ عددی از پاسخ پنل.
     *
     * @param array<string, mixed> $adminDetails
     */
    private function extractId(array $adminDetails): ?int
    {
        if (isset($adminDetails['id']) && is_numeric($adminDetails['id'])) {
            return (int) $adminDetails['id'];
        }

        return null;
    }

    /**
     * تبدیل وضعیت پنل به وضعیت داخلی ربات.
     */
    private function mapPanelStatus(string $status): string
    {
        return match ($status) {
            'active'   => 'active',
            'limited'  => 'limited',
            'disabled' => 'disabled',
            default    => 'pending',
        };
    }
}