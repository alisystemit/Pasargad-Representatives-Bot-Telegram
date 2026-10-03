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
require_once __DIR__ . '/Fixture.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$panel    = new FakePanelClient();
$orders   = new OrderRepository($db);
$users    = new UserRepository($db);
$panels   = new PanelRepository($db);
$packages = new PackageRepository($db);
$settings = new Settings($db);
$prov     = new Provisioner($panel, $orders, $users, $settings, $panels);
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
 * ساخت کاربر متصل به یک پنل قلابی با آیدی یکتا و مثبت.
 *
 * @param array<string, mixed> $options
 */
function makeUser(string $panelUser, array $options = []): array
{
    global $users, $panel, $panels;

    [$user] = makeRep($panelUser, $panel, $users, $panels, $options);

    return $user;
}

echo "\n════════════════════════════════════════";
echo "\n  باگ ۱: سفارش ردشده نباید اجرا شود";
echo "\n════════════════════════════════════════\n";

$user = makeUser('reject_admin');

$pkgId = $packages->create([
    'title' => 'بسته', 'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 100, 'duration_days' => 30, 'price_toman' => 500000, 'is_active' => true,
]);

$order = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_TOPUP, 'volume_gb' => 100,
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
    'kind' => PackageRepository::KIND_TOPUP, 'volume_gb' => 100,
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
echo "\n  باگ ۳: حجم زیر یک بایت نباید پنل نامحدود بدهد";
echo "\n════════════════════════════════════════\n";

// در پنل، data_limit = 0 یعنی «نامحدود». یک بستهٔ با حجم کوچکِ
// اعشاری (مثلاً 0.0000000001 گیگ) در تبدیل به بایت صفر می‌شود و اگر رد
// نشود، نماینده یک پنل **نامحدود** رایگان می‌گیرد.
$user3 = makeUser('tiny_admin');
$panelsBefore = $panels->countByUser((int) $user3['id']);
check('نماینده یک پنل اولیه دارد', $panelsBefore === 1, 'n=' . $panelsBefore);

$tinyPkg = $packages->create([
    'title' => 'بستهٔ ناچیز', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 0.0000000001, 'duration_days' => 30, 'price_toman' => 500000, 'is_active' => true,
]);

$tinyOrder = $orders->create((int) $user3['id'], [
    'package_id' => (int) $tinyPkg, 'package_title' => 'بستهٔ ناچیز',
    'kind' => PackageRepository::KIND_AGENCY, 'volume_gb' => 0.0000000001,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_PAID, 'paid_at' => time(),
]);

$tinyResult = $prov->provision($orders->find((int) $tinyOrder['id']));
check('بستهٔ با حجم صفر رد شد', !$tinyResult['ok'], (string) $tinyResult['message']);
check('دلیل نامعتبر بودن حجم ثبت شد',
    (string) $orders->find((int) $tinyOrder['id'])['terminal_reason'] === 'invalid_volume',
    'reason=' . var_export($orders->find((int) $tinyOrder['id'])['terminal_reason'], true));
check('هیچ پنل **تازه‌ای** ساخته نشد', $panels->countByUser((int) $user3['id']) === $panelsBefore,
    'n=' . $panels->countByUser((int) $user3['id']));
check('هیچ ادمینی روی پنل ساخته نشد', count($panel->createdAdmins) === 0,
    'created=' . count($panel->createdAdmins));

echo "\n════════════════════════════════════════";
echo "\n  باگ ۴: قیمت بسته نباید به ۱ تومان تبدیل شود";
echo "\n════════════════════════════════════════\n";

