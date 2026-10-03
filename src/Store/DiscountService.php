<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Logger;

/**
 * منطق تخفیف: کد تخفیف + معرفی کاربر.
 *
 * چرا یک سرویس جدا و نه منطق داخل Kernel؟
 *   • محاسبهٔ مبلغ نهایی باید **همه‌جا** یکی باشد (صفحهٔ خرید، ساخت سفارش،
 *     بررسی پرداخت، فاکتور). اگر دو جا جدا حساب شود، یکی از آن‌ها تخفیف
 *     را اعمال نمی‌کند و کاربر یا پول اضافه می‌دهد یا بستهٔ بیشتری می‌گیرد.
 *   • مصرف کد و پاداش معرفی هر دو نیاز به تراکنش دیتابیس دارند که در لایهٔ
 *     Bot نباید نوشته شود.
 *
 * قرارداد مبلغ:
 *   `orders.price_toman`        مبلغ **قابل پرداخت** (بعد از تخفیف)
 *   `orders.original_price_toman` مبلغ **لیست** (قبل از تخفیف)
 *   `orders.discount_toman`     اختلاف
 *
 * چرا `price_toman` مبلغ نهایی است و نه مبلغ لیست؟ چون بقیهٔ سیستم
 * (بررسی مبلغ IPN، رسید کارت، اجرای بسته، آمار درآمد) همه `price_toman` را
 * می‌خوانند. اگر آن را دست‌نخورده بگذاریم و تخفیف را جای دیگری اعمال کنیم،
 * درگاه پرداخت مبلغ اشتباه می‌گیرد.
 */
final class DiscountService
{
    private CouponRepository $coupons;
    private ReferralRepository $referrals;
    private Settings $settings;
    private \Pasargad\Support\Db $db;

    public function __construct(
        ?CouponRepository $coupons = null,
        ?ReferralRepository $referrals = null,
        ?Settings $settings = null,
        ?\Pasargad\Support\Db $db = null
    ) {
        $this->db        = $db ?? \Pasargad\Support\Db::instance();
        $this->coupons   = $coupons ?? new CouponRepository($this->db);
        $this->referrals = $referrals ?? new ReferralRepository($this->db);
        $this->settings  = $settings ?? new Settings($this->db);
    }

    public function coupons(): CouponRepository
    {
        return $this->coupons;
    }

    public function referrals(): ReferralRepository
    {
        return $this->referrals;
    }

    // ------------------------------------------------------------------
    // کد تخفیف
    // ------------------------------------------------------------------

