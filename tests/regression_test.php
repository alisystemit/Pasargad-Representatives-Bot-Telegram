<?php

declare(strict_types=1);

/**
 * تست‌های بازگشتی برای باگ‌های بحرانی مالی.
 *
 * هر تست اینجا یک باگ واقعی را قفل می‌کند که در صورت بازگشت، جلوی
 * از دست رفتن پول یا دادن سرویس رایگان را می‌گیرد.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserProvisioner;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Crypto;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$panel    = new FakePanelClient();
$orders   = new OrderRepository($db);
$users    = new UserRepository($db);
$packages = new PackageRepository($db);
$settings = new Settings($db);
$prov     = new Provisioner($panel, $orders, $users, $settings);
$payments = new PaymentService($orders, $prov, $settings);

$GB = 1073741824;

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

/**
 * ساخت کاربر متصل به پنل قلابی با آیدی یکتا و مثبت.
 */
function makeUser(string $panelUser, int $credit = 0): array
{
    global $users, $panel, $GB;

    // آیدی مثبت و یکتا از نام (crc32 می‌تواند منفی یا صفر شود)
    $telegramId = 900000 + (crc32($panelUser) % 90000);

    $id = $users->upsertByTelegram($telegramId, [
        'telegram_id'       => $telegramId,
        'panel_username'    => $panelUser,
        'panel_password'    => Crypto::encrypt('pass'),
        'panel_status'      => 'active',
        'user_credit'       => $credit,
    ])['id'];

    $panel->addAdmin($panelUser, ['data_limit' => 0, 'used_traffic' => 0]);

    return $users->findById($id);
}

echo "\n════════════════════════════════════════";
echo "\n  باگ ۱: سفارش ردشده نباید اجرا شود";
echo "\n════════════════════════════════════════\n";

$user = makeUser('reject_admin', 0);

$pkgId = $packages->create([
    'title' => 'بسته', 'kind' => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb' => 100, 'duration_days' => 30, 'price_toman' => 500000, 'is_active' => true,
]);

$order = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_PAID, 'paid_at' => time(),
]);

// سوپرادمین پرداخت را رد می‌کند
$orders->createPayment((int) $order['id'], [
    'method' => 'card2card', 'amount_toman' => 500000, 'external_id' => (string) $order['code'],
    'status' => 'waiting',
]);
$orders->update((int) $order['id'], [
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'receipt_file_id' => 'FAKE_RECEIPT',
]);

$reviewResult = $payments->reviewOrder($orders->find((int) $order['id']), false, 999);
check('رد پرداخت انجام شد', $reviewResult['ok']);

$afterReject = $orders->find((int) $order['id']);
check('وضعیت rejected گرفت', (string) $afterReject['status'] === OrderRepository::STATUS_REJECTED, 'status=' . $afterReject['status']);
check('terminal_reason ثبت شد', $afterReject['terminal_reason'] !== null);

$queued = array_map(static fn (array $o): int => (int) $o['id'], $orders->pendingApply(50));
check('سفارش ردشده در صف اجرا نیست', !in_array((int) $order['id'], $queued, true), 'queued=' . implode(',', $queued));

// حتی اگر کسی اشتباهاً status را به paid برگرداند
$db->run('UPDATE orders SET status = ? WHERE id = ?', [OrderRepository::STATUS_PAID, (int) $order['id']]);
$queued2 = array_map(static fn (array $o): int => (int) $o['id'], $orders->pendingApply(50));
check('حتی با status دستکاری‌شده هم در صف نمی‌آید', !in_array((int) $order['id'], $queued2, true));

$queueRun = $prov->processQueue(50);
check('کرون هیچ چیزی اجرا نکرد', (int) $panel->admins['reject_admin']['data_limit'] === 0, 'limit=' . $panel->admins['reject_admin']['data_limit']);
check('پنل دست‌نخورده ماند', $panel->modifyCalls === 0, 'calls=' . $panel->modifyCalls);

// اجرای مستقیم هم باید رد شود
$db->run('UPDATE orders SET status = ? WHERE id = ?', [OrderRepository::STATUS_APPLYING, (int) $order['id']]);
$direct = $prov->provision($orders->find((int) $order['id']));
check('اجرای مستقیم سفارش ردشده هم مسدود است', !$direct['ok']);
check('هیچ حجمی اعمال نشد', (int) $panel->admins['reject_admin']['data_limit'] === 0);

