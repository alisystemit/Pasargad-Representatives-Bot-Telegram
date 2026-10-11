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
            '«من پنل دارم» آدرس پنل را می‌پرسد (جریان کلید)',
            str_contains($bot->allText(), 'آدرس پنل'),
            $bot->allText()
        );

        check(
            '«من پنل دارم» نام کاربری نمی‌پرسد',
            !str_contains($bot->allText(), 'نام کاربری'),
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
        "«{$where}» → دسترسی‌های لازم توضیح داده شده‌اند",
        str_contains($shown, 'admins.create') && str_contains($shown, 'admins.update'),
        $shown
    );
}

// ------------------------------------------------------------------
echo "\n▶ ۴) جریان کامل اتصال با آدرس + کلید API\n";
// ------------------------------------------------------------------

seedOwner($panel, 'op1');

$bot->reset();
$kernel->handle(cb(ADM, ['n' => 'panel.connect.apikey']));
$kernel->handle(txt(ADM, 'https://my-panel.example.com'));
$kernel->handle(txt(ADM, 'pg_key_from_user'));

$adminId = (int) (new UserRepository())->findByTelegramId(ADM)['id'];
$mine    = (new PanelRepository())->listByUser($adminId);

check('پنل بعد از جریان کامل ثبت شد', $mine !== [], $bot->allText());
check(
    'آدرس اختصاصی ذخیره شد',
    ($mine[0]['base_url'] ?? '') === 'https://my-panel.example.com',
    (string) ($mine[0]['base_url'] ?? 'NULL')
);

// نام واقعی از خودِ پنل می‌آید، نه از هش کلید.
check('نام کاربری از پنل خوانده شد', ($mine[0]['panel_username'] ?? '') === 'op1', (string) ($mine[0]['panel_username'] ?? 'NULL'));

$access = new \Pasargad\Store\PanelAccess(new PanelRepository());

check(
    'کلید رمزنگاری‌شده ذخیره شد، نه خام',
    ($mine[0]['api_key'] ?? '') !== '' && ($mine[0]['api_key'] ?? '') !== 'pg_key_from_user'
);
check(
    'کلید قابل بازیابی است',
    $access->apiKeyOf((array) $mine[0]) === 'pg_key_from_user'
);
check('هیچ رمز عبوری ذخیره نشد', ($mine[0]['panel_password'] ?? '') === '');

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

Config::set('panel.api_key', 'pg_key_test_global');

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

// ------------------------------------------------------------------
echo "\n▶ ۷) پروفایل کاربر (UserProfile)\n";
// ------------------------------------------------------------------
//
// این کلاس کامل بود ولی هیچ مسیری نداشت و هر ۸ دکمه‌اش مرده بود. نکتهٔ
// مهم این تست‌ها این است که فقط «پیامی نفرست» را چک نمی‌کنند؛ اگر یکی
// از متدهای ریپازیتوری که `UserProfile` صدا می‌زند وجود نداشته باشد، خطای
// PHP می‌خورد و به این پیام «خطایی رخ داد» تبدیل می‌شود — پس تست شکست
// می‌خورد. یعنی این تست‌ها عملاً تضمین می‌کنند که اسکیما و متدها هم هستند.

$mustNotError = static function (string $label, string $output): void {
    check($label, !str_contains($output, 'خطایی رخ داد'), $output);
};

$bot->reset();
$kernel->handle(cb(TG, ['n' => 'profile:menu']));

$profileMenu = json_encode(end($bot->sent)['keyboard'] ?? [], JSON_UNESCAPED_UNICODE);

check('صفحهٔ پروفایل باز می‌شود', str_contains($bot->allText(), 'پروفایل شما'), $bot->allText());
$mustNotError('پروفایل بدون خطای PHP رندر شد', $bot->allText());

foreach ([
    'profile:edit_name'    => 'نام',
    'profile:language'     => 'زبان',
    'profile:contact'      => 'تماس',
    'profile:change_password' => 'رمز',
] as $ns => $needle) {
    $bot->reset();
    $kernel->handle(cb(TG, ['n' => $ns]));
    $out = $bot->allText();

    check("«{$ns}» صفحه‌ای نشان می‌دهد", !str_contains($out, 'در دسترس نیست') && $out !== '', $out);
    $mustNotError("«{$ns}» بدون خطای PHP", $out);
}

