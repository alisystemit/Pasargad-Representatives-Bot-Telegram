<?php

declare(strict_types=1);

/**
 * مشتریِ سمج در برابر سیستم — و ادمین در همان لحظه.
 *
 * ## چرا این تست فرق دارد
 *
 * بقیهٔ تست‌ها یک کاربرِ **همکار** می‌سازند: دکمهٔ درست را می‌زند و انتظارِ
 * نتیجهٔ درست دارد. این یکی کاربرِ **خصم** می‌سازد.
 *
 * کاربری که:
 *   • به هر دکمهی چندبار پشت‌سرهم می‌زند
 *   • `callback_data` جعلی می‌فرستد (JSON خراب، شناسهٔ منفی، شناسهٔ کسِ دیگر)
 *   • خودش را معرفِ خودش می‌کند و حلقه می‌سازد
 *   • سفارشِ لغوشده را دوباره پرداخت می‌کند
 *   • همان `update_id` را دوباره می‌فرستد
 *   • اسمش را `<script>` می‌گذارد
 *   • همه‌جای ربات را می‌گردد و هیچ‌جا آرام نمی‌نشیند
 *
 * و مهم‌تر: **همزمان** ادمین هم بازی می‌شود. چون بیشتر باگ‌های واقعی در
 * نقطه‌ای ظاهر می‌شوند که یکی دارد کاری می‌کند و دیگری وسطش چیزی را عوض
 * می‌کند — مسدود شدنِ کاربر وسطِ سفارش، تغییرِ قیمت قبل از پرداخت، رد کردنِ
 * سفارش و تلاشِ دوبارهٔ مشتری.
 *
 * ## معیار قبولی
 *
 * نه «خطا نداد»، بلکه: **هیچ پولی جابه‌جا نشد که نباید، هیچ کاربری چیزی از
 * کاربرِ دیگر نگرفت، و هیچ صفحه‌ای با پیامِ گنگِ «خطایی رخ داد» جواب نداد.**
 *
 * پیامِ گنگ خودش یک باگ است: کاربرِ سالم هم با آن نمی‌تواند کار کند.
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

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

/** مشتریِ سمج. */
const NASTY = 990001;
/** ادمین. */
const BOSS = 990002;
/** قربانیِ بی‌گناه — کاربری که نباید چیزی از او دست کسی برسد. */
const VICTIM = 990003;

Config::set('bot_token', '123456:NASTYCUSTOMERTEST0123456789abcdef');
Config::set('base_url', 'https://bot.example.com');
Config::set('bot_username', '@NastyBot');
Config::set('super_admins', [BOSS]);
Config::set('crypto_key', 'nasty-test-key');
Config::set('panel.api_key', 'pg_key_nasty');
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

$users    = new UserRepository();
$panels   = new PanelRepository();
$orders   = new OrderRepository();
$pkgs     = new PackageRepository();
$settings = new Settings();
$flags    = new FeatureFlags($settings);
$sessions = new SessionStore();
$discounts = new DiscountService();
$referrals = $discounts->referrals();
$dbx        = \Pasargad\Support\Db::instance();

$panel = new FakePanelClient();
$panel->ownerUsername = 'nasty_owner';
$panel->addAdmin('nasty_owner', [
    'id' => 820, 'status' => 'active', 'data_limit' => 1073741824,
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
    return ++$seq + 300000;
}

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

/**
 * کلیک با `callback_data` خام.
 *
 * پارامتر `raw` عمداً هست: بیشترِ باگ‌های این تست از جایی می‌آیند که کلاینت
 * یک JSON خراب یا یک ساختار غیرمنتظره می‌فرستد و کد فرض می‌کند ورودی سالم است.
 */
function rawClick(int $userId, string $raw, ?int $forcedUpdateId = null): void
{
    global $kernel;

    $kernel->handle(new Update([
        'update_id' => $forcedUpdateId ?? uid(),
        'callback_query' => [
            'id' => 'cb' . uid(),
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'U'],
            'chat_instance' => 'ci',
            'data' => $raw,
            'message' => ['message_id' => 1, 'date' => time(),
                          'chat' => ['id' => $userId, 'type' => 'private']],
        ],
    ]));
}

function click(int $userId, string $ns, array $params = []): void
{
    rawClick($userId, json_encode(['n' => $ns] + $params, JSON_UNESCAPED_UNICODE));
}

function text(): string
{
    global $bot;
    return $bot->allText();
}

function lastText(): string
{
    global $bot;
    return $bot->lastText();
}

/** آیا ربات با پیامِ گنگِ «خطایی رخ داد» جواب داد؟ خودش یک باگ است. */
function vagueError(): bool
{
    return str_contains(text(), 'خطایی رخ داد');
}

/** آیا خطایی در لاگ ثبت شد که مربوط به همین بخش است؟ */
function freshErrors(int $sinceLine): array
{
    global $logBaseline;

    $file = Config::str('log.path', __DIR__ . '/../data/logs') . '/' . date('Y-m-d') . '.log';

    if (!is_file($file)) {
        return [];
    }

    $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];

    return array_values(array_filter(
        array_slice($lines, $sinceLine),
        static fn (string $l): bool => str_contains($l, 'app.ERROR')
    ));
}

function logLineCount(): int
{
    $file = Config::str('log.path', __DIR__ . '/../data/logs') . '/' . date('Y-m-d') . '.log';

    return is_file($file) ? count(@file($file, FILE_IGNORE_NEW_LINES) ?: []) : 0;
}

function freshBot(): void
{
    global $bot;
    $bot->reset();
    clearFlood();
}

