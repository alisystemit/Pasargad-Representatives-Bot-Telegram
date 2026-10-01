<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;

/**
 * ارسال اعلان به کاربران و سوپرADMین‌ها.
 */
final class Notifier
{
    private BotApi $bot;
    private float $lastSendAt = 0.0;

    public function __construct(?BotApi $bot = null)
    {
        $this->bot = $bot ?? new BotApi();
    }

    public function bot(): BotApi
    {
        return $this->bot;
    }

    /**
     * @return array<int, int> فهرست سوپرادمین‌های تنظیم‌شده
     */
    public function adminIds(): array
    {
        return array_values(array_map('intval', Config::arr('super_admins')));
    }

    public function isAdmin(int $userId): bool
    {
        return in_array($userId, $this->adminIds(), true);
    }

    /**
     * ارسال پیام به یک کاربر بر اساس شناسهٔ داخلی ربات.
     */
    public function notifyUser(int $userId, string $text, array $keyboard = []): bool
    {
        $telegramId = (int) $userId;

        // محدودیت نرخ: حداقل نیم‌ثانیه فاصله بین پیام‌ها
        $now = microtime(true);
        if ($now - $this->lastSendAt < 0.4) {
            usleep((int) ((0.4 - ($now - $this->lastSendAt)) * 1_000_000));
        }
        $this->lastSendAt = microtime(true);

        $result = $this->bot->sendMessage($telegramId, $text, [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);

        return (bool) ($result['ok'] ?? false);
    }

    /**
     * ارسال به همهٔ سوپرادمین‌ها.
     *
     * @param array<string, mixed>|null $button یک دکمهٔ اختیاری زیر پیام
     */
    public function notifyAdmins(string $text, ?array $button = null): void
    {
        $keyboard = $button !== null ? [[$button]] : [];

        foreach ($this->adminIds() as $adminId) {
            $result = $this->bot->sendMessage($adminId, $text, [
                'reply_markup' => $this->bot->buildMarkup($keyboard),
            ]);

            if (!($result['ok'] ?? false)) {
                Logger::warning('Failed to notify admin', [
                    'admin_id' => $adminId,
                    'error'    => $result['description'] ?? 'unknown',
                ]);
            }
        }

        // گروه/کانال ادمین (اختیاری)
        $adminChat = Config::int('notifications.admin_chat');
        if ($adminChat > 0) {
            $this->bot->sendMessage($adminChat, $text, [
                'reply_markup' => $this->bot->buildMarkup($keyboard),
            ]);
        }
    }

    /**
     * ارسال رسید (عکس) به سوپرادمین‌ها برای تأیید.
     *
     * @param array<string, mixed>|null $button
     */
    /**
     * ارسال رسید (عکس) به سوپرADMین‌ها برای تأیید.
     *
     * @param array<string, mixed>|null $button
     */
    public function notifyAdminsWithPhoto(string $fileId, string $caption, ?array $button = null): void
    {
        $keyboard = $button !== null ? [[$button]] : [];

        if ($this->adminIds() === []) {
            return;
        }

        $result = $this->bot->sendPhoto(
            $this->adminIds()[0],
            $fileId,
            $caption,
            $keyboard
        );

        if (!($result['ok'] ?? false)) {
            Logger::warning('Failed to send receipt to admin', [
                'admin_id' => $this->adminIds()[0],
                'error'    => $result['description'] ?? 'unknown',
            ]);

            // اگر ارسال عکس شکست خورد، پیام متنی بفرست تا دست‌کم اطلاعات برسد.
            $this->notifyAdmins($caption . "\n\n(تصویر رسید ارسال نشد)", $button);
        }
    }

    /**
     * ارسال عکس با کپشن.
     *
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     */
    public function sendPhoto(int $chatId, string $fileId, string $caption = '', array $keyboard = []): bool
    {
        $params = ['chat_id' => $chatId, 'photo' => $fileId];

        if ($caption !== '') {
            $params['caption'] = Str::truncate($caption, 1024);
            $params['parse_mode'] = 'HTML';
        }

        $markup = $this->bot->buildMarkup($keyboard);
        if ($markup !== null) {
            $params['reply_markup'] = json_encode($markup);
        }

        $result = $this->bot->call('sendPhoto', $params);

        return (bool) ($result['ok'] ?? false);
    }

    /**
     * ارسال پیام همگانی (با احترام به محدودیت نرخ تلگرام).
     *
     * @param array<int, int> $userIds
     * @return array{sent:int, failed:int}
     */
    public function broadcast(array $userIds, string $text, int $delayMs = 50): array
    {
        $sent   = 0;
        $failed = 0;

        foreach ($userIds as $userId) {
            $result = $this->bot->sendMessage((int) $userId, $text);

            if ($result['ok'] ?? false) {
                $sent++;
            } else {
                $failed++;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}