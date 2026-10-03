<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Config;

/**
 * دروازهٔ وبهوک ربات مدیریتیِ جدا.
 *
 * چرا یک کلاس جدا و نه چند خط داخل `admin.php`؟
 *   چون این دقیقاً همان‌جایی است که یک باگ = یک رخنهٔ امنیتی است، و منطقِ
 *   امنیتیِ بدون تست یعنی «امیدوارم درست نوشته باشم». اینجا سه تصمیم حیاتی
 *   جدا شده و هر کدام قابل آزمودن است:
 *
 *     ۱) ربات دوم پیکربندی شده است؟        (نه ⇒ ۵۰۳)
 *     ۲) توکن امنیتی معتبر است؟            (نه یا نمونه ⇒ ۵۰۳، غلط ⇒ ۴۰۳)
 *     ۳) فرستنده سوپرادمین است؟            (نه ⇒ بی‌صدا نادیده گرفته)
 *
 * مورد ۳ عمداً «نادیده گرفته می‌شود» و پیام خطا نمی‌گیرد: اگر به هر کسی که به
 * ربات پیام داد پاسخ می‌دادیم، ربات دوم تبدیل به اسپم‌بان می‌شد.
 */
final class AdminWebhook
{
    /** مقدار نمونه‌ای که یعنی «تنظیم نشده» و باید رد شود */
    public const PLACEHOLDER_SECRET = 'CHANGE-THIS-RANDOM-SECRET';

    /**
     * آیا ربات مدیریتی در کانفیگ وجود دارد؟
     */
    public static function isConfigured(): bool
    {
        return trim(Config::str('admin_bot_token', '')) !== '';
    }

    /**
     * وضعیت پیکربندی وبهوک مدیریتی.
     *
     * @return array{ready:bool, status:int, error:string}
     */
    public static function status(): array
    {
        if (!self::isConfigured()) {
            return [
                'ready'  => false,
                'status' => 503,
                'error'  => 'admin_bot_token is not configured',
            ];
        }

        if (!self::hasValidSecret()) {
            return [
                'ready'  => false,
                'status' => 503,
                'error'  => 'admin_webhook_secret is not configured',
            ];
        }

        return ['ready' => true, 'status' => 200, 'error' => ''];
    }

    /**
     * آیا توکن امنیتی واقعاً تنظیم شده است (نه خالی، نه مقدار نمونه)؟
     */
    public static function hasValidSecret(): bool
    {
        $secret = trim(Config::str('admin_webhook_secret', ''));

        return $secret !== '' && $secret !== self::PLACEHOLDER_SECRET;
    }

    /**
     * توکن امنیتی درخواست درست است؟
     *
     * `hash_equals` مهم است: مقایسهٔ سادهٔ رشته‌ها در برابر حملهٔ زمان‌سنجی
     * قابل اتکا نیست، و اینجا دقیقاً جایی است که آن حمله معنادار است.
     */
    public static function secretMatches(?string $provided): bool
    {
        if (!self::hasValidSecret()) {
            return false;
        }

        return hash_equals(trim(Config::str('admin_webhook_secret', '')), (string) $provided);
    }

    /**
     * آیا این کاربر اجازهٔ استفاده از ربات مدیریتی را دارد؟
     *
     * فقط `super_admins` کانفیگ — نه چیز دیگری. عمداً دیتابیس خوانده نمی‌شود
     * تا کاربر عادی حتی ردیف در دیتابیس هم نسازد.
     */
    public static function allows(int $telegramId): bool
    {
        if ($telegramId <= 0) {
            return false;
        }

        foreach (Config::arr('super_admins') as $id) {
            if ((int) $id === $telegramId) {
                return true;
            }
        }

        return false;
    }
}