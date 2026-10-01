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

        // نکتهٔ حیاتی: عکس باید به **همهٔ** ادمین‌ها برسد، نه فقط اولی.
        //
        // دلیل: خودِ عکس مدرکی است که برای تأیید لازم است. اگر فقط به ادمین اول
        // برسد و او ربات را بلاک کرده باشد یا آیدی‌اش stale باشد، هیچ‌کس دیگری
        // نمی‌تواند سفارش را بررسی کند — در حالی که متن رسید به همه می‌رسد.
        // نتیجه: سفارش برای همیشه در انتظار می‌ماند در حالی که کاربر پول داده.
        $targets = $this->adminIds();
        $chat    = Config::int('notifications.admin_chat');

        if ($chat > 0) {
            $targets[] = $chat;
        }

        if ($targets === []) {
            Logger::warning('No admin targets configured for receipt notification');

            return;
        }

        $delivered = 0;

        foreach ($targets as $adminId) {
            $result = $this->bot->sendPhoto($adminId, $fileId, $caption, $keyboard);

            if ($result['ok'] ?? false) {
                $delivered++;
                continue;
            }

            Logger::warning('Failed to send receipt photo to admin', [
                'admin_id' => $adminId,
                'error'    => $result['description'] ?? 'unknown',
            ]);

            // برای این مقصد، پیام متنی بفرست تا دست‌کم اطلاعات سفارش برسد.
            $this->bot->sendMessage($adminId, $caption . "\n\n(تصویر رسید ارسال نشد)", [
                'reply_markup' => $this->bot->buildMarkup($keyboard),
            ]);
        }

        if ($delivered === 0) {
            Logger::error('Receipt photo reached nobody — order cannot be reviewed', [
                'targets' => count($targets),
            ]);
        }
    }

    /**
     * ارسال عکس با کپشن (delegates به BotApi که فرمت HTML و
     * fallback بدون فرمت را مدیریت می‌کند).
     *
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     */
    public function sendPhoto(int $chatId, string $fileId, string $caption = '', array $keyboard = []): bool
    {
        $result = $this->bot->sendPhoto($chatId, $fileId, $caption, $keyboard);

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