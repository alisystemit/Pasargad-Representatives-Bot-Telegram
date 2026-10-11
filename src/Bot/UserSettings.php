<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * منوی «⚙️ تنظیمات» کاربر.
 *
 * چرا این کلاس وجود دارد؟
 *
 * دکمهٔ تنظیمات در منوی اصلی از قبل بود ولی هیچ مسیری (namespace) پشتش نبود،
 * پس کلیک روی آن فقط «این گزینه در دسترس نیست» می‌داد. این کلاس آن مسیر را
 * کامل می‌کند.
 *
 * ⚠️ نکتهٔ مهم دربارهٔ دامنهٔ کار:
 *
 * این منو **تنظیمات شخصی کاربر** را نشان می‌دهد (کد تخفیف، وضعیت حساب، پنل‌ها).
 * تنظیمات سراسری مثل آدرس پنل یا کارت پرداخت در `PasarguardSettings` و فقط برای
 * سوپرادمین است. چون جدول `users` ستون‌های `email`/`phone`/`language` ندارد،
 * این کلاس ادعای ویرایش آن‌ها را هم نمی‌کند — چیزی که ذخیره نشود نباید در UI
 * وعده داده شود.
 */
final class UserSettings
{
    private BotApi $bot;
    private UserRepository $users;
    private Settings $settings;
    private PanelRepository $panels;

    public function __construct(
        BotApi $bot,
        UserRepository $users,
        Settings $settings,
        PanelRepository $panels
    ) {
        $this->bot      = $bot;
        $this->users    = $users;
        $this->settings = $settings;
        $this->panels   = $panels;
    }

    /**
     * صفحهٔ اصلی تنظیمات کاربر.
     *
     * @param array<string, mixed> $user رکورد کاربر
     */
    public function showMenu(int $chatId, array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);

        $panelCount = $this->panels->countByUser($userId);
        $couponCode = $this->users->couponCode($userId);
        $isBlocked  = !empty($user['is_blocked']);

        $lines = [
            '⚙️ <b>تنظیمات</b>',
            '',
            '🆔 <b>آیدی عددی شما:</b> <code>' . $userId . '</code>',
            '👤 <b>نام:</b> ' . Str::escape((string) ($user['first_name'] ?? '—')),
        ];

        if (!empty($user['username'])) {
            $lines[] = '📱 <b>یوزرنیم:</b> @' . Str::escape((string) $user['username']);
        }

        $lines[] = '🌐 <b>زبان تلگرام:</b> ' . Str::escape((string) ($user['language_code'] ?? '—'));
        $lines[] = '🖥️ <b>پنل‌های فعال:</b> ' . Str::faNumber($panelCount);
        $lines[] = '💳 <b>کد تخفیف شما:</b> ' . ($couponCode !== ''
            ? '<code>' . Str::escape($couponCode) . '</code>'
            : '—');

        if ($isBlocked) {
            $lines[] = '';
            $lines[] = '⛔️ <b>حساب شما مسدود است.</b>';
            $lines[] = '📩 دلیل: ' . Str::escape((string) ($user['blocked_reason'] ?? 'نامشخص'));
        }

        $lines[] = '';
        $lines[] = '👇 <b>از گزینه‌های زیر استفاده کنید:</b>';

        $keyboard = [
            [['text' => '🖥️ پنل‌های من', 'data' => BotApi::encodeData('panel.list')]],
            [['text' => '➕ اتصال پنل جدید (آدرس + رمز یا کلید API)', 'data' => BotApi::encodeData('panel.connect')]],
            [['text' => '🎟️ کد تخفیف من', 'data' => BotApi::encodeData('coupon.apply')]],
            [['text' => '👤 حساب من', 'data' => BotApi::encodeData('user.account')]],
            [['text' => '🔐 تنظیمات مدیریتی', 'data' => BotApi::encodeData('settings.global')]],
            [['text' => '📜 قوانین', 'data' => BotApi::encodeData('rules')]],
            [['text' => '❓ راهنما', 'data' => BotApi::encodeData('help')]],
            Keyboard::back('menu', '🏠 بازگشت به منو'),
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * توضیح کوتاه دربارهٔ اینکه تنظیمات سراسری کجاست.
     */
    public function showGlobalNote(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            "🔐 <b>تنظیمات مدیریتی</b>\n\n"
            . "آدرس پنل، اطلاعات ورود و درگاه‌های پرداخت فقط برای مدیر قابل تغییر است.\n\n"
            . "👑 مسیر: <b>پنل مدیریت ← تنظیمات ← پنل پاسارگاد</b>",
            [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back('settings', '⬅️ بازگشت'),
                ])),
            ]
        );
    }
}