<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * اتولودر ساده و بدون وابستگی (PSR-4) برای پروژه.
 *
 * نگاشت:  Pasargad\Support\Logger  ->  src/Support/Logger.php
 */
final class Autoloader
{
    private static bool $registered = false;

    public static function register(string $baseDir): void
    {
        if (self::$registered) {
            return;
        }

        $prefix = 'Pasargad\\';
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        $length  = strlen($prefix);

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir, $length): void {
            if (strncmp($class, $prefix, $length) !== 0) {
                return;
            }

            $relative = substr($class, $length);
            $path = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });

        self::$registered = true;
    }
}