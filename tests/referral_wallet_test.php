<?php

declare(strict_types=1);

/**
 * «همکاری در فروش» از ابتدا تا انتها — و مهم‌تر: **پول واقعی کجا می‌رود**.
 *
 * ## چرا این تست جداگانه وجود دارد
 *
 * پاداش معرفی قبلاً یک عددِ تزئینی بود: به کیف پول کاربر اضافه می‌شد و
 * کیف پول نه خرج می‌شد نه قابل برداشت بود. یعنی کاربر برای هر دوستی که می‌آورد
 * پاداش می‌گرفت و هیچ کاری نمی‌توانست بکند.
 *
 * حالا کیف پول یک **روش پرداخت** است. این یعنی یک مسیرِ پولیِ تازه که باید مثل
 * بقیهٔ مسیرها آزموده شود — و سخت‌گیرانه‌تر، چون اشتباه در آن مستقیماً به
 * مشتری آسیب می‌زند:
 *
 *   • موجودیِ ناکافی نباید **کم** شود.
 *   • پرداختِ دوبارهِ یک سفارشِ پرداخت‌شده نباید پول را دو بار ببرد.
 *   • سفارشِ بسته (لغو/ردشده) نباید اصلاً به کیف پول نزدیک شود.
 *   • اگر موجودی کم شد ولی ثبتِ پرداخت شکست خورد، پول باید **برگردد**.
 *
 * تست‌های دیگر این را نمی‌سنجند چون `FakePanelClient`/`FakeBotApi` مرزِ
 * پول نیستند؛ اینجا خودِ SQL و خودِ Kernel اجرا می‌شوند.
 *
 * ## بخش‌ها
 *
 *   ۱) معرفی در سطح دیتابیس — اتصال، تکراری، آمار
 *   ۲) زمان‌بندیِ پاداش — فقط بعد از **پرداخت واقعی**
 *   ۳) `spendWallet` — اتمی بودن، مرزها، عدم اجازهٔ خرج دوباره
 *   ۴) سفرِ کامل: معرف ← معرفی‌شده ← خرید ← پاداش ← خرج کردن پاداش
 *   ۵) مرزهای امنیتیِ صفحهٔ همکاری
 *   ۶) پرداخت با کیف پول در ربات — مسیرهای شکست
 *   ۷) همان مسیرها در مینی‌اپ (لایهٔ دوم، بدون ربات)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\DiscountService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\ReferralRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Support\Str;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;
use Pasargad\WebApp\Api;
use Pasargad\WebApp\InitData;

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

/** معرفی که دوست جمع می‌کند و پاداشش را خرج می‌کند. */
const REFERRER = 980001;
/** دوستی که با لینکِ معرف می‌آید و می‌خرد. */
const REFEREE  = 980002;
/** دوستی که می‌آید ولی هرگز نمی‌خرد — نباید پاداشی تولید کند. */
const FREEBOY  = 980003;
/** غریبه — نباید بتواند آمارِ دعوتِ دیگران را ببیند. */
const OUTSIDER = 980004;

Config::set('bot_token', '123456:REFERRALWALLETTEST0123456789abcdef');
Config::set('base_url', 'https://bot.example.com');
Config::set('bot_username', '@PasargadRefBot');
Config::set('super_admins', [980099]);
Config::set('crypto_key', 'referral-wallet-key');
Config::set('panel.api_key', 'pg_key_referral_wallet');
Config::set('store.card_number', '6037997512345678');
Config::set('store.card_owner', 'فروشگاه تست');
Config::set('store.card_bank', 'بانک تست');

$passed   = 0;
$failed   = 0;
$failures = [];

function check(string $label, mixed $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ((bool) $ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    $failures[] = $label;

    echo "  ❌ {$label}\n";

    if ($detail !== '') {
        echo '     ↳ ' . mb_substr(str_replace("\n", ' ⏎ ', $detail), 0, 240) . "\n";
    }
}

// ------------------------------------------------------------------
// زیرساخت
// ------------------------------------------------------------------

$users    = new UserRepository();
$panels   = new PanelRepository();
$orders   = new OrderRepository();
$pkgs     = new PackageRepository();
$settings = new Settings();
$flags    = new FeatureFlags($settings);
$sessions = new SessionStore();
$discounts = new DiscountService();
$referrals = $discounts->referrals();

$panel = new FakePanelClient();
$panel->ownerUsername = 'ref_owner';
$panel->addAdmin('ref_owner', [
    'id' => 810, 'status' => 'active', 'data_limit' => 1073741824,
    'role' => ['id' => 1, 'name' => 'owner', 'is_owner' => true],
]);

$prov = new Provisioner($panel, $orders, $users, $settings, $panels);
$pay  = new PaymentService($orders, $prov, $settings, $flags, $users);

$bot    = new FakeBotApi();
$kernel = new Kernel(
    $bot, new Notifier($bot), $users, $pkgs, $orders, $prov, $pay,
    $settings, $sessions, $panel, $flags
);

$seq = 0;

function uid(): int
{
    global $seq;
    return ++$seq + 200000;
}

/**
 * ارسال پیامِ متنی از طرف کاربر.
 *
 * `$name` پارامتر است چون هر پیام `first_name` را در دیتابیس بازنویسی می‌کند؛
 * یک نامِ ثابت یعنی همهٔ کاربران اسمِ «U» می‌گیرند و تستِ «نامِ درست نمایش
 * داده می‌شود» عملاً بی‌معنی می‌شود.
 */
function tap(int $userId, string $text, string $name = 'U'): void
{
    global $kernel;

    $kernel->handle(new Update([
        'update_id' => uid(),
        'message'   => ['message_id' => uid(), 'date' => time(),
                        'chat' => ['id' => $userId, 'type' => 'private'],
                        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => $name],
                        'text' => $text],
    ]));
}

function click(int $userId, string $ns, array $params = []): void
{
    global $kernel;

    $kernel->handle(new Update([
        'update_id' => uid(),
        'callback_query' => [
            'id' => 'cb' . uid(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'U'],
            'chat_instance' => 'ci',
            'data' => json_encode(['n' => $ns] + $params, JSON_UNESCAPED_UNICODE),
            'message' => ['message_id' => 1, 'date' => time(),
                          'chat' => ['id' => $userId, 'type' => 'private']],
        ],
    ]));
}

function text(): string
{
    global $bot;
    return $bot->allText();
}

/** فقط آخرین پیام — برای وقتی که `allText()` انبوه پیام قبلی را قاطی می‌کند. */
function lastText(): string
{
    global $bot;
    return $bot->lastText();
}

function broken(): bool
{
    return str_contains(text(), 'خطایی رخ داد');
}

/**
 * خالی‌کردنِ جدول ضدتکرار.
 *
 * `FloodGuard` کلیکِ تکراریِ همان دکمه را در پنجرهٔ کوتاه رد می‌کند — رفتاری که
 * **درست** است ولی تستِ خودکار همه‌چیز را در چند میلی‌ثانیه اجرا می‌کند.
 */
