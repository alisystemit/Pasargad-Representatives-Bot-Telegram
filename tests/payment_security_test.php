<?php

declare(strict_types=1);

/**
 * تست امنیتی پرداخت و وبهوک.
 *
 * هر تست اینجا یک راه دور زدن کنترل را قفل می‌کند: امضای IPN، مبلغ پرداخت،
 * تلاش مجدد برای سفارش نهایی، رسید روی سفارش ارز دیجیتال، و بستن فروشگاه.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Payment\CardToCardGateway;
use Pasargad\Payment\NowPaymentsGateway;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$panel    = new \Pasargad\Panel\FakePanelClient();
$orders   = new OrderRepository($db);
$users    = new UserRepository($db);
$packages = new PackageRepository($db);
$settings = new Settings($db);
$prov     = new Provisioner($panel, $orders, $users, $settings);
$payments = new PaymentService($orders, $prov, $settings, null, $users);

$GB = 1073741824;

Config::set('store.card_number', '6037999999999999');
Config::set('nowpayments.api_key', 'test-ipn-key');
Config::set('nowpayments.ipn_secret', 'test-ipn-secret');
Config::set('store.toman_per_usd', 100000.0);

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
 * @param array<string, mixed> $order
 */
function makeUser(string $name, int $credit = 0): array
{
    global $users, $panel;

    $tid = 700000 + (crc32($name) % 90000);

    $id = $users->upsertByTelegram($tid, [
        'telegram_id'    => $tid,
        'panel_username' => $name,
        'panel_password' => Crypto::encrypt('pass'),
        'panel_status'   => 'active',
        'user_credit'    => $credit,
    ])['id'];

    $panel->addAdmin($name, ['data_limit' => 0, 'used_traffic' => 0]);

    return $users->findById($id);
}

/**
 * ساخت امضای معتبر NOWPayments برای یک بدنه.
 *
 * @param array<string, mixed> $payload
 * @return array{body:string, headers:array<string,string>}
 */
function signedIpn(array $payload, string $secret = 'test-ipn-secret'): array
{
    // بدنه باید دقیقاً همان بایت‌هایی باشد که امضا می‌شوند
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'body'    => (string) $body,
        'headers' => ['x-signature' => hash_hmac('sha512', (string) $body, $secret)],
    ];
}

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: امضای IPN روی بدنهٔ بازکدگذاری‌شده حساب می‌شد";
echo "\n════════════════════════════════════════\n";
// =====================================================================

$user = makeUser('ipn_admin');
$pkgId = $packages->create([
    'title' => 'بستهٔ تست', 'kind' => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb' => 100, 'duration_days' => 30, 'price_toman' => 500000, 'is_active' => true,
]);

// سفارش ارز دیجیتال با پرداخت ثبت‌شده
$order = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
]);

$orders->upsertPayment((int) $order['id'], [
    'method' => NowPaymentsGateway::NAME, 'amount_toman' => 500000,
    'external_id' => 'NP-UNIQ-1', 'status' => 'pending',
]);

// ---- ۱) بازکدگذاری مجدد با escape یونیکد → امضا نباید بخواند ----
$payload = [
    'payment_id'     => 'NP-UNIQ-1',
    'order_id'       => (string) $order['code'],
    'payment_status' => 'finished',
    'pay_amount'     => 5.0,
    'pay_currency'   => 'usd',
    'order_description' => 'Purchase بستهٔ تست',   // فارسی!
];

$body     = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
$headers  = ['x-signature' => hash_hmac('sha512', $body, 'test-ipn-secret')];
$reEncoded = (string) json_encode($payload);   // بدون UNESCAPED → فرق می‌کند

check('بازکدگذاری مجدد با فارسی فرق می‌کند', $reEncoded !== $body,
    'both=' . mb_strlen($reEncoded) . '/' . mb_strlen($body));

// شبیه‌سازی رفتار قدیمی: امضا روی بدنهٔ واقعی، ولی پاس دادن رشتهٔ بازکدگذاری‌شده
$gateway = new NowPaymentsGateway();
check('امضا روی رشتهٔ غلط رد می‌شود', !$gateway->verifyIpnSignature($headers, $reEncoded));
check('امضا روی بدنهٔ درست قبول می‌شود', $gateway->verifyIpnSignature($headers, $body));

// ---- ۲) IPN واقعی با بدنهٔ درست باید کار کند ----
$ipn = signedIpn($payload);
$result = $payments->handleIpn($ipn['body'] === '' ? [] : json_decode($ipn['body'], true), $ipn['headers'], $ipn['body']);
check('IPN با امضای معتبر پرداخت شد', $result['ok'] ?? false, (string) ($result['message'] ?? ''));

