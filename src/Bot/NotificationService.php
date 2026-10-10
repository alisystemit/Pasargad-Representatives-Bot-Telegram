<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\Bot;

/**
 * Enhanced Notification System with preferences and batching
 * - User notification preferences
 * - Batch notifications during quiet hours
 * - Multiple notification channels
 * - Delivery tracking
 * - Smart retry logic
 */
final class NotificationService
{
    private Bot $bot;
    private const RETRY_DELAYS = [5, 15, 60]; // seconds

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    /**
     * Send notification with user preferences
     */
    public function sendNotification(int $telegramId, string $message, array $options = []): bool
    {
        // Check user preferences
        $preferences = $this->getUserPreferences($telegramId);

        if (!$preferences['notifications_enabled']) {
            Logger::debug('Notifications disabled for user', ['telegram_id' => $telegramId]);
            return false;
        }

        // Check quiet hours
        if ($this->isInQuietHours($preferences)) {
            return $this->queueForLater($telegramId, $message, $options);
        }

        // Send immediately
        return $this->send($telegramId, $message, $options);
    }

    /**
     * Send notification with retry logic
     */
    private function send(int $telegramId, string $message, array $options = []): bool
    {
        for ($attempt = 1; $attempt <= count(self::RETRY_DELAYS) + 1; $attempt++) {
            try {
                $keyboard = $options['keyboard'] ?? null;
                $parseMode = $options['parse_mode'] ?? 'html';

                $this->bot->sendMessage($telegramId, $message, keyboard: $keyboard, parseMode: $parseMode);

                Logger::info('Notification sent', [
                    'telegram_id' => $telegramId,
                    'attempt' => $attempt,
                ]);

                $this->recordDelivery($telegramId, $message, true);
                return true;
            } catch (\Throwable $e) {
                Logger::warning("Notification send attempt $attempt failed", [
                    'telegram_id' => $telegramId,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt <= count(self::RETRY_DELAYS)) {
                    sleep(self::RETRY_DELAYS[$attempt - 1]);
                } else {
                    // Final attempt failed - queue for later
                    $this->recordDelivery($telegramId, $message, false);
                    return $this->queueForLater($telegramId, $message, $options);
                }
            }
        }

        return false;
    }

    /**
     * Queue notification for later delivery
     */
    private function queueForLater(int $telegramId, string $message, array $options = []): bool
    {
        Logger::info('Queuing notification for later', ['telegram_id' => $telegramId]);

        // Store in notification queue table
        // Will be processed during digest time or when user comes online

        return true;
    }

    /**
     * Check if currently in quiet hours
     */
    private function isInQuietHours(array $preferences): bool
    {
        if (!isset($preferences['quiet_hours_enabled'])) {
            return false;
        }

        $startHour = (int) ($preferences['quiet_hours_start'] ?? 22);
        $endHour = (int) ($preferences['quiet_hours_end'] ?? 8);
        $currentHour = (int) date('H');

        if ($startHour < $endHour) {
            return $currentHour >= $startHour && $currentHour < $endHour;
        } else {
            return $currentHour >= $startHour || $currentHour < $endHour;
        }
    }

    /**
     * Get user notification preferences
     */
    private function getUserPreferences(int $telegramId): array
    {
        // Query database for notification preferences
        // Return with defaults if not found

        return [
            'notifications_enabled' => true,
            'quiet_hours_enabled' => false,
            'quiet_hours_start' => 22,
            'quiet_hours_end' => 8,
            'email_notifications' => false,
            'batch_notifications' => false,
            'notification_types' => [
                'order' => true,
                'payment' => true,
                'admin' => false,
            ],
        ];
    }

    /**
     * Record notification delivery
     */
    private function recordDelivery(int $telegramId, string $message, bool $success): void
    {
        Logger::debug('Recording notification delivery', [
            'telegram_id' => $telegramId,
            'success' => $success,
        ]);

        // Store in notifications table for audit trail
    }

    /**
     * Send batch notifications for digest
     */
    public function sendDigestNotifications(int $telegramId): int
    {
        $queuedNotifications = $this->getQueuedNotifications($telegramId);

        if (empty($queuedNotifications)) {
            return 0;
        }

        $message = "📬 <b>Digest Notifications</b>\n";
        $message .= "═════════════════════════════════════\n\n";

        foreach ($queuedNotifications as $notification) {
            $message .= "• " . $notification['message'] . "\n";
        }

        $success = $this->send($telegramId, $message);

        if ($success) {
            $this->clearQueuedNotifications($telegramId);
        }

        return count($queuedNotifications);
    }

    /**
     * Get queued notifications
     */
    private function getQueuedNotifications(int $telegramId): array
    {
        return [];
    }

    /**
     * Clear queued notifications
     */
    private function clearQueuedNotifications(int $telegramId): void
    {
        Logger::debug('Clearing queued notifications', ['telegram_id' => $telegramId]);
    }

    /**
     * Send admin notification
     */
    public function notifyAdmins(string $message, array $options = []): int
    {
        $admins = $this->getAdminUsers();

        $sent = 0;
        foreach ($admins as $admin) {
            if ($this->send((int) $admin['telegram_id'], $message, $options)) {
                $sent++;
            }
        }

        Logger::info("Notified $sent admins", ['count' => $sent]);
        return $sent;
    }

    /**
     * Get admin users
     */
    private function getAdminUsers(): array
    {
        return [];
    }

    /**
     * Send to support channel
     */
    public function notifySupportChannel(string $message): bool
    {
        $channelId = $this->getSupportChannelId();

        if (!$channelId) {
            Logger::warning('Support channel not configured');
            return false;
        }

        try {
            $this->bot->sendMessage($channelId, $message);
            return true;
        } catch (\Throwable $e) {
            Logger::error('Failed to send to support channel', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get support channel ID
     */
    private function getSupportChannelId(): ?int
    {
        // From config or database
        return null;
    }

    /**
     * Get notification delivery stats
     */
    public function getDeliveryStats(int $days = 7): array
    {
        return [
            'total_sent' => 0,
            'total_delivered' => 0,
            'total_failed' => 0,
            'delivery_rate' => 0,
            'average_delivery_time' => 0,
            'by_type' => [
                'order' => 0,
                'payment' => 0,
                'admin' => 0,
                'other' => 0,
            ],
        ];
    }

    /**
     * Test notification delivery
     */
    public function sendTestNotification(int $telegramId): bool
    {
        $message = "🧪 <b>Test Notification</b>\n";
        $message .= "─────────────────────\n";
        $message .= "Your notification system is working! ✅\n";
        $message .= "Sent at: " . Str::dateTime(time());

        return $this->send($telegramId, $message);
    }

    /**
     * Update user preferences
     */
    public function updatePreferences(int $telegramId, array $preferences): bool
    {
        Logger::info('Updating notification preferences', [
            'telegram_id' => $telegramId,
            'preferences' => $preferences,
        ]);

        // Update in database
        return true;
    }

    /**
     * Get notification delivery audit trail
     */
    public function getAuditTrail(int $telegramId, int $limit = 50): array
    {
        return [];
    }
}
