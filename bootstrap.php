<?php

declare(strict_types=1);

/**
 * نقطهٔ شروع مشترک همهٔ entrypointها (وبهوک، IPN، کرون، ابزارهای CLI).
 */

require_once __DIR__ . '/src/Support/Autoloader.php';

Pasargad\Support\Autoloader::register(__DIR__ . '/src');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile) && is_file(__DIR__ . '/config.example.php') && PHP_SAPI === 'cli') {
    // در محیط CLI اجازه می‌دهیم برای تست‌های خشک با تنظیمات نمونه کار کند.
    $configFile = __DIR__ . '/config.example.php';
}

Pasargad\Support\Config::load($configFile);
Pasargad\Support\Logger::configure(
    Pasargad\Support\Config::str('log.path'),
    Pasargad\Support\Config::str('log.level', 'info'),
    Pasargad\Support\Config::int('log.max_files', 14)
);

if (!defined('PASARGAD_ROOT')) {
    define('PASARGAD_ROOT', __DIR__);
}