<?php

declare(strict_types=1);

/**
 * تست خشک هستهٔ پروژه: تنظیمات، دیتابیس، مایگریشن، رمزنگاری و ابزارهای کمکی.
 * هیچ درخواستی به شبکه ارسال نمی‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;
use Pasargad\Support\Str;

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "\n▶ تنظیمات\n";
check('config بارگذاری شد', Config::isLoaded());
check('دسترسی به تنظیم تودرتو', Config::str('panel.base_url') !== '');
check('پنجرهٔ آرایه‌ای', is_array(Config::arr('super_admins')));
check('کلید ناموجود مقدار پیش‌فرض می‌گیرد', Config::str('nope.nothing', 'fallback') === 'fallback');

echo "\n▶ ابزارهای کمکی\n";
check('تبدیل ارقام فارسی', Str::toEnglishDigits('۱۲۳') === '123');
check('قالب‌بندی اعداد فارسی', Str::toPersianDigits('1405') === '۱۴۰۵');
check('تبدیل گیگابایت به بایت', Str::gbToBytes(1) === 1073741824);
check('قالب‌بندی حجم', str_contains(Str::formatBytes(1073741824), 'گیگابایت'));
check('حجم نامحدود', Str::formatBytes(null) === 'نامحدود');
check('کد سفارش یکتا', (bool) preg_match('/^ORD-[A-Z0-9]{6}$/', Str::orderCode()));
check('یوزرنیم نامعتبر رد می‌شود', !Str::isValidPanelUsername('a b'));
check('یوزرنیم معتبر', Str::isValidPanelUsername('admin_1'));

echo "\n▶ رمزنگاری\n";
$secret = 'Test@Passw0rd-2026';
$enc = Crypto::encrypt($secret);
check('رمزنگاری بدون خطا', $secret !== $enc);
check('متن رمزشده پسورد را ندارد', !str_contains($enc, $secret));
check('رمزگشایی درست', Crypto::decrypt($enc) === $secret);
check('متن خام قابل رمزگشایی نیست', (static function () {
    try {
        Crypto::decrypt('plain-text');
        return false;
    } catch (\Throwable) {
        return true;
    }
})());

echo "\n▶ دیتابیس و مایگریشن\n";
$db = Db::instance();
check('اتصال SQLite برقرار شد', $db instanceof Db);
check('WAL فعال است', $db->pdo()->query('PRAGMA journal_mode')->fetchColumn() === 'wal');

$migrator = new Migrator($db);
$migrator->migrate();
$applied = $migrator->appliedMigrations();

foreach (['users', 'packages', 'orders', 'payments', 'provision_logs', 'settings'] as $table) {
    check("جدول {$table} ساخته شد", $db->tableExists($table));
}

$migrator->migrate();
check('اجرای دوبارهٔ مایگریشن بی‌اثر است', count($migrator->appliedMigrations()) === count($applied));

echo "\n▶ کوئری‌های پایه\n";
$db->run('DELETE FROM packages');
$pkgId = $db->insert('packages', [
    'slug'          => 'test-pkg',
    'title'         => 'بستهٔ آزمایشی',
    'kind'          => 'panel_quota',
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 250000,
    'sort_order'    => 1,
    'is_active'     => 1,
    'created_at'    => time(),
    'updated_at'    => time(),
]);
check('درج بسته', $pkgId > 0);

$pkg = $db->first('SELECT * FROM packages WHERE slug = ?', ['test-pkg']);
check('خواندن بسته', ($pkg['volume_gb'] ?? 0) == 50.0);
check('قیمت درست ذخیره شده', (int) $pkg['price_toman'] === 250000);

$db->update('packages', ['price_toman' => 300000], ['id' => $pkgId]);
check('به‌روزرسانی بسته', (int) $db->value('SELECT price_toman FROM packages WHERE id = ?', [$pkgId]) === 300000);

$userId = $db->insert('users', [
    'telegram_id' => 111111111,
    'username'    => 'rep_test',
    'panel_username' => 'rep_admin',
    'panel_password' => Crypto::encrypt('secret-pass'),
    'panel_status' => 'active',
    'created_at'  => time(),
    'updated_at'  => time(),
]);
check('درج کاربر', $userId > 0);

$orderId = $db->insert('orders', [
    'code'          => Str::orderCode(),
    'user_id'       => $userId,
    'package_id'    => $pkgId,
    'package_title' => 'بستهٔ آزمایشی',
    'kind'          => 'panel_quota',
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 300000,
    'status'        => 'paid',
    'created_at'    => time(),
    'updated_at'    => time(),
]);
check('درج سفارش', $orderId > 0);

$paid = $db->first('SELECT * FROM orders WHERE user_id = ? AND status = ?', [$userId, 'paid']);
check('کوئری سفارش پرداخت‌شده', $paid !== null);

$db->insert('payments', [
    'order_id'     => $orderId,
    'method'       => 'nowpayments',
    'amount_toman' => 300000,
    'amount_usd'   => 3.0,
    'external_id'  => 'np-12345',
    'status'       => 'confirmed',
    'created_at'   => time(),
    'updated_at'   => time(),
]);
check('درج پرداخت', $db->count('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$orderId]) === 1);

echo "\n▶ تراکنش\n";
$db->transaction(function (Db $tx): void {
    $tx->update('packages', ['is_active' => 0], ['slug' => 'test-pkg']);
});
check('کامیت تراکنش', (int) $db->value('SELECT is_active FROM packages WHERE slug = ?', ['test-pkg']) === 0);

$rolledBack = false;
try {
    $db->transaction(function (Db $tx): void {
        $tx->update('packages', ['is_active' => 1], ['slug' => 'test-pkg']);
        throw new \RuntimeException('test');
    });
} catch (\RuntimeException) {
    $rolledBack = true;
}
check('رول‌بک تراکنش', $rolledBack && (int) $db->value('SELECT is_active FROM packages WHERE slug = ?', ['test-pkg']) === 0);

echo "\n▶ پاک‌سازی داده‌های تست\n";
$db->run('DELETE FROM payments');
$db->run('DELETE FROM orders');
$db->run('DELETE FROM users');
$db->run('DELETE FROM packages');
check('جدول‌ها خالی شدند', $db->count('SELECT COUNT(*) FROM orders') === 0);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);