$row = $orders->find((int) $order['id']);
check('وضعیت سفارش paid شد', (string) $row['status'] === OrderRepository::STATUS_APPLIED || (string) $row['status'] === OrderRepository::STATUS_PAID,
    'status=' . $row['status']);

// ---- ۳) بدنهٔ خالی → باید رد شود نه اینکه با امضای نادرست مقایسه کند ----
$order2 = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
]);
$orders->upsertPayment((int) $order2['id'], [
    'method' => NowPaymentsGateway::NAME, 'amount_toman' => 500000,
    'external_id' => 'NP-EMPTY-BODY', 'status' => 'pending',
]);

$noRaw = $payments->handleIpn(
    ['payment_id' => 'NP-EMPTY-BODY', 'payment_status' => 'finished'],
    ['x-signature' => 'anything-at-all']
);
check('بدنهٔ خالی رد شد', !($noRaw['ok'] ?? false));
check('سفارش دوم پرداخت نشد', (string) $orders->find((int) $order2['id'])['status'] === OrderRepository::STATUS_AWAITING_PAYMENT);

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: مبلغ کمتر از قیمت سفارش بسته را فعال می‌کرد";
echo "\n════════════════════════════════════════\n";
// =====================================================================

$order3 = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
]);
$orders->upsertPayment((int) $order3['id'], [
    'method' => NowPaymentsGateway::NAME, 'amount_toman' => 500000,
    'external_id' => 'NP-CHEAP', 'status' => 'pending',
]);

// کاربر ۱ دلار به‌جای ۵ دلار می‌فرستد
$cheap = signedIpn([
    'payment_id'     => 'NP-CHEAP',
    'payment_status' => 'finished',
    'pay_amount'     => 1.0,
    'pay_currency'   => 'usd',
]);
$cheapResult = $payments->handleIpn(json_decode($cheap['body'], true), $cheap['headers'], $cheap['body']);
check('پرداخت کم‌مبلغ رد شد', !($cheapResult['ok'] ?? false), (string) ($cheapResult['message'] ?? ''));
check('سفارش کم‌مبلغ پرداخت نشد', (string) $orders->find((int) $order3['id'])['status'] === OrderRepository::STATUS_AWAITING_PAYMENT,
    'status=' . $orders->find((int) $order3['id'])['status']);

// مبلغ درست (با تلورانس ۲٪) باید پذیرفته شود
$good = signedIpn([
    'payment_id'     => 'NP-CHEAP',
    'payment_status' => 'finished',
    'pay_amount'     => 5.09,   // ۲٪ بالاتر از ۵ دلار
    'pay_currency'   => 'usd',
]);
$goodResult = $payments->handleIpn(json_decode($good['body'], true), $good['headers'], $good['body']);
check('مبلغ با تلورانس پذیرفته شد', $goodResult['ok'] ?? false, (string) ($goodResult['message'] ?? ''));

// مبلغ بسیار متفاوت → رد
$order4 = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
]);
$orders->upsertPayment((int) $order4['id'], [
    'method' => NowPaymentsGateway::NAME, 'amount_toman' => 500000,
    'external_id' => 'NP-WRONG', 'status' => 'pending',
]);
$wrong = signedIpn([
    'payment_id'     => 'NP-WRONG',
    'payment_status' => 'finished',
    'pay_amount'     => 100.0,
    'pay_currency'   => 'usd',
]);
$wrongResult = $payments->handleIpn(json_decode($wrong['body'], true), $wrong['headers'], $wrong['body']);
check('مبلغ ۲۰ برابر رد شد', !($wrongResult['ok'] ?? false));

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: سفارش بدون رکورد پرداخت قابل فعال‌سازی بود";
echo "\n════════════════════════════════════════\n";
// =====================================================================

$order5 = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
]);

$orphan = signedIpn([
    'payment_id'     => 'NP-UNKNOWN-99',
    'order_id'       => (string) $order5['code'],   // کد سفارش کنترل‌نشده!
    'payment_status' => 'finished',
    'pay_amount'     => 5.0,
    'pay_currency'   => 'usd',
]);
$orphanResult = $payments->handleIpn(json_decode($orphan['body'], true), $orphan['headers'], $orphan['body']);
check('IPN بدون رکورد پرداخت رد شد', !($orphanResult['ok'] ?? false));
check('سفارش یتیم پرداخت نشد', (string) $orders->find((int) $order5['id'])['status'] === OrderRepository::STATUS_AWAITING_PAYMENT,
    'status=' . $orders->find((int) $order5['id'])['status']);

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: رسید کارت‌به‌کارت روی سفارش ارز دیجیتال";
echo "\n════════════════════════════════════════\n";
// =====================================================================

