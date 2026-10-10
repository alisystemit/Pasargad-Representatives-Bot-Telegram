<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Config;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * 🔧 تنظیمات اتصال Pasargad
 *
 * این کلاس صفحات تنظیمات اتصال پنل Pasargad را مدیریت می‌کند:
 * - تنظیمات پایه (base_url)
 * - اکانت owner برای فروش پنل نمایندگی
 * - نقش برای نمایندگان
 * - تنظیمات پرداخت
 */
final class PasarguardSettings
{
    private BotApi $bot;

    public function __construct(BotApi $bot)
    {
        $this->bot = $bot;
    }

    /**
     * منوی اصلی تنظیمات Pasargad
     */
    public function mainMenu(int $chatId): void
    {
        $baseUrl = Config::str('panel.base_url', '');
        $owner = Config::str('panel.owner_username', '');
        $autocard = Config::str('autocard.api_url', '');
        $card = Config::str('store.card_number', '');

        $status = [
            $baseUrl ? '✅' : '❌' => 'آدرس پنل',
            $owner ? '✅' : '❌' => 'اکانت Owner',
            $autocard ? '✅' : '❌' => 'کارت خودکار',
            $card ? '✅' : '❌' => 'کارت دستی',
        ];

        $lines = [
            '🔧✨ <b>تنظیمات Pasargad 🌐</b>',
            '',
            '📋 وضعیت فعلی:',
        ];

        foreach ($status as $icon => $name) {
            $lines[] = '  ' . $icon . ' ' . $name;
        }

        $lines[] = '';
        $lines[] = '👇 انتخاب کنید:';

        $keyboard = Keyboard::rows([
            [['text' => '🌐 آدرس پنل', 'data' => BotApi::encodeData('admin.settings.panel.base_url')]],
            [['text' => '👤 اکانت Owner', 'data' => BotApi::encodeData('admin.settings.panel.owner')]],
            [['text' => '🎭 نقش نماینده', 'data' => BotApi::encodeData('admin.settings.panel.role')]],
            [['text' => '💳 روش پرداخت', 'data' => BotApi::encodeData('admin.settings.payment')]],
            [['text' => '⚡️ کارت خودکار', 'data' => BotApi::encodeData('admin.settings.autocard')]],
            Keyboard::back(BotApi::encodeData('admin.settings'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * تنظیم آدرس پنل
     */
    public function setPanelBaseUrl(int $chatId): void
    {
        $current = Config::str('panel.base_url', 'https://us.api-system.top');
        
        $lines = [
            '🌐 <b>آدرس پنل Pasargad</b>',
            '',
            '📌 آدرس فعلی:',
            '<code>' . Str::escape($current) . '</code>',
            '',
            '💡 آدرس جدید را بفرستید (مثال: https://panel.example.com)',
            '',
            '⚠️ <b>نکات مهم:</b>',
            '• حتماً با <code>https://</code> شروع کنید',
            '• بدون <code>/</code> انتهایی',
            '• /cancel برای لغو',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * تنظیم اکانت Owner
     */
    public function setOwnerAccount(int $chatId, ?string $step = null): void
    {
        $current = Config::str('panel.owner_username', '');

        if ($step === 'username') {
            $lines = [
                '👤 <b>نام کاربری Owner</b>',
                '',
                '📌 فعلی:',
                '<code>' . ($current ? Str::escape($current) : '---') . '</code>',
                '',
                'نام کاربری پنل Pasargad را بفرستید',
                '(این اکانت برای ساخت پنل‌های نمایندگی استفاده می‌شود)',
                '',
                '/cancel برای لغو',
            ];
        } else {
            $pass = Config::str('panel.owner_password', '');
            $lines = [
                '👤✨ <b>اکانت Owner برای ساخت پنل 🌐</b>',
                '',
                '📋 وضعیت فعلی:',
                '• نام کاربری: ' . ($current ? '<code>' . Str::escape($current) . '</code>' : '❌ تنظیم نشده'),
                '• رمز عبور: ' . ($pass ? '✅ ذخیره شده (رمزشده)' : '❌ تنظیم نشده'),
                '',
                '💡 این اکانت باید دسترسی owner/admin به پنل داشته باشد',
                '',
                '👇 انتخاب کنید:',
            ];

            $keyboard = Keyboard::rows([
                [['text' => '✏️ نام کاربری', 'data' => BotApi::encodeData('admin.settings.panel.owner', ['step' => 'username'])]],
                [['text' => '🔑 رمز عبور', 'data' => BotApi::encodeData('admin.settings.panel.owner', ['step' => 'password'])]],
                [['text' => '🧪 تست اتصال', 'data' => BotApi::encodeData('admin.settings.panel.owner.test')]],
                Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
            ]);

            $this->bot->sendMessage($chatId, implode("\n", $lines), [
                'reply_markup' => $this->bot->buildMarkup($keyboard),
            ]);
            return;
        }

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.panel.owner'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * تنظیم نقش نماینده
     */
    public function setRepRole(int $chatId): void
    {
        $roleId = Config::int('panel.rep_role_id', 0);

        $lines = [
            '🎭 <b>نقش نماینده در پنل</b>',
            '',
            '📌 نقش فعلی:',
            '<code>' . ($roleId > 0 ? Str::faNumber($roleId) : 'خودکار (پیش‌فرض)') . '</code>',
            '',
            '💡 توضیح:',
            '• اگر خالی بگذارید: نقش پیش‌فرض پنل استفاده می‌شود',
            '• عدد مشخص: ربات این نقش را برای نمایندگان استفاده می‌کند',
            '',
            '⚠️ <b>نکات:</b>',
            '• شناسهٔ نقش را از API پنل دریافت کنید',
            '• بیشتر پنل‌ها: admin=1, operator=2, reseller=3',
            '',
            'عدد نقش را بفرستید (یا 0 برای خودکار)',
            '/cancel برای لغو',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * تنظیمات پرداخت (کارت دستی)
     */
    public function paymentSettings(int $chatId): void
    {
        $card = Config::str('store.card_number', '');
        $owner = Config::str('store.card_owner', '');
        $bank = Config::str('store.card_bank', '');

        $lines = [
            '💳✨ <b>تنظیمات پرداخت 💰</b>',
            '',
            '📋 کارت دستی فعلی:',
            '• شماره: ' . ($card ? '<code>' . Str::maskCardNumber($card) . '</code>' : '❌ تنظیم نشده'),
            '• صاحب: ' . ($owner ? '<code>' . Str::escape($owner) . '</code>' : '❌ تنظیم نشده'),
            '• بانک: ' . ($bank ? '<code>' . Str::escape($bank) . '</code>' : '❌ تنظیم نشده'),
            '',
            '👇 انتخاب کنید:',
        ];

        $keyboard = Keyboard::rows([
            [['text' => '💳 شماره کارت', 'data' => BotApi::encodeData('admin.settings.payment.card')]],
            [['text' => '👤 صاحب کارت', 'data' => BotApi::encodeData('admin.settings.payment.owner')]],
            [['text' => '🏦 نام بانک', 'data' => BotApi::encodeData('admin.settings.payment.bank')]],
            Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * تنظیمات کارت خودکار
     */
    public function autocardSettings(int $chatId, ?string $step = null): void
    {
        $apiUrl = Config::str('autocard.api_url', '');
        $apiKey = Config::str('autocard.api_key', '');
        $card = Config::str('autocard.card_number', '');

        $lines = [
            '⚡️✨ <b>کارت‌به‌کارت خودکار 🤖</b>',
            '',
            '📋 وضعیت فعلی:',
            '• API: ' . ($apiUrl ? '✅ تنظیم شده' : '❌ تنظیم نشده'),
            '• کلید: ' . ($apiKey ? '✅ ذخیره شده (رمزشده)' : '❌ تنظیم نشده'),
            '• کارت: ' . ($card ? '✅ تنظیم شده' : '❌ استفاده از کارت دستی'),
            '',
            '💡 در این سیستم:',
            '• ربات یک مبلغ یکتا برای هر سفارش ایجاد می‌کند',
            '• سپس خودکار از سرویس استعلام می‌خواهد',
            '• و پرداخت را تأیید می‌کند 🎯',
            '',
        ];

        if ($step) {
            match ($step) {
                'api_url' => $this->setAutocardApiUrl($chatId),
                'api_key' => $this->setAutocardApiKey($chatId),
                'card' => $this->setAutocardCard($chatId),
                default => null,
            };
            return;
        }

        $lines[] = '👇 انتخاب کنید:';

        $keyboard = Keyboard::rows([
            [['text' => '🌐 آدرس API', 'data' => BotApi::encodeData('admin.settings.autocard', ['step' => 'api_url'])]],
            [['text' => '🔑 کلید API', 'data' => BotApi::encodeData('admin.settings.autocard', ['step' => 'api_key'])]],
            [['text' => '💳 شماره کارت', 'data' => BotApi::encodeData('admin.settings.autocard', ['step' => 'card'])]],
            Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function setAutocardApiUrl(int $chatId): void
    {
        $current = Config::str('autocard.api_url', '');

        $lines = [
            '🌐 <b>آدرس API استعلام تراکنش</b>',
            '',
            '📌 فعلی:',
            '<code>' . ($current ? Str::escape($current) : '---') . '</code>',
            '',
            'آدرس API سرویس استعلام را بفرستید',
            '(GET request، پاسخ JSON)',
            '',
            '/cancel برای لغو',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.autocard'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function setAutocardApiKey(int $chatId): void
    {
        $lines = [
            '🔑 <b>کلید API</b>',
            '',
            'کلید API سرویس استعلام را بفرستید',
            '(در هدر Authorization: Bearer ارسال می‌شود)',
            '',
            '⚠️ کلید <b>رمزشده</b> ذخیره می‌شود',
            '',
            '/cancel برای لغو',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.autocard'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function setAutocardCard(int $chatId): void
    {
        $current = Config::str('autocard.card_number', '');

        $lines = [
            '💳 <b>شماره کارت خودکار</b>',
            '',
            '📌 فعلی:',
            '<code>' . ($current ? Str::maskCardNumber($current) : 'استفاده از کارت دستی' ) . '</code>',
            '',
            'اگر خالی بگذارید، کارت دستی استفاده می‌شود',
            '',
            'شماره کارت (۱۶ رقم) را بفرستید یا /cancel',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.autocard'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }
}
