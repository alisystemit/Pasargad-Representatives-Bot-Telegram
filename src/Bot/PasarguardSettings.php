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
        $botConfig = new \Pasargad\Store\BotConfig();
        $baseUrl = $botConfig->panelBaseUrl();
        $botKey = $botConfig->ownerApiKey();
        $autocard = $botConfig->autocardApiUrl();
        $card = $botConfig->cardNumber();

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
        $current = (new \Pasargad\Store\BotConfig())->panelBaseUrl();
        
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
        $botConfig = new \Pasargad\Store\BotConfig();
        $current = $botConfig->ownerApiKey();
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
            '📝 برای تنظیم کلید جدید، لطفاً کلید API را در پیام بعدی ارسال کنید.',
            'کلید باید از پنل مرکزی Pasargad گرفته شده باشد.',
        ];

        $keyboard = Keyboard::rows([
            [['text' => '✏️ تنظیم کلید جدید', 'data' => BotApi::encodeData('admin.settings.panel.owner.set')]],
            [['text' => '🧪 تست اتصال', 'data' => BotApi::encodeData('admin.settings.panel.owner.test')]],
            [['text' => '🗑️ حذف کلید', 'data' => BotApi::encodeData('admin.settings.panel.owner.delete')]],
            Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }
    
    /**
     * شروع فرآیند تنظیم کلید API جدید.
     */
    public function startSetApiKey(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions): void
    {
        $sessions->set($telegramId, [
            'mode' => 'set_panel_api_key',
            'step' => 'enter_api_key',
        ]);
        
        $this->bot->sendMessage($chatId, implode("\n", [
            '🔑 <b>لطفاً کلید API را ارسال کنید</b>',
            '',
            'کلید باید از پنل مرکزی Pasargad گرفته شده باشد:',
            '1. وارد پنل Pasargad شوید',
            '2. به Settings → API Keys بروید',
            '3. یک کلید جدید با دسترسی‌های زیر بسازید:',
            '   • admins.create',
            '   • admins.read', 
            '   • api_keys.create',
            '   • admin_roles.read',
            '',
            '⚠️ بعد از ارسال، این پیام را حذف کنید چون کلید در گفتگو دیده می‌شود.',
            '❌ برای لغو: /cancel',
        ]));
    }
    
    /**
     * پردازش کلید API ارسال شده.
     */
    public function processApiKey(int $chatId, int $telegramId, string $apiKey, \Pasargad\Bot\SessionStore $sessions): void
    {
        $apiKey = trim($apiKey);
        
        if (empty($apiKey)) {
            $this->bot->sendMessage($chatId, '⚠️ کلید API نمی‌تواند خالی باشد. لطفاً دوباره ارسال کنید یا /cancel بزنید.');
            return;
        }
        
        // تست کلید
        $this->bot->sendMessage($chatId, '⏳ در حال تست کلید API…');
        
        try {
            $client = new PasarGuardClient();
            $client->useStaticToken($apiKey);
            $admin = $client->getAdminWithApiKey($apiKey);
            
            // ذخیره کلید در تنظیمات
            (new \Pasargad\Store\BotConfig())->setOwnerApiKey($apiKey);
            
            $sessions->clear($telegramId);
            
            $this->bot->sendMessage($chatId, implode("\n", [
                '✅ <b>کلید API با موفقیت تنظیم شد!</b>',
                '',
                'پنل: ' . ($admin['username'] ?? '—'),
                'نقش: ' . (is_array($admin['role'] ?? null) ? ($admin['role']['name'] ?? '—') : ($admin['role'] ?? '—')),
                '',
                '📋 سیستم اکنون آماده ساخت پنل‌های نمایندگی است.',
                'کاربران می‌توانند بستهٔ «پنل نمایندگی» را خریداری کنند.',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.settings.panel.owner'), '🔙 بازگشت'),
                ])),
            ]);
            
        } catch (PanelException $e) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '❌ <b>کلید API معتبر نیست</b>',
                '',
                Str::escape($e->getMessage()),
                '',
                '💡 مطمئن شوید کلید دسترسی‌های لازم را دارد.',
                'لطفاً کلید صحیح را ارسال کنید یا /cancel بزنید.',
            ]));
        }
    }
    
    /**
     * حذف کلید API ذخیره شده.
     */
    public function deleteApiKey(int $chatId): void
    {
        (new \Pasargad\Store\BotConfig())->deleteOwnerApiKey();
        
        $this->bot->sendMessage($chatId, implode("\n", [
            '🗑️ <b>کلید API حذف شد</b>',
            '',
            'ساخت پنل نمایندگی تا تنظیم مجدد کلید غیرفعال است.',
            '',
            'برای تنظیم مجدد از دکمهٔ «✏️ تنظیم کلید جدید» استفاده کنید.',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.settings.panel.owner'), '🔙 بازگشت'),
            ])),
        ]);
    }

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * تنظیم نقش نماینده
     */
    public function setRepRole(int $chatId): void
    {
        $roleId = (new \Pasargad\Store\BotConfig())->repRoleId();

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

        $apiKey = (new \Pasargad\Store\BotConfig())->ownerApiKey();

        if ($apiKey === '') {
            $this->bot->sendMessage($chatId, implode("\n", [
                '❌ <b>تست ممکن نیست — کلید تنظیم نشده</b>',
                '',
                'کلید سازندهٔ پنل تنظیم نشده است.',
                '',
                'روی دکمهٔ «✏️ تنظیم کلید جدید» بزنید و کلید API را وارد کنید.',
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
        $botConfig = new \Pasargad\Store\BotConfig();
        $card = $botConfig->cardNumber();
        $owner = $botConfig->cardOwner();
        $bank = $botConfig->cardBank();

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
            [['text' => '🪙 ارز دیجیتال (NowPayments)', 'data' => BotApi::encodeData('admin.settings.nowpayments')]],
            Keyboard::back(BotApi::encodeData('admin.settings.pasargad'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * تنظیمات ارز دیجیتال (NowPayments)
     */
    public function nowpaymentsSettings(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions, ?string $step = null): void
    {
        $botConfig = new \Pasargad\Store\BotConfig();
        $apiKey = $botConfig->nowpaymentsApiKey();
        $ipnSecret = $botConfig->nowpaymentsIpnSecret();

        $lines = [
            '🪙✨ <b>ارز دیجیتال (NowPayments)</b>',
            '',
            '📋 وضعیت فعلی:',
            '• کلید API: ' . ($apiKey ? '✅ ذخیره شده (رمزشده)' : '❌ تنظیم نشده'),
            '• IPN Secret: ' . ($ipnSecret ? '✅ ذخیره شده (رمزشده)' : '❌ تنظیم نشده'),
            '',
            '👇 انتخاب کنید:',
        ];

        if ($step) {
            match ($step) {
                'api_key' => $this->setNowpaymentsApiKey($chatId, $telegramId, $sessions),
                'ipn' => $this->setNowpaymentsIpn($chatId, $telegramId, $sessions),
                default => null,
            };
            return;
        }

        $keyboard = Keyboard::rows([
            [['text' => '🔑 کلید API', 'data' => BotApi::encodeData('admin.settings.nowpayments', ['step' => 'api_key'])]],
            [['text' => '🔒 IPN Secret', 'data' => BotApi::encodeData('admin.settings.nowpayments', ['step' => 'ipn'])]],
            Keyboard::back(BotApi::encodeData('admin.settings.payment'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function setNowpaymentsApiKey(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions): void
    {
        $sessions->set($telegramId, ['step' => 'admin_nowpayments_api_key']);

        $lines = [
            '🔑 <b>کلید API NowPayments</b>',
            '',
            'کلید API درگاه NowPayments را بفرستید.',
            'از پنل NowPayments دریافت کنید: https://nowpayments.io/api-keys',
            '',
            '❌ برای لغو: /cancel',
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.settings.nowpayments'), '🔙 بازگشت'),
            ])),
        ]);
    }

    private function setNowpaymentsIpn(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions): void
    {
        $sessions->set($telegramId, ['step' => 'admin_nowpayments_ipn_secret']);

        $lines = [
            '🔒 <b>IPN Secret</b>',
            '',
            'Secret مورد استفاده برای امضای IPN را بفرستید.',
            'در پنل NowPayments > Settings > IPN تنظیم کنید.',
            '',
            '❌ برای لغو: /cancel',
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.settings.nowpayments'), '🔙 بازگشت'),
            ])),
        ]);
    }

    /**
     * تنظیمات کارت خودکار
     */
    public function autocardSettings(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions, ?string $step = null): void
    {
        $botConfig = new \Pasargad\Store\BotConfig();
        $apiUrl = $botConfig->autocardApiUrl();
        $apiKey = $botConfig->autocardApiKey();
        $card = $botConfig->autocardCardNumber();

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
                'api_url' => $this->setAutocardApiUrl($chatId, $telegramId, $sessions),
                'api_key' => $this->setAutocardApiKey($chatId, $telegramId, $sessions),
                'card' => $this->setAutocardCard($chatId, $telegramId, $sessions),
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

    private function setAutocardApiUrl(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions): void
    {
        $current = (new \Pasargad\Store\BotConfig())->autocardApiUrl();

        $sessions->set($telegramId, ['step' => 'admin_autocard_api_url']);

        $lines = [
            '🌐 <b>آدرس API استعلام تراکنش</b>',
            '',
            '📌 فعلی:',
            '<code>' . ($current ? Str::escape($current) : '---') . '</code>',
            '',
            'آدرس API سرویس استعلام را بفرستید',
            '(GET request، پاسخ JSON)',
            '',
            '❌ برای لغو: /cancel',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.autocard'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function setAutocardApiKey(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions): void
    {
        $sessions->set($telegramId, ['step' => 'admin_autocard_api_key']);

        $lines = [
            '🔑 <b>کلید API</b>',
            '',
            'کلید API سرویس استعلام را بفرستید',
            '(در هدر Authorization: Bearer ارسال می‌شود)',
            '',
            '⚠️ کلید <b>رمزشده</b> ذخیره می‌شود',
            '',
            '❌ برای لغو: /cancel',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.autocard'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function setAutocardCard(int $chatId, int $telegramId, \Pasargad\Bot\SessionStore $sessions): void
    {
        $current = (new \Pasargad\Store\BotConfig())->autocardCardNumber();

        $sessions->set($telegramId, ['step' => 'admin_autocard_card']);

        $lines = [
            '💳 <b>شماره کارت خودکار</b>',
            '',
            '📌 فعلی:',
            '<code>' . ($current ? Str::maskCardNumber($current) : 'استفاده از کارت دستی' ) . '</code>',
            '',
            'اگر خالی بگذارید، کارت دستی استفاده می‌شود',
            '',
            'شماره کارت (۱۶ رقم) را بفرستید',
            '❌ برای لغو: /cancel',
        ];

        $keyboard = Keyboard::rows([
            Keyboard::back(BotApi::encodeData('admin.settings.autocard'), '🔙 بازگشت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }
}
