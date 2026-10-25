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
    ['بازگشتی (باگ‌های بحرانی)', $root . '/tests/regression_test.php', false],
    ['امنیت پرداخت',          $root . '/tests/payment_security_test.php', false],
    ['جریان‌های چندمرحله‌ای',  $root . '/tests/state_flow_test.php', false],
    ['ساختار کیبورد',         $root . '/tests/keyboard_test.php',   false],
    ['ناوبری و دکمه‌های مرده', $root . '/tests/navigation_test.php', false],
    // این دو، تست‌های «ساختاری» هستند و جلوی دو دسته باگِ کاملاً بی‌صدا را
    // می‌گیرند: دکمه‌ای که ساخته می‌شود ولی مسیریابی ندارد، و نوعی که در امضای
    // یک متد به کلاسِ ناموجود اشاره می‌کند (که `php -l` آن را نمی‌بیند).
    ['مسیریابی همهٔ دکمه‌ها',   $root . '/tests/routing_test.php',   false],
    ['تنظیمات ادمین (ذخیره و نمایش)', $root . '/tests/admin_settings_test.php', false],
    ['نوع‌های حل‌نشده',        $root . '/tests/types_test.php',     false],
    ['تلاش دوبارهٔ وبهوک',     $root . '/tests/webhook_reliability_test.php', false],
    ['سفر مشتری و ادمین',    $root . '/tests/customer_journey_test.php', false],
    ['همکاری در فروش و کیف پول', $root . '/tests/referral_wallet_test.php', false],
    ['bind پارامترهای کوئری',  $root . '/tests/db_binding_test.php',      false],
    ['مشتریِ سمج و ادمین',    $root . '/tests/hostile_customer_test.php', false],
    ['اتصال پنل با کلید (وب‌اپ)', $root . '/tests/webapp_link_key_test.php', false],
    ['گزارش خطای داخلی',   $root . '/tests/error_report_test.php',   false],
    ['لایهٔ همگام‌سازی پنل',   $root . '/tests/panel_sync_manager_test.php', false],
    ['مایگریشن',               $root . '/tests/migrator_test.php',   false],
    ['پنل متصل و کلید API',     $root . '/tests/panel_access_test.php', false],
    ['تقسیم پیام بلند',       $root . '/tests/message_split_test.php', false],
    ['منطق خرید و اجرا',      $root . '/tests/shop_test.php',      false],
    ['پنل‌های نمایندگی',       $root . '/tests/panel_test.php',     false],
    // این دو تست قراردادِ کلاینت با اسپک واقعی PasarGuard را نگهبانی می‌کنند.
    // «قرارداد» یعنی مسیرهایی که پنل با ۴۰۵/۴۰۴ رد می‌کند هرگز صدا زده نشوند.
    ['قرارداد API پنل',         $root . '/tests/panel_contract_test.php', false],
    ['سقف زمانی تماس با پنل',   $root . '/tests/panel_budget_test.php',    false],
    ['تست کانفیگ',            $root . '/tests/test_config_test.php', false],
    ['عضویت اجباری کانال',    $root . '/tests/channel_guard_test.php', false],
    ['قطع دسترسی کاربران',    $root . '/tests/access_cutoff_test.php', false],
    ['مهلت ارفاقی انقضا',     $root . '/tests/grace_test.php',        false],
    ['تخفیف و معرفی و فاکتور', $root . '/tests/discount_test.php',    false],
    ['تیکت پشتیبانی',         $root . '/tests/support_test.php',     false],
    ['وبهوک مدیریتی و بکاپ', $root . '/tests/admin_webhook_test.php', false],
    ['پیکربندی نصب',        $root . '/tests/configure_test.php', false],
    ['بذر فروشگاه',          $root . '/tests/seed_test.php',      false],
    ['سرویس هشدارها',          $root . '/tests/alerts_test.php',      false],
    ['سوییچ‌ها و متن‌ها',       $root . '/tests/switches_test.php',    false],
    ['مینی‌اپ تلگرام',         $root . '/tests/webapp_test.php',    false],
    ['جریان کامل ربات',        $root . '/tests/bot_flow_test.php',  false],
    ['امنیت و کنترل دسترسی',   $root . '/tests/security_test.php',  false],
    ['کلاینت پنل (زنده)',      $root . '/tests/panel_live_test.php', true],
];

