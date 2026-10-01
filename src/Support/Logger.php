<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * لاگر ساده و امن برای محیط وب و CLI.
 *
 * در وب، خطاها داخل پوشهٔ لاگ نوشته می‌شوند و پاسخ JSON به تلگرام برمی‌گردد؛
 * بنابراین هیچ اطلاعاتی به کاربر نشت نمی‌کند.
 */
final class Logger
{
    public const DEBUG   = 10;
    public const INFO    = 20;
    public const WARNING = 30;
    public const ERROR   = 40;

    private static string $dir = '';
    private static int $minLevel = self::INFO;
    private static int $maxFiles = 14;
    private static string $channel = 'app';
    private static bool $initialized = false;

    private const LABELS = [
        self::DEBUG   => 'DEBUG',
        self::INFO    => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR   => 'ERROR',
    ];

    public static function configure(?string $dir = null, string $level = 'info', int $maxFiles = 14): void
    {
        self::$dir         = $dir !== null ? $dir : Config::str('log.path', __DIR__ . '/../../data/logs');
        self::$minLevel    = self::levelToInt($level);
        self::$maxFiles    = max(1, $maxFiles);
        self::$initialized = true;
        self::prune();
    }

    public static function channel(string $name): void
    {
        self::$channel = $name;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(int $level, string $message, array $context = []): void
    {
        if (!self::$initialized) {
            self::configure();
        }

        if ($level < self::$minLevel) {
            return;
        }

        $line = sprintf(
            '[%s] %s.%s: %s %s%s',
            date('Y-m-d H:i:s'),
            self::$channel,
            self::LABELS[$level] ?? 'INFO',
            $message,
            $context === [] ? '' : (json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            PHP_EOL
        );

        $file = self::file();
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // فایل با قفل نوشته می‌شود تا درخواست‌های همزمان، لایج شدن نداشته باشند.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        @chmod($file, 0664);
    }

    public static function file(): string
    {
        return rtrim((string) self::$dir, '/\\') . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
    }

    /**
     * پاک‌سازی فایل‌های قدیمی لاگ.
     */
    public static function prune(): void
    {
        $dir = rtrim((string) self::$dir, '/\\');
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.log') ?: [];
        if (count($files) <= self::$maxFiles) {
            return;
        }

        sort($files);
        foreach (array_slice($files, 0, count($files) - self::$maxFiles) as $file) {
            @unlink($file);
        }
    }

    /**
     * اجرای یک عملیات و ثبت خطای احتمالی بدون اینکه خطا به بیرون نشت کند.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public static function guard(callable $callback, string $context = '')
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            self::error('Unhandled exception in ' . $context, [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);

            throw $e;
        }
    }

    private static function levelToInt(string $level): int
    {
        return match (strtolower(trim($level))) {
            'debug'   => self::DEBUG,
            'info'    => self::INFO,
            'warning' => self::WARNING,
            'error'   => self::ERROR,
            default   => self::INFO,
        };
    }
}