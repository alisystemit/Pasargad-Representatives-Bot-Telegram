<?php
declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Config;
use Pasargad\Support\Str;
use Pasargad\Store\PanelRepository;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * 🎨📱 منوی کامل و زیبای ربات تلگرام
 *
 * این کلاس تمام صفحات ربات را با طراحی زیبا و کامل تولید می‌کند.
 */
final class FullMenu
{
    private BotApi $bot;

    public function __construct(BotApi $bot)
    {
        $this->bot = $bot;
    }

    /**
     * آدرس Mini App.
     *
     * ⚠️ چرا `base_url` کانفیگ و نه `HTTP_HOST`؟
     *
     * `HTTP_HOST` را **کلاینت** می‌فرستد. اگر آدرس Mini App از آن ساخته
     * شود، یک درخواست با `Host: دامنهٔ-م attacker` باعث می‌شود ربات در پیام
     * خودش لینکی به دامنهٔ مهاجم بسازد — و آن لینک را برای همهٔ کاربران
     * بفرستد. یعنی یک سرریز مستقیم اطلاعات از طریق ربات.
     *
     * اگر `base_url` تنظیم نشده باشد، رشتهٔ خالی برمی‌گردد و
     * `BotApi::normalizeButton()` آن دکمه را حذف می‌کند (بی‌سروصدا، بهتر
     * از یک دکمهٔ شکسته که کاربر را گمراه کند).
     */
    private function webAppUrl(): string
    {
        $base = rtrim(trim(Config::str('base_url', '')), '/');

        if ($base === '' || !str_starts_with($base, 'https://')) {
            return '';
        }

        return $base . '/webapp.php';
    }

    // ------------------------------------------------------------------
    //  🏠 منوی اصلی
    // ------------------------------------------------------------------