function clearFlood(): void
{
    $dbx = \Pasargad\Support\Db::instance();
    $dbx->run('DELETE FROM flood_guard');
    $dbx->run('DELETE FROM processed_updates');
}

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
$settings->set(Settings::REFERRAL_COMMISSION_PERCENT, '10');
$settings->set(Settings::REFERRAL_COMMISSION_MAX_ORDERS, '5');
Config::set('store.min_order_toman', 1000);
Settings::flush();
$flags = new FeatureFlags($settings);

$pkgId = $pkgs->create([
    'title' => 'پنل نمایندگی', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 50, 'duration_days' => 30, 'price_toman' => 900000, 'is_active' => 1,
]);
$cheapPkgId = $pkgs->create([
    'title' => 'بستهٔ ارزان', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 5, 'duration_days' => 7, 'price_toman' => 10000, 'is_active' => 1,
]);

foreach ([NASTY, BOSS, VICTIM] as $id) {
    $users->upsertByTelegram($id, ['first_name' => 'User' . $id]);
}

$nastyId  = (int) $users->findByTelegramId(NASTY)['id'];
$victimId = (int) $users->findByTelegramId(VICTIM)['id'];

$logBaseline = logLineCount();

// ==================================================================
echo "\n▶ بخش ۱) مشتریِ سمج با متن‌های کثیف\n";
// ==================================================================

freshBot();
tap(NASTY, '/start');
check('شروعِ عادی کار می‌کند', !vagueError(), lastText());

// ---- متن‌های بی‌معنی ---------------------------------------------
$garbage = [
    'یک کلمهٔ تنها',
    str_repeat('ا', 5000),                      // خیلی بلند
    str_repeat('🧨🎉', 400),                    // ایموجیِ زیاد
    "خطا\tجدول\n\r\v\f",                       // کاراکترهای کنترلی
    "'; DROP TABLE users; --",                 // تلاش SQL
    '<script>alert(1)</script>',
    '<b>',                                      // HTML ناتمام
    str_repeat('0', 300),                       // عددِ خیلی بلند
    ' ',                               // بایتِ null
    '/start ref_',
    '/start ' . str_repeat('A', 500),
];

foreach ($garbage as $i => $g) {
    tap(NASTY, $g);
}

check('هیچ متنِ کثیفی ربات را نساخت', !vagueError(), text());

// ⭐️ جدول‌ها باید سالم مانده باشند — تلاشِ SQL یعنی DROP.
$usersAlive = (int) $dbx->count('SELECT COUNT(*) FROM users WHERE id IN (?, ?)', [$nastyId, $victimId]);
check('جدول users بعد از تلاشِ DROP سالم است', $usersAlive === 2, "count={$usersAlive}");

$ordersAlive = (int) $dbx->count('SELECT COUNT(*) FROM orders');
check('جدول orders سالم است', $ordersAlive >= 0);

// ---- نامِ مخرب --------------------------------------------------
freshBot();
tap(NASTY, '/start', '<script>alert("xss")</script>');

$afterXss = $dbx->first('SELECT first_name FROM users WHERE id = ?', [$nastyId]);
check('نامِ خام در دیتابیس همان است که فرستاده شد',
    str_contains((string) $afterXss['first_name'], '<script>'), json_encode($afterXss, JSON_UNESCAPED_UNICODE));

// ⭐� اما هرگز نباید **خام** به تلگرام برسد؛ پیامِ HTML با پارامتر، هر چیزی
// بین <> را تگ می‌کند و بقیهٔ صفحه را به‌هم می‌ریزد.
$panelsHtml = implode("\n", array_map(
    static fn (array $m): string => (string) ($m['text'] ?? ''),
    $bot->sent
));
check('نامِ مخرب در هیچ پیامی خام دیده نمی‌شود',
    !str_contains($panelsHtml, '<script>'), 'یک پیام تگ خام داشت');

// ==================================================================
echo "\n▶ بخش ۲) callback_data جعلی\n";
// ==================================================================

$badPayloads = [
    '',
    'null',
    '{}',
    '[]',
    '"یک رشتهٔ تنها"',
    '123',
    'tru',                                   // JSON ناقص
    '{"n":}',
    '{"n":123}',                             // n باید رشته باشد
    '{"n":"pay"}',                           // بدون پارامترهای لازم
    '{"n":"pay","id":"abc"}',                // شناسهٔ غیرعددی
    '{"n":"pay","id":[]}',
    '{"n":"pay","id":{"a":1}}',
    '{"n":"order.view","id":-1}',
    '{"n":"order.view","id":0}',
    '{"n":"order.view","id":99999999999999999999}',   // بیش از int
    '{"n":"referral.one","id":-999}',
    '{"n":"panel.detail","id":null}',
    '{"n":"admin.home"}',                     // مشتری، نه ادمین
    '{"n":"admin.user.wallet","id":1,"amount":999999999}',
    '{"n":"unknown.namespace.here"}',
    '{"n":"../../etc/passwd"}',
    // هدفِ این یکی: نامِ روشِ پرداخت که خودش یک رشتهٔ SQL است.
    '{"n":"pay","id":1,"m":"x\'; DROP TABLE orders; --"}',
    '{"n":"order.list","page":"99999999999999999999"}',
    '{"n":"pkg.buy","id":1,"p":0,"d":-1}',
    '{"n":"admin.grow.field","f":"../../settings"}',
    '{"n":"coupon.apply","code":"\' OR 1=1 --"}',
];

foreach ($badPayloads as $p) {
    freshBot();
    $before = (int) $dbx->count('SELECT COUNT(*) FROM orders');

    rawClick(NASTY, $p);
    rawClick(VICTIM, $p);

    $after = (int) $dbx->count('SELECT COUNT(*) FROM orders');

    // ⭐️ اگر ورودی‌ای چیزی ساخت، **همان‌جا** نامش را گزارش می‌کنیم. وگرنه بعداً
    // فقط می‌فهمیم «یک جا» خراب است و باید دوباره دنبالش بگردیم.
    if ($after !== $before) {
        check('ورودیِ جعلیِ «' . mb_substr($p, 0, 50) . '» سفارش ساخت', false,
            "before={$before} after={$after}");
    }
}

