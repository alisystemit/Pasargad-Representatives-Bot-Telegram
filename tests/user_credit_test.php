<?php

declare(strict_types=1);

/**
 * تست ساخت و تمدید کاربر با اعتبار خریداری‌شده (بستهٔ user_credit).
 *
 * هدف: اطمینان از اینکه اعتبار درست کسر می‌شود، پنل فراخوانی می‌شود و
 * اگر پنل خطا دهد اعتبار دست‌نخورده می‌ماند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserProvisioner;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Crypto;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$panel   = new FakePanelClient();
$users   = new UserRepository($db);
$service = new UserProvisioner($users, $panel);

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

// ------------------------------------------------------------------
echo "\n▶ آماده‌سازی کاربر با اعتبار\n";
// ------------------------------------------------------------------

$userId = $users->upsertByTelegram(4242, [
    'telegram_id'       => 4242,
    'panel_username'    => 'reseller1',
    'panel_password'    => Crypto::encrypt('reseller-pass'),
    'panel_status'      => 'active',
    'user_credit'       => 1073741824 * 100,   // 100 گیگابایت اعتبار
    'user_credit_expire' => time() + 30 * 86400,
])['id'];

$user = $users->findById($userId);
check('کاربر با ۱۰۰ گیگ اعتبار ساخته شد', (int) $user['user_credit'] === 1073741824 * 100);

$panel->addAdmin('reseller1');

// ------------------------------------------------------------------
echo "\n▶ ساخت کاربر جدید\n";
// ------------------------------------------------------------------

$result = $service->createUser($user, 'customer_a', 20, 30);
check('ساخت کاربر موفق بود', $result['ok'], (string) $result['message']);
check('نام کاربری برگشت داده شد', ($result['username'] ?? '') === 'customer_a');
check('پنل فراخوانی شد', count($panel->created) === 1);
check('حجم ارسالی درست است', (int) ($panel->created[0]['data_limit'] ?? 0) === 1073741824 * 20);
check('expire ارسالی در آینده است', (int) ($panel->created[0]['expire'] ?? 0) > time());
check('وضعیت active ارسال شد', ($panel->created[0]['status'] ?? '') === 'active');

$after = $users->findById($userId);
check('اعتبار ۲۰ گیگ کسر شد', (int) $after['user_credit'] === 1073741824 * 80, 'credit=' . $after['user_credit']);

// ------------------------------------------------------------------
echo "\n▶ اعتبارسنجی ورودی\n";
// ------------------------------------------------------------------

$before = (int) $users->findById($userId)['user_credit'];

check('نام کاربری نامعتبر رد شد', !$service->createUser($user, 'bad name!', 5, 30)['ok']);
check('حجم صفر رد شد', !$service->createUser($user, 'ok_name', 0, 30)['ok']);
check('مدت صفر رد شد', !$service->createUser($user, 'ok_name', 5, 0)['ok']);
check('اعتبار کسر نشد', (int) $users->findById($userId)['user_credit'] === $before);

// حجم بیش از اعتبار
$tooBig = $service->createUser($user, 'too_big', 500, 30);
check('حجم بیش از اعتبار رد شد', !$tooBig['ok']);
check('پیام اعتبار ناکافی دارد', str_contains((string) $tooBig['message'], 'اعتبار'), (string) $tooBig['message']);
check('اعتبار دست‌نخورده ماند', (int) $users->findById($userId)['user_credit'] === $before);

// کاربر بدون اتصال پنل
$orphan = $users->upsertByTelegram(5555, ['telegram_id' => 5555])['id'];
$orphanRow = $users->findById($orphan);
$orphanResult = $service->createUser($orphanRow, 'nope', 10, 30);
check('کاربر بدون اتصال رد شد', !$orphanResult['ok']);
check('پیام «وارد شوید» دارد', str_contains((string) $orphanResult['message'], 'وارد'), (string) $orphanResult['message']);

// ------------------------------------------------------------------
echo "\n▶ خطای پنل هنگام ساخت (اعتبار نباید کسر شود)\n";
// ------------------------------------------------------------------

$before = (int) $users->findById($userId)['user_credit'];
$panel->createUserError = 'سرور پنل در دسترس نیست';

$failResult = $service->createUser($user, 'will_fail', 10, 30);
check('ساخت ناموفق برگزار', !$failResult['ok']);
check('پیام خطای پنل دارد', str_contains((string) $failResult['message'], 'سرور پنل'), (string) $failResult['message']);
check('اعتبار در خطا کسر نشد', (int) $users->findById($userId)['user_credit'] === $before, 'credit=' . $users->findById($userId)['user_credit']);

$panel->createUserError = '';

// ------------------------------------------------------------------
echo "\n▶ تمدید کاربر\n";
// ------------------------------------------------------------------

$extendResult = $service->extendUser($user, 'customer_a', 10, 15);
check('تمدید موفق بود', $extendResult['ok'], (string) $extendResult['message']);
check('حجم روی پنل افزایش یافت', (int) ($panel->existingUsers['customer_a']['data_limit'] ?? 0) === 1073741824 * 30, 'limit=' . ($panel->existingUsers['customer_a']['data_limit'] ?? 'n/a'));
check('اعتبار ۱۰ گیگ کسر شد', (int) $users->findById($userId)['user_credit'] === 1073741824 * 70, 'credit=' . $users->findById($userId)['user_credit']);

$firstExpire = (int) $panel->existingUsers['customer_a']['expire'];
$extend2 = $service->extendUser($user, 'customer_a', 0, 15);
$secondExpire = (int) $panel->existingUsers['customer_a']['expire'];
check('تمدید زمانی انباشته شد', $secondExpire > $firstExpire, "first=$firstExpire second=$secondExpire");
check('تمدید بدون حجم اعتبار کسر نکرد', (int) $users->findById($userId)['user_credit'] === 1073741824 * 70);

// ------------------------------------------------------------------
echo "\n▶ خطای پنل هنگام تمدید\n";
// ------------------------------------------------------------------

$before = (int) $users->findById($userId)['user_credit'];
$panel->modifyUserError = 'خطای داخلی';

$failExtend = $service->extendUser($user, 'customer_a', 5, 30);
check('تمدید ناموفق برگزار', !$failExtend['ok']);
check('اعتبار در خطای تمدید کسر نشد', (int) $users->findById($userId)['user_credit'] === $before);
$panel->modifyUserError = '';

// ------------------------------------------------------------------
echo "\n▶ لیست کاربران پنل\n";
// ------------------------------------------------------------------

$listResult = $service->listUsers($user);
check('لیست کاربران دریافت شد', $listResult['ok']);
check('کاربر ساخته‌شده در لیست هست', count((array) $listResult['users']) >= 1);

// ------------------------------------------------------------------
echo "\n▶ نتیجهٔ نهایی\n";
// ------------------------------------------------------------------

$final = $users->findById($userId);
echo "  اعتبار اولیه: 100 GB\n";
echo "  مصرف‌شده: ساخت 20 GB + تمدید 10 GB = 30 GB\n";
echo "  باقی‌مانده: " . round((int) $final['user_credit'] / 1073741824) . " GB\n";
check('باقی‌مانده ۷۰ گیگ است', (int) $final['user_credit'] === 1073741824 * 70);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);