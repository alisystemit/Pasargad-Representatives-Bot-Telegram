<?php

declare(strict_types=1);

namespace Pasargad\Support;

use Pasargad\Bot\ShopException;
use Pasargad\Bot\WebhookReliability;
use Pasargad\Panel\PanelException;

/**
 * Centralized error handling with context and user-friendly messages
 */
final class ErrorHandler
{
    /**
     * طولانی‌ترین متنی که از «ورودی کاربر» به کاربر نشان داده می‌شود.
     *
     * چرا سقف دارد: ورودی کاربر می‌تواند یک پیامِ ۴۰۰۰ کاراکتری باشد و آن‌وقت
     * خودِ پیامِ خطا از حدِ تلگرام رد می‌شود و کاربر **هیچ** چیز نمی‌بیند — یعنی
     * گزارشی که برای عیب‌یابی ساخته شده بود، خودش به باگ تبدیل می‌شود.
     */
    public const MAX_INPUT_SHOW = 120;

    /**
     * ساختِ گزارشِ خطای داخلی.
     *
     * ⭐️ چرا این متن را **به کاربر** نشان می‌دهیم و نه فقط لاگ:
     *
     * ما به سرور و تلگرام دسترسی نداریم؛ تنها چیزی که از یک خطا می‌بینیم همین
     * پیامی است که کاربر کپی می‌کند و برایمان می‌فرستد. «خطایی رخ داد» بدون
     * هیچ جزئیاتی یعنی حدس زدن؛ با بخش + ورودی + علت + `فایل:خط` یعنی یک
     * جست‌وجوی دقیق.
     *
     * ## چیزهایی که عمداً نشان نمی‌دهیم
     *
     * ۱) **مسیرِ کاملِ فایل** — فقط `basename`. مسیرِ مطلق چیدمانِ سرور و گاهی
     *    نامِ کاربریِ هاست را لو می‌دهد؛ برای عیب‌یابی هم لازم نیست.
     * ۲) **استک‌تریس** — ده‌ها خطِ بی‌ربط برای کاربر. در لاگ هست.
     * ۳) **کلید و رمز** — `sanitize()` هر چیزی شبیه توکن را می‌پوشاند.
     *
     * @param array<string, mixed> $meta داده‌های کمکی برای لاگ (نه برای نمایش)
     */
    public static function diagnostic(\Throwable $e, string $stage, string $input, array $meta = []): string
    {
        $file = basename((string) $e->getFile());
        $line = (int) $e->getLine();
        $why  = self::sanitize($e->getMessage());

        // لاگ کامل و بدونِ محدودیت — اینجاست که عیب‌یابی واقعی انجام می‌شود.
        try {
            Logger::error('Internal error report', array_merge($meta, [
                'stage'     => $stage,
                'input'     => Str::truncate($input, 500),
                'exception' => get_class($e),
                'message'   => $why,
                'code'      => $e->getCode(),
                'file'      => $e->getFile() . ':' . $line,
                'trace'     => $e->getTraceAsString(),
            ]));
        } catch (\Throwable) {
            // ⭐️ اگر لاگر هم خراب باشد، پیامِ کاربر نباید از بین برود.
            // یک گزارشِ کمتر ولی **موجود** از هیچ گزارشی بهتر است.
        }

        $shown = trim($input);

        return implode("\n", [
            '⚠️ <b>خطای داخلی ربات — عملیات متوقف شد</b>',
            '',
            '🧩 بخش: <code>' . Str::escape($stage) . '</code>',
            '⌨️ ورودی شما: <code>' . Str::escape(
                $shown === '' ? '—' : Str::truncate($shown, self::MAX_INPUT_SHOW)
            ) . '</code>',
            '❗️ علت: ' . Str::escape(self::wrap($why)),
            '📍 محل دقیق خطا: <code>' . Str::escape($file . ':' . $line) . '</code>',
            '',
            str_repeat('━', 30),
            // ⚠️ این جمله **دقیقاً** مطابقِ رفتار واقعی نوشته شده. نسخه‌ای که می‌گفت
            // «آپدیت علامت‌گذاری شد پس تکرار نمی‌شود» در این ربات **دروغ** بود:
            // آپدیت پارک می‌شود و کرون تا ۳ بار دوباره اجرایش می‌کند. متنی که خلافِ
            // رفتار باشد، کاربر را بر اساسِ همان متن تصمیم‌گیریِ غلط می‌گیرد.
            '🔁 این عملیات خودکار تا ' . Str::faNumber(WebhookReliability::MAX_ATTEMPTS)
                . ' بار دیگر تلاش می‌شود؛ اگر باز هم خطا داد در «مرده‌نامه‌ها» ثبت می‌ماند.',
            '',
            'اگر تکرار شد همین پیام را برای پشتیبانی بفرستید — محل خطا دقیقاً مشخص است. 🙏',
        ]);
    }

