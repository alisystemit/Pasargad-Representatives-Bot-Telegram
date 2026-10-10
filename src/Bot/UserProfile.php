<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\UserRepository;
use Pasargad\Support\Crypto;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\Bot;

/**
 * User profile management
 * - View/edit profile
 * - Change password
 * - Language preferences
 * - Contact information
 */
final class UserProfile
{
    private Bot $bot;
    private UserRepository $users;

    public function __construct(Bot $bot, UserRepository $users)
    {
        $this->bot = $bot;
        $this->users = $users;
    }

    /**
     * Show profile menu
     */
    public function showMenu(int $chatId, int $telegramId): void
    {
        $user = $this->users->findByTelegramId($telegramId);
        if ($user === null) {
            $this->bot->sendMessage($chatId, '❌ کاربر یافت نشد.');
            return;
        }

        $name = Str::escape((string) ($user['first_name'] ?? 'کاربر'));
        $username = !empty($user['username']) ? '@' . Str::escape((string) $user['username']) : '—';
        $language = $this->getLanguageName((string) ($user['language'] ?? 'fa'));

        $message = MessageFormatter::info('👤 پروفایل شما', [
            'نام' => $name,
            'یوزرنیم' => $username,
            'زبان' => $language,
            'عضویت از' => Str::date((int) $user['created_at']),
        ]);

        $keyboard = [
            [['text' => '✏️ ویرایش نام', 'callback_data' => 'profile:edit_name']],
            [['text' => '🔐 تغییر رمز', 'callback_data' => 'profile:change_password']],
            [['text' => '🌐 زبان', 'callback_data' => 'profile:language']],
            [['text' => '📞 مشخصات تماس', 'callback_data' => 'profile:contact']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'main_menu']],
        ];