$pkgId = $packages->create([
    'title' => 'بستهٔ ویرایش', 'kind' => PackageRepository::KIND_TOPUP,
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
echo "\n  باگ ۵: شارژ هرگز نباید سقف را زیر مصرف ببرد";
echo "\n════════════════════════════════════════\n";

// اگر سقف یک پنل (مثلاً به‌دلیل دستکاری دستی یا باگ پنل) از مصرف
// واقعی‌اش کمتر شده باشد، جمع سادهٔ «سقف + حجم خریداری‌شده» می‌تواند سقف
// جدید را باز هم زیر مصرف نگه دارد. نتیجه: نماینده پول داده ولی سرویس
// مشتریانش قطع است. پس پایه همیشه max(سقف، مصرف) است.
$user5 = makeUser('below_usage_admin', [
    'data_limit'   => 10 * $GB,
    'used_traffic' => 40 * $GB,   // مصرف بیشتر از سقف!
]);

$topup5Id = $packages->create([
    'title' => 'شارژ ۱۰ گیگ', 'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 10, 'duration_days' => 30, 'price_toman' => 300000, 'is_active' => true,
]);
$topup5 = $packages->find($topup5Id);

$order5 = makePaidOrder($orders, (int) $user5['id'], $topup5, [
    'panel_id' => primaryPanelId($panels, $user5),
]);

$r5 = $prov->provision($order5);
check('شارژ موفق بود', $r5['ok'], (string) $r5['message']);

$limit5 = (int) $panel->admins['below_usage_admin']['data_limit'];
check('سقف جدید بالاتر از مصرف است', $limit5 > 40 * $GB,
    'limit=' . ($limit5 / $GB) . 'GB');
check('سقف = مصرف + حجم خریداری‌شده', $limit5 === 50 * $GB, 'limit=' . ($limit5 / $GB) . 'GB');

// دوباره اجرا نباید حجم را دوباره جمع کند
$prov->provision($order5);
check('اجرای دوباره سقف را دوبرابر نکرد',
    (int) $panel->admins['below_usage_admin']['data_limit'] === 50 * $GB,
    'limit=' . ($panel->admins['below_usage_admin']['data_limit'] / $GB) . 'GB');

echo "\n════════════════════════════════════════";
echo "\n  باگ ۶: پاسخ ناقص پنل نباید حجم را صفر کند";
echo "\n════════════════════════════════════════\n";

[$user6, $panel6] = makeRep('partial_admin', $panel, $users, $panels, [
    'data_limit'   => 100 * $GB,
    'used_traffic' => 40 * $GB,
]);
$panel6Id = (int) $panel6['id'];

// پاسخ PUT ناقص (بدون data_limit و used_traffic)
$panels->syncFromPanel($panel6Id, ['status' => 'active']);
$after6 = $panels->find($panel6Id);
check('حجم صفر نشد', (int) $after6['data_limit'] === 100 * $GB, 'limit=' . $after6['data_limit']);
check('مصرف صفر نشد', (int) $after6['used_traffic'] === 40 * $GB, 'used=' . $after6['used_traffic']);
check('وضعیت به‌روز شد', (string) $after6['panel_status'] === 'active');

// پاسخ کامل باید بازنویسی کند
$panels->syncFromPanel($panel6Id, ['status' => 'limited', 'data_limit' => 50 * $GB, 'used_traffic' => 50 * $GB]);
$after6b = $panels->find($panel6Id);
check('حجم جدید اعمال شد', (int) $after6b['data_limit'] === 50 * $GB);
check('وضعیت limited ثبت شد', (string) $after6b['panel_status'] === 'limited');

echo "\n════════════════════════════════════════";
echo "\n  باگ ۷: پرداخت تکراری نباید سفارش را خراب کند";
echo "\n════════════════════════════════════════\n";

$user7 = makeUser('doublepay_admin');
\Pasargad\Support\Config::set('store.card_number', '6037999999999999');
\Pasargad\Support\Config::set('nowpayments.api_key', 'test-key-dup-check');

$order7 = $orders->create((int) $user7['id'], [
    'package_id' => (int) $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_TOPUP, 'volume_gb' => 50,
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
    'kind' => PackageRepository::KIND_TOPUP, 'volume_gb' => 50,
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
echo "\n  باگ ۹: پنل منقضی نباید فعال به نظر برسد";
echo "\n════════════════════════════════════════\n";

[$user9, $panel9] = makeRep('expired_panel', $panel, $users, $panels, [
    'data_limit' => 50 * $GB,
    'expire_at'  => time() - 86400,   // یک روز پیش منقضی شده
]);

check('پنل منقضی تشخیص داده می‌شود', \Pasargad\Store\PanelRepository::isExpired($panel9));
check('پنل منقضی قابل استفاده نیست', !\Pasargad\Store\PanelRepository::isUsable($panel9));

// پنل‌های فعال = پنل‌هایی که کاربر می‌تواند رویشان تست بگیرد/شارژ کند
$active = $panels->listActiveByUser((int) $user9['id']);
check('پنل منقضی در فهرست فعال نیست', count($active) === 0, 'n=' . count($active));

// پس از تمدید دوباره فعال می‌شود
$panels->extendExpiry((int) $panel9['id'], time() + 30 * 86400);
$revived = $panels->find((int) $panel9['id']);
check('بعد از تمدید فعال شد', \Pasargad\Store\PanelRepository::isUsable($revived));
check('در فهرست فعال برگشت', count($panels->listActiveByUser((int) $user9['id'])) === 1);

echo "\n════════════════════════════════════════";
echo "\n  باگ ۱۰: شارژ پنل نامحدود نباید محدودش کند";
echo "\n════════════════════════════════════════\n";

// پنل نامحدود (data_limit = 0) که ۸۰ گیگ مصرف کرده است. اگر شارژ از
// صفر شروع کند، سقف جدید ۵۰ گیگ می‌شود که زیر مصرف است و سرویس مشتری
// نماینده بی‌دلیل قطع می‌شود — در حالی که نماینده پول داده است.
[$user10, $panel10] = makeRep('unlimited_target', $panel, $users, $panels, [
    'data_limit'   => 0,          // نامحدود
    'used_traffic' => 80 * $GB,   // ولی ۸۰ گیگ مصرف کرده
]);

$order10 = makePaidOrder($orders, (int) $user10['id'], $topup5, [
    'panel_id' => (int) $panel10['id'],
]);

$res10 = $prov->provision($order10);
check('شارژ موفق بود', $res10['ok'], (string) $res10['message']);

$newLimit = (int) $panel->admins['unlimited_target']['data_limit'];
check('سقف جدید بالای مصرف است', $newLimit > 80 * $GB, "new=" . ($newLimit / $GB) . 'GB');
check('سقف = مصرف + ۱۰ گیگ (بستهٔ شارژ)', $newLimit === 90 * $GB, 'new=' . ($newLimit / $GB) . 'GB');
check('پنل محدود نشد (بازگشت به حالت محدود)',
    \Pasargad\Store\PanelRepository::isUsable($panels->find((int) $panel10['id'])));

echo "\n════════════════════════════════════════";
echo "\n  نتیجهٔ بازگشتی";
echo "\n════════════════════════════════════════\n";

echo "\n════════════════════════════════════════";
echo "\n  باگ ۱۱: اجرای دستی سفارش نهایی بستهٔ رایگان می‌داد";
echo "\n════════════════════════════════════════\n";

$retryUser = makeUser('retry_terminal_admin');
$panel->addAdmin('retry_terminal_admin', ['data_limit' => 0, 'used_traffic' => 0]);

$retryOrder = $orders->create((int) $retryUser['id'], [
    'package_id' => $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_TOPUP, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_PAID, 'paid_at' => time(),
]);

// سفارش با دلیل نهایی شکست می‌خورد (مثلاً کاربر مسدود شده)
$db->run(
    "UPDATE orders SET status = ?, terminal_reason = ?, panel_applied = 0 WHERE id = ?",
    [OrderRepository::STATUS_FAILED, 'user_blocked', (int) $retryOrder['id']]
);

$retryResult = $prov->provision($orders->find((int) $retryOrder['id']));
check('اجرای سفارش terminal ناموفق بود', !$retryResult['ok'], (string) $retryResult['message']);
check('حجمی اعمال نشد', (int) $panel->admins['retry_terminal_admin']['data_limit'] === 0,
    'limit=' . $panel->admins['retry_terminal_admin']['data_limit']);
check('panel_applied علامت نخورد', (int) $orders->find((int) $retryOrder['id'])['panel_applied'] === 0);
check('دلیل پایانی تغییر نکرد',
    (string) $orders->find((int) $retryOrder['id'])['terminal_reason'] === 'user_blocked',
    'reason=' . (string) $orders->find((int) $retryOrder['id'])['terminal_reason']);

// حتی اگر status را به paid برگردانند، terminal_reason جلویش را می‌گیرد
$db->run('UPDATE orders SET status = ? WHERE id = ?', [OrderRepository::STATUS_PAID, (int) $retryOrder['id']]);
$retryResult2 = $prov->provision($orders->find((int) $retryOrder['id']));
check('اجرای سفارش paid-با-دلیل-پایانی ناموفق بود', !$retryResult2['ok']);
check('باز هم حجمی اعمال نشد', (int) $panel->admins['retry_terminal_admin']['data_limit'] === 0);

// سفارش بدون paid_at (یعنی پرداخت‌نشده) نباید اجرا شود
$unpaid = $orders->create((int) $retryUser['id'], [
    'package_id' => $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_TOPUP, 'volume_gb' => 50,
    'duration_days' => 30, 'price_toman' => 300000,
    'status' => OrderRepository::STATUS_PAID,
]);
$db->run('UPDATE orders SET paid_at = NULL WHERE id = ?', [(int) $unpaid['id']]);

$unpaidResult = $prov->provision($orders->find((int) $unpaid['id']));
check('اجرای سفارش بدون paid_at ناموفق بود', !$unpaidResult['ok'], (string) $unpaidResult['message']);
check('حجمی برای سفارش بدون پرداخت اعمال نشد',
    (int) $panel->admins['retry_terminal_admin']['data_limit'] === 0,
    'limit=' . $panel->admins['retry_terminal_admin']['data_limit']);

echo "\n════════════════════════════════════════";
echo "\n  باگ ۱۲: خطای دسترسی (۴۰۳) حساب کاربر را قطع می‌کرد";
echo "\n════════════════════════════════════════\n";

$permUser = makeUser('perm_admin');
$panel->addAdmin('perm_admin', ['data_limit' => 0, 'used_traffic' => 0]);

$permOrder = $orders->create((int) $permUser['id'], [
    'package_id' => $pkgId, 'package_title' => 'بسته',
    'kind' => PackageRepository::KIND_TOPUP, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_PAID, 'paid_at' => time(),
]);

// ۴۰۳ روی مسیر عملیاتی = نقش کاربر اجازه ندارد (نه اطلاعات ورود خراب)
$panel->modifyError = 'Forbidden';
$panel->httpStatusOverride = 403;

$permResult = $prov->provision($orders->find((int) $permOrder['id']));
check('خطای ۴۰۳ ناموفق برگشت', !$permResult['ok']);

$permRow = $orders->find((int) $permOrder['id']);
check('دلیل permission_denied ثبت شد', (string) $permRow['terminal_reason'] === 'permission_denied',
    'reason=' . (string) $permRow['terminal_reason']);

$permPanel = $panels->find(primaryPanelId($panels, $permUser));
check('پنل کاربر قطع نشد', (string) $permPanel['panel_status'] !== PanelRepository::STATUS_REVOKED,
    'status=' . $permPanel['panel_status']);
check('پیام راهنمای دسترسی دارد', str_contains((string) $permResult['message'], 'پشتیبانی'), (string) $permResult['message']);

$panel->modifyError = '';
$panel->httpStatusOverride = 0;

echo "  {$passed} موفق، {$failed} ناموفق\n";

exit($failed === 0 ? 0 : 1);