// سفارش ارز دیجیتال در انتظار
$cryptoOrder = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method' => NowPaymentsGateway::NAME,
]);

$res = $payments->submitReceipt($orders->find((int) $cryptoOrder['id']), 'FAKE_PHOTO');
check('رسید روی سفارش ارز دیجیتال رد شد', !($res['ok'] ?? false), (string) $res['message']);
check('پیام راهنما دارد', str_contains((string) $res['message'], 'خودکار'), (string) $res['message']);
check('رسید ذخیره نشد', $orders->find((int) $cryptoOrder['id'])['receipt_file_id'] === null);

// سفارش کارت‌به‌کارت باید بپذیرد
$c2Order = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method' => CardToCardGateway::NAME,
]);
$res2 = $payments->submitReceipt($orders->find((int) $c2Order['id']), 'REAL_RECEIPT');
check('رسید کارت‌به‌کارت پذیرفته شد', $res2['ok'] ?? false, (string) $res2['message']);
check('رسید ذخیره شد', $orders->find((int) $c2Order['id'])['receipt_file_id'] === 'REAL_RECEIPT');

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: دکمهٔ رد پرداختِ قدیمی سفارش اجراشده را رد می‌کرد";
echo "\n════════════════════════════════════════\n";
// =====================================================================

$approved = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method' => CardToCardGateway::NAME,
]);
$orders->markPaid((int) $approved['id'], CardToCardGateway::NAME, 'x');
$prov->provision($orders->find((int) $approved['id']));

$beforeReject = $orders->find((int) $approved['id']);
check('سفارش اجرا شد', (string) $beforeReject['status'] === OrderRepository::STATUS_APPLIED,
    'status=' . $beforeReject['status']);

$rejectResult = $payments->reviewOrder($orders->find((int) $approved['id']), false, 999);
check('رد کردن سفارش اجراشده ناموفق بود', !($rejectResult['ok'] ?? false), (string) $rejectResult['message']);
check('پیام راهنما دارد', str_contains((string) $rejectResult['message'], 'اعمال'), (string) $rejectResult['message']);

$afterReject = $orders->find((int) $approved['id']);
check('وضعیت همچنان applied ماند', (string) $afterReject['status'] === OrderRepository::STATUS_APPLIED,
    'status=' . $afterReject['status']);

// سفارش در حال اجرا هم نباید رد شود
$applying = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_APPLYING,
]);
$rej2 = $payments->reviewOrder($orders->find((int) $applying['id']), false, 999);
check('رد کردن سفارش در حال اجرا ناموفق بود', !($rej2['ok'] ?? false));
check('وضعیت applying دست‌نخورده', (string) $orders->find((int) $applying['id'])['status'] === OrderRepository::STATUS_APPLYING);

// سفارش واقعاً در انتظار پرداخت باید رد شود
$okReject = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method' => CardToCardGateway::NAME,
]);
$rej3 = $payments->reviewOrder($orders->find((int) $okReject['id']), false, 999);
check('رد کردن سفارش در انتظار موفق بود', $rej3['ok'] ?? false);
check('وضعیت rejected', (string) $orders->find((int) $okReject['id'])['status'] === OrderRepository::STATUS_REJECTED);

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: تکرار IPN بسته را دوباره اعمال می‌کرد";
echo "\n════════════════════════════════════════\n";
// =====================================================================

$replayUser = makeUser('replay_admin');
$panel->addAdmin('replay_admin', ['data_limit' => 0, 'used_traffic' => 0]);

$replayOrder = $orders->create((int) $replayUser['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 50,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method' => NowPaymentsGateway::NAME,
]);
$orders->upsertPayment((int) $replayOrder['id'], [
    'method' => NowPaymentsGateway::NAME, 'amount_toman' => 500000,
    'external_id' => 'NP-REPLAY', 'status' => 'pending',
]);

$replayPayload = [
    'payment_id'     => 'NP-REPLAY',
    'order_id'       => (string) $replayOrder['code'],
    'payment_status' => 'finished',
    'pay_amount'     => 5.0,
    'pay_currency'   => 'usd',
];

$first = signedIpn($replayPayload);
$r1 = $payments->handleIpn(json_decode($first['body'], true), $first['headers'], $first['body']);
check('IPN اول پرداخت کرد', $r1['ok'] ?? false, (string) ($r1['message'] ?? ''));

