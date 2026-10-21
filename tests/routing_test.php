<?php

declare(strict_types=1);

/**
 * تست «همهٔ دکمه‌ها» — سخت‌گیرانه‌ترین تست ناوبری.
 *
 * ## چرا این تست جداگانه لازم است
 *
 * `navigation_test.php` فقط دکمه‌هایی را کلیک می‌کند که از **منوی اصلی** قابل
 * رسیدن‌اند. ولی بیشتر دکمه‌های پروژه از صفحه‌های داخلی‌اند (تنظیمات پنل، صفحهٔ
 * کانفیگ، پنل مدیریت کوپن، …) و هیچ‌کدام از منوی اصلی قابل کشف نیستند.
 *
 * نتیجه: پنج دکمهٔ یک‌بار-پیاده‌سازی‌شده و بدون مسیریابی اصلاً دیده نمی‌شدند و
 * کاربر با کلیک رویشان «این گزینه در دسترس نیست» می‌گرفت — و چون از منو
 * قابل‌رسیدن نبودند، هیچ تستی هم آن‌ها را نمی‌دید.
 *
 * این تست راه را برعکس می‌رود: **خودِ کد را می‌خواند**، هر رشته‌ای که به
 * `BotApi::encodeData()` یا `Keyboard::back()` داده شده را برمی‌دارد، و تک‌تک
 * کلیک می‌کند. پس دکمه‌ای که فردا اضافه شود، خودبه‌خود تحت پوشش می‌آید.
 *
 * سه چیز بررسی می‌شود:
 *   ۱) به «این گزینه در دسترس نیست» نمی‌رسد      ⇒ دکمهٔ مرده نیست
 *   ۲) خطای PHP/استثنای مدیریت‌نشده نمی‌دهد
 *   ۳) پیام خطای عمومی «خطایی رخ داد» نمی‌دهد    ⇒ مسیر شکسته نیست
 *
 * نکتهٔ مهم: بعضی دکمه‌ها باید با **سوپرادمین** کلیک شوند. برای همین هر دکمهٔ
 * `admin.*` با ادمین و همان دکمه با کاربر عادی هم امتحان می‌شود، تا معلوم شود
 * کنترل دسترسی هم درست است (کاربر عادی نباید صفحهٔ مدیریت را ببیند، ولی نباید
 * هم خطای فنی بگیرد).
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
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

const TG  = 9200;
const ADM = 9201;

Config::set('bot_token', '123456:ROUTETESTTOKEN0123456789abcdefghi');
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', [ADM]);
Config::set('crypto_key', 'route-key');
Config::set('panel.api_key', 'pg_key_route_global');

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    echo "  ❌ {$label}\n";

    if ($detail !== '') {
        echo '     ↳ ' . str_replace("\n", "\n       ", mb_substr($detail, 0, 300)) . "\n";
    }
}

function bootKernel(FakeBotApi $bot, FakePanelClient $panel): Kernel
{
    $users  = new UserRepository();
    $pkgs   = new PackageRepository();
    $orders = new OrderRepository();
    $st     = new Settings();
    $flags  = new FeatureFlags($st);
    $panels = new PanelRepository();
    $prov   = new Provisioner($panel, $orders, $users, $st, $panels);
    $pay    = new PaymentService($orders, $prov, $st, $flags, $users);

    return new Kernel(
        $bot, new Notifier($bot), $users, $pkgs, $orders, $prov, $pay,
        $st, new SessionStore(), $panel, $flags
    );
}

/**
 * بذر داده: یک کاربر عادی، یک پنلِ وصل، یک بسته و یک سفارش.
 *
 * بدون داده، خیلی از صفحه‌ها «چیزی برای نمایش نداری» می‌دهند که با خطای فنی
 * فرق دارد و تست را بی‌معنا می‌کند.
 */