        $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
    }

    /**
     * Show edit name dialog
     */
    public function showEditName(int $chatId, int $telegramId): void
    {
        $user = $this->users->findByTelegramId($telegramId);
        if ($user === null) {
            return;
        }

        $currentName = Str::escape((string) ($user['first_name'] ?? ''));
        $message = MessageFormatter::inputRequest(
            'نام کامل',
            'نام شما برای نمایش در سیستم',
            'علی‌رضا محمدی'
        );

        $message .= "\n\n📌 نام فعلی: <code>$currentName</code>";

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Handle name change
     */
    public function handleNameChange(int $chatId, int $telegramId, string $newName): void
    {
        $newName = trim($newName);

        // Validation
        if (empty($newName)) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::validationError('نام', '⚠️ نام نمی‌تواند خالی باشد.')
            );
            return;
        }

        if (mb_strlen($newName) > 100) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::validationError('نام', '⚠️ نام نمی‌تواند بیش از ۱۰۰ کاراکتر باشد.')
            );
            return;
        }

        // Update
        try {
            $this->users->updateField((int) $this->users->findByTelegramId($telegramId)['id'], 'first_name', $newName);

            $message = MessageFormatter::success(
                'نام شما به‌روزرسانی شد! ✨',
                ['نام جدید' => Str::escape($newName)]
            );

            $this->bot->sendMessage($chatId, $message);

            // Show menu again
            $this->showMenu($chatId, $telegramId);
        } catch (\Throwable $e) {
            Logger::error('Failed to update user name', ['error' => $e->getMessage()]);
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::error('خطایی پیش آمد.')
            );
        }
    }

    /**
     * Show language selection
     */
    public function showLanguageSelection(int $chatId): void
    {
        $message = "🌐 <b>زبان را انتخاب کنید</b>\n\n";

        $keyboard = [
            [['text' => '🇮🇷 فارسی', 'callback_data' => 'profile:lang_fa']],
            [['text' => '🇬🇧 English', 'callback_data' => 'profile:lang_en']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'profile:menu']],
        ];

        $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
    }

    /**
     * Handle language change
     */
    public function handleLanguageChange(int $chatId, int $telegramId, string $lang): void
    {
        $validLangs = ['fa', 'en'];
        if (!in_array($lang, $validLangs, true)) {
            return;
        }

        try {
            $user = $this->users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            $this->users->updateField((int) $user['id'], 'language', $lang);

            $langName = $this->getLanguageName($lang);
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::success("زبان به $langName تغییر یافت! 🌐")
            );

            $this->showMenu($chatId, $telegramId);
        } catch (\Throwable $e) {
            Logger::error('Failed to update language', ['error' => $e->getMessage()]);
            $this->bot->sendMessage($chatId, MessageFormatter::error('خطایی پیش آمد.'));
        }
    }

    /**
     * Show password change dialog
     */
    public function showChangePassword(int $chatId, int $telegramId): void
    {
        $message = "🔐 <b>تغییر رمز عبور</b>\n";
        $message .= "─────────────────────\n\n";
        $message .= "برای تغییر رمز عبور:\n\n";
        $message .= "1️⃣ رمز عبور فعلی را وارد کنید\n";
        $message .= "2️⃣ رمز عبور جدید را تأیید کنید\n\n";
        $message .= "❌ برای لغو: /cancel";

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Show contact information editor
     */
    public function showContactEditor(int $chatId, int $telegramId): void
    {
        $user = $this->users->findByTelegramId($telegramId);
        if ($user === null) {
            return;
        }

        $email = !empty($user['email']) ? Str::escape((string) $user['email']) : '—';
        $phone = !empty($user['phone']) ? Str::escape((string) $user['phone']) : '—';

        $message = MessageFormatter::info('📞 مشخصات تماس', [
            'ایمیل' => $email,
            'تلفن' => $phone,
        ]);

        $keyboard = [
            [['text' => '✏️ ایمیل', 'callback_data' => 'profile:edit_email']],
            [['text' => '📱 تلفن', 'callback_data' => 'profile:edit_phone']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'profile:menu']],
        ];

        $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
    }

    /**
     * Show email edit dialog
     */
    public function showEditEmail(int $chatId): void
    {
        $message = MessageFormatter::inputRequest(
            'آدرس ایمیل',
            'برای دریافت اعلان‌های مهم',
            'user@example.com'
        );

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Handle email change
     */
    public function handleEmailChange(int $chatId, int $telegramId, string $email): void
    {
        $email = trim($email);

        // Validation
        $error = ErrorHandler::validateInput($email, 'email');
        if ($error !== null) {
            $this->bot->sendMessage($chatId, MessageFormatter::validationError('ایمیل', $error));
            return;
        }

        try {
            $user = $this->users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            $this->users->updateField((int) $user['id'], 'email', $email);

            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::success('ایمیل شما به‌روزرسانی شد! ✨')
            );

            $this->showContactEditor($chatId, $telegramId);
        } catch (\Throwable $e) {
            Logger::error('Failed to update email', ['error' => $e->getMessage()]);
            $this->bot->sendMessage($chatId, MessageFormatter::error('خطایی پیش آمد.'));
        }
    }

    /**
     * Show phone edit dialog
     */
    public function showEditPhone(int $chatId): void
    {
        $message = MessageFormatter::inputRequest(
            'شماره تلفن',
            'شماره تلفن همراه برای تماس سریع',
            '+98912345678'
        );

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Handle phone change
     */
    public function handlePhoneChange(int $chatId, int $telegramId, string $phone): void
    {
        $phone = trim($phone);

        // Validation
        if (empty($phone)) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::validationError('تلفن', '⚠️ شماره تلفن نمی‌تواند خالی باشد.')
            );
            return;
        }

        // Remove non-digits except + at start
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($cleaned) < 10) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::validationError('تلفن', '⚠️ شماره تلفن نامعتبر است.')
            );
            return;
        }

        try {
            $user = $this->users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            $this->users->updateField((int) $user['id'], 'phone', $cleaned);

            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::success(
                    'شماره تلفن شما به‌روزرسانی شد! ✨',
                    ['تلفن' => $cleaned]
                )
            );

            $this->showContactEditor($chatId, $telegramId);
        } catch (\Throwable $e) {
            Logger::error('Failed to update phone', ['error' => $e->getMessage()]);
            $this->bot->sendMessage($chatId, MessageFormatter::error('خطایی پیش آمد.'));
        }
    }

    /**
     * Get language name
     */
    private function getLanguageName(string $code): string
    {
        return match ($code) {
            'fa' => '🇮🇷 فارسی',
            'en' => '🇬🇧 English',
            default => 'نامعلوم',
        };
    }

    /**
     * Get user preferences summary
     */
    public function getPreferencesSummary(int $telegramId): string
    {
        $user = $this->users->findByTelegramId($telegramId);
        if ($user === null) {
            return '';
        }

        return MessageFormatter::info('⚙️ ترجیحات شما', [
            'زبان' => $this->getLanguageName((string) ($user['language'] ?? 'fa')),
            'اعلان‌ها' => ($user['notifications_enabled'] ?? 1) ? '✅ فعال' : '❌ غیرفعال',
            'دیجیتال وقت' => ($user['digest_emails'] ?? 0) ? '✅ فعال' : '❌ غیرفعال',
        ]);
    }
}
