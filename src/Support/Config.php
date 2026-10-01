<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * بارگذاری و دسترسی به تنظیمات پروژه با سینتکش نقطه‌ای: config('panel.base_url')
 */
final class Config
{
    private static ?array $items = null;
    private static string $path = '';

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException(
                'فایل تنظیمات پیدا نشد: ' . $file . ' — ابتدا config.example.php را به config.php کپی کنید.'
            );
        }

        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException('محتوای فایل تنظیمات باید یک آرایه باشد: ' . $file);
        }

        self::$items = $data;
        self::$path  = $file;

        $tz = (string) ($data['timezone'] ?? 'Asia/Tehran');
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'Asia/Tehran';
        }
        date_default_timezone_set($tz);
    }

    /**
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $node = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $value = self::get($key);
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return array<int, mixed>
     */
    public static function arr(string $key): array
    {
        $value = self::get($key);
        return is_array($value) ? $value : [];
    }

    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $node =& self::$items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $node[$segment] = $value;
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node =& $node[$segment];
        }
    }

    public static function isLoaded(): bool
    {
        return self::$items !== null;
    }

    public static function path(): string
    {
        return self::$path;
    }
}