<?php

declare(strict_types=1);

/**
 * تست پنل‌های نمایندگی — قلب تغییر معماری.
 *
 * سه چیز باید اثبات شود:
 *   ۱) یک کاربر می‌تواند **چند پنل** داشته باشد، هر کدام مستقل.
 *   ۲) خرید بستهٔ `agency` یک حساب اپراتور روی پنل می‌سازد و یک‌بار اجرا
 *      می‌شود (idempotency روی مسیر برگشت‌ناپذیر).
 *   ۳) شارژ، سقف **همان پنلی** را بالا می‌برد که کاربر انتخاب کرده — نه
 *      پنل دیگر و نه پنل یک کاربر دیگر.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

// اکانت سازندهٔ پنل را فعال می‌کنیم تا AgencyService اجازهٔ ساخت پنل بدهد.
Config::set('panel.owner_username', 'root');
Config::set('panel.owner_password', 'rootpass');

$users    = new UserRepository($db);
$panels   = new PanelRepository($db);
$orders   = new OrderRepository($db);
$packages = new PackageRepository($db);
$settings = new Settings($db);
$panel    = new FakePanelClient();

$provisioner = new Provisioner($panel, $orders, $users, $settings, $panels);

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

$GB = 1073741824;

// ==================================================================
echo "\n▶ ساخت چند پنل برای یک کاربر\n";
// ==================================================================

$buyer = $users->upsertByTelegram(2001, ['telegram_id' => 2001, 'first_name' => 'رضا']);
$uid   = (int) $buyer['id'];

$agencyPkgId = $packages->create([
    'title'         => 'پنل ۱۰۰ گیگ',
    'kind'          => PackageRepository::KIND_AGENCY,
    'volume_gb'     => 100,
    'duration_days' => 30,
    'price_toman'   => 900000,
]);

$topupPkgId = $packages->create([
    'title'         => 'شارژ ۵۰ گیگ',
    'kind'          => PackageRepository::KIND_TOPUP,
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 400000,
]);

$agencyPkg = $packages->find($agencyPkgId);
$topupPkg  = $packages->find($topupPkgId);

/**
 * ساخت سفارش پرداخت‌شده و اجرایش.
 */
function paidOrder(OrderRepository $orders, int $userId, array $package, int $panelId = 0): array
{
    $order = $orders->create($userId, [
        'package_id'    => (int) $package['id'],
        'package_title' => (string) $package['title'],
        'kind'          => (string) $package['kind'],
        'volume_gb'     => (float) $package['volume_gb'],
        'duration_days' => (int) $package['duration_days'],
        'price_toman'   => (int) $package['price_toman'],
        'panel_id'      => $panelId > 0 ? $panelId : null,
    ]);

    $orders->markPaid((int) $order['id'], 'card2card', 'ref');

    return $orders->find((int) $order['id']);
}

// --- خرید اول ---
$r1 = $provisioner->provision(paidOrder($orders, $uid, $agencyPkg));
check('خرید اول پنل ساخت', ($r1['ok'] ?? false) === true, (string) ($r1['message'] ?? ''));

$panel1Id = (int) ($r1['details']['panel_id'] ?? 0);
$panel1   = $panels->find($panel1Id);

check('پنل در دیتابیس ثبت شد', $panel1 !== null, 'panel_id=' . $panel1Id);
check('یک حساب ادمین روی پنل ساخته شد', count($panel->createdAdmins) === 1);
check('نقش اپراتور اعمال شد', ($panel1['panel_role'] ?? '') !== '', 'role=' . (string) ($panel1['panel_role'] ?? 'NULL'));
check('سقف حجم روی پنل نشست', (int) $panel1['data_limit'] === 100 * $GB, 'limit=' . $panel1['data_limit']);
check('انقضا تنظیم شد', $panel1['access_expire_at'] !== null);
check('حجم خریداری‌شده ثبت شد', (int) $panel1['granted_volume'] === 100 * $GB);
check('منبع پنل «خرید» است', $panel1['source'] === PanelRepository::SOURCE_BOT);
check('پنل به سفارش لینک شد', (int) $panel1['order_id'] > 0);
check('رمز قابل رمزگشایی است', strlen($panels->plainPassword($panel1)) >= 8);

// --- خرید دوم (چندپنلی) ---
$r2 = $provisioner->provision(paidOrder($orders, $uid, $agencyPkg));
check('خرید دوم هم موفق بود', ($r2['ok'] ?? false) === true, (string) ($r2['message'] ?? ''));

$panel2Id = (int) ($r2['details']['panel_id'] ?? 0);
check('پنل دوم شناسهٔ متفاوت دارد', $panel2Id !== $panel1Id);
check('کاربر حالا ۲ پنل دارد', $panels->countByUser($uid) === 2, 'count=' . $panels->countByUser($uid));

