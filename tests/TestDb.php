<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;

/**
 * دیتابیس جداگانه برای تست‌ها (در حافظه).
 *
 * با تعریف کلاس TestDb، کلاس Db از آن استفاده می‌کند و فایل واقعی دست‌نخورده می‌ماند.
 */
final class TestDb
{
    private static ?Db $instance = null;

    public static function boot(): Db
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $reflection = new \ReflectionClass(Db::class);
        $db = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        $txProp = $reflection->getProperty('txDepth');
        $txProp->setAccessible(true);
        $txProp->setValue($db, 0);

        // Db::instance() باید به همین اتصال تست برگردد، وگرنه کلاس‌هایی که
        // خودشان Db::instance() می‌سازند (مثل Provisioner) روی فایل واقعی کار می‌کنند.
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, $db);

        self::$instance = $db;

        return $db;
    }
}