echo "\n════════════════════════════════════════";
echo "\n  باگ ۲: اجرای دوباره نباید حجم را دو برابر کند";
echo "\n════════════════════════════════════════\n";

$user2 = makeUser('idempotent_admin');
$panel->addAdmin('idempotent_admin', ['data_limit' => 10 * $GB, 'used_traffic' => 5 * $GB]);

$order2 = $orders->create((int) $user2['id'], [
    'package_id' => $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_PAID, 'paid_at' => time(),
]);

$first = $prov->provision($orders->find((int) $order2['id']));
check('اجرای اول موفق بود', $first['ok'], (string) $first['message']);
check('حجم = ۱۰ + ۱۰۰ گیگ', (int) $panel->admins['idempotent_admin']['data_limit'] === 110 * $GB,
    'limit=' . ($panel->admins['idempotent_admin']['data_limit'] / $GB) . 'GB');

$row2 = $orders->find((int) $order2['id']);
check('target_limit ذخیره شد', $row2['target_limit'] !== null && (int) $row2['target_limit'] === 110 * $GB,
    'target=' . var_export($row2['target_limit'], true));
check('panel_applied علامت خورد', (int) $row2['panel_applied'] === 1);

// شبیه‌سازی: اجرا دوباره با همان داده
$second = $prov->provision($orders->find((int) $order2['id']));
check('اجرای دوم ناموفق برگشت (قبلاً اجرا شده)', !$second['ok']);
check('حجم دو برابر نشد', (int) $panel->admins['idempotent_admin']['data_limit'] === 110 * $GB,
    'limit=' . ($panel->admins['idempotent_admin']['data_limit'] / $GB) . 'GB');

// حتی اگر status را دستی به failed برگردانیم، panel_applied جلوی اجرا را می‌گیرد
$db->run('UPDATE orders SET status = ?, terminal_reason = NULL, next_attempt_at = NULL WHERE id = ?',
    [OrderRepository::STATUS_FAILED, (int) $order2['id']]);

$queued3 = array_map(static fn (array $o): int => (int) $o['id'], $orders->pendingApply(50));
check('سفارش panel_applied از صف بیرون است', !in_array((int) $order2['id'], $queued3, true));

$prov->processQueue(50);
check('کرون حجم را دوباره اضافه نکرد', (int) $panel->admins['idempotent_admin']['data_limit'] === 110 * $GB,
    'limit=' . ($panel->admins['idempotent_admin']['data_limit'] / $GB) . 'GB');

echo "\n════════════════════════════════════════";
echo "\n  باگ ۳: حجم زیر یک بایت نباید نامحدود بدهد";
echo "\n════════════════════════════════════════\n";

$user3 = makeUser('tiny_admin', 10 * $GB);
$up = new UserProvisioner($users, $panel);

foreach ([0.0000000001, 0.0001, 0.5, 0.9] as $tiny) {
    $res = $up->createUser($user3, 'tiny_' . str_replace('.', '_', (string) $tiny), $tiny, 30);
    check("حجم {$tiny} گیگ رد شد", !$res['ok'], (string) $res['message']);
    check("پیام حداقل حجم برای {$tiny}", str_contains((string) $res['message'], '۱ گیگابایت'), (string) $res['message']);
}

check('هیچ کاربر نامحدودی ساخته نشد', $panel->created === [], 'created=' . count($panel->created));
check('اعتبار دست‌نخورده ماند', (int) $users->findById((int) $user3['id'])['user_credit'] === 10 * $GB);

// حداقل معتبر
$ok = $up->createUser($user3, 'valid_user', 1, 30);
check('حجم ۱ گیگ پذیرفته شد', $ok['ok'], (string) $ok['message']);
check('سقف ۱ گیگ روی پنل رفت', (int) ($panel->created[0]['data_limit'] ?? 0) === 1 * $GB);

echo "\n════════════════════════════════════════";
echo "\n  باگ ۴: قیمت بسته نباید به ۱ تومان تبدیل شود";
echo "\n════════════════════════════════════════\n";