    /**
     * بررسی اعتبار یک کد تخفیف برای یک کاربر و یک مبلغ.
     *
     * @return array{ok:bool, message:string, discount:int, coupon?:array<string, mixed>}
     */
    public function quote(string $code, int $userId, int $priceToman): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['ok' => false, 'message' => 'کد تخفیف را وارد کنید.', 'discount' => 0];
        }

        $coupon = $this->coupons->findByCode($code);

        if ($coupon === null) {
            return ['ok' => false, 'message' => 'کد تخفیف یافت نشد. 🔍', 'discount' => 0];
        }

        if ((int) $coupon['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'این کد تخفیف غیرفعال شده است. 😔', 'discount' => 0];
        }

        if ($coupon['expires_at'] !== null && (int) $coupon['expires_at'] <= time()) {
            return ['ok' => false, 'message' => 'اعتبار این کد تخفیف تمام شده است. ⏳', 'discount' => 0];
        }

        $maxUses = (int) $coupon['max_uses'];

        if ($maxUses > 0 && (int) $coupon['used_count'] >= $maxUses) {
            return ['ok' => false, 'message' => 'ظرفیت این کد تخفیف تمام شده است.', 'discount' => 0];
        }

        $perUser = (int) $coupon['per_user_limit'];

        if ($perUser > 0 && $this->coupons->usedByUser((int) $coupon['id'], $userId) >= $perUser) {
            return [
                'ok'       => false,
                'message'  => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید. 🙃',
                'discount' => 0,
            ];
        }

        $minOrder = (int) $coupon['min_order_toman'];

        if ($minOrder > 0 && $priceToman < $minOrder) {
            return [
                'ok'       => false,
                'message'  => 'این کد برای سفارش‌های بالای '
                    . \Pasargad\Support\Str::formatToman($minOrder) . ' است.',
                'discount' => 0,
            ];
        }

        $discount = $this->computeDiscount($coupon, $priceToman);

        if ($discount <= 0) {
            return [
                'ok'       => false,
                'message'  => 'این کد روی این بسته تخفیفی ایجاد نمی‌کند.',
                'discount' => 0,
            ];
        }

        return [
            'ok'       => true,
            'message'  => '🎉 کد تخفیف اعمال شد!',
            'discount' => $discount,
            'coupon'   => $coupon,
        ];
    }

    /**
     * مبلغ تخفیف خام یک کد روی یک قیمت.
     *
     * @param array<string, mixed> $coupon
     */
    public function computeDiscount(array $coupon, int $priceToman): int
    {
        if ($priceToman <= 0) {
            return 0;
        }

        $value = (int) ($coupon['value'] ?? 0);

        $discount = (string) ($coupon['kind'] ?? CouponRepository::KIND_PERCENT) === CouponRepository::KIND_FIXED
            ? $value
            : (int) floor($priceToman * max(0, min(100, $value)) / 100);

        $cap = (int) ($coupon['max_discount_toman'] ?? 0);

        if ($cap > 0) {
            $discount = min($discount, $cap);
        }

        // تخفیف هرگز نباید کل مبلغ را ببلعد، وگرنه سفارش رایگان می‌شود.
        $discount = min($discount, $priceToman);

        return max(0, $discount);
    }

    /**
     * تخفیف پیشنهادی معرفی برای اولین خرید کاربر معرفی‌شده.
     *
     * @return array{ok:bool, discount:int, percent:int}
     */
    public function referralDiscount(int $userId, int $priceToman): array
    {
        $none = ['ok' => false, 'discount' => 0, 'percent' => 0];

        if (!$this->settings->bool(Settings::REFERRAL_ENABLED, true)) {
            return $none;
        }

        $bind = $this->referrals->findByReferee($userId);

        if ($bind === null) {
            return $none;
        }

        // تخفیف معرفی فقط روی **اولین** خرید است، وگرنه یک نفر می‌توانست
        // همیشه با یک معرفی وارد شود و از تخفیف دائمی استفاده کند.
        $used = (int) $this->dbValue(
            "SELECT COUNT(*) FROM orders
             WHERE user_id = :id AND referred_by IS NOT NULL
               AND status NOT IN ('cancelled','rejected','refunded')",
            ['id' => $userId]
        );

        if ($used > 0) {
            return $none;
        }

        $percent = max(0, min(100, (int) $this->settings->int(Settings::REFERRAL_DISCOUNT, 10)));

        if ($percent <= 0) {
            return $none;
        }

        $discount = (int) floor($priceToman * $percent / 100);

        if ($discount <= 0) {
            return $none;
        }

        return ['ok' => true, 'discount' => $discount, 'percent' => $percent];
    }

    /**
     * کد معرفی خودِ کاربر + آمارش (برای صفحهٔ «🎁 دعوت دوست»).
     *
     * @return array{code:string, invited:int, rewarded:int, bonus_each:int}
     */
    public function referralSummary(int $userId): array
    {
        $rows = $this->referrals->listByReferrer($userId, 200);
        $rewarded = 0;

        foreach ($rows as $row) {
            if ($row['rewarded_at'] !== null) {
                $rewarded++;
            }
        }

        return [
            'code'       => ReferralRepository::codeFor($userId),
            'invited'    => count($rows),
            'rewarded'   => $rewarded,
            'bonus_each' => max(0, (int) $this->settings->int(Settings::REFERRAL_BONUS, 50000)),
        ];
    }

    // ------------------------------------------------------------------
    // مصرف
    // ------------------------------------------------------------------

    /**
     * ثبت مصرف کد تخفیف برای یک سفارش.
     *
     * اگر مصرف شکست بخورد (ظرفیت تمام شده بین بررسی و ثبت) هیچ استثنایی
     * پرتاب نمی‌شود: سفارش ساخته شده و مبلغش از قبل کم شده، پس خراب کردنش
     * بدترین کار است. فقط لاگ می‌شود.
     */
    public function consumeCoupon(int $couponId, int $userId, int $orderId, int $discountToman): bool
    {
        try {
            return $this->coupons->consume($couponId, $userId, $orderId, $discountToman);
        } catch (\Throwable $e) {
            Logger::warning('Coupon consume failed', [
                'coupon_id' => $couponId,
                'order_id'  => $orderId,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * پاداش دادن به معرفهایی که معرفی‌شده‌شان پرداخت موفق داشته.
     *
     * این متد از کرون صدا زده می‌شود. اگر دوباره اجرا شود چیزی نمی‌دهد چون
     * `rewarded_at` پر شده.
     *
     * @param object $notifier شیء duck-typed با متد notifyAdmins()
     * @return array{rewarded:int, toman:int}
     */
    public function rewardReferrers(?object $notifier = null): array
    {
        $result = ['rewarded' => 0, 'toman' => 0];

        foreach ($this->referrals->listUnrewarded(50) as $row) {
            $bonus = (int) ($row['bonus_toman'] ?? 0);

            if ($bonus <= 0) {
                $this->referrals->markRewarded((int) $row['id']);
                continue;
            }

            $referrerId = (int) $row['referrer_user_id'];

            try {
                $users = new UserRepository();
                $users->adjustWallet(
                    $referrerId,
                    $bonus,
                    'پاداش معرفی: ' . (($row['first_name'] ?? '') ?: 'کاربر جدید'),
                    0,
                    'referral'
                );
            } catch (\Throwable $e) {
                Logger::warning('Referral reward failed', [
                    'referral_id' => $row['id'],
                    'error'       => $e->getMessage(),
                ]);

                continue;
            }

            $this->referrals->markRewarded((int) $row['id']);

            $result['rewarded']++;
            $result['toman'] += $bonus;
        }

        if ($result['rewarded'] > 0 && $notifier !== null) {
            $notifier->notifyAdmins(
                '🎁 <b>پاداش معرفی پرداخت شد</b>' . "\n\n"
                . '👥 تعداد معرفی موفق: <b>' . \Pasargad\Support\Str::faNumber($result['rewarded']) . '</b>' . "\n"
                . '💰 مجموع پاداش: <b>' . \Pasargad\Support\Str::formatToman($result['toman']) . '</b>'
            );
        }

        return $result;
    }

    /**
     * مقدار اسکالر یک ستون.
     *
     * @param array<string, mixed> $params
     */
    private function dbValue(string $sql, array $params): mixed
    {
        return $this->db->value($sql, $params);
    }
}