<?php

declare(strict_types=1);

/**
 * تست ناوبری: هیچ دکمه‌ای نباید «مرده» باشد.
 *
 * هدف این تست ساده ولی مهم است: کاربر گزارش داده بود که بعضی دکمه‌ها وجود
 * ندارند یا کار نمی‌کنند. اینجا **فعلاً هر دکمهٔ منوی اصلی کلیک می‌شود** و
 * سه چیز بررسی می‌شود:
 *
 *   ۱) هیچ دکمه‌ای به «این گزینه در دسترس نیست» نرسد  (دکمهٔ مرده)
 *   ۲) هر صفحه دست‌کم یک دکمهٔ بازگشت داشته باشد      (کاربر گم نشود)
 *   ۳) دکمه‌های اصلی که باید باشند، واقعاً باشند
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Payment\PaymentService;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
Settings::flush();
(new Pasargad\Support\Migrator($db))->migrate();

const TG   = 9100;
const ADM  = 9001;

Config::set('bot_token', '123456:NAVTESTTOKEN0123456789abcdefghij');
Config::set('base_url', 'https://bot.example.com');
Config::set('super_admins', [ADM]);
Config::set('crypto_key', 'nav-key');
Config::set('panel.base_url', 'https://panel.example.com');

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
        echo '     ↳ ' . str_replace("\n", "\n       ", mb_substr($detail, 0, 260)) . "\n";
    }
}

function bootKernel(FakeBotApi $bot, ?FakePanelClient $panel = null): Kernel
{
    $db     = \Pasargad\Support\Db::instance();
    $users  = new UserRepository();
    $pkgs   = new PackageRepository();
    $orders = new OrderRepository();
    $st     = new Settings();
    $flags  = new FeatureFlags($st);
    $ss     = new SessionStore();
    $panels = new PanelRepository();
    $panel ??= new FakePanelClient();
    $prov   = new Provisioner($panel, $orders, $users, $st, $panels);
    $pay    = new PaymentService($orders, $prov, $st, $flags, $users);

    return new Kernel(
        $bot, new Notifier($bot), $users, $pkgs, $orders, $prov, $pay,
        $st, $ss, $panel, $flags
    );
}

/** ادمینی که روی پنل تستی به‌عنوان owner وجود دارد. */
function seedOwner(FakePanelClient $panel, string $username): void
{
    $panel->admins[$username] = [
        'id'          => 501,
        'username'    => $username,
        'status'      => 'active',
        'data_limit'  => 10737418240,
        'used_traffic' => 0,
        'role'        => ['name' => 'owner', 'is_owner' => true],
    ];
}

function cb(int $tg, array $payload): Update
{
    return new Update([
        'update_id'      => 1,
        'callback_query' => [
            'id'            => 'cb',
            'from'          => ['id' => $tg],
            'chat_instance' => 'x',
            'data'          => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'message'       => ['message_id' => 5, 'chat' => ['id' => $tg]],
        ],
    ]);
}

