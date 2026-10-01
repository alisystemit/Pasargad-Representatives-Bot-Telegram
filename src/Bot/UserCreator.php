<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\UserProvisioner;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * جریان ساخت/تمدید کاربر با «اعتبار کاربر» خریداری‌شده.
 *
 * دکمه‌ها: 👥 ساخت کاربر | 🔄 تمدید کاربر | 📋 لیست کاربران
 * سپس ربات مرحله‌به‌مرحله نام کاربری، حجم و مدت را می‌پرسد.
 */
final class UserCreator
{
    private UserRepository $users;
    private UserProvisioner $provisioner;
    private BotApi $bot;

    public function __construct(UserRepository $users, UserProvisioner $provisioner, BotApi $bot)
    {
        $this->users       = $users;
        $this->provisioner = $provisioner;
        $this->bot         = $bot;
    }

    /**
     * منوی ابزارهای کاربر.
     *
     * @param array<string, mixed> $user
     */
    public function showMenu(int $chatId, array $user): void
    {
        $credit = (int) $user['user_credit'];
        $flags  = new \Pasargad\Store\FeatureFlags();
        $toolsOn = $flags->isUserToolsEnabled();
        $renewOn = $flags->isRenewalEnabled();

        $lines = [
            '🎫 <b>ابزار کاربران</b>',
            '',
            '💾 اعتبار شما: <b>' . Str::formatBytes($credit) . '</b>',
        ];

        if ($user['user_credit_expire'] !== null) {
            $lines[] = '📅 انقضای اعتبار: ' . Str::date((int) $user['user_credit_expire']);
        }

        if (!$toolsOn) {
            $lines[] = '';
            $lines[] = '⚙️ این ابزار موقتاً غیرفعال است.';
        } elseif ($credit <= 0) {
            $lines[] = '';
            $lines[] = '⚠️ اعتباری برای ساخت کاربر ندارید.';
            $lines[] = 'برای افزایش، بستهٔ «اعتبار کاربر» را بخرید.';
        } else {
            $lines[] = '';
            $lines[] = 'با این ابزار می‌توانید برای مشتریان خود کاربر بسازید یا تمدید کنید.';
        }

        $keyboard = [];

        if (!$toolsOn) {
            $keyboard[] = [['text' => '🛒 خرید اعتبار کاربر', 'data' => BotApi::encodeData('shop', ['kind' => 'user_credit'])]];
        } elseif ($credit > 0) {
            $keyboard[] = [['text' => '➕ ساخت کاربر جدید', 'data' => BotApi::encodeData('uc.new')]];

            // دکمهٔ تمدید فقط وقتی نمایش داده می‌شود که قابلیتش روشن باشد.
            if ($renewOn) {
                $keyboard[] = [['text' => '🔄 تمدید کاربر', 'data' => BotApi::encodeData('uc.extend')]];
            } else {
                $keyboard[] = [['text' => '⏸️ تمدید (غیرفعال)', 'data' => BotApi::encodeData('noop')]];
            }

            $keyboard[] = [['text' => '📋 لیست کاربران پنل', 'data' => BotApi::encodeData('uc.list')]];
        } else {
            $keyboard[] = [[
                'text' => '🛒 خرید اعتبار کاربر',
                'data' => BotApi::encodeData('shop', ['kind' => 'user_credit']),
            ]];
        }

        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * شروع مرحلهٔ گرفتن نام کاربری.
     */
    public function askUsername(int $chatId, string $mode): void
    {
        $title = $mode === 'extend' ? '🔄 تمدید کاربر' : '➕ ساخت کاربر جدید';

        $this->bot->sendMessage($chatId, implode("\n", [
            $title,
            '',
            'نام کاربری کاربر را بفرستید.',
            '',
            'ℹ️ نام کاربری باید با حروف انگلیسی، عدد، _ یا - باشد.',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::back('user.credit', '❌ انصراف')),
        ]);
    }

    /**
     * پردازش مرحلهٔ نام کاربری (از طریق پیام متنی).
     *
     * @param  array<string, mixed> $user
     * @return bool true یعنی پیام در این جریان مصرف شد
     */
    public function handleUsername(int $chatId, array $user, string $mode, string $username): bool
    {
        $username = Str::toEnglishDigits(trim($username));

        if (!Str::isValidPanelUsername($username)) {
            $this->bot->sendMessage($chatId, '⚠️ نام کاربری نامعتبر است.');
            return true;
        }

        $this->bot->sendMessage($chatId, "حجم مورد نیاز را به گیگابایت بفرستید.\n\n💾 اعتبار فعلی شما: <b>"
            . Str::formatBytes((int) $user['user_credit']) . '</b>', [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::back('user.credit', '❌ انصراف')),
        ]);

        return true;
    }

