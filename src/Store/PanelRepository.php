<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * مخزن پنل‌های خریداری‌شدهٔ نمایندگان.
 *
 * هر نماینده می‌تواند **چند پنل** داشته باشد و هر ردیف این جدول یکی از آن‌هاست
 * با حجم، مصرف، انقضا، وضعیت و شمارندهٔ هشدارهای مستقلِ خودش.
 *
 * چرا جدول جدا و نه ستون روی `users`؟
 *   ۱) خرید چندپنلی: یک کاربر باید بتواند بیش از یک پنل داشته باشد.
 *   ۲) هشدار per-panel: وقتی حجم یک پنل به آخر می‌رسد باید همان پنل هشدار
 *      بگیرد، نه مجموع همهٔ پنل‌های کاربر.
 *   ۳) قطع دسترسی per-panel: پس از انقضای *یک* پنل باید کاربران همان پنل
 *      قطع شوند، نه کاربران بقیهٔ پنل‌هایش.
 *
 * @psalm-type PanelRow = array<string, mixed>
 */
final class PanelRepository
{
    /**
     * وضعیت‌های معتبر پنل.
     *
     * `expired` وضعیتی است که **فقط** ربات می‌گذارد (وقتی تاریخ اعتبار تمام
     * شده). پنل خودش این وضعیت را برنمی‌گرداند.
     */
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_LIMITED  = 'limited';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REVOKED  = 'revoked';
    public const STATUS_EXPIRED  = 'expired';

    public const SOURCE_BOT    = 'bot';
    public const SOURCE_LEGACY = 'legacy';
    public const SOURCE_SELF   = 'self';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * اتصال دیتابیس این مخزن.
     *
     * برای کلاس‌هایی که باید از همین اتصال استفاده کنند لازم است (مثل
     * AlertService که آیدی تلگرام صاحب پنل را می‌خواند).
     */
    public function db(): Db
    {
        return $this->db;
    }