$totalPassed = 0;
$totalFailed = 0;
$failedSuites = [];
$optionalNotes = [];

foreach ($suites as [$name, $path, $optional]) {
    if (!is_file($path)) {
        continue;
    }

    echo "\n" . str_repeat('═', 62) . "\n";
    echo "  " . $name . ($optional ? '  (اختیاری — نیازمند اینترنت)' : '') . "\n";
    echo str_repeat('═', 62) . "\n";

    // اجرا در پروسهٔ جداگانه تا تست‌ها همدیگر را آلوده نکنند
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    $output  = [];
    $exitCode = 0;

    exec($command . ' 2>&1', $output, $exitCode);

    $text = implode("\n", $output);
    echo $text . "\n";

    // ⚠️ پیشوند «نتیجه:» اجباری نیست؛ بعضی مجموعه‌ها (مثل regression و
    // payment_security) خلاصه را بدون آن چاپ می‌کنند و با الگوی سخت‌گیرانه
    // اصلاً شمرده نمی‌شدند — یعنی «مجموع کل» کمتر از واقعیت گزارش می‌شد.
    // آخرین خط «N موفق، M ناموفق» همان خلاصهٔ واقعی هر مجموعه است.
    $found = preg_match_all(
        '/(?:نتیجه[:ٔ]?\s*)?(\d+)\s*موفق،?\s*(\d+)\s*ناموفق/u',
        $text,
        $matches,
        PREG_SET_ORDER
    );

    $suiteFailed = 0;

    if ($found > 0) {
        $last         = end($matches);
        $suiteFailed  = (int) $last[2];
        $totalPassed += (int) $last[1];

        // ------------------------------------------------------------------
        // ⚠️ مجموعه‌های **اختیاری** در شمارشِ ناموفقی نمی‌آیند.
        //
        // `panel_live_test` به پنلِ واقعی روی اینترنت وصل می‌شود. وقتی شبکه
        // قطع است یا TLS در محیطِ CI مشکل دارد، چهار تستش قرمز می‌شود و کلِ
        // خروجی قرمز می‌شود — در حالی که **هیچ چیزِ کد ما خراب نیست**.
        //
        // نتیجه‌اش بدتر از بی‌فایده بودن است: کسی که واقعاً یک باگ می‌آورد
        // نمی‌تواند فرقِ «نشبکه» و «خرابی» را ببیند، و اعتمادش به تست‌ها از
        // بین می‌رود. پس شمارشِ سخت‌گیرانه فقط روی تست‌های آفلاین است.
        //
        // شمارشِ موفقی همچنان جمع می‌شود تا دیده شود چقدر اجرا شده.
        // ------------------------------------------------------------------
        if (!$optional) {
            $totalFailed += $suiteFailed;
        } else {
            $optionalNotes[] = $name . ' (' . (int) $last[1] . ' موفق، '
                . $suiteFailed . ' ناموفق — اینترنت در دسترس نبود)';
        }
    }

    if ($exitCode !== 0) {
        if ($optional) {
            continue;
        }

        $failedSuites[] = $name;
    }
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  خلاصهٔ کل\n";
echo str_repeat('═', 62) . "\n";
echo "  {$totalPassed} تست موفق، {$totalFailed} ناموفق\n";

if ($failedSuites !== []) {
    echo "  مجموعه‌های ناموفق: " . implode('، ', $failedSuites) . "\n";
}

if ($optionalNotes !== []) {
    echo "\n  ℹ️ مجموعه‌های اختیاری (اینترنت لازم دارند و در شمارشِ ناموفقی\n";
    echo "     نیامده‌اند تا قطعیِ شبکه شبیه باگِ کد به نظر نرسد):\n";
    foreach ($optionalNotes as $note) {
        echo "   • {$note}\n";
    }
}

echo "\n";

exit($totalFailed === 0 && $failedSuites === [] ? 0 : 1);