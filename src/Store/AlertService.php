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
     * @return array{checked:int, low_volume:int, low_credit:int, expiring:int, credit_expiring:int}
     */
    public function runAll(): array
    {
        $result = ['checked' => 0, 'low_volume' => 0, 'low_credit' => 0, 'expiring' => 0, 'credit_expiring' => 0];

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

            if ($this->checkCreditExpiring($user)) {
                $result['credit_expiring']++;
            }
        }

        $total = $result['low_volume'] + $result['low_credit'] + $result['expiring'] + $result['credit_expiring'];

        if ($total > 0) {
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

        return $this->sendAndFlag(
            (int) $user['telegram_id'],
            implode("\n", [
                '⚠️ <b>هشدار حجم پنل</b>',
                '',
                '💾 سقف حجم: <b>' . Str::formatBytes($limit) . '</b>',
                '📥 مصرف: <b>' . Str::formatBytes($used) . '</b>',
                '📊 باقی‌مانده: <b>' . Str::faNumber(max(0, $remainingPercent), 1) . '٪</b>',
                '',
                'برای ادامه سرویس، بستهٔ جدید بخرید. 🛒',
            ]),
            $key,
            (int) ($user['panel_data_limit'] ?? 0),
            [[
                ['text' => '🛒 خرید بسته', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_PANEL_QUOTA])],
            ]]
        );
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

            return $this->sendAndFlag(
                (int) $user['telegram_id'],
                implode("\n", [
                    '⚠️ <b>اعتبار کاربر شما تمام شده است</b>',
                    '',
                    'برای ساخت کاربران جدید باید اعتبار بیشتری خریداری کنید.',
                ]),
                $key,
                0,
                [[
                    ['text' => '🛒 خرید اعتبار', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_USER_CREDIT])],
                ]]
            );
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

        return $this->sendAndFlag(
            (int) $user['telegram_id'],
            implode("\n", [
                '⏳ <b>یادآوری اعتبار</b>',
                '',
                $message,
                '📅 تاریخ انقضا: ' . Str::date((int) $expireAt),
                '',
                'برای تمدید، بستهٔ جدید بخرید. 🛒',
            ]),
            $key,
            0,
            [[
                ['text' => '🛒 تمدید بسته', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_PANEL_QUOTA])],
            ]]
        );
    }

    /**
     * هشدار نزدیک شدن به پایان اعتبار ساخت کاربر (کاربر قبلاً بابت آن پول داده).
     *
     * این با هشدار حجم متفاوت است: آن یکی دربارهٔ سقف پنل است، این یکی دربارهٔ
     * اعتباری که با آن کاربر می‌سازد.
     *
     * @param array<string, mixed> $user
     */
    public function checkCreditExpiring(array $user): bool
    {
        $expireAt = $user['user_credit_expire'] ?? null;

        if ($expireAt === null || (int) $user['user_credit'] <= 0) {
            return false;
        }

        $daysLeft = (int) ceil(((int) $expireAt - time()) / 86400);

        if ($daysLeft > 3 || $daysLeft < 0) {
            return false;
        }

        $key = $this->flagKey((int) $user['id'], 'credit_expiring_' . $daysLeft);
        if ($this->alreadySent($key, 0)) {
            return false;
        }

        $message = $daysLeft > 0
            ? 'اعتبار ساخت کاربر شما <b>' . Str::faNumber($daysLeft) . ' روز</b> دیگر تمام می‌شود.'
            : 'اعتبار ساخت کاربر شما به پایان رسیده است.';

        return $this->sendAndFlag(
            (int) $user['telegram_id'],
            implode("\n", [
                '⏳ <b>یادآوری اعتبار کاربر</b>',
                '',
                $message,
                '📅 تاریخ انقضا: ' . Str::date((int) $expireAt),
                '',
                'برای ساخت کاربر جدید، اعتبار تازه بخرید. 🛒',
            ]),
            $key,
            0,
            [[
                ['text' => '🛒 خرید اعتبار', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_USER_CREDIT])],
            ]]
        );
    }

    /**
     * ارسال پیام و برگرداندن موفقیت آن.
     *
     * نکتهٔ مهم: اگر ارسال شکست بخورد نباید کلید «ارسال شد» ثبت شود، وگرنه یک
     * خطای موقت تلگرام (۴۲۹ یا تایم‌اوت) باعث می‌شود کاربر تا ابد بی‌خبر بماند.
     *
     * @param  array<int, array<int, array<string, mixed>>> $keyboard
     * @return bool
     */
    private function send(int $telegramId, string $text, array $keyboard = []): bool
    {
        if ($this->bot === null) {
            try {
                $this->bot = new BotApi();
            } catch (\Throwable $e) {
                Logger::warning('Bot API not available for alerts', ['error' => $e->getMessage()]);
                return false;
            }
        }

        $result = $this->bot->sendMessage($telegramId, $text, [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);

        if (!($result['ok'] ?? false)) {
            Logger::warning('Alert delivery failed', [
                'user_id' => $telegramId,
                'error'   => $result['description'] ?? 'unknown',
            ]);

            return false;
        }

        return true;
    }

    /**
     * ارسال هشدار و علامت‌گذاری «ارسال شد» فقط در صورت موفقیت.
     *
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     */
    private function sendAndFlag(int $telegramId, string $text, string $flagKey, int $flagValue, array $keyboard = []): bool
    {
        if (!$this->send($telegramId, $text, $keyboard)) {
            // ارسال ناموفق: دفعهٔ بعد دوباره تلاش می‌شود.
            return false;
        }

        $this->markSent($flagKey, $flagValue);

        return true;
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