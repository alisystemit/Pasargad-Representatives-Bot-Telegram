<?php

declare(strict_types=1);

/**
 * تستِ خودِ نصب‌کننده: ابزار `tools/configure.php`.
 *
 * باگی که این تست نگهبانی می‌کند:
 *
 *   `base_url` دو بار در قالب تنظیمات می‌آید — یکی سطحِ بالا (آدرس سایتِ
 *   خودِ ربات) و یکی داخل `panel` (آدرس پنل PasarGuard). هر جایگزینیِ
 *   ساده با str_replace می‌تواند آدرس پنل را هم عوض کند و عملاً فروش را
 *   از کار بیندازد، بی‌آنکه کسی متوجه شود.
 *
 * علاوه بر آن: کلیدهای امنیتی فقط وقتی عوض می‌شوند که هنوز مقدار نمونه
 * داشته باشند (crypto_key عوض شدنش رمز همهٔ پنل‌های فروخته‌شده را می‌شکند).
 */

require_once __DIR__ . '/../bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * اجرای configure.php در یک پوشهٔ موقت.
 *
 * @param  array<int, string> $args
 * @return array{rc:int, out:string}
 */
function runConfigure(string $configPath, string $examplePath, array $args): array
{
    // متغیرهای محیطی نباید روی تست اثر بگذارند.
    foreach (['BOT_TOKEN', 'ADMIN_ID', 'BASE_URL', 'BOT_USERNAME'] as $name) {
        putenv($name);
    }

    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../tools/configure.php')
        . ' --config=' . escapeshellarg($configPath)
        . ' --example=' . escapeshellarg($examplePath);

    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output  = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return ['rc' => $exitCode, 'out' => implode("\n", $output)];
}

// ------------------------------------------------------------------

$tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'pasargad-configure-' . bin2hex(random_bytes(4));

if (!mkdir($tmp) && !is_dir($tmp)) {
    echo "  ❌ ساخت پوشهٔ موقت ناموفق بود: {$tmp}\n";
    echo "\nنتیجه: 0 موفق، 1 ناموفق\n";
    exit(1);
}

$examplePath = $tmp . DIRECTORY_SEPARATOR . 'config.example.php';
$configPath  = $tmp . DIRECTORY_SEPARATOR . 'config.php';
copy(__DIR__ . '/../config.example.php', $examplePath);

// ------------------------------------------------------------------
echo "\n▶ ساخت config.php از قالب و نوشتن سه مقدار نصب\n";
// ------------------------------------------------------------------

$result = runConfigure($configPath, $examplePath, [
    '--bot-token=123456:ABCDEFGH123456',
    '--admin-id=123456789',
    '--base-url=https://shop.example.com/',
]);

check('کد خروج صفر است', $result['rc'] === 0, $result['out']);
check('config.php ساخته شد', is_file($configPath));

$data = is_file($configPath) ? include $configPath : null;
check('config.php یک آرایهٔ معتبر است', is_array($data));

if (is_array($data)) {
    check('توکن نوشته شد', $data['bot_token'] === '123456:ABCDEFGH123456', var_export($data['bot_token'] ?? null, true));
    check('آیدی سوپرادمین نوشته شد', $data['super_admins'] === [123456789]);
    check('اسلش انتهای آدرس وب سایت حذف شد', $data['base_url'] === 'https://shop.example.com', $data['base_url']);
    check('نام کاربری ربات هنوز خالی است', $data['bot_username'] === '');

    // ⚠️ مهم‌ترین بررسی این فایل
    check(
        'panel.base_url دست‌نخورده ماند',
        $data['panel']['base_url'] === 'https://us.api-system.top',
        'آدرس پنل با آدرس سایت جایگزین شد!'
    );

    check('crypto_key تولید شد', strlen((string) $data['crypto_key']) >= 32);
    check('crypto_key مقدار نمونه نیست', !str_contains((string) $data['crypto_key'], 'CHANGE-THIS'));
    check('webhook_secret تولید شد', $data['webhook_secret'] !== 'CHANGE-THIS-RANDOM-SECRET');
    check('webhook_secret با admin_webhook_secret فرق دارد', $data['webhook_secret'] !== $data['admin_webhook_secret']);
    check('کلید توکن مدیریتی خالی ماند', $data['admin_bot_token'] === '');

    check('store.card_number دست‌نخورده ماند', $data['store']['card_number'] === '');
    check('panel.owner_username دست‌نخورده ماند', $data['panel']['owner_username'] === '');
    check('nowpayments.api_key دست‌نخورده ماند', $data['nowpayments']['api_key'] === '');

    $firstCryptoKey = (string) $data['crypto_key'];
}

check('قبل از نوشتن، پشتیبان گرفته شد', is_file($configPath . '.bak'));

// ------------------------------------------------------------------
echo "\n▶ اجرای دوباره نباید چیزی را خراب کند\n";
// ------------------------------------------------------------------

$result = runConfigure($configPath, $examplePath, [
    '--bot-token=123456:ABCDEFGH123456',
    '--admin-id=123456789',
    '--base-url=https://shop.example.com/',
]);

check('کد خروج صفر است', $result['rc'] === 0, $result['out']);

$again = include $configPath;
check('crypto_key عوض نشد', is_array($again) && (string) $again['crypto_key'] === ($firstCryptoKey ?? ''));
check(
    'panel.base_url باز هم دست‌نخورده است',
    is_array($again) && $again['panel']['base_url'] === 'https://us.api-system.top'
);

// ------------------------------------------------------------------
echo "\n▶ مقدار نامعتبر باید رد شود، نه بی‌صدا پذیرفته شود\n";
// ------------------------------------------------------------------

$before = (string) file_get_contents($configPath);

$result = runConfigure($configPath, $examplePath, ['--bot-token=not-a-token']);
check('توکن بدون کالون رد شد', $result['rc'] === 1, $result['out']);
check('فایل تغییری نکرد', (string) file_get_contents($configPath) === $before);

$result = runConfigure($configPath, $examplePath, ['--base-url=example.com']);
check('آدرس بدون پروتکل رد شد', $result['rc'] === 1, $result['out']);

$result = runConfigure($configPath, $examplePath, ['--admin-id=ali']);
check('آیدی غیرعددی رد شد', $result['rc'] === 1, $result['out']);

$result = runConfigure($configPath, $examplePath, ['--base-url=https://shop.example.com/path with space']);
check('آدرس با فاصله رد شد', $result['rc'] === 1, $result['out']);

// ------------------------------------------------------------------
echo "\n▶ آپدیت گزینشی فقط همان کلید را عوض می‌کند\n";
// ------------------------------------------------------------------

$result = runConfigure($configPath, $examplePath, ['--bot-username=MyPasargadBot']);
check('کد خروج صفر است', $result['rc'] === 0, $result['out']);

$updated = include $configPath;
check('bot_username نوشته شد', is_array($updated) && $updated['bot_username'] === 'MyPasargadBot');
check('توکن به‌جامانده', is_array($updated) && $updated['bot_token'] === '123456:ABCDEFGH123456');
check('آدرس وب سایت به‌جامانده', is_array($updated) && $updated['base_url'] === 'https://shop.example.com');

// ------------------------------------------------------------------
// پاک‌سازی
// ------------------------------------------------------------------

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tmp);

echo "\nنتیجه: {$passed} موفق، {$failed} ناموفق\n";
exit($failed === 0 ? 0 : 1);