function clearFlood(): void
{
    $dbx = \Pasargad\Support\Db::instance();
    $dbx->run('DELETE FROM flood_guard');
    $dbx->run('DELETE FROM processed_updates');
}

function freshBot(): void
{
    global $bot;
    $bot->reset();
    clearFlood();
}

/**
 * ساختِ یک سفارشِ تستی با ستون‌های اجباری پرشده.
 *
 * ⚠️ چرا این تابع هست و نه `$orders->create()` مستقیم: جدول `orders` چند ستون
 * `NOT NULL` بدون پیش‌فرض دارد (`package_title`, `kind`). فراموشیِ یکی از آن‌ها
 * یک `PDOException` می‌دهد که **کلِ تست را می‌کُشد** — نه اینکه آن سناریو را
 * قرمز کند. یعنی یک typo کوچک، کل پوششِ بقیهٔ بخش‌ها را از بین می‌برد.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function mkOrder(int $userId, array $data): array
{
    global $orders;

    return $orders->create($userId, array_merge([
        'package_title' => 'بستهٔ تست',
        'kind'          => PackageRepository::KIND_AGENCY,
        'volume_gb'     => 50,
        'duration_days' => 30,
    ], $data));
}

// ---- بذرِ داده ----------------------------------------------------

$settings->set(Settings::GATEWAY_CARD2CARD, '1');
$settings->set(Settings::GATEWAY_NOWPAYMENTS, '0');
$settings->set(Settings::GATEWAY_AUTOCARD, '0');
$settings->set(Settings::REFERRAL_BONUS, '50000');
$settings->set(Settings::REFERRAL_DISCOUNT, '10');
Config::set('store.min_order_toman', 1000);
Settings::flush();
$flags = new FeatureFlags($settings);

$agencyPkgId = $pkgs->create([
    'title' => 'پنل نمایندگی تست', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 50, 'duration_days' => 30, 'price_toman' => 900000, 'is_active' => 1,
]);

foreach ([REFERRER, REFEREE, FREEBOY, OUTSIDER] as $id) {
    $users->upsertByTelegram($id, ['first_name' => 'User' . $id, 'username' => 'u' . $id]);
}

// ==================================================================
echo "\n▶ بخش ۱) معرفی در سطح دیتابیس\n";
// ==================================================================

$refId     = (int) $users->findByTelegramId(REFERRER)['id'];
$refereeId = (int) $users->findByTelegramId(REFEREE)['id'];
$freeId    = (int) $users->findByTelegramId(FREEBOY)['id'];

$refCode = ReferralRepository::codeFor($refId);

check('کد معرف ساخته می‌شود', $refCode !== '', $refCode);
check('کد معرف یکتا و قابل حدس نیست (پیشوند R)', str_starts_with($refCode, 'R'), $refCode);

$bind1 = $referrals->bind($refId, $refereeId, 50000);
check('اتصال معرفی‌شده به معرف انجام شد', ($bind1['ok'] ?? false) === true, json_encode($bind1, JSON_UNESCAPED_UNICODE));

$bind2 = $referrals->bind($refId, $refereeId, 50000);
check('یک نفر نمی‌تواند دو معرف داشته باشد', ($bind2['ok'] ?? false) === false);

// ⚠️ این مهم است: بدون این شرط، کاربر می‌توانست چند بار `/start ref_X` بفرستد و
// برای یک دوستِ واحد چند پاداش تولید کند.
$bind3 = $referrals->bind((int) $users->findByTelegramId(OUTSIDER)['id'], $refereeId, 50000);
check('معرفی دوم روی همان کاربر رد می‌شود', ($bind3['ok'] ?? false) === false);

$referrals->bind($refId, $freeId, 50000);

check('یافتن معرف با کد کار می‌کند',
    (int) ($referrals->findReferrerByCode($refCode)['id'] ?? 0) === $refId);

check('کد نامعتبر، معرف برنمی‌گرداند', $referrals->findReferrerByCode('REF_NOTREAL') === null);

// ---- فهرستِ تفصیلی ----------------------------------------------

$detailed = $referrals->listDetailed($refId, 50);
check('فهرست دعوت‌شده‌ها دو نفر است', count($detailed) === 2, (string) count($detailed));

$byReferee = [];
foreach ($detailed as $row) {
    $byReferee[(int) $row['referee_user_id']] = $row;
}

// ⭐️ معرفی‌شده هنوز نخریده ⇒ نه پاداش، نه درآمد.
$paidRow  = $byReferee[$refereeId];
$freeRow  = $byReferee[$freeId];

check('معرفی‌شدهٔ تازه «خرید نکرده» است', empty($paidRow['is_paid']));
check('معرفی‌شدهٔ تازه پاداش نگرفته', empty($paidRow['reward_given']));
check('مهمانِ بی‌خرید هم «خرید نکرده» است', empty($freeRow['is_paid']));

$summary = $discounts->referralSummary($refId);
check('خلاصه: ۲ دعوت‌شده', (int) $summary['invited'] === 2, json_encode($summary, JSON_UNESCAPED_UNICODE));
check('خلاصه: ۰ خریدکرده', (int) $summary['paid'] === 0);
check('خلاصه: ۰ درآمد', (int) $summary['earned'] === 0);
check('خلاصه: فهرست هم دارد', is_array($summary['list']) && count($summary['list']) === 2);

// ==================================================================
echo "\n▶ بخش ۲) زمان‌بندیِ پاداش: فقط بعد از پرداختِ واقعی\n";
// ==================================================================

// ⚠️ نکتهٔ اصلیِ این بخش:
// پاداش **نباید** بعد از ثبت‌نام یا ثبت سفارش داده شود. اگر داده شود، یک نفر
// می‌تواند چند سفارش باز ثبت کند، پاداش بگیرد و هرگز پولی پرداخت نکند.
check('قبل از هر خریدی هیچ پاداشی در صف نیست',
    $referrals->listUnrewarded(50) === [], json_encode($referrals->listUnrewarded(50)));

// یک سفارشِ باز (نه پرداخت‌شده) بساز.
$pending = mkOrder($refereeId, [
    'user_id' => $refereeId, 'package_id' => $agencyPkgId, 'code' => Str::orderCode('REF'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 900000,
    'original_price_toman' => 900000, 'discount_toman' => 0,
]);

check('سفارشِ «ساخته‌شده ولی پرداخت‌نشده» پاداش تولید نمی‌کند',
    $referrals->listUnrewarded(50) === []);

// حتی اگر کسی اشتباهاً paid_at را ست کند بدون اینکه وضعیت paid باشد...
$orders->update((int) $pending['id'], ['paid_at' => time()]);

check('سفارش با paid_at ولی وضعیتِ غیرِ paid هم پاداش نمی‌سازد',
    $referrals->listUnrewarded(50) === [],
    'وضعیت=' . (string) $orders->find((int) $pending['id'])['status']);

// حالا واقعاً پرداخت کن.
$orders->update((int) $pending['id'], ['status' => OrderRepository::STATUS_PAID]);

$due = $referrals->listUnrewarded(50);
check('بعد از پرداختِ واقعی، معرفی‌شده در صف پاداش می‌آید', count($due) === 1, (string) count($due));
check('مهمانِ بی‌خرید در صف پاداش نیست',
    count(array_filter($due, static fn (array $r): bool => (int) $r['referee_user_id'] === $freeId)) === 0);

// ---- اجرای کرونِ پاداش ------------------------------------------

$users->adjustWallet($refId, 0, '', 0, 'manual');  // لاگ‌کردنِ بی‌اثر، فقط برای اطمینان
$before = $users->walletBalance($refId);

$reward1 = $discounts->rewardReferrers();
check('کرونِ پاداش یک نفر را پاداش داد', (int) $reward1['rewarded'] === 1, json_encode($reward1));
check('مبلغ پاداش درست است', (int) $reward1['toman'] === 50000, json_encode($reward1));

$after = $users->walletBalance($refId);
check('موجودی معرف دقیقاً به‌اندازهٔ پاداش رشد کرد',
    $after - $before === 50000, sprintf('before=%d after=%d', $before, $after));

// ---- اجرای دوبارهٔ کرون: نباید پاداش دوباره بدهد -----------------
$reward2 = $discounts->rewardReferrers();
check('کرونِ دوم هیچ پاداشی نمی‌دهد (idempotent)', (int) $reward2['rewarded'] === 0, json_encode($reward2));
check('موجودی معرف دوباره رشد نکرد', $users->walletBalance($refId) === $after);

// ---- خریدِ دومِ همان دوست: باز هم پاداش ندارد --------------------
$second = mkOrder($refereeId, [
    'user_id' => $refereeId, 'package_id' => $agencyPkgId, 'code' => Str::orderCode('REF'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 900000,
    'original_price_toman' => 900000, 'discount_toman' => 0,
]);
$orders->update((int) $second['id'], [
    'status' => OrderRepository::STATUS_PAID,
    'paid_at' => time(),
]);

$reward3 = $discounts->rewardReferrers();
check('خریدِ دومِ همان دوست پاداشِ دوم نمی‌دهد', (int) $reward3['rewarded'] === 0, json_encode($reward3));
check('موجودی معرف هنوز همان مقدار است', $users->walletBalance($refId) === $after);

// ---- نمای فهرست حالا که خرید انجام شده ---------------------------
$detailed2 = $referrals->listDetailed($refId, 50);
$paid2 = null;
foreach ($detailed2 as $row) {
    if ((int) $row['referee_user_id'] === $refereeId) {
        $paid2 = $row;
    }
}

check('معرفی‌شده حالا «خریده» است', !empty($paid2['is_paid']));
check('معرفی‌شده حالا «پاداش گرفته» است', !empty($paid2['reward_given']));
check('مجموع خریدش محاسبه شده', (int) ($paid2['orders_total'] ?? 0) >= 900000, (string) ($paid2['orders_total'] ?? 'null'));
check('تعداد سفارش‌هایش محاسبه شده', (int) ($paid2['orders_count'] ?? 0) >= 1);

$summary2 = $discounts->referralSummary($refId);
check('خلاصه: ۱ خریدکرده', (int) $summary2['paid'] === 1, json_encode([
    'invited' => $summary2['invited'], 'paid' => $summary2['paid'], 'rewarded' => $summary2['rewarded'],
], JSON_UNESCAPED_UNICODE));
check('خلاصه: ۱ پاداش‌گرفته', (int) $summary2['rewarded'] === 1);
check('خلاصه: درآمد = ۵۰ هزار', (int) $summary2['earned'] === 50000, (string) $summary2['earned']);
check('خلاصه: موجودی کیف پول با درآمد یکی است',
    $users->walletBalance($refId) === (int) $summary2['earned']);

// ---- ⭐️ دو مسیرِ شمارش باید همیشه یکی باشند ---------------------
//
// `referralStats` (یک کوئری، برای bootstrap مینی‌اپ) و `referralSummary`
// (فهرستِ کامل، برای صفحهٔ همکاری) **مستقل** پیاده شده‌اند. اگر روزی یکی
// عوض شود و دیگری نه، کاربر در صفحهٔ همکاری یک عدد می‌بیند و در کاشیِ بالای
// اپ عددی دیگر — و هیچ‌کدام خطا نمی‌دهد.
//
// ⚠️ این تست دقیقاً برای گرفتنِ همان بی‌صدا ساخته شده.
$stats = $discounts->referralStats($refId);

check('statsFor: تعداد دعوت با خلاصه یکی است',
    (int) $stats['invited'] === (int) $summary2['invited'],
    sprintf('stats=%d summary=%d', (int) $stats['invited'], (int) $summary2['invited']));
check('statsFor: تعداد خرید با خلاصه یکی است',
    (int) $stats['paid'] === (int) $summary2['paid'],
    sprintf('stats=%d summary=%d', (int) $stats['paid'], (int) $summary2['paid']));
check('statsFor: تعداد پاداش با خلاصه یکی است',
    (int) $stats['rewarded'] === (int) $summary2['rewarded'],
    sprintf('stats=%d summary=%d', (int) $stats['rewarded'], (int) $summary2['rewarded']));
check('statsFor: درآمد با خلاصه یکی است',
    (int) $stats['earned'] === (int) $summary2['earned'],
    sprintf('stats=%d summary=%d', (int) $stats['earned'], (int) $summary2['earned']));
check('statsFor: کد هم درست است', (string) $stats['code'] === $refCode);
check('statsFor: فهرست را برنمی‌گرداند (سبک است)',
    !array_key_exists('list', $stats), json_encode(array_keys($stats)));

// برای کاربرِ بدون هیچ دعوتی هم نباید صفرِ منفی یا null بدهد.
$emptyStats = $discounts->referralStats((int) $users->findByTelegramId(OUTSIDER)['id']);
check('statsFor: کاربرِ بدون دعوت ⇒ همه صفر',
    (int) $emptyStats['invited'] === 0
    && (int) $emptyStats['paid'] === 0
    && (int) $emptyStats['rewarded'] === 0
    && (int) $emptyStats['earned'] === 0,
    json_encode($emptyStats, JSON_UNESCAPED_UNICODE));

// ==================================================================
echo "\n▶ بخش ۳) spendWallet — مرزهای پولی\n";
// ==================================================================

$wId = (int) $users->findByTelegramId(OUTSIDER)['id'];
$users->adjustWallet($wId, 100000, 'شارژ تست', 0, 'manual');
check('شارژ اولیه انجام شد', $users->walletBalance($wId) === 100000, (string) $users->walletBalance($wId));

// ---- ناکافی: نباید حتی یک تومان کم شود ---------------------------
$short = $users->spendWallet($wId, 150000, 'نباید بگذرد');
check('پرداختِ بیش از موجودی رد شد', ($short['ok'] ?? true) === false, json_encode($short, JSON_UNESCAPED_UNICODE));
check('موجودی دست‌نخورده ماند', $users->walletBalance($wId) === 100000, (string) $users->walletBalance($wId));
check('پیامِ خطا به کاربر گفته شد', str_contains((string) $short['message'], 'کافی نیست'), (string) $short['message']);
check('کمبود محاسبه شد', (int) $short['shortfall'] === 50000, (string) $short['shortfall']);

$txnBefore = count($users->walletHistory($wId, 50));
$users->spendWallet($wId, 150000, 'نباید بگذرد');
check('تراکنشِ ناموفق ثبت نشد', count($users->walletHistory($wId, 50)) === $txnBefore);

// ---- دقیقاً برابر موجودی -----------------------------------------
$exact = $users->spendWallet($wId, 100000, 'پرداخت دقیق');
check('پرداختِ دقیقاً برابرِ موجودی انجام شد', ($exact['ok'] ?? false) === true, json_encode($exact, JSON_UNESCAPED_UNICODE));
check('موجودی صفر شد', $users->walletBalance($wId) === 0, (string) $users->walletBalance($wId));
check('موجودیِ گزارش‌شده با واقعی یکی است', (int) $exact['balance'] === 0);

// ---- موجودی صفر: هیچ خرجِ منفی ---------------------------------
$zero = $users->spendWallet($wId, 1000, 'با موجودی صفر');
check('با موجودی صفر، خرج رد شد', ($zero['ok'] ?? true) === false);
check('موجودی به منفی نرفت', $users->walletBalance($wId) === 0, (string) $users->walletBalance($wId));

// ---- مبلغ صفر/منفی: بی‌اثر ولی بی‌خطا ----------------------------
$noop = $users->spendWallet($wId, 0, 'صفر');
check('مبلغ صفر، عملِ بی‌اثر و موفق', ($noop['ok'] ?? false) === true);
$noopNeg = $users->spendWallet($wId, -5000, 'منفی');
check('مبلغ منفی، عملِ بی‌اثر و موفق (نه خرجِ معکوس)', ($noopNeg['ok'] ?? false) === true);
check('مبلغ منفی موجودی را زیاد نکرد', $users->walletBalance($wId) === 0, (string) $users->walletBalance($wId));

// ---- ⭐️ خرجِ دوباره: همان سناریوی واقعیِ کلیکِ دوباره -----------
$users->adjustWallet($wId, 60000, 'شارژ دوم', 0, 'manual');

$spendA = $users->spendWallet($wId, 40000, 'خرج اول');
$spendB = $users->spendWallet($wId, 40000, 'خرج دوم');

check('خرج اول موفق بود', ($spendA['ok'] ?? false) === true);
check('خرج دوم رد شد — موجودی را دوبار خرج نکرد', ($spendB['ok'] ?? true) === false, json_encode($spendB, JSON_UNESCAPED_UNICODE));
check('موجودیِ نهایی دقیقاً ۲۰ هزار است (نه منفی)',
    $users->walletBalance($wId) === 20000, (string) $users->walletBalance($wId));

// ---- تراکنش‌ها ----------------------------------------------------
// ⚠️ فقط **موفق‌ها** باید تراکنش ثبت کنند. اگر خرجِ ناموفق هم ردیف می‌ساخت،
// تاریخچهٔ کاربر پر از «پرداخت»هایی می‌شد که هرگز اتفاق نیفتاده بودند.
$history = $users->walletHistory($wId, 50);
$pays = array_values(array_filter($history, static fn (array $h): bool => ($h['kind'] ?? '') === 'wallet_pay'));

check('دقیقاً دو خرجِ موفق ثبت شد (۱۰۰٬۰۰۰ و ۴۰٬۰۰۰)',
    count($pays) === 2, (string) count($pays));
check('هیچ خرجِ ناموفقی در تاریخچه ثبت نشد',
    count(array_filter($pays, static fn (array $h): bool => (int) $h['amount'] !== -100000 && (int) $h['amount'] !== -40000)) === 0,
    json_encode(array_map(static fn (array $h): int => (int) $h['amount'], $pays)));

// ==================================================================
echo "\n▶ بخش ۴) سفرِ کامل از داخل ربات\n";
// ==================================================================

// ⚠️ این بخش از صفر شروع می‌کند تا مسیرِ واقعیِ /start ref_ هم آزموده شود —
// نه فقط متدِ repository که ممکن است اصلاً در ربات صدا زده نشود.
$users->upsertByTelegram(980011, ['first_name' => 'RealReferrer']);
$users->upsertByTelegram(980012, ['first_name' => 'RealReferee']);
$users->upsertByTelegram(980013, ['first_name' => 'RealFreeboy']);

freshBot();
tap(980011, '/start');
$realRefId = (int) $users->findByTelegramId(980011)['id'];
$realCode = ReferralRepository::codeFor($realRefId);

freshBot();
click(980011, 'referral.my');

check('صفحهٔ همکاری در فروش باز شد', str_contains(text(), 'همکاری در فروش'), text());
check('کد معرف نمایش داده شد', str_contains(text(), $realCode), $realCode);
check('دکمهٔ دعوت دوستان (اشتراک‌گذاری) هست', $bot->hasButton('دعوت دوستان'), text());
check('دکمهٔ کیف پول هست', $bot->hasButton('کیف پول'), text());

// لینک باید داخل متن باشد و قابل کپی باشد.
check('لینک دعوت ساخته شد', str_contains(text(), 't.me/PasargadRefBot?start='), text());
// ⚠️ اگر `@` در نام‌کاربری بماند، لینک `https://t.me/@Bot` می‌شود که تلگرام
// باز نمی‌کند — یعنی دکمهٔ دعوت ساکتاً خراب بود.
check('اسم‌کاربریِ با @ به‌درستی پاک شد', !str_contains(text(), '@PasargadRefBot'), text());

// ---- مهمان با لینک می‌آید ----------------------------------------
freshBot();
tap(980013, '/start ' . $realCode, 'RealFreeboy');

$realFreeId = (int) $users->findByTelegramId(980013)['id'];
check('مهمان با لینک به معرف وصل شد',
    (int) ($referrals->findByReferee($realFreeId)['referrer_user_id'] ?? 0) === $realRefId,
    json_encode($referrals->findByReferee($realFreeId), JSON_UNESCAPED_UNICODE));

check('به او گفته شد خریدش ارزان‌تر است', str_contains(text(), 'ارزان‌تر'), text());
check('پیامِ خوش‌آمدِ معرفی خطا ندارد', !broken(), text());

// ---- معرف فهرست را می‌بیند --------------------------------------
freshBot();
click(980011, 'referral.my');
clearFlood();
click(980011, 'referral.list');

// ⚠️ از `lastText()` استفاده می‌شود نه `allText()`: متنِ انباشته شامل پیامِ
// صفحهٔ قبلی هم هست و باعث می‌شود تست **الگوریتمِ کهنه** را بگیرد — یعنی سبز
// بماند در حالی که دکمه اصلاً کار نمی‌کند.
check('فهرست دعوت‌شده‌ها باز شد', str_contains(lastText(), 'دعوت‌شده‌های من'), lastText());
check('نامِ مهمان در فهرست هست', str_contains(lastText(), 'RealFreeboy'), lastText());
// ⭐️ این پیام لازم است؛ بدون آن کاربر فکر می‌کند سیستم خراب است.
check('به او گفته شده هنوز خرید نکرده', str_contains(lastText(), 'هنوز خرید نکرده'), lastText());
check('دکمهٔ «کیف پول من» در فهرست هست', $bot->hasButton('کیف پول'), lastText());

// ---- مهمان از مسیرِ واقعیِ خرید می‌رود --------------------------
// مسیر: shop → pkg → pkg.buy  (همان‌طور که یک کاربر واقعی کلیک می‌کند)
freshBot();
tap(980013, '/start');
click(980013, 'shop', ['kind' => PackageRepository::KIND_AGENCY]);
clearFlood();
click(980013, 'pkg', ['id' => $agencyPkgId, 'p' => 0]);
clearFlood();
click(980013, 'pkg.buy', ['id' => $agencyPkgId, 'p' => 0, 'd' => 30]);

check('خرید از مسیرِ واقعی سفارش ساخت', !str_contains(text(), 'خطایی رخ داد'), text());

$freeOrders = $orders->listByUser($realFreeId, 10);
check('یک سفارش برای مهمان ثبت شد', count($freeOrders) === 1, (string) count($freeOrders));

$freeOrder = $freeOrders[0] ?? null;
check('سفارشِ مهمان ثبت شد', $freeOrder !== null);

if ($freeOrder !== null) {
    // ⭐️ تخفیفِ معرفی باید روی سفارش اعمال شده باشد: ۹۰۰٬۰۰۰ − ۱۰٪ = ۸۱۰٬۰۰۰
    check('تخفیفِ معرفی روی سفارش اعمال شد',
        (int) ($freeOrder['discount_toman'] ?? 0) === 90000,
        sprintf('قیمت=%d تخفیف=%d', (int) $freeOrder['price_toman'], (int) ($freeOrder['discount_toman'] ?? 0)));
    check('مبلغِ نهایی ۸۱۰ هزار است', (int) $freeOrder['price_toman'] === 810000,
        (string) $freeOrder['price_toman']);
    check('معرف روی سفارش ثبت شد', (string) ($freeOrder['referred_by'] ?? '') === $realCode,
        (string) ($freeOrder['referred_by'] ?? 'null'));

    // حالا مهمان پرداخت می‌کند.
    $orders->update((int) $freeOrder['id'], [
        'status' => OrderRepository::STATUS_PAID, 'paid_at' => time(),
    ]);
}

// ---- کرونِ پاداش: حالا معرف پاداش می‌گیرد ----------------------
$beforeWallet = $users->walletBalance($realRefId);
$reward = $discounts->rewardReferrers();

check('پاداشِ مهمانِ خریدار به معرف رسید', (int) $reward['rewarded'] >= 1, json_encode($reward));
check('مبلغِ پاداش ۵۰ هزار است', (int) $reward['toman'] === 50000, json_encode($reward));
check('موجودی معرف رشد کرد', $users->walletBalance($realRefId) > $beforeWallet);

// ---- معرف پاداشش را می‌بیند و خرجش می‌کند -----------------------
freshBot();
click(980011, 'referral.my');

check('آمارِ «خرید کرده‌اند» درست است', str_contains(text(), 'خرید کرده‌اند'), text());
check('درآمد نمایش داده شد', str_contains(text(), 'مجموع درآمد شما'), text());
check('موجودی کیف پول نمایش داده شد', str_contains(text(), 'موجودی کیف پول'), text());
check('دکمهٔ «دعوت‌شده‌های من» ظاهر شد', $bot->hasButton('دعوت‌شده‌های من'), text());
check('صفحهٔ همکاری بدون خطا رندر شد', !broken(), text());

clearFlood();
click(980011, 'referral.list');
check('در فهرست، خریدِ مهمان تأیید شد', str_contains(lastText(), 'خرید کرده'), lastText());
check('پیامِ پاداشِ گرفته‌شده هست', str_contains(lastText(), 'گرفته شد'), lastText());

// ---- حالا خرجش کن: یک سفارشِ کوچک با کیف پول -------------------
$cheap = $pkgs->create([
    'title' => 'بستهٔ ارزان', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 5, 'duration_days' => 7, 'price_toman' => 10000, 'is_active' => 1,
]);

$walletBefore = $users->walletBalance($realRefId);

$spendOrder = mkOrder($realRefId, [
    'user_id' => $realRefId, 'package_id' => $cheap, 'code' => Str::orderCode('RWP'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 10000,
    'original_price_toman' => 10000, 'discount_toman' => 0,
]);

$spend = $users->spendWallet($realRefId, 10000, 'پرداخت سفارش ' . $spendOrder['code']);
check('خرج کردن پاداش با کیف پول ممکن است', ($spend['ok'] ?? false) === true, json_encode($spend, JSON_UNESCAPED_UNICODE));

$paid = $orders->markPaid((int) $spendOrder['id'], PaymentService::METHOD_WALLET, (string) $spendOrder['code']);
check('سفارش با کیف پول پرداخت‌شده علامت خورد', $paid === true);
check('روش پرداخت روی سفارش ثبت شد',
    (string) $orders->find((int) $spendOrder['id'])['payment_method'] === PaymentService::METHOD_WALLET);
check('موجودی به‌اندازهٔ سفارش کم شد',
    $walletBefore - $users->walletBalance($realRefId) === 10000,
    sprintf('%d → %d', $walletBefore, $users->walletBalance($realRefId)));

// ==================================================================
echo "\n▶ بخش ۵) مرزهای امنیتیِ صفحهٔ همکاری\n";
// ==================================================================

$anyRef = $referrals->listDetailed($realRefId, 10)[0] ?? null;
check('یک ردیف دعوت برای آزمون مالکیت هست', $anyRef !== null);

freshBot();
click(OUTSIDER, 'referral.one', ['id' => (int) $anyRef['id']]);

// ⚠️ شناسهٔ دعوت داخل callback_data است و هر کاربری می‌تواند عدد دلخواه
// بفرستد. بدون بررسیِ مالکیت، هرکسی آمارِ فروشِ دیگران را می‌دید.
check('غریبه نتوانست جزئیاتِ دعوتِ دیگری را ببیند',
    !str_contains(text(), 'RealFreeboy') && !str_contains(text(), 'پاداش هر خرید'), text());

freshBot();
click(OUTSIDER, 'referral.one', ['id' => 999999]);
check('شناسهٔ ناموجود، «پیدا نشد» می‌دهد نه خطای فنی', !broken(), text());

freshBot();
click(OUTSIDER, 'referral.my');
check('غریبه صفحهٔ همکاریِ خودش را می‌بیند (خالی)', str_contains(text(), 'همکاری در فروش'), text());
check('کدِ خودِ غریبه نمایش داده می‌شود',
    str_contains(text(), ReferralRepository::codeFor((int) $users->findByTelegramId(OUTSIDER)['id'])), text());

freshBot();
click(OUTSIDER, 'referral.list');
check('فهرست خالی با پیامِ درست نمایش داده شد', str_contains(text(), 'هنوز کسی را دعوت نکرده'), text());
check('صفحهٔ خالی هم دکمهٔ بازگشت دارد', $bot->hasButton('بازگشت'), text());

// ==================================================================
echo "\n▶ بخش ۶) پرداخت با کیف پول در ربات — مسیرهای شکست\n";
// ==================================================================

$u6 = 980021;
$users->upsertByTelegram($u6, ['first_name' => 'Payer']);
$u6id = (int) $users->findByTelegramId($u6)['id'];

// ---- ۶.۱: موجودیِ ناکافی ----------------------------------------
$poor = mkOrder($u6id, [
    'user_id' => $u6id, 'package_id' => $cheap, 'code' => Str::orderCode('RWF'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 10000,
    'original_price_toman' => 10000, 'discount_toman' => 0,
]);
$users->adjustWallet($u6id, 3000, 'شارژ ناکافی', 0, 'manual');

freshBot();
click($u6, 'pay', ['id' => (int) $poor['id'], 'm' => PaymentService::METHOD_WALLET]);

check('به کاربر گفته شد موجودی کافی نیست', str_contains(text(), 'موجودی کیف پول کافی نیست'), text());
check('موجودی دست‌نخورده ماند', $users->walletBalance($u6id) === 3000, (string) $users->walletBalance($u6id));
check('سفارش پرداخت‌شده علامت نخورد',
    (string) $orders->find((int) $poor['id'])['status'] === OrderRepository::STATUS_CREATED,
    (string) $orders->find((int) $poor['id'])['status']);
check('راهِ جایگزین به او داده شد', $bot->hasButton('روش‌های دیگر'), text());
check('صفحهٔ کیف پولِ ناموفق دکمهٔ «پرداخت» ندارد', !$bot->hasButton('پرداخت'), text());
check('جریانِ ناموفق خطای فنی نداد', !broken(), text());

// دکمهٔ «روش‌های دیگر» باید کار کند و سفارش را از دست ندهد.
clearFlood();
click($u6, 'pay.again', ['id' => (int) $poor['id']]);
check('«روش‌های دیگر» صفحهٔ انتخاب را باز کرد', str_contains(text(), 'روش پرداخت'), text());
check('سفارش هنوز در دسترس است',
    (string) $orders->find((int) $poor['id'])['status'] === OrderRepository::STATUS_CREATED);

// ---- ۶.۲: سفارشِ لغوشده نباید به کیف پول نزدیک شود -------------
$cancelled = mkOrder($u6id, [
    'user_id' => $u6id, 'package_id' => $cheap, 'code' => Str::orderCode('RWC'),
    'status' => OrderRepository::STATUS_CANCELLED, 'price_toman' => 10000,
    'original_price_toman' => 10000, 'discount_toman' => 0,
]);
$users->adjustWallet($u6id, 50000, 'شارژ کافی', 0, 'manual');
$balBeforeCancel = $users->walletBalance($u6id);

freshBot();
click($u6, 'pay', ['id' => (int) $cancelled['id'], 'm' => PaymentService::METHOD_WALLET]);

check('پرداختِ سفارشِ لغوشده رد شد', str_contains(text(), 'قابل پرداخت نیست'), text());
check('موجودیِ کاربر دست‌نخورده ماند', $users->walletBalance($u6id) === $balBeforeCancel,
    sprintf('%d → %d', $balBeforeCancel, $users->walletBalance($u6id)));

// ---- ۶.۳: سفارشِ پرداخت‌شده دوباره پرداخت نمی‌شود ---------------
$already = mkOrder($u6id, [
    'user_id' => $u6id, 'package_id' => $cheap, 'code' => Str::orderCode('RWD'),
    'status' => OrderRepository::STATUS_PAID, 'price_toman' => 10000,
    'original_price_toman' => 10000, 'discount_toman' => 0, 'paid_at' => time(),
]);
$balBeforeAgain = $users->walletBalance($u6id);

freshBot();
click($u6, 'pay', ['id' => (int) $already['id'], 'm' => PaymentService::METHOD_WALLET]);
check('سفارشِ پرداخت‌شده دوباره پرداخت نمی‌شود', str_contains(text(), 'قابل پرداخت نیست'), text());
check('پولِ کاربر برای سفارشِ تمام‌شده کم نشد',
    $users->walletBalance($u6id) === $balBeforeAgain,
    sprintf('%d → %d', $balBeforeAgain, $users->walletBalance($u6id)));

// ---- ۶.۴: سفارشِ غریبه از راه callback جعلی -----------------------
$other = mkOrder($refId, [
    'user_id' => $refId, 'package_id' => $cheap, 'code' => Str::orderCode('RWO'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 10000,
    'original_price_toman' => 10000, 'discount_toman' => 0,
]);
$users->adjustWallet($u6id, 20000, 'شارژ', 0, 'manual');
$balBeforeTheft = $users->walletBalance($u6id);

freshBot();
click($u6, 'pay', ['id' => (int) $other['id'], 'm' => PaymentService::METHOD_WALLET]);

// ⚠️ اگر `findForUser` درست عمل کند، سفارشِ کاربرِ دیگر اصلاً پیدا نمی‌شود و
// پول از کیف پولِ غریبه کم نمی‌شود.
check('سفارشِ کاربرِ دیگر قابل پرداخت از راه غریبه نیست',
    (string) $orders->find((int) $other['id'])['status'] === OrderRepository::STATUS_CREATED,
    (string) $orders->find((int) $other['id'])['status']);
check('پولِ غریبه برای سفارشِ دیگری کم نشد',
    $users->walletBalance($u6id) === $balBeforeTheft,
    sprintf('%d → %d', $balBeforeTheft, $users->walletBalance($u6id)));

// ---- ۶.۵: پرداختِ موفق با کیف پول -------------------------------
$good = mkOrder($u6id, [
    'user_id' => $u6id, 'package_id' => $cheap, 'code' => Str::orderCode('RWG'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 10000,
    'original_price_toman' => 10000, 'discount_toman' => 0,
]);
$balBeforeGood = $users->walletBalance($u6id);

freshBot();
click($u6, 'pay', ['id' => (int) $good['id'], 'm' => PaymentService::METHOD_WALLET]);

$freshGood = $orders->find((int) $good['id']);
check('سفارش با کیف پول پرداخت شد',
    (string) $freshGood['status'] === OrderRepository::STATUS_APPLIED
    || (string) $freshGood['status'] === OrderRepository::STATUS_PAID
    || (string) $freshGood['status'] === OrderRepository::STATUS_APPLYING,
    'وضعیت=' . (string) $freshGood['status']);
check('paid_at ثبت شد', $freshGood['paid_at'] !== null);
check('روش پرداخت = کیف پول', (string) $freshGood['payment_method'] === PaymentService::METHOD_WALLET);
check('موجودی به‌اندازهٔ سفارش کم شد',
    $balBeforeGood - $users->walletBalance($u6id) === 10000,
    sprintf('%d → %d', $balBeforeGood, $users->walletBalance($u6id)));
check('پیامِ موفقیت به کاربر رسید', str_contains(text(), 'پرداخت از کیف پول انجام شد'), text());
check('موجودیِ باقی‌مانده اعلام شد', str_contains(text(), 'موجودی باقی‌مانده'), text());

// ---- ۶.۶: کیف پول یک روشِ پرداختِ قابل انتخاب است ---------------
freshBot();
$showFor = mkOrder($u6id, [
    'user_id' => $u6id, 'package_id' => $cheap, 'code' => Str::orderCode('RWM'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 10000,
    'original_price_toman' => 10000, 'discount_toman' => 0,
]);

// صفحهٔ انتخاب روش پرداخت از همان مسیرِ خرید ساخته می‌شود:
// shop → pkg → pkg.buy ⇒ به صفحهٔ روش پرداخت می‌رسیم.
tap($u6, '/start');
click($u6, 'shop', ['kind' => PackageRepository::KIND_AGENCY]);
clearFlood();
click($u6, 'pkg', ['id' => $cheap, 'p' => 0]);
clearFlood();
click($u6, 'pkg.buy', ['id' => $cheap, 'p' => 0, 'd' => 7]);

// ⭐️ کیف پول باید کنار درگاه‌ها به‌عنوان یک روش پرداخت بیاید. قبلاً نبود،
// یعنی پاداش معرفی هیچ راهی برای خرج شدن نداشت.
check('کیف پول در صفحهٔ روش پرداخت پیشنهاد شد',
    $bot->hasButton('کیف پول') || $bot->hasButton('پرداخت از کیف پول'), text());
check('موجودی کیف پول در صفحهٔ پرداخت اعلام شد',
    str_contains(text(), 'موجودی کیف پول'), text());
check('درگاهِ واقعی هم کنارش هست', $bot->hasButton('کارت') || $bot->hasButton('درگاه'), text());
check('صفحهٔ پرداخت بدون خطا رندر شد', !broken(), text());

// ==================================================================
echo "\n▶ بخش ۷) مینی‌اپ — همان مسیر، لایهٔ دوم\n";
// ==================================================================

$TOKEN = Config::str('bot_token');

function makeInitData(int $userId, string $firstName): string
{
    global $TOKEN;

    $pairs = [
        'auth_date' => (string) time(),
        'query_id'  => 'AAH_ref_wallet_query',
        'user'      => json_encode([
            'id'         => $userId,
            'first_name' => $firstName,
            'username'   => 'w' . $userId,
        ], JSON_UNESCAPED_UNICODE),
    ];

    ksort($pairs);

    $check = [];
    foreach ($pairs as $k => $v) {
        $check[] = $k . '=' . $v;
    }

    $secret = hash_hmac('sha256', $TOKEN, 'WebAppData', true);
    $pairs['hash'] = hash_hmac('sha256', implode("\n", $check), $secret);

    $query = [];
    foreach ($pairs as $k => $v) {
        $query[] = urlencode($k) . '=' . urlencode($v);
    }

    return implode('&', $query);
}

/**
 * ساخت یک نشستِ مینی‌اپ برای کاربر داده‌شده.
 *
 * ⚠️ دو نکته که هر دو از کار می‌افتادند اگر نادیده گرفته شوند:
 *
 * ۱) `Api` خودش initData نمی‌گیرد؛ اول باید `authenticate()` صدا زده شود با
 *    خروجیِ `InitData::parse()`. رد کردن رشتهٔ خام به سازنده یعنی TypeError.
 *
 * ۲) کلاینت پنلِ جعلی حتماً تزریق می‌شود. بدون آن، `Provisioner` یک
 *    `PasarGuardClient` **واقعی** می‌سازد و پرداختِ کیف پول به سرورِ واقعی
 *    وصل می‌شود — که در تست یعنی کندی/شکستِ غیرقابل پیش‌بینی، و بدتر،
 *    یک درخواستِ واقعیِ مالی از داخل تست.
 */
