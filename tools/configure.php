<?php

declare(strict_types=1);

/**
 * نوشتن تنظیمات ضروری نصب داخل config.php — بدون دست زدن به بقیهٔ کلیدها.
 *
 * چرا یک ابزار جدا؟ چون «جایگزینی مقدار در config.php» باید دقیق باشد:
 * کلید `base_url` دو بار در فایل می‌آید (یکی سطح بالا، یکی داخل `panel`)؛
 * یک str_replace ساده می‌تواند آدرس پنل را خراب کند. این ابزار فقط خطِ
 * سطحِ بالا را با الگوی `^    'key'` می‌گیرد و اگر یک جا هم جا نیفتد، خطا
 * می‌دهد به‌جای اینکه بی‌صدا نصب را «موفق» جا بزند.
 *
 * استفاده:
 *   php tools/configure.php --bot-token=123:ABC --admin-id=123456 --base-url=https://bot.example.com
 *   php tools/configure.php --bot-username=MyBot
 *
 * برای تست: --config=/مسیر/موقت/config.php --example=/مسیر/موقت/config.example.php
 *
 * متغیرهای محیطی جایگزین (امن‌تر؛ توکن در `ps` دیده نمی‌شود):
 *   BOT_TOKEN, ADMIN_ID, BASE_URL, BOT_USERNAME
 *
 * خروجی: هر تغییر را گزارش می‌کند. کد خروج ۱ یعنی هیچ تغییری اعمال نشد
 * یا مقدار ورودی نامعتبر بود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این ابزار فقط از خط فرمان قابل اجراست.\n");
}

// مسیرها را می‌توان برای تست جایگزین کرد؛ پیش‌فرض همان config.php کنار پروژه است.
$exampleFile = __DIR__ . '/../config.example.php';
$configFile  = __DIR__ . '/../config.php';

// ------------------------------------------------------------------
// خواندن ورودی‌ها
// ------------------------------------------------------------------

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        fwrite(STDERR, "آرگومان ناشناخته: {$arg}\n");
        exit(1);
    }
    $pair = explode('=', substr($arg, 2), 2);
    $options[$pair[0]] = $pair[1] ?? '';
}

if (isset($options['config'])) {
    $configFile = $options['config'];
}
if (isset($options['example'])) {
    $exampleFile = $options['example'];
}

$botToken   = $options['bot-token']   ?? (getenv('BOT_TOKEN') ?: '');
$adminId    = $options['admin-id']    ?? (getenv('ADMIN_ID') ?: '');
$baseUrl    = $options['base-url']    ?? (getenv('BASE_URL') ?: '');
$botUser    = $options['bot-username'] ?? (getenv('BOT_USERNAME') ?: '');

if ($botToken === '' && $adminId === '' && $baseUrl === '' && $botUser === '') {
    fwrite(STDERR, "هیچ مقداری داده نشد. مثال:\n");
    fwrite(STDERR, "  php tools/configure.php --bot-token=123:ABC --admin-id=123456 --base-url=https://bot.example.com\n");
    exit(1);
}

// ------------------------------------------------------------------
// اعتبارسنجی
// ------------------------------------------------------------------

$errors = [];

if ($botToken !== '' && preg_match('/^[0-9]{4,}:[A-Za-z0-9_-]{8,}$/', $botToken) !== 1) {
    $errors[] = 'توکن ربات قالب درست ندارد. قالب باید «عدد:رشته» باشد، مثلاً 123456789:AAH-xxxxxxxx';
}

if ($adminId !== '' && preg_match('/^[0-9]{4,20}(,[0-9]{4,20})*$/', $adminId) !== 1) {
    $errors[] = 'آیدی سوپرادمین باید عددی باشد (چندتا با کاما جدا شوند).';
}

if ($baseUrl !== '') {
    $baseUrl = rtrim($baseUrl, '/');
    if (preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]+)?(/[^\\s]*)?$#', $baseUrl) !== 1) {
        $errors[] = "آدرس وب سایت معتبر نیست: {$baseUrl} — مثال: https://bot.example.com";
    }
}

if ($botUser !== '' && preg_match('/^@?[A-Za-z0-9_]{5,32}$/', $botUser) !== 1) {
    $errors[] = "نام کاربری ربات معتبر نیست: {$botUser}";
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "❌ {$error}\n");
    }
    exit(1);
}

// ------------------------------------------------------------------
// آماده‌سازی فایل
// ------------------------------------------------------------------

if (!is_file($configFile)) {
    if (!is_file($exampleFile)) {
        fwrite(STDERR, "❌ نه {$configFile} هست نه قالبش — پروژه ناقص است.\n");
        exit(1);
    }
    if (!copy($exampleFile, $configFile)) {
        fwrite(STDERR, "❌ ساخت config.php ناموفق بود (پوشه نوشتنی نیست؟)\n");
        exit(1);
    }
    echo "✅ config.php از روی قالب ساخته شد.\n";
}

$content = file_get_contents($configFile);
if ($content === false || $content === '') {
    fwrite(STDERR, "❌ خواندن config.php ناموفق بود.\n");
    exit(1);
}

$changed = [];
$notes   = [];

/**
 * جایگزینی مقدار فقط روی خط سطحِ بالای همان کلید.
 *
 * الگو `^    'key'` عمداً چهار فاصله می‌خواهد: کلیدهای تو در تو (مثل
 * `panel.base_url`) هشت فاصله دارند و اصلاً جا نمی‌افتند.
 */