check('هیچ callback جعلی ربات را نساخت', !vagueError(), 'یکی از payloadها خطا داد');

// ⭐️ مهم‌تر از «خطا نداد»: هیچ‌کدام نباید **اثر** داشته باشند.
check('کیف پولِ قربانی دست‌نخورده است',
    $users->walletBalance($victimId) === 0, (string) $users->walletBalance($victimId));

// اگر سفارشی ساخته شده، باید بدانیم **کدام** ورودی آن را ساخته — وگرنه فقط
// می‌فهمیم یک جا خراب است، نه کجا.
$stray = $dbx->all('SELECT id, code, user_id, status FROM orders ORDER BY id');
check('هیچ ورودیِ جعلی‌ای سفارش نساخت', $stray === [],
    json_encode($stray, JSON_UNESCAPED_UNICODE));

if ($stray === []) {
    check('مشتری به پنلِ مدیریت راه ندارد', !str_contains(text(), 'پنل مدیریت'), text());
    check('جدول users سالم است', (int) $dbx->count('SELECT COUNT(*) FROM users') >= 3);
}

// ---- آیا ادمین می‌تواند همان دکمه‌ها را بزند؟ ----------------------
freshBot();
click(BOSS, 'admin.home');
check('ادمین به پنل مدیریت می‌رسد', str_contains(lastText(), 'مدیریت') || str_contains(lastText(), 'داشبورد'),
    lastText());
check('و صفحهٔ ادمین خطا نداد', !vagueError(), lastText());

// ==================================================================
echo "\n▶ بخش ۳) خودمعرفی و حلقه\n";
// ==================================================================

freshBot();
tap(NASTY, '/start');

$ownCode = ReferralRepository::codeFor($nastyId);

freshBot();
tap(NASTY, '/start ' . $ownCode);

// ⭐️ اگر خودمعرفی رد نمی‌شد، کاربر یک‌بار پاداشِ ۵۰ هزاری می‌گرفت و بعد
// با هر خریدِ خودش کمیسیون می‌گرفت — یعنی جیبِ فروشگاه به جیبِ خودش.
check('خودمعرفی رد شد',
    $referrals->findByReferee($nastyId) === null,
    json_encode($referrals->findByReferee($nastyId), JSON_UNESCAPED_UNICODE));
// ⭐️ از `text()` استفاده می‌شود نه `lastText()`: پیامِ معرفی **قبل** از منوی
// اصلی فرستاده می‌شود، پس آخرین پیام منو است. با `lastText()` این تست
// همیشه قرمز می‌ماند و کسی نمی‌فهمد پیام اصلاً می‌رود یا نه.
check('و به کاربر گفته شد نمی‌تواند خودش را معرفی کند',
    str_contains(text(), 'خودتان'), text());
check('و خطای فنی نداد', !vagueError(), text());

// ---- `/start` با متنِ بی‌ربط نباید جوابِ نامربوط بدهد -----------
//
// ⭐️ دو رفتارِ **متفاوت** که نباید قاطی شوند:
//
//   • `/start hello`   → کاربر تصادفی چیزی نوشته ⇒ سکوت (ربات نباید به هر
//     متنی بگوید «کد معرفی نامعتبر»)
//   • `/start R999999` → کاربر عمداً یک کد زده ⇒ باید جواب بگیرد، وگرنه فکر
//     می‌کند ثبت شده و بعد کلافه می‌شود که چرا تخفیف نگرفت
freshBot();
tap(NASTY, '/start hello world');
check('متنِ بی‌ربط بعد از /start جوابِ معرفی نداد',
    !str_contains(text(), 'کد معرفی'), text());

freshBot();
tap(NASTY, '/start R999999');
check('ولی کدِ نامعتبر (با شکلِ درست) پاسخ گرفت',
    str_contains(text(), 'نامعتبر'), text());

// ---- حلقهٔ دوتایی: الف→ب و ب→الف ---------------------------------
$users->upsertByTelegram(990011, ['first_name' => 'LoopA']);
$users->upsertByTelegram(990012, ['first_name' => 'LoopB']);
$loopA = (int) $users->findByTelegramId(990011)['id'];
$loopB = (int) $users->findByTelegramId(990012)['id'];

$referrals->bind($loopA, $loopB, 50000);
$referrals->bind($loopB, $loopA, 50000);

check('دومین نفر حلقه را بست', $referrals->findByReferee($loopA) !== null,
    'یکی باید رد شده باشد');

// ---- زنجیرهٔ بلند ----------------------------------------------
// هر کاربر نفرِ بعدی را معرفی می‌کند. کمیسیون باید فقط **یک** سطح بدهد.
$chainIds = [];
for ($i = 0; $i < 5; $i++) {
    $tg = 990020 + $i;
    $users->upsertByTelegram($tg, ['first_name' => 'Chain' . $i]);
    $chainIds[] = (int) $users->findByTelegramId($tg)['id'];
}

for ($i = 0; $i < 4; $i++) {
    $referrals->bind($chainIds[$i], $chainIds[$i + 1], 50000);
}

check('زنجیرهٔ ۵ نفره ساخته شد',
    $referrals->findByReferee($chainIds[4]) !== null);

// فقط **آخرین** حلقه باید کمیسیون بگیرد، نه همه‌ی حلقه‌ها.
$discounts->payCommissions();
$discounts->rewardReferrers();

