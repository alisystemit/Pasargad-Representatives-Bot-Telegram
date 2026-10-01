<?php

declare(strict_types=1);

namespace Pasargad\Store;

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

    public function isUserToolsEnabled(): bool
    {
        return $this->settings->bool(Settings::USER_TOOLS, true);
    }

    public function setUserToolsEnabled(bool $enabled): void
    {
        $this->settings->set(Settings::USER_TOOLS, $enabled ? '1' : '0');
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
            'nowpayments'   => $this->isGatewayEnabled(NowPaymentsGateway::NAME),
            'renewal'       => $this->isRenewalEnabled(),
            'user_tools'    => $this->isUserToolsEnabled(),
            'shop'          => $this->settings->bool(Settings::SHOP_OPENED, true),
            'auto_apply'    => $this->settings->bool(Settings::AUTO_APPLY, true),
        ];
    }
}