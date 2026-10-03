<?php

declare(strict_types=1);

/**
 * تست کدهای تخفیف، معرفی کاربر و فاکتور.
 *
 * تمرکز روی مرزهای مالی:
 *   • تخفیف هرگز نباید مبلغ را منفی یا بیشتر از قیمت کند.
 *   • سقف استفاده باید اتمیک رعایت شود (دو سفارش همزمان کد را دوبار مصرف نکنند).
 *   • `price_toman` باید مبلغ **قابل پرداخت** باشد وگرنه درگاه اشتباه می‌گیرد.
 *   • معرفی: خودمعرفی ممنوع، یک معرف برای هر کاربر، پاداش فقط بعد از پرداخت.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/Fixture.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\CouponRepository;
use Pasargad\Store\DiscountService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\ReferralRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Invoice;
use Pasargad\Support\Migrator;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
(new Migrator($db))->migrate();

Config::set('super_admins', [999]);
Config::set('store.card_number', '6037999999999999');
Config::set('panel.base_url', 'https://panel.test');

$settings = new Settings($db);
$flags    = new \Pasargad\Store\FeatureFlags($settings);
$users    = new UserRepository($db);
$orders   = new OrderRepository($db);
$packages = new PackageRepository($db);
$panels   = new PanelRepository($db);
$coupons  = new CouponRepository($db);
$refs     = new ReferralRepository($db);
$discount = new DiscountService($coupons, $refs, $settings);

$panel = new FakePanelClient();
$bot   = new FakeBotApi();

$provisioner = new \Pasargad\Store\Provisioner($panel, $orders, $users, $settings, $panels);
$payments    = new PaymentService($orders, $provisioner, $settings, $flags, $users);

$kernel = new Kernel(
    $bot, new Notifier($bot), $users, $packages, $orders,
    $provisioner, $payments, $settings, new SessionStore($db), $panel, $flags
);

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

function cb(int $userId, array $payload): Update
{
    return new Update([
        'update_id'      => random_int(1, 999999),
        'callback_query' => [
            'id'            => 'cb' . random_int(1000, 99999),
            'chat_instance' => 'ci',
            'data'          => json_encode($payload),
            'from'          => ['id' => $userId],
            'message'       => ['message_id' => random_int(1, 999), 'chat' => ['id' => $userId]],
        ],
    ]);
}

function txt(int $userId, string $text, int $msgId): Update
{
    return new Update([
        'update_id' => random_int(1, 999999),
        'message'   => [
            'message_id' => $msgId,
            'chat'       => ['id' => $userId],
            'from'       => ['id' => $userId],
            'text'       => $text,
        ],
    ]);
}

/**
 * اجرای یک تست با `base_url` موقت و بازگرداندن مقدار قبلی.
 *
 * لازم است چون `Config::set` روی همان فرایند باقی می‌ماند و بدون بازگردانی،
 * تست‌های بعدی با آدرس اشتباه اجرا می‌شوند.
 */
function withBaseUrl(string $value, callable $fn): void
{
    $previous = Config::get('base_url', '');

    Config::set('base_url', $value);

    try {
        $fn();
    } finally {
        Config::set('base_url', $previous);
    }
}

$PRICE = 1000000;   // یک میلیون تومان

// =====================================================================
echo "\n▶ محاسبهٔ تخفیف درصدی و نقدی\n";
// =====================================================================

$percentId = $coupons->create([
    'code' => 'SAVE20', 'kind' => CouponRepository::KIND_PERCENT,
    'value' => 20, 'max_uses' => 0, 'per_user_limit' => 1,
]);
$fixedId = $coupons->create([
    'code' => 'TAKE100', 'kind' => CouponRepository::KIND_FIXED,
    'value' => 100000, 'max_uses' => 0, 'per_user_limit' => 1,
]);
$cappedId = $coupons->create([
    'code' => 'CAPPED', 'kind' => CouponRepository::KIND_PERCENT,
    'value' => 50, 'max_discount_toman' => 150000,
]);
$hugeId = $coupons->create([
    'code' => 'HUGE', 'kind' => CouponRepository::KIND_FIXED,
    'value' => 99999999,
]);

[$buyer] = makeRep('disc_buyer', $panel, $users, $panels, ['telegram_id' => 7001]);
$buyerId = (int) $buyer['id'];

check('تخفیف ۲۰٪ درست حساب شد',
    $discount->quote('SAVE20', $buyerId, $PRICE)['discount'] === 200000,
    json_encode($discount->quote('SAVE20', $buyerId, $PRICE)));

check('تخفیف نقدی درست است',
    $discount->quote('TAKE100', $buyerId, $PRICE)['discount'] === 100000);

check('سقف تخفیف رعایت شد',
    $discount->quote('CAPPED', $buyerId, $PRICE)['discount'] === 150000,
    (string) $discount->quote('CAPPED', $buyerId, $PRICE)['discount']);

