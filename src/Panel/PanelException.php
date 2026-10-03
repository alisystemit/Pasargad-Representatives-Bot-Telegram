<?php

declare(strict_types=1);

namespace Pasargad\Panel;

/**
 * خطای API پنل با پیام فارسی مناسب نمایش به کاربر.
 */
class PanelException extends \RuntimeException
{
    private int $httpStatus;
    private ?array $payload;
    private bool $onTokenEndpoint;
    private bool $budgetExhausted;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        ?array $payload = null,
        ?\Throwable $previous = null,
        bool $onTokenEndpoint = false,
        bool $budgetExhausted = false
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus     = $httpStatus;
        $this->payload        = $payload;
        $this->onTokenEndpoint = $onTokenEndpoint;
        $this->budgetExhausted = $budgetExhausted;
    }

    /**
     * خطای ناشی از تمام شدن مهلت پردازش (نه خطای شبکه).
     *
     * سازندهٔ جدا لازم است چون این حالت هم وضعیت ۰ دارد ولی تلاش مجددش بی‌فایده
     * است؛ بدون پرچم اختصاصی، `isRetryable()` آن را اشتباهی قابل‌تلاش‌مجدد
     * می‌دید و هر بار دوباره همان لحظه شکست می‌خورد.
     */
    public static function budgetExceeded(string $message, string $path = ''): self
    {
        return new self($message, 0, $path === '' ? null : ['path' => $path], null, false, true);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }

    /**
     * آیا خطای احراز هویت است؟ یعنی باید کاربر دوباره لاگین کند.
     *
     * نکتهٔ حیاتی: فقط **۴۰۱** و ۴۰۳ روی *مسیر صدور توکن* نشانهٔ خرابی
     * اطلاعات ورود است.
     *
     * ۴۰۳ روی مسیرهای عملیاتی (مثل PUT /api/admin یا POST /api/user) یعنی
     * «نقش این کاربر اجازهٔ این عملیات را ندارد» — اطلاعات ورودش کاملاً سالم
     * است. اگر این را خطای احراز هویت بدانیم، کاربر بی‌دلیل از حساب پنل قطع و
     * از فروشگاه بیرون می‌افتد، در حالی که فقط باید به او بگوییم نقشش کافی نیست.
     */
    public function isAuthError(): bool
    {
        if ($this->httpStatus === 401) {
            return true;
        }

        if ($this->httpStatus !== 403) {
            return false;
        }

        // ۴۰۳ فقط وقتی خطای احراز هویت است که مربوط به گرفتن توکن باشد.
        return $this->onTokenEndpoint;
    }

    /**
     * آیا این خطا «دسترسی نداری» است؟ (نقش کاربر اجازهٔ عملیات را ندارد)
     *
     * این خطا **نه** باعث قطع حساب کاربر می‌شود و **نه** تلاش مجدد
     * بی‌فایده را تکرار می‌کند — کاربر باید با پشتیبانی تماس بگیرد.
     */
    public function isPermissionError(): bool
    {
        return $this->httpStatus === 403 && !$this->onTokenEndpoint;
    }

    /**
     * آیا خطای موقت شبکه/سرور است و ارزش تلاش مجدد دارد؟
     *
     * ⚠️ **۰۵ باید از این فهرست بیرون باشد.**
     * وقتی بودجهٔ زمانی تماس با پنل تمام می‌شود، کلاینت با وضعیت ۰ استثنا
     * می‌پرتابد تا یک خطای اختصاصی بسازد — ولی ۰ در این فهرست یعنی «ارتباط
     * شبکه برقرار نشد، دوباره تلاش کن». تلاش مجدد در آن حالت قطعاً شکست می‌خورد
     * چون وقتی وجود ندارد. پس پیش از این بررسی، پرچم مخصوص را می‌سنجیم.
     */
    public function isRetryable(): bool
    {
        if ($this->budgetExhausted) {
            return false;
        }

        return $this->httpStatus === 0
            || $this->httpStatus === 429
            || ($this->httpStatus >= 500 && $this->httpStatus < 600);
    }

    /**
     * آیا این خطا فقط به‌خاطر تمام شدن مهلت پردازش ساخته شده؟
     *
     * تفکیک این دو حالت حیاتی است چون هر دو وضعیت ۰ دارند ولی رفتارشان فرق
     * دارد: خطای شبکه با تلاش مجدد شاید درست شود، ولی تمام شدن بودجه با تلاش
     * مجدد فقط ربات را دیرتر جواب می‌دهد.
     */
    public function isBudgetExhausted(): bool
    {
        return $this->budgetExhausted;
    }
}