function setTopLevel(string $content, string $key, string $value, array &$changed): string
{
    // گروه ۱ = کلید و عملگر، گروه ۲ = مقدار فعلی. فقط گروه ۱ نگه داشته می‌شود.
    $pattern = "/^(    '" . preg_quote($key, '/') . "'\s*=>\s*)"
        . "(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|\[[^\]]*\]|true|false|-?[0-9]+)/m";

    $count = 0;
    $result = preg_replace_callback(
        $pattern,
        static fn (array $m): string => $m[1] . $value,
        $content,
        1,
        $count
    );

    if ($count !== 1 || $result === null) {
        fwrite(STDERR, "❌ کلید '{$key}' در config.php پیدا نشد (یا بیش از یک بار آمد) — تغییر اعمال نشد.\n");
        exit(1);
    }

    $changed[] = $key;
    return $result;
}

// --- توکن، آیدی، آدرس وب سایت ---
if ($botToken !== '') {
    $content = setTopLevel($content, 'bot_token', var_export($botToken, true), $changed);
}
if ($adminId !== '') {
    $ids = array_map('intval', explode(',', $adminId));
    $content = setTopLevel($content, 'super_admins', '[' . implode(', ', $ids) . ']', $changed);
}
if ($baseUrl !== '') {
    $content = setTopLevel($content, 'base_url', var_export($baseUrl, true), $changed);
}
if ($botUser !== '') {
    $content = setTopLevel($content, 'bot_username', var_export(ltrim($botUser, '@'), true), $changed);
}

// ------------------------------------------------------------------
// کلیدهای امنیتی — فقط وقتی هنوز مقدار نمونه مانده باشند
// ------------------------------------------------------------------
//
// عمداً دستی روی کلیدی که قبلاً پر شده نمی‌رود: crypto_key بعد از رمز
// شدن رمز پنل‌ها هرگز نباید عوض شود، و webhook_secret عوض شدنش همهٔ
// درخواست‌های وبهوک را ۴۰۳ می‌کند.
// ------------------------------------------------------------------

$placeholders = [
    'crypto_key'     => ['CHANGE-THIS-TO-A-LONG-RANDOM-STRING-32+CHARS', ''],
    'webhook_secret' => ['CHANGE-THIS-RANDOM-SECRET', ''],
    'admin_webhook_secret' => ['CHANGE-THIS-RANDOM-SECRET', ''],
];

foreach ($placeholders as $key => $badValues) {
    if (preg_match("/^    '" . preg_quote($key, '/') . "'\s*=>\s*'([^']*)'/m", $content, $m) !== 1) {
        continue;
    }
    if (!in_array(trim($m[1]), $badValues, true)) {
        continue;   // قبلاً تنظیم شده — دست نمی‌زنیم
    }

    $length  = $key === 'crypto_key' ? 32 : 24;
    $content = setTopLevel($content, $key, var_export(bin2hex(random_bytes($length)), true), $changed);
    $notes[] = "{$key} تولید شد";
}

// ------------------------------------------------------------------
// ثبت
// ------------------------------------------------------------------

if ($changed === []) {
    echo "ℹ️  هیچ تغییری لازم نبود (مقادیر از قبل درست‌اند).\n";
    exit(0);
}

// پیش از نوشتن، یک نسخهٔ کنار می‌گذاریم. پسوند عمداً `.bak` است تا داخل
// `.gitignore` بیفتد و فایل‌های بی‌شمار در `git status` تلنبار نشوند.
@copy($configFile, $configFile . '.bak');

if (file_put_contents($configFile, $content) === false) {
    fwrite(STDERR, "❌ نوشتن config.php ناموفق بود.\n");
    exit(1);
}

echo '✅ در config.php نوشته شد: ' . implode(', ', $changed) . "\n";
if ($notes !== []) {
    echo '✅ ' . implode('، ', $notes) . "\n";
}

// راستی‌آزمایی: فایل باید هنوز یک آرایهٔ معتبر PHP باشد.
$data = @include $configFile;
if (!is_array($data)) {
    fwrite(STDERR, "❌ config.php بعد از ویرایش باز هم بارگذاری نمی‌شود!\n");
    exit(1);
}