    public function mainMenu(int $chatId, array $user, bool $isAdmin = false): void
    {
        $name = Str::escape((string) ($user['first_name'] ?? 'دوست عزیز'));
        $panelsCount = (int) ($user['panel_count'] ?? 0);
        $wallet = Str::formatToman((int) ($user['wallet_balance'] ?? 0));

        $lines = [
            '🌟 <b>سلام ' . $name . ' عزیز!</b> 👋',
            '',
            '┌─ سخت و شکیل ─',
            '│ 🖥 پنل‌های شما: <b>' . (string) $panelsCount . '</b> عدد',
            '│ 💰 موجودی کیف پول: <b>' . $wallet . '</b>',
            '│ 🏆 امتیاز: ' . Str::faNumber((int) ($user['loyalty_points'] ?? 0)),
            '└────────────',
            '',
            'چی انجام بدم؟ 👇',
        ];

        $keyboard = Keyboard::rows([
            // ردیف اول
            [
                ['text' => '🛒 فروشگاه پنل', 'data' => BotApi::encodeData('shop')],
                ['text' => '👤 حساب من', 'data' => BotApi::encodeData('user.account')],
            ],
            // ردیف دوم
            [
                ['text' => '🖥️ پنل‌های من', 'data' => BotApi::encodeData('panel.list')],
                ['text' => '💰 کیف پول', 'data' => BotApi::encodeData('user.wallet')],
            ],
            // ردیف سوم
            [
                ['text' => '📦 سفارش‌ها', 'data' => BotApi::encodeData('order.list')],
                ['text' => '🎫 پشتیبانی', 'data' => BotApi::encodeData('ticket.list')],
            ],
            // ردیف چهارم
            [
                ['text' => '🧪 تست کانفیگ', 'data' => BotApi::encodeData('panel.test.list')],
                ['text' => '🎁 دعوت دوستان', 'data' => BotApi::encodeData('referral.my')],
            ],
            // ردیف پنجم — دکمهٔ Mini App
            [['text' => '⚙️ تنظیمات', 'data' => BotApi::encodeData('settings')]],
            $this->webAppButton(),
        ]);

        if ($isAdmin) {
            $keyboard[] = [['text' => '🛠 پنل مدیریت VIP', 'data' => BotApi::encodeData('admin.home')]];
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * ردیف دکمهٔ Mini App (یا خالی اگر آدرس معتبر نباشد).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function webAppButton(): array
    {
        $url = $this->webAppUrl();

        if ($url === '') {
            return [];
        }

        return [[['text' => '📱 اپلیکیشن وب', 'web_app' => $url]]];
    }

    /**
     * همان ردیف، برای وقتی که کلاس دیگری کیبورد را می‌سازد.
     *
     * عمومی است چون `Kernel::mainMenuKeyboard()` هم کیبورد خودش را دارد و باید
     * همان دکمه را داشته باشد؛ تکرار ساختن URL در دو جا یعنی یکی از آن دو
     * جا بعداً با دیگری ناهماهنگ می‌شود.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function webAppRows(): array
    {
        return $this->webAppButton();
    }

    // ------------------------------------------------------------------
    //  🛒 فروشگاه
    // ------------------------------------------------------------------

    public function shopMenu(int $chatId): void
    {
        $lines = [
            '🛍️✨ <b>فروشگاه پنل 🌟</b>',
            '',
            'انتخاب کنید چه می‌خواهید:',
        ];

        $keyboard = Keyboard::rows([
            [
                ['text' => '🖥 پنل نمایندگی جدید', 'data' => BotApi::encodeData('shop', ['kind' => 'agency'])],
                ['text' => '⚡️ شارژ پنل موجود', 'data' => BotApi::encodeData('shop', ['kind' => 'topup'])],
            ],
            [['text' => '🎟️ کد تخفیف', 'data' => BotApi::encodeData('coupon.apply')]],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    // ------------------------------------------------------------------
    //  👤 حساب کاربری
    // ------------------------------------------------------------------

    public function accountMenu(int $chatId, array $user): void
    {
        $name = Str::escape((string) ($user['first_name'] ?? 'کاربر'));
        $username = Str::escape((string) ($user['username'] ?? '---'));
        $telegramId = (int) ($user['telegram_id'] ?? 0);
        $wallet = Str::formatToman((int) ($user['wallet_balance'] ?? 0));
        $orders = (int) ($user['orders_count'] ?? 0);
        $totalPaid = Str::formatToman((int) ($user['total_paid'] ?? 0));
        $points = Str::faNumber((int) ($user['loyalty_points'] ?? 0));

        $lines = [
            '👤✨ <b>پروفایل کاربری</b>',
            '',
            '📝 اطلاعات شخصی:',
            '• نام: <b>' . $name . '</b>',
            '• آیدی: @' . $username,
            '• آیدی عددی: <code>' . $telegramId . '</code>',
            '',
            '📊 آمار شما:',
            '• 🔢 سفارش‌ها: <b>' . (string) $orders . '</b>',
            '• 💰 مجموع خرید: <b>' . $totalPaid . '</b>',
            '• 🏆 امتیاز: <b>' . $points . '</b>',
            '• 👛 موجودی: <b>' . $wallet . '</b>',
        ];

        $keyboard = Keyboard::rows([
            [['text' => '👛 کیف پول من', 'data' => BotApi::encodeData('user.wallet')]],
            [['text' => '🧾 تاریخچه پرداخت‌ها', 'data' => BotApi::encodeData('user.payments')]],
            [['text' => '🎟️ کد تخفیف من', 'data' => BotApi::encodeData('coupon.my')]],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    // ------------------------------------------------------------------
    //  🖥️ پنل‌های من
    // ------------------------------------------------------------------

    public function panelsMenu(int $chatId, array $panels): void
    {
        $lines = ['🖥️✨ <b>پنل‌های من 🌐</b> (' . Str::faNumber(count($panels)) . ')', ''];

        if (empty($panels)) {
            $lines[] = '📭 هنوز هیچ پنلی ندارید!';
            $lines[] = '';
            $lines[] = '🛒 برای خرید پنل جدید وارد فروشگاه شوید 👇';
        } else {
            foreach ($panels as $panel) {
                $status = PanelRepository::statusLabel($panel);
                $limit = Str::formatBytes((int) ($panel['data_limit'] ?? 0));
                $used = Str::formatBytes((int) ($panel['used_traffic'] ?? 0));
                $lines[] = $status . ' <b>' . Str::escape((string) $panel['panel_username']) . '</b>';
                $lines[] = '   💾 ' . $limit . ' • 📥 ' . $used;
                $lines[] = '';
            }
        }

        $keyboard = Keyboard::rows([
            [['text' => '🔄 بروزرسانی همه', 'data' => BotApi::encodeData('panel.refresh')]],
            [['text' => '🔗 اضافه کردن پنل موجود', 'data' => BotApi::encodeData('panel.self')]],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    // ------------------------------------------------------------------
    //  💰 کیف پول
    // ------------------------------------------------------------------

    public function walletMenu(int $chatId, int $balance, array $history = []): void
    {
        $lines = [
            '👛✨ <b>کیف پول 💰</b>',
            '',
            '💰 موجودی فعلی: <b>' . Str::formatToman($balance) . '</b> 💵',
            '',
        ];

        if (!empty($history)) {
            $lines[] = '📜 <b>آخرین تراکنش‌ها:</b>';
            foreach (array_slice($history, 0, 5) as $tx) {
                $amount = Str::formatToman((int) ($tx['amount'] ?? 0));
                $kind = (string) ($tx['kind'] ?? 'manual');
                $icon = (int) ($tx['amount'] ?? 0) >= 0 ? '➕' : '➖';
                $lines[] = $icon . ' ' . $amount . ' (' . $kind . ')';
            }
        }

        $keyboard = Keyboard::rows([
            [['text' => '💳 واریز وجه', 'data' => BotApi::encodeData('wallet.deposit')]],
            [['text' => '📜 تاریخچه کامل', 'data' => BotApi::encodeData('user.payments')]],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    // ------------------------------------------------------------------
    //  🎫 پشتیبانی
    // ------------------------------------------------------------------

    public function supportMenu(int $chatId, int $openCount = 0): void
    {
        $lines = [
            '🎫✨ <b>پشتیبانی و تیکت‌ها 📞</b>',
            '',
        ];

        if ($openCount > 0) {
            $lines[] = '📬 شما <b>' . Str::faNumber($openCount) . '</b> تیکت باز دارید!';
            $lines[] = '';
        }

        $lines[] = 'چه کمکی می‌توانم به شما بکنم؟ 👇';

        $keyboard = Keyboard::rows([
            [['text' => '📝 ثبت تیکت جدید', 'data' => BotApi::encodeData('ticket.new')]],
            [['text' => '📋 تیکت‌های من', 'data' => BotApi::encodeData('ticket.list')]],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    // ------------------------------------------------------------------
    //  🎁 دعوت دوستان
    // ------------------------------------------------------------------

    public function referralMenu(int $chatId, array $summary): void
    {
        $code = (string) ($summary['code'] ?? '');
        $invited = (int) ($summary['invited'] ?? 0);
        $rewarded = (int) ($summary['rewarded'] ?? 0);
        $bonusEach = Str::formatToman((int) ($summary['bonus_each'] ?? 0));

        $lines = [
            '🎁✨ <b>دعوت دوستان 👥</b>',
            '',
            '🔗 کد اختصاصی شما:',
            '<code>' . Str::escape($code) . '</code>',
            '',
            '👥 دوستان دعوت‌شده: <b>' . Str::faNumber($invited) . '</b>',
            '✅ پاداش تعلق‌گرفته: <b>' . Str::faNumber($rewarded) . '</b>',
            '💰 پاداش هر دعوت موفق: <b>' . $bonusEach . '</b>',
            '',
            'دوستانتان با کد شما تخفیف می‌گیرند! 🎉',
        ];

        $keyboard = Keyboard::rows([
            [['text' => '👛 کیف پول من', 'data' => BotApi::encodeData('user.wallet')]],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    // ------------------------------------------------------------------
    //  ⚙️ تنظیمات
    // ------------------------------------------------------------------

    public function settingsMenu(int $chatId): void
    {
        $lines = [
            '⚙️✨ <b>تنظیمات 🔧</b>',
            '',
            'یک گزینه را انتخاب کنید:',
        ];

        $keyboard = Keyboard::rows([
            [['text' => '🔔 اعلان‌ها', 'data' => BotApi::encodeData('setting.notifications')]],
            [['text' => '🌍 زبان', 'data' => BotApi::encodeData('setting.language')]],
            [['text' => '💳 روش پرداخت', 'data' => BotApi::encodeData('setting.payment')]],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    // ------------------------------------------------------------------
    //  🛠 پنل مدیریت (VIP)
    // ------------------------------------------------------------------

    public function adminMenu(int $chatId, array $stats): void
    {
        $lines = [
            '🛠✨ <b>پنل مدیریت VIP 👑</b>',
            '',
            '📊 وضعیت کلی:',
            '• 👥 کاربران: <b>' . Str::faNumber((int) ($stats['users'] ?? 0)) . '</b>',
            '• 🖥️ پنل‌ها: <b>' . Str::faNumber((int) ($stats['panels'] ?? 0)) . '</b>',
            '• 🧾 سفارش‌ها: <b>' . Str::faNumber((int) ($stats['orders'] ?? 0)) . '</b>',
            '• 💰 درآمد: <b>' . Str::formatToman((int) ($stats['revenue'] ?? 0)) . '</b>',
            '',
            '👇 انتخاب کنید:',
        ];

        $keyboard = Keyboard::rows([
            [
                ['text' => '🖥️ پنل‌ها', 'data' => BotApi::encodeData('admin.panels')],
                ['text' => '📦 بسته‌ها', 'data' => BotApi::encodeData('admin.packages')],
            ],
            [
                ['text' => '🧾 سفارش‌ها', 'data' => BotApi::encodeData('admin.orders')],
                ['text' => '👥 کاربران', 'data' => BotApi::encodeData('admin.users')],
            ],
            [
                ['text' => '💰 درآمد', 'data' => BotApi::encodeData('admin.revenue')],
                ['text' => '📋 لاگ حسابرسی', 'data' => BotApi::encodeData('admin.audit_log')],
            ],
            [
                ['text' => '🚀 رشد و نگهداشت', 'data' => BotApi::encodeData('admin.grow')],
                ['text' => '⚙️ تنظیمات', 'data' => BotApi::encodeData('admin.settings')],
            ],
            [
                ['text' => '💾 بکاپ', 'data' => BotApi::encodeData('admin.backup')],
                ['text' => '📣 پیام همگانی', 'data' => BotApi::encodeData('admin.broadcast')],
            ],
            Keyboard::back('menu', '🔙 منوی اصلی'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }
}