    /**
     * پوشاندنِ چیزهایی که نباید در پیامِ کاربر دیده شوند.
     *
     * ⭐️ این کار **پیش از** نمایش انجام می‌شود، نه فقط در لاگ: پیامِ استثنا در خیلی
     * از موارد از خودِ داده‌ها ساخته می‌شود (`INSERT INTO … VALUES (?, ?)` با
     * مقدارِ واقعی) و یک رمز یا کلید می‌تواند همان‌جا گیر بیفتد.
     *
     * @return array{0:string,1:array<int,string>} پیامِ پاک‌شده و پوشانده‌شده‌ها
     */
    public static function redact(string $message): array
    {
        $hidden = [];

        $patterns = [
            // کلیدهای API پنل
            '/\bpg[_-]?key[_-][A-Za-z0-9_\-]{4,}/i',
            // «token=…» / «password: …» / «api_key = …»
            '/\b(password|passwd|pwd|token|secret|api[_-]?key|authorization)\b\s*[=:]\s*\S+/i',
            // توکنِ تلگرام: «123456789:AA…»
            '/\b\d{6,12}:[A-Za-z0-9_\-]{20,}/',
            // رشته‌های خیلی بلندِ تصادفی (base64/hex) که به‌احتمال زیاد رازند
            '/\b[A-Za-z0-9+\/=_\-]{40,}\b/',
        ];

        foreach ($patterns as $index => $pattern) {
            $replaced = preg_replace_callback(
                $pattern,
                static function (array $m) use (&$hidden, $index): string {
                    $hidden[] = $m[0];

                    // برای `password=…` خودِ نامِ فیلد را نگه می‌داریم: گفتنِ
                    // «کدام چیز پوشانده شد» به عیب‌یابی کمک می‌کند.
                    if ($index === 1 && str_contains($m[0], '=')) {
                        [$field] = explode('=', $m[0], 2);

                        return trim($field) . '=«پوشانده شد»';
                    }

                    return '«پوشانده شد»';
                },
                $message
            );

            if (is_string($replaced)) {
                $message = $replaced;
            }
        }

        return [$message, $hidden];
    }

    /**
     * پاک‌سازیِ پیام استثنا — بدون فهرست.
     */
    public static function sanitize(string $message): string
    {
        return self::redact($message)[0];
    }

    /**
     * ایمن‌کردنِ متنِ بلند تا پیامِ HTML نشکند.
     *
     * تلگرام پیامِ خیلی بلند را رد می‌کند و کاربر هیچ پیامی نمی‌بیند. ضمناً متنِ
     * استثنا می‌تواند نویسهٔ کنترلی داشته باشد که ساختارِ HTML را خراب می‌کند.
     */
    private static function wrap(string $message): string
    {
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $message) ?? $message;
        $message = trim($message);

        if ($message === '') {
            return 'خطای ناشناخته (پیام خالی بود)';
        }

