<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Support\Db;
use Pasargad\Support\Logger;

/**
 * سلامتِ درگاه‌های پرداخت — بر پایهٔ **تلاش‌های واقعی**، نه حدس.
 *
 * ## چرا این کلاس این‌طور نوشته شده
 *
 * نسخهٔ قبلی یک health-check داشت که همیشه `true` برمی‌گرداند، آمارش عدد
 * ساختگی بود (۹۵٪ موفقیت، ۱۰٬۰۰۰ تراکنش) و اصلاً فراخوانی نمی‌شد.
 *
 * اگر آن را به مسیر پول وصل می‌کردیم، **بدترین** حالت ممکن رخ می‌داد:
 * درگاهی که واقعاً خراب است «سالم» گزارش می‌شد، مشتری پولش را می‌داد و
 * پرداختش هرگز ثبت نمی‌شد.
 *
 * ## دو تصمیم که آگاهانه گرفته شد
 *
 * **۱) پروبِ فعال انجام نمی‌دهیم.**
 *
 * «سلامت درگاه» را با فرستادن یک درخواست ساختگی نمی‌سنجیم — یعنی تولید
 * تراکنشِ بی‌معنا روی سامانه‌ای که به پول واقعی وصل است. سنجهٔ درست، نتیجهٔ
 * همان تلاش‌هایی است که کاربر واقعاً می‌کند.
 *
 * **۲) روش پرداخت را پشت سرِ کاربر عوض نمی‌کنیم.**
 *
 * کاربر یک روش را انتخاب کرده. هر درگاه کارمزد، ارز و دستورالعمل خودش را
 * دارد؛ عوض‌کردنِ خودکار یعنی کاربر پولش را به حسابی می‌فرستد که انتظارش را
 * ندارد. پس این کلاس فقط **می‌گوید** کدام روش‌ها الآن خراب‌اند تا کاربر
 * انتخاب درستی داشته باشد.
 */
final class PaymentGatewayOptimizer
{
    /**
     * بعد از این تعداد شکستِ پیاپی، درگاه «ناسالم» اعلام می‌شود.
     *
     * ⚠️ ۲ است نه ۱: یک خطای گذرا (شبکه، تایم‌اوت) نباید یک درگاهِ سالم را
     * از کار بیندازد. با ۱، یک تایم‌اوت کل درگاه از فهرست حذف می‌شد.
     */
    private const FAILURES_BEFORE_UNHEALTHY = 2;

    /** پس از این مدت، وضعیتِ «ناسالم» خودبه‌خود دور ریخته می‌شود. */
    private const HEALTH_TTL = 900;   // ۱۵ دقیقه

    /** فاصلهٔ تلاش دوباره پس از خطای محدودیتِ نرخ. */
    private const RATE_LIMIT_BACKOFF = 60;

    private static ?Db $db = null;

    public static function useDb(?Db $db): void
    {
        self::$db = $db;
    }

    private static function db(): Db
    {
        return self::$db ?? Db::instance();
    }

    // ------------------------------------------------------------------
    // ثبت نتیجهٔ واقعیِ تلاش‌ها
    // ------------------------------------------------------------------

    /**
     * ثبت یک تلاشِ موفق روی درگاه.
     *
     * @param int $elapsedMs مدت پاسخ، برای میانگین
     */
    public static function recordSuccess(string $gateway, int $elapsedMs = 0): void
    {
        self::write($gateway, true, $elapsedMs, null);
    }

    /**
     * ثبت یک تلاشِ ناموفق.
     *
     * @param \Throwable|string|null $error فقط پیام ذخیره می‌شود، نه پشتهٔ
     *                                  فراخوانی — چون ممکن است شامل داده‌ای
     *                                  حساس باشد و جدول هرگز رشد نمی‌کند.
     */
    public static function recordFailure(string $gateway, \Throwable|string|null $error = null, int $elapsedMs = 0): void
    {
        self::write($gateway, false, $elapsedMs, self::messageOf($error));
    }

    private static function messageOf(\Throwable|string|null $error): ?string
    {
        if ($error === null) {
            return null;
        }

        return \Pasargad\Support\Str::truncate(
            $error instanceof \Throwable ? $error->getMessage() : $error,
            300
        );
    }