function seed(FakePanelClient $panel): array
{
    $users   = new UserRepository();
    $panels  = new PanelRepository();
    $orders  = new OrderRepository();
    $pkgs    = new PackageRepository();

    $uid = (int) $users->upsertByTelegram(TG, ['telegram_id' => TG, 'first_name' => 'کاربر'])['id'];
    $aid = (int) $users->upsertByTelegram(ADM, ['telegram_id' => ADM, 'first_name' => 'ادمین'])['id'];

    $panel->addAdmin('route_owner', [
        'id' => 900, 'status' => 'active', 'data_limit' => 10737418240,
        'role' => ['id' => 1, 'name' => 'owner', 'is_owner' => true],
    ]);
    $panel->ownerUsername = 'route_owner';
    $panel->addAdmin('route_rep', ['id' => 901, 'status' => 'active', 'data_limit' => 1073741824]);
    $panel->bindApiKey('pg_key_route_rep', 'route_rep');

    $panelId = $panels->create($uid, 'route_rep', '', [
        'panel_status' => PanelRepository::STATUS_ACTIVE,
        'data_limit'   => 1073741824,
        'api_key'      => 'pg_key_route_rep',
        'login_url'    => 'https://panel.example.com',
    ]);

    // کاربرِ دوم با پنل — برای تست‌هایی که «انتخاب پنل» دارند.
    $uid2 = (int) $users->upsertByTelegram(9299, ['telegram_id' => 9299])['id'];
    $panel->addAdmin('route_rep2', ['id' => 902, 'status' => 'active', 'data_limit' => 1073741824]);
    $panel->bindApiKey('pg_key_route_rep2', 'route_rep2');
    $panels->create($uid2, 'route_rep2', '', [
        'panel_status' => PanelRepository::STATUS_ACTIVE,
        'data_limit'   => 1073741824,
        'api_key'      => 'pg_key_route_rep2',
    ]);

    $pkgId = $pkgs->create([
        'title' => 'بستهٔ نمایندگی', 'kind' => PackageRepository::KIND_AGENCY,
        'volume_gb' => 10, 'duration_days' => 30, 'price_toman' => 100000, 'active' => 1,
    ]);
    $topupId = $pkgs->create([
        'title' => 'بستهٔ شارژ', 'kind' => PackageRepository::KIND_TOPUP,
        'volume_gb' => 5, 'duration_days' => 30, 'price_toman' => 50000, 'active' => 1,
    ]);

    $orderId = $orders->create($uid, [
        'package_id' => (int) $pkgId, 'package_title' => 'بستهٔ نمایندگی',
        'kind' => PackageRepository::KIND_AGENCY, 'volume_gb' => 10.0,
        'duration_days' => 30, 'price_toman' => 100000,
    ]);
    $orders->markPaid((int) $orderId, 'card2card', 'ref');

    // یک کانفیگ تست فعال، تا صفحه‌های «تست کانفیگ» داده داشته باشند.
    //
    // ⚠️ از ریپازیتوری استفاده می‌شود نه SQL خام: با SQL خام هر مهاجرتِ بعدی
    // این تست را می‌شکند (و یک تستِ ناوبری نباید به اسکیمای جدول‌ها گره بخورد).
    //
    // ⚠️ این کانفیگ **یک‌بار** ساخته و شناسه‌اش برگردانده می‌شود، چون دکمه‌های
    // `panel.test.autodelete.*` با شناسهٔ همین رکورد کار می‌کنند و `seed()`
    // برای هر دکمه دوباره صدا زده می‌شود.
    static $testConfigId = null;

    if ($testConfigId === null) {
        $testConfigId = (new \Pasargad\Store\TestConfigRepository())->create(
            $panelId, $uid, 'route_rep', [
                'data_limit' => 1073741824,
                'expire_at'  => time() + 86400,
                'sub_url'    => 'https://sub.example.com',
            ]
        );
    }

    // یک کوپن، تا صفحه‌های کوپن داده داشته باشند.
    //
    // ⚠️ `seed()` برای هر دکمه دوباره صدا زده می‌شود، پس باید **idempotent**
    // باشد؛ کدِ کوپن یکتا است و درج دوبارهٔ آن خطای یکتایی می‌دهد.
    if ((new \Pasargad\Store\CouponRepository())->findByCode('ROUTE10') === null) {
        (new \Pasargad\Store\CouponRepository())->create([
            'code' => 'ROUTE10', 'kind' => 'percent', 'value' => 10, 'max_uses' => 10,
        ]);
    }

    return ['uid' => $uid, 'aid' => $aid, 'panel_id' => $panelId, 'pkg' => (int) $pkgId,
            'topup' => (int) $topupId, 'order' => (int) $orderId,
            'test_config' => (int) $testConfigId];
}

