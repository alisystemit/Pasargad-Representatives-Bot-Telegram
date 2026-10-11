<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;
use Pasargad\Support\Logger;

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
 * شمارش‌های معرفیِ یک کاربر، در **یک کوئری و بدون برگرداندن ردیف**.
 *
 * مکملِ `listDetailed()`: آن برای صفحهٔ «همکاری در فروش» لازم است ولی تا ۲۰۰
 * ردیف برمی‌گرداند. این برای `bootstrap` مینی‌اپ است که در هر بار باز شدن اپ
 * صدا زده می‌شود و فقط به عدد نیاز دارد.
 *
 * ⭐️ «خرید کرده» شرطش `status IN ('paid','applied')` است — **همان شرطی که
 * پاداش بر اساسش پرداخت می‌شود**. اگر این دو از هم جدا شوند، کاربر می‌بیند
 * «پاداش گرفته» ولی «خرید کرده» صفر است (یا برعکس) و گیج می‌شود.
 *
 * @return array{invited:int, paid:int, rewarded:int, earned:int}
 */
public function statsFor(int $referrerId): array
{
    $row = $this->db->first(
        "SELECT
            COUNT(*) AS invited,
            SUM(CASE WHEN rewarded_at IS NOT NULL THEN 1 ELSE 0 END) AS rewarded,
            COALESCE(SUM(CASE WHEN rewarded_at IS NOT NULL
                THEN bonus_toman + COALESCE(slate_bonus_toman, 0) ELSE 0 END), 0) AS earned,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM orders o
                 WHERE o.user_id = r.referee_user_id
                   AND o.status IN ('paid','applied')
            ) THEN 1 ELSE 0 END) AS paid
         FROM referrals r
         WHERE r.referrer_user_id = :id",
        ['id' => $referrerId]
    ) ?? [];

    return [
        'invited'  => (int) ($row['invited'] ?? 0),
        'paid'     => (int) ($row['paid'] ?? 0),
        'rewarded' => (int) ($row['rewarded'] ?? 0),
        'earned'   => (int) ($row['earned'] ?? 0),
    ];
}