// هر عضوِ زنجیره فقط یک معرف دارد، پس هر کدام حداکثر یک پاداشِ اولیه.
$totalReward = 0;
foreach ($chainIds as $cid) {
    $totalReward += (int) $dbx->count(
        'SELECT COUNT(*) FROM referrals WHERE referrer_user_id = ? AND rewarded_at IS NOT NULL',
        [$cid]
    );
}
check('زنجیره هرگز بیش از ۴ پاداشِ اولیه نساخت', $totalReward <= 4, "rewarded={$totalReward}");

// ==================================================================
echo "\n▶ بخش ۴) مشتری روی مسیرهای پولی می‌کوبد\n";
// ==================================================================

// ---- ۴.۱: کلیکِ مکرر روی «پرداخت» --------------------------------
freshBot();
tap(NASTY, '/start');
$order = mkOrder($nastyId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NA'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
]);
$users->adjustWallet($nastyId, 100000, 'شارژ تست', 0, 'manual');
$balanceBefore = $users->walletBalance($nastyId);

// ⭐️ عمداً **بدون** `clearFlood()`: هدف این است که ببینیم ضدتکرار کار می‌کند.
// مشتریِ واقعی هم همین کار را می‌کند — چندبار روی همان دکمه می‌زند چون جوابی
// ندیده و فکر می‌کند ربات کند است.
for ($i = 0; $i < 6; $i++) {
    click(NASTY, 'pay', ['id' => (int) $order['id'], 'm' => PaymentService::METHOD_WALLET]);
}

$charged = $balanceBefore - $users->walletBalance($nastyId);
check('شش کلیکِ پیاپی، فقط **یک** بار پول کم کرد',
    $charged === 10000, sprintf('برداشت=%d از موجودی اولیه=%d', $charged, $balanceBefore));
check('سفارش دقیقاً یک بار پرداخت شد',
    (string) $orders->find((int) $order['id'])['payment_method'] === PaymentService::METHOD_WALLET);

// ---- ۴.۲: پرداختِ دوباره از مسیر دیگر ----------------------------
clearFlood();
$balanceBefore2 = $users->walletBalance($nastyId);

freshBot();
click(NASTY, 'pay', ['id' => (int) $order['id'], 'm' => PaymentService::METHOD_WALLET]);
click(NASTY, 'pay', ['id' => (int) $order['id'], 'm' => 'card2card']);

check('پرداختِ دوبارهٔ سفارشِ تمام‌شده، پولی برنداشت',
    $users->walletBalance($nastyId) === $balanceBefore2,
    sprintf('%d → %d', $balanceBefore2, $users->walletBalance($nastyId)));

// ---- ۴.۳: سفارشِ لغوشده را دوباره پرداخت می‌کند ------------------
$cancelled = mkOrder($nastyId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NC'),
    'status' => OrderRepository::STATUS_CANCELLED,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
]);
clearFlood();
$balBefore = $users->walletBalance($nastyId);

freshBot();
click(NASTY, 'pay', ['id' => (int) $cancelled['id'], 'm' => PaymentService::METHOD_WALLET]);
click(NASTY, 'pay', ['id' => (int) $cancelled['id'], 'm' => PaymentService::METHOD_WALLET]);

check('سفارشِ لغوشده هیچ پولی نمی‌دهد',
    $users->walletBalance($nastyId) === $balBefore,
    sprintf('%d → %d', $balBefore, $users->walletBalance($nastyId)));
check('و وضعیتش لغوشده ماند',
    (string) $orders->find((int) $cancelled['id'])['status'] === OrderRepository::STATUS_CANCELLED);

// ---- ۴.۴: سفارشِ قربانی -----------------------------------------
$victimOrder = mkOrder($victimId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NV'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
]);
clearFlood();
$balVictimBefore = $users->walletBalance($victimId);
$balNastyBefore  = $users->walletBalance($nastyId);

freshBot();
// مشتری حدس می‌زند شناسهٔ سفارشِ یک نفرِ دیگر چیست (اینجا ۱ است).
click(NASTY, 'pay', ['id' => (int) $victimOrder['id'], 'm' => PaymentService::METHOD_WALLET]);
click(NASTY, 'pay', ['id' => (int) $victimOrder['id'], 'm' => 'card2card']);

check('پولِ قربانی دست‌نخورده است', $users->walletBalance($victimId) === $balVictimBefore);
check('سفارشِ قربانی تغییری نکرد',
    (string) $orders->find((int) $victimOrder['id'])['status'] === OrderRepository::STATUS_CREATED);
check('پولِ خودِ مهاجم هم از سفارشِ دیگری کم نشد',
    $users->walletBalance($nastyId) === $balNastyBefore);

// ---- ۴.۵: همان update را دوباره می‌فرستد -------------------------
// تلگرام در صورتِ timeout همان آپدیت را دوباره می‌فرستد. سیستم باید دوبار
// پردازشش نکند، وگرنه دوبار پول کم می‌شود.

// ---- ۴.۶: مدتِ ناشناخته در خرید ----------------------------------
//
// ⭐️ این یکی از بدترین باگ‌های ممکن بود و **بی‌صدا** بود:
//
// دکمهٔ خرید `d` (مدت) را داخل callback_data دارد. اگر `d` با هیچ گزینه‌ای
// نمی‌خواند، قبلاً بی‌صدا نادیده گرفته می‌شد و سفارش با **قیمتِ پیش‌فرض**
// ساخته می‌شد.
//
// یعنی دکمه‌ای که رویش نوشته «۹۰ روزه • ۵۰۰ هزار» می‌توانست سفارشی با قیمتِ
// گزینهٔ ۳۰ روزه ثبت کند. کاربر فکر می‌کند گزینهٔ گران را خریده و مبلغِ
// دیگری از او گرفته می‌شود.
$periodPkgId = $pkgs->create([
    'title' => 'بستهٔ چنددوره‌ای', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 20, 'duration_days' => 30, 'price_toman' => 100000, 'is_active' => 1,
    'prices' => '30:100000,90:300000',
]);