    /**
     * پردازش مرحلهٔ حجم.
     *
     * @param  array<string, mixed> $user
     * @return bool
     */
    public function handleVolume(int $chatId, array $user, string $mode, string $username, string $volumeInput): bool
    {
        $volume = (float) Str::toEnglishDigits(trim($volumeInput));

        if ($volume <= 0 || $volume > 10000) {
            $this->bot->sendMessage($chatId, '⚠️ حجم نامعتبر است. عددی بین ۱ تا ۱۰۰۰۰ بفرستید.');
            return true;
        }

        $needed = Str::gbToBytes($volume);
        if ($needed > (int) $user['user_credit']) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '❌ اعتبار کافی ندارید.',
                '',
                '💾 نیاز: <b>' . Str::formatBytes($needed) . '</b>',
                '💰 موجودی: <b>' . Str::formatBytes((int) $user['user_credit']) . '</b>',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🛒 خرید اعتبار', 'data' => BotApi::encodeData('shop', ['kind' => 'user_credit'])]],
                    Keyboard::back('user.credit'),
                ])),
            ]);
            return true;
        }

        $this->bot->sendMessage($chatId, "مدت زمان را به روز بفرستید.\n\nمثال: <code>۳۰</code>", [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::back('user.credit', '❌ انصراف')),
        ]);

        return true;
    }

    /**
     * پردازش مرحلهٔ مدت زمان و اجرای عملیات.
     *
     * @param  array<string, mixed> $user
     * @return bool
     */
    public function handleDuration(int $chatId, array $user, string $mode, string $username, float $volume, string $daysInput): bool
    {
        $days = (int) Str::toEnglishDigits(trim($daysInput));

        if ($days <= 0 || $days > 3650) {
            $this->bot->sendMessage($chatId, '⚠️ مدت زمان نامعتبر است. عددی بین ۱ تا ۳۶۵۰ بفرستید.');
            return true;
        }

        $fresh = $this->users->findById((int) $user['id']) ?? $user;

        $result = $mode === 'extend'
            ? $this->provisioner->extendUser($fresh, $username, $volume, $days)
            : $this->provisioner->createUser($fresh, $username, $volume, $days);

        if ($result['ok']) {
            $updated = $this->users->findById((int) $user['id']) ?? $fresh;

            $lines = [
                '✅ ' . $result['message'],
                '',
                '💾 حجم: <b>' . Str::faNumber($volume, 1) . ' گیگابایت</b>',
                '📅 اعتبار: <b>' . Str::faNumber($days) . ' روز</b>',
                '💰 اعتبار باقی‌مانده: <b>' . Str::formatBytes((int) $updated['user_credit']) . '</b>',
            ];

            if (!empty($result['data']['changes'])) {
                $lines[] = '';
                foreach ((array) $result['data']['changes'] as $change) {
                    $lines[] = '🔄 ' . Str::escape((string) $change);
                }
            }

            $this->bot->sendMessage($chatId, implode("\n", $lines), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [
                        ['text' => '➕ ساخت کاربر دیگر', 'data' => BotApi::encodeData('uc.new')],
                        ['text' => '🔄 تمدید', 'data' => BotApi::encodeData('uc.extend')],
                    ],
                    [['text' => '📋 لیست کاربران', 'data' => BotApi::encodeData('uc.list')]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return true;
        }

        $this->bot->sendMessage($chatId, '❌ ' . Str::escape((string) $result['message']), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [
                    ['text' => '➕ ساخت کاربر جدید', 'data' => BotApi::encodeData('uc.new')],
                    ['text' => '🔄 تمدید کاربر', 'data' => BotApi::encodeData('uc.extend')],
                ],
                Keyboard::back('user.credit'),
            ])),
        ]);

        return true;
    }

    /**
     * نمایش لیست کاربران پنل.
     *
     * @param  array<string, mixed> $user
     */
    public function showUsers(int $chatId, array $user, string $search = ''): void
    {
        $result = $this->provisioner->listUsers($user, $search);

        if (!$result['ok']) {
            $this->bot->sendMessage($chatId, '❌ ' . Str::escape((string) $result['message']));
            return;
        }

        $users = (array) ($result['users'] ?? []);

        if ($users === []) {
            $this->bot->sendMessage($chatId, '📭 کاربری یافت نشد.', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('user.credit')])),
            ]);
            return;
        }

        $lines = ['📋 <b>کاربران پنل</b>', ''];

        foreach (array_slice($users, 0, 15) as $panelUser) {
            $status = (string) ($panelUser['status'] ?? '');
            $icon = match ($status) {
                'active'  => '🟢',
                'limited' => '🟡',
                'expired' => '⏳',
                'disabled'=> '⛔️',
                default   => '⚪️',
            };

            $lines[] = $icon . ' <code>' . Str::escape((string) ($panelUser['username'] ?? '—')) . '</code>';
            $lines[] = '   حجم: ' . Str::formatBytes((int) ($panelUser['data_limit'] ?? 0))
                . ' • مصرف: ' . Str::formatBytes((int) ($panelUser['used_traffic'] ?? 0));
            $lines[] = '   انقضا: ' . Str::date((int) ($panelUser['expire'] ?? 0));
            $lines[] = '';
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [
                    ['text' => '➕ ساخت کاربر', 'data' => BotApi::encodeData('uc.new')],
                    ['text' => '🔄 تمدید', 'data' => BotApi::encodeData('uc.extend')],
                ],
                Keyboard::back('user.credit'),
            ])),
        ]);
    }
}