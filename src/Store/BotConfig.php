<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Config;

/**
 * تنظیمات قابل ویرایش ربات.
 *
 * این کلاس یک لایه بافر بین فایل config.php و جدول settings است.
 * اولویت با مقادیر ذخیره شده در جدول settings است (که از داخل ربات
 * توسط سوپر ادمین قابل تغییر هستند). اگر مقداری در settings نباشد،
 * از config.php استفاده می‌شود.
 */
final class BotConfig
{
    private Settings $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    // ------------------------------------------------------------------
    // کارت‌به‌کارت دستی
    // ------------------------------------------------------------------

    public function cardNumber(): string
    {
        return $this->settings->get('store_card_number', Config::str('store.card_number', ''));
    }

    public function setCardNumber(string $value): void
    {
        $this->settings->set('store_card_number', trim($value));
    }

    public function cardOwner(): string
    {
        return $this->settings->get('store_card_owner', Config::str('store.card_owner', ''));
    }

    public function setCardOwner(string $value): void
    {
        $this->settings->set('store_card_owner', trim($value));
    }

    public function cardBank(): string
    {
        return $this->settings->get('store_card_bank', Config::str('store.card_bank', ''));
    }

    public function setCardBank(string $value): void
    {
        $this->settings->set('store_card_bank', trim($value));
    }

    public function cardNote(): string
    {
        return $this->settings->get('store_card_note', Config::str('store.card_note', ''));
    }

    public function setCardNote(string $value): void
    {
        $this->settings->set('store_card_note', trim($value));
    }

    // ------------------------------------------------------------------
    // NowPayments (ارز دیجیتال)
    // ------------------------------------------------------------------

    public function nowpaymentsApiKey(): string
    {
        return $this->settings->get('nowpayments_api_key', Config::str('nowpayments.api_key', ''));
    }

    public function setNowpaymentsApiKey(string $value): void
    {
        $this->settings->set('nowpayments_api_key', trim($value));
    }

    public function nowpaymentsIpnSecret(): string
    {
        return $this->settings->get('nowpayments_ipn_secret', Config::str('nowpayments.ipn_secret', ''));
    }

    public function setNowpaymentsIpnSecret(string $value): void
    {
        $this->settings->set('nowpayments_ipn_secret', trim($value));
    }

    // ------------------------------------------------------------------
    // کارت‌به‌کارت خودکار (AutoCard)
    // ------------------------------------------------------------------

    public function autocardApiUrl(): string
    {
        return $this->settings->get('autocard_api_url', Config::str('autocard.api_url', ''));
    }

    public function setAutocardApiUrl(string $value): void
    {
        $this->settings->set('autocard_api_url', trim($value));
    }

    public function autocardApiKey(): string
    {
        return $this->settings->get('autocard_api_key', Config::str('autocard.api_key', ''));
    }

    public function setAutocardApiKey(string $value): void
    {
        $this->settings->set('autocard_api_key', trim($value));
    }

    public function autocardCardNumber(): string
    {
        return $this->settings->get('autocard_card_number', Config::str('autocard.card_number', ''));
    }

    public function setAutocardCardNumber(string $value): void
    {
        $this->settings->set('autocard_card_number', trim($value));
    }

    public function autocardCardOwner(): string
    {
        return $this->settings->get('autocard_card_owner', Config::str('autocard.card_owner', ''));
    }

    public function setAutocardCardOwner(string $value): void
    {
        $this->settings->set('autocard_card_owner', trim($value));
    }

    // ------------------------------------------------------------------
    // اکانت سازنده (برای ساخت پنل نمایندگی)
    // ------------------------------------------------------------------

    public function ownerApiKey(): string
    {
        $value = $this->settings->get('panel_api_key');

        if ($value !== null && $value !== '' && $value !== 'YOUR_PANEL_API_KEY_HERE') {
            return $value;
        }

        $configValue = Config::str('panel.api_key', '');

        return ($configValue === '' || $configValue === 'YOUR_PANEL_API_KEY_HERE') ? '' : $configValue;
    }

    public function setOwnerApiKey(string $value): void
    {
        $this->settings->set('panel_api_key', trim($value));
    }

    public function deleteOwnerApiKey(): void
    {
        $this->settings->delete('panel_api_key');
    }

    // ------------------------------------------------------------------
    // آدرس پنل مرکزی
    // ------------------------------------------------------------------

    public function panelBaseUrl(): string
    {
        return $this->settings->get('panel_base_url', Config::str('panel.base_url', 'https://us.api-system.top'));
    }

    public function setPanelBaseUrl(string $value): void
    {
        $this->settings->set('panel_base_url', rtrim(trim($value), '/'));
    }

    // ------------------------------------------------------------------
    // نقش نماینده
    // ------------------------------------------------------------------

    public function repRoleId(): int
    {
        $value = $this->settings->get('panel_rep_role_id');

        if ($value === null) {
            return Config::int('panel.rep_role_id', 0);
        }

        return (int) $value;
    }

    public function setRepRoleId(int $value): void
    {
        $this->settings->set('panel_rep_role_id', (string) $value);
    }
}