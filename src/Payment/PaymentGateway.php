<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Support\Config;

/**
 * قرارداد مشترک درگاه‌های پرداخت.
 */
interface PaymentGateway
{
    /**
     * نام کوتاه درگاه (card2card | nowpayments).
     */
    public function name(): string;

    /**
     * آیا درگاه با تنظیمات فعلی آمادهٔ استفاده است؟
     */
    public function isEnabled(): bool;

    /**
     * عنوان نمایشی برای دکمه.
     */
    public function title(): string;

    /**
     * آیا پرداخت به تأیید دستی نیاز دارد؟
     */
    public function requiresReview(): bool;

    /**
     * شروع پرداخت.
     *
     * @param  array<string, mixed> $order
     * @return array{
     *     ok: bool,
     *     message: string,
     *     instructions?: string,
     *     pay_url?: string,
     *     reference?: string,
     *     amount_usd?: float,
     *     currency?: string,
     *     requires_review?: bool,
     *     raw?: array<string, mixed>
     * }
     */
    public function start(array $order, int $chatId): array;

    /**
     * بررسی وضعیت پرداخت (برای دکمهٔ «بررسی مجدد» یا کرون).
     *
     * @param  array<string, mixed> $payment رکورد جدول payments
     * @return array{status:string, paid:bool, message:string, reference?:string, raw?:array<string, mixed>}
     */
    public function checkStatus(array $payment): array;
}