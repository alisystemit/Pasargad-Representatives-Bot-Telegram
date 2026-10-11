<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
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
        $botKey = Config::str('panel.api_key', '');
        $autocard = Config::str('autocard.api_url', '');
        $card = Config::str('store.card_number', '');

        $status = [
            $baseUrl ? '✅' : '❌' => 'آدرس پنل',
            $botKey ? '✅' : '❌' => 'کلید سازندهٔ پنل',
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
            [['text' => '🔑 کلید سازندهٔ پنل', 'data' => BotApi::encodeData('admin.settings.panel.owner')]],
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
     * کلید سازندهٔ پنل.
     *
     * ⭐️ ربات دیگر **هیچ رمز عبوری نمی‌گیرد** — حتی از مدیر خودش. تنها راه
     * ساخت «پنل نمایندگی» یک کلید API است که ساختِ آن داخل خودِ پنل انجام
     * می‌شود و رمز مدیریتی هرگز از پنل بیرون نمی‌آید.
     *
     * کلید فقط برای خریداری لازم است که **پنلش را وصل نکرده**؛ اگر نماینده
     * پنل خودش را با کلید خودش وصل کند، ساخت روی همان پنل انجام می‌شود و
     * این مقدار اصلاً استفاده نمی‌شود.
     */
    public function setOwnerAccount(int $chatId): void
    {
        $current = Config::str('panel.api_key', '');
        $masked  = $current === ''
            ? ''
            : '…' . substr($current, -4);

        $lines = [
            '🔑✨ <b>کلید سازندهٔ پنل نمایندگی 🌐</b>',
            '',
            '📋 وضعیت فعلی: ' . ($current
                ? '✅ تنظیم شده (<code>' . $masked . '</code>)'
                : '❌ تنظیم نشده'),
            '',
            '💡 این کلید فقط برای ساخت پنل نمایندگی است. اگر نماینده پنل خودش',
            'را با کلید خودش وصل کرده باشد، ساخت روی همان پنل انجام می‌شود.',
            '',
            '⚠️ ربات رمز عبور نمی‌گیرد. برای همین باید کلید را از داخل پنل',
            'بسازید: <b>Settings → API Keys → ساخت کلید جدید</b>',
            '',
            'دسترسی‌های لازم:',
            '• <code>admins.create</code> — ساخت پنل نمایندگی',
            '• <code>admins.read</code> — دیدن پنل ساخته‌شده',
            '• <code>api_keys.create</code> — ساخت کلید برای پنل نمایندگی',
            '• <code>admin_roles.read</code> — پیدا کردن نقش اپراتور',
            '',
            'بررسی کافی‌بودن کلید (فقط‌خواندنی، چیزی نمی‌سازد):',
            '<code>php tools/panel_key_check.php &lt;کلید&gt;</code>',
            '',
            '📝 برای ویرایش، مقدار را در <code>config.php</code> زیر',
            '<code>panel.api_key</code> بگذارید و ربات را ری‌استارت کنید.',
        ];

        $keyboard = Keyboard::rows([
            [['text' => '🧪 تست اتصال', 'data' => BotApi::encodeData('admin.settings.panel.owner.test')]],
            Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
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
     * 🧪 تست اتصال اکانت Owner
     *
     * قبلاً دکمهٔ «🧪 تست اتصال» در کیبورد صفحهٔ اکانت owner بود ولی هیچ
     * مسیری در `AdminController` آن را نمی‌گرفت، پس کلیک رویش به
     * «❓ این گزینه در پنل مدیریت تعریف نشده است» می‌رسید.
     *
     * این متد همان تست را انجام می‌دهد: با کلیدِ ذخیره‌شده هویت ادمین را
     * می‌خواند تا مدیر قبل از فروش پنل مطمئن شود کار می‌کند.
     */
    public function testOwnerConnection(int $chatId): void
    {
        $this->bot->sendMessage($chatId, '⏳ در حال تست اتصال به پنل…');

        $apiKey = Config::str('panel.api_key', '');

        if ($apiKey === '') {
            $this->bot->sendMessage($chatId, implode("\n", [
                '❌ <b>تست ممکن نیست — کلید تنظیم نشده</b>',
                '',
                'مقدار <code>panel.api_key</code> در <code>config.php</code> خالی است.',
                '',
                'کلید را از داخل پنل بسازید (Settings → API Keys) و آنجا بگذارید.',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.settings.panel.owner'), '🔙 بازگشت'),
                ])),
            ]);

            return;
        }

        try {
            $client = new PasarGuardClient();
            $client->useStaticToken($apiKey);
            $admin  = $client->getAdminWithApiKey($apiKey);
        } catch (PanelException $e) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '❌ <b>اتصال برقرار نشد</b>',
                '',
                Str::escape($e->getMessage()),
                '',
                '💡 اگر پیام «مجوز ندارید» دیدید، کلید باید دسترسی <code>admins.read</code> داشته باشد.',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.settings.panel.owner'), '🔙 بازگشت'),
                ])),
            ]);

            return;
        }

        $name = (string) ($admin['username'] ?? '—');
        $role = is_array($admin['role'] ?? null)
            ? (string) ($admin['role']['name'] ?? '')
            : (string) ($admin['role'] ?? '');

        $this->bot->sendMessage($chatId, implode("\n", [
            '✅ <b>اتصال برقرار شد</b>',
            '',
            '• ادمین کلید: <code>' . Str::escape($name) . '</code>',
            '• نقش: ' . ($role !== '' ? Str::escape($role) : '—'),
            '• آدرس پنل: <code>' . Str::escape(Config::str('panel.base_url', '—')) . '</code>',
            '',
            '🎉 کلید معتبر است.',
            '',
            '⚠️ بررسی کامل کافی‌بودنِ دسترسی‌ها با این دکمه ممکن نیست؛ برای',
            'دیدن فهرست دقیق مجوزهای کم‌شده این را اجرا کنید:',
            '<code>php tools/panel_key_check.php</code>',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.settings.panel.owner'), '🔙 بازگشت'),
            ])),
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