$validOrder   = mkOrder($nastyId, ['package_id' => $periodPkgId, 'code' => Str::orderCode('VP'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 100000, 'original_price_toman' => 100000, 'discount_toman' => 0]);
$invalidOrder = mkOrder($nastyId, ['package_id' => $periodPkgId, 'code' => Str::orderCode('IP'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 100000, 'original_price_toman' => 100000, 'discount_toman' => 0]);

$beforePeriod = (int) $dbx->count('SELECT COUNT(*) FROM orders');

freshBot();
click(NASTY, 'pkg.buy', ['id' => $periodPkgId, 'p' => 0, 'd' => 90]);
check('مدتِ معتبر (۹۰ روزه) سفارش ساخت',
    (int) $dbx->count('SELECT COUNT(*) FROM orders') === $beforePeriod + 1,
    'سفارشِ ۹۰ روزه باید ساخته شود');
check('و قیمتِ همان گزینه را گرفت', (int) $orders->listByUser($nastyId, 1)[0]['price_toman'] === 300000,
    json_encode($orders->listByUser($nastyId, 1), JSON_UNESCAPED_UNICODE));

$afterValid = (int) $dbx->count('SELECT COUNT(*) FROM orders');
freshBot();
click(NASTY, 'pkg.buy', ['id' => $periodPkgId, 'p' => 0, 'd' => 45]);     // وجود ندارد
click(NASTY, 'pkg.buy', ['id' => $periodPkgId, 'p' => 0, 'd' => -1]);     // منفی
click(NASTY, 'pkg.buy', ['id' => $periodPkgId, 'p' => 0, 'd' => 99999]);  // خیلی بزرگ

check('مدتِ ناشناخته/منفی/نامعقول هیچ سفارشی نساخت',
    (int) $dbx->count('SELECT COUNT(*) FROM orders') === $afterValid,
    'before=' . $afterValid . ' after=' . (int) $dbx->count('SELECT COUNT(*) FROM orders'));
check('و کاربر دقیقاً فهمید چرا',
    str_contains(text(), 'معتبر نیست'), text());
check('و خطای فنی نداد', !vagueError(), text());

// ---- ۴.۷: همان update را دوباره می‌فرستد -------------------------
// پردازشش نکند، وگرنه دوبار پول کم می‌شود.
$replayOrder = mkOrder($nastyId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NR'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
]);
clearFlood();
$balReplay = $users->walletBalance($nastyId);

$frozenUpdateId = uid();
rawClick(NASTY, json_encode([
    'n' => 'pay', 'id' => (int) $replayOrder['id'], 'm' => PaymentService::METHOD_WALLET,
], JSON_UNESCAPED_UNICODE), $frozenUpdateId);

$afterFirst = $users->walletBalance($nastyId);

// ⚠️ `clearFlood` عمداً صدا زده نمی‌شود: ضدتکرارِ `processed_updates` باید
// خودش جلوی تکرار را بگیرد. اگر اینجا پول کم شود، یعنی webhookِ تکراری
// دوباره پردازش شده — که در عمل یعنی مشتری دوبار پول کم می‌شود.
rawClick(NASTY, json_encode([
    'n' => 'pay', 'id' => (int) $replayOrder['id'], 'm' => PaymentService::METHOD_WALLET,
], JSON_UNESCAPED_UNICODE), $frozenUpdateId);

$afterReplay = $users->walletBalance($nastyId);
check('آپدیتِ تکراریِ تلگرام دوباره پردازش نشد',
    $afterReplay === $afterFirst,
    sprintf('%d → %d (بعد از اولین: %d)', $balReplay, $afterReplay, $afterFirst));
check('و سفارش هم فقط یک‌بار پرداخت شد',
    (int) $orders->find((int) $replayOrder['id'])['attempts'] >= 0);

// ==================================================================
echo "\n▶ بخش ۵) مشتری و ادمین، همزمان\n";
// ==================================================================

// ---- ۵.۱: ادمین وسطِ کار مشتری مسدودش می‌کند ----------------------
$blockedUser = 990031;
$users->upsertByTelegram($blockedUser, ['first_name' => 'MidBlocked']);
$blockedId = (int) $users->findByTelegramId($blockedUser)['id'];

freshBot();
tap($blockedUser, '/start');
$blockedOrder = mkOrder($blockedId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NB'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
]);
$users->adjustWallet($blockedId, 50000, 'شارژ', 0, 'manual');
$balBeforeBlock = $users->walletBalance($blockedId);

// حالا ادمین مسدودش می‌کند — **قبل** از اینکه مشتری پرداخت کند.
$users->setBlocked($blockedId, true, 'نقض قوانین');

clearFlood();
freshBot();
tap($blockedUser, '/buy');
click($blockedUser, 'pay', ['id' => (int) $blockedOrder['id'], 'm' => PaymentService::METHOD_WALLET]);

// ⭐️ کاربرِ مسدود نباید بتواند سفارشش را پرداخت کند. اگر بتواند، مسدودی
// بی‌معنی است — کاربر مسدود می‌تواند همچنان پول خرج کند و پنل بگیرد.
$blockedSpent = $balBeforeBlock - $users->walletBalance($blockedId);
check('کاربرِ مسدود نتوانست پول خرج کند', $blockedSpent === 0, "خرج={$blockedSpent}");
check('سفارشِ کاربرِ مسدود پرداخت‌نشده ماند',
    in_array((string) $orders->find((int) $blockedOrder['id'])['status'], [
        OrderRepository::STATUS_CREATED, OrderRepository::STATUS_CANCELLED,
    ], true),
    (string) $orders->find((int) $blockedOrder['id'])['status']);