$seed = seed(new FakePanelClient());

// ==================================================================
echo "\n▶ ۱) جمع‌آوری هر رشته‌ای که به‌عنوان دکمه ساخته می‌شود\n";
// ==================================================================

$found = [];

$src = dirname(__DIR__) . '/src';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

foreach ($rii as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $text = file_get_contents($file->getPathname());

    if (preg_match_all("/encodeData\(\s*'([^']+)'/", $text, $m)) {
        foreach ($m[1] as $ns) {
            $found[$ns][] = basename($file->getPathname());
        }
    }

    if (preg_match_all("/Keyboard::back\(\s*'([^']+)'/", $text, $m)) {
        foreach ($m[1] as $ns) {
            // `menu` و `close` مسیرهای ثابت سراسری‌اند، نه دکمه‌های واقعی.
            if (!in_array($ns, ['menu', 'close', 'noop'], true)) {
                $found[$ns][] = basename($file->getPathname()) . ' (back)';
            }
        }
    }
}

ksort($found);

check('دکمه‌ای برای بررسی پیدا شد', count($found) > 50, 'count=' . count($found));

// ==================================================================
echo "\n▶ ۲) کلیک روی همهٔ دکمه‌ها (سوپرادمین)\n";
// ==================================================================

/**
 * پارامترهای لازم برای دکمه‌هایی که شناسه می‌خواهند.
 *
 * بدون این‌ها خیلی از دکمه‌ها «شناسه نامعتبر» می‌دهند — که خطا نیست، فقط
 * دادهٔ ناقص است. پس شناسه‌های واقعیِ بذر داده به آن‌ها تزریق می‌شود.
 */
function paramsFor(string $ns, array $seed): array
{
    $p = [];

    if (str_contains($ns, 'panel.') && !str_contains($ns, 'panel.test')) {
        $p['id'] = $seed['panel_id'];
    }

    if (str_contains($ns, 'pkg')) {
        $p['id'] = str_contains($ns, 'topup') ? $seed['topup'] : $seed['pkg'];
    }

    if (str_contains($ns, 'order')) {
        $p['id'] = $seed['order'];
    }

    if (str_contains($ns, 'admin.pkg')) {
        $p['id'] = $seed['pkg'];
    }

    if (str_contains($ns, 'admin.order') || $ns === 'admin.retry' || $ns === 'admin.review') {
        $p['id'] = $seed['order'];
    }

    if (str_contains($ns, 'admin.panel') && $ns !== 'admin.panels') {
        $p['id'] = $seed['panel_id'];
    }

    if (str_contains($ns, 'admin.user')) {
        $p['id'] = $seed['uid'];
    }

    if (str_contains($ns, 'testconfig') || str_contains($ns, 'admin.testconfig')) {
        $p['id'] = $seed['panel_id'];
    }

    if ($ns === 'admin.coupon.delete' || $ns === 'admin.coupon.toggle') {
        $p['code'] = 'ROUTE10';
    }

    // ⚠️ `panel.test.autodelete.*` شناسهٔ **کانفیگ تست** می‌خواهد، نه پنل.
    // با شناسهٔ پنل، رکورد پیدا نمی‌شود و صفحه خطا نشان می‌دهد — که خطای
    // محصول نیست، فقط دادهٔ ناقص تست.
    if (str_contains($ns, 'panel.test.autodelete') || $ns === 'panel.test.off') {
        $p['id'] = $seed['test_config'];
    }

    if ($ns === 'pay') {
        $p['id'] = $seed['order'];
    }

    return $p;
}

