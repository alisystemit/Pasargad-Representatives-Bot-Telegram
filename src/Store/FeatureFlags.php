<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Payment\AutoCardGateway;
use Pasargad\Payment\CardToCardGateway;
use Pasargad\Payment\NowPaymentsGateway;

/**
 * مدیریت یکپارچهٔ کلیدهای فعال/غیرفعال و متن‌های سفارشی ربات.
 *
 * هدف: یک منبع حقیقت برای این پرسش‌ها:
 *   • ربات کل فعال است یا نه؟
 *   • وقتی خاموش است، چه متنی به کاربر نشان داده شود؟
 *   • هر درگاه پرداخت جداگانه فعال است یا نه؟
 *   • تمدید و ساخت کاربر مجاز است یا نه؟
 *
 * همهٔ مقادیر در جدول settings ذخیره می‌شوند تا از داخل ربات قابل تغییر باشند.
 */
final class FeatureFlags
{
    private Settings $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * دسترسی به لایهٔ تنظیمات (برای ابزارهای خط فرمان و کرون).
     */
    public function settings(): Settings
    {
        return $this->settings;
    }

    // ------------------------------------------------------------------
    // کل ربات
    // ------------------------------------------------------------------

    /**
     * آیا ربات برای کاربران عادی فعال است؟
     *
     * سوپرادمین‌ها همیشه دسترسی دارند تا بتوانند ربات را دوباره روشن کنند.
     */
    public function isBotEnabled(): bool
    {
        return $this->settings->bool(Settings::BOT_ENABLED, true);
    }

    public function setBotEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::BOT_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * متنی که وقتی ربات خاموش است به کاربر نشان داده می‌شود.
     *
     * اگر متن پیش‌فرض تغییر نکند، مقدار پیش‌فرض برگردانده می‌شود.
     */
    public function disabledNotice(): string
    {
        $notice = trim((string) $this->settings->get(Settings::BOT_DISABLED_NOTICE, ''));

        return $notice !== '' ? $notice : Settings::DEFAULT_DISABLED_NOTICE;
    }

    public function setDisabledNotice(string $text): void
    {
        $this->settings->set(Settings::BOT_DISABLED_NOTICE, trim($text));
    }

    public function resetDisabledNotice(): void
    {
        $this->settings->set(Settings::BOT_DISABLED_NOTICE, '');
    }

    // ------------------------------------------------------------------
    // درگاه‌های پرداخت
    // ------------------------------------------------------------------

    /**
     * کلید تنظیم متناظر هر درگاه.
     */
    public function gatewaySettingKey(string $gatewayName): ?string
    {
        return match ($gatewayName) {
            CardToCardGateway::NAME   => Settings::GATEWAY_CARD2CARD,
            NowPaymentsGateway::NAME => Settings::GATEWAY_NOWPAYMENTS,
            AutoCardGateway::NAME    => Settings::GATEWAY_AUTOCARD,
            default                  => null,
        };
    }

    /**
     * آیا درگاه از نظر سوییچ ربات فعال است؟ (مستقل از تنظیمات کانفیگ)
     */
    public function isGatewayEnabled(string $gatewayName): bool
    {
        $key = $this->gatewaySettingKey($gatewayName);

        // درگاه ناشناخته پیش‌فرض فعال است تا رفتار غیرمنتظره نداشته باشیم.
        if ($key === null) {
            return true;
        }

        return $this->settings->bool($key, true);
    }

    public function setGatewayEnabled(string $gatewayName, bool $enabled): bool
    {
        $key = $this->gatewaySettingKey($gatewayName);
        if ($key === null) {
            return false;
        }

        $this->settings->set($key, $enabled ? '1' : '0');

        return true;
    }

    /**
     * وضعیت کامل همهٔ درگاه‌ها برای نمایش در پنل مدیریت.
     *
     * @return array<string, array{enabled:bool, configured:bool}>
     */
    public function gatewayStatuses(): array
    {
        $configured = [
            CardToCardGateway::NAME   => \Pasargad\Support\Config::str('store.card_number') !== '',
            NowPaymentsGateway::NAME => \Pasargad\Support\Config::str('nowpayments.api_key') !== '',
            AutoCardGateway::NAME    => AutoCardGateway::isConfigured(),
        ];

        $result = [];
        foreach ($configured as $name => $isConfigured) {
            $result[$name] = [
                'enabled'    => $this->isGatewayEnabled($name),
                'configured' => $isConfigured,
            ];
        }

        return $result;
    }