$pkgId = $packages->create([
    'title' => 'بستهٔ ویرایش', 'kind' => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb' => 100, 'duration_days' => 30, 'price_toman' => 500000, 'is_active' => true,
]);

$packages->update((int) $pkgId, [
    'price_toman'   => 750000,
    'duration_days' => 60,
    'volume_gb'     => 250,
    'max_per_user'  => 3,
    'sort_order'    => 5,
]);

$edited = $packages->find((int) $pkgId);
check('قیمت درست ذخیره شد', (int) $edited['price_toman'] === 750000, 'price=' . $edited['price_toman']);
check('مدت درست ذخیره شد', (int) $edited['duration_days'] === 60, 'days=' . $edited['duration_days']);
check('حجم درست ذخیره شد', (float) $edited['volume_gb'] === 250.0, 'vol=' . $edited['volume_gb']);
check('سقف هر کاربر درست', (int) $edited['max_per_user'] === 3, 'max=' . $edited['max_per_user']);
check('ترتیب درست', (int) $edited['sort_order'] === 5, 'sort=' . $edited['sort_order']);
check('وضعیت فعال حفظ شد', (int) $edited['is_active'] === 1);

echo "\n════════════════════════════════════════";
echo "\n  باگ ۵: کسر اعتبار باید اتمیک و بدون منفی شدن باشد";
echo "\n════════════════════════════════════════\n";

$user5 = makeUser('credit_admin', 10 * $GB);
$uid5 = (int) $user5['id'];

// تلاش برای کسر بیش از موجودی
check('کسر بیش از موجودی رد شد', !$users->consumeUserCredit($uid5, 20 * $GB));
check('موجودی منفی نشد', (int) $users->findById($uid5)['user_credit'] === 10 * $GB, 'credit=' . $users->findById($uid5)['user_credit']);

// کسر دقیق موجودی
check('کسر دقیق انجام شد', $users->consumeUserCredit($uid5, 10 * $GB));
check('موجودی صفر شد', (int) $users->findById($uid5)['user_credit'] === 0);

// کسر صفر یا منفی نباید انجام شود
check('کسر صفر رد شد', !$users->consumeUserCredit($uid5, 0));
check('کسر منفی رد شد', !$users->consumeUserCredit($uid5, -100));

// افزودن اتمیک
$users->addUserCredit($uid5, 5 * $GB);
check('افزودن اعتبار کار کرد', (int) $users->findById($uid5)['user_credit'] === 5 * $GB);
check('صفر رد شد', (function () use ($users, $uid5) { $users->addUserCredit($uid5, 0); return true; })());
check('مقدار تغییر نکرد', (int) $users->findById($uid5)['user_credit'] === 5 * $GB);

echo "\n════════════════════════════════════════";
echo "\n  باگ ۶: پاسخ ناقص پنل نباید حجم را صفر کند";
echo "\n════════════════════════════════════════\n";

$user6 = makeUser('partial_admin');
$users->update((int) $user6['id'], ['panel_data_limit' => 100 * $GB, 'panel_used' => 40 * $GB]);

// پاسخ PUT ناقص (بدون data_limit و used_traffic)
$users->syncPanelState((int) $user6['id'], ['status' => 'active']);
$after6 = $users->findById((int) $user6['id']);
check('حجم صفر نشد', (int) $after6['panel_data_limit'] === 100 * $GB, 'limit=' . $after6['panel_data_limit']);
check('مصرف صفر نشد', (int) $after6['panel_used'] === 40 * $GB, 'used=' . $after6['panel_used']);
check('وضعیت به‌روز شد', (string) $after6['panel_status'] === 'active');

// پاسخ کامل باید بازنویسی کند
$users->syncPanelState((int) $user6['id'], ['status' => 'limited', 'data_limit' => 50 * $GB, 'used_traffic' => 50 * $GB]);
$after6b = $users->findById((int) $user6['id']);
check('حجم جدید اعمال شد', (int) $after6b['panel_data_limit'] === 50 * $GB);
check('وضعیت limited ثبت شد', (string) $after6b['panel_status'] === 'limited');

echo "\n════════════════════════════════════════";
echo "\n  باگ ۷: پرداخت تکراری نباید سفارش را خراب کند";
echo "\n════════════════════════════════════════\n";