check('و ربات خطای فنی نداد', !vagueError(), lastText());

// ادمین برگشتش می‌دهد.
$users->setBlocked($blockedId, false);
clearFlood();
freshBot();
click($blockedUser, 'pay', ['id' => (int) $blockedOrder['id'], 'm' => PaymentService::METHOD_WALLET]);
check('بعد از رفعِ مسدودی، پرداخت ممکن شد',
    (string) $orders->find((int) $blockedOrder['id'])['status'] !== OrderRepository::STATUS_CREATED,
    (string) $orders->find((int) $blockedOrder['id'])['status']);

// ---- ۵.۲: مشتری سفارش می‌سازد، ادمین قیمت را عوض می‌کند --------
$priceUser = 990032;
$users->upsertByTelegram($priceUser, ['first_name' => 'PriceRace']);
$priceId = (int) $users->findByTelegramId($priceUser)['id'];

freshBot();
tap($priceUser, '/start');
$raceOrder = mkOrder($priceId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NP'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
]);

// ادمین قیمتِ بسته را بالا می‌برد، ولی سفارشِ ساخته‌شده باید **همان** قیم
// را نگه دارد — وگرنه کاربر یا بیشتر می‌پردازد یا کمتر از قیمتِ واقعی.
$pkgs->update($cheapPkgId, ['price_toman' => 500000]);

clearFlood();
freshBot();
click($priceUser, 'pay', ['id' => (int) $raceOrder['id'], 'm' => 'card2card']);
check('قیمتِ سفارشِ ساخته‌شده با تغییرِ قیمتِ بسته عوض نشد',
    (int) $orders->find((int) $raceOrder['id'])['price_toman'] === 10000,
    (string) $orders->find((int) $raceOrder['id'])['price_toman']);

$pkgs->update($cheapPkgId, ['price_toman' => 10000]);

// ---- ۵.۳: ادمین سفارش را رد می‌کند، مشتری دوباره تلاش می‌کند ------
$rejectUser = 990033;
$users->upsertByTelegram($rejectUser, ['first_name' => 'Rejected']);
$rejectId = (int) $users->findByTelegramId($rejectUser)['id'];

$reviewOrder = mkOrder($rejectId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NRJ'),
    'status' => OrderRepository::STATUS_AWAITING_PAYMENT,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
    'payment_method' => 'card2card',
]);

$orders->markTerminal((int) $reviewOrder['id'], OrderRepository::STATUS_REJECTED, 'رسید نامعتبر');

clearFlood();
$rejectBalBefore = $users->walletBalance($rejectId);
freshBot();
click($rejectUser, 'pay', ['id' => (int) $reviewOrder['id'], 'm' => PaymentService::METHOD_WALLET]);

check('سفارشِ ردشده با کیف پول هم پرداخت نمی‌شود',
    $users->walletBalance($rejectId) === $rejectBalBefore,
    sprintf('%d → %d', $rejectBalBefore, $users->walletBalance($rejectId)));
check('و وضعیتِ «ردشده» دست‌نخورده ماند',
    (string) $orders->find((int) $reviewOrder['id'])['status'] === OrderRepository::STATUS_REJECTED);

// ⚠️ سفارشِ ردشده **نباید** هرگز دوباره باز شود — حتی با «تلاش مجدد».
clearFlood();
freshBot();
click(BOSS, 'admin.order.retry', ['id' => (int) $reviewOrder['id']]);
check('تلاشِ مجددِ ادمین روی سفارشِ ردشده آن را برنمی‌گرداند',
    (string) $orders->find((int) $reviewOrder['id'])['status'] === OrderRepository::STATUS_REJECTED,
    (string) $orders->find((int) $reviewOrder['id'])['status']);
check('و ادمین هم پیامِ روشن گرفت', !vagueError(), lastText());

// ---- ۵.۴: مشتری ادمین را جعل می‌کند ------------------------------
freshBot();
$adminProbe = mkOrder($victimId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NAF'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 10000, 'original_price_toman' => 10000, 'discount_toman' => 0,
]);

$walletVictimBefore = $users->walletBalance($victimId);

rawClick(NASTY, json_encode(['n' => 'admin.user.wallet', 'id' => $victimId, 'amount' => 500000], JSON_UNESCAPED_UNICODE));
rawClick(NASTY, json_encode(['n' => 'admin.review', 'id' => (int) $adminProbe['id'], 'act' => 'approve'], JSON_UNESCAPED_UNICODE));
rawClick(NASTY, json_encode(['n' => 'admin.flag.toggle', 'key' => Settings::REFERRAL_COMMISSION_PERCENT], JSON_UNESCAPED_UNICODE));
rawClick(NASTY, json_encode(['n' => 'admin.user.block', 'id' => $victimId], JSON_UNESCAPED_UNICODE));

check('جعلِ مدیر، کیف پولِ قربانی را پر نکرد',
    $users->walletBalance($victimId) === $walletVictimBefore,
    sprintf('%d → %d', $walletVictimBefore, $users->walletBalance($victimId)));
check('سفارشِ قربانی تأیید نشد',
    (string) $orders->find((int) $adminProbe['id'])['status'] === OrderRepository::STATUS_CREATED);
check('قربانی مسدود نشد', (int) ($users->findById($victimId)['is_blocked'] ?? 0) === 0);
check('و تنظیماتِ سیستم دست‌نخورده ماند',
    (int) (new Settings())->int(Settings::REFERRAL_COMMISSION_PERCENT, 0) === 10,
    (string) (new Settings())->int(Settings::REFERRAL_COMMISSION_PERCENT, 0));