    /**
     * آیا حداقل یک درگاه پرداخت آمادهٔ استفاده است؟
     *
     * @param  array<string, bool> $gatewayIsEnabled نتیجهٔ isEnabled() هر درگاه
     */
    public function hasAvailableGateway(array $gatewayIsEnabled): bool
    {
        foreach ($gatewayIsEnabled as $name => $isEnabled) {
            if ($isEnabled && $this->isGatewayEnabled((string) $name)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // قابلیت‌ها
    // ------------------------------------------------------------------

    public function isRenewalEnabled(): bool
    {
        return $this->settings->bool(Settings::RENEWAL_ENABLED, true);
    }

    public function setRenewalEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::RENEWAL_ENABLED, $enabled ? '1' : '0');
    }

    // ------------------------------------------------------------------
    // پنل‌های نمایندگی
    // ------------------------------------------------------------------

    /** همگام‌سازی خودکار پنل‌ها از API توسط کرون */
    public function isPanelSyncEnabled(): bool
    {
        return $this->settings->bool(Settings::PANEL_SYNC, true);
    }

    public function setPanelSyncEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::PANEL_SYNC, $enabled ? '1' : '0');
    }

    /** دریافت تست کانفیگ */
    public function isTestConfigEnabled(): bool
    {
        return $this->settings->bool(Settings::TEST_CONFIG_ENABLED, true);
    }

    public function setTestConfigEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::TEST_CONFIG_ENABLED, $enabled ? '1' : '0');
    }

    /** پس از انقضا، درخواست قطع دسترسی کاربران پنل داده شود */
    public function isCutoffOnExpireEnabled(): bool
    {
        return $this->settings->bool(Settings::CUTOFF_ON_EXPIRE, true);
    }

    public function setCutoffOnExpireEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::CUTOFF_ON_EXPIRE, $enabled ? '1' : '0');
    }

    /** عضویت کانال برای کاربران عادی اجباری باشد */
    public function isChannelEnforced(): bool
    {
        return $this->settings->bool(Settings::CHANNEL_ENFORCED, false);
    }

    public function setChannelEnforced(bool $enforced): void
    {
        $this->settings->set(Settings::CHANNEL_ENFORCED, $enforced ? '1' : '0');
    }

    // ------------------------------------------------------------------
    // تخفیف، معرفی و تیکت
    // ------------------------------------------------------------------

    /** کدهای تخفیف فعال باشند */
    public function isCouponsEnabled(): bool
    {
        return $this->settings->bool(Settings::COUPONS_ENABLED, true);
    }

    public function setCouponsEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::COUPONS_ENABLED, $enabled ? '1' : '0');
    }

    /** سیستم معرفی کاربر فعال باشد */
    public function isReferralEnabled(): bool
    {
        return $this->settings->bool(Settings::REFERRAL_ENABLED, true);
    }

    public function setReferralEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::REFERRAL_ENABLED, $enabled ? '1' : '0');
    }

    /** تیکت پشتیبانی داخل ربات فعال باشد */
    public function isTicketsEnabled(): bool
    {
        return $this->settings->bool(Settings::TICKETS_ENABLED, true);
    }

    public function setTicketsEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::TICKETS_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * مهلت ارفاقی پس از انقضا (روز). صفر یعنی بدون مهلت.
     */
    public function graceDays(): int
    {
        return max(0, $this->settings->int(Settings::EXPIRE_GRACE_DAYS, 3));
    }

    /**
     * تغییر وضعیت یک کلید دوحالته.
     *
     * @return bool|null وضعیت جدید، یا null اگر کلید نامعتبر باشد
     */
    public function toggle(string $key): ?bool
    {
        if (!in_array($key, Settings::TOGGLE_KEYS, true)) {
            return null;
        }

        // کلیدهای درگاه از مسیر جداگانه مدیریت می‌شوند ولی در لیست هستند.
        $current = $this->settings->bool($key, true);
        $new     = !$current;
        $this->settings->set($key, $new ? '1' : '0');

        return $new;
    }

    /**
     * خلاصهٔ وضعیت همهٔ سوییچ‌ها برای نمایش در پنل.
     *
     * @return array<string, bool>
     */
    public function summary(): array
    {
        return [
            'bot'          => $this->isBotEnabled(),
            'card2card'     => $this->isGatewayEnabled(CardToCardGateway::NAME),
            'autocard'      => $this->isGatewayEnabled(AutoCardGateway::NAME),
            'nowpayments'   => $this->isGatewayEnabled(NowPaymentsGateway::NAME),
            'renewal'       => $this->isRenewalEnabled(),
            'panel_sync'    => $this->isPanelSyncEnabled(),
            'test_config'   => $this->isTestConfigEnabled(),
            'cutoff'        => $this->isCutoffOnExpireEnabled(),
            'channel'       => $this->isChannelEnforced(),
            'coupons'       => $this->isCouponsEnabled(),
            'referral'      => $this->isReferralEnabled(),
            'tickets'       => $this->isTicketsEnabled(),
            'shop'          => $this->settings->bool(Settings::SHOP_OPENED, true),
            'auto_apply'    => $this->settings->bool(Settings::AUTO_APPLY, true),
        ];
    }
}