<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * رمزنگاری متقارن پسورد پنل ادمین‌ها با libsodium (fallback به OpenSSL).
 *
 * چرا رمزنگاری؟ ربات برای اعمال خودکار بسته‌ها باید لاگین پنل را داشته باشد،
 * بنابراین پسورد هرگز به‌صورت خام در دیتابیس ذخیره نمی‌شود.
 */
final class Crypto
{
    private const PREFIX = 'v1';

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt');
    }

    public static function encrypt(string $plain, ?string $key = null): string
    {
        $key = self::normalizeKey($key);

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

            return self::PREFIX . ':' . self::base64UrlEncode($nonce . $cipher);
        }

        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('هیچ پیاده‌سازی رمزنگاری روی سرور در دسترس نیست.');
        }

        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('رمزنگاری با OpenSSL ناموفق بود.');
        }

        return self::PREFIX . ':o:' . self::base64UrlEncode($iv . $cipher);
    }

    public static function decrypt(string $payload, ?string $key = null): string
    {
        $key = self::normalizeKey($key);

        if (strncmp($payload, self::PREFIX . ':', 3) !== 0) {
            throw new \RuntimeException('قالب دادهٔ رمزشده نامعتبر است.');
        }

        $body     = substr($payload, 3);
        $isOpenSsl = false;
        if (strncmp($body, 'o:', 2) === 0) {
            $isOpenSsl = true;
            $body     = substr($body, 2);
        }

        $raw = self::base64UrlDecode($body);
        if ($raw === null) {
            throw new \RuntimeException('دادهٔ رمزشده قابل خواندن نیست.');
        }

        if (!$isOpenSsl && function_exists('sodium_crypto_secretbox_open')) {
            $nonceSize  = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plain      = sodium_crypto_secretbox_open(substr($raw, $nonceSize), substr($raw, 0, $nonceSize), $key);
            if ($plain === false) {
                throw new \RuntimeException('رمزگشایی ناموفق بود.');
            }

            return $plain;
        }

        if (!function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL روی سرور در دسترس نیست.');
        }

        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new \RuntimeException('رمزگشایی با OpenSSL ناموفق بود.');
        }

        return $plain;
    }

    /**
     * کلید مشتق‌شده از رشتهٔ خام کانفیگ، با طول دقیق ۳۲ بایت.
     */
    public static function normalizeKey(?string $key = null): string
    {
        if ($key === null) {
            $key = Config::str('crypto_key', '');
        }

        if ($key === '') {
            throw new \RuntimeException('کلید رمزنگاری (crypto_key) در تنظیمات تعریف نشده است.');
        }

        return hash('sha256', $key, true);
    }

    /**
     * base64 امن برای URL (بدون padding و کاراکترهای +/=).
     */
    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): ?string
    {
        $normalized = strtr($encoded, '-_', '+/');
        $padding    = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? null : $decoded;
    }
}