$user7 = makeUser('doublepay_admin');
\Pasargad\Support\Config::set('store.card_number', '6037999999999999');
\Pasargad\Support\Config::set('nowpayments.api_key', 'test-key-dup-check');

$order7 = $orders->create((int) $user7['id'], [
    'package_id' => (int) $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 50,
    'duration_days' => 30, 'price_toman' => 300000,
    'status' => OrderRepository::STATUS_CREATED,
]);

$r1 = $payments->startPayment($orders->find((int) $order7['id']), 'card2card', 5000);
check('پرداخت اول موفق بود', $r1['ok'], (string) ($r1['message'] ?? ''));

$r2 = $payments->startPayment($orders->find((int) $order7['id']), 'card2card', 5000);
check('پرداخت دوم خطا نداد', is_array($r2));
check('سفارش خراب نشد', $orders->find((int) $order7['id']) !== null);
check('سفارش همچنان قابل پرداخت است', (string) $orders->find((int) $order7['id'])['status'] === OrderRepository::STATUS_AWAITING_PAYMENT,
    'status=' . $orders->find((int) $order7['id'])['status']);

// روش‌های مختلف باید سطرهای جدا بسازند
// ثبت روش دوم باید سطر جدا بسازد نه خطای UNIQUE بدهد
$orders->upsertPayment((int) $order7['id'], [
    'method' => 'nowpayments', 'amount_toman' => 300000,
    'external_id' => 'np-dup-1', 'status' => 'pending',
]);
$payCount = $db->count('SELECT COUNT(*) FROM payments WHERE order_id = ?', [(int) $order7['id']]);
check('دو رکورد پرداخت ثبت شد (نه تکراری)', $payCount === 2, 'count=' . $payCount);

// upsert دوباره همان روش نباید سطر سوم بسازد
$orders->upsertPayment((int) $order7['id'], [
    'method' => 'nowpayments', 'amount_toman' => 300000,
    'external_id' => 'np-dup-2', 'status' => 'waiting',
]);
$payCount2 = $db->count('SELECT COUNT(*) FROM payments WHERE order_id = ?', [(int) $order7['id']]);
check('upsert همان روش سطر تکراری نساخت', $payCount2 === 2, 'count=' . $payCount2);

// سفارش پرداخت‌شده دیگر پرداخت نمی‌شود
$orders->markPaid((int) $order7['id'], 'card2card', 'X');
$r4 = $payments->startPayment($orders->find((int) $order7['id']), 'card2card', 5000);
check('سفارش پرداخت‌شده دوباره پرداخت نمی‌شود', !($r4['ok'] ?? false));
check('پیام مناسب دارد', str_contains((string) ($r4['message'] ?? ''), 'قبلاً'), (string) ($r4['message'] ?? ''));

echo "\n════════════════════════════════════════";
echo "\n  باگ ۸: خطای غیرقابل تلاش مجدد نباید بی‌نهایت تکرار شود";
echo "\n════════════════════════════════════════\n";

$user8 = makeUser('notfound_admin');
// پنل خطای ۴۰۴ می‌دهد که غیرقابل تلاش مجدد است
$panel->modifyError = 'Admin not found';
$panel->httpStatusOverride = 404;

$order8 = $orders->create((int) $user8['id'], [
    'package_id' => (int) $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 50,
    'duration_days' => 30, 'price_toman' => 300000,
    'status' => OrderRepository::STATUS_PAID, 'paid_at' => time(),
]);

$res8 = $prov->provision($orders->find((int) $order8['id']));
check('خطای 404 ناموفق برگشت', !$res8['ok']);
$row8 = $orders->find((int) $order8['id']);
check('terminal_reason ثبت شد', $row8['terminal_reason'] !== null, 'reason=' . var_export($row8['terminal_reason'], true));
check('از صف اجرا خارج شد', !in_array((int) $order8['id'], array_map(static fn (array $o): int => (int) $o['id'], $orders->pendingApply(50)), true));

// سفارش با خطای 404 نباید هرگز دوباره به پنل زده شود، اما سفارش
// پرداخت‌شدهٔ باگ ۷ که هنوز اجرا نشده، باید اجرا شود (رفتار درست).
$notFoundId   = (int) $order8['id'];
$paidOrderId  = (int) $order7['id'];
error_clear:
$panel->httpStatusOverride = 0;       // خطاي 404 مخصوص سفارش باگ ۸ بود
$panel->modifyError          = '';     // و خطاي سرور را هم برداريم
$panel->loggedRequests = [];
$prov->processQueue(50);