$deadAdmin = [];
$crashed   = [];
$generic   = [];
$noReply   = [];

foreach (array_keys($found) as $ns) {
    $panel = new FakePanelClient();
    seed($panel);

    $bot    = new FakeBotApi();
    $kernel = bootKernel($bot, $panel);

    $payload = ['n' => $ns] + paramsFor($ns, $seed);

    // هر سناریو کاربر تازه‌ای ندارد؛ بدون خالی‌کردن پنجرهٔ ضداسپم، بعد از ۱۵
    // کلیک بقیهٔ دکمه‌ها بی‌پاسخ می‌مانند و تست به‌اشتباه شکست می‌خورد.
    $db->run('DELETE FROM flood_guard');

    try {
        $kernel->handle(new Update([
            'update_id' => crc32($ns) % 100000 + 1,
            'callback_query' => [
                'id'            => 'cb_' . crc32($ns),
                'from'          => ['id' => ADM, 'is_bot' => false, 'first_name' => 'A'],
                'chat_instance' => 'ci',
                'data'          => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'message'       => ['message_id' => 1, 'date' => time(),
                                    'chat' => ['id' => ADM, 'type' => 'private']],
            ],
        ]));
    } catch (\Throwable $e) {
        $crashed[$ns] = $e->getMessage();
        continue;
    }

    $text = $bot->allText();

    if (str_contains($text, 'این گزینه در دسترس نیست')) {
        $deadAdmin[$ns] = $text;
        continue;
    }

    if (str_contains($text, 'خطایی رخ داد')) {
        $generic[$ns] = $text;
        continue;
    }

    if (trim($text) === '' && !$bot->hasButton('بستن')) {
        // پاسخِ خالی لزوماً ایراد نیست (بعضی صفحه‌ها فقط کیبورد می‌فرستند)،
        // ولی باید دست‌کم یک پیام یا یک دکمه داشته باشند.
        if (($bot->sent === []) || (end($bot->sent)['keyboard'] ?? []) === []) {
            $noReply[$ns] = 'هیچ پیام و هیچ کیبوردی فرستاده نشد';
        }
    }
}

check('هیچ دکمه‌ای «در دسترس نیست» نمی‌گوید', $deadAdmin === [],
    'مرده: ' . implode(', ', array_keys($deadAdmin)));
check('هیچ دکمه‌ای استثنا نمی‌دهد', $crashed === [],
    $crashed === []
        ? ''
        : implode("\n", array_map(
            static fn (string $k, string $v): string => "{$k}: {$v}",
            array_keys($crashed),
            array_values($crashed)
        )));
check('هیچ دکمه‌ای خطای عمومی نمی‌دهد', $generic === [],
    implode(' | ', array_keys($generic)));
