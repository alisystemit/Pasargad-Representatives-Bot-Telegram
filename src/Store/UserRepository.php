<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * مخزن کاربران ربات (نمایندگان).
 *
 * این جدول فقط **هویت رباتی** کاربر را نگه می‌دارد: آیدی تلگرام، نام، وضعیت
 * مسدودی و آمار خرید.
 *
 * پنل‌ها جدا نگهداری می‌شوند (جدول `panels`) چون هر نماینده می‌تواند **چند پنل**
 * داشته باشد و حجم/انقضا/هشدار هر پنل مستقل است. اطلاعات ورود پنل قبلاً روی
 * همین جدول بود و در مایگریشن ۵ به `panels` منتقل شد.
 *
 * ستون‌های `panel_*` و `user_credit` برای سازگاری با داده‌های قدیمی باقی
 * مانده‌اند ولی دیگر منبع حقیقت نیستند.
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
     * پیدا کردن کاربری که این پنل به او تعلق دارد (بدون حساسیت به حروف بزرگ).
     *
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
     * به‌روزرسانی وضعیت پنل کاربر (از مسیرهای قدیمی که کلید کاربر می‌دادند).
     *
     * نگه داشته شده برای سازگاری، ولی فقط **اولین** پنل کاربر را تغییر می‌دهد.
     * مسیر درست، استفاده از PanelRepository است.
     *
     * @param array<string, mixed> $adminDetails پاسخ GET /api/admin/{username}
     */
    public function linkPanel(int $userId, string $panelUsername, string $password, array $adminDetails): void
    {
        $this->db->update('users', [
            'panel_username'   => $panelUsername,
            'panel_password'   => Crypto::encrypt($password),
            'panel_user_id'    => $this->extractId($adminDetails),
            'panel_status'     => PanelRepository::mapStatus((string) ($adminDetails['status'] ?? 'active')),
            'panel_data_limit' => (int) ($adminDetails['data_limit'] ?? 0),
            'panel_used'       => (int) ($adminDetails['used_traffic'] ?? 0),
            'panel_role'       => isset($adminDetails['role']['name']) ? (string) $adminDetails['role']['name'] : null,
            'panel_is_owner'   => !empty($adminDetails['role']['is_owner']) ? 1 : 0,
            'panel_synced_at'  => time(),
            'updated_at'       => time(),
        ], ['id' => $userId]);

        // هم‌زمان در جدول پنل‌ها هم ثبت می‌شود تا از این لحظه موجود باشد.
        (new PanelRepository($this->db))->upsertFromPanel($userId, $panelUsername, $password, $adminDetails);

        Logger::info('Panel account linked', [
            'user_id'        => $userId,
            'panel_username' => $panelUsername,
        ]);
    }

    /**
     * قطع اتصال پنل (از مسیر قدیمی /logout).
     *
     * پنل‌های واقعی حذف نمی‌شوند — فقط ربات دیگر آن‌ها را مدیریت نمی‌کند.
     */
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
     * کاربرانی که حداقل یک پنل متصل دارند.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLinkedAdmins(): array
    {
        return $this->db->all(
            "SELECT u.* FROM users u
             INNER JOIN panels p ON p.user_id = u.id
             WHERE u.is_blocked = 0
             GROUP BY u.id"
        );
    }

    /**
     * نماینده‌هایی که پنلشان رو به اتمام یا منقضی شده است.
     *
     * برای داشبورد مدیریت استفاده می‌شود تا ادمین بدون گشتن بین صفحه‌ها بفهمد
     * کدام نماینده‌ها نیاز به تمدید دارند.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAtRiskRepresentatives(int $limit = 20): array
    {
        return $this->db->all(
            "SELECT u.id, u.telegram_id, u.username, u.first_name,
                    COUNT(p.id) AS panel_count,
                    SUM(CASE WHEN p.access_expire_at IS NOT NULL
                              AND p.access_expire_at <= :now THEN 1 ELSE 0 END) AS expired_count,
                    MIN(p.access_expire_at) AS soonest_expire
             FROM users u
             INNER JOIN panels p ON p.user_id = u.id
             WHERE u.is_blocked = 0
             GROUP BY u.id
             HAVING expired_count > 0
                OR soonest_expire <= :soon
             ORDER BY soonest_expire ASC
             LIMIT " . max(1, min($limit, 100)),
            ['now' => time(), 'soon' => time() + 7 * 86400]
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

    // ------------------------------------------------------------------
    // کیف پول 💰
    // ------------------------------------------------------------------

    /**
     * موجودی کیف پول کاربر (تومان).
     */
    public function walletBalance(int $userId): int
    {
        $row = $this->db->first('SELECT wallet_balance FROM users WHERE id = ?', [$userId]);

        return (int) ($row['wallet_balance'] ?? 0);
    }

    /**
     * شارژ/کسر کیف پول + ثبت در تاریخچه.
     *
     * `$kind` دلیل تراکنش را جدا می‌کند تا تاریخچه قابل تفکیک باشد:
     * `manual` (ادمین)، `referral` (پاداش معرفی)، `wallet_pay` (پرداخت با
     * کیف پول).
     *
     * @return array{ok:bool, message:string, balance:int}
     */
    public function adjustWallet(int $userId, int $amount, string $note = '', int $adminId = 0, string $kind = 'manual'): array
    {
        if ($amount === 0) {
            return ['ok' => false, 'message' => 'مبلغ صفر است.', 'balance' => $this->walletBalance($userId)];
        }

        $balance = 0;

        $this->db->transaction(function () use ($userId, $amount, $note, $adminId, $kind, &$balance): void {
            $current = $this->walletBalance($userId);
            $balance = $current + $amount;

            // موجودی منفی نمی‌شود — به‌جای بدهکار کردن، تا صفر کم می‌شود
            if ($balance < 0) {
                $amount = -$current;
                $balance = 0;
            }

            $this->db->run(
                'UPDATE users SET wallet_balance = wallet_balance + :a, updated_at = :t WHERE id = :id',
                ['a' => $amount, 't' => time(), 'id' => $userId]
            );

            $this->db->insert('wallet_txns', [
                'user_id'    => $userId,
                'amount'     => $amount,
                'kind'       => in_array($kind, ['manual', 'referral', 'wallet_pay'], true) ? $kind : 'manual',
                'note'       => Str::truncate($note !== '' ? $note : ($amount > 0 ? 'شارژ توسط مدیریت' : 'کسر توسط مدیریت'), 300),
                'admin_id'   => $adminId > 0 ? $adminId : null,
                'created_at' => time(),
            ]);
        });

        return [
            'ok'      => true,
            'message' => $amount > 0 ? 'کیف پول شارژ شد.' : 'از کیف پول کسر شد.',
            'balance' => $balance,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function walletHistory(int $userId, int $limit = 15): array
    {
        if (!$this->db->tableExists('wallet_txns')) {
            return [];
        }

        return $this->db->all(
            'SELECT * FROM wallet_txns WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min($limit, 30)),
            [$userId]
        );
    }

    // ------------------------------------------------------------------
    // کد تخفیف فعال
    // ------------------------------------------------------------------

    /**
     * کد تخفیف فعال کاربر (خالی یعنی ندارد).
     */
    public function couponCode(int $userId): string
    {
        $row = $this->db->first('SELECT coupon_code FROM users WHERE id = ?', [$userId]);

        return trim((string) ($row['coupon_code'] ?? ''));
    }

    public function setCouponCode(int $userId, string $code): void
    {
        $code = strtoupper(trim($code));

        $this->db->run(
            'UPDATE users SET coupon_code = :c, updated_at = :t WHERE id = :id',
            ['c' => $code !== '' ? $code : null, 't' => time(), 'id' => $userId]
        );
    }

    /**
     * @return bool آیا کدی بود که حذف شد؟
     */
    public function clearCouponCode(int $userId): bool
    {
        if ($this->couponCode($userId) === '') {
            return false;
        }

        $this->setCouponCode($userId, '');

        return true;
    }
}