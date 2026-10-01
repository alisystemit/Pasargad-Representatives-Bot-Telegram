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
     * تبدیل ارقام فارسی/عربی به انگلیسی (برای ورودی‌های عددی کاربر).
     */
    public static function toEnglishDigits(string $input): string
    {
        $out = strtr($input, self::PERSIAN_DIGITS, '0123456789');

        return strtr($out, self::ARABIC_DIGITS, '0123456789');
    }

    /**
     * تبدیل ارقام انگلیسی به فارسی برای نمایش.
     */
    public static function toPersianDigits(string $input): string
    {
        return strtr($input, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /**
     * تبدیل عدد به رشتهٔ فارسی با جداکنندهٔ هزارگان.
     */
    public static function faNumber(int|float $number, int $decimals = 0): string
    {
        $formatted = number_format((float) $number, $decimals, '.', ',');

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

    public static function truncate(string $text, int $length = 64, string $suffix = '…'): string
    {
        return mb_strlen($text) > $length
            ? mb_substr($text, 0, $length) . $suffix
            : $text;
    }

    /**
     * آیا متن یک یوزرنیم معتبر پنل است؟
     */
    public static function isValidPanelUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) === 1;
    }
}