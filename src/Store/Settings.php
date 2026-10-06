<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;
use Pasargad\Support\Logger;

/**
 * تنظیمات قابل تغییر از داخل ربات (کلید-مقدار در جدول settings).
 *
 * ⚠️ **نکتهٔ مهم برای کسی که تست یا ابزار می‌نویسد:**
 * حافظهٔ نهان `self::$cache` **استاتیک** است، یعنی بین همهٔ نمونه‌ها و حتی بین
 * اتصال‌های مختلف دیتابیس مشترک است. نتیجه:
 *   • نوشتن مستقیم با `UPDATE settings …` آن را دور می‌زند و `get()` مقدار
 *     **کهنه** را برمی‌گرداند. همیشه از `set()` استفاده کنید.
 *   • در تست‌هایی که `TestDb` اتصال را عوض می‌کند، اگر قبلش چیزی خوانده شده
 *     باشد، با `Settings::flush()` حافظه را خالی کنید.
 */
final class Settings
{
    private Db $db;
    private static ?array $cache = null;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            $this->load();
        }

        $value = self::$cache[$key] ?? null;

        return $value === null ? $default : (string) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            ['k' => $key, 'v' => $value, 't' => time()]
        );

        if (self::$cache === null) {
            $this->load();
        }
        self::$cache[$key] = $value;
    }

    /**
     * @param array<string, string|int> $pairs
     */
    public function setMany(array $pairs): void
    {
        $this->db->transaction(function () use ($pairs): void {
            foreach ($pairs as $key => $value) {
                $this->set((string) $key, (string) $value);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $this->load();

        return self::$cache ?? [];
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private function load(): void
    {
        $rows = $this->db->all('SELECT key, value FROM settings');
        $data = [];
        foreach ($rows as $row) {
            $data[(string) $row['key']] = (string) $row['value'];
        }
        self::$cache = $data;

        Logger::debug('Settings loaded', ['count' => count($data)]);
    }

    // ------------------------------------------------------------------
    // کلیدهای تنظیمات
    // ------------------------------------------------------------------

    // فروشگاه و اجرا
    public const SHOP_OPENED = 'shop_opened';
    public const AUTO_APPLY  = 'auto_apply';

    // کل ربات
    public const BOT_ENABLED         = 'bot_enabled';
    public const BOT_DISABLED_NOTICE = 'bot_disabled_notice';

    // درگاه‌های پرداخت (هر کدام مستقلاً قابل خاموش/روشن شدن)
    public const GATEWAY_CARD2CARD      = 'gateway_card2card';
    public const GATEWAY_NOWPAYMENTS    = 'gateway_nowpayments';
    public const GATEWAY_AUTOCARD       = 'gateway_autocard';

    // قابلیت‌ها
    public const RENEWAL_ENABLED = 'renewal_enabled';     // تمدید بسته/کاربر
    public const USER_TOOLS      = 'user_tools_enabled';  // ساخت و تمدید کاربر (حذف‌شده)

    // ------------------------------------------------------------------
    // نمایندگان و پنل‌ها
    // ------------------------------------------------------------------

    /** همگام‌سازی خودکار وضعیت پنل‌ها از API توسط کرون */
    public const PANEL_SYNC = 'panel_sync_cron';

    /** هشدار «حجم رو به اتمام» به خریدار و مدیر */
    public const LOW_VOLUME_ALERT = 'low_volume_alert';

    /** چند روز قبل از انقضا هشدار داده شود */
    public const EXPIRE_WARN_DAYS = 'expire_warn_days';

    /** پس از اتمام اعتبار، درخواست قطع دسترسی کاربران پنل داده شود */
    public const CUTOFF_ON_EXPIRE = 'cutoff_on_expire';

    /**
     * مهلت ارفاقی (روز) پس از انقضا و پیش از قطع دسترسی.
     *
     * صفر یعنی رفتار قدیمی: به‌محض انقضا، قطع دسترسی پیشنهاد می‌شود.
     */
    public const EXPIRE_GRACE_DAYS = 'expire_grace_days';

    // ------------------------------------------------------------------
    // تخفیف و معرفی
    // ------------------------------------------------------------------
    public const COUPONS_ENABLED = 'coupons_enabled';

    public const REFERRAL_ENABLED = 'referral_enabled';

    /** درصد تخفیف اولین خرید کاربری که با کد معرفی آمده */
    public const REFERRAL_DISCOUNT = 'referral_discount_percent';

    /** پاداش معرف به تومان (به کیف پول او اضافه می‌شود) */
    public const REFERRAL_BONUS = 'referral_bonus_toman';

    /** پاداش سطح دوم معرفی (پدر/مادرمعرف) به تومان */
    public const REFERRAL_BONUS_LEVEL2 = 'referral_bonus_level2_toman';

    // ------------------------------------------------------------------
    // تیکت پشتیبانی
    // ------------------------------------------------------------------
    public const TICKETS_ENABLED = 'tickets_enabled';

    // ------------------------------------------------------------------
    // آمار کاربران پنل
    // ------------------------------------------------------------------
    /** چند دقیقه آمار کاربران پنل تازه بماند قبل از خواندن دوباره از API */
    public const PANEL_STATS_TTL = 'panel_stats_ttl_minutes';

    // ------------------------------------------------------------------
    // کانفیگ تست
    // ------------------------------------------------------------------
    public const TEST_CONFIG_ENABLED   = 'test_config_enabled';
    public const TEST_CONFIG_VOLUME_GB = 'test_config_volume_gb';
    public const TEST_CONFIG_DAYS      = 'test_config_days';
    public const TEST_CONFIG_MAX       = 'test_config_max_per_user';
    public const TEST_CONFIG_COOLDOWN  = 'test_config_cooldown';

    /** شناسهٔ پنل ثابت برای تست (۰ = پنل خود کاربر) */
    public const TEST_CONFIG_PANEL_ID = 'test_config_panel_id';

    // ------------------------------------------------------------------
    // عضویت اجباری کانال و قوانین
    // ------------------------------------------------------------------
    public const CHANNEL_ENFORCED = 'channel_enforced';
    public const CHANNEL          = 'channel';
    public const CHANNEL_TTL      = 'channel_cache_minutes';
    public const RULES_TEXT       = 'rules_text';

    // ------------------------------------------------------------------
    // متن‌ها
    public const WELCOME_TEXT = 'welcome_text';
    public const SUPPORT_TEXT = 'support_text';
    public const HELP_TEXT = 'help_text';

    // ------------------------------------------------------------------
    // وفاداری
    // ------------------------------------------------------------------
    /** درصد تخفیفِ وفاداری روی خرید بعدی */
    public const LOYALTY_DISCOUNT = 'loyalty_discount_percent';
    /** حداقل امتیاز برای فعال‌شدن تخفیف وفاداری */
    public const LOYALTY_REDEEM   = 'loyalty_redeem_points';

    // ------------------------------------------------------------------
    // ضد brute-force
    // ------------------------------------------------------------------
    /** حداکثر تلاش ناموفق قبل از قفل */
    public const LOGIN_MAX_ATTEMPTS = 'login_max_attempts';
    /** مدت قفل به دقیقه */
    public const LOGIN_LOCK_MINUTES = 'login_lock_minutes';

    // ------------------------------------------------------------------
    // گزارش ادمین
    // ------------------------------------------------------------------
    public const REPORT_DAILY  = 'admin_daily_report';
    public const REPORT_WEEKLY = 'admin_weekly_report';
    public const REPORT_DAILY_LAST = 'admin_daily_report_last_at';
    public const REPORT_WEEKLY_LAST = 'admin_weekly_report_last_at';

    // ------------------------------------------------------------------
    // بکاپ‌گیری خودکار
    // ------------------------------------------------------------------
    /** off | daily | twice — زمان‌بندی بکاپ خودکار دیتابیس */
    public const BACKUP_SCHEDULE = 'backup_schedule';
    /** زمان آخرین بکاپ موفق (unix) */
    public const BACKUP_LAST_AT = 'backup_last_at';
    /** چند نسخهٔ بکاپ نگه داشته شود */
    public const BACKUP_KEEP    = 'backup_keep';

    // ------------------------------------------------------------------
    // ربات مدیریتی جدا (وبهوک دوم)
    // ------------------------------------------------------------------
    /** آماده است یا نه (از روی وجود admin_bot_token در کانفیگ) */
    public const ADMIN_BOT_READY = 'admin_bot_ready';

    /**
     * پیام پیش‌فرض وقتی ربات خاموش است.
     */
    public const DEFAULT_DISABLED_NOTICE = "⛔️🔴 <b>ربات در حال حاضر غیرفعال است! 😴</b>\n\n"
        . "سرویس موقتاً در دسترس نیست! لطفاً کمی بعد دوباره مراجعه کنید. ⏳🙏\n"
        . "در صورت نیاز به پشتیبانی با ما در تماس باشید. 📞💬";

    /**
     * قوانین پیش‌فرض نمایندگی.
     *
     * این متن به کاربر نشان داده می‌شود و ادمین می‌تواند از پنل مدیریت
     * تغییرش دهد. عمداً HTML است چون مستقیم در پیام تلگرام می‌رود.
     */
    public const DEFAULT_RULES = "📜✨ <b>قوانین و شرایط ارتباط با مدیریت ⚖️</b>\n\n"
        . "۱️⃣ هر نماینده پس از خرید پنل، یک حساب ادمین (اپراتور) در پنل دارد. 👑\n"
        . "۲️⃣🔒 اطلاعات ورود پنل فقط متعلق به خودِ شماست؛ انتشار یا فروش آن مجاز نیست! ⛔️\n"
        . "۳️⃣📦 حجم و اعتبار هر پنل جداگانه محاسبه می‌شود و تمدید آن فقط از همین ربات انجام می‌گیرد. 🤖\n"
        . "۴️⃣🔔 با کاهش حجم یا نزدیک شدن به انقضا، هشدار برای شما و مدیریت ارسال می‌شود. ⏳\n"
        . "۵️⃣✂️ پس از اتمام اعتبار پنل، دسترسی تمام کاربران آن پنل قطع می‌شود! 🔒\n"
        . "۶️⃣📢 عضویت در کانال رسمی ربات برای استفاده از سرویس الزامی است. ✅\n"
        . "۷️⃣⚖️ هرگونه سوءاستفاده از پنل، درخشک سرویس و پیگیری قانونی خواهد شد! 🚨\n\n"
        . "با پذیرش این قوانین موافقت می‌کنید. برای ارتباط با مدیریت از دکمهٔ پشتیبانی استفاده کنید. 📞🙏";

    /**
     * کلیدهایی که مقدارشان صفر/یک (bool) است و می‌توانند از پنل تغییر کنند.
     *
     * @var array<int, string>
     */
    public const TOGGLE_KEYS = [
        self::BOT_ENABLED,
        self::GATEWAY_CARD2CARD,
        self::GATEWAY_NOWPAYMENTS,
        self::GATEWAY_AUTOCARD,
        self::RENEWAL_ENABLED,
        self::SHOP_OPENED,
        self::AUTO_APPLY,
        self::PANEL_SYNC,
        self::CUTOFF_ON_EXPIRE,
        self::TEST_CONFIG_ENABLED,
        self::CHANNEL_ENFORCED,
        self::COUPONS_ENABLED,
        self::REFERRAL_ENABLED,
        self::TICKETS_ENABLED,
        self::REPORT_DAILY,
        self::REPORT_WEEKLY,
    ];
}