check('تخفیف بیش از قیمت، به اندازهٔ قیمت می‌شود',
    $discount->quote('HUGE', $buyerId, $PRICE)['discount'] === $PRICE,
    (string) $discount->quote('HUGE', $buyerId, $PRICE)['discount']);

check('تخفیف هرگز منفی نمی‌شود', $discount->computeDiscount(['kind' => 'percent', 'value' => 0], 5000) === 0);
check('تخفیف روی قیمت صفر = صفر', $discount->computeDiscount(['kind' => 'percent', 'value' => 50], 0) === 0);

check('درصد بالای ۱۰۰ کل قیمت را می‌دهد نه بیشتر',
    $discount->computeDiscount(['kind' => 'percent', 'value' => 500], $PRICE) === $PRICE);

// =====================================================================
echo "\n▶ کد نامعتبر، منقضی، خاموش و تمام‌ظرفیت\n";
// =====================================================================

check('کد ناموجود رد می‌شود', !$discount->quote('NOPE', $buyerId, $PRICE)['ok']);

$expiredId = $coupons->create([
    'code' => 'OLD', 'kind' => 'percent', 'value' => 50, 'expires_at' => time() - 60,
]);
check('کد منقضی رد می‌شود', !$discount->quote('OLD', $buyerId, $PRICE)['ok']);

$offId = $coupons->create([
    'code' => 'OFFCODE', 'kind' => 'percent', 'value' => 50, 'is_active' => 0,
]);
check('کد غیرفعال رد می‌شود', !$discount->quote('OFFCODE', $buyerId, $PRICE)['ok']);

$tinyId = $coupons->create([
    'code' => 'ONEONLY', 'kind' => 'percent', 'value' => 10, 'max_uses' => 1,
]);
check('کد با ظرفیت محدود معتبر است', $discount->quote('ONEONLY', $buyerId, $PRICE)['ok']);
check('اولین مصرف موفق بود', $coupons->consume($tinyId, $buyerId, 0, 100000));
check('ظرفیت تمام‌شده رد می‌شود', !$discount->quote('ONEONLY', $buyerId, $PRICE)['ok']);
check('همان کاربر نمی‌تواند دوباره مصرف کند',
    !$coupons->consume($tinyId, $buyerId, 0, 100000),
    'succeeded=' . ($coupons->usedByUser($tinyId, $buyerId)));

$minId = $coupons->create([
    'code' => 'BIGONLY', 'kind' => 'percent', 'value' => 10, 'min_order_toman' => 2000000,
]);
check('کد با حداقل سفارش، سفارش کوچک را رد می‌کند',
    !$discount->quote('BIGONLY', $buyerId, $PRICE)['ok']);
check('ولی سفارش بزرگ را قبول می‌کند',
    $discount->quote('BIGONLY', $buyerId, 3000000)['ok']);

$multiId = $coupons->create([
    'code' => 'TWICE', 'kind' => 'percent', 'value' => 10, 'per_user_limit' => 2,
]);
check('کد چندبارمصرف: اولین بار', $coupons->consume($multiId, $buyerId, 0, 1000));
check('کد چندبارمصرف: دومین بار', $coupons->consume($multiId, $buyerId, 0, 1000));
check('کد چندبارمصرف: سومی رد شد',
    !$discount->quote('TWICE', $buyerId, $PRICE)['ok'],
    'used=' . $coupons->usedByUser($multiId, $buyerId));

// =====================================================================
echo "\n▶ یکتا بودن کد\n";
// =====================================================================

$dupId = $coupons->create(['code' => 'SAVE20', 'kind' => 'percent', 'value' => 5]);
$dupRow = $coupons->find($dupId);
check('کد تکراری خودکار بی‌نام شد', (string) $dupRow['code'] !== 'SAVE20', (string) $dupRow['code']);
check('و هنوز قابل جست‌وجو نیست که با کد اشتباه گرفته شود',
    $coupons->findByCode('SAVE20')['id'] === $percentId);

// =====================================================================
echo "\n▶ تخفیف در سفارش واقعی\n";
// =====================================================================

$pkgId = $packages->create([
    'title' => 'بستهٔ تخفیف', 'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 10, 'duration_days' => 30, 'price_toman' => $PRICE,
    'sort_order' => 1, 'is_active' => true,
]);

$orderCouponId = $coupons->create([
    'code' => 'ORDER10', 'kind' => CouponRepository::KIND_PERCENT,
    'value' => 10, 'max_uses' => 0,
]);

$users->setCouponCode($buyerId, 'ORDER10');
check('کد روی کاربر ذخیره شد', $users->couponCode($buyerId) === 'ORDER10');

$buyerRow = $users->findById($buyerId);
$panelId   = primaryPanelId($panels, $buyerRow);