    // ------------------------------------------------------------------
    // خواندن
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM panels WHERE id = ?', [$id]);
    }

    /**
     * پنل‌های یک نماینده، تازه‌ترین اول.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(int $userId): array
    {
        return $this->db->all(
            'SELECT * FROM panels WHERE user_id = ? ORDER BY id DESC',
            [$userId]
        );
    }

    /**
     * پنل‌های یک نماینده که هنوز اعتبار دارند (برای دکمهٔ «شارژ» و تست کانفیگ).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveByUser(int $userId): array
    {
        return $this->db->all(
            "SELECT * FROM panels
             WHERE user_id = :uid
               AND (access_expire_at IS NULL OR access_expire_at > :now)
             ORDER BY id DESC",
            ['uid' => $userId, 'now' => time()]
        );
    }

    /**
     * پیدا کردن پنل بر اساس نام کاربری پنل (بدون حساسیت به بزرگی حروف).
     *
     * برای جلوگیری از دوباره‌سازی استفاده می‌شود: اگر نماینده با دکمهٔ
     * «من پنل دارم» همان پنلی را ثبت کند که قبلاً خریده، نباید ردیف جدید ساخته شود.
     *
     * @return array<string, mixed>|null
     */
    public function findByPanelUsername(string $panelUsername): ?array
    {
        return $this->db->first(
            'SELECT * FROM panels WHERE panel_username = ? COLLATE NOCASE ORDER BY id ASC LIMIT 1',
            [trim($panelUsername)]
        );
    }

    /**
     * پنل مشخصِ یک کاربر (برای جلوگیری از دستکاری شناسهٔ پنلِ کاربر دیگر).
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $panelId, int $userId): ?array
    {
        return $this->db->first(
            'SELECT * FROM panels WHERE id = ? AND user_id = ?',
            [$panelId, $userId]
        );
    }

    /**
     * پنل پیش‌فرض کاربر؛ اگر نبود، تازه‌ترین پنلش.
     *
     * برای جاهایی که «پنل فعلی» لازم است ولی کاربر انتخابی نکرده.
     *
     * @param array<string, mixed> $user رکورد users
     * @return array<string, mixed>|null
     */
    public function primaryForUser(array $user): ?array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $default = $this->db->first(
            'SELECT * FROM panels WHERE user_id = ? AND is_default = 1 LIMIT 1',
            [$userId]
        );

        if ($default !== null) {
            return $default;
        }

        return $this->db->first(
            'SELECT * FROM panels WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    /**
     * همهٔ پنل‌ها (پنل مدیریت).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $limit = 50, int $offset = 0, string $search = ''): array
    {
        $limit  = max(1, min($limit, 200));
        $offset = max(0, $offset);

        if ($search !== '') {
            $like = '%' . $search . '%';

            return $this->db->all(
                'SELECT p.*, u.telegram_id FROM panels p
                 LEFT JOIN users u ON u.id = p.user_id
                 WHERE p.panel_username LIKE :like OR CAST(p.user_id AS TEXT) LIKE :like
                 ORDER BY p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['like' => $like]
            );
        }

        return $this->db->all(
            'SELECT p.*, u.telegram_id FROM panels p
             LEFT JOIN users u ON u.id = p.user_id
             ORDER BY p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
    }

    public function countAll(string $search = ''): int
    {
        if ($search !== '') {
            return $this->db->count(
                'SELECT COUNT(*) FROM panels WHERE panel_username LIKE ?',
                ['%' . $search . '%']
            );
        }

        return $this->db->count('SELECT COUNT(*) FROM panels');
    }

    /**
     * آیا کاربر حداقل یک پنل دارد؟ (یعنی «نماینده» است)
     */
    public function isRepresentative(int $userId): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM panels WHERE user_id = ?',
            [$userId]
        ) > 0;
    }

    public function countByUser(int $userId): int
    {
        return $this->db->count('SELECT COUNT(*) FROM panels WHERE user_id = ?', [$userId]);
    }

    // ------------------------------------------------------------------
    // نوشتن
    // ------------------------------------------------------------------

    /**
     * ثبت یک پنل جدید برای نماینده.
     *
     * پسورد رمزنگاری می‌شود چون برای اعمال خودکار شارژ و ساخت کانفیگ تست
     * باید از ربات به پنل لاگین کند.
     *
     * @param array<string, mixed> $data
     */
    public function create(int $userId, string $panelUsername, string $password, array $data = []): int
    {
        $now = time();

        return $this->db->insert('panels', array_merge([
            'user_id'        => $userId,
            'panel_username' => trim($panelUsername),
            'panel_password' => $password === '' ? '' : Crypto::encrypt($password),
            'panel_status'   => self::STATUS_ACTIVE,
            'source'         => self::SOURCE_BOT,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $data));
    }

    /**
     * ثبت/به‌روزرسانی یک پنل از روی پاسخ API پنل (`GET /api/admin/{username}`).
     *
     * اگر پنل از قبل وجود داشته باشد (مثلاً کاربر با دکمهٔ «من پنل دارم»
     * همان پنلی را ثبت کند که قبلاً خریده) رکورد موجود به‌روزرسانی و
     * **شناسهٔ همان رکورد** برگردانده می‌شود — نه یک ردیف تکراری.
     *
     * @param array<string, mixed> $adminDetails پاسخ پنل
     * @return int شناسهٔ پنل
     */
    public function upsertFromPanel(int $userId, string $panelUsername, string $password, array $adminDetails, string $source = self::SOURCE_BOT): int
    {
        $existing = $this->findByPanelUsername($panelUsername);

        $payload = [
            'user_id'        => $userId,
            'panel_username' => trim($panelUsername),
            'panel_password' => $password === '' ? '' : Crypto::encrypt($password),
            'panel_user_id'  => isset($adminDetails['id']) && is_numeric($adminDetails['id']) ? (int) $adminDetails['id'] : null,
            // پرچم‌های صریح پنل بر متن status اولویت دارند: ممکن است status
            // همچنان active باشد ولی حساب واقعاً غیرفعال/محدود شده باشد.
            // (کلیدها اختیاری‌اند؛ نبودشان یعنی همان mapStatus قبلی.)
            'panel_status'   => self::resolveApiStatus($adminDetails),
            'panel_role'     => isset($adminDetails['role']['name']) ? (string) $adminDetails['role']['name'] : null,
            'panel_is_owner' => !empty($adminDetails['role']['is_owner']) ? 1 : 0,
            'data_limit'     => isset($adminDetails['data_limit']) && is_numeric($adminDetails['data_limit']) ? (int) $adminDetails['data_limit'] : 0,
            'used_traffic'   => isset($adminDetails['used_traffic']) && is_numeric($adminDetails['used_traffic']) ? (int) $adminDetails['used_traffic'] : 0,
            'synced_at'      => time(),
            'updated_at'     => time(),
        ];

        // تعداد کاربران پنل از خودِ پاسخ getAdmin (ارزان‌تر از walk کامل).
        // فقط وقتی می‌نویسیم که عدد معتبر باشد تا آمار تازه‌تر خراب نشود.
        if (isset($adminDetails['total_users']) && is_numeric($adminDetails['total_users'])) {
            $payload['users_total'] = max(0, (int) $adminDetails['total_users']);
        }

        $subUrl = self::extractSubUrl($adminDetails);
        if ($subUrl !== null) {
            $payload['sub_url'] = $subUrl;
        }

        if ($existing !== null) {
            $this->db->update('panels', $payload, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert('panels', array_merge($payload, [
            'source'     => $source,
            'created_at' => time(),
        ]));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $panelId, array $data): void
    {
        $data['updated_at'] = time();
        $this->db->update('panels', $data, ['id' => $panelId]);
    }

    public function delete(int $panelId): void
    {
        $this->db->delete('panels', ['id' => $panelId]);
    }

    // ------------------------------------------------------------------
    // همگام‌سازی
    // ------------------------------------------------------------------

    /**
     * به‌روزرسانی وضعیت پنل از پاسخ API.
     *
     * فقط کلیدهایی که واقعاً در پاسخ آمده‌اند نوشته می‌شوند. پاسخ PUT ممکن
     * است ناقص باشد؛ اگر کلیدی نبود نباید مقدار قبلی صفر شود چون
     * data_limit=0 در پنل یعنی «نامحدود» و هشدار حجم از کار می‌افتد.
     *
     * @param array<string, mixed> $adminDetails
     */
    public function syncFromPanel(int $panelId, array $adminDetails): void
    {
        $payload = ['synced_at' => time(), 'updated_at' => time()];

        if (isset($adminDetails['status']) || isset($adminDetails['is_disabled']) || isset($adminDetails['is_limited'])) {
            $payload['panel_status'] = self::resolveApiStatus($adminDetails);
        }

        if (isset($adminDetails['data_limit']) && is_numeric($adminDetails['data_limit'])) {
            $payload['data_limit'] = (int) $adminDetails['data_limit'];
        }

        if (isset($adminDetails['used_traffic']) && is_numeric($adminDetails['used_traffic'])) {
            $payload['used_traffic'] = (int) $adminDetails['used_traffic'];
        }

        $subUrl = self::extractSubUrl($adminDetails);
        if ($subUrl !== null) {
            $payload['sub_url'] = $subUrl;
        }

        $this->db->update('panels', $payload, ['id' => $panelId]);
    }

    /**
     * افزودن حجم خریداری‌شده و تمدید اعتبار زمانی.
     *
     * نکتهٔ مهم: این متد **سقف پنل را دست نمی‌زند**. سقف از پنل خوانده و
     * نوشته می‌شود (مرجع حقیقت = پنل). اینجا فقط دو چیز ثبت می‌شود که پنل
     * نمی‌داند: «این‌قدر خریده شده» و «تا کی اعتبار دارد».
     *
     * اگر اینجا data_limit را هم اضافه می‌کردیم، چون syncFromPanel همین
     * قبلش سقفِ جدید را از پاسخ پنل نوشته بود، حجم **دو بار** جمع می‌شد.
     *
     * انقضا با MAX گرفته می‌شود نه جمع: اگر کاربر دو بستهٔ ۳۰ روزه بخرد،
     * انتظار «۶۰ روز» دارد نه «تا ۳۰ روز بعد».
     */
public function addGranted(int $panelId, int $bytes, ?int $expireAt): void
    {
        if ($bytes <= 0 && $expireAt === null) {
            return;
        }

        if ($expireAt !== null) {
            $this->db->run(
                'UPDATE panels
                 SET granted_volume   = granted_volume + :b,
                     access_expire_at = CASE
                         WHEN access_expire_at IS NULL OR access_expire_at < :e THEN :e
                         ELSE access_expire_at
                     END,
                     updated_at       = :now
                 WHERE id = :id',
                ['b' => $bytes, 'e' => $expireAt, 'now' => time(), 'id' => $panelId]
            );

            return;
        }

        $this->db->run(
            'UPDATE panels
             SET granted_volume = granted_volume + :b,
                 updated_at     = :now
             WHERE id = :id',
            ['b' => $bytes, 'now' => time(), 'id' => $panelId]
        );
    }

    /**
     * ثبت فقط تاریخ انقضا (بدون افزودن حجم) — برای تمدید زمانیِ خالی.
     */
    public function extendExpiry(int $panelId, ?int $expireAt): void
    {
        $this->db->run(
            'UPDATE panels
             SET access_expire_at = CASE
                 WHEN access_expire_at IS NULL OR access_expire_at < :e THEN :e
                 ELSE access_expire_at
             END,
             updated_at = :now
             WHERE id = :id',
            ['e' => $expireAt, 'now' => time(), 'id' => $panelId]
        );
    }

    /**
     * علامت‌گذاری «هشدار حجم» به‌عنوان ارسال‌شده.
     *
     * کلید شامل سقف فعلی است تا با هر خرید/شارژ دوباره هشدار بدهد.
     */
    public function markLowVolumeWarned(int $panelId, int $limit): void
    {
        $this->update($panelId, ['warned_low_volume' => time()]);

        // سقفِ مرجع در settings نگهداری می‌شود تا با هر شارژ دوباره هشدار بدهد
        // (جدول panels فقط یک ستون زمانی دارد، نه «برای چه سقفی»).
        (new Settings($this->db))->set('panelwarn:' . $panelId . ':low', (string) $limit);
    }

    public function markExpiryWarned(int $panelId, int $expireAt): void
    {
        $this->update($panelId, ['warned_expiring' => time()]);

        // تاریخ انقضا به‌عنوان کلید مرجع در settings ذخیره می‌شود تا با هر
        // تمدید، هشدار دوباره فرستاده شود (ستون warned_expiring فقط زمان
        // آخرین هشدار را نگه می‌دارد و «برای کدام انقضا» را نه).
        (new Settings($this->db))->set('panelwarn:' . $panelId . ':exp', (string) $expireAt);
    }

    public function markExpiryNotified(int $panelId): void
    {
        $this->update($panelId, ['expiry_notified' => 1]);
    }

    /**
     * علامت‌گذاری «هشدار مهلت ارفاقی» به‌عنوان ارسال‌شده.
     *
     * کلید شامل پایان مهلت است تا اگر نماینده تمدید کرد و دوباره منقضی شد،
     * هشدار تازه فرستاده شود.
     */
    public function markGraceNotified(int $panelId, int $deadline): void
    {
        $this->update($panelId, ['grace_notified' => 1]);

        (new Settings($this->db))->set('panelwarn:' . $panelId . ':grace', (string) $deadline);
    }

    public function markCutoffRequested(int $panelId): void
    {
        $this->update($panelId, ['cutoff_requested_at' => time()]);
    }

    /**
     * علامت‌گذاری «هشدار سقف کاربران» به‌عنوان ارسال‌شده.
     *
     * کلید شامل سقف فعلی است تا با هر شارژ (سقف جدید) دوباره هشدار بدهد.
     * ستون زمانی جدا لازم نیست — همین کلید مرجع کافی است.
     */
    public function markUserLimitWarned(int $panelId, int $limit): void
    {
        (new Settings($this->db))->set('panelwarn:' . $panelId . ':users', (string) $limit);
    }

    public function markCutoffDone(int $panelId, int $disabledCount): void
    {
        $this->update($panelId, [
            'cutoff_done_at' => time(),
            'cutoff_count'   => $disabledCount,
        ]);
    }

    // ------------------------------------------------------------------
    // کمکی
    // ------------------------------------------------------------------

    /**
     * آیا اعتبار زمانی این پنل تمام شده است؟
     *
     * @param array<string, mixed> $panel
     */
    public static function isExpired(array $panel, ?int $now = null): bool
    {
        $expireAt = $panel['access_expire_at'] ?? null;

        if ($expireAt === null) {
            return false;   // بدون انقضا (نامحدود)
        }

        return (int) $expireAt <= ($now ?? time());
    }

    /**
     * تاریخ پایان مهلت ارفاقی (انقضا + مهلت).
     *
     * چرا مهلت ارفاقی؟ انقضا یعنی «قرارداد تمام شد» ولی نه لزوماً «همین
     * الان باید سرویس مشتریان را قطع کرد». اگر تاریخ اشتباه محاسبه شده باشد
     * یا نماینده چند ساعت بعد از انقضا شارژ کند، قطع فوری یعنی قطع خدمت
     * مردم. پس چند روز فرصت می‌دهیم و بعد درخواست قطع می‌دهیم.
     *
     * @param array<string, mixed> $panel
     */
    public static function graceDeadline(array $panel, int $graceDays, ?int $now = null): ?int
    {
        $expireAt = $panel['access_expire_at'] ?? null;

        if ($expireAt === null) {
            return null;
        }

        return (int) $expireAt + max(0, $graceDays) * 86400;
    }

    /**
     * آیا پنل در مهلت ارفاقی است؟ (انقضا گذشته ولی هنوز فرصت دارد)
     *
     * @param array<string, mixed> $panel
     */
    public static function isInGrace(array $panel, int $graceDays, ?int $now = null): bool
    {
        $now = $now ?? time();

        if (!self::isExpired($panel, $now)) {
            return false;
        }

        return (int) $panel['access_expire_at'] + max(0, $graceDays) * 86400 > $now;
    }

    /**
     * آیا مهلت ارفاقی هم تمام شده (یعنی واقعاً باید قطع دسترسی کرد)؟
     *
     * @param array<string, mixed> $panel
     */
    public static function isGraceOver(array $panel, int $graceDays, ?int $now = null): bool
    {
        return self::isExpired($panel, $now) && !self::isInGrace($panel, $graceDays, $now);
    }

    /**
     * چند روز از مهلت ارفاقی باقی مانده (منفی = گذشته).
     *
     * @param array<string, mixed> $panel
     */
    public static function graceDaysLeft(array $panel, int $graceDays, ?int $now = null): ?int
    {
        $deadline = self::graceDeadline($panel, $graceDays, $now);

        if ($deadline === null) {
            return null;
        }

        return (int) ceil(($deadline - ($now ?? time())) / 86400);
    }

    /**
     * چند روز تا انقضا مانده (منفی یعنی گذشته).
     *
     * @param array<string, mixed> $panel
     */
    public static function daysLeft(array $panel, ?int $now = null): ?int
    {
        $expireAt = $panel['access_expire_at'] ?? null;

        if ($expireAt === null) {
            return null;
        }

        return (int) ceil(((int) $expireAt - ($now ?? time())) / 86400);
    }

    /**
     * آیا پنل از نظر ربات قابل استفاده است؟
     *
     * نکتهٔ مهم: پنلی که فقط زمانش تمام شده ولی هنوز روی خودِ پنل فعال است،
     * «منقضی» حساب می‌شود — چون قرارداد فروش رفته و کرون باید کاربرانش را
     * قطع کند.
     *
     * @param array<string, mixed> $panel
     */
    public static function isUsable(array $panel, ?int $now = null): bool
    {
        if (self::isExpired($panel, $now)) {
            return false;
        }

        return in_array((string) ($panel['panel_status'] ?? ''), [self::STATUS_ACTIVE, self::STATUS_LIMITED], true);
    }

    /**
     * برچسب فارسی وضعیت پنل.
     *
     * @param array<string, mixed> $panel
     */
    public static function statusLabel(array $panel): string
    {
        $status = (string) ($panel['panel_status'] ?? '');

        $label = match ($status) {
            self::STATUS_ACTIVE   => '🟢 فعال',
            self::STATUS_LIMITED  => '🟡 محدود (حجم تمام شده)',
            self::STATUS_DISABLED => '⛔️ غیرفعال',
            self::STATUS_REVOKED  => '🔑 نیازمند ورود مجدد',
            self::STATUS_EXPIRED  => '⌛️ اعتبار تمام شده',
            default               => $status !== '' ? $status : '—',
        };

        // وضعیت پنل ممکن است هنوز فعال باشد ولی قرارداد تمام شده باشد؛
        // در این حالت «منقضی» حرف آخر است چون باید به کاربر اطلاع داده شود.
        return self::isExpired($panel) ? '⌛️ اعتبار تمام شده' : $label;
    }

    /**
     * تبدیل وضعیت پنل به شکل داخلی ربات.
     */
    public static function mapStatus(string $status): string
    {
        return match ($status) {
            'active'   => self::STATUS_ACTIVE,
            'limited'  => self::STATUS_LIMITED,
            'disabled' => self::STATUS_DISABLED,
            default    => self::STATUS_ACTIVE,
        };
    }

    /**
     * وضعیت نهایی یک ادمین از روی پاسخ API.
     *
     * علاوه بر متن status، پرچم‌های صریح `is_disabled` و `is_limited` هم
     * خوانده می‌شوند چون ممکن است status همچنان active باشد ولی حساب
     * واقعاً بسته/محدود شده باشد (در آن صورت سینک موفق نشان می‌داد ولی
     * عملیات بعدی ۴۰۳ می‌خورد و کاربر گیج می‌شد).
     *
     * @param array<string, mixed> $adminDetails
     */
    public static function resolveApiStatus(array $adminDetails): string
    {
        if (!empty($adminDetails['is_disabled'])) {
            return self::STATUS_DISABLED;
        }

        if (!empty($adminDetails['is_limited'])) {
            return self::STATUS_LIMITED;
        }

        return self::mapStatus((string) ($adminDetails['status'] ?? 'active'));
    }

    /**
     * بیرون کشیدن لینک اشتراک از پاسخ پنل.
     *
     * پنل‌های مختلف نام فیلد متفاوتی برای این می‌گذارند، پس چند کلید
     * شناخته‌شده امتحان می‌شود. اگر مقدار یک URL کامل بود همان برگردانده
     * می‌شود؛ اگر توکن بود با الگوی قابل تنظیم ساخته می‌شود.
     *
     * @param array<string, mixed> $details
     */
    public static function extractSubUrl(array $details): ?string
    {
        foreach (['sub_url', 'subscription_url', 'sub_link', 'sub', 'sub_token', 'subscription_token', 'token'] as $key) {
            $value = $details[$key] ?? null;

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);

            if (preg_match('#^https?://#i', $value) === 1) {
                return $value;
            }

            // توکن خام → ساخت لینک با الگوی تنظیمات
            $pattern = \Pasargad\Support\Config::str('panel.sub_url_pattern', '/sub/{token}');

            return rtrim(\Pasargad\Support\Config::str('panel.base_url', ''), '/')
                . str_replace('{token}', rawurlencode($value), $pattern);
        }

        return null;
    }

    /**
     * آدرس ورود به پنل (قابل تنظیم در config چون پنل‌ها مسیر متفاوت دارند).
     */
    public static function loginUrl(?string $stored = null): string
    {
        if ($stored !== null && trim($stored) !== '') {
            return trim($stored);
        }

        return rtrim(\Pasargad\Support\Config::str('panel.base_url', ''), '/');
    }

    /**
     * رمز عبور رمزگشایی‌شدهٔ پنل.
     *
     * @param array<string, mixed> $panel
     * @return string رشتهٔ خالی یعنی رمز در دسترس نیست
     */
    public function plainPassword(array $panel): string
    {
        $encrypted = (string) ($panel['panel_password'] ?? '');

        if ($encrypted === '') {
            return '';
        }

        try {
            return Crypto::decrypt($encrypted);
        } catch (\Throwable $e) {
            Logger::error('Cannot decrypt panel password', [
                'panel_id' => $panel['id'] ?? null,
                'error'    => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * پنل‌هایی که کرون باید برایشان هشدار بررسی کند.
     *
     * فقط پنل‌های «زنده» برگردانده می‌شوند تا کرون هر ۵ دقیقه بی‌جهت روی
     * پنل‌های منقضی‌شده درخواست نزند. پنجرهٔ `lookback` باید از بیشترین
     * مقدارِ ممکن (هشدار انقضا + مهلت ارفاقی) بزرگ‌تر باشد.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWatchable(int $limit = 200, int $lookbackDays = 60): array
    {
        return $this->db->all(
            "SELECT p.*, u.telegram_id, u.is_blocked
             FROM panels p
             INNER JOIN users u ON u.id = p.user_id
             WHERE u.is_blocked = 0
               AND p.panel_status IN ('active','limited','expired')
               AND (p.access_expire_at IS NULL OR p.access_expire_at > :cutoff)
             ORDER BY p.access_expire_at IS NULL, p.access_expire_at ASC
             LIMIT " . max(1, min($limit, 500)),
            ['cutoff' => time() - max(1, $lookbackDays) * 86400]
        );
    }
}