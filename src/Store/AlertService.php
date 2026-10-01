<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Telegram\BotApi;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * اعلان‌های خودکار به کاربران.
 *
 * هشدارها در جدول settings علامت‌گذاری می‌شوند تا هر کاربر فقط یک‌بار
 * برای هر آستانه مطلع شود (بدون نیاز به جدول جداگانه).
 */
final class AlertService
{
    private UserRepository $users;
    private Settings $settings;
    private ?BotApi $bot = null;

    public function __construct(UserRepository $users, ?Settings $settings = null, ?BotApi $bot = null)
    {
        $this->users    = $users;
        $this->settings = $settings ?? new Settings();
        $this->bot      = $bot;
    }

    /**
     * بررسی همهٔ کاربران متصل و ارسال هشدارهای لازم.
     *
     * @return array{checked:int, low_volume:int, low_credit:int, expiring:int}
     */
    public function runAll(): array
    {
        $result = ['checked' => 0, 'low_volume' => 0, 'low_credit' => 0, 'expiring' => 0];

        foreach ($this->users->listLinkedAdmins() as $user) {
            $result['checked']++;

            if ($this->checkLowVolume($user)) {
                $result['low_volume']++;
            }

            if ($this->checkLowCredit($user)) {
                $result['low_credit']++;
            }

            if ($this->checkExpiring($user)) {
                $result['expiring']++;
            }
        }

        if ($result['low_volume'] + $result['low_credit'] + $result['expiring'] > 0) {
            Logger::info('Alerts dispatched', $result);
        }

        return $result;
    }

    /**
     * هشدار نزدیک شدن حجم پنل به سقف.
     *
     * @param array<string, mixed> $user
     */
    public function checkLowVolume(array $user): bool
    {
        $limit = (int) ($user['panel_data_limit'] ?? 0);
        $used  = (int) ($user['panel_used'] ?? 0);

        if ($limit <= 0 || $used <= 0) {
            return false;   // نامحدود یا بدون مصرف
        }

        $threshold = max(1, $this->settings->int(Settings::LOW_VOLUME_ALERT, 5));
        $remainingPercent = (($limit - $used) / $limit) * 100;

        if ($remainingPercent > $threshold) {
            return false;
        }

        $key = $this->flagKey((int) $user['id'], 'low_volume');
        if ($this->alreadySent($key, (int) ($user['panel_data_limit'] ?? 0))) {
            return false;
        }

        $this->send((int) $user['telegram_id'], implode("\n", [
            '⚠️ <b>هشدار حجم پنل</b>',
            '',
            '💾 سقف حجم: <b>' . Str::formatBytes($limit) . '</b>',
            '📥 مصرف: <b>' . Str::formatBytes($used) . '</b>',
            '📊 باقی‌مانده: <b>' . Str::faNumber(max(0, $remainingPercent), 1) . '٪</b>',
            '',
            'برای ادامه سرویس، بستهٔ جدید بخرید. 🛒',
        ]), [[
            ['text' => '🛒 خرید بسته', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_PANEL_QUOTA])],
        ]]);

        $this->markSent($key, (int) ($user['panel_data_limit'] ?? 0));

        return true;
    }

    /**
     * هشدار کمبود اعتبار ساخت کاربر.
     *
     * @param array<string, mixed> $user
     */
    public function checkLowCredit(array $user): bool
    {
        $credit = (int) ($user['user_credit'] ?? 0);

        if ($credit <= 0) {
            $key = $this->flagKey((int) $user['id'], 'no_credit');
            if ($this->alreadySent($key, 0)) {
                return false;
            }

            $this->send((int) $user['telegram_id'], implode("\n", [
                '⚠️ <b>اعتبار کاربر شما تمام شده است</b>',
                '',
                'برای ساخت کاربران جدید باید اعتبار بیشتری خریداری کنید.',
            ]), [[
                ['text' => '🛒 خرید اعتبار', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_USER_CREDIT])],
            ]]);

            $this->markSent($key, 0);

            return true;
        }

        return false;
    }

    /**
     * هشدار نزدیک شدن به پایان اعتبار حجم خریداری‌شده.
     *
     * @param array<string, mixed> $user
     */
    public function checkExpiring(array $user): bool
    {
        $expireAt = $user['granted_expire_at'] ?? null;

        if ($expireAt === null) {
            return false;
        }

        $daysLeft = (int) ceil(((int) $expireAt - time()) / 86400);

        if ($daysLeft > 3 || $daysLeft < 0) {
            return false;
        }

        $key = $this->flagKey((int) $user['id'], 'expiring_' . $daysLeft);
        if ($this->alreadySent($key, 0)) {
            return false;
        }

        $message = $daysLeft > 0
            ? '⏳ اعتبار حجم خریداری‌شدهٔ شما <b>' . Str::faNumber($daysLeft) . ' روز</b> دیگر تمام می‌شود.'
            : '⌛️ اعتبار حجم خریداری‌شدهٔ شما به پایان رسیده است.';

        $this->send((int) $user['telegram_id'], implode("\n", [
            '⏳ <b>یادآوری اعتبار</b>',
            '',
            $message,
            '📅 تاریخ انقضا: ' . Str::date((int) $expireAt),
            '',
            'برای تمدید، بستهٔ جدید بخرید. 🛒',
        ]), [[
            ['text' => '🛒 تمدید بسته', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_PANEL_QUOTA])],
        ]]);

        $this->markSent($key, 0);

        return true;
    }

    /**
     * ارسال پیام (اگر کلاینت تلگرام در دسترس باشد).
     *
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     */
    private function send(int $telegramId, string $text, array $keyboard = []): void
    {
        if ($this->bot === null) {
            try {
                $this->bot = new BotApi();
            } catch (\Throwable $e) {
                Logger::warning('Bot API not available for alerts', ['error' => $e->getMessage()]);
                return;
            }
        }

        $this->bot->sendMessage($telegramId, $text, [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * آیا این هشدار قبلاً با همین مقدار ارسال شده است؟
     *
     * کلید ذخیره‌شده شامل «مقدار» است تا با تغییر سقف حجم، هشدار دوباره ارسال شود.
     */
    private function alreadySent(string $key, int $value): bool
    {
        return $this->settings->get($key) === (string) $value;
    }

    private function markSent(string $key, int $value): void
    {
        $this->settings->set($key, (string) $value);
    }

    private function flagKey(int $userId, string $topic): string
    {
        return 'alert:' . $userId . ':' . $topic;
    }
}