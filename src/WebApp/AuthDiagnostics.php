<?php

declare(strict_types=1);

namespace Pasargad\WebApp;

use Pasargad\Support\Config;
use Pasargad\Support\Logger;

/**
 * تشخیص دقیق شکست احراز هویت وب‌اپ.
 *
 * چرا این کلاس وجود دارد؟
 *
 * خطای «امضای دادهٔ ورودی نامعتبر است» به‌تنهایی بی‌فایده است: پنج علت کاملاً
 * متفاوت دارد و هیچ‌کدام را از خودِ پیام پاسخ نمی‌شود فهمید. این کلاس برای هر
 * شکست یک **کد یکتا** (`reason`) و یک **راه‌حل** (`fix`) تولید می‌کند و تمام
 * واقعیت‌های لازم برای تصمیم‌گیری را در یک خط JSON لاگ می‌نویسد.
 *
 * ⚠️ اصل حاکم بر این فایل: **هیچ‌وقت توکن یا initData خام لاگ نشود.**
 *
 * چرا؟ چون initData خودش شامل اطلاعات شخصی کاربر است (`user` JSON) و توکن
 * ربات مثل پسورد است. لاگ ممکن است به هر کسی برسد. پس فقط:
 *   • **طول** و **نام کلیدها**، نه مقدارها
 *   • **اثرانگشت** توکن (`sha256` بریده‌شده)، نه خودِ توکن
 *   • هش دریافتی و هش محاسبه‌شده، بریده‌شده
 *
 * اثرانگشت دقیقاً همان چیزی است که تشخیص «دو سرور دو توکن متفاوت دارند» را
 * ممکن می‌کند، بدون افشای توکن.
 */
final class AuthDiagnostics
{
    /** طول اثرانگشت توکن؛ کافی برای تشخیص یکسانی/نایکسانی. */
    private const FINGERPRINT_LEN = 12;

    /**
     * کدهای شکست.
     *
     * هر کد دقیقاً یک علت را نام می‌برد؛ `fix` می‌گوید اپراتور چه کند.
     *
     * @var array<string, string>
     */
    private const REASONS = [
        'no_header'        => 'هدر X-Telegram-Init-Data اصلاً به PHP نرسید (فایروال، پروکسی، کش، یا باز شدن لینک در مرورگر به‌جای تلگرام).',
        'empty'            => 'هدر رسید ولی خالی بود — یعنی اپ خارج از تلگرام باز شده یا اسکریپت تلگرام لود نشده.',
        'too_long'         => 'initData از سقف طول مجاز بیشتر است؛ احتمالاً توسط وب‌سرور بریده شده (LimitRequestFieldSize آپاچی پیش‌فرض ۸۱۹۰ بایت است).',
        'token_placeholder'=> 'bot_token در config.php تعریف نشده یا هنوز مقدار نمونه است.',
        'token_format'     => 'bot_token قالب توکن تلگرام را ندارد (باید عدد و دونقطه و حروف باشد).',
        'no_hash'          => 'کلید hash در initData نیست — یعنی داده اصلاً امضا نشده یا وسط راه بریده شده.',
        'hash_format'      => 'کلید hash هست ولی ۶۴ کاراکتر hex نیست — نشانهٔ بریده شدن یا دستکاری.',
        'hash_mismatch'    => 'امضا با bot_token کانفیگ نمی‌خواند: یا توکن مال ربات دیگری است، یا فاصله/خط‌جدید دارد، یا کد روی سرور با این کد فرق دارد.',
        'bad_auth_date'    => 'auth_date وجود ندارد یا صفر است.',
        'expired'          => 'initData قدیمی است (بیشتر از سقف مجاز). اپ را ببندید و دوباره باز کنید.',
        'no_user'          => 'اطلاعات کاربر داخل initData قابل خواندن نیست.',
        'unverified_call'  => 'authenticate() با داده‌ای صدا زده شده که از InitData::parse عبور نکرده.',
    ];

    /**
     * اثرانگشت توکن کانفیگ — قابل مقایسه، غیرقابل بازگشت.
     */
    public static function tokenFingerprint(?string $token = null): string
    {
        $token = trim($token ?? (string) Config::str('bot_token', ''));

        return $token === ''
            ? '(empty)'
            : substr(hash('sha256', $token), 0, self::FINGERPRINT_LEN);
    }

    /**
     * آیا توکن کانفیگ شکل توکن تلگرام را دارد؟
     */
    public static function tokenLooksValid(?string $token = null): bool
    {
        $token = trim($token ?? (string) Config::str('bot_token', ''));

        return preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token) === 1;
    }

    /**
     * واقعیت‌های مربوط به خودِ توکن (بدون افشای توکن).
     *
     * @return array<string, mixed>
     */
    public static function tokenFacts(): array
    {
        $raw     = (string) Config::str('bot_token', '');
        $trimmed = trim($raw);

        return [
            'fingerprint'  => self::tokenFingerprint($trimmed),
            'length'       => strlen($trimmed),
            'has_whitespace' => $raw !== $trimmed,
            'is_placeholder' => in_array($trimmed, ['', 'PUT_BOT_TOKEN_HERE'], true),
            'format_ok'    => self::tokenLooksValid($trimmed),
        ];
    }

    /**
     * واقعیت‌های سطح انتقال (هدر رسید؟ طولش چقدر است؟ چه هدرهایی دیده شد؟).
     *
     * @param array<string, mixed> $server معمولاً `$_SERVER`
     * @return array<string, mixed>
     */
    public static function transportFacts(array $server): array
    {
        $raw = (string) ($server['HTTP_X_TELEGRAM_INIT_DATA'] ?? '');

        // نام هدرهای HTTP_* که واقعاً رسیده‌اند — برای تشخیص اینکه پروکسی
        // هدر را حذف کرده یا نه. فقط نام، نه مقدار.
        $headers = [];

        foreach (array_keys($server) as $key) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[] = strtolower(str_replace('_', '-', substr((string) $key, 5)));
            }
        }

        sort($headers);

        return [
            'header_present' => array_key_exists('HTTP_X_TELEGRAM_INIT_DATA', $server),
            'raw_length'     => strlen($raw),
            'empty'          => $raw === '',
            'http_headers'   => $headers,
        ];
    }

    /**
     * ثبت یک شکست با همهٔ شواهد موجود.
     *
     * @param string               $reason یکی از کلیدهای `REASONS`
     * @param array<string, mixed> $extra  جزئیات مخصوص همان مرحله
     */
    public static function logFailure(string $reason, array $extra = []): void
    {
        Logger::error('webapp.auth.rejected', [
            'reason'   => $reason,
            'fix'      => self::REASONS[$reason] ?? 'نامشخص',
            'token'    => self::tokenFacts(),
            'runtime'  => [
                'php'  => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
        ] + $extra);
    }

    /**
     * ثبت موفقیت — سبک، ولی با اثرانگشت تا اگر بعداً خطایی پیش آمد معلوم باشد
     * کدام توکن در حال کار بوده است.
     *
     * @param array<string, mixed> $extra
     */
    public static function logSuccess(array $extra = []): void
    {
        Logger::info('webapp.auth.ok', [
            'token_fingerprint' => self::tokenFingerprint(),
            'user_id'           => $extra['user_id'] ?? null,
        ]);
    }

    /**
     * یک ردیف قابل چاپ برای خط فرمان (بدون رنگ و کوتاه).
     *
     * @param array<string, mixed> $facts
     */
    public static function render(array $facts): string
    {
        $out = [];

        foreach ($facts as $k => $v) {
            $out[] = $k . '=' . (is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE));
        }

        return implode(' ', $out);
    }
}