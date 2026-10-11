<?php

declare(strict_types=1);

/**
 * تست مایگریشن.
 *
 * تمرکز روی یک چیز است: **اجرای مایگریشن باید مستقل از وضعیت جدول
 * `migrations` درست کار کند.**
 *
 * قبلاً `runPending()` فقط «duplicate column» را بی‌خطر می‌دانست، آن هم
 * *به شرطی که رکوردِ همان نسخه در جدول باشد*. یعنی اگر جدول `migrations`
 * از رابطهٔ همگامی خارج می‌شد — که در عمل پیش می‌آید: بکاپ ناقص، انتقال
 * دیتابیس، یا همان باگی که قبلاً باعث شد `users.coupon_code` گم شود —
 * اجرای بعدی با خطای SQLite می‌مرد و کل ربات ۵۰۰ می‌داد.
 */

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Support\Db;
use Pasargad\Support\Migrator;

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    echo "  ❌ {$label}\n";

    if ($detail !== '') {
        echo '     ↳ ' . mb_substr($detail, 0, 240) . "\n";
    }
}

/**
 * یک اتصال SQLite مستقل می‌سازد تا دیتابیس واقعی دست‌نخورده بماند.
 *
 * `Db` سازندهٔ private دارد، پوزیشنال و رفلکشن لازم است.
 *
 * @return array{0: Db, 1: PDO}
 */
function freshDb(string $name): array
{
    $path = sys_get_temp_dir() . '/' . $name . '_' . getmypid() . '.sqlite';
    @unlink($path);

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $ref    = new ReflectionClass(Db::class);
    $db     = $ref->newInstanceWithoutConstructor();
    $fields = ['pdo' => $pdo, 'txDepth' => 0];

    foreach ($fields as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($db, $value);
    }

    return [$db, $pdo];
}

/** @return array<int, string> */
function columns(Db $db, string $table): array
{
    return array_column($db->all('PRAGMA table_info(' . $table . ')'), 'name');
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  مایگریشن — اجرای تکرارپذیر و مقاوم در برابر ناهماهنگی\n";
echo str_repeat('═', 62) . "\n";

// ------------------------------------------------------------------
echo "\n▶ ۱) نصب از صفر\n";
// ------------------------------------------------------------------

[$db, $pdo] = freshDb('mig_fresh');
$migrator   = new Migrator($db);

$migrator->migrate();

$userCols = columns($db, 'users');

check('جدول users ساخته شد', $userCols !== []);
check('ستون‌های پروفایل اضافه شدند', array_diff(['email', 'phone', 'notifications_enabled', 'digest_emails'], $userCols) === [], implode(',', $userCols));
check('جدول panels ساخته شد', $db->tableExists('panels'));
check('جدول orders ساخته شد', $db->tableExists('orders'));
check('جدول packages ساخته شد', $db->tableExists('packages'));

$applied = $migrator->appliedMigrations();

check('همهٔ نسخه‌ها ثبت شدند', $applied !== [], implode(',', $applied));

// ------------------------------------------------------------------
echo "\n▶ ۲) اجرای دوباره روی دیتابیس سالم\n";
// ------------------------------------------------------------------

$migrator->migrate();
check('اجرای دوباره خطا نداد', true);
check('نسخهٔ جدیدی اضافه نشد', $migrator->appliedMigrations() === $applied);

// ------------------------------------------------------------------
echo "\n▶ ۳) رکوردِ یک نسخه پاک شود ولی ستون‌هایش سر جایشان باشند\n";
// ------------------------------------------------------------------
//
// این همان حالتی است که روی سرور شما رخ داده بود: دیتابیس می‌گفت نسخهٔ
// جدید اعمال شده، ولی خودِ ستون‌ها نبودند — یا برعکس.

$pdo->exec("DELETE FROM migrations WHERE name = '013_user_profile'");

$migrator->migrate();

check('بدون خطای duplicate column اجرا شد', true);
check('ستون همچنان هست', in_array('email', columns($db, 'users'), true));
check(
    'نسخه دوباره ثبت شد',
    $db->first("SELECT name FROM migrations WHERE name = '013_user_profile'") !== null
);

// ------------------------------------------------------------------
echo "\n▶ ۴) کل جدول migrations خالی شود (بدترین حالت)\n";
// ------------------------------------------------------------------

$pdo->exec('DELETE FROM migrations');

$migrator->migrate();

check('بدون خطا اجرا شد', true);
check('اسکیما کامل است', in_array('email', columns($db, 'users'), true));
check('نسخه‌ها دوباره ثبت شدند', $migrator->appliedMigrations() !== []);

// ------------------------------------------------------------------
echo "\n▶ ۵) دادهٔ موجود نباید آسیب ببیند\n";
// ------------------------------------------------------------------

$db->insert('users', [
    'telegram_id' => 555555,
    'first_name'  => 'رضا محمدی',
    'created_at'  => time(),
    'updated_at'  => time(),
]);

$migrator->migrate();

$row = $db->first('SELECT first_name, email FROM users WHERE telegram_id = ?', [555555]);

check('کاربر قدیمی سالم ماند', ($row['first_name'] ?? '') === 'رضا محمدی');
check('ستون جدید مقدار پیش‌فرض دارد', array_key_exists('email', (array) $row));

// ------------------------------------------------------------------
echo "\n▶ ۶) پروفایل: فقط ستون‌های مجاز نوشته می‌شوند\n";
// ------------------------------------------------------------------

[$db2, $pdo2] = freshDb('mig_profile');
$m2 = new Migrator($db2);
$m2->migrate();

$users = new Pasargad\Store\UserRepository($db2);

$db2->insert('users', [
    'telegram_id'       => 42,
    'first_name'        => 'قبل',
    'created_at'        => time(),
    'updated_at'        => time(),
    'is_blocked'        => 0,
    'orders_count'      => 0,
    'total_paid'        => 0,
]);

$userId = (int) $db2->first('SELECT id FROM users WHERE telegram_id = ?', [42])['id'];

check('نوشتن ستون مجاز کار می‌کند', $users->updateField($userId, 'first_name', 'بعد'));
check('مقدار واقعاً عوض شد', (string) $db2->first('SELECT first_name FROM users WHERE id = ?', [$userId])['first_name'] === 'بعد');

check(
    'ستون غیرمجاز رد می‌شود (راه تزریق SQL)',
    $users->updateField($userId, 'telegram_id; DROP TABLE users; --', 'x') === false
);

check('جدول users هنوز سر جایش است', $db2->tableExists('users'));
check(
    'مقدار غیرمجاز تغییر نکرد',
    (int) $db2->first('SELECT telegram_id FROM users WHERE id = ?', [$userId])['telegram_id'] === 42
);

@unlink(sys_get_temp_dir() . '/mig_fresh_' . getmypid() . '.sqlite');
@unlink(sys_get_temp_dir() . '/mig_profile_' . getmypid() . '.sqlite');

echo "\n" . str_repeat('─', 62) . "\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);