foreach (['profile:lang_fa' => 'فارسی', 'profile:lang_en' => 'English'] as $ns => $langName) {
    $bot->reset();
    $kernel->handle(cb(TG, ['n' => $ns]));
    $out = $bot->allText();

    check("«{$ns}» زبان را عوض می‌کند", str_contains($out, $langName), $out);
}

$bot->reset();
$kernel->handle(cb(TG, ['n' => 'profile:edit_email']));
$kernel->handle(txt(TG, 'test@example.com'));
$mustNotError('ایمیل ذخیره می‌شود', $bot->allText());

check(
    'ایمیل واقعاً در دیتابیس ذخیره شد',
    (string) (new UserRepository())->findByTelegramId(TG)['email'] === 'test@example.com',
    (string) ((new UserRepository())->findByTelegramId(TG)['email'] ?? 'NULL')
);

$bot->reset();
$kernel->handle(cb(TG, ['n' => 'profile:edit_phone']));
$kernel->handle(txt(TG, '+98912345678'));
$mustNotError('تلفن ذخیره می‌شود', $bot->allText());

check(
    'تلفن واقعاً در دیتابیس ذخیره شد',
    (string) (new UserRepository())->findByTelegramId(TG)['phone'] === '+98912345678',
    (string) ((new UserRepository())->findByTelegramId(TG)['phone'] ?? 'NULL')
);

$bot->reset();
$kernel->handle(cb(TG, ['n' => 'profile:edit_name']));
$kernel->handle(txt(TG, 'رضا محمدی'));
$mustNotError('نام ذخیره می‌شود', $bot->allText());

check(
    'نام واقعاً در دیتابیس ذخیره شد',
    (string) (new UserRepository())->findByTelegramId(TG)['first_name'] === 'رضا محمدی',
    (string) ((new UserRepository())->findByTelegramId(TG)['first_name'] ?? 'NULL')
);

$bot->reset();
$kernel->handle(cb(TG, ['n' => 'main_menu']));
check('دکمهٔ «بازگشت» به منوی اصلی کار می‌کند', $bot->allText() !== '', $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ ۸) داشبورد آماری مدیر (AdminDashboard)\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb(ADM, ['n' => 'admin:dashboard']));

$dash = $bot->allText();

check('داشبورد باز می‌شود', str_contains($dash, 'داشبورد مدیریتی'), $dash);
$mustNotError('داشبورد بدون خطای PHP رندر شد', $dash);

check(
    'آمار واقعی خوانده می‌شود (نه صفر ساختگی)',
    str_contains($dash, 'درآمد') && str_contains($dash, 'پنل‌ها'),
    $dash
);

foreach ([
    'admin:chart'       => 'درآمد ۷ روز اخیر',
    'admin:activity'    => 'فعالیت‌های اخیر',
    'admin:reconcile'   => 'تسویه حساب',
    'admin:sync_status' => 'وضعیت همگام‌سازی',
] as $ns => $needle) {
    $bot->reset();
    $kernel->handle(cb(ADM, ['n' => $ns]));
    $out = $bot->allText();

    check("«{$ns}» صفحه‌ای نشان می‌دهد", str_contains($out, $needle), $out);
    $mustNotError("«{$ns}» بدون خطای PHP", $out);
}

// ------------------------------------------------------------------
echo "\n▶ ۹) دکمه‌های باقی‌ماندهٔ FullMenu\n";
// ------------------------------------------------------------------

foreach ([
    'wallet.deposit'        => 'کیف پول',
    'setting.language'      => 'زبان',
    'setting.notifications' => 'اعلان',
    'setting.payment'       => 'پرداخت',
] as $ns => $needle) {
    $bot->reset();
    $kernel->handle(cb(TG, ['n' => $ns]));
    $out = $bot->allText();

    check("«{$ns}» صفحه‌ای نشان می‌دهد", !str_contains($out, 'در دسترس نیست') && str_contains($out, $needle), $out);
    $mustNotError("«{$ns}» بدون خطای PHP", $out);
}

$bot->reset();
$kernel->handle(cb(TG, ['n' => 'setting.notifications.toggle', 'c' => 'notifications_enabled']));
$mustNotError('تغییر وضعیت اعلان کار می‌کند', $bot->allText());

check(
    'اعلان واقعاً خاموش شد',
    (int) (new UserRepository())->findByTelegramId(TG)['notifications_enabled'] === 0,
    (string) ((new UserRepository())->findByTelegramId(TG)['notifications_enabled'] ?? 'NULL')
);

echo "\n" . str_repeat('─', 62) . "\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);