        return Str::truncate($message, 300, '…');
    }

    /**
     * Log error and return user-friendly message
     */
    public static function handle(\Throwable $e, string $context = '', array $meta = []): string
    {
        // Log with full context
        Logger::error($context ?: 'An error occurred', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'meta' => $meta,
        ]);

        // Return user-friendly message based on exception type
        if ($e instanceof PanelException) {
            return self::panelErrorMessage($e);
        }

        if ($e instanceof ShopException) {
            return self::shopErrorMessage($e);
        }

        // Generic error message
        return '❌ خطایی پیش آمد. لطفاً بعداً دوباره تلاش کنید.';
    }

    /**
     * Handle panel connection errors
     */
    private static function panelErrorMessage(PanelException $e): string
    {
        if ($e->isAuthError()) {
            return '🔐 خطای احراز هویت: نام کاربری یا رمز عبور نادرست است.';
        }

        if ($e->isBudgetExceeded()) {
            return '⏱️ بودجهٔ زمانی درخواست‌های API تمام شده است. لطفاً کمی بعد تلاش کنید.';
        }

        if ($e->isPermissionError()) {
            return '🔒 شما مجوز انجام این کار را ندارید.';
        }

        return '📡 ارتباط با پنل ناموفق بود: ' . $e->getMessage();
    }

    /**
     * Handle shop operation errors
     */
    private static function shopErrorMessage(ShopException $e): string
    {
        return '🛒 خطا در فروشگاه: ' . $e->getMessage();
    }

    /**
     * Validate input with context
     */
    public static function validateInput(string $input, string $type, array $rules = []): ?string
    {
        $input = trim($input);

        // Check minimum length
        if (($rules['min_length'] ?? 0) > 0 && mb_strlen($input) < $rules['min_length']) {
            return sprintf('⚠️ حداقل %d کاراکتر وارد کنید.', $rules['min_length']);
        }

        // Check maximum length
        if (($rules['max_length'] ?? 0) > 0 && mb_strlen($input) > $rules['max_length']) {
            return sprintf('⚠️ حداکثر %d کاراکتر مجاز است.', $rules['max_length']);
        }

        // Type-specific validation
        return match ($type) {
            'email' => self::validateEmail($input),
            'url' => self::validateUrl($input),
            'card_number' => self::validateCardNumber($input),
            'numeric' => self::validateNumeric($input, $rules),
            'username' => self::validateUsername($input),
            default => null,
        };
    }

    private static function validateEmail(string $email): ?string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '⚠️ آدرس ایمیل نامعتبر است.';
        }
        return null;
    }

    private static function validateUrl(string $url): ?string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '⚠️ آدرس وب نامعتبر است. باید با https:// شروع شود.';
        }
        return null;
    }

    private static function validateCardNumber(string $card): ?string
    {
        $card = preg_replace('/\D/', '', $card);
        if (strlen($card) !== 16) {
            return '⚠️ شماره کارت باید ۱۶ رقم باشد.';
        }
        if (!Str::isValidCardNumber($card)) {
            return '⚠️ شماره کارت معتبر نیست (فیلتر Luhn).';
        }
        return null;
    }

    private static function validateNumeric(string $input, array $rules): ?string
    {
        if (!is_numeric($input)) {
            return '⚠️ فقط عدد وارد کنید.';
        }

        $num = (int) $input;

        if (($rules['min'] ?? 0) > 0 && $num < $rules['min']) {
            return sprintf('⚠️ حداقل %d وارد کنید.', $rules['min']);
        }

        if (($rules['max'] ?? 0) > 0 && $num > $rules['max']) {
            return sprintf('⚠️ حداکثر %d مجاز است.', $rules['max']);
        }

        return null;
    }

    private static function validateUsername(string $username): ?string
    {
        if (strlen($username) < 3) {
            return '⚠️ نام کاربری باید حداقل ۳ کاراکتر باشد.';
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return '⚠️ فقط حروف انگلیسی، عدد و _ مجاز است.';
        }
        return null;
    }
}
