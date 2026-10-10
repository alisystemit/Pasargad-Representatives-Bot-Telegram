<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * توابع کمکی: تولید شناسه، قالب‌بندی حجم/پول/تاریخ و اعتبارسنجی ورودی.
 */
final class Str
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const ARABIC_DIGITS  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /**
     * شناسهٔ یکتا و خوانا برای سفارش/رسید (مثلاً ORD-8F3K2Q).
     */
    public static function orderCode(string $prefix = 'ORD'): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $part = '';
        for ($i = 0; $i < 6; $i++) {
            $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix . '-' . $part;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(8, $bytes)));
    }

    /**
     * نگاشت ارقام فارسی/عربی به ارقام انگلیسی (طول هر دو رشته برابر است).
     *
     * @return array<string, string>
     */
    private static function digitMap(): array
    {
        static $map = null;

        if ($map === null) {
            $from   = array_merge(self::PERSIAN_DIGITS, self::ARABIC_DIGITS);
            $to     = array_merge(range('0', '9'), range('0', '9'));
            $map    = array_combine($from, $to);
        }

        return $map;
    }

    /**
     * تبدیل ارقام فارسی/عربی به انگلیسی (برای ورودی‌های عددی کاربر).
     */
    public static function toEnglishDigits(string $input): string
    {
        return strtr($input, self::digitMap());
    }

    /**
     * تبدیل ارقام انگلیسی به فارسی برای نمایش.
     */
    public static function toPersianDigits(string $input): string
    {
        static $map = null;

        if ($map === null) {
            $map = array_combine(range('0', '9'), self::PERSIAN_DIGITS);
        }

        return strtr($input, $map);
    }

    /**
     * تبدیل عدد به رشتهٔ فارسی با جداکنندهٔ هزارگان.
     *
     * جداکننده‌ها هم فارسی می‌شوند: هزارگان `٬` (U+066C) و اعشار `٫` (U+066B).
     *
     * چرا؟ چون عددی مثل `۱,۲۰۰,۰۰۰` یعنی «رقم فارسی با جداکنندهٔ
     * لاتین» — قاطی شدن دو خط نوشتاری در یک عدد. در تایپوگرافی فارسی،
     * جداکنندهٔ درست همان `٬` و `٫` است. این روی **همهٔ** عددهای ربات اثر
     * دارد (قیمت‌ها، حجم‌ها، شمارنده‌ها) و همه‌جا یکدست می‌شوند.
     */
    public static function faNumber(int|float $number, int $decimals = 0): string
    {
        $formatted = number_format((float) $number, $decimals, '.', ',');

        // جداکننده‌ها **قبل** از تبدیل رقم‌ها عوض می‌شوند، چون رقم‌های فارسی
        // خودشان شامل `٬`/`٫` نیستند و تبدیل رقم، جداکننده را دست‌نخورده
        // می‌گذارد.
        $formatted = strtr($formatted, ['.' => '٫', ',' => '٬']);

        return self::toPersianDigits($formatted);
    }

    /**
     * حجم بر حسب بایت به رشتهٔ خوانا (۱ GB = 1073741824 بایت).
     */
    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'نامحدود';
        }

        if ($bytes <= 0) {
            return '۰';
        }

        $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
        $index = (int) floor(log((float) $bytes, 1024));
        $index = max(0, min($index, count($units) - 1));

        $value = $bytes / (1024 ** $index);
        $decimals = $value >= 100 || $index <= 1 ? 0 : ($value >= 10 ? 1 : 2);

        return self::faNumber($value, $decimals) . ' ' . $units[$index];
    }

    /**
     * حجم بر حسب گیگابایت اعشاری → بایت.
     */
    public static function gbToBytes(float $gb): int
    {
        return (int) round($gb * 1073741824);
    }

    /**
     * حجم بر حسب بایت → گیگابایت اعشاری.
     */
    public static function bytesToGb(int $bytes): float
    {
        return round($bytes / 1073741824, 3);
    }

    public static function formatToman(int $amount): string
    {
        return self::faNumber($amount) . ' تومان';
    }

    /**
     * نمایش تاریخ میلادی با تاریخ شمسی در صورت وجود افزونهٔ intl.
     */
    public static function date(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '—';
        }

        $jalali = self::toJalali($timestamp);

        return $jalali ?? date('Y/m/d - H:i', $timestamp);
    }

    public static function dateShort(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '—';
        }

        $jalali = self::toJalali($timestamp);

        return $jalali ?? date('Y/m/d', $timestamp);
    }

    /**
     * تبدیل تاریخ به شمسی با افزونهٔ intl (اگر نصب باشد).
     *
     * خروجی intl بسته به نسخه می‌تواند ارقام لاتین یا عربی داشته باشد؛
     * در هر دو حالت به ارقام فارسی استاندارد تبدیل می‌شود.
     */
    public static function toJalali(int $timestamp): ?string
    {
        if (!class_exists(\IntlDateFormatter::class) || !class_exists(\IntlCalendar::class)) {
            return null;
        }

        try {
            $formatter = new \IntlDateFormatter(
                'fa_IR@calendar=persian',
                \IntlDateFormatter::SHORT,
                \IntlDateFormatter::SHORT,
                'Asia/Tehran',
                \IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd - HH:mm'
            );

            $formatted = $formatter->format($timestamp);
            if (!is_string($formatted) || $formatted === '') {
                return null;
            }

            return self::toPersianDigits(self::toEnglishDigits($formatted));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * «۳ روز و ۵ ساعت» — مدت زمانی خوانا.
     */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0
                ? self::faNumber($days) . ' روز و ' . self::faNumber($hours) . ' ساعت'
                : self::faNumber($days) . ' روز';
        }

        if ($hours > 0) {
            return $minutes > 0
                ? self::faNumber($hours) . ' ساعت و ' . self::faNumber($minutes) . ' دقیقه'
                : self::faNumber($hours) . ' ساعت';
        }

        return self::faNumber($minutes) . ' دقیقه';
    }

    /**
     * نام فایل امن از روی متن کاربر (برای رسیدها).
     */
    public static function slug(string $text, int $maxLength = 40): string
    {
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
        $text = trim($text, '-');

        return mb_substr($text === '' ? 'file' : $text, 0, $maxLength);
    }

    /**
     * متن HTML امن برای پیام‌های تلگرام.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * بریدن متن به طول مشخص، با رعایت سقف نهایی.
 *
     * نکتهٔ مهم: طول برگشتی **شامل پسوند** است. اگر از این تابع با سقف
     * سخت‌افزاری تلگرام (مثلاً ۴۰۹۶) استفاده شود و مقدار برگشتی ۴۰۹۷ شود،
     * تلگرام کل پیام را رد می‌کند. برای همین اینجا جای پسوند کنار گذاشته
     * می‌شود تا نتیجه هرگز از `$length` بیشتر نشود.
 *
 * @param int $length حداکثر طول نهایی خروجی (شامل پسوند)
 */
    public static function truncate(string $text, int $length = 64, string $suffix = '…'): string
    {
        $length = max(1, $length);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        // پسوند باید داخل سقف جا شود، وگرنه متن اصلی بی‌دلیل کوتاه می‌شود.
        $keep = $length - mb_strlen($suffix);

        if ($keep <= 0) {
            return mb_substr($suffix, 0, $length);
        }

        return rtrim(mb_substr($text, 0, $keep)) . $suffix;
    }

    /**
     * آیا متن یک یوزرنیم معتبر پنل است؟
     */
    public static function isValidPanelUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) === 1;
    }

    /**
     * مخفی کردن شماره کارت (نمایش فقط ۴ رقم آخر).
     * 
     * مثال: 1234567890123456 → ••••••••••••3456
     */
    public static function maskCardNumber(string $cardNumber): string
    {
        $cleaned = preg_replace('/\D/', '', $cardNumber);
        
        if (strlen($cleaned) < 4) {
            return '••••••••••••••••';
        }
        
        $last4 = substr($cleaned, -4);
        $masked = str_repeat('•', strlen($cleaned) - 4) . $last4;
        
        return $masked;
    }

    /**
     * تأیید صحت شماره کارت (الگوریتم Luhn).
     * 
     * @return bool
     */
    public static function isValidCardNumber(string $cardNumber): bool
    {
        $number = preg_replace('/\D/', '', $cardNumber);
        
        // فقط کارت‌های ۱۶ رقمی ایران
        if (strlen($number) !== 16) {
            return false;
        }
        
        // الگوریتم Luhn
        $sum = 0;
        $isEven = false;
        
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];
            
            if ($isEven) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            
            $sum += $digit;
            $isEven = !$isEven;
        }
        
        return ($sum % 10) === 0;
    }
}