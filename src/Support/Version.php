<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * مُهر نسخهٔ کدِ در حال اجرا.
 *
 * چرا این کلاس وجود دارد؟
 *
 * یک بار آپلود ناقص داشتیم: `Api.php` جدید بود ولی `Settings.php` و
 * `Migrator.php` قدیمی. نتیجه دو خطای کاملاً گمراه‌کننده بود:
 *
 *   • «Undefined constant Settings::REFERRAL_BONUS_LEVEL2»
 *   • «Database schema outdated … expected 010_user_limit»
 *     در حالی که کد محلی ۱۲ مهاجرت داشت.
 *
 * هر دو فقط یک معنی داشتند: فایل‌های روی سرور از یک نسخهٔ یکسان نیستند.
 *
 * این کلاس همان را **آشکار** می‌کند: با هر درخواست یک مُهر کوتاه در لاگ
 * می‌نشیند، پس با یک نگاه معلوم می‌شود کدام نسخه واقعاً روی سرور است.
 *
 * ⚠️ `STAMP` را بعد از هر استقرار دستی به‌روز کنید. لازم نیست دقیق باشد؛ فقط
 * باید با هر آپلودِ واقعی عوض شود.
 */
final class Version
{
    /**
     * مُهر استقرار.
     *
     * قالب پیشنهادی: `<تاریخ>-<۶ کاراکتر اول هش کامیت>`.
     */
    public const STAMP = '2026-10-11-journey-r4';

    /** آیا این پروسه مُهر را چاپ کرده است؟ */
    private static bool $stamped = false;

    /**
     * مُهر کوتاه برای لاگ/پاسخ (۸ کاراکتر از هش STAMP).
     */
    public static function short(): string
    {
        return substr(hash('sha256', self::STAMP), 0, 8);
    }

    /**
     * چاپ مُهر یک‌بار در هر پروسه.
     *
     * عمداً «یک‌بار در هر پروسه» است: وبهوک هر درخواست یک پروسهٔ تازه PHP است،
     * پس عملاً هر درخواست یک خط می‌نویسد — که همان چیزی است که برای تشخیص
     * آپلود ناقص لازم داریم.
     */
    public static function stampOnce(): void
    {
        if (self::$stamped) {
            return;
        }

        self::$stamped = true;

        Logger::debug('runtime.version', [
            'stamp' => self::STAMP,
            'short' => self::short(),
            'php'   => PHP_VERSION,
            'sapi'  => PHP_SAPI,
        ]);
    }
}