$panel2 = $panels->find($panel2Id);
check('پنل دوم یوزرنیم متفاوت دارد',
    $panel2['panel_username'] !== $panel1['panel_username']);
check('پنل اول دست‌نخورده ماند',
    $panels->find($panel1Id)['data_limit'] === 100 * $GB);

// --- سفارش باید به پنل خودش لینک شود ---
check('سفارش اول به پنل اول لینک شد', (int) $orders->find(1)['panel_id'] === $panel1Id,
    'panel_id=' . var_export($orders->find(1)['panel_id'], true));
check('سفارش دوم به پنل دوم لینک شد', (int) $orders->find(2)['panel_id'] === $panel2Id,
    'panel_id=' . var_export($orders->find(2)['panel_id'], true));

// ==================================================================
echo "\n▶ idempotency: تلاش مجدد پس از ساخت موفق\n";
// ==================================================================

// سناریوی واقعی: ساخت روی پنل موفق شد ولی پیش از `applied` شدن سفارش، خطا
// رخ داد. اینجا دستی به وضعیت «قابل اجرا» برمی‌گردانیم تا مسیر retry را
// ببینیم. (اجرای دوبارهٔ یک سفارش *applied* درست است که رد شود چون پایانی است.)
$orders->update(2, [
    'status'     => OrderRepository::STATUS_PAID,
    'terminal_reason' => null,
    'paid_at'    => time(),
]);

$again = $provisioner->provision($orders->find(2));

check('تلاش مجدد ناموفق نشد', ($again['ok'] ?? false) === true, (string) ($again['message'] ?? ''));
check('حساب تازه‌ای ساخته نشد', count($panel->createdAdmins) === 2,
    'createdAdmins=' . count($panel->createdAdmins));
check('پنل تکراری ساخته نشد', $panels->countByUser($uid) === 2, 'count=' . $panels->countByUser($uid));

// اجرای دوبارهٔ یک سفارش نهایی باید رد شود
$orders->update(2, ['status' => OrderRepository::STATUS_APPLIED]);
$terminalReplay = $provisioner->provision($orders->find(2));
check('اجرای سفارش نهایی رد می‌شود', ($terminalReplay['ok'] ?? false) === false);

// ==================================================================
echo "\n▶ شارژ روی پنل انتخابی\n";
// ==================================================================

// پنل اول را کمی مصرف‌شده می‌کنیم تا تفاوت سقف‌ها گویا باشد
$panels->update($panel1Id, ['used_traffic' => 20 * $GB]);

$r3 = $provisioner->provision(paidOrder($orders, $uid, $topupPkg, $panel1Id));
check('شارژ موفق بود', ($r3['ok'] ?? false) === true, (string) ($r3['message'] ?? ''));

$after1 = $panels->find($panel1Id);
$after2 = $panels->find($panel2Id);

check('سقف پنل هدف بالا رفت', (int) $after1['data_limit'] === 150 * $GB,
    'limit=' . $after1['data_limit']);
check('پنل دیگر دست‌نخورده ماند', (int) $after2['data_limit'] === 100 * $GB,
    'other=' . $after2['data_limit']);
check('حجم خریداری‌شدهٔ پنل هدف جمع شد', (int) $after1['granted_volume'] === 150 * $GB);

// ==================================================================
echo "\n▶ شارژ روی پنل دوم (مستقل بودن)\n";
// ==================================================================

$r4 = $provisioner->provision(paidOrder($orders, $uid, $topupPkg, $panel2Id));
check('شارژ پنل دوم موفق بود', ($r4['ok'] ?? false) === true);

$after1 = $panels->find($panel1Id);
$after2 = $panels->find($panel2Id);
check('پنل اول همچنان ۱۵۰ گیگ', (int) $after1['data_limit'] === 150 * $GB);
check('پنل دوم به ۱۵۰ گیگ رسید', (int) $after2['data_limit'] === 150 * $GB);

// ==================================================================
echo "\n▶ شارژ روی پنل کاربر دیگر رد می‌شود\n";
// ==================================================================

$other = $users->upsertByTelegram(2002, ['telegram_id' => 2002]);
$r5    = $provisioner->provision(paidOrder($orders, (int) $other['id'], $topupPkg, $panel1Id));

check('شارژ پنل غریبه ناموفق بود', ($r5['ok'] ?? false) === false);
check('پیام خطا مناسب است', str_contains((string) $r5['message'], 'تعلق ندارد')
    || str_contains((string) $r5['message'], 'پیدا نشد'), (string) $r5['message']);
check('پنل غریبه تغییر نکرد', (int) $panels->find($panel1Id)['data_limit'] === 150 * $GB);

// ==================================================================
echo "\n▶ idempotency شارژ: حجم دوباره اعمال نمی‌شود\n";
// ==================================================================