// ---- ۵.۵: ادمین کیف پول را دستی دستکاری می‌کند ------------------
freshBot();
click(BOSS, 'admin.user', ['id' => $victimId]);
check('ادمین صفحهٔ کاربر را می‌بیند', !vagueError(), lastText());

$victimWalletBefore = $users->walletBalance($victimId);
$users->adjustWallet($victimId, 250000, 'شارژ دستی ادمین', 0, 'manual');
check('شارژِ دستیِ ادمین اعمال شد', $users->walletBalance($victimId) === $victimWalletBefore + 250000);

$txns = $users->walletHistory($victimId, 10);
check('و در تاریخچه ثبت شد', count($txns) >= 1, (string) count($txns));
check('با برچسبِ درست', ($txns[0]['kind'] ?? '') === 'manual', (string) ($txns[0]['kind'] ?? 'null'));

// ==================================================================
echo "\n▶ بخش ۶) مشتری مرزهای مبلغی را می‌آزماید\n";
// ==================================================================

$edgeUser = 990041;
$users->upsertByTelegram($edgeUser, ['first_name' => 'Edge']);
$edgeId = (int) $users->findByTelegramId($edgeUser)['id'];

// ---- دقیقاً برابر موجودی -----------------------------------------
$users->adjustWallet($edgeId, 15000, 'شارژ', 0, 'manual');
$exact = mkOrder($edgeId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NE'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 15000, 'original_price_toman' => 15000, 'discount_toman' => 0,
]);
clearFlood();
freshBot();
click($edgeUser, 'pay', ['id' => (int) $exact['id'], 'm' => PaymentService::METHOD_WALLET]);
check('پرداختِ دقیقاً برابرِ موجودی انجام شد', $users->walletBalance($edgeId) === 0,
    (string) $users->walletBalance($edgeId));

// ---- یک تومان کمتر از موجودی ------------------------------------
$users->adjustWallet($edgeId, 15000, 'شارژ', 0, 'manual');
$almost = mkOrder($edgeId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NA'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 15001, 'original_price_toman' => 15001, 'discount_toman' => 0,
]);
clearFlood();
freshBot();
click($edgeUser, 'pay', ['id' => (int) $almost['id'], 'm' => PaymentService::METHOD_WALLET]);
check('یک تومان کمتر ⇒ موجودی دست‌نخورده', $users->walletBalance($edgeId) === 15000,
    (string) $users->walletBalance($edgeId));
check('و سفارش پرداخت‌نشده ماند',
    (string) $orders->find((int) $almost['id'])['status'] === OrderRepository::STATUS_CREATED);

// ---- مبلغِ غول‌پیکر ----------------------------------------------
$huge = mkOrder($edgeId, [
    'package_id' => $cheapPkgId, 'code' => Str::orderCode('NH'),
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 999999999999, 'original_price_toman' => 999999999999, 'discount_toman' => 0,
]);
$users->adjustWallet($edgeId, 50000000, 'شارژ', 0, 'manual');
$hugeBal = $users->walletBalance($edgeId);
clearFlood();
freshBot();
click($edgeUser, 'pay', ['id' => (int) $huge['id'], 'm' => PaymentService::METHOD_WALLET]);
check('سفارشِ نجومی موجودی را کامل نمی‌بلعد',
    $users->walletBalance($edgeId) === $hugeBal,
    sprintf('%d → %d', $hugeBal, $users->walletBalance($edgeId)));
check('و موجودی منفی نشد', $users->walletBalance($edgeId) >= 0);

// ---- قیمتِ صفر و منفی --------------------------------------------
foreach ([0, -5000] as $i => $price) {
    $zeroOrder = mkOrder($edgeId, [
        'package_id' => $cheapPkgId, 'code' => Str::orderCode('NZ' . $i),
        'status' => OrderRepository::STATUS_CREATED,
        'price_toman' => $price, 'original_price_toman' => $price, 'discount_toman' => 0,
    ]);
    $balBeforeZero = $users->walletBalance($edgeId);
    clearFlood();
    freshBot();
    click($edgeUser, 'pay', ['id' => (int) $zeroOrder['id'], 'm' => PaymentService::METHOD_WALLET]);

    check("قیمتِ {$price} پرداخت‌نشده می‌ماند",
        in_array((string) $orders->find((int) $zeroOrder['id'])['status'], [
            OrderRepository::STATUS_CREATED, OrderRepository::STATUS_CANCELLED,
        ], true),
        (string) $orders->find((int) $zeroOrder['id'])['status']);
    check("و موجودی برای قیمتِ {$price} تغییر نکرد",
        $users->walletBalance($edgeId) === $balBeforeZero,
        sprintf('%d → %d', $balBeforeZero, $users->walletBalance($edgeId)));
}

// ==================================================================
echo "\n▶ بخش ۷) مشتری همه‌جای ربات را می‌گردد\n";
// ==================================================================

$everyPage = [
    'menu', 'profile', 'profile.menu', 'shop.list', 'wallet', 'orders',
    'order.list', 'referral.my', 'referral.list', 'ticket.new', 'rules',
    'support', 'panel.link', 'renew.list', 'help', 'admin.home', 'admin.orders',
    'admin.users', 'admin.stats', 'admin.settings', 'admin.coupons', 'admin.grow',
    'admin.referrals', 'admin.panels', 'admin.tickets', 'admin.flags', 'admin.risk',
    'admin.audit', 'admin.gateways', 'admin.testconfig',
];

$crashed = [];

