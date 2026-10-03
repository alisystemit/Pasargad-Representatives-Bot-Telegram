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

/**
 * کپی مقادیر پیش‌فرض کانفیگ به جدول settings (فقط برای کلیدهای غایب).
 *
 * چرا این کار لازم است؟
 *
 * بسیاری از تنظیمات (کانال اجباری، حجم تست کانفیگ، آستانهٔ هشدار انقضا) هم در
 * `config.php` پیش‌فرض دارند و هم از داخل ربات قابل تغییرند. اگر فقط از ربات
 * خوانده شوند، کسی که تازه نصب کرده و هنوز ربات را راه نینداخته، هیچ راهی برای
 * دیدن/تغییرشان ندارد.
 *
 * قاعدهٔ `INSERT OR IGNORE` یعنی اگر ادمین قبلاً چیزی را از داخل ربات تغییر داده
 * باشد، **دست‌نخورده می‌ماند**؛ فقط کلیدهای غایب پر می‌شوند. پس اجرای دوبارهٔ
 * این تابع هرگز تنظیمات کاربر را بازنویسی نمی‌کند.
 */
function pasargad_seed_config_defaults(): void
{
    $db = Pasargad\Support\Db::instance();

    if (!$db->tableExists('settings')) {
        return;   // هنوز مایگریشنی اجرا نشده؛ بعداً انجام می‌شود.
    }

    $defaults = [
        Pasargad\Store\Settings::CHANNEL_ENFORCED    => Pasargad\Support\Config::str('channel.enforced', '0'),
        Pasargad\Store\Settings::CHANNEL             => ltrim(Pasargad\Support\Config::str('channel.name', ''), '@'),
        Pasargad\Store\Settings::CHANNEL_TTL         => (string) Pasargad\Support\Config::int('channel.cache_minutes', 30),
        Pasargad\Store\Settings::EXPIRE_WARN_DAYS    => (string) Pasargad\Support\Config::int('worker.expire_warn_days', 3),
        Pasargad\Store\Settings::LOW_VOLUME_ALERT    => (string) Pasargad\Support\Config::int('worker.low_volume_alert', 5),
        Pasargad\Store\Settings::TEST_CONFIG_VOLUME_GB => (string) Pasargad\Support\Config::str('worker.test_config.volume_gb', '1'),
        Pasargad\Store\Settings::TEST_CONFIG_DAYS     => (string) Pasargad\Support\Config::int('worker.test_config.days', 1),
        Pasargad\Store\Settings::TEST_CONFIG_MAX      => (string) Pasargad\Support\Config::int('worker.test_config.max_per_user', 2),
        Pasargad\Store\Settings::TEST_CONFIG_COOLDOWN => (string) Pasargad\Support\Config::int('worker.test_config.cooldown', 30),
        Pasargad\Store\Settings::EXPIRE_GRACE_DAYS   => (string) Pasargad\Support\Config::int('worker.expire_grace_days', 3),
        Pasargad\Store\Settings::REFERRAL_DISCOUNT   => (string) Pasargad\Support\Config::int('worker.referral.discount_percent', 10),
        Pasargad\Store\Settings::REFERRAL_BONUS      => (string) Pasargad\Support\Config::int('worker.referral.bonus_toman', 50000),
        Pasargad\Store\Settings::PANEL_STATS_TTL      => (string) Pasargad\Support\Config::int('worker.panel_stats_ttl_minutes', 30),
        Pasargad\Store\Settings::BACKUP_KEEP          => (string) Pasargad\Support\Config::int('worker.backup_keep', 14),
    ];

    $defaults = array_filter($defaults, static fn (string $v): bool => $v !== '');

    if ($defaults === []) {
        return;
    }

    $columns = [];
    $params  = [];

    foreach ($defaults as $key => $value) {
        $columns[]              = '(:key_' . $key . ', :val_' . $key . ', 0)';
        $params['key_' . $key] = $key;
        $params['val_' . $key] = $value;
    }

    $db->run(
        'INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ' . implode(', ', $columns),
        $params
    );
}

pasargad_seed_config_defaults();

if (!defined('PASARGAD_ROOT')) {
    define('PASARGAD_ROOT', __DIR__);
}