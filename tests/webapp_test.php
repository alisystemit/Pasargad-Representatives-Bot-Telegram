<?php

declare(strict_types=1);

/**
 * تست‌های مینی‌اپ تلگرام: اعتبارسنجی initData، API و نمایش.
 *
 * چرا این تست‌ها مهم‌اند؟
 *
 * مینی‌اپ تنها جایی است که کاربر **بدون** پاس‌کردن از فیلتر چتِ ربات به
 * داده دسترسی دارد. یک باگ در اعتبارسنجی `initData` یعنی هر کسی می‌تواند
 * با یک درخواست ساده پنل‌های دیگران (شامل رمز) و سفارش‌هایشان را بخواند.
 * پس این مسیر باید تست‌شده باشد، نه «به نظر درست».
 */

// ⚠️ ترتیب مهم: اول `bootstrap.php` (اتولودر) و بعد FakeBotApi — چون
// FakeBotApi از BotApi ارث می‌برد و باید هنگام تعریف کلاس در دسترس باشد.
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/Fixture.php';

use Pasargad\Telegram\FakeBotApi;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\WebApp\Api;
use Pasargad\WebApp\InitData;
use Pasargad\WebApp\View;

/* شناسه‌های ثابت تست. اعداد بزرگِ ساختگی‌اند تا با داده‌های واقعی قاطی
   نشوند؛ کاربر «مدیر» باید با `super_admins` کانفیگ هم‌خوان باشد. */
const ADMIN_TG  = 700001;
const USER_TG   = 700002;
const OTHER_TG  = 700003;
const BLOCKED_TG = 700004;

$db = TestDb::boot();
Settings::flush();

(new \Pasargad\Support\Migrator($db))->migrate();

$TOKEN = '123456789:TEST-TOKEN-FOR-WEBAPP';
Config::set('bot_token', $TOKEN);
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', [ADMIN_TG]);
Config::set('bot_username', 'PasargadTestBot');
Config::set('crypto_key', 'test-key-webapp');
Config::set('store.min_order_toman', 50000);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $extra = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo '  ✅ ' . $label . "\n";
        return;
    }

    $failed++;
    echo '  ❌ ' . $label . ($extra !== '' ? "\n      → " . $extra : '') . "\n";
}

/**
 * ساخت initData معتبر با امضای HMAC.
 */