// شبیه‌سازی تلاش مجددِ یک شارژ که PUT موفق شد ولی ثبت نهایی ناتمام ماند.
$orders->update(4, [
    'status'          => OrderRepository::STATUS_PAID,
    'terminal_reason' => null,
    'paid_at'         => time(),
    'panel_applied'   => 0,
    'error'           => null,
]);

$limitBeforeReplay = (int) $panels->find($panel1Id)['data_limit'];

$replayCharge = $provisioner->provision($orders->find(4));
check('تلاش مجدد شارژ خطا نداد', ($replayCharge['ok'] ?? false) === true,
    (string) ($replayCharge['message'] ?? ''));
check('سقف پنل دوبرابر نشد',
    (int) $panels->find($panel1Id)['data_limit'] === $limitBeforeReplay,
    'limit=' . $panels->find($panel1Id)['data_limit']);

// ==================================================================
echo "\n▶ انقضا با هر تمدید جلو می‌رود\n";
// ==================================================================

$orders2 = new OrderRepository($db);
$longPkgId = $packages->create([
    'title' => 'تمدید بلندمدت', 'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 1, 'duration_days' => 90, 'price_toman' => 500000,
]);
$longPkg = $packages->find($longPkgId);

$provisioner->provision(paidOrder($orders2, $uid, $longPkg, $panel1Id));
$expireAfter = (int) $panels->find($panel1Id)['access_expire_at'];

check('تمدید انقضا را جلو برد', $expireAfter > time() + 89 * 86400,
    'expire=' . $expireAfter . ' now=' . time());

// ==================================================================
echo "\n▶ بستهٔ legacy (user_credit) دیگر اجرا نمی‌شود\n";
// ==================================================================

$legacyOrder = $orders2->create($uid, [
    'package_title' => 'اعتبار کاربر قدیمی',
    'kind'          => PackageRepository::KIND_USER_CREDIT,
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 300000,
]);
$orders2->markPaid((int) $legacyOrder['id'], 'card2card', 'x');

$legacy = $provisioner->provision($orders2->find((int) $legacyOrder['id']));
check('بستهٔ حذف‌شده ناموفق است', ($legacy['ok'] ?? false) === false);
check('پیام به پشتیبانی ارجاع می‌دهد', str_contains((string) $legacy['message'], 'پشتیبانی'),
    (string) $legacy['message']);
check('سفارش پایانی شد', $orders2->find((int) $legacyOrder['id'])['terminal_reason'] !== null);

// ==================================================================
echo "\n▶ نرمال‌سازی نام نوع بسته\n";
// ==================================================================

check('panel_quota → topup', PackageRepository::normalizeKind('panel_quota') === PackageRepository::KIND_TOPUP);
check('پنل → agency', PackageRepository::normalizeKind('پنل') === PackageRepository::KIND_AGENCY);
check('شارژ → topup', PackageRepository::normalizeKind('شارژ') === PackageRepository::KIND_TOPUP);
check('نامحدود یعنی agency', PackageRepository::createsPanel(PackageRepository::KIND_AGENCY));
check('شارژ پنل نمی‌سازد', !PackageRepository::createsPanel(PackageRepository::KIND_TOPUP));

// ==================================================================
echo "\n▶ برچسب وضعیت\n";
// ==================================================================

$activeRow = ['panel_status' => 'active', 'access_expire_at' => null];
$expRow    = ['panel_status' => 'active', 'access_expire_at' => time() - 10];

check('پنل فعال، فعال است', !PanelRepository::isExpired($activeRow));
check('پنل فعال قابل استفاده است', PanelRepository::isUsable($activeRow));
check('پنل منقضی، منقضی است', PanelRepository::isExpired($expRow));
check('پنل منقضی قابل استفاده نیست', !PanelRepository::isUsable($expRow));
check('برچسب پنل منقضی درست است',
    str_contains(PanelRepository::statusLabel($expRow), 'تمام شده'),
    PanelRepository::statusLabel($expRow));

// ==================================================================
echo "\n▶ آمار و فهرست‌ها\n";
// ==================================================================

check('شمارش کل پنل‌ها', $panels->countAll() === 2, 'count=' . $panels->countAll());
check('فهرست کاربر ۲ پنل دارد', count($panels->listByUser($uid)) === 2);
check('پنل‌های فعال کاربر هر دو هستند', count($panels->listActiveByUser($uid)) === 2);
check('کاربر نماینده است', $panels->isRepresentative($uid));
check('کاربر دیگر نماینده نیست', !$panels->isRepresentative((int) $other['id']));
check('جست‌وجوی پنل با حروف بزرگ/کوچک', $panels->findByPanelUsername(strtoupper((string) $panel1['panel_username'])) !== null);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);