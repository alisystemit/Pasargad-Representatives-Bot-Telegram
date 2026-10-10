<?php

declare(strict_types=1);

namespace Pasargad\Support;

use Pasargad\Bot\ShopException;
use Pasargad\Panel\PanelException;

/**
 * Centralized error handling with context and user-friendly messages
 */
final class ErrorHandler
{
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
