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
        $this->db->update('users', [
            'panel_status'     => $this->mapPanelStatus((string) ($adminDetails['status'] ?? 'active')),
            'panel_data_limit' => (int) ($adminDetails['data_limit'] ?? 0),
            'panel_used'       => (int) ($adminDetails['used_traffic'] ?? 0),
            'panel_synced_at'  => time(),
            'updated_at'       => time(),
        ], ['id' => $userId]);
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
        return $this->db->transaction(function () use ($userId, $bytes, $expireAt): array {
            $user = $this->findById($userId);
            if ($user === null) {
                throw new \RuntimeException('کاربر یافت نشد.');
            }

            $granted = (int) $user['granted_volume'] + $bytes;
            $currentExpire = $user['granted_expire_at'] !== null ? (int) $user['granted_expire_at'] : 0;

            // اعتبار از سقف اعتبار فعلی و زمان جدید محاسبه می‌شود.
            $newExpire = $expireAt !== null ? max($currentExpire, $expireAt) : ($currentExpire ?: null);

            $this->db->update('users', [
                'granted_volume'    => $granted,
                'granted_expire_at' => $newExpire,
                'updated_at'        => time(),
            ], ['id' => $userId]);

            $row = $this->findById($userId);

            return $row ?? [];
        });
    }

    /**
     * کسر اعتبار ساخت کاربر (بایت) — با بررسی موجودی.
     */
    public function consumeUserCredit(int $userId, int $bytes): bool
    {
        return $this->db->transaction(function () use ($userId, $bytes): bool {
            $user = $this->findById($userId);
            if ($user === null || (int) $user['user_credit'] < $bytes) {
                return false;
            }

            $this->db->update('users', [
                'user_credit' => (int) $user['user_credit'] - $bytes,
                'updated_at'   => time(),
            ], ['id' => $userId]);

            return true;
        });
    }

    public function addUserCredit(int $userId, int $bytes, ?int $expireAt = null): void
    {
        $user = $this->findById($userId);
        if ($user === null) {
            return;
        }

        $currentExpire = $user['user_credit_expire'] !== null ? (int) $user['user_credit_expire'] : 0;
        $newExpire = $expireAt !== null ? max($currentExpire, $expireAt) : ($currentExpire ?: null);

        $this->db->update('users', [
            'user_credit'        => (int) $user['user_credit'] + $bytes,
            'user_credit_expire' => $newExpire,
            'updated_at'         => time(),
        ], ['id' => $userId]);
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