$limitAfterFirst = (int) $panel->admins['replay_admin']['data_limit'];
check('حجم اعمال شد', $limitAfterFirst === 50 * $GB, 'limit=' . ($limitAfterFirst / $GB) . 'GB');

// همان IPN دوباره (NOWPayments گاهی تکرار می‌فرستد)
$second = signedIpn($replayPayload);
$r2 = $payments->handleIpn(json_decode($second['body'], true), $second['headers'], $second['body']);
$limitAfterSecond = (int) $panel->admins['replay_admin']['data_limit'];
check('IPN تکراری حجم را دوباره اضافه نکرد', $limitAfterSecond === 50 * $GB,
    'limit=' . ($limitAfterSecond / $GB) . 'GB');
check('پاسخ تکراری بی‌خطا بود', $r2['ok'] ?? false, (string) ($r2['message'] ?? ''));

// سومین بار هم
$third = signedIpn($replayPayload);
$payments->handleIpn(json_decode($third['body'], true), $third['headers'], $third['body']);
check('سومین تکرار هم بی‌اثر بود', (int) $panel->admins['replay_admin']['data_limit'] === 50 * $GB);

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: IPN پرداخت‌نشده بسته را فعال می‌کرد";
echo "\n════════════════════════════════════════\n";
// =====================================================================

$unpaid = $orders->create((int) $user['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method' => NowPaymentsGateway::NAME,
]);
$orders->upsertPayment((int) $unpaid['id'], [
    'method' => NowPaymentsGateway::NAME, 'amount_toman' => 500000,
    'external_id' => 'NP-PENDING', 'status' => 'pending',
]);

foreach (['waiting', 'pending', 'failed', 'expired', ''] as $badStatus) {
    $p = signedIpn([
        'payment_id'     => 'NP-PENDING',
        'order_id'       => (string) $unpaid['code'],
        'payment_status' => $badStatus,
        'pay_amount'     => 5.0,
        'pay_currency'   => 'usd',
    ]);
    $payments->handleIpn(json_decode($p['body'], true), $p['headers'], $p['body']);
}

check('وضعیت غیرپرداخت بسته را فعال نکرد',
    (string) $orders->find((int) $unpaid['id'])['status'] === OrderRepository::STATUS_AWAITING_PAYMENT,
    'status=' . $orders->find((int) $unpaid['id'])['status']);

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  باگ: امضای نادرست پذیرفته می‌شد";
echo "\n════════════════════════════════════════\n";
// =====================================================================

$secUser = makeUser('sig_admin');
$secOrder = $orders->create((int) $secUser['id'], [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 100,
    'duration_days' => 30, 'price_toman' => 500000,
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method' => NowPaymentsGateway::NAME,
]);
$orders->upsertPayment((int) $secOrder['id'], [
    'method' => NowPaymentsGateway::NAME, 'amount_toman' => 500000,
    'external_id' => 'NP-BADSIG', 'status' => 'pending',
]);

$badBody = (string) json_encode([
    'payment_id'     => 'NP-BADSIG',
    'order_id'       => (string) $secOrder['code'],
    'payment_status' => 'finished',
    'pay_amount'     => 5.0,
    'pay_currency'   => 'usd',
], JSON_UNESCAPED_UNICODE);

// کلید اشتباه
$bad1 = $payments->handleIpn(json_decode($badBody, true),
    ['x-signature' => hash_hmac('sha512', $badBody, 'WRONG-SECRET')], $badBody);
check('امضای HMAC با کلید اشتباه رد شد', !($bad1['ok'] ?? false));

// دستکاری بدنه با حفظ امضا
$tampered = str_replace('"finished"', '"waiting"', $badBody);
$bad2 = $payments->handleIpn(json_decode($tampered, true),
    ['x-signature' => hash_hmac('sha512', $badBody, 'test-ipn-secret')], $tampered);
check('دستکاری بدنه رد شد', !($bad2['ok'] ?? false));

check('سفارش با امضای بد پرداخت نشد',
    (string) $orders->find((int) $secOrder['id'])['status'] === OrderRepository::STATUS_AWAITING_PAYMENT,
    'status=' . $orders->find((int) $secOrder['id'])['status']);

// بدون هدر امضا
$bad3 = $payments->handleIpn(json_decode($badBody, true), [], $badBody);
check('بدون هدر امضا رد شد', !($bad3['ok'] ?? false));

// =====================================================================
echo "\n════════════════════════════════════════";
echo "\n  خلاصه";
echo "\n════════════════════════════════════════\n";
// =====================================================================

echo "  {$passed} موفق، {$failed} ناموفق\n";

exit($failed === 0 ? 0 : 1);