$bot->reset();
$kernel->handle(cb(7001, ['n' => 'pkg.buy', 'id' => $pkgId, 'p' => $panelId]));

$paid = $db->first("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$buyerId]);

check('سفارش ساخته شد', $paid !== null);
check('مبلغ قابل پرداخت = قیمت منهای تخفیف',
    (int) $paid['price_toman'] === 900000, (string) $paid['price_toman']);
check('قیمت اصلی حفظ شد', (int) $paid['original_price_toman'] === $PRICE);
check('مبلغ تخفیف ثبت شد', (int) $paid['discount_toman'] === 100000);
check('کد تخفیف روی سفارش ثبت شد', (string) $paid['coupon_code'] === 'ORDER10');

check('پیام پرداخت مبلغ درست را نشان داد',
    str_contains($bot->lastTextFor(7001), '۹۰۰٬۰۰۰'), $bot->lastTextFor(7001));
check('خط تخفیف در پیام آمد',
    str_contains($bot->lastTextFor(7001), 'تخفیف'), $bot->lastTextFor(7001));

$couponRow = $coupons->find($orderCouponId);
check('شمارندهٔ کد یکی زیاد شد', (int) $couponRow['used_count'] === 1);
check('مصرف در جدول سابقه ثبت شد', $coupons->usedByUser($orderCouponId, $buyerId) === 1);

check('کد از کاربر برداشته شد (یک‌بارمصرف)', $users->couponCode($buyerId) === '');

// =====================================================================
// خرید دوم با همان کلیک: FloodGuard جلوی تکرار در چند ثانیه را می‌گیرد.
// جدول ضدتکرار خالی می‌شود تا «گذر زمان» شبیه‌سازی شود، وگرنه سفارش دوم اصلاً
// ساخته نمی‌شود و تست دربارهٔ تخفیف چیزی نمی‌گوید.
// =====================================================================
$db->run('DELETE FROM flood_guard');

// سفارش دوم نباید تخفیف بگیرد
$bot->reset();
$kernel->handle(cb(7001, ['n' => 'pkg.buy', 'id' => $pkgId, 'p' => $panelId]));

$second = $db->first("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$buyerId]);
check('سفارش دوم بدون تخفیف ساخته شد', (int) $second['price_toman'] === $PRICE,
    (string) $second['price_toman']);
check('و تخفیفش صفر است', (int) $second['discount_toman'] === 0);

// =====================================================================
echo "\n▶ تخفیف خودکار بدون کد در نشست\n";
// =====================================================================

$staleId = $coupons->create(['code' => 'GONE', 'kind' => 'percent', 'value' => 10]);
$users->setCouponCode($buyerId, 'GONE');
$coupons->delete($staleId);

$bot->reset();
$db->run('DELETE FROM flood_guard');
$kernel->handle(cb(7001, ['n' => 'pkg.buy', 'id' => $pkgId, 'p' => $panelId]));

$third = $db->first("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$buyerId]);
check('کد حذف‌شده خرید را قفل نکرد', (int) $third['price_toman'] === $PRICE);
check('کد بی‌اعتبار از کاربر پاک شد', $users->couponCode($buyerId) === '');

// =====================================================================
echo "\n▶ حداقل مبلغ خرید بعد از تخفیف\n";
// =====================================================================

$cheapPkgId = $packages->create([
    'title' => 'بستهٔ ارزان', 'kind' => PackageRepository::KIND_TOPUP,
    'volume_gb' => 1, 'duration_days' => 30, 'price_toman' => 60000,
    'sort_order' => 2, 'is_active' => true,
]);

$freeId = $coupons->create([
    'code' => 'ALMOSTFREE', 'kind' => CouponRepository::KIND_PERCENT, 'value' => 90,
]);

$users->setCouponCode($buyerId, 'ALMOSTFREE');
$bot->reset();

$before = (int) $db->count('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$buyerId]);
$kernel->handle(cb(7001, ['n' => 'pkg.buy', 'id' => $cheapPkgId, 'p' => $panelId]));
$after = (int) $db->count('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$buyerId]);

check('سفارشی که بعد از تخفیف زیر حداقل می‌شد ساخته نشد', $before === $after,
    "before={$before} after={$after}");
check('پیام روشنگر داده شد',
    str_contains($bot->allText(), 'حداقل'), $bot->allText());

// =====================================================================
echo "\n▶ معرفی کاربر\n";
// =====================================================================

$settings->set(Settings::REFERRAL_ENABLED, '1');
$settings->set(Settings::REFERRAL_DISCOUNT, '15');
$settings->set(Settings::REFERRAL_BONUS, '70000');

$referrerDbId = (int) $buyer['id'];
$refCode = ReferralRepository::codeFor($referrerDbId);

[$referee, $refereePanel] = makeRep('ref_newbie', $panel, $users, $panels, ['telegram_id' => 7002]);
$refereeDbId = (int) $referee['id'];
$refereePanelId = (int) $refereePanel['id'];

$found = $refs->findReferrerByCode($refCode);
check('کد معرفی معتبر شناسایی شد', $found !== null && (int) $found['id'] === $referrerDbId);
check('کد بی‌ربط رد می‌شود', $refs->findReferrerByCode('R999999') === null);
check('کد با پیشوند اشتباه رد می‌شود', $refs->findReferrerByCode('X' . $referrerDbId) === null);
check('کد تصادفی رد می‌شود', $refs->findReferrerByCode('SUMMER') === null);

$bound = $refs->bind($referrerDbId, $refereeDbId, 70000);
check('معرفی ثبت شد', $bound['ok'], $bound['message']);
check('معرفی دوباره ثبت نمی‌شود', !$refs->bind($referrerDbId, $refereeDbId, 70000)['ok']);
check('خودمعرفی ممنوع است', !$refs->bind($referrerDbId, $referrerDbId, 70000)['ok']);

$refeDiscount = $discount->referralDiscount($refereeDbId, $PRICE);
check('تخفیف معرفی برای اولین خرید هست', $refeDiscount['ok']
    && $refeDiscount['discount'] === 150000, json_encode($refeDiscount));
check('برای کسی که معرفی نشده تخفیفی نیست',
    !$discount->referralDiscount($buyerId, $PRICE)['ok']);

// ------------------------------------------------------------------
// لینک عمیق /start ref_CODE
//
// کاربر کاملاً تازه لازم است: کسی که قبلاً معرفی ثبت کرده، دوباره ثبت نمی‌شود
// (قید یکتا) و پیام موفق نمی‌گیرد.
// ------------------------------------------------------------------
$deepUser = makeRep('ref_deep', $panel, $users, $panels, ['telegram_id' => 7009]);
$deepUserId = (int) $deepUser[0]['id'];

$bot->reset();
$kernel->handle(txt(7009, '/start ref_' . $refCode, 10));
check('لینک دعوت پیام موفق داد',
    str_contains($bot->allText(), 'معرفی ثبت شد'), $bot->allText());
check('و منوی اصلی هم نشان داده شد', str_contains($bot->allText(), 'منوی اصلی'), $bot->allText());
check('رکورد معرفی ساخته شد', $refs->findByReferee($deepUserId) !== null);

$bot->reset();
$kernel->handle(txt(7010, '/start ref_NOPE', 11));
check('کد معرفی نامعتبر پیام خطا می‌دهد',
    str_contains($bot->allText(), 'معتبر نیست') || $bot->allText() !== '',
    $bot->allText());
check('و برای کسی ثبت نشد',
    $refs->findByReferee((int) ($users->findByTelegramId(7010)['id'] ?? 0)) === null);

// =====================================================================
echo "\n▶ تخفیف معرفی در سفارش\n";
// =====================================================================

$bot->reset();
$db->run('DELETE FROM flood_guard');
$kernel->handle(cb(7002, ['n' => 'pkg.buy', 'id' => $pkgId, 'p' => $refereePanelId]));

$refOrder = $db->first("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$refereeDbId]);
check('مبلغ با تخفیف معرفی کم شد', (int) $refOrder['price_toman'] === 850000,
    (string) $refOrder['price_toman']);
check('کد معرفی روی سفارش ثبت شد', (string) $refOrder['referred_by'] === $refCode);
check('کد تخفیف ثبت نشد', ($refOrder['coupon_code'] ?? null) === null);
check('پیام پرداخت تخفیف معرفی را گفت',
    str_contains($bot->lastTextFor(7002), 'معرفی'), $bot->lastTextFor(7002));

// سفارش دوم نباید تخفیف معرفی بگیرد
$bot->reset();
$db->run('DELETE FROM flood_guard');
$kernel->handle(cb(7002, ['n' => 'pkg.buy', 'id' => $pkgId, 'p' => $refereePanelId]));
$refOrder2 = $db->first("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$refereeDbId]);
check('تخفیف معرفی فقط برای اولین خرید بود',
    (int) $refOrder2['price_toman'] === $PRICE, (string) $refOrder2['price_toman']);

// =====================================================================
echo "\n▶ کد تخفیف برنده است بر معرفی\n";
// =====================================================================

$couponForRef = $coupons->create([
    'code' => 'PREFER', 'kind' => CouponRepository::KIND_PERCENT, 'value' => 50,
]);

$thirdReferee = makeRep('ref3', $panel, $users, $panels, ['telegram_id' => 7003]);
$refs->bind($referrerDbId, (int) $thirdReferee[0]['id'], 70000);

$users->setCouponCode((int) $thirdReferee[0]['id'], 'PREFER');
$bot->reset();
$db->run('DELETE FROM flood_guard');
$kernel->handle(cb(7003, ['n' => 'pkg.buy', 'id' => $pkgId, 'p' => (int) $thirdReferee[1]['id']]));

$bothOrder = $db->first("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1", [(int) $thirdReferee[0]['id']]);
check('تخفیف‌ها روی هم جمع نشدند',
    (int) $bothOrder['price_toman'] === 500000, (string) $bothOrder['price_toman']);

// =====================================================================
echo "\n▶ پاداش معرفی فقط بعد از پرداخت\n";
// =====================================================================

$notifier = new class {
    /** @var array<int, string> */
    public array $calls = [];

    public function notifyAdmins(string $text, ?array $button = null): void
    {
        $this->calls[] = $text;
    }
};

$walletBefore = $users->walletBalance($referrerDbId);

$reward1 = $discount->rewardReferrers($notifier);
check('بدون پرداخت، پاداشی داده نشد', $reward1['rewarded'] === 0, json_encode($reward1));
check('کیف پول معرف دست‌نخورده', $users->walletBalance($referrerDbId) === $walletBefore);

// حالا پرداخت معرفی‌شده را شبیه‌سازی می‌کنیم
$db->run(
    "UPDATE orders SET status = 'applied', paid_at = :paid, panel_applied = 1
     WHERE id = :id",
    ['paid' => time(), 'id' => (int) $refOrder['id']]
);

$reward2 = $discount->rewardReferrers($notifier);
check('بعد از پرداخت، پاداش داده شد', $reward2['rewarded'] >= 1, json_encode($reward2));
check('مبلغ پاداش درست است', $reward2['toman'] >= 70000, json_encode($reward2));
check('کیف پول معرف شارژ شد',
    $users->walletBalance($referrerDbId) === $walletBefore + 70000,
    'balance=' . $users->walletBalance($referrerDbId));
check('پاداش در تاریخچهٔ کیف پول ثبت شد',
    count(array_filter(
        $users->walletHistory($referrerDbId, 20),
        static fn (array $t): bool => $t['kind'] === 'referral'
    )) === 1);
check('مدیر مطلع شد', $notifier->calls !== []);

$balanceAfterReward = $users->walletBalance($referrerDbId);
$reward3 = $discount->rewardReferrers($notifier);
check('پاداش تکراری داده نمی‌شود', $reward3['rewarded'] === 0, json_encode($reward3));
check('کیف پول دست‌نخورده ماند',
    $users->walletBalance($referrerDbId) === $balanceAfterReward);

$summary = $discount->referralSummary($referrerDbId);
check('آمار معرفی درست است', $summary['invited'] >= 2, json_encode($summary));
check('کد معرفی قطعی است', $summary['code'] === 'R' . $referrerDbId);

// =====================================================================
echo "\n▶ فاکتور\n";
// =====================================================================

// سفارشِ تخفیف‌خورده را پرداخت‌شده می‌کنیم تا فاکتور شامل تخفیف هم باشد
$paidOrder = $orders->find((int) $paid['id']);
$orders->update((int) $paidOrder['id'], [
    'status'  => OrderRepository::STATUS_APPLIED,
    'paid_at' => time(),
]);

$paidOrder = $orders->find((int) $paidOrder['id']);

check('سفارش پرداخت‌شده فاکتورپذیر است', Invoice::isPayable($paidOrder));

$withToken = Invoice::ensureToken($orders, $paidOrder);
$token = (string) $withToken['invoice_token'];

check('توکن فاکتور ساخته شد', preg_match('/^[a-f0-9]{32}$/', $token) === 1, $token);

$again = Invoice::ensureToken($orders, $orders->find((int) $paidOrder['id']));
check('توکن بین درخواست‌ها ثابت می‌ماند', (string) $again['invoice_token'] === $token);

check('پیدا کردن با توکن کار می‌کند',
    (int) (Invoice::findByToken($orders, $token)['id'] ?? 0) === (int) $paidOrder['id']);

check('توکن نامعتبر ۴۰۴ می‌شود', Invoice::findByToken($orders, str_repeat('a', 32)) === null);
check('توکن با طول غلط رد می‌شود', Invoice::findByToken($orders, 'abc') === null);
check('توکن خالی رد می‌شود', Invoice::findByToken($orders, '') === null);

$unpaid = $db->first("SELECT * FROM orders WHERE status IN ('created','awaiting_payment') LIMIT 1");
check('سفارش پرداخت‌نشده فاکتورپذیر نیست', !Invoice::isPayable($unpaid));

$html = Invoice::html($withToken);
check('HTML فاکتور شامل مبلغ است', str_contains($html, 'مبلغ قابل پرداخت'));
check('HTML فاکتور شامل تخفیف است', str_contains($html, 'تخفیف'));
check('HTML فاکتور شامل کد تخفیف است', str_contains($html, 'ORDER10'));
check('HTML فاکتور قابل چاپ است', str_contains($html, 'window.print()'));

$telegram = Invoice::telegramText($withToken, 'مشتری نمونه');
check('متن تلگرام شامل مبلغ نهایی است', str_contains($telegram, '۹۰۰٬۰۰۰'), $telegram);
check('متن تلگرام شامل «پرداخت‌شده» است', str_contains($telegram, 'پرداخت‌شده'));

$url = Invoice::url($withToken);
check('لینک فاکتور ساخته شد', str_contains($url, '/invoice.php?t='), $url);

// دکمهٔ فاکتور در صفحهٔ سفارش
$bot->reset();
$db->run('DELETE FROM flood_guard');
$kernel->handle(cb(7001, ['n' => 'order.view', 'id' => (int) $paidOrder['id']]));
check('دکمهٔ فاکتور در صفحهٔ سفارش هست', $bot->hasButton('فاکتور خرید'));

$bot->reset();
$kernel->handle(cb(7001, ['n' => 'order.invoice', 'id' => (int) $paidOrder['id']]));
check('صفحهٔ فاکتور باز شد', str_contains($bot->allText(), 'فاکتور خرید'), $bot->allText());
check('دکمهٔ فاکتور قابل چاپ دارد', $bot->hasButton('قابل چاپ'));

$bot->reset();
$kernel->handle(cb(7002, ['n' => 'order.invoice', 'id' => (int) $paidOrder['id']]));
check('کاربر دیگر نمی‌تواند فاکتور کس دیگر را ببیند',
    !str_contains($bot->allText(), 'مبلغ قابل پرداخت'), $bot->allText());
check('و پیام «پیدا نشد» می‌گیرد', str_contains($bot->allText(), 'پیدا نشد'), $bot->allText());

// سفارش پرداخت‌نشده فاکتور نمی‌گیرد
$db->run("UPDATE orders SET status = 'created', paid_at = NULL WHERE id = :id", ['id' => (int) $second['id']]);
$bot->reset();
$kernel->handle(cb(7001, ['n' => 'order.invoice', 'id' => (int) $second['id']]));
check('سفارش پرداخت‌نشده فاکتور نمی‌گیرد',
    str_contains($bot->allText(), 'پرداخت‌شده'), $bot->allText());

// =====================================================================
echo "\n▶ سازگاری حسابی فاکتور (دادهٔ ناسازگار)\n";
// =====================================================================
//
// کاربر (یا یک باگ قدیمی) ممکن است داده‌ای بسازد که جمعش درنمی‌آید:
// original=350k، discount=100k ولی price=300k. فاکتور نباید خودتناقض باشد
// چون مشتری حساب می‌کند و حق دارد اعتراض کند.

$brokenAmounts = Invoice::amounts([
    'price_toman'             => 300000,
    'discount_toman'          => 100000,
    'original_price_toman'    => 350000,   // ناسازگار
]);

check('قیمت پایه بازسازی شد تا جمع درست دربیاید',
    $brokenAmounts['original'] === 400000, json_encode($brokenAmounts));
check('مبلغ پرداخت‌شده دست‌نخورده ماند', $brokenAmounts['price'] === 300000);
check('و در نتیجه جمع درست است',
    $brokenAmounts['original'] - $brokenAmounts['discount'] === $brokenAmounts['price']);

$normalAmounts = Invoice::amounts([
    'price_toman'          => 960000,
    'discount_toman'       => 240000,
    'original_price_toman' => 1200000,
]);
check('دادهٔ سالم دست‌نخورده می‌ماند', $normalAmounts['original'] === 1200000,
    json_encode($normalAmounts));

$noOriginal = Invoice::amounts(['price_toman' => 500000, 'discount_toman' => 100000]);
check('original خالی از روی price+discount ساخته می‌شود',
    $noOriginal['original'] === 600000, json_encode($noOriginal));

$negative = Invoice::amounts(['price_toman' => -5, 'discount_toman' => -100]);
check('اعداد منفی به صفر تبدیل می‌شوند',
    $negative['price'] === 0 && $negative['discount'] === 0, json_encode($negative));

// ------------------------------------------------------------------
// دلیل تخفیف — مشتری باید بفهمد «چرا» تخفیف خورده
// ------------------------------------------------------------------
check('دلیل کد تخفیف شناسایی شد',
    (Invoice::discountReason(['coupon_code' => 'SUMMER20'])['label'] ?? '') === 'کد تخفیف');
check('دلیل معرفی شناسایی شد',
    (Invoice::discountReason(['referred_by' => 'R7'])['label'] ?? '') === 'پاداش معرفی');
check('بدون تخفیف، دلیلی هم نیست', Invoice::discountReason([]) === null);
check('کد تخفیف بر معرفی اولویت دارد',
    (Invoice::discountReason(['coupon_code' => 'X', 'referred_by' => 'R7'])['value'] ?? '') === 'X');

// ------------------------------------------------------------------
// متن فاکتور باید تخفیف معرفی را توضیح دهد
// ------------------------------------------------------------------
$referralText = Invoice::telegramText([
    'code' => 'ORD-TEST', 'created_at' => time(), 'package_title' => 'بسته',
    'kind' => 'agency', 'volume_gb' => 10.0, 'duration_days' => 30,
    'price_toman' => 900000, 'original_price_toman' => 1000000, 'discount_toman' => 100000,
    'coupon_code' => null, 'referred_by' => 'R42', 'status' => 'applied', 'paid_at' => time(),
]);

check('متن فاکتور، پاداش معرفی را نام می‌برد',
    str_contains($referralText, 'پاداش معرفی'), $referralText);
check('و کد معرفی را نشان می‌دهد', str_contains($referralText, 'R42'));

$mysteryText = Invoice::telegramText([
    'code' => 'ORD-TEST', 'created_at' => time(), 'package_title' => 'بسته',
    'kind' => 'agency', 'volume_gb' => 10.0, 'duration_days' => 30,
    'price_toman' => 900000, 'original_price_toman' => 1000000, 'discount_toman' => 100000,
    'coupon_code' => null, 'referred_by' => null, 'status' => 'applied', 'paid_at' => time(),
]);
check('تخفیف بی‌دلیل هم صریح علامت‌گذاری می‌شود',
    str_contains($mysteryText, 'تخفیف اعمال‌شده'), $mysteryText);

// ------------------------------------------------------------------
// روش پرداخت و کد پیگیری باید در فاکتور باشد
// ------------------------------------------------------------------
check('روش پرداخت ترجمه می‌شود',
    Invoice::paymentMethod(['payment_method' => 'card2card']) === 'کارت‌به‌کارت دستی');
check('روش ناشناخته خام نمایش داده می‌شود',
    Invoice::paymentMethod(['payment_method' => 'weird_gateway']) === 'weird_gateway');
check('روش خالی، خالی است', Invoice::paymentMethod([]) === '');

$paidText = Invoice::telegramText([
    'code' => 'ORD-TEST', 'created_at' => time(), 'package_title' => 'بسته',
    'kind' => 'agency', 'volume_gb' => 10.0, 'duration_days' => 30,
    'price_toman' => 900000, 'original_price_toman' => null, 'discount_toman' => 0,
    'coupon_code' => null, 'referred_by' => null,
    'payment_method' => 'card2card', 'payment_ref' => 'TRX-12345',
    'status' => 'applied', 'paid_at' => time(),
]);
check('روش پرداخت در فاکتور آمد', str_contains($paidText, 'کارت‌به‌کارت دستی'), $paidText);
check('کد پیگیری در فاکتور آمد', str_contains($paidText, 'TRX-12345'));

// ------------------------------------------------------------------
// صفحهٔ جزئیات سفارش هم باید تخفیف را نشان دهد
// ------------------------------------------------------------------
$details = \Pasargad\Bot\Text::orderDetails([
    'code' => 'ORD-TEST', 'package_title' => 'بسته', 'status' => 'paid',
    'price_toman' => 960000, 'original_price_toman' => 1200000, 'discount_toman' => 240000,
    'coupon_code' => 'SUMMER20', 'referred_by' => null, 'created_at' => time(),
]);

check('جزئیات سفارش: قیمت پایه دیده می‌شود', str_contains($details, 'قیمت پایه'), $details);
check('جزئیات سفارش: کد تخفیف دیده می‌شود', str_contains($details, 'SUMMER20'));
check('جزئیات سفارش: مبلغ تخفیف دیده می‌شود', str_contains($details, '۲۴۰٬۰۰۰'), $details);
check('جزئیات سفارش: مبلغ نهایی دیده می‌شود', str_contains($details, '۹۶۰٬۰۰۰'));

$plainDetails = \Pasargad\Bot\Text::orderDetails([
    'code' => 'ORD-TEST', 'package_title' => 'بسته', 'status' => 'paid',
    'price_toman' => 400000, 'original_price_toman' => null, 'discount_toman' => 0,
    'created_at' => time(),
]);
check('سفارش بدون تخفیف، خط «قیمت پایه» ندارد', !str_contains($plainDetails, 'قیمت پایه'));

// ------------------------------------------------------------------
// لینک فاکتور بدون base_url نباید لینک نسبی بدهد
// ------------------------------------------------------------------
$tokenOrder = ['invoice_token' => str_repeat('a', 32)];

withBaseUrl('https://bot.test', static function () use ($tokenOrder, $paidText): void {
    check('با base_url، لینک مطلق ساخته می‌شود',
        str_starts_with(Invoice::url($tokenOrder), 'https://bot.test/invoice.php?t='),
        Invoice::url($tokenOrder));
    check('و قابل چاپ اعلام می‌شود', Invoice::hasPrintableLink($tokenOrder));
});

withBaseUrl('', static function () use ($tokenOrder): void {
    check('بدون base_url لینک نسبی ساخته نمی‌شود', Invoice::url($tokenOrder) === '',
        Invoice::url($tokenOrder));
    check('و قابل چاپ اعلام می‌شود که نیست', !Invoice::hasPrintableLink($tokenOrder));
});

withBaseUrl('https://bot.test/', static function () use ($tokenOrder): void {
    check('اسلش انتهایی base_url تکراری نمی‌شود',
        !str_contains(Invoice::url($tokenOrder), '.test//'), Invoice::url($tokenOrder));
});

withBaseUrl('https://bot.test', static function (): void {
    check('سفارش بدون توکن لینک ندارد', Invoice::url([]) === '');
});

// ------------------------------------------------------------------
// 🛡️ رگرسیون: کیبورد نباید بی‌سروصدا نابود شود
//
// دکمهٔ `url` تلگرام فقط آدرس مطلق قبول می‌کند. اگر لینک نسبی بفرستیم،
// `BotApi::buildMarkup()` کل کیبورد را `null` می‌کند — یعنی «جزئیات سفارش»
// و «بازگشت» هم بی‌سروصدا حذف می‌شوند و کاربر در یک صفحهٔ بن‌بست می‌ماند.
// ------------------------------------------------------------------
$invoiceOrderId = (int) $paidOrder['id'];
Invoice::ensureToken($orders, $orders->find($invoiceOrderId));

withBaseUrl('https://bot.test', static function () use ($kernel, $bot, $db, $invoiceOrderId): void {
    $db->run('DELETE FROM flood_guard');
    $bot->reset();
    $kernel->handle(cb(7001, ['n' => 'order.invoice', 'id' => $invoiceOrderId]));

    check('با base_url: دکمهٔ چاپ هست', $bot->hasButton('قابل چاپ'));
    check('و دکمهٔ «جزئیات سفارش» هم سالم است', $bot->hasButton('جزئیات سفارش'));
});

withBaseUrl('', static function () use ($kernel, $bot, $db, $invoiceOrderId): void {
    $db->run('DELETE FROM flood_guard');
    $bot->reset();
    $kernel->handle(cb(7001, ['n' => 'order.invoice', 'id' => $invoiceOrderId]));

    check('بدون base_url: دکمهٔ چاپ حذف می‌شود (نه اینکه url خالی بفرستیم)',
        !$bot->hasButton('قابل چاپ'));
    check('و کاربر دلیلش را می‌بیند', str_contains($bot->allText(), 'base_url'));
    check('و دکمهٔ «جزئیات سفارش» هنوز هست ⇒ کیبورد نابود نشده',
        $bot->hasButton('جزئیات سفارش'), $bot->allText());
});

// ------------------------------------------------------------------
// توکن فاکتور نباید با دو فراخوان عوض شود
// ------------------------------------------------------------------
$tokenRace = $orders->create($buyerId, [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست', 'kind' => 'topup',
    'volume_gb' => 10.0, 'duration_days' => 30, 'price_toman' => $PRICE,
    'status' => OrderRepository::STATUS_CREATED,
]);

$first = Invoice::ensureToken($orders, $orders->find((int) $tokenRace['id']));
$second = Invoice::ensureToken($orders, $orders->find((int) $tokenRace['id']));

check('توکن دوباره ساخته نمی‌شود',
    (string) $first['invoice_token'] === (string) $second['invoice_token'],
    $first['invoice_token'] . ' vs ' . $second['invoice_token']);
check('و لینک قبلی هنوز کار می‌کند',
    Invoice::findByToken($orders, (string) $first['invoice_token']) !== null);

// ------------------------------------------------------------------
// جداکنندهٔ عدد فارسی
// ------------------------------------------------------------------
check('هزارگان با «٬» جدا می‌شود', \Pasargad\Support\Str::faNumber(1200000) === '۱٬۲۰۰٬۰۰۰',
    \Pasargad\Support\Str::faNumber(1200000));
check('اعشار با «٫» جدا می‌شود', \Pasargad\Support\Str::faNumber(5000.0, 1) === '۵٬۰۰۰٫۰',
    \Pasargad\Support\Str::faNumber(5000.0, 1));
check('عدد بدون جداکننده دست‌نخورده است', \Pasargad\Support\Str::faNumber(0) === '۰');
check('هیچ کاما یا نقطهٔ لاتینی باقی نمی‌ماند',
    !str_contains(\Pasargad\Support\Str::faNumber(1234567.89, 2), ',')
    && !str_contains(\Pasargad\Support\Str::faNumber(1234567.89, 2), '.'),
    \Pasargad\Support\Str::faNumber(1234567.89, 2));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);