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
require_once __DIR__ . '/Fixture.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

// اکانت سازندهٔ پنل را فعال می‌کنیم تا بتوان پنل نمایندگی ساخت.
Config::set('panel.owner_username', 'root');
Config::set('panel.owner_password', 'rootpass');

$panel       = new FakePanelClient();
$orders      = new OrderRepository($db);
$users       = new UserRepository($db);
$panels      = new PanelRepository($db);
$settings    = new Settings($db);
$packages    = new PackageRepository($db);
$provisioner = new Provisioner($panel, $orders, $users, $settings, $panels);

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
    'title'         => 'بستهٔ شارژ ۱۰۰ گیگ',
    'kind'          => PackageRepository::KIND_TOPUP,
    'volume_gb'     => 100,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'sort_order'    => 1,
    'is_active'     => true,
]);

$agencyId = $packages->create([
    'title'         => 'پنل نمایندگی ۲۰ گیگ',
    'kind'          => PackageRepository::KIND_AGENCY,
    'volume_gb'     => 20,
    'duration_days' => 30,
    'price_toman'   => 400000,
    'sort_order'    => 2,
    'is_active'     => true,
]);

check('بستهٔ شارژ ایجاد شد', $quotaId > 0);
check('بستهٔ پنل نمایندگی ایجاد شد', $agencyId > 0);
check('دو بستهٔ فعال داریم', count($packages->activePackages()) === 2);

[$user, $userPanel] = makeRep('rep1', $panel, $users, $panels, [
    'telegram_id' => 123456,
    'username'    => 'rep_one',
    'data_limit'  => 1073741824 * 10,
    'used_traffic'=> 1073741824 * 5,
    'password'    => 'panel-pass',
]);

$userId = (int) $user['id'];
check('کاربر ایجاد شد', $user !== null && $userId > 0);
check('پنل به کاربر وصل شد', $userPanel !== null);
check('پسورد رمزنگاری‌شده ذخیره شد',
    (string) $userPanel['panel_password'] !== 'panel-pass');
check('رمزگشایی پسورد کار می‌کند',
    $panels->plainPassword($userPanel) === 'panel-pass');
check('پنل برای کاربر قابل بازیابی است',
    $panels->findForUser((int) $userPanel['id'], $userId) !== null);

// ------------------------------------------------------------------
echo "\n▶ اعمال بستهٔ شارژ (افزایش data_limit روی پنل موجود)\n";
// ------------------------------------------------------------------

$pkg = $packages->find($quotaId);
$order = $orders->create($userId, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => (float) $pkg['volume_gb'],
    'duration_days' => (int) $pkg['duration_days'],
    'price_toman'   => (int) $pkg['price_toman'],
    'panel_id'      => (int) $userPanel['id'],
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);
check('سفارش paid ایجاد شد', (string) $order['status'] === OrderRepository::STATUS_PAID);
check('کد سفارش ساخته شد', str_starts_with((string) $order['code'], 'ORD-'));

$result = $provisioner->provision($order);
check('اجرای بسته موفق بود', $result['ok'], (string) $result['message']);

$after = $orders->find((int) $order['id']);
check('وضعیت سفارش applied شد', (string) $after['status'] === OrderRepository::STATUS_APPLIED);
check('before_limit ثبت شد (10GB)', (int) $after['before_limit'] === 1073741824 * 10);
check('after_limit = 10+100GB', (int) $after['after_limit'] === 1073741824 * 110);
check('applied_volume = 100GB', (int) $after['applied_volume'] === 1073741824 * 100);
check('پنل modify فراخوانی شد', $panel->modifyCalls === 1);
check('data_limit جدید روی پنل', (int) $panel->admins['rep1']['data_limit'] === 1073741824 * 110);

$panelAfter = $panels->find((int) $userPanel['id']);
check('granted_volume به‌روز شد', (int) $panelAfter['granted_volume'] === 1073741824 * 100);
check('access_expire_at تنظیم شد',
    $panelAfter['access_expire_at'] !== null && (int) $panelAfter['access_expire_at'] > time());
check('sync از پنل انجام شد', (int) $panelAfter['data_limit'] === 1073741824 * 110);

$logs = $orders->provisionLogs((int) $order['id']);
check('لاگ اعمال ثبت شد', count($logs) >= 1);

// ------------------------------------------------------------------
echo "\n▶ اعمال بستهٔ پنل نمایندگی (ساخت اکانت اپراتور)\n";
// ------------------------------------------------------------------

