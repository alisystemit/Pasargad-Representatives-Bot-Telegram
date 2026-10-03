<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;

/**
 * مخزن معرفی کاربر (زیرشاخه‌ها).
 *
 * کد معرفی **قطعی و بدون جدول** ساخته می‌شود: `R` + شمارهٔ کاربر. دلیل اینکه
 * نیازی به تولید/نگهداری شناسه نیست و هرگز نمی‌تواند تکراری یا حدس‌ناپذیرِ
 * ناخواسته باشد. جدول `referrals` فقط «چه کسی چه کسی را آورد و آیا پاداشش
 * داده شد» را نگه می‌دارد.
 *
 * قید یکتای `referee_user_id` تضمین می‌کند هر کاربر تازه فقط یک معرف داشته
 * باشد؛ وگرنه کاربر می‌توانست با یک کد معرفی، پاداش بی‌نهایت بگیرد.
 */
final class ReferralRepository
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * کد معرفی یک کاربر.
     */
    public static function codeFor(int $userId): string
    {
        return 'R' . $userId;
    }

    /**
     * کاربری که این کد معرفی به او تعلق دارد.
     *
     * @return array<string, mixed>|null رکورد users
     */
    public function findReferrerByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        // فقط پیشوند R را می‌پذیریم؛ بقیهٔ رشته باید عدد باشد.
        if (!preg_match('/^R(\d{1,10})$/', $code, $m)) {
            return null;
        }

        $referrerId = (int) $m[1];

        if ($referrerId <= 0) {
            return null;
        }

        $referrer = $this->db->first('SELECT * FROM users WHERE id = ?', [$referrerId]);

        if ($referrer === null || !empty($referrer['is_blocked'])) {
            return null;
        }

        return $referrer;
    }

    /**
     * ثبت اینکه کاربر تازه با کدِ چه کسی آمده است.
     *
     * @return array{ok:bool, message:string}
     */
    public function bind(int $referrerId, int $refereeId, int $bonusToman): array
    {
        if ($referrerId <= 0 || $refereeId <= 0) {
            return ['ok' => false, 'message' => 'کد معرفی نامعتبر است.'];
        }

        if ($referrerId === $refereeId) {
            return ['ok' => false, 'message' => 'نمی‌توانید خودتان را معرفی کنید. 😄'];
        }

        $existing = $this->findByReferee($refereeId);

        if ($existing !== null) {
            return ['ok' => false, 'message' => 'شما قبلاً با کد معرفی وارد شده‌اید.'];
        }

        try {
            $this->db->insert('referrals', [
                'referrer_user_id' => $referrerId,
                'referee_user_id'  => $refereeId,
                'code'             => self::codeFor($referrerId),
                'bonus_toman'      => max(0, $bonusToman),
                'rewarded_at'      => null,
                'created_at'       => time(),
            ]);
        } catch (\Throwable $e) {
            // قید یکتای referee_user_id یعنی دو درخواست همزمان فقط یکی موفق می‌شود.
            return ['ok' => false, 'message' => 'ثبت معرفی ناموفق بود.'];
        }

        return ['ok' => true, 'message' => 'معرفی ثبت شد.'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByReferee(int $refereeId): ?array
    {
        return $this->db->first('SELECT * FROM referrals WHERE referee_user_id = ?', [$refereeId]);
    }

    /**
     * معرفی‌های یک کاربر (کسانی که او آورده).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByReferrer(int $referrerId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT r.*, u.telegram_id, u.first_name
             FROM referrals r
             LEFT JOIN users u ON u.id = r.referee_user_id
             WHERE r.referrer_user_id = :id
             ORDER BY r.id DESC
             LIMIT ' . max(1, min($limit, 200)),
            ['id' => $referrerId]
        );
    }

    /**
     * معرفی‌هایی که پاداششان هنوز داده نشده ولی معرفی‌شده یک خرید موفق
     * داشته است.
     *
     * پاداش فقط بعد از **پرداخت واقعی** داده می‌شود، نه بعد از ثبت سفارش؛
     * وگرنه یک نفر می‌توانست چند سفارش باز ثبت کند و پاداش بگیرد و هرگز
     * پرداخت نکند.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUnrewarded(int $limit = 20): array
    {
        return $this->db->all(
            "SELECT r.*, u.telegram_id, u.first_name
             FROM referrals r
             INNER JOIN users u ON u.id = r.referee_user_id
             WHERE r.rewarded_at IS NULL
               AND r.bonus_toman > 0
               AND EXISTS (
                   SELECT 1 FROM orders o
                   WHERE o.user_id = r.referee_user_id
                     AND o.status IN ('paid','applied')
                     AND o.paid_at IS NOT NULL
               )
             ORDER BY r.id ASC
             LIMIT " . max(1, min($limit, 200))
        );
    }

    public function countByReferrer(int $referrerId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = ?', [$referrerId]);
    }

    /**
     * علامت‌گذاری پاداش این معرفی به‌عنوان پرداخت‌شده.
     */
    public function markRewarded(int $referralId): void
    {
        $this->db->update('referrals', ['rewarded_at' => time()], ['id' => $referralId]);
    }
}