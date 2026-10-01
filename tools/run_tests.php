<?php

declare(strict_types=1);

/**
 * اجرای همهٔ تست‌های پروژه.
 *
 * استفاده: php tools/run_tests.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);

$suites = [
    ['هستهٔ سیستم',            $root . '/tools/selftest.php',        false],
    ['منطق خرید و اجرا',      $root . '/tests/shop_test.php',      false],
    ['اعتبار ساخت کاربر',      $root . '/tests/user_credit_test.php', false],
    ['سرویس هشدارها',          $root . '/tests/alerts_test.php',      false],
    ['سوییچ‌ها و متن‌ها',       $root . '/tests/switches_test.php',    false],
    ['جریان کامل ربات',        $root . '/tests/bot_flow_test.php',  false],
    ['امنیت و کنترل دسترسی',   $root . '/tests/security_test.php',  false],
    ['کلاینت پنل (زنده)',      $root . '/tests/panel_live_test.php', true],
];

$totalPassed = 0;
$totalFailed = 0;
$failedSuites = [];

foreach ($suites as [$name, $path, $optional]) {
    if (!is_file($path)) {
        continue;
    }

    echo "\n" . str_repeat('═', 62) . "\n";
    echo "  " . $name . "\n";
    echo str_repeat('═', 62) . "\n";

    // اجرا در پروسهٔ جداگانه تا تست‌ها همدیگر را آلوده نکنند
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    $output  = [];
    $exitCode = 0;

    exec($command . ' 2>&1', $output, $exitCode);

    $text = implode("\n", $output);
    echo $text . "\n";

    if (preg_match('/نتیجه:\s*(\d+)\s*موفق،?\s*(\d+)\s*ناموفق/u', $text, $m) === 1) {
        $totalPassed += (int) $m[1];
        $totalFailed += (int) $m[2];
    }

    if ($exitCode !== 0) {
        $failedSuites[] = $name . ($optional ? ' (اختیاری)' : '');
    }
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  خلاصهٔ کل\n";
echo str_repeat('═', 62) . "\n";
echo "  {$totalPassed} تست موفق، {$totalFailed} ناموفق\n";

if ($failedSuites !== []) {
    echo "  مجموعه‌های ناموفق: " . implode('، ', $failedSuites) . "\n";
}

echo "\n";

exit($totalFailed === 0 && $failedSuites === [] ? 0 : 1);