    private static function write(string $gateway, bool $ok, int $elapsedMs, ?string $error): void
    {
        $gateway = trim($gateway);

        if ($gateway === '') {
            return;
        }

        try {
            $db = self::db();
            $row = self::row($gateway);

            // ⭐️ سالم یا ناسالم از **شمارشِ پیاپیِ شکست** می‌آید، نه از
            // نرخِ کل. یک درگاه با ۱ شکست از ۱۰۰ تلاش، «سالم» است و باید
            // بماند؛ نسبت اینجا همین را خراب می‌کرد.
            $failures = $ok ? 0 : (int) ($row['failures'] ?? 0) + 1;
            $healthy = $failures < self::FAILURES_BEFORE_UNHEALTHY;

            $db->run(
                'INSERT INTO gateway_health
                     (gateway, healthy, attempts, successes, failures, total_ms, last_error, checked_at, updated_at)
                 VALUES (:g, :h, 1, :s, :f, :ms, :e, :t, :t)
                 ON CONFLICT(gateway) DO UPDATE SET
                     healthy      = :h,
                     attempts     = gateway_health.attempts + 1,
                     successes    = gateway_health.successes + :s,
                     failures     = :f,
                     total_ms     = gateway_health.total_ms + :ms,
                     last_error   = :e,
                     checked_at   = :t,
                     updated_at   = :t',
                [
                    ':g'  => $gateway,
                    ':h'  => $healthy ? 1 : 0,
                    ':s'  => $ok ? 1 : 0,
                    ':f'  => $failures,
                    ':ms' => max(0, $elapsedMs),
                    ':e'  => $error,
                    ':t'  => time(),
                ]
            );
        } catch (\Throwable $e) {
            // سنجش سلامت هرگز نباید خودش مسیر پول را خراب کند.
            Logger::warning('Could not record gateway health', [
                'gateway' => $gateway,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $gateway): array
    {
        try {
            return self::db()->first(
                'SELECT * FROM gateway_health WHERE gateway = ?',
                [$gateway]
            ) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // پرسش وضعیت
    // ------------------------------------------------------------------

    /**
     * آیا این درگاه الآن سالم است؟
     *
     * درگاهی که هنوز سابقه‌ای ندارد **سالم** شمرده می‌شود (خوش‌بینانه). وگرنه
     * اولین تلاشِ یک درگاهِ تازه‌افزوده همیشه رد می‌شد و کاربر هیچ‌وقت نمی‌توانست
     * با آن پرداخت کند.
     */
    public static function isGatewayHealthy(string $gateway): bool
    {
        $row = self::row(trim($gateway));

        if ($row === [] || (int) ($row['attempts'] ?? 0) === 0) {
            return true;
        }

        $checkedAt = (int) ($row['checked_at'] ?? 0);

        // وضعیتِ کهنه را نمی‌شود مبنا گرفت؛ درگاه ممکن است خوب شده باشد.
        if ($checkedAt > 0 && (time() - $checkedAt) > self::HEALTH_TTL) {
            return true;
        }

        return (int) ($row['healthy'] ?? 1) === 1;
    }

    /**
     * اولین درگاهِ سالم از فهرست.
     *
     * ⚠️ اگر هیچ‌کدام سالم نبود، **اولین درگاه** برگردانده می‌شود، نه `null`.
     *
     * دلیل: وضعیتِ سلامت یک تخمین است، نه حقیقت. اگر همه را «ناسالم» اعلام
     * کنیم، کاربر پیام «هیچ روش پرداختی در دسترس نیست» می‌بیند و فکر می‌کند
     * فروشگاه خراب است. بهتر است تلاش کند و اگر واقعاً خراب بود، همان خطای
     * واقعی را ببیند.
     *
     * @param  array<int, string> $available
     */
    public static function getHealthyGateway(array $available): ?string
    {
        $fallback = null;

        foreach ($available as $gateway) {
            $gateway = (string) $gateway;

            if ($fallback === null) {
                $fallback = $gateway;
            }

            if (self::isGatewayHealthy($gateway)) {
                return $gateway;
            }
        }

        return $fallback;
    }

    /**
     * نامِ روش‌هایی که الآن خراب‌اند (برای پیام به کاربر).
     *
     * @param  array<int, string> $available
     * @return array<int, string>
     */
    public static function unhealthyAmong(array $available): array
    {
        return array_values(array_filter(
            array_map('strval', $available),
            static fn (string $g): bool => !self::isGatewayHealthy($g)
        ));
    }

    // ------------------------------------------------------------------
    // سیاست تلاش مجدد
    // ------------------------------------------------------------------

    /**
     * دسته‌بندی خطا.
     *
     * ⚠️ کدِ وضعیت، اگر موجود باشد، **اولویت** دارد: برای درگاه‌های پرداخت کد
     * HTTP دقیق‌تر از متن خطاست (متن ممکن است ترجمه‌شده یا خالی باشد).
     *
     * پارامتر دوم اختیاری است چون درگاه‌های این پروژه استثنا نمی‌اندبلند و
     * کد را در آرایهٔ برگشتی می‌دهند؛ پس فراخنده می‌تواند آن را مستقیم بدهد.
     */
    public static function categorizeError(\Throwable|string|null $error = null, ?int $status = null): string
    {
        if ($status !== null) {
            $byStatus = match (true) {
                $status === 0                                     => 'timeout',   // ارتباط برقرار نشد
                $status === 429                                    => 'rate_limit',
                $status === 401 || $status === 403                 => 'authentication',
                $status === 400 || $status === 422                 => 'invalid_input',
                $status >= 500                                     => 'server',
                $status >= 400                                     => 'unknown',
                default                                            => null,
            };

            if ($byStatus !== null) {
                return $byStatus;
            }
        }

        $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

        if ($message === '') {
            return 'unknown';
        }

        return match (true) {
            str_contains($message, '429') || stripos($message, 'rate limit') !== false     => 'rate_limit',
            str_contains($message, '401') || stripos($message, 'auth') !== false           => 'authentication',
            str_contains($message, '503') || stripos($message, 'unavailable') !== false   => 'server',
            stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false => 'timeout',
            stripos($message, 'invalid') !== false                                          => 'invalid_input',
            default                                                                        => 'unknown',
        };
    }

    /**
     * سیاست تلاش مجدد بر پایهٔ دستهٔ خطا.
     *
     * @return array{retry:bool, max_retries:int, backoff?:string, reason?:string}
     */
    public static function getRetryStrategy(string $category): array
    {
        return match ($category) {
            // ورودی خراب و اشتباه پسورد با تلاش مجدد **بدتر** می‌شود.
            'invalid_input', 'authentication' => [
                'retry'       => false,
                'max_retries' => 0,
                'reason'      => $category === 'authentication'
                    ? 'اعتبارسنجی درگاه شکست خورد؛ تنظیمات باید اصلاح شود.'
                    : 'درگاه ورودی را نپذیرفت؛ تلاش مجدد فایده ندارد.',
            ],

            // محدودیت نرخ: صبر کردن واقعاً کمک می‌کند.
            'rate_limit' => ['retry' => true, 'max_retries' => 3, 'backoff' => 'linear'],

            'timeout', 'server' => ['retry' => true, 'max_retries' => 2, 'backoff' => 'exponential'],

            default => ['retry' => false, 'max_retries' => 0, 'reason' => 'خطای ناشناخته؛ تلاش مجدد بی‌فایده.'],
        };
    }

    /**
     * آیا این خطا ارزش تلاش مجددِ فوری دارد؟
     *
     * ⚠️ پیش از هر چیز بررسی می‌شود که **پرداختی ثبت نشده باشد**. تلاش مجددِ
     * یک درگاهِ پول‌محور، وقتی تلاش اول شاید ثبت شده، یعنی پرداختِ دوباره —
     * و این خطرناک‌ترین باگِ ممکن در مسیر پول است.
     */
    public static function shouldRetry(\Throwable|string|null $error = null, bool $paymentRecorded = false, ?int $status = null): bool
    {
        if ($paymentRecorded) {
            return false;
        }

        return self::getRetryStrategy(self::categorizeError($error, $status))['retry'];
    }

    public static function rateLimitBackoffSeconds(): int
    {
        return self::RATE_LIMIT_BACKOFF;
    }

    // ------------------------------------------------------------------
    // آمار
    // ------------------------------------------------------------------

    /**
     * آمارِ **واقعی** هر درگاه.
     *
     * نسخهٔ قبلی عدد ساختگی برمی‌گرداند (۹۵٪ موفقیت، ۱۰٬۰۰۰ تراکنش). عدد
     * ساختگی در داشبوردِ ادمین از هر خرابی‌ای بدتر است: مدیر به آن اعتماد
     * می‌کند و وقتی درگاه خراب می‌شود، دنبالش نمی‌گردد.
     *
     * @return array<string, mixed>
     */
    public static function getPerformanceMetrics(string $gateway): array
    {
        $row = self::row(trim($gateway));

        $attempts  = (int) ($row['attempts'] ?? 0);
        $successes = (int) ($row['successes'] ?? 0);
        $failures  = (int) ($row['failures'] ?? 0);

        return [
            'gateway'          => $gateway,
            'attempts'         => $attempts,
            'successes'        => $successes,
            'failures'         => $failures,
            // وقتی هنوز تلاشی نشده، «نرخ» یعنی ندانستن — نه صفر درصد و نه صد
            // درصد. صفر درصد یعنی «همه‌چیز خراب است» و هشدار الکی می‌سازد.
            'success_rate'     => $attempts > 0 ? round($successes / $attempts, 4) : null,
            'avg_response_ms'  => $successes > 0
                ? (int) round((int) ($row['total_ms'] ?? 0) / $successes)
                : null,
            'healthy'          => self::isGatewayHealthy($gateway),
            'last_error'       => $row['last_error'] ?? null,
            'checked_at'       => (int) ($row['checked_at'] ?? 0),
            'has_data'         => $attempts > 0,
        ];
    }

    /**
     * آمارِ همهٔ درگاه‌هایی که تا حالا تلاشی داشته‌اند.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allMetrics(): array
    {
        try {
            $rows = self::db()->all('SELECT gateway FROM gateway_health ORDER BY gateway');

            return array_map(
                static fn (array $r): array => self::getPerformanceMetrics((string) $r['gateway']),
                $rows
            );
        } catch (\Throwable $e) {
            return [];
        }
    }
}