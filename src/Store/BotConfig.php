<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Logger;

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

    /**
     * مقدارِ قابل‌ویرایش از دیتابیس، و اگر خالی بود مقدارِ config.php.
     *
     * ⚠️ چرا «خالی» هم مثل «نبود» رفتار می‌کند: مایگریشن برای این کلیدها
     * ردیفِ خالی می‌سازد. اگر فقط `null` را نشانهٔ نبود بدانیم، مقدارِ
     * `config.php` هیچ‌وقت استفاده نمی‌شود و نصبی که کارت را در config
     * گذاشته، بی‌دلیل «درگاه پیکربندی‌نشده» می‌بیند.
     */
    private function setting(string $key, string $configKey, string $default = ''): string
    {
        $value = $this->settings->get($key);

        if ($value !== null && trim($value) !== '') {
            return $value;
        }

        return Config::str($configKey, $default);
    }

    /**
     * خواندنِ رازی که در دیتابیس رمزنگاری‌شده ذخیره می‌شود.
     *
     * ⚠️ سازگاری با مقدارهای قدیمی: ردیف‌هایی که پیش از افزودن رمزنگاری
     * ذخیره شده‌اند، پیشوند `v1:` ندارند و همان‌طور خام برگردانده می‌شوند؛
     * وگرنه با یک تغییر کوچک، کلیدهایی که کاربر قبلاً وارد کرده از کار
     * می‌افتادند.
     */
    private function secret(string $key, string $configKey, string $default = ''): string
    {
        $value = $this->setting($key, $configKey, $default);

        if ($value === '' || !str_starts_with($value, 'v1:')) {
            return $value;
        }

        try {
            return Crypto::decrypt($value);
        } catch (\Throwable $e) {
            Logger::error('Cannot decrypt bot setting', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * ذخیرهٔ رمزنگاری‌شده (اگر رمزنگاری در دسترس باشد).
     */
    private function setSecret(string $key, string $value): void
    {
        $value = trim($value);

        if ($value === '') {
            $this->settings->set($key, '');
            return;
        }

        if (Crypto::available() && Config::str('crypto_key', '') !== '') {
            try {
                $this->settings->set($key, Crypto::encrypt($value));
                return;
            } catch (\Throwable $e) {
                Logger::warning('Cannot encrypt bot setting; stored as-is', [
                    'key'   => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->settings->set($key, $value);
    }

    // ------------------------------------------------------------------
    // کارت‌به‌کارت دستی
    // ------------------------------------------------------------------

    public function cardNumber(): string
    {
        return $this->setting('store_card_number', 'store.card_number');
    }

    public function setCardNumber(string $value): void
    {
        $this->settings->set('store_card_number', trim($value));
    }

    public function cardOwner(): string
    {
        return $this->setting('store_card_owner', 'store.card_owner');
    }

    public function setCardOwner(string $value): void
    {
        $this->settings->set('store_card_owner', trim($value));
    }

    public function cardBank(): string
    {
        return $this->setting('store_card_bank', 'store.card_bank');
    }

    public function setCardBank(string $value): void
    {
        $this->settings->set('store_card_bank', trim($value));
    }

    public function cardNote(): string
    {
        return $this->setting('store_card_note', 'store.card_note');
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
        return $this->secret('nowpayments_api_key', 'nowpayments.api_key');
    }

    public function setNowpaymentsApiKey(string $value): void
    {
        $this->setSecret('nowpayments_api_key', $value);
    }

    public function nowpaymentsIpnSecret(): string
    {
        return $this->secret('nowpayments_ipn_secret', 'nowpayments.ipn_secret');
    }

    public function setNowpaymentsIpnSecret(string $value): void
    {
        $this->setSecret('nowpayments_ipn_secret', $value);
    }

    // ------------------------------------------------------------------
    // کارت‌به‌کارت خودکار (AutoCard)
    // ------------------------------------------------------------------

    public function autocardApiUrl(): string
    {
        return $this->setting('autocard_api_url', 'autocard.api_url');
    }

    public function setAutocardApiUrl(string $value): void
    {
        $this->settings->set('autocard_api_url', trim($value));
    }

    public function autocardApiKey(): string
    {
        return $this->secret('autocard_api_key', 'autocard.api_key');
    }

    public function setAutocardApiKey(string $value): void
    {
        $this->setSecret('autocard_api_key', $value);
    }

    public function autocardCardNumber(): string
    {
        return $this->setting('autocard_card_number', 'autocard.card_number');
    }

    public function setAutocardCardNumber(string $value): void
    {
        $this->settings->set('autocard_card_number', trim($value));
    }

    public function autocardCardOwner(): string
    {
        return $this->setting('autocard_card_owner', 'autocard.card_owner');
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
        $value = $this->secret('panel_api_key', 'panel.api_key');

        return $value === 'YOUR_PANEL_API_KEY_HERE' ? '' : $value;
    }

    public function setOwnerApiKey(string $value): void
    {
        $this->setSecret('panel_api_key', $value);
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
        return $this->setting('panel_base_url', 'panel.base_url', 'https://us.api-system.top');
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

        if ($value === null || trim($value) === '') {
            return Config::int('panel.rep_role_id', 0);
        }

        return (int) $value;
    }

    public function setRepRoleId(int $value): void
    {
        $this->settings->set('panel_rep_role_id', (string) $value);
    }
}