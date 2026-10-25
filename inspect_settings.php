<?php
require_once __DIR__ . '/bootstrap.php';

use Pasargad\Support\Db;

echo "=== بررسی کلیدهای تنظیمات کارت در DB ===\n\n";

$db = Db::instance();

$rows = $db->all("SELECT key, value, updated_at FROM settings WHERE key LIKE '%card%' OR key LIKE '%payment%' ORDER BY key");

if (empty($rows)) {
    echo "هیچ ردیفی یافت نشد!\n";
} else {
    foreach ($rows as $row) {
        echo "کلید: '{$row['key']}' = '" . substr((string)$row['value'], 0, 20) . "' (updated_at: {$row['updated_at']})\n";
    }
}

echo "\n--- همه کلیدهای مرتبط با کارت ---\n";
$all = $db->all("SELECT key, value FROM settings ORDER BY key");
foreach ($all as $row) {
    echo "'{$row['key']}' = '" . substr((string)$row['value'], 0, 30) . "'\n";
}

echo "\n✅ کامل شد\n";