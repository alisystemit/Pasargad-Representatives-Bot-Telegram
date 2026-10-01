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

    public function __construct(string $message, int $httpStatus = 0, ?array $payload = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->payload    = $payload;
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
     * آیا خطای احراز هویت است؟ (401/403) — یعنی باید دوباره لاگین کرد.
     */
    public function isAuthError(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }

    /**
     * آیا خطای موقت شبکه/سرور است و ارزش تلاش مجدد دارد؟
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 0
            || $this->httpStatus === 429
            || ($this->httpStatus >= 500 && $this->httpStatus < 600);
    }
}