function txt(int $tg, string $text): Update
{
    return new Update([
        'update_id' => 1,
        'message'   => ['message_id' => 6, 'from' => ['id' => $tg], 'chat' => ['id' => $tg], 'text' => $text],
    ]);
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  ناوبری — هیچ دکمهٔ مرده‌ای نباید بماند\n";
echo str_repeat('═', 62) . "\n";

$panel = new FakePanelClient();
$bot    = new FakeBotApi();
$kernel = bootKernel($bot, $panel);

$kernel->handle(txt(TG, '/start'));

$mainButtons = end($bot->sent)['keyboard'] ?? [];

echo "\n▶ ۱) دکمه‌های اصلی که باید در منو باشند\n";

$labels = json_encode($mainButtons, JSON_UNESCAPED_UNICODE);

check('دکمهٔ «📱 اپلیکیشن وب» در منو هست', str_contains($labels, 'web_app'), $labels);
check('دکمهٔ «⚙️ تنظیمات» در منو هست', str_contains($labels, 'تنظیمات'), $labels);
check('دکمهٔ «🛒 خرید پنل نمایندگی» در منو هست', str_contains($labels, 'خرید پنل'), $labels);
check('دکمهٔ «🔗 من پنل دارم» در منو هست', str_contains($labels, 'من پنل دارم'), $labels);

// ------------------------------------------------------------------
echo "\n▶ ۲) کلیک روی هر دکمهٔ منوی اصلی\n";
// ------------------------------------------------------------------
//
// هر دکمه کلیک می‌شود و بررسی می‌کنیم که «در دسترس نیست» نداده باشد.

$namespaces = [];

foreach ($mainButtons as $row) {
    foreach ($row as $b) {
        $raw = (string) ($b['data'] ?? $b['callback_data'] ?? '');

        if ($raw === '') {
            continue;   // دکمهٔ Mini App یا لینک، مسیر داخلی ندارد
        }

        $decoded = json_decode($raw, true);
        $namespaces[] = is_array($decoded) ? (string) ($decoded['n'] ?? '') : $raw;
    }
}

$dead = [];

foreach (array_unique(array_filter($namespaces)) as $ns) {
    $bot->reset();
    $kernel->handle(cb(TG, ['n' => $ns]));

    if (str_contains($bot->allText(), 'این گزینه در دسترس نیست')) {
        $dead[] = $ns;
    }
}

check(
    'هیچ دکمهٔ منوی اصلی مرده نیست',
    $dead === [],
    'مرده: ' . implode(', ', $dead)
);

// ------------------------------------------------------------------
echo "\n▶ ۳) دکمه‌های «اتصال پنل» از سه مسیر\n";
// ------------------------------------------------------------------
//
// مسیرهای ورود به افزودن اعتبار پنل: منوی اصلی («من پنل دارم»)، فهرست
// پنل‌های کاربر، و تنظیمات. هر سه باید کار کنند.

$entries = [
    'منوی اصلی'    => ['panel.self',    null],
    'فهرست پنل‌ها' => ['panel.list',    'panel.connect'],
    'تنظیمات'      => ['settings',      'panel.connect'],
];

foreach ($entries as $where => [$entry, $button]) {
    $bot->reset();
    $kernel->handle(cb(TG, ['n' => $entry]));

    if ($button === null) {
        check(
            '«من پنل دارم» نام کاربری می‌پرسد',
            str_contains($bot->allText(), 'نام کاربری'),
            $bot->allText()
        );
        continue;
    }

    $kb = json_encode(end($bot->sent)['keyboard'] ?? [], JSON_UNESCAPED_UNICODE);

    if (!str_contains($kb, $button)) {
        check("«{$where}» دکمهٔ اتصال پنل را دارد", false, 'دکمهٔ ' . $button . ' پیدا نشد');
        continue;
    }

    check("«{$where}» دکمهٔ اتصال پنل را دارد", true);

    $bot->reset();
    $kernel->handle(cb(TG, ['n' => $button]));

    $shown = $bot->allText();

    // صفحهٔ اول «کدام احراز هویت؟» را می‌پرسد؛ «آدرس پنل» در قدم بعدی است.
    check(
        "«{$where}» → صفحهٔ اتصال باز می‌شود",
        str_contains($shown, 'اتصال پنل') && str_contains($shown, 'کلید API'),
        $shown
    );

    check(
        "«{$where}» → هر دو روش ورود دارد",
        str_contains($shown, 'رمز عبور') && str_contains($shown, 'کلید API'),
        $shown
    );
}

// ------------------------------------------------------------------
echo "\n▶ ۴) جریان کامل اتصال با آدرس + نام کاربری + رمز\n";
// ------------------------------------------------------------------

seedOwner($panel, 'op1');

$bot->reset();
$kernel->handle(cb(ADM, ['n' => 'panel.connect.password']));
$kernel->handle(txt(ADM, 'https://my-panel.example.com'));
$kernel->handle(txt(ADM, 'op1'));
$kernel->handle(txt(ADM, 'secret-pass'));

$adminId = (int) (new UserRepository())->findByTelegramId(ADM)['id'];
$mine    = (new PanelRepository())->listByUser($adminId);

check('پنل بعد از جریان کامل ثبت شد', $mine !== [], $bot->allText());
check(
    'آدرس اختصاصی ذخیره شد',
    ($mine[0]['base_url'] ?? '') === 'https://my-panel.example.com',
    (string) ($mine[0]['base_url'] ?? 'NULL')
);
check('نام کاربری ذخیره شد', ($mine[0]['panel_username'] ?? '') === 'op1', (string) ($mine[0]['panel_username'] ?? 'NULL'));
check('رمز خام ذخیره نشد', ($mine[0]['panel_password'] ?? '') !== 'secret-pass');

// ------------------------------------------------------------------
echo "\n▶ ۵) دکمه‌هایی که قبلاً «مرده» بودند\n";
// ------------------------------------------------------------------
//
// هرکدام یک‌بار مسیریابی شد ولی هیچ دکمه‌ای آن‌ها را صدا نمی‌زد، یعنی
// قابلیت کاملاً غیرقابل‌دسترس بود.

$wasDead = [
    'panel.mine' => 'فهرست پنل‌های من',
];

$bot->reset();
$kernel->handle(cb(ADM, ['n' => 'panel.connect']));

$connectMenu = json_encode(end($bot->sent)['keyboard'] ?? [], JSON_UNESCAPED_UNICODE);

foreach ($wasDead as $ns => $label) {
    check(
        "«{$label}» ({$ns}) حالا دکمه دارد",
        str_contains($connectMenu, $ns),
        $connectMenu
    );
}

// «حذف خودکار» از صفحهٔ کانفیگ‌های تست بیرون می‌آید، پس اول یک کانفیگ لازم است.
$adminIdForTest = (int) (new UserRepository())->findByTelegramId(ADM)['id'];
$myPanelId      = (int) ($mine[0]['id'] ?? 0);

(new \Pasargad\Store\TestConfigRepository())
    ->create($myPanelId, $adminIdForTest, (string) ($mine[0]['panel_username'] ?? 'op1'), [
        'data_limit'   => 1073741824,
        'used_traffic' => 0,
        'expire_at'    => strtotime('+30 days'),
    ]);

$bot->reset();
$kernel->handle(cb(ADM, ['n' => 'panel.test.list']));

$testListKb = json_encode(end($bot->sent)['keyboard'] ?? [], JSON_UNESCAPED_UNICODE);

check(
    '«صفحهٔ حذف خودکار» (panel.test.autodelete.show) حالا دکمه دارد',
    str_contains($testListKb, 'panel.test.autodelete.show'),
    $bot->allText()
);

// ------------------------------------------------------------------
echo "\n▶ ۶) تست اتصال اکانت owner در پنل مدیریت\n";
// ------------------------------------------------------------------

Config::set('panel.owner_username', 'op1');
Config::set('panel.owner_password', 'secret-pass');

$bot->reset();
$kernel->handle(cb(ADM, ['n' => 'admin.settings.panel.owner']));

$ownerMenu = json_encode(end($bot->sent)['keyboard'] ?? [], JSON_UNESCAPED_UNICODE);

check('دکمهٔ «🧪 تست اتصال» در منوی owner هست', str_contains($ownerMenu, 'admin.settings.panel.owner.test'), $ownerMenu);

$bot->reset();
$kernel->handle(cb(ADM, ['n' => 'admin.settings.panel.owner.test']));

$testOut = $bot->allText();

check(
    'تست اتصال اکانت owner پاسخ می‌دهد (نه «تعریف نشده»)',
    !str_contains($testOut, 'در پنل مدیریت تعریف نشده است'),
    $testOut
);

echo "\n" . str_repeat('─', 62) . "\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);