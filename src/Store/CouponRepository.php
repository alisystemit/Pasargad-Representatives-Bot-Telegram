<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;

/**
 * مخزن کدهای تخفیف و سابقهٔ استفاده از آن‌ها.
 *
 * مدل کار:
 *   • کد یا **درصدی** است (`percent`) یا **مبلغ ثابتی** (`fixed`).
 *   • `max_uses` سقف استفادهٔ کل است؛ صفر یعنی نامحدود.
 *   • `per_user_limit` سقف استفادهٔ هر کاربر است؛ صفر یعنی نامحدود.
 *   • `max_discount_toman` جلوی تخفیفِ بیش از حد روی بسته‌های گران را می‌گیرد.
 *   • `expires_at` پایان اعتبار زمانی کد.
 *
 * چرا مصرف کد در جدول جدا (`coupon_uses`) و نه فقط شمارندهٔ `used_count`؟
 * چون دو چیز لازم است: (۱) شمارنده باید در همان تراکنشِ ساخت سفارش
 * به‌طور اتمیک یکی زیاد شود تا دو کلیک همزمان کد را دو بار مصرف نکنند،
 * و (۲) باید بتوان فهمید هر کاربر کدام کد را چند بار استفاده کرده.
 */
final class CouponRepository
{
    public const KIND_PERCENT = 'percent';
    public const KIND_FIXED   = 'fixed';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM coupons WHERE id = ?', [$id]);
    }

    /**
     * پیدا کردن کد (بدون حساسیت به بزرگی حروف).
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return $this->db->first('SELECT * FROM coupons WHERE code = ? COLLATE NOCASE LIMIT 1', [$code]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = time();
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($code === '') {
            $code = $this->generateCode();
        }

        // یکتا بودن کد حیاتی است: دو کد با یک نام یعنی تخفیف قابل دور زدن.
        if ($this->findByCode($code) !== null) {
            $code = $code . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        }

        return $this->db->insert('coupons', [
            'code'               => $code,
            'kind'               => (string) ($data['kind'] ?? self::KIND_PERCENT),
            'value'              => (int) ($data['value'] ?? 0),
            'max_uses'           => (int) ($data['max_uses'] ?? 0),
            'used_count'         => 0,
            'per_user_limit'     => (int) ($data['per_user_limit'] ?? 1),
            'min_order_toman'    => (int) ($data['min_order_toman'] ?? 0),
            'max_discount_toman' => (int) ($data['max_discount_toman'] ?? 0),
            'expires_at'         => !empty($data['expires_at']) ? (int) $data['expires_at'] : null,
            'is_active'          => (int) ($data['is_active'] ?? 1),
            'note'               => isset($data['note']) ? (string) $data['note'] : null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = time();
        $this->db->update('coupons', $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('coupons', ['id' => $id]);
    }

    /**
     * فهرست کدها برای پنل مدیریت.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $limit = 50, int $offset = 0): array
    {
        return $this->db->all(
            'SELECT * FROM coupons ORDER BY id DESC LIMIT ' . max(1, min($limit, 200))
            . ' OFFSET ' . max(0, $offset)
        );
    }

    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM coupons');
    }

    public function countActive(): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM coupons
             WHERE is_active = 1
               AND (expires_at IS NULL OR expires_at > :now)',
            ['now' => time()]
        );
    }

    /**
     * تعداد دفعات استفادهٔ یک کاربر از یک کد.
     */
    public function usedByUser(int $couponId, int $userId): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM coupon_uses WHERE coupon_id = ? AND user_id = ?',
            [$couponId, $userId]
        );
    }

    /**
     * ثبت مصرف کد.
     *
     * شمارندهٔ کد **اتمیک** زیاد می‌شود: اگر کد به سقف رسیده باشد UPDATE
     * هیچ ردیفی را عوض نمی‌کند و تابع false می‌دهد. اگر ابتدا شمارنده را
     * می‌خواندیم و بعد می‌نوشتیم، دو درخواست همزمان هر دو سقف را رد می‌کردند.
     */
    public function consume(int $couponId, int $userId, int $orderId, int $discountToman): bool
    {
        $ok = false;

        $this->db->transaction(function () use ($couponId, $userId, $orderId, $discountToman, &$ok): void {
            // «ظرفیت تمام شد» یک نتیجهٔ قابل انتظار است، نه استثنا: بین بررسی
            // اولیه و این لحظه ممکن است کاربر دیگری کد را مصرف کرده باشد.
            // استثنا باعث می‌شد کل تراکنشِ ساخت سفارش برگردد.
            $updated = $this->db->run(
                'UPDATE coupons
                 SET used_count = used_count + 1, updated_at = :t
                 WHERE id = :id
                   AND is_active = 1
                   AND (max_uses = 0 OR used_count < max_uses)',
                ['t' => time(), 'id' => $couponId]
            )->rowCount();

            if ($updated !== 1) {
                $ok = false;
                return;
            }

            $this->db->insert('coupon_uses', [
                'coupon_id'      => $couponId,
                'user_id'        => $userId,
                'order_id'       => $orderId > 0 ? $orderId : null,
                'discount_toman' => max(0, $discountToman),
                'created_at'     => time(),
            ]);

            $ok = true;
        });

        return $ok;
    }

    /**
     * کد تصادفی خوانا (بدون نویسه‌های گیج‌کنندهٔ ۰/O و ۱/l).
     */
    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = '';

            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if ($this->findByCode($code) === null) {
                return $code;
            }
        }

        return 'C' . strtoupper(bin2hex(random_bytes(4)));
    }
}