function apiFor(int $telegramId, string $name, ?FakePanelClient $client = null): Api
{
    $api = new Api(new FakeBotApi(), $client ?? new FakePanelClient());
    $api->authenticate(InitData::parse(makeInitData($telegramId, $name)));

    return $api;
}

$refApiUserId = (int) $users->findByTelegramId(980011)['id'];

// پنلِ مینی‌اپ باید همان پنلی باشد که ربات از آن استفاده می‌کند، وگرنه
// اجرای بسته با کلاینتِ خالی شکست می‌خورد.
$api = apiFor(980011, 'RealReferrer', $panel);

$refData = $api->handle('referral', []);

check('مینی‌اپ: endpoint معرفی پاسخ داد', is_array($refData) && ($refData['ok'] ?? true) !== false, json_encode($refData, JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: کد معرف برگشت', ($refData['code'] ?? '') === $realCode, (string) ($refData['code'] ?? 'null'));
check('مینی‌اپ: شمارِ خریدکرده‌ها برگشت', array_key_exists('paid', $refData));
check('مینی‌اپ: درآمد برگشت', array_key_exists('earned', $refData) && array_key_exists('earned_text', $refData));
check('مینی‌اپ: موجودی کیف پول برگشت', array_key_exists('wallet', $refData));
check('مینی‌اپ: لینک اشتراک‌گذاری برگشت', str_contains((string) ($refData['share_url'] ?? ''), 't.me/share/url'), (string) ($refData['share_url'] ?? 'null'));
check('مینی‌اپ: سیستم معرفی فعال است', ($refData['enabled'] ?? false) === true);
check('مینی‌اپ: فهرست دعوت‌شده‌ها آمد', is_array($refData['items'] ?? null) && count($refData['items']) >= 1, (string) count($refData['items'] ?? []));

// ⭐️ کلیدِ نمایشِ وضعیتِ خرید.
$firstItem = $refData['items'][0] ?? [];
check('مینی‌اپ: هر دعوت پرچمِ خرید دارد', array_key_exists('is_paid', $firstItem), json_encode($firstItem, JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: دعوتِ خریدار، خریدار علامت خورده', ($firstItem['is_paid'] ?? false) === true, json_encode($firstItem, JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: مبلغ خرید نمایش داده می‌شود', !empty($firstItem['orders_total_text']), json_encode($firstItem, JSON_UNESCAPED_UNICODE));

// ⚠️ نشستِ مینی‌اپ باید **همان کاربری** باشد که سفارش مالِ اوست.
// با نشستِ کاربرِ دیگر، `order.detail` درست رد می‌کند و تست به‌جای سنجشِ
// کیف پول، سنجشِ مالکیت می‌شد.
$apiPayer = apiFor($u6, 'Payer', $panel);

// ---- detail سفارش: پیشنهاد کیف پول --------------------------------

// کاربرِ ۹۸۰۰۲۱ موجودیِ کافی دارد ⇒ باید can_pay = true باشد.
$detail = $apiPayer->handle('order.detail', ['id' => (int) $showFor['id']]);

check('مینی‌اپ: جزئیات سفارش آمد', isset($detail['order']), json_encode(array_keys($detail), JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: بلوک کیف پول در پاسخ هست', isset($detail['wallet']), json_encode(array_keys($detail), JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: نامِ روش کیف پول درست است',
    ($detail['wallet']['method'] ?? '') === PaymentService::METHOD_WALLET,
    (string) ($detail['wallet']['method'] ?? 'null'));
check('مینی‌اپ: موجودیِ کاربر درست گزارش شد',
    (int) ($detail['wallet']['balance'] ?? -1) === $users->walletBalance($u6id),
    sprintf('api=%d real=%d', (int) ($detail['wallet']['balance'] ?? -1), $users->walletBalance($u6id)));
check('مینی‌اپ: موجودیِ کافی ⇒ دکمهٔ کیف پول فعال',
    ($detail['wallet']['can_pay'] ?? false) === true,
    json_encode($detail['wallet'] ?? [], JSON_UNESCAPED_UNICODE));

// ---- پرداختِ کیف پول از مینی‌اپ ---------------------------------
$payBefore = $users->walletBalance($u6id);
$payResult = $apiPayer->handle('order.pay', [
    'id'     => (int) $showFor['id'],
    'method' => PaymentService::METHOD_WALLET,
]);

check('مینی‌اپ: پرداختِ کیف پول موفق بود', ($payResult['ok'] ?? false) === true, json_encode($payResult, JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: موجودی کم شد', $users->walletBalance($u6id) === $payBefore - 10000,
    sprintf('%d → %d', $payBefore, $users->walletBalance($u6id)));
check('مینی‌اپ: سفارش پرداخت‌شده شد',
    in_array((string) $orders->find((int) $showFor['id'])['status'],
        [OrderRepository::STATUS_PAID, OrderRepository::STATUS_APPLYING, OrderRepository::STATUS_APPLIED], true),
    (string) $orders->find((int) $showFor['id'])['status']);

// ---- موجودیِ نیمه در مینی‌اپ --------------------------------------
$half = mkOrder($u6id, [
    'user_id' => $u6id, 'package_id' => $cheap, 'code' => Str::orderCode('RWH'),
    'status' => OrderRepository::STATUS_CREATED, 'price_toman' => 900000,
    'original_price_toman' => 900000, 'discount_toman' => 0,
]);

$halfDetail = $apiPayer->handle('order.detail', ['id' => (int) $half['id']]);
check('مینی‌اپ: موجودیِ نیمه ⇒ دکمهٔ کیف پول خاموش است',
    ($halfDetail['wallet']['can_pay'] ?? true) === false,
    json_encode($halfDetail['wallet'], JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: موجودیِ نیمه علامت‌گذاری شد', ($halfDetail['wallet']['partial'] ?? false) === true);

// ---- پرداختِ کیف پولِ ناکافی در مینی‌اپ -------------------------
$halfBefore = $users->walletBalance($u6id);
$halfPay = $apiPayer->handle('order.pay', ['id' => (int) $half['id'], 'method' => PaymentService::METHOD_WALLET]);

check('مینی‌اپ: پرداختِ ناکافی رد شد', ($halfPay['ok'] ?? true) === false, json_encode($halfPay, JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: پیامِ کمبود به کاربر رسید', str_contains((string) ($halfPay['message'] ?? ''), 'کافی نیست'), (string) ($halfPay['message'] ?? ''));
check('مینی‌اپ: موجودی دست‌نخورده ماند', $users->walletBalance($u6id) === $halfBefore);
check('مینی‌اپ: سفارش پرداخت‌نشده ماند',
    (string) $orders->find((int) $half['id'])['status'] === OrderRepository::STATUS_CREATED);

// ---- سفارشِ غریبه در مینی‌اپ --------------------------------------
$otherBefore = $users->walletBalance($u6id);
$steal = $apiPayer->handle('order.pay', ['id' => (int) $other['id'], 'method' => PaymentService::METHOD_WALLET]);

check('مینی‌اپ: سفارشِ کاربرِ دیگر رد شد', ($steal['ok'] ?? true) === false, json_encode($steal, JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: پولِ کاربر کم نشد', $users->walletBalance($u6id) === $otherBefore);
check('مینی‌اپ: سفارشِ غریبه تغییری نکرد',
    (string) $orders->find((int) $other['id'])['status'] === OrderRepository::STATUS_CREATED);

// ---- روش پرداختِ ناشناخته -----------------------------------------
$bogus = $apiPayer->handle('order.pay', ['id' => (int) $half['id'], 'method' => 'not_a_gateway']);

check('مینی‌اپ: روشِ ناشناخته رد شد', ($bogus['ok'] ?? true) === false, json_encode($bogus, JSON_UNESCAPED_UNICODE));
check('مینی‌اپ: موجودی برای روشِ ناشناخته هم دست‌نخورده', $users->walletBalance($u6id) === $otherBefore);

// ---- اتصال کد معرفی در مینی‌اپ ------------------------------------
$newbie = 980031;
$users->upsertByTelegram($newbie, ['first_name' => 'Newbie']);

$newbieApi = apiFor($newbie, 'Newbie');
$bindApi = $newbieApi->handle('referral.bind', ['code' => $realCode]);

$newbieId = (int) $users->findByTelegramId($newbie)['id'];
check('مینی‌اپ: کد معرفی ثبت شد',
    (int) ($referrals->findByReferee($newbieId)['referrer_user_id'] ?? 0) === $realRefId,
    json_encode($bindApi, JSON_UNESCAPED_UNICODE));

$rebind = $newbieApi->handle('referral.bind', ['code' => $realCode]);
check('مینی‌اپ: ثبت دوبارهٔ همان کد رد شد', isset($rebind['ok']) && $rebind['ok'] === false, json_encode($rebind, JSON_UNESCAPED_UNICODE));

$badBind = $newbieApi->handle('referral.bind', ['code' => 'REF_TOTALLY_FAKE']);
check('مینی‌اپ: کدِ ناموجود رد شد', ($badBind['ok'] ?? true) === false, json_encode($badBind, JSON_UNESCAPED_UNICODE));

// ==================================================================
echo "\n" . str_repeat('─', 62) . "\n";
echo "  نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

if ($failures !== []) {
    echo "\n  موارد ناموفق:\n";
    foreach ($failures as $f) {
        echo "   • {$f}\n";
    }
}

exit($failed === 0 ? 0 : 1);