$agencyPkg = $packages->find($agencyId);
$agencyOrder = $orders->create($userId, [
    'package_id'    => $agencyId,
    'package_title' => $agencyPkg['title'],
    'kind'          => $agencyPkg['kind'],
    'volume_gb'     => (float) $agencyPkg['volume_gb'],
    'duration_days' => (int) $agencyPkg['duration_days'],
    'price_toman'   => (int) $agencyPkg['price_toman'],
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$result2 = $provisioner->provision($agencyOrder);
check('اجرای بستهٔ پنل موفق بود', $result2['ok'], (string) $result2['message']);
check('حساب ادمین روی پنل ساخته شد', count($panel->createdAdmins) >= 1);

$newPanelId = (int) ($result2['details']['panel_id'] ?? 0);
$newPanel   = $panels->find($newPanelId);

check('پنل جدید ثبت شد', $newPanel !== null);
check('نام کاربری برگشت', ($result2['details']['panel_username'] ?? '') !== '');
check('رمز برگشت', strlen((string) ($result2['details']['panel_password'] ?? '')) >= 8);
check('نقش پنل ثبت شد', ($newPanel['panel_role'] ?? '') !== '',
    'role=' . (string) ($newPanel['panel_role'] ?? 'NULL'));
check('پنل اول دست‌نخورده ماند',
    (int) $panels->find((int) $userPanel['id'])['data_limit'] === 1073741824 * 110);
check('کاربر حالا ۲ پنل دارد', $panels->countByUser($userId) === 2,
    'n=' . $panels->countByUser($userId));

// ------------------------------------------------------------------
echo "\n▶ رفتارهای لبه: تکرار، حجم نامحدود، کاربر قطع‌شده\n";
// ------------------------------------------------------------------

// اعمال مجدد همان سفارش نباید حجم را دوباره اضافه کند
$replay = $provisioner->provision($order);
check('اعمال تکراری حجم دوباره اضافه نکرد',
    (int) $panel->admins['rep1']['data_limit'] === 1073741824 * 110,
    'limit=' . ((int) $panel->admins['rep1']['data_limit'] / 1073741824) . 'GB');
check('سفارش همچنان applied است',
    (string) $orders->find((int) $order['id'])['status'] === OrderRepository::STATUS_APPLIED);
// پیام می‌تواند یکی از این دو باشد: یا سفارش از قبل «نهایی» شناخته می‌شود
// (وقتی ردیف تازه از دیتابیس خوانده شود) یا «قبلاً اعمال شده» (وقتی آرایهٔ
// کهنهٔ فراخوان هنوز paid است). نکتهٔ اصلی، عدم اجرای دوباره است.
check('پیام «قبلاً اجرا شده» داده شد',
    str_contains((string) $replay['message'], 'قبلاً')
    || str_contains((string) $replay['message'], 'نهایی'),
    (string) $replay['message']);
check('تعداد فراخوانی modify تغییر نکرد', $panel->modifyCalls === 1);

// کاربر بدون هیچ پنلی
$orphanId = $users->upsertByTelegram(999999, ['telegram_id' => 999999])['id'];
$orphanOrder = $orders->create($orphanId, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 10,
    'duration_days' => 30,
    'price_toman'   => 100000,
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);
$orphanResult = $provisioner->provision($orphanOrder);
check('کاربر بدون پنل ناموفق بود', !$orphanResult['ok']);
check('پیام مناسب برای کاربر بدون پنل', str_contains((string) $orphanResult['message'], 'پیدا نشد'),
    (string) $orphanResult['message']);
check('سفارش وضعیت پایانی گرفت', (string) $orders->find((int) $orphanOrder['id'])['status'] === OrderRepository::STATUS_FAILED);
check('سفارش terminal_reason دارد', $orders->find((int) $orphanOrder['id'])['terminal_reason'] !== null);

// کاربر مسدود
[$blockedUser, $blockedPanel] = makeRep('blocked_admin', $panel, $users, $panels, [
    'telegram_id' => 555555,
]);
$blockedId = (int) $blockedUser['id'];
$users->setBlocked($blockedId, true, 'test');
$blockedOrder = $orders->create($blockedId, [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 10,
    'duration_days' => 30,
    'price_toman'   => 100000,
    'panel_id'      => (int) $blockedPanel['id'],
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);
$blockedResult = $provisioner->provision($blockedOrder);
check('کاربر مسدود سرویس نگرفت', !$blockedResult['ok']);
check('دلیل مسدودی ثبت شد',
    (string) $orders->find((int) $blockedOrder['id'])['terminal_reason'] === 'user_blocked',
    'reason=' . (string) $orders->find((int) $blockedOrder['id'])['terminal_reason']);

// ------------------------------------------------------------------
echo "\n▶ تلاش مجدد در خطای موقت پنل\n";
// ------------------------------------------------------------------

$panel->modifyError = 'خطای داخلی سرور';
$panel->modifyCalls = 0;

[$retryUser, $retryPanel] = makeRep('retry_admin', $panel, $users, $panels, [
    'telegram_id' => 777777,
]);

$retryOrder = $orders->create((int) $retryUser['id'], [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 20,
    'duration_days' => 30,
    'price_toman'   => 200000,
    'panel_id'      => (int) $retryPanel['id'],
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$retryResult = $provisioner->provision($retryOrder);
check('خطای 500 ناموفق برگرداند', !$retryResult['ok']);
$retryRow = $orders->find((int) $retryOrder['id']);
check('سفارش failed شد', (string) $retryRow['status'] === OrderRepository::STATUS_FAILED);
check('پیام خطا ذخیره شد', str_contains((string) $retryRow['error'], 'سرور'));
check('زمان تلاش مجدد ست شد', $retryRow['next_attempt_at'] !== null && (int) $retryRow['next_attempt_at'] > time());
check('attempt شمارش شد', (int) $retryRow['attempts'] === 1);

// نکتهٔ حیاتی: خطای موقت نباید panel_applied را ست کند، وگرنه تلاش مجدد
// هرگز انجام نمی‌شود و سفارش برای همیشه نیمه‌کاره می‌ماند.
check('panel_applied نخورد (تلاش مجدد ممکن است)', (int) $retryRow['panel_applied'] === 0,
    'panel_applied=' . $retryRow['panel_applied']);

// سفارش در backoff است و نباید زودتر از موعد در صف قرار گیرد
$pending = $orders->pendingApply(10);
$pendingIds = array_map(static fn (array $o): int => (int) $o['id'], $pending);
check('سفارش در backoff از صف کنار گذاشته شد', !in_array((int) $retryOrder['id'], $pendingIds, true));
check('سفارش در backoff زمان‌بندی شد', (int) $orders->find((int) $retryOrder['id'])['next_attempt_at'] > time());

// شبیه‌سازی گذشت زمان backoff و رفع خطا
$panel->modifyError = '';
$db->run('UPDATE orders SET next_attempt_at = ? WHERE id = ?', [time() - 1, (int) $retryOrder['id']]);

echo 'DEBUG pending: ' . json_encode(array_map(fn($o)=>['id'=>$o['id'],'st'=>$o['status'],'att'=>$o['attempts'],'na'=>$o['next_attempt_at'],'tr'=>(int)($o['terminal_reason']!==null),'pa'=>(int)$o['panel_applied'],'paid'=>(int)($o['paid_at']!==null)], $orders->pendingApply(20))) . PHP_EOL;
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

[$authUser, $authPanel] = makeRep('expired_admin', $panel, $users, $panels, [
    'telegram_id' => 888888,
    'password'    => 'wrong-pass',
]);

$authOrder = $orders->create((int) $authUser['id'], [
    'package_id'    => $quotaId,
    'package_title' => $pkg['title'],
    'kind'          => $pkg['kind'],
    'volume_gb'     => 5,
    'duration_days' => 30,
    'price_toman'   => 50000,
    'panel_id'      => (int) $authPanel['id'],
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$authResult = $provisioner->provision($authOrder);
check('خطای 401 ناموفق برگرداند', !$authResult['ok']);
$authRow = $orders->find((int) $authOrder['id']);
check('سفارش 401 وضعیت پایانی گرفت', (string) $authRow['status'] === OrderRepository::STATUS_FAILED);
check('سفارش 401 دیگر تلاش مجدد نمی‌شود', $authRow['terminal_reason'] === 'auth_error', 'reason=' . $authRow['terminal_reason']);
check('سفارش 401 از صف اجرا خارج شد', !in_array((int) $authOrder['id'], array_map(static fn(array $o): int => (int) $o['id'], $orders->pendingApply(50)), true));
check('پنل revoked شد',
    (string) $panels->find((int) $authPanel['id'])['panel_status'] === PanelRepository::STATUS_REVOKED);
check('پیام «دوباره وارد شود» دارد', str_contains((string) $authResult['message'], 'دوباره'), (string) $authResult['message']);
$panel->loginError = '';

// ------------------------------------------------------------------
echo "\n▶ آمار و پرداخت‌ها\n";
// ------------------------------------------------------------------

$stats = $orders->stats();
check('آمار سفارش‌ها محاسبه شد', $stats['total'] >= 6, json_encode($stats));
check('درآمد محاسبه شد', $stats['revenue'] > 0);

$users->refreshOrderStats($userId);
$userStats = $users->findById($userId);
check('شمارش سفارش کاربر', (int) $userStats['orders_count'] === 2,
    'n=' . $userStats['orders_count']);
check('مجموع پرداخت کاربر', (int) $userStats['total_paid'] === 500000 + 400000,
    'paid=' . $userStats['total_paid']);

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