function makeInitData(
    int $userId,
    string $firstName,
    array $extra = [],
    ?string $token = null,
    ?int $authDate = null
): string {
    $token = $token ?? '123456789:TEST-TOKEN-FOR-WEBAPP';

    $pairs = [
        'auth_date' => (string) ($authDate ?? time()),
        'query_id'  => 'AAH_test_query_id',
        'user'      => json_encode([
            'id'         => $userId,
            'first_name' => $firstName,
            'username'   => 'u' . $userId,
        ], JSON_UNESCAPED_UNICODE),
    ];

    foreach ($extra as $k => $v) {
        $pairs[$k] = $v;
    }

    ksort($pairs);

    $check = [];
    foreach ($pairs as $k => $v) {
        $check[] = $k . '=' . $v;
    }

    $secret = hash_hmac('sha256', 'WebAppData', $token, true);
    $hash   = hash_hmac('sha256', implode("\n", $check), $secret);

    $pairs['hash'] = $hash;

    $query = [];
    foreach ($pairs as $k => $v) {
        $query[] = urlencode($k) . '=' . urlencode($v);
    }

    return implode('&', $query);
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  مینی‌اپ تلگرام — اعتبارسنجی initData\n";
echo str_repeat('═', 62) . "\n";

$valid = makeInitData(USER_TG, 'علی');
$init  = InitData::parse($valid);

check('initData معتبر پذیرفته می‌شود', $init['ok'] === true, $init['message']);
check('کاربر از initData استخراج می‌شود', (int) ($init['user']['id'] ?? 0) === USER_TG);
check('نام کاربری درست خوانده می‌شود', ($init['user']['first_name'] ?? '') === 'علی');

/* ── امضای دست‌کاری‌شده ── */

// تغییر آیدی کاربر بدون عوض کردن hash — یعنی جعل هویت
$tamperedId = preg_replace('/user=[^&]*/', 'user=' . urlencode((string) json_encode(['id' => ADMIN_TG, 'first_name' => 'هکر'])), $valid, 1);
check('تغییر کاربر در initData رد می‌شود', InitData::parse((string) $tamperedId)['ok'] === false);

// تغییر یک فیلد دیگر پس از امضا
$tamperedStart = preg_replace('/auth_date=\d+/', 'auth_date=' . (time() - 10), $valid, 1);
check('دستکاری auth_date رد می‌شود', InitData::parse((string) $tamperedStart)['ok'] === false);

// توکن اشتباه
$wrongToken = makeInitData(USER_TG, 'علی', [], '999:WRONG-TOKEN');
check('امضای ساخته‌شده با توکن دیگر رد می‌شود', InitData::parse($wrongToken)['ok'] === false);

check('initData خالی رد می‌شود', InitData::parse('')['ok'] === false);
check('initData بدون hash رد می‌شود', InitData::parse('auth_date=1&user=%7B%22id%22%3A1%7D')['ok'] === false);
check('رشتهٔ بی‌ربط رد می‌شود', InitData::parse('hello world')['ok'] === false);

/* ── تازگی ──
   تاریخ از قبل داخل امضا گذاشته می‌شود (نه با regex روی رشته)؛ وگرنه
   hash دیگر نمی‌خواند و تست به‌جای «کهنگی»، «امضای خراب» را می‌سنجید. */
$stale = InitData::parse(makeInitData(USER_TG, 'علی', [], null, time() - 90000));
check('initData منقضی رد می‌شود', $stale['ok'] === false, $stale['message']);

$oneHour = InitData::parse(makeInitData(USER_TG, 'علی', [], null, time() - 3600));
check('initData یک‌ساعته معتبر است', $oneHour['ok'] === true, $oneHour['message']);

/* ── پارامتر شروع (کد معرفی) ── */
$withStart = InitData::parse(makeInitData(USER_TG, 'علی', ['start_param' => 'R999']));
check('start_param استخراج می‌شود', $withStart['start_param'] === 'R999');

$plusEncoded = InitData::parse(str_replace('%3D', '=',
    makeInitData(USER_TG, 'علی', ['start_param' => 'a b+c=d'])
));
check('مقدار شامل + و = درست decode می‌شود', $plusEncoded['start_param'] === 'a b+c=d',
    'دریافت: ' . $plusEncoded['start_param']);

echo "\n" . str_repeat('═', 62) . "\n";
echo "  مینی‌اپ — API\n";
echo str_repeat('═', 62) . "\n";

/**
 * ساخت یک Api احرازهویت‌شده برای یک کاربر.
 *
 * کلاینت تلگرامِ ساختگی تزریق می‌شود تا هیچ اعلانی واقعاً به تلگرام نرود —
 * تست باید میلی‌ثانیه‌ای باشد، نه ۱۰ ثانیه‌ای منتظر تایم‌اوت شبکه.
 */
function apiFor(int $userId, string $name = 'کاربر'): Api
{
    $api = new Api(new FakeBotApi());
    $api->authenticate(InitData::parse(makeInitData($userId, $name)));

    return $api;
}

$user = apiFor(USER_TG, 'علی');
$myId = $user->userId();

check('شناسهٔ داخلی کاربر ساخته شد', $myId > 0);

$boot = $user->handle('bootstrap', []);
check('bootstrap موفق است', ($boot['ok'] ?? false) === true, json_encode($boot) ?: 'empty');
check('bootstrap نام کاربر را دارد', ($boot['user']['first_name'] ?? '') === 'علی');
check('bootstrap کیف پول را دارد', array_key_exists('wallet', $boot['user'] ?? []));
check('bootstrap پرچم‌های ربات را دارد', isset($boot['app']['flags']));
check('bootstrap پنل‌ها را دارد', isset($boot['panels']['items']));
check('bootstrap شمارهٔ کاربر درست است', (int) ($boot['user']['id'] ?? 0) === USER_TG);

/* ── کنترل دسترسی مدیریتی ── */
$normal = apiFor(USER_TG, 'عادی');
$admin  = apiFor(ADMIN_TG, 'مدیر');

check('کاربر عادی به پنل مدیریت دسترسی ندارد', ($normal->handle('admin.overview', [])['forbidden'] ?? false) === true);
check('کاربر عادی آمار کاربران را نمی‌بیند', ($normal->handle('admin.users', [])['forbidden'] ?? false) === true);
check('کاربر عادی سوییچ‌ها را نمی‌بیند', ($normal->handle('admin.flags', [])['forbidden'] ?? false) === true);
check('کاربر عادی بسته‌ها را نمی‌بیند', ($normal->handle('admin.packages', [])['forbidden'] ?? false) === true);
check('کاربر عادی نمی‌تواند سفارش تأیید کند', ($normal->handle('admin.order.review', ['id' => 1, 'approved' => true])['forbidden'] ?? false) === true);
check('کاربر عادی نمی‌تواند کیف پول کسی را شارژ کند', ($normal->handle('admin.user.wallet', ['id' => 1, 'amount' => 1000])['forbidden'] ?? false) === true);
check('کاربر عادی نمی‌تواند کاربری را بلاک کند', ($normal->handle('admin.user.block', ['id' => 1, 'blocked' => true])['forbidden'] ?? false) === true);

check('سوپرادمین به پنل مدیریت دسترسی دارد', ($admin->handle('admin.overview', [])['forbidden'] ?? false) === false);
check('سوپرادمین آمار کاربران را می‌بیند', isset($admin->handle('admin.users', [])['items']));

/* ── جداسازی دادهٔ کاربران ── */
$other = (new UserRepository($db))->upsertByTelegram(OTHER_TG, ['first_name' => 'سایر', 'username' => 'other']);
$userRepo = new UserRepository($db);

$panelRepo = new PanelRepository($db);
$secretPanelId = $panelRepo->create((int) $other['id'], 'other_panel', 'SuperSecret123', [
    'data_limit' => 10737418240,
    'panel_status' => 'active',
]);

$leak = $user->handle('panel.detail', ['id' => $secretPanelId]);
check('پنل کاربر دیگر قابل خواندن نیست', ($leak['ok'] ?? true) === false, json_encode($leak));
check('رمز پنل دیگر نشت نمی‌کند', !str_contains(json_encode($leak, JSON_UNESCAPED_UNICODE), 'SuperSecret123'));

check('سینک پنل دیگر مجاز نیست', ($user->handle('panel.sync', ['id' => $secretPanelId])['ok'] ?? true) === false);
check('آمار پنل دیگر مجاز نیست', ($user->handle('panel.stats', ['id' => $secretPanelId])['ok'] ?? true) === false);
check('ساخت کانفیگ تست روی پنل دیگر مجاز نیست', ($user->handle('test.issue', ['panel_id' => $secretPanelId])['ok'] ?? true) === false);

/* ── سفارش کاربر دیگر ── */
$orderRepo = new OrderRepository($db);
$otherOrder = $orderRepo->create((int) $other['id'], [
    'package_title' => 'بستهٔ خصوصی',
    'kind'          => PackageRepository::KIND_AGENCY,
    'price_toman'   => 500000,
    'status'        => OrderRepository::STATUS_CREATED,
]);

$leakOrder = $user->handle('order.detail', ['id' => (int) $otherOrder['id']]);
check('سفارش کاربر دیگر قابل خواندن نیست', ($leakOrder['ok'] ?? true) === false);

check('پرداخت سفارش دیگر ممکن نیست', ($user->handle('order.pay', ['id' => (int) $otherOrder['id'], 'method' => 'card2card'])['ok'] ?? true) === false);
check('بررسی سفارش دیگر ممکن نیست', ($user->handle('order.check', ['id' => (int) $otherOrder['id']])['ok'] ?? true) === false);

/* ── تیکت کاربر دیگر ── */
$tickets = new \Pasargad\Store\TicketRepository($db);
$otherTicket = $tickets->create((int) $other['id'], 'other', 'پیام خصوصی محرمانه');

$leakTicket = $user->handle('ticket.detail', ['id' => $otherTicket]);
check('تیکت کاربر دیگر قابل خواندن نیست', ($leakTicket['ok'] ?? true) === false);
check('متن تیکت دیگر نشت نمی‌کند', !str_contains(json_encode($leakTicket, JSON_UNESCAPED_UNICODE), 'محرمانه'));

check('پاسخ به تیکت دیگر ممکن نیست', ($user->handle('ticket.reply', ['id' => $otherTicket, 'body' => 'سلام'])['ok'] ?? true) === false);
check('بستن تیکت دیگر ممکن نیست', ($user->handle('ticket.close', ['id' => $otherTicket])['ok'] ?? true) === false);

/* ── بلاک بودن کاربر ── */
$blockedRepo = new UserRepository($db);
$blockedId = (int) $blockedRepo->upsertByTelegram(BLOCKED_TG, ['first_name' => 'مسدود'])['id'];
$blockedRepo->setBlocked($blockedId, true, 'نقض قوانین');

$blockedApi    = new Api(new FakeBotApi());
$blockedAuth   = $blockedApi->authenticate(InitData::parse(makeInitData(BLOCKED_TG, 'مسدود')));

check('کاربر مسدود شماره ۴۰۳ می‌گیرد', $blockedAuth['ok'] === false);
check('پیام مسدودی شامل دلیل است', str_contains($blockedAuth['message'], 'نقض قوانین'));

/* ── اعتبارسنجی ورودی ── */
check('panelId غیرعددی به صفر تبدیل می‌شود', ($user->handle('panel.detail', ['id' => 'abc'])['ok'] ?? true) === false);
check('panelId منفی رد می‌شود', ($user->handle('panel.detail', ['id' => -5])['ok'] ?? true) === false);
check('عملیات ناموجود رد می‌شود', ($user->handle('does.not.exist', [])['ok'] ?? true) === false);

check('body خالی تیکت رد می‌شود', ($user->handle('ticket.create', ['category' => 'other', 'body' => ''])['ok'] ?? true) === false);
check('دستهٔ تیکت نامعتبر به other می‌رسد',
    (($user->handle('ticket.create', ['category' => 'چیزی‌نامعتبر', 'body' => 'سلام، این یک تست است'])['ok'] ?? false) === true));

check('مبلغ صفر برای شارژ کیف پول رد می‌شود',
    ($admin->handle('admin.user.wallet', ['id' => 1, 'amount' => 0])['ok'] ?? true) === false);
check('مبلغ غیرقابل‌قبول برای شارژ رد می‌شود',
    ($admin->handle('admin.user.wallet', ['id' => 1, 'amount' => 5_000_000_000])['ok'] ?? true) === false);

check('سوییچ نامعتبر رد می‌شود',
    ($admin->handle('admin.flag.toggle', ['key' => 'کلید-جعلی'])['ok'] ?? true) === false);
check('تنظیم خارج از allowlist رد می‌شود',
    ($admin->handle('admin.setting.save', ['key' => 'bot_token', 'value' => '123'])['ok'] ?? true) === false);
check('تنظیم داخل allowlist ذخیره می‌شود',
    ($admin->handle('admin.setting.save', ['key' => Settings::EXPIRE_GRACE_DAYS, 'value' => '5'])['ok'] ?? false) === true);
check('مقدار بیش از سقف تنظیم رد می‌شود',
    ($admin->handle('admin.setting.save', ['key' => Settings::LOYALTY_DISCOUNT, 'value' => '500'])['ok'] ?? true) === false);

/* ── بلاک کردن خودِ مدیر ── */
$adminUserId = (int) (new UserRepository($db))->findByTelegramId(ADMIN_TG)['id'];

check('مدیر نمی‌تواند خودش را بلاک کند',
    ($admin->handle('admin.user.block', ['id' => $adminUserId, 'blocked' => true])['ok'] ?? true) === false);
check('مدیر نمی‌تواند کل ربات را از این بخش خاموش کند',
    ($admin->handle('admin.flag.toggle', ['key' => Settings::BOT_ENABLED])['ok'] ?? true) === false);
check('ربات همچنان فعال است', (new Settings($db))->bool(Settings::BOT_ENABLED, true) === true);

/* ── جریان کامل خرید ── */
$packageId = (new PackageRepository($db))->create([
    'title'         => 'پنل تست',
    'kind'          => PackageRepository::KIND_AGENCY,
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'is_active'     => 1,
]);

$shop = $user->handle('shop.list', ['kind' => PackageRepository::KIND_AGENCY]);
check('فروشگاه بستهٔ فعال را برمی‌گرداند', count($shop['items'] ?? []) >= 1);
check('بستهٔ کاربر-ساخته در فروشگاه هست',
    in_array((int) $packageId, array_map(static fn ($p) => (int) $p['id'], $shop['items'] ?? []), true));

$quote = $user->handle('shop.quote', ['package_id' => (int) $packageId, 'days' => 30]);
check('پیش‌فاکتور بدون ساخت سفارش کار می‌کند', ($quote['ok'] ?? false) === true);
check('مبلغ پیش‌فاکتور درست است', (int) ($quote['quote']['final'] ?? 0) === 500000);
check('پیش‌فاکتور سفارشی نمی‌سازد', $orderRepo->countByUser($myId) === 0);

$created = $user->handle('order.create', ['package_id' => (int) $packageId, 'days' => 30]);
check('ساخت سفارش موفق است', ($created['ok'] ?? false) === true, json_encode($created, JSON_UNESCAPED_UNICODE));

$newOrderId = (int) ($created['order']['id'] ?? 0);
check('سفارش در دیتابیس ثبت شد', $orderRepo->find($newOrderId) !== null);

$detail = $user->handle('order.detail', ['id' => $newOrderId]);
check('جزئیات سفارش خودم باز می‌شود', ($detail['ok'] ?? false) === true);
check('اقدال «پرداخت» برای سفارش تازه پیشنهاد شده', in_array('pay', $detail['order']['actions'] ?? [], true));
check('فاکتور برای سفارش پرداخت‌نشده صادر نمی‌شود', ($detail['order']['invoice_ready'] ?? true) === false);

$invEarly = $user->handle('order.invoice', ['id' => $newOrderId]);
check('فاکتور سفارش پرداخت‌نشده رد می‌شود', ($invEarly['ok'] ?? true) === false);

/* ── سقف خرید هر کاربر ── */
$orderRepo->update($newOrderId, ['status' => OrderRepository::STATUS_APPLIED]);

$again = $user->handle('order.create', ['package_id' => (int) $packageId, 'days' => 30]);
check('سفارش تکراری مجاز است وقتی max_per_user صفر است', ($again['ok'] ?? false) === true);

$pkgRepo = new PackageRepository($db);
$limitedId = $pkgRepo->create([
    'title'         => 'بستهٔ محدود',
    'kind'          => PackageRepository::KIND_TOPUP,
    'volume_gb'     => 10,
    'duration_days' => 30,
    'price_toman'   => 300000,
    'max_per_user'  => 1,
    'is_active'     => 1,
]);

// شارژ بدون پنل باید رد شود
$noPanel = $user->handle('order.create', ['package_id' => (int) $limitedId, 'days' => 30]);
check('شارژ بدون داشتن پنل رد می‌شود', ($noPanel['ok'] ?? true) === false,
    json_encode($noPanel, JSON_UNESCAPED_UNICODE));

// حالا یک پنل برای کاربر بساز
$myPanelId = $panelRepo->create($myId, 'my_panel', 'MyPass123', [
    'data_limit'   => 10737418240,
    'panel_status' => 'active',
]);

$firstTopup = $user->handle('order.create', ['package_id' => (int) $limitedId, 'days' => 30]);
check('اولین شارژ موفق است', ($firstTopup['ok'] ?? false) === true,
    json_encode($firstTopup, JSON_UNESCAPED_UNICODE));

/* سقف خرید فقط سفارش‌های **پرداخت‌شده/اجرا شده** را می‌شمارد — دقیقاً مثل
   ربات. سفارشِ ثبت‌شده ولی پرداخت‌نشده نباید جلوی خرید بعدی را بگیرد. */
$secondTopup = $user->handle('order.create', ['package_id' => (int) $limitedId, 'days' => 30]);
check('سفارش پرداخت‌نشده سقف خرید را پر نمی‌کند', ($secondTopup['ok'] ?? false) === true,
    json_encode($secondTopup, JSON_UNESCAPED_UNICODE));

$orderRepo->update((int) ($secondTopup['order']['id'] ?? 0), ['status' => OrderRepository::STATUS_APPLIED]);

$thirdTopup = $user->handle('order.create', ['package_id' => (int) $limitedId, 'days' => 30]);
check('سفارش اجراشده سقف خرید را پر می‌کند', ($thirdTopup['ok'] ?? true) === false,
    json_encode($thirdTopup, JSON_UNESCAPED_UNICODE));

/* ── شارژ پنل غریبه ── */
$foreignTopup = $user->handle('order.create', [
    'package_id' => (int) $limitedId,
    'days'       => 30,
    'panel_id'   => $secretPanelId,
]);
check('شارژ روی پنل غریبه رد می‌شود', ($foreignTopup['ok'] ?? true) === false);

/* ── کد تخفیف ── */
$couponId = (new \Pasargad\Store\CouponRepository($db))->create([
    'code'            => 'WEBAPP20',
    'kind'            => \Pasargad\Store\CouponRepository::KIND_PERCENT,
    'value'           => 20,
    'max_uses'        => 5,
    'per_user_limit'  => 1,
    'min_order_toman' => 100000,
]);

/* کد وقتی معتبر است که هم برای مبلغِ اعلام‌شده و هم برای مبلغِ واقعیِ
   خرید صدق کند. اینجا هر دو بررسی می‌شوند تا رفتار واقعی سنجیده شود. */
check('کد با مبلغ کمتر از حداقل کد رد می‌شود',
    ($user->handle('coupon.apply', ['code' => 'WEBAPP20', 'price' => 50000])['ok'] ?? true) === false);

$apply = $user->handle('coupon.apply', ['code' => 'WEBAPP20', 'price' => 500000]);
check('کد تخفیف معتبر اعمال می‌شود', ($apply['ok'] ?? false) === true, json_encode($apply, JSON_UNESCAPED_UNICODE));
check('کد تخفیف روی کاربر ذخیره شد', $userRepo->couponCode($myId) === 'WEBAPP20');

$badCoupon = $user->handle('coupon.apply', ['code' => 'NOPE']);
check('کد تخفیف نامعتبر رد می‌شود', ($badCoupon['ok'] ?? true) === false);

$cleared = $user->handle('coupon.clear', []);
check('حذف کد تخفیف موفق است', ($cleared['ok'] ?? false) === true);
check('کد تخفیف حذف شد', $userRepo->couponCode($myId) === '');

/* ── کیف پول ── */
$walletBefore = $userRepo->walletBalance($myId);

$adj = $admin->handle('admin.user.wallet', [
    'id'     => $myId,
    'amount' => 250000,
    'note'   => 'شارژ تست',
]);

check('شارژ کیف پول موفق است', ($adj['ok'] ?? false) === true);
check('موجودی درست است', $userRepo->walletBalance($myId) === $walletBefore + 250000,
    'موجودی: ' . $userRepo->walletBalance($myId));

/* کسرِ بیشتر از موجودی باید موجودی را صفر کند، نه بدهکار — و مقدارِ منفی
   باید به‌درستی parse شود (علامت منفی در `intInput` حفظ می‌شود ولی فقط
   وقتی خودِ درخواست منفی باشد). */
$deduct = $admin->handle('admin.user.wallet', [
    'id'     => $myId,
    'amount' => -1000000,
    'note'   => 'کسر تست',
]);

check('کسر کیف پول موفق است', ($deduct['ok'] ?? false) === true);
check('موجودی منفی نمی‌شود', $userRepo->walletBalance($myId) === 0,
    'موجودی: ' . $userRepo->walletBalance($myId));

/* ── تیکت: ساخت، پاسخ، بستن ── */
$mine = $user->handle('ticket.create', ['category' => 'panel', 'body' => 'سلام، پنل من کار نمی‌کند.']);
check('ثبت تیکت موفق است', ($mine['ok'] ?? false) === true);

$myTicketId = (int) ($mine['ticket']['id'] ?? 0);

$replied = $user->handle('ticket.reply', ['id' => $myTicketId, 'body' => 'پیام دوم']);
check('پاسخ به تیکت خود موفق است', ($replied['ok'] ?? false) === true);
check('پاسخ در تاریخچه هست', count($replied['messages'] ?? []) === 2);

$adminReply = $admin->handle('admin.ticket.reply', ['id' => $myTicketId, 'body' => 'بررسی شد.']);
check('مدیر می‌تواند پاسخ دهد', ($adminReply['ok'] ?? false) === true);
check('وضعیت تیکت answered شد', ($adminReply['ticket']['status'] ?? '') === 'answered');

$closed = $user->handle('ticket.close', ['id' => $myTicketId]);
check('کاربر می‌تواند تیکتش را ببندد', ($closed['ok'] ?? false) === true);
check('وضعیت تیکت closed شد', ($closed['ticket']['status'] ?? '') === 'closed');

$reopenReply = $user->handle('ticket.reply', ['id' => $myTicketId, 'body' => 'هنوز مشکل دارم']);
check('پاسخ به تیکت بسته دوباره بازش می‌کند', ($reopenReply['ticket']['status'] ?? '') === 'open');

echo "\n" . str_repeat('═', 62) . "\n";
echo "  مینی‌اپ — نمایش (View)\n";
echo str_repeat('═', 62) . "\n";

$panelRow = [
    'panel_username'    => 'demo_panel',
    'panel_status'      => PanelRepository::STATUS_ACTIVE,
    'data_limit'        => 10737418240,   // ۱۰ گیگ
    'used_traffic'      => 5368709120,    // ۵ گیگ
    'access_expire_at'  => time() + 20 * 86400,
    'user_limit'        => 50,
    'users_total'       => 12,
    'users_active'      => 9,
    'users_disabled'    => 3,
    'stats_at'          => time(),
];

$card = View::panelCard($panelRow, 3);
check('درصد مصرف درست حساب می‌شود', (int) $card['traffic']['percent'] === 50);
check('باقی‌مانده درست است', (int) $card['traffic']['left'] === 5368709120);
check('برچسب وضعیت فعال است', $card['status']['tone'] === 'ok');
check('روزهای باقی‌مانده درست است', (int) $card['days_left'] === 20);

/* قرارداد پنل: data_limit = 0 یعنی نامحدود، نه «ظرفیت پر شده» */
$unlimited = View::panelTraffic(['data_limit' => 0, 'used_traffic' => 999]);
check('حجم صفر = نامحدود', $unlimited['unlimited'] === true);
check('درصد نامحدود null است (نه صفر)', $unlimited['percent'] === null);

/* مصرف بیشتر از سقف نباید درصد را بیش از ۱۰۰ بدهد */
$over = View::panelTraffic(['data_limit' => 100, 'used_traffic' => 500]);
check('درصد هرگز از ۱۰۰ بیشتر نمی‌شود', $over['percent'] === 100);
check('باقی‌مانده منفی نمی‌شود', $over['left'] === 0);

$expired = View::panelCard([
    'panel_username'   => 'old_panel',
    'panel_status'     => PanelRepository::STATUS_ACTIVE,
    'access_expire_at' => time() - 86400,
], 3);
// ۱۰ روز گذشته ⇒ مهلت ارفاقیِ ۳ روزه هم تمام شده ⇒ قطعی «منقضی».
$expired = View::panelCard([
    'id'             => 11,
    'panel_username' => 'old_panel',
    'panel_status'   => PanelRepository::STATUS_ACTIVE,
    'access_expire_at' => time() - 10 * 86400,
], 3);
check('پنل منقضی، حتی با status فعال، منقضی گزارش می‌شود', $expired['status']['tone'] === 'bad');

// ۱ روز گذشته ⇒ هنوز داخل مهلت ارفاقی ⇒ «هشدار» نه «قطعی».
$grace = View::panelCard([
    'id'             => 12,
    'panel_username' => 'grace_panel',
    'panel_status'   => PanelRepository::STATUS_ACTIVE,
    'access_expire_at' => time() - 86400,
], 3);
check('پنل در مهلت ارفاقی tone هشدار می‌گیرد', $grace['status']['tone'] === 'warn');
check('پنل در مهلت ارفاقی usable=false است', $grace['usable'] === false);

/* مبلغ سفارش باید خودتناقض نباشد */
$amounts = \Pasargad\Support\Invoice::amounts([
    'price_toman'          => 300000,
    'discount_toman'       => 100000,
    'original_price_toman' => 350000,
]);
check('مبلغ فاکتور ناسازگار بازسازی می‌شود', $amounts['original'] === 400000,
    'original: ' . $amounts['original']);

$orderView = View::order([
    'id'                => 5,
    'code'              => 'ORD-ABC123',
    'package_title'     => 'بستهٔ تست',
    'kind'              => PackageRepository::KIND_AGENCY,
    'price_toman'       => 300000,
    'discount_toman'    => 50000,
    'original_price_toman' => 350000,
    'status'            => OrderRepository::STATUS_AWAITING_PAYMENT,
    'payment_method'    => 'card2card',
    'payment_payload'   => 'javascript:alert(1)',
    'created_at'        => time(),
]);

check('لینک غیرامن در pay_url نمی‌آید', $orderView['pay_url'] === null);
check('اقدام رسید فقط برای کارت‌به‌کارت پیشنهاد می‌شود',
    in_array('receipt', View::orderDetail([
        'id'             => 5,
        'status'         => OrderRepository::STATUS_AWAITING_PAYMENT,
        'payment_method' => 'card2card',
    ])['actions'] ?? [], true));

check('اقدام رسید برای ارز دیجیتال پیشنهاد نمی‌شود',
    !in_array('receipt', View::orderDetail([
        'id'             => 5,
        'status'         => OrderRepository::STATUS_AWAITING_PAYMENT,
        'payment_method' => 'nowpayments',
    ])['actions'] ?? [], true));

check('View::safeUrl فقط http/https را قبول می‌کند', View::safeUrl('ftp://x') === null);
check('View::safeUrl آدرس https را نگه می‌دارد', View::safeUrl('https://x.com/a') === 'https://x.com/a');

echo "\n" . str_repeat('═', 62) . "\n";
echo "  مینی‌اپ — آپلود رسید\n";
echo str_repeat('═', 62) . "\n";

/*
 * آپلود رسید تنها جایی است که فایلِ کاربر (base64) به سرور می‌رسد، پس
 * باید کاملاً بسته باشد. با کلاینت ساختگی بررسی می‌شود که:
 *   • فقط تصویرِ واقعاً معتبر عبور می‌کند (نه فایل متنی با نام png)
 *   • فایل موقت پاک می‌شود
 */
$bot     = new FakeBotApi();
$apiFile = new Api($bot);
$apiFile->authenticate(InitData::parse(makeInitData(USER_TG, 'رسید')));

$upload = new ReflectionMethod(Api::class, 'uploadReceipt');
$upload->setAccessible(true);

/*
 * یک PNG واقعی و کوچک. `getimagesizefromstring()` روی همین اعتبارسنجی می‌کند
 * که سرور انجام می‌دهد، پس اگر اینجا معتبر نباشد هر تست آپلود بی‌معنی می‌شود.
 */
$png = (static function (): string {
    $image = imagecreatetruecolor(6, 6);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    if (@getimagesizefromstring($bytes) === false) {
        throw new RuntimeException('ساخت PNG آزمایشی ناموفق بود.');
    }

    return base64_encode($bytes);
})();

$send = static fn (string $data): string => (string) $upload->invoke($apiFile, $data);

/*
 * `file_id` در تست برنمی‌گردد چون کلاینت ساختگی پاسخ واقعی تلگرام
 * (با آرایهٔ `photo`) نمی‌سازد. پس معیارِ درست این است: **آیا فایل به
 * تلگرام فرستاده شد یا نه** — کلاینت ساختگی هر `sendPhoto` را ثبت می‌کند.
 */
check('تصویر واقعی به تلگرام فرستاده می‌شود',
    (static function () use ($send, $bot, $png): bool {
        $before = count($bot->sentPhotos);
        $send($png);

        return count($bot->sentPhotos) === $before + 1;
    })(),
    'sent: ' . count($bot->sentPhotos));

check('قالب data URI هم پذیرفته می‌شود',
    (static function () use ($send, $bot, $png): bool {
        $before = count($bot->sentPhotos);
        $send('data:image/png;base64,' . $png);

        return count($bot->sentPhotos) === $before + 1;
    })());

$sentBefore = count($bot->sentPhotos);

check('فایل متنی با نام png رد می‌شود',
    $send(base64_encode('<?php system($_GET[0]); ?>')) === ''
    && count($bot->sentPhotos) === $sentBefore);

check('SVG (با onload) رد می‌شود',
    $send('data:image/svg+xml;base64,' . base64_encode('<svg onload=alert(1)>')) === ''
    && count($bot->sentPhotos) === $sentBefore);

check('base64 خراب رد می‌شود', $send('!!! not base64 !!!') === '');
check('ورودی خالی رد می‌شود', $send('') === '');
check('فایل بزرگ‌تر از ۵ مگابایت رد می‌شود',
    $send(base64_encode(str_repeat('A', 6 * 1024 * 1024))) === '');

check('هیچ ورودی نامعتبری به تلگرام نرسید', count($bot->sentPhotos) === $sentBefore,
    'sent: ' . count($bot->sentPhotos));

$tmp = PASARGAD_ROOT . '/data/tmp';
check('فایل موقت باقی نمی‌ماند', !is_dir($tmp) || (glob($tmp . '/*') ?: []) === [],
    'leftover: ' . implode(',', glob($tmp . '/*') ?: []));

echo "\n" . str_repeat('═', 62) . "\n";
echo "  مینی‌اپ — دکمهٔ web_app و امنیت دکمه‌ها\n";
echo str_repeat('═', 62) . "\n";

/** BotApi را با توکن تست می‌سازیم (کلاس والد ctor را صدا می‌زند). */
final class WebAppBotApi extends \Pasargad\Telegram\BotApi
{
    public function build(array $keyboard): ?array
    {
        return $this->buildMarkup($keyboard);
    }
}

$testBot = new WebAppBotApi($TOKEN);

$markup = $testBot->build([
    [['text' => '📱 اپلیکیشن', 'web_app' => 'https://bot.example.com/webapp.php']],
]);

check('دکمهٔ web_app ساخته می‌شود (شکل آبجکت تلگرام)',
    ($markup['inline_keyboard'][0][0]['web_app']['url'] ?? '') === 'https://bot.example.com/webapp.php',
    json_encode($markup, JSON_UNESCAPED_SLASHES));

check('دکمهٔ web_app باید callback_data نداشته باشد',
    !isset($markup['inline_keyboard'][0][0]['callback_data']));

check('ورودی آبجکتی web_app هم پذیرفته می‌شود',
    ($testBot->build([[['text' => 'x', 'web_app' => ['url' => 'https://bot.example.com/webapp.php']]]])
        ['inline_keyboard'][0][0]['web_app']['url'] ?? '') === 'https://bot.example.com/webapp.php');

check('web_app با http رد می‌شود',
    $testBot->build([[['text' => 'x', 'web_app' => 'http://bot.example.com/webapp.php']]]) === null);

check('web_app با دامنهٔ غریبه رد می‌شود',
    $testBot->build([[['text' => 'x', 'web_app' => 'https://evil.com/webapp.php']]]) === null);

check('web_app با userinfo رد می‌شود',
    $testBot->build([[['text' => 'x', 'web_app' => 'https://bot.example.com@evil.com/']]]) === null);

check('web_app با fragment رد می‌شود',
    $testBot->build([[['text' => 'x', 'web_app' => 'https://bot.example.com/x#f']]]) === null);

check('زیردامنهٔ base_url پذیرفته می‌شود',
    ($testBot->build([[['text' => 'x', 'web_app' => 'https://api.bot.example.com/webapp.php']]])
        ['inline_keyboard'][0][0]['web_app']['url'] ?? '') === 'https://api.bot.example.com/webapp.php');

check('دکمهٔ callback عادی سالم است',
    ($testBot->build([[['text' => 'x', 'data' => 'menu']]])['inline_keyboard'][0][0]['callback_data'] ?? '') === 'menu');

echo "\n" . str_repeat('═', 62) . "\n";
echo "  نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('═', 62) . "\n";

exit($failed === 0 ? 0 : 1);