check('هیچ دکمه‌ای بی‌پاسخ نمی‌ماند', $noReply === [],
    implode(' | ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($noReply), array_values($noReply))));

// ==================================================================
echo "\n▶ ۳) دکمه‌های مدیریتی از دستِ کاربر عادی\n";
// ==================================================================
//
// کنترل دسترسی هم باید آزموده شود: کاربر عادی نباید صفحهٔ مدیریت را باز کند، ولی
// نباید خطای فنی هم بگیرد (که لو می‌دهد کد اجرا شده).

$leaked = [];

foreach (array_keys($found) as $ns) {
    if (!str_starts_with($ns, 'admin.')) {
        continue;
    }

    $panel = new FakePanelClient();
    seed($panel);

    $bot    = new FakeBotApi();
    $kernel = bootKernel($bot, $panel);

    $payload = ['n' => $ns] + paramsFor($ns, $seed);

    // هر سناریو کاربر تازه‌ای ندارد؛ بدون خالی‌کردن پنجرهٔ ضداسپم، بعد از ۱۵
    // کلیک بقیهٔ دکمه‌ها بی‌پاسخ می‌مانند و تست به‌اشتباه شکست می‌خورد.
    $db->run('DELETE FROM flood_guard');

    try {
        $kernel->handle(new Update([
            'update_id' => (crc32($ns) % 90000) + 500000,
            'callback_query' => [
                'id'            => 'cu_' . crc32($ns),
                'from'          => ['id' => TG, 'is_bot' => false, 'first_name' => 'U'],
                'chat_instance' => 'ci',
                'data'          => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'message'       => ['message_id' => 1, 'date' => time(),
                                    'chat' => ['id' => TG, 'type' => 'private']],
            ],
        ]));
    } catch (\Throwable $e) {
        $leaked[$ns] = 'EXCEPTION: ' . $e->getMessage();
        continue;
    }

    $text = $bot->allText();

    // نشانه‌های دسترسیِ نشت‌کرده: صفحه‌ای که فقط مدیر باید ببیند.
    foreach (['💰 درآمد', '📊 آمار کلی', '👥 کاربران', '🔧 تنظیمات Pasargad'] as $marker) {
        if (str_contains($text, $marker)) {
            $leaked[$ns] = $marker;
            break;
        }
    }

    if (str_contains($text, 'خطایی رخ داد')) {
        $leaked[$ns] = 'خطای عمومی';
    }
}

check('کاربر عادی به هیچ صفحهٔ مدیریتی دسترسی ندارد', $leaked === [],
    implode(' | ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($leaked), array_values($leaked))));

// ==================================================================
echo "\n▶ ۴) دکمه‌هایی که فقط برای کاربر عادی ساخته می‌شوند\n";
// ==================================================================
//
// تقارنِ تست قبلی: اگر دکمه‌ای مخصوص کاربر عادی باشد ولی به سوپرادمین خطا دهد، آن
// هم باگ است — سوپرادمین هم کاربر ربات است.

$brokenForAdmin = [];

foreach (array_keys($found) as $ns) {
    if (str_starts_with($ns, 'admin.')) {
        continue;
    }

    $panel = new FakePanelClient();
    seed($panel);

    $bot    = new FakeBotApi();
    $kernel = bootKernel($bot, $panel);

    $payload = ['n' => $ns] + paramsFor($ns, $seed);

    // هر سناریو کاربر تازه‌ای ندارد؛ بدون خالی‌کردن پنجرهٔ ضداسپم، بعد از ۱۵
    // کلیک بقیهٔ دکمه‌ها بی‌پاسخ می‌مانند و تست به‌اشتباه شکست می‌خورد.
    $db->run('DELETE FROM flood_guard');

    try {
        $kernel->handle(new Update([
            'update_id' => (crc32($ns) % 90000) + 700000,
            'callback_query' => [
                'id'            => 'ca_' . crc32($ns),
                'from'          => ['id' => ADM, 'is_bot' => false, 'first_name' => 'A'],
                'chat_instance' => 'ci',
                'data'          => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'message'       => ['message_id' => 1, 'date' => time(),
                                    'chat' => ['id' => ADM, 'type' => 'private']],
            ],
        ]));
    } catch (\Throwable $e) {
        $brokenForAdmin[$ns] = 'EXCEPTION: ' . $e->getMessage();
        continue;
    }

    $text = $bot->allText();

    if (str_contains($text, 'خطایی رخ داد')) {
        $brokenForAdmin[$ns] = 'خطای عمومی';
    }
}

check('سوپرادمین هم به صفحات کاربری دسترسی دارد', $brokenForAdmin === [],
    implode(' | ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($brokenForAdmin), array_values($brokenForAdmin))));

echo "\n" . str_repeat('─', 56) . "\n";
echo 'نتیجه: ' . $passed . ' موفق، ' . $failed . " ناموفق\n";
echo str_repeat('─', 56) . "\n";

exit($failed === 0 ? 0 : 1);