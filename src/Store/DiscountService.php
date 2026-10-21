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
        //
        // ⚠️ معیار «خرید» پرداخت واقعی است، نه فقط ساخت سفارش. قبلاً سفارش
        // `created`/`awaiting_payment` هم تخفیف معرفی را می‌سوزاند؛ حتی اگر
        // کاربر هرگز پول نمی‌داد.
        $used = (int) $this->dbValue(
            "SELECT COUNT(*) FROM orders
             WHERE user_id = :id AND referred_by IS NOT NULL
               AND paid_at IS NOT NULL
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
        $rows = $this->referrals->listDetailed($userId, 200);

        $rewarded = 0;
        $paid     = 0;
        $earned   = 0;

        foreach ($rows as $row) {
            if (!empty($row['reward_given'])) {
                $rewarded++;
                $earned += (int) ($row['bonus_toman'] ?? 0) + (int) ($row['slate_bonus_toman'] ?? 0);
            }

            if (!empty($row['is_paid'])) {
                $paid++;
            }
        }

        $commission = $this->referrals->commissionStatsFor($userId);

        // کمیسیونِ هر دعوت به خودِ ردیفِ آن نشانده می‌شود تا صفحهٔ فهرست بتواند
        // بگوید «این دوست تا اینجا X تومان کمیسیون داده» — بدون این، کاربر فقط
        // یک عدد کلی می‌بیند و نمی‌داند کدام دوست ارزشِ پیگیری دارد.
        foreach ($rows as $i => $row) {
            $per = $commission['per_referral'][(int) $row['id']] ?? null;
            $rows[$i]['commission_toman']  = $per['total'] ?? 0;
            $rows[$i]['commission_orders'] = $per['orders'] ?? 0;
        }

        return [
            'code'       => ReferralRepository::codeFor($userId),
            'invited'    => count($rows),
            // «خرید کرده» یعنی واقعاً پول داده — نه اینکه فقط ثبت‌نام کرده.
            'paid'       => $paid,
            'rewarded'   => $rewarded,
            'bonus_each' => max(0, (int) $this->settings->int(Settings::REFERRAL_BONUS, 50000)),
            // مجموع پاداشِ پرداخت‌شده؛ باید با موجودی کیف پول بخواند.
            'earned'     => $earned,
            // مدل دوم: درآمدِ درصدیِ خریدهای بعدی، جدا از پاداشِ اولیه.
            'commission'     => $commission['total'],
            'commission_cnt' => $commission['count'],
            'policy'         => $this->commissionPolicy(),
            'list'           => $rows,
        ];
    }

    /**
     * آمارِ معرفی **بدون فهرست** — یک کوئری، صفر ردیف.
     *
     * چرا جدا از `referralSummary()`: آن یکی فهرستِ کامل (تا ۲۰۰ ردیف) را برمی‌گرداند
     * چون صفحهٔ «همکاری در فروش» به آن نیاز دارد. ولی `bootstrap` مینی‌اپ در **هر
     * بار باز شدن اپ** صدا زده می‌شود و فقط به سه عدد نیاز دارد — پس فرستادنِ
     * فهرست یعنی چند ده کیلوبایت JSON اضافه روی هر بارگذاری، برای چیزی که
     * کلاینت نمی‌خواند.
     *
     * ⭐️ همان شمارش‌ها، بدون بارگذاری ردیف‌ها.
     *
     * @return array{code:string, invited:int, paid:int, rewarded:int, bonus_each:int, earned:int}
     */
    public function referralStats(int $userId): array
    {
        $row = $this->referrals->statsFor($userId);

        $invited  = (int) ($row['invited'] ?? 0);
        $rewarded = (int) ($row['rewarded'] ?? 0);

        $commission = $this->referrals->commissionStatsFor($userId);

        return [
            'code'           => ReferralRepository::codeFor($userId),
            'invited'        => $invited,
            'paid'           => (int) ($row['paid'] ?? 0),
            'rewarded'       => $rewarded,
            'bonus_each'     => max(0, (int) $this->settings->int(Settings::REFERRAL_BONUS, 50000)),
            'earned'         => (int) ($row['earned'] ?? 0),
            'commission'     => $commission['total'],
            'commission_cnt' => $commission['count'],
            'policy'         => $this->commissionPolicy(),
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

            // سطح دوم: پدر/مادرمعرفِ همین معرف هم درصد ثابتی از همان پاداش می‌گیرد
            $parent = $this->referrals->findByReferee($referrerId);
            if ($parent !== null && (int) $parent['referrer_user_id'] > 0
                && (int) $parent['referrer_user_id'] !== $referrerId) {
                $level2 = $this->settings->int(Settings::REFERRAL_BONUS_LEVEL2, 25000);
                if ($level2 > 0) {
                    try {
                        $users = new UserRepository();
                        $users->adjustWallet(
                            (int) $parent['referrer_user_id'],
                            $level2,
                            'پاداش معرفی سطح دوم: ' . (($row['first_name'] ?? '') ?: 'کاربر جدید'),
                            0,
                            'referral_level2'
                        );
                        $result['level2'] = ($result['level2'] ?? 0) + $level2;
                    } catch (\Throwable $e) {
                        Logger::warning('Level2 referral reward failed', ['error' => $e->getMessage()]);
                    }
                }
            }
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

    // ------------------------------------------------------------------
    // مدل دوم: کمیسیونِ درصدیِ خریدهای بعدی
    // ------------------------------------------------------------------

    /**
     * درصد کمیسیون، اگر واقعاً فعال باشد.
     *
     * ⚠️ «فعال» یعنی هم درصد بزرگ‌تر از صفر **و** سقف بزرگ‌تر از صفر.
     *
     * بازگرداندنِ درصد حتی وقتی سقف صفر است، خطرناک است: صفحهٔ کاربر و داشبورد
     * ادمین «کمیسیون فعال» نشان می‌دهند ولی هیچ‌وقت پرداخت نمی‌شود — همان
     * نمایشِ دروغین که کاربر را گمراه می‌کند.
     *
     * @return array{percent:int, max_orders:int, enabled:bool}
     */
    public function commissionPolicy(): array
    {
        $percent = max(0, min(100, $this->settings->int(Settings::REFERRAL_COMMISSION_PERCENT, 0)));
        $max     = max(0, $this->settings->int(Settings::REFERRAL_COMMISSION_MAX_ORDERS, 0));

        return [
            'percent'    => $percent,
            'max_orders' => $max,
            'enabled'    => $percent > 0 && $max > 0,
        ];
    }

    /**
     * پرداختِ کمیسیونِ خریدهای بعدی.
     *
     * ## ترتیبِ حیاتی: اول دفتر، بعد پول
     *
     * `recordCommission()` **اول** صدا زده می‌شود و اگر `false` داد (یعنی قیدِ
     * یکتا خورد و سفارش قبلاً پرداخت شده) اصلاً پول ریخته نمی‌شود.
     *
     * ترتیبِ برعکس فاجعه بود: پول به کیف پول می‌رفت، ثبت در دفتر شکست می‌خورد،
     * و کرونِ بعدی همان پول را **دوباره** می‌ریخت. کاربر پولِ اضافه می‌گرفت و
     * هیچ ردی هم برایش نبود.
     *
     * تنها ریسکِ باقی‌مانده این است که پول ریخته شود و همان لحظه خطا بخورد
     * (قطع دیتابیس وسط کار). آن‌وقت دفتر می‌گوید «پرداخت شد» ولی پول نرسیده —
     * و کرونِ بعدی دوباره نمی‌ریزد. این حالت در `adjustWallet` خودش تراکنشی
     * است، پس بازگرداندنِ خودکار ندارد؛ به‌جایش لاگ و گزارشِ ادمین دارد تا
     * قابل بررسی باشد. انتخابِ «کم‌پرداخت کردن» امن‌تر از «دوباره پرداخت کردن» است.
     *
     * @param object|null $notifier duck-typed با متد notifyAdmins()
     * @return array{orders:int, toman:int, referrers:int, skipped:int}
     */
    public function payCommissions(?object $notifier = null): array
    {
        $result = ['orders' => 0, 'toman' => 0, 'referrers' => 0, 'skipped' => 0];

        if (!$this->flagsAreUsable()) {
            return $result;
        }

        $policy = $this->commissionPolicy();

        // ⭐️ کمیسیون خاموش است ⇒ هیچ کوئریِ سنگینی اجرا نمی‌شود. کرون هر چند
        // دقیقه یک‌بار اجرا می‌شود و نباید بی‌دلیل جدولِ سفارش‌ها را بکوبد.
        if (!$policy['enabled']) {
            return $result;
        }

        $users  = new UserRepository($this->db);
        $seen   = [];

        // ⭐️ لحظهٔ شروع، **اینجا** و فقط اینجا ثبت می‌شود.
        //
        // نه در `commissionPolicy()` و نه در صفحه‌های نمایش: آن‌ها ممکن است
        // صدها بار در روز صدا زده شوند و نباید دیتابیس را بنویسند. این متد
        // تنها جایی است که پول واقعاً جابه‌جا می‌شود، پس همان‌جا هم مهرِ شروع
        // را می‌زند.
        $since = $this->ensureCommissionWindow();

        foreach ($this->referrals->allWithPaidReferee(100) as $ref) {
            $referralId  = (int) $ref['id'];
            $referrerId  = (int) $ref['referrer_user_id'];
            $refereeId   = (int) $ref['referee_user_id'];
            $name        = trim((string) ($ref['first_name'] ?? '')) ?: 'کاربر';

            foreach ($this->referrals->eligibleCommissionOrders($refereeId, $policy['max_orders'], $since, 50) as $order) {
                // ⚠️ مبنای کمیسیون `price_toman` است، نه `original_price_toman`.
                //
                // قیمتِ پرداخت‌شده همان چیزی است که فروشگاه واقعاً گرفته. اگر
                // مبنا را قیمتِ قبل از تخفیف بگیریم، وقتی دوستم معرف تخفیف هم
                // گرفته، کمیسیونِ معرف از درآمدی بیشتر می‌شود که فروشگاه
                // ندیده — یعنی پولِ ساختگی.
                $base = (int) $order['price_toman'];

                if ($base < 1) {
                    $result['skipped']++;
                    continue;
                }

                $amount = (int) floor(($base * $policy['percent']) / 100);

                if ($amount < 1) {
                    $result['skipped']++;
                    continue;
                }

                // ۱) دفتر — اگر ثبت نشد، پولی هم ریخته نمی‌شود.
                if (!$this->referrals->recordCommission(
                    $referralId,
                    $referrerId,
                    $refereeId,
                    (int) $order['id'],
                    $policy['percent'],
                    $base,
                    $amount
                )) {
                    $result['skipped']++;
                    continue;
                }

                // ۲) پول
                try {
                    $users->adjustWallet(
                        $referrerId,
                        $amount,
                        'کمیسیون معرفی: ' . $name . ' • ' . (string) $order['code'],
                        0,
                        'referral_commission'
                    );
                } catch (\Throwable $e) {
                    Logger::error('Referral commission payout failed', [
                        'referral_id' => $referralId,
                        'order_id'    => (int) $order['id'],
                        'amount'      => $amount,
                        'error'       => $e->getMessage(),
                    ]);

                    // ⚠️ ردیف دفتر هست ولی پول نیست. عمداً پاک نمی‌کنیم: پاک کردن
                    // یعنی کرونِ بعدی دوباره می‌ریزد و کاربر پولِ مضاعف می‌گیرد.
                    // بهتر است این مورد در گزارشِ ادمین دیده شود تا دستی حل شود.
                    $result['skipped']++;
                    continue;
                }

                $result['orders']++;
                $result['toman'] += $amount;
                $seen[$referrerId] = true;
            }
        }

        $result['referrers'] = count($seen);

        if ($result['orders'] > 0 && $notifier !== null) {
            $notifier->notifyAdmins(
                '💸 <b>کمیسیون معرفی پرداخت شد</b>' . "\n\n"
                . '🧾 سفارش‌های کمیسیونی: <b>'
                . \Pasargad\Support\Str::faNumber($result['orders']) . '</b>' . "\n"
                . '👥 معرف‌های درگیر: <b>'
                . \Pasargad\Support\Str::faNumber($result['referrers']) . '</b>' . "\n"
                . '💰 مجموع کمیسیون: <b>'
                . \Pasargad\Support\Str::formatToman($result['toman']) . '</b>'
            );
        }

        return $result;
    }

    /** آیا خودِ سیستم معرفی روشن است؟ */
    private function flagsAreUsable(): bool
    {
        return $this->settings->bool(Settings::REFERRAL_ENABLED, true);
    }

    /**
     * لحظهٔ شروعِ کمیسیون؛ بارِ اول که ثبت می‌شود.
     *
     * عمداً **هرگز خودکار پاک نمی‌شود**. اگر ادمین کمیسیون را خاموش و بعد
     * دوباره روشن کند، بازه از همان بارِ اول حساب می‌شود — نه از بارِ دوم.
     * پاک کردنش یعنی «دفعهٔ بعد که روشن کردی، کلِ تاریخچهٔ بازهٔ خاموش را هم
     * پرداخت کن»، که همان هیزِ غافلگیری است که این مکانیزم برای جلوگیری از آن
     * ساخته شده. اگر ادمین واقعاً می‌خواهد از تاریخِ خاصی اعمال شود، باید خودش
     * کلید را در تنظیمات بنویسد.
     *
     * @return int
     */
    private function ensureCommissionWindow(): int
    {
        $since = $this->settings->int(Settings::REFERRAL_COMMISSION_SINCE, 0);

        if ($since > 0) {
            return $since;
        }

        $since = time();

        $this->settings->set(Settings::REFERRAL_COMMISSION_SINCE, (string) $since);

        Logger::info('Referral commission window opened', ['since' => $since]);

        return $since;
    }

    /**
     * لحظهٔ شروعِ ثبت‌شدهٔ کمیسیون (۰ یعنی هنوز روشن نشده).
     *
     * برای نمایش به ادمین — باید بداند کمیسیون از چه زمانی حساب می‌شود،
     * وگرنه وقتی عددش نمی‌خواند حدس می‌زند که همه‌چیز خراب است.
     *
     * @return int
     */
    public function commissionStartedAt(): int
    {
        return max(0, $this->settings->int(Settings::REFERRAL_COMMISSION_SINCE, 0));
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