$touchedNotFound = false;
$touchedPaid     = false;
foreach ($panel->loggedRequests as $req) {
    if (str_contains((string) $req['path'], 'notfound_admin')) { $touchedNotFound = true; }
    if (str_contains((string) $req['path'], 'doublepay_admin'))  { $touchedPaid     = true; }
}

check('کرون سفارش با خطای 404 را دوباره امتحان نکرد', !$touchedNotFound);
check('کرون سفارش پرداخت‌شدهٔ معتبر را اجرا کرد', $touchedPaid);
check('سفارش ۴۰۴ هنوز هم در صف نیست', !in_array($notFoundId, array_map(static fn(array $o): int => (int) $o['id'], $orders->pendingApply(50)), true));
$paidRow = $orders->find($paidOrderId);
check('سفارش پرداخت‌شده از صف خارج شد', in_array((string) $paidRow['status'], [OrderRepository::STATUS_APPLIED, OrderRepository::STATUS_APPLYING], true), 'status=' . var_export($paidRow['status'], true) . ' id=' . $paidRow['id'] . ' asked=' . $paidOrderId);


echo "\n════════════════════════════════════════";
echo "\n  باگ ۹: اعتبار منقضی نباید قابل استفاده باشد";
echo "\n════════════════════════════════════════\n";

$user9 = makeUser('expired_credit', 50 * $GB);
$uid9 = (int) $user9['id'];
$users->update($uid9, ['user_credit_expire' => time() - 86400]);   // یک روز پیش منقضی شده

$up9 = new UserProvisioner($users, $panel);
$res9 = $up9->createUser($users->findById($uid9), 'expired_user', 10, 30);
check('اعتبار منقضی رد شد', !$res9['ok'], (string) $res9['message']);
check('پیام اعتبار کافی دارد', str_contains((string) $res9['message'], 'اعتبار'), (string) $res9['message']);
check('کاربری ساخته نشد', !isset($panel->existingUsers['expired_user']));

// تمدید اعتبار
// تمدید اعتبار: تاریخ انقضا جلو می‌رود و اعتبار قابل استفاده می‌شود
$db->run('UPDATE users SET user_credit_expire = ? WHERE id = ?', [time() + 30 * 86400, $uid9]);
$res9b = $up9->createUser($users->findById($uid9), 'renewed_user', 10, 30);
check('بعد از تمدید کار می‌کند', $res9b['ok'], (string) $res9b['message']);

echo "\n════════════════════════════════════════";
echo "\n  باگ ۱۰: تمدید کاربر نامحدود نباید محدود شود";
echo "\n════════════════════════════════════════\n";

$user10 = makeUser('unlimited_target', 50 * $GB);
$panel->existingUsers['vip_customer'] = [
    'username'      => 'vip_customer',
    'status'        => 'active',
    'data_limit'    => 0,          // نامحدود
    'used_traffic'  => 80 * $GB,   // ولی ۸۰ گیگ مصرف کرده
    'expire'        => time() + 86400,
];

$up10 = new UserProvisioner($users, $panel);
$res10 = $up10->extendUser($users->findById((int) $user10['id']), 'vip_customer', 50, 30);
check('تمدید موفق بود', $res10['ok'], (string) $res10['message']);

$newLimit = (int) ($panel->existingUsers['vip_customer']['data_limit'] ?? 0);
$used = 80 * $GB;
check('سقف جدید بالای مصرف است', $newLimit > $used, "new=$newLimit used=$used");
check('سقف = مصرف + ۵۰ گیگ', $newLimit === 130 * $GB, 'new=' . ($newLimit / $GB) . 'GB');
check('کاربر محدود نشد', (string) ($panel->existingUsers['vip_customer']['status'] ?? '') === 'active');

echo "\n════════════════════════════════════════";
echo "\n  نتیجهٔ بازگشتی";
echo "\n════════════════════════════════════════\n";

echo "  {$passed} موفق، {$failed} ناموفق\n";

exit($failed === 0 ? 0 : 1);