foreach ($everyPage as $ns) {
    freshBot();
    click(NASTY, $ns);
    click(NASTY, $ns, ['id' => 0]);
    click(NASTY, $ns, ['id' => -1]);
    click(NASTY, $ns, ['id' => 999999]);
    click(NASTY, $ns, ['page' => -5]);

    if (vagueError()) {
        $crashed[] = $ns;
    }
}

check('هیچ صفحه‌ای از پنل مدیریت برای مشتری خطا نداد', $crashed === [],
    'خطادار: ' . implode(', ', $crashed));

// همان فهرست برای ادمین.
$adminCrashed = [];
foreach ($everyPage as $ns) {
    freshBot();
    click(BOSS, $ns);

    if (vagueError()) {
        $adminCrashed[] = $ns;
    }
}

check('هیچ صفحه‌ای برای ادمین خطا نداد', $adminCrashed === [],
    'خطادار: ' . implode(', ', $adminCrashed));

// ---- ۵۰ کلیکِ تصادفی --------------------------------------------
freshBot();
$randomNs = ['a', 'b', 'c', 'zzz', 'pay', 'order.view', 'pkg.buy', 'shop', 'panel.detail'];
$randomCrashes = 0;

for ($i = 0; $i < 50; $i++) {
    $ns = $randomNs[$i % count($randomNs)];
    rawClick(NASTY, json_encode(['n' => $ns, 'id' => $i, 'p' => $i, 'm' => $ns], JSON_UNESCAPED_UNICODE));
    clearFlood();

    if (vagueError()) {
        $randomCrashes++;
    }
}

check('۵۰ کلیکِ تصادفی هیچ‌کدام ربات را نساخت', $randomCrashes === 0, "خطادار={$randomCrashes}");

// ==================================================================
echo "\n▶ بخش ۸) حسابداری: هیچ پولی گم یا اضافه نشد\n";
// ==================================================================

// ⭐️ مهم‌ترین سنجهٔ این تست. بعد از همهٔ آن کوبش‌ها:
//   • هر ریالی که از کیف پول کم شده باید یک سفارشِ پرداخت‌شده داشته باشد
//   • هیچ سفارشِ پرداخت‌شده‌ای نباید بی‌دفتر باشد

$allWallets = $dbx->all('SELECT id, telegram_id, wallet_balance FROM users');

$ledgerOk = true;
foreach ($allWallets as $u) {
    $paidByWallet = $dbx->value(
        "SELECT COALESCE(SUM(price_toman), 0) FROM orders
          WHERE user_id = ? AND payment_method = 'wallet_balance'
            AND status IN ('paid','applied','applying')",
        [(int) $u['id']]
    );

    $txns = $dbx->all(
        "SELECT COALESCE(-SUM(amount), 0) AS spent FROM wallet_txns
          WHERE user_id = ? AND kind = 'wallet_pay'",
        [(int) $u['id']]
    );

    $txnSpent = (int) ($txns[0]['spent'] ?? 0);

    if ($txnSpent !== (int) $paidByWallet) {
        $ledgerOk = false;
        check('دفتر کیف پول با سفارش‌های کیف‌پولی می‌خواند (کاربر '
            . (int) $u['telegram_id'] . ')', false,
            sprintf('تراکنش=%d سفارش=%d', $txnSpent, (int) $paidByWallet));
        break;
    }
}

if ($ledgerOk) {
    check('دفترِ کیف پول با سفارش‌های کیف‌پولی برای همه کاربران می‌خواند', true);
}

$negative = $dbx->all('SELECT telegram_id, wallet_balance FROM users WHERE wallet_balance < 0');
check('هیچ موجودیِ منفی‌ای وجود ندارد', $negative === [],
    json_encode($negative, JSON_UNESCAPED_UNICODE));

$overflow = $dbx->all('SELECT telegram_id, wallet_balance FROM users WHERE wallet_balance > 999999999999');
check('هیچ موجودیِ غیرمنطقی‌ای ثبت نشده', $overflow === [],
    json_encode($overflow, JSON_UNESCAPED_UNICODE));

// ---- کمیسیون: دفتر با کیف پول بخواند -----------------------------
$agents = $dbx->all(
    "SELECT u.telegram_id, u.id,
            COALESCE((SELECT SUM(amount_toman) FROM referral_commissions WHERE referrer_user_id = u.id), 0) AS ledger,
            COALESCE((SELECT -SUM(amount) FROM wallet_txns WHERE user_id = u.id AND kind = 'referral_commission'), 0) AS paid
     FROM users u
     WHERE EXISTS (SELECT 1 FROM referrals r WHERE r.referrer_user_id = u.id)"
);

$commissionOk = true;
foreach ($agents as $ag) {
    if ((int) $ag['ledger'] !== (int) $ag['paid']) {
        $commissionOk = false;
        check('دفترِ کمیسیون با کیف پول می‌خواند (کاربر ' . (int) $ag['telegram_id'] . ')', false,
            sprintf('دفتر=%d کیف پول=%d', (int) $ag['ledger'], (int) $ag['paid']));
        break;
    }
}

if ($commissionOk) {
    check('دفترِ کمیسیون با کیف پول برای همه معرف‌ها می‌خواند', true);
}

// ---- خطاهای ثبت‌شده در لاگ --------------------------------------
$errors = freshErrors($logBaseline);

// پیامِ «خطایی رخ داد» برای کاربر قابل قبول نیست؛ اگر در لاگ هم خطای
// پردازشِ update هست، یعنی یک مسیر واقعاً شکست خورده.
$updateErrors = array_filter($errors, static fn (string $l): bool => str_contains($l, 'Update handling failed'));
check('هیچ آپدیتی در لاگ با خطا مواجه نشد', $updateErrors === [],
    implode(' | ', array_slice(array_values($updateErrors), 0, 3)));

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