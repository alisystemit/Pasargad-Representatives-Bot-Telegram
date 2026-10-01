<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;
use Pasargad\Support\Logger;

/**
 * تنظیمات قابل تغییر از داخل ربات (کلید-مقدار در جدول settings).
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
    public const SHOP_OPENED      = 'shop_opened';
    public const AUTO_APPLY       = 'auto_apply';
    public const LOW_VOLUME_ALERT = 'low_volume_alert';

    // کل ربات
    public const BOT_ENABLED         = 'bot_enabled';
    public const BOT_DISABLED_NOTICE = 'bot_disabled_notice';

    // درگاه‌های پرداخت (هر کدام مستقلاً قابل خاموش/روشن شدن)
    public const GATEWAY_CARD2CARD      = 'gateway_card2card';
    public const GATEWAY_NOWPAYMENTS    = 'gateway_nowpayments';

    // قابلیت‌ها
    public const RENEWAL_ENABLED = 'renewal_enabled';     // تمدید بسته/کاربر
    public const USER_TOOLS      = 'user_tools_enabled';  // ساخت و تمدید کاربر

    // متن‌ها
    public const WELCOME_TEXT = 'welcome_text';
    public const SUPPORT_TEXT = 'support_text';

    /**
     * پیام پیش‌فرض وقتی ربات خاموش است.
     */
    public const DEFAULT_DISABLED_NOTICE = "⛔️ <b>ربات در حال حاضر غیرفعال است</b>\n\n"
        . "سرویس موقتاً در دسترس نیست. لطفاً کمی بعد دوباره مراجعه کنید.\n"
        . "در صورت نیاز به پشتیبانی با ما در تماس باشید.";

    /**
     * کلیدهایی که مقدارشان صفر/یک (bool) است و می‌توانند از پنل تغییر کنند.
     *
     * @var array<int, string>
     */
    public const TOGGLE_KEYS = [
        self::BOT_ENABLED,
        self::GATEWAY_CARD2CARD,
        self::GATEWAY_NOWPAYMENTS,
        self::RENEWAL_ENABLED,
        self::USER_TOOLS,
        self::SHOP_OPENED,
        self::AUTO_APPLY,
    ];
}