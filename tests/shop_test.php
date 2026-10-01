<?php

declare(strict_types=1);

/**
 * تست یکپارچهٔ منطق خرید و اعمال خودکار بسته روی پنل.
 *
 * هیچ درخواست شبکه‌ای انجام نمی‌شود؛ از FakePanelClient و دیتابیس در حافظه استفاده می‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$panel       = new FakePanelClient();
$orders      = new OrderRepository($db);
$users       = new UserRepository($db);
$settings    = new Settings($db);
$packages    = new PackageRepository($db);
$provisioner = new Provisioner($panel, $orders, $users, $settings);

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
echo "\n▶ آماده‌سازی داده\n";
// ------------------------------------------------------------------

$settings->setMany(['auto_apply' => '1', 'shop_opened' => '1']);
check('تنظیمات ذخیره و خوانده می‌شوند', $settings->bool('auto_apply', false));

$quotaId = $packages->create([
    'title'         => 'بستهٔ حجمی ۱۰۰ گیگ',
    'kind'          => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb'     => 100,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'sort_order'    => 1,
    'is_active'     => true,
]);

$creditId = $packages->create([
    'title'         => 'بستهٔ اعتبار کاربر',
    'kind'          => PackageRepository::KIND_USER_CREDIT,
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 400000,
    'sort_order'    => 2,
    'is_active'     => true,
]);

check('بستهٔ حجمی ایجاد شد', $quotaId > 0);
check('بستهٔ اعتبار کاربر ایجاد شد', $creditId > 0);
check('slug یکتا ساخته شد', $packages->findBySlug('بسته-حجمی-100-گیگ') === null && count($packages->activePackages()) === 2);

$panel->addAdmin('rep1', ['data_limit' => 1073741824 * 10, 'used_traffic' => 1073741824 * 5]);  // 10GB limit, 5GB used

$userId = $users->upsertByTelegram(123456, [
    'telegram_id'    => 123456,
    'username'       => 'rep_one',
    'panel_username' => 'rep1',
    'panel_password' => Crypto::encrypt('panel-pass'),
    'panel_status'   => 'active',
    'panel_data_limit' => 1073741824 * 10,
])['id'];

$user = $users->findById($userId);
check('کاربر ایجاد شد', $user !== null && (int) $user['id'] > 0);
check('پسورد رمزنگاری‌شده ذخیره شد', (string) $user['panel_password'] !== 'panel-pass');
check('رمزگشایی پسورد کار می‌کند', Crypto::decrypt((string) $user['panel_password']) === 'panel-pass');

// ------------------------------------------------------------------
echo "\n▶ اعمال بستهٔ حجمی (افزایش data_limit روی پنل)\n";
// ------------------------------------------------------------------

$pkg = $packages->find($quotaId);
$order = $orders->create($userId, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => (float) $pkg['volume_gb'],
    'duration_days' => (int) $pkg['duration_days'],
    'price_toman'   => (int) $pkg['price_toman'],
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);
check('سفارش paid ایجاد شد', (string) $order['status'] === OrderRepository::STATUS_PAID);
check('کد سفارش ساخته شد', str_starts_with((string) $order['code'], 'ORD-'));

$result = $provisioner->provision($order);
check('اجرای بسته موفق بود', $result['ok'], $result['message']);

$after = $orders->find((int) $order['id']);
check('وضعیت سفارش applied شد', (string) $after['status'] === OrderRepository::STATUS_APPLIED);
check('before_limit ثبت شد (10GB)', (int) $after['before_limit'] === 1073741824 * 10);
check('after_limit = 10+100GB', (int) $after['after_limit'] === 1073741824 * 110);
check('applied_volume = 100GB', (int) $after['applied_volume'] === 1073741824 * 100);
check('پنل modify فراخوانی شد', $panel->modifyCalls === 1);
check('data_limit جدید روی پنل', (int) $panel->admins['rep1']['data_limit'] === 1073741824 * 110);

$userAfter = $users->findById($userId);
check('granted_volume به‌روز شد', (int) $userAfter['granted_volume'] === 1073741824 * 100);
check('granted_expire_at تنظیم شد', $userAfter['granted_expire_at'] !== null && (int) $userAfter['granted_expire_at'] > time());
check('sync از پنل انجام شد', (int) $userAfter['panel_data_limit'] === 1073741824 * 110);

$logs = $orders->provisionLogs((int) $order['id']);
check('لاگ اعمال ثبت شد', count($logs) >= 1);

// ------------------------------------------------------------------
echo "\n▶ اعمال بستهٔ اعتبار کاربر (user_credit)\n";
// ------------------------------------------------------------------

$creditPkg = $packages->find($creditId);
$creditOrder = $orders->create($userId, [
    'package_id'    => $creditId,
    'package_title' => $creditPkg['title'],
    'kind'          => $creditPkg['kind'],
    'volume_gb'     => (float) $creditPkg['volume_gb'],
    'duration_days' => (int) $creditPkg['duration_days'],
    'price_toman'   => (int) $creditPkg['price_toman'],
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$result2 = $provisioner->provision($creditOrder);
check('اجرای بستهٔ اعتبار موفق بود', $result2['ok'], $result2['message']);

$userCredit = $users->findById($userId);
check('اعتبار کاربر اضافه شد (50GB)', (int) $userCredit['user_credit'] === 1073741824 * 50);
check('user_credit_expire تنظیم شد', $userCredit['user_credit_expire'] !== null);

// ------------------------------------------------------------------
echo "\n▶ رفتارهای لبه: تکرار، حجم نامحدود، کاربر قطع‌شده\n";
// ------------------------------------------------------------------

// اعمال مجدد همان سفارش نباید حجم را دوباره اضافه کند
$replay = $provisioner->provision($order);
check('اعمال تکراری نادیده گرفته شد', !$replay['ok'], 'نباید حجم دوباره اضافه شود');
check('تعداد فراخوانی modify تغییر نکرد', $panel->modifyCalls === 1);

// کاربر بدون اتصال پنل
$orphanId = $users->upsertByTelegram(999999, ['telegram_id' => 999999, 'panel_status' => 'pending'])['id'];
$orphanOrder = $orders->create($orphanId, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 10,
    'duration_days' => 30,
    'price_toman'   => 100000,
    'status'        => OrderRepository::STATUS_PAID,
]);
$orphanResult = $provisioner->provision($orphanOrder);
check('کاربر قطع‌شده ناموفق بود', !$orphanResult['ok']);
check('پیام مناسب برای کاربر قطع‌شده', str_contains($orphanResult['message'], 'قطع'), $orphanResult['message']);
check('سفارش failed شد', (string) $orders->find((int) $orphanOrder['id'])['status'] === OrderRepository::STATUS_FAILED);

// کاربر مسدود
$blockedId = $users->upsertByTelegram(555555, [
    'telegram_id'    => 555555,
    'panel_username' => 'blocked_admin',
    'panel_password' => Crypto::encrypt('pass'),
    'panel_status'   => 'active',
])['id'];
$users->setBlocked($blockedId, true, 'test');
$blockedOrder = $orders->create($blockedId, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 10,
    'duration_days' => 30,
    'price_toman'   => 100000,
    'status'        => OrderRepository::STATUS_PAID,
]);
$blockedResult = $provisioner->provision($blockedOrder);
check('کاربر مسدود سرویس نگرفت', !$blockedResult['ok']);

// ------------------------------------------------------------------
echo "\n▶ تلاش مجدد در خطای موقت پنل\n";
// ------------------------------------------------------------------

$panel->modifyError = 'خطای داخلی سرور';
$panel->modifyCalls = 0;

$retryUser = $users->upsertByTelegram(777777, [
    'telegram_id'    => 777777,
    'panel_username' => 'retry_admin',
    'panel_password' => Crypto::encrypt('pass'),
    'panel_status'   => 'active',
])['id'];
$panel->addAdmin('retry_admin', ['data_limit' => 0, 'used_traffic' => 0]);

$retryOrder = $orders->create($retryUser, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 20,
    'duration_days' => 30,
    'price_toman'   => 200000,
    'status'        => OrderRepository::STATUS_PAID,
]);

$retryResult = $provisioner->provision($retryOrder);
check('خطای 500 ناموفق برگرداند', !$retryResult['ok']);
$retryRow = $orders->find((int) $retryOrder['id']);
check('سفارش failed شد', (string) $retryRow['status'] === OrderRepository::STATUS_FAILED);
check('پیام خطا ذخیره شد', str_contains((string) $retryRow['error'], 'سرور'));
check('زمان تلاش مجدد ست شد', $retryRow['next_attempt_at'] !== null && (int) $retryRow['next_attempt_at'] > time());
check('attempt شمارش شد', (int) $retryRow['attempts'] === 1);

// سفارش در backoff است و نباید زودتر از موعد در صف قرار گیرد
$pending = $orders->pendingApply(10);
$pendingIds = array_map(static fn (array $o): int => (int) $o['id'], $pending);
check('سفارش در backoff از صف کنار گذاشته شد', !in_array((int) $retryOrder['id'], $pendingIds, true));
check('سفارش در backoff زمان‌بندی شد', (int) $orders->find((int) $retryOrder['id'])['next_attempt_at'] > time());

// شبیه‌سازی گذشت زمان backoff و رفع خطا
$panel->modifyError = '';
$db->run('UPDATE orders SET next_attempt_at = ? WHERE id = ?', [time() - 1, (int) $retryOrder['id']]);

$queueResult = $provisioner->processQueue(10);
check('پردازش صف انجام شد', $queueResult['processed'] >= 1);
$retryRow2 = $orders->find((int) $retryOrder['id']);
check('سفارش پس از رفع خطا applied شد', (string) $retryRow2['status'] === OrderRepository::STATUS_APPLIED, 'status=' . $retryRow2['status']);
check('حجم ۲۰ گیگ اعمال شد', (int) $retryRow2['applied_volume'] === 1073741824 * 20, 'vol=' . $retryRow2['applied_volume']);

// ------------------------------------------------------------------
echo "\n▶ خطای احراز هویت پنل\n";
// ------------------------------------------------------------------

$panel->modifyError = '';
$panel->loginError  = 'نام کاربری نامعتبر';

$authUser = $users->upsertByTelegram(888888, [
    'telegram_id'    => 888888,
    'panel_username' => 'expired_admin',
    'panel_password' => Crypto::encrypt('wrong-pass'),
    'panel_status'   => 'active',
])['id'];
$panel->addAdmin('expired_admin', ['data_limit' => 0, 'used_traffic' => 0]);

$authOrder = $orders->create($authUser, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 5,
    'duration_days' => 30,
    'price_toman'   => 50000,
    'status'        => OrderRepository::STATUS_PAID,
]);

$authResult = $provisioner->provision($authOrder);
check('خطای 401 ناموفق برگرداند', !$authResult['ok']);
$authRow = $orders->find((int) $authOrder['id']);
check('سفارش failed شد', (string) $authRow['status'] === OrderRepository::STATUS_FAILED);
$authUserRow = $users->findById($authUser);
check('کاربر revoked شد', (string) $authUserRow['panel_status'] === 'revoked');
check('پیام «دوباره وارد شود» دارد', str_contains($authResult['message'], 'دوباره'), $authResult['message']);
$panel->loginError = '';

// ------------------------------------------------------------------
echo "\n▶ آمار و پرداخت‌ها\n";
// ------------------------------------------------------------------

$stats = $orders->stats();
check('آمار سفارش‌ها محاسبه شد', $stats['total'] >= 5, json_encode($stats));
check('درآمد محاسبه شد', $stats['revenue'] > 0);

$users->refreshOrderStats($userId);
$userStats = $users->findById($userId);
check('شمارش سفارش کاربر', (int) $userStats['orders_count'] === 2);
check('مجموع پرداخت کاربر', (int) $userStats['total_paid'] === 500000 + 400000);

$payId = $orders->createPayment((int) $order['id'], [
    'method'       => 'nowpayments',
    'amount_toman' => 500000,
    'external_id'  => 'np-test-1',
    'status'       => 'confirmed',
]);
check('پرداخت ثبت شد', $payId > 0);
check('یافتن پرداخت با شناسهٔ خارجی', $orders->findPaymentByExternal('np-test-1') !== null);

$awaiting = $orders->awaitingReview();
check('فهرست رسیدهای در انتظار', is_array($awaiting));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);