/**
     * فهرست دعوت‌شده‌ها **همراه با وضعیت خریدِ هرکدام**.
     *
     * چرا این جداست: کاربرِ معرف باید ببیند چه کسی را آورده، آن شخص خرید کرده
     * یا نه و چقدر. بدون این، صفحهٔ «دعوت دوست» فقط دو عدد می‌دهد و کاربر هیچ
     * کاری نمی‌تواند بکند جز فرستادن دوبارهٔ لینک — یعنی صفحه عملاً بی‌فایده است.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDetailed(int $referrerId, int $limit = 50): array
    {
        $rows = $this->db->all(
            "SELECT r.id, r.referrer_user_id, r.referee_user_id, r.code,
                    r.bonus_toman, r.slate_bonus_toman, r.rewarded_at, r.created_at,
                    u.telegram_id, u.first_name,
                    (SELECT COUNT(*) FROM orders o
                      WHERE o.user_id = r.referee_user_id AND o.status IN ('paid','applied')
                    ) AS orders_count,
                    (SELECT COALESCE(SUM(o.price_toman), 0) FROM orders o
                      WHERE o.user_id = r.referee_user_id AND o.status IN ('paid','applied')
                    ) AS orders_total
             FROM referrals r
             LEFT JOIN users u ON u.id = r.referee_user_id
             WHERE r.referrer_user_id = :id
             ORDER BY r.id DESC
             LIMIT " . max(1, min($limit, 200)),
            ['id' => $referrerId]
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['is_paid']      = ((int) ($row['orders_count'] ?? 0)) > 0;
            $rows[$i]['reward_given'] = $row['rewarded_at'] !== null;
        }

        return $rows;
    }

    /**
     * یک معرفی با شناسه — برای صفحهٔ جزئیاتِ یک دعوت.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $referralId): ?array
    {
        return $this->db->first(
            'SELECT r.*, u.telegram_id, u.first_name
             FROM referrals r
             LEFT JOIN users u ON u.id = r.referee_user_id
             WHERE r.id = ?',
            [$referralId]
        );
    }

    /**
     * سفارش‌هایِ یک معرفی‌شده که **واجد کمیسیون** هستند و هنوز پرداخت نشده‌اند.
     *
     * ## «بدوی از چندمین خرید؟»
     *
     * اولین خریدِ موفق، سهمِ **پاداشِ ثابت** است (`referral_bonus_toman`) و
     * کمیسیون ندارد؛ مدلِ دوم از **دومین** خرید شروع می‌شود. پس این کوئری
     * همیشه یک سفارشِ موفق را «کنار می‌گذارد» — به‌ترتیبِ زمان پرداخت، نه
     * شناسه.
     *
     * ⚠️ ترتیب بر اساس `paid_at` است نه `id`: اگر دو سفارش در یک ثانیه پرداخت
     * شوند، «اولی» باید همان باشد که واقعاً زودتر پرداخت شده، نه آنکه
     * تصادفاً شناسهٔ کوچک‌تری دارد.
     *
     * ## سقف
     *
     * `$maxOrders` روی **کمیسیون‌های ثبت‌شده** اعمال می‌شود، نه روی کلِ
     * سفارش‌ها. اگر روی کلِ سفارش‌ها بود، یک سفارشِ بدون کمیسیون (مثلاً
     * سفارشی که هنوز سقفش پر بوده) سهمِ سفارش‌های بعدی را می‌خورد و سقف هرگز
     * پر نمی‌شد — یعنی کمیسیون برای همیشه قطع می‌شد.
     *
     * @param  int $maxOrders سقف؛ ۰ یعنی بدون سقف. منفی عملاً «بسته» است.
     * @return array<int, array<string, mixed>>
     */
    public function eligibleCommissionOrders(int $refereeId, int $maxOrders = 0, int $since = 0, int $limit = 50): array
    {
        $paidCount = $this->commissionOrderCount($refereeId)['count'];

        // سقف پر شده ⇒ هیچ سفارشِ واجدشرایطی نیست.
        if ($maxOrders > 0 && $paidCount >= $maxOrders) {
            return [];
        }

        // اگر سقف محدود است، فقط به‌اندازهٔ جای مانده برمی‌گردانیم؛ وگرنه کلِ
        // دسته را می‌گیریم و `payCommissions()` در چند دورِ کرون بقیه را می‌خواند.
        $room = $maxOrders > 0 ? min($limit, $maxOrders - $paidCount) : $limit;

        // ⭐️ سقفِ زمانی: فقط سفارش‌هایی که **بعد از** شروع کمیسیون پرداخت شده‌اند.
        //
        // بدون این، روشن کردنِ کمیسیون توسط ادمین یک «پرداختِ یک‌بارهٔ گذشته»
        // به کیف پول ده‌ها معرف تبدیل می‌شد — رقمی که هیچ‌کس درخواستش نکرده و
        // ممکن است از کلِ سودِ ماهانه بیشتر باشد. جزئیات در
        // `Settings::REFERRAL_COMMISSION_SINCE`.
        $sinceClause = $since > 0
            ? ' AND COALESCE(o.paid_at, o.created_at) >= :since'
            : '';

        $params = ['uid' => $refereeId];

        if ($since > 0) {
            $params['since'] = $since;
        }

        return $this->db->all(
            "SELECT o.id, o.code, o.price_toman, o.paid_at, o.applied_at
               FROM orders o
              WHERE o.user_id = :uid
                AND o.status IN ('paid','applied')"
            . $sinceClause
            . "
                -- ⭐️ سهمِ خریدِ اول، پاداشِ ثابت است نه کمیسیون.
                --
                -- «اولین» یعنی قدیمی‌ترین سفارشِ پرداخت‌شده به ترتیب
                -- (paid_at, id) — نه کم‌ترین id. اگر روزی هم‌زمانی پیش بیاید
                -- که یک سفارشِ قدیمی‌تر هم‌زمان پرداخت شود، انتخاب بر اساس id
                -- می‌تواند سفارشِ اشتباه را «اول» اعلام کند و کمیسیونِ واقعیِ
                -- خریدِ اول را از کاربر بگیرد.
                AND o.id <> (
                    SELECT f.id FROM orders f
                     WHERE f.user_id = o.user_id
                       AND f.status IN ('paid','applied')
                     ORDER BY COALESCE(f.paid_at, f.created_at) ASC, f.id ASC
                     LIMIT 1
                )
                -- دفترِ کمیسیون: هر سفارش حداکثر یک بار.
                AND NOT EXISTS (
                    SELECT 1 FROM referral_commissions c WHERE c.order_id = o.id
                )
              ORDER BY COALESCE(o.paid_at, o.created_at) ASC, o.id ASC
              LIMIT " . max(1, min($room, 200)),
            $params
        );
    }

    /**
     * چند سفارش تا این لحظه برای این معرفی‌شده کمیسیون گرفته است.
     *
     * از **دفتر** خوانده می‌شود، نه از شمارشِ سفارش‌ها — چون سقف باید روی
     * چیزی اعمال شود که واقعاً پرداخت شده.
     *
     * @return array{count:int, total:int}
     */
    public function commissionOrderCount(int $refereeId): array
    {
        $row = $this->db->first(
            'SELECT COUNT(*) AS c, COALESCE(SUM(amount_toman), 0) AS t
               FROM referral_commissions
              WHERE referee_user_id = ?',
            [$refereeId]
        ) ?? [];

        return [
            'count' => (int) ($row['c'] ?? 0),
            'total' => (int) ($row['t'] ?? 0),
        ];
    }

    /**
     * ثبتِ یک کمیسیون در دفتر.
     *
     * ⭐️ برمی‌گردد که واقعاً ثبت شد یا نه. قیدِ یکتای `order_id` است که از
     * پرداختِ دوباره جلوگیری می‌کند؛ این متد آن را نمی‌بلعد بلکه **گزارش**
     * می‌کند، تا کرون بتواند پول را فقط یک‌بار بریزد.
     *
     * نکتهٔ ترتیب: `adjustWallet` باید **بعد** از این موفقیت صدا زده شود.
     * اگر اول پول ریخته شود و ثبت شکست بخورد، پول رفته ولی در دفتر نیست —
     * و کرونِ بعدی دوباره می‌ریزد.
     *
     * @return bool
     */
    public function recordCommission(
        int $referralId,
        int $referrerId,
        int $refereeId,
        int $orderId,
        int $percent,
        int $baseToman,
        int $amountToman
    ): bool {
        try {
            $this->db->insert('referral_commissions', [
                'referral_id'      => $referralId,
                'referrer_user_id' => $referrerId,
                'referee_user_id'  => $refereeId,
                'order_id'         => $orderId,
                'percent'          => $percent,
                'base_toman'       => $baseToman,
                'amount_toman'     => $amountToman,
                'created_at'       => time(),
            ]);
        } catch (\Throwable $e) {
            // قیدِ یکتا خورد ⇒ سفارش قبلاً کمیسیون گرفته. این **خطا نیست**،
            // نتیجهٔ درست است.
            Logger::info('Commission already recorded', ['order_id' => $orderId]);

            return false;
        }

        return true;
    }

    /**
     * مجموع کمیسیون‌های یک معرف، و پراکندگی‌اش بین دعوت‌ها.
     *
     * @return array{total:int, count:int, per_referral:array<int, array<string, mixed>>}
     */
    public function commissionStatsFor(int $referrerId): array
    {
        $row = $this->db->first(
            'SELECT COALESCE(SUM(amount_toman), 0) AS total, COUNT(*) AS c
               FROM referral_commissions
              WHERE referrer_user_id = ?',
            [$referrerId]
        ) ?? [];

        $per = $this->db->all(
            'SELECT referral_id,
                    COUNT(*) AS orders,
                    COALESCE(SUM(amount_toman), 0) AS total
               FROM referral_commissions
              WHERE referrer_user_id = ?
              GROUP BY referral_id',
            [$referrerId]
        );

        $byReferral = [];
        foreach ($per as $r) {
            $byReferral[(int) $r['referral_id']] = [
                'orders' => (int) $r['orders'],
                'total'  => (int) $r['total'],
            ];
        }

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'count'        => (int) ($row['c'] ?? 0),
            'per_referral' => $byReferral,
        ];
    }

    /**
     * معرفی‌هایی که معرفی‌شده‌شان حداقل یک خریدِ موفق دارد.
     *
     * برای کرونِ کمیسیون: لازم نیست کلِ جدولِ معرفی‌ها را بکوبیم — کسی که هرگز
     * نخریده، کمیسیونی هم ندارد. این فیلتر، بارِ کار را به معرفی‌های
     * واقعاً فعال محدود می‌کند.
     *
     * @return array<int, array<string, mixed>>
     */
public function allWithPaidReferee(int $limit = 100): array
{
    return $this->db->all(
        "SELECT r.*, u.first_name, u.telegram_id
           FROM referrals r
           LEFT JOIN users u ON u.id = r.referee_user_id
          WHERE EXISTS (
              SELECT 1 FROM orders o
               WHERE o.user_id = r.referee_user_id
                 AND o.status IN ('paid','applied')
          )
          ORDER BY r.id DESC
          LIMIT " . max(1, min($limit, 500))
    );
}

/**
     * آمارِ کلِ کمیسیون در کل سیستم — برای داشبورد ادمین.
     *
     * @return array{total:int, count:int, referrers:int}
     */
    public function commissionTotals(): array
    {
        $row = $this->db->first(
            'SELECT COALESCE(SUM(amount_toman), 0) AS total,
                    COUNT(*) AS c,
                    COUNT(DISTINCT referrer_user_id) AS rr
               FROM referral_commissions'
        ) ?? [];

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'count'     => (int) ($row['c'] ?? 0),
            'referrers' => (int) ($row['rr'] ?? 0),
        ];
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