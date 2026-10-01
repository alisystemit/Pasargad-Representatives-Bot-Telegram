<?php

declare(strict_types=1);

/**
 * تست‌های امنیتی: کنترل دسترسی، SQL injection، XSS و اعتبارسنجی.
 *
 * هدف: اطمینان از اینکه یک کاربر عادی نمی‌تواند به پنل مدیریت یا
 * سفارش دیگران دسترسی پیدا کند و ورودی‌های مخرب بی‌خطر پردازش می‌شوند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Panel\FakePanelClient;
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
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
(new Migrator($db))->migrate();

Config::set('super_admins', [999]);
Config::set('store.card_number', '6037999999999999');

$panel    = new FakePanelClient();
$bot      = new FakeBotApi();
$settings = new Settings($db);
$users    = new UserRepository($db);
$packages = new PackageRepository($db);
$orders   = new OrderRepository($db);

$provisioner = new Provisioner($panel, $orders, $users, $settings);
$payments    = new PaymentService($orders, $provisioner, $settings);

$kernel = new Kernel(
    $bot, new Notifier($bot), $users, $packages, $orders,
    $provisioner, $payments, $settings, new SessionStore($db), $panel
);

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

function cb(int $userId, array $payload, int $msgId = 100): Update
{
    return new Update([
        'update_id'      => random_int(1, 999999),
        'callback_query' => [
            'id'            => 'cb' . random_int(1000, 99999),
            'chat_instance' => 'ci',
            'data'          => json_encode($payload),
            'from'          => ['id' => $userId],
            'message'       => ['message_id' => $msgId, 'chat' => ['id' => $userId]],
        ],
    ]);
}

function msg(int $userId, string $text, int $msgId = 200): Update
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

// ------------------------------------------------------------------
echo "\n▶ سناریو: ادمین و کاربر عادی\n";
// ------------------------------------------------------------------

$adminId = 999;
$adminRow = $users->upsertByTelegram($adminId, [
    'telegram_id' => $adminId,
    'first_name'  => 'سوپرادمین',
    'panel_username' => 'superadmin',
    'panel_password' => Crypto::encrypt('admin-pass'),
    'panel_status'   => 'active',
])['id'];

$panel->addAdmin('superadmin', ['data_limit' => 0, 'used_traffic' => 0]);

$userId = 1111;
$userRow = $users->upsertByTelegram($userId, [
    'telegram_id'    => $userId,
    'panel_username' => 'normaluser',
    'panel_password' => Crypto::encrypt('user-pass'),
    'panel_status'   => 'active',
])['id'];

$panel->addAdmin('normaluser', ['data_limit' => 0, 'used_traffic' => 0]);

// ------------------------------------------------------------------
echo "\n▶ کاربر عادی به پنل مدیریت دسترسی ندارد\n";
// ------------------------------------------------------------------

$adminPages = [
    'admin.home',
    'admin.stats',
    'admin.packages',
    'admin.settings',
    'admin.users',
    'admin.orders',
];

foreach ($adminPages as $ns) {
    $bot->reset();
    $kernel->handle(cb($userId, ['n' => $ns]));

    $text = $bot->allText();
    check("«{$ns}» برای کاربر عادی مسدود است", !str_contains($text, 'پنل مدیریت') && !str_contains($text, 'مدیریت بسته'), $text);
}

// ------------------------------------------------------------------
echo "\n▶ کاربر عادی نمی‌تواند سفارش دیگران را ببیند\n";
// ------------------------------------------------------------------

$pkgId = $packages->create([
    'title' => 'بستهٔ تست', 'kind' => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb' => 50, 'duration_days' => 30, 'price_toman' => 300000,
    'sort_order' => 1, 'is_active' => true,
]);

$otherOrder = $orders->create($adminRow, [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 50,
    'duration_days' => 30, 'price_toman' => 300000,
    'status' => OrderRepository::STATUS_PAID,
]);

$bot->reset();
$kernel->handle(cb($userId, ['n' => 'order.view', 'id' => (int) $otherOrder['id']]));
check('سفارش دیگران برای کاربر عادی نمایش داده نشد', str_contains($bot->allText(), 'پیدا نشد'), $bot->allText());

$bot->reset();
$kernel->handle(cb($userId, ['n' => 'admin.order.view', 'id' => (int) $otherOrder['id']]));
check('جزئیات سفارش دیگران از مسیر ادمین مسدود است', !str_contains($bot->allText(), 'جزئیات سفارش'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ کاربر عادی نمی‌تواند کاربر دیگری را مسدود کند\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb($userId, ['n' => 'admin.user.block', 'id' => (int) $userRow]));
check('مسدودسازی از مسیر عادی انجام نشد', (int) $users->findByTelegramId($adminId)['is_blocked'] === 0);

// سوپرادمین می‌تواند
$bot->reset();
$kernel->handle(cb($adminId, ['n' => 'admin.user.block', 'id' => (int) $userRow]));
check('سوپرادمین می‌تواند مسدود کند', (int) $users->findByTelegramId($userId)['is_blocked'] === 1);
$kernel->handle(cb($adminId, ['n' => 'admin.user.block', 'id' => (int) $userRow]));
check('سوپرادمین می‌تواند رفع مسدودی کند', (int) $users->findByTelegramId($userId)['is_blocked'] === 0);

// ------------------------------------------------------------------
echo "\n▶ سوپرادمین به پنل مدیریت دسترسی دارد\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(cb($adminId, ['n' => 'admin.home']));
check('پنل مدیریت برای سوپرادمین باز شد', str_contains($bot->allText(), 'پنل مدیریت'), $bot->allText());

$bot->reset();
$kernel->handle(cb($adminId, ['n' => 'admin.packages']));
check('مدیریت بسته‌ها برای سوپرادمین باز شد', str_contains($bot->allText(), 'مدیریت بسته'), $bot->allText());

$bot->reset();
$kernel->handle(cb($adminId, ['n' => 'admin.stats']));
check('آمار برای سوپرادمین باز شد', str_contains($bot->allText(), 'گزارش آماری'), $bot->allText());

$bot->reset();
$kernel->handle(cb($adminId, ['n' => 'admin.settings']));
check('تنظیمات برای سوپرادمین باز شد', str_contains($bot->allText(), 'تنظیمات ربات'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ SQL injection\n";
// ------------------------------------------------------------------

$payloads = [
    "'; DROP TABLE users; --",
    "' OR '1'='1",
    "admin'--",
    "1; DELETE FROM orders WHERE 1=1; --",
    "' UNION SELECT * FROM settings --",
    "\\'; DROP TABLE orders; --",
];

foreach ($payloads as $i => $payload) {
    $before = [
        'users'  => $db->count('SELECT COUNT(*) FROM users'),
        'orders' => $db->count('SELECT COUNT(*) FROM orders'),
    ];

    // در جستجوی کاربران
    $users->listAll(10, 0, $payload);

    // در جستجوی سفارش‌ها
    $orders->listAll(10, 0, $payload);

    // در نام بسته (بسته‌ها با هر عنوانی ساخته می‌شوند و slug خودکار می‌گیرند)
    $packages->create(['title' => $payload, 'price_toman' => 1000, 'volume_gb' => 1]);

    $after = [
        'users'  => $db->count('SELECT COUNT(*) FROM users'),
        'orders' => $db->count('SELECT COUNT(*) FROM orders'),
    ];

    check("تزریق «" . \Pasargad\Support\Str::truncate($payload, 24) . "» بی‌اثر بود",
        $after['users'] >= $before['users'] && $after['orders'] === $before['orders'],
        "users {$before['users']}→{$after['users']}, orders {$before['orders']}→{$after['orders']}");
}

check('جدول‌های اصلی سالم هستند', $db->tableExists('users') && $db->tableExists('orders'));

// ------------------------------------------------------------------
echo "\n▶ XSS و HTML injection در متن‌ها\n";
// ------------------------------------------------------------------

$xssUser = $users->upsertByTelegram(2222, [
    'telegram_id'    => 2222,
    'first_name'     => '<script>alert(1)</script>',
    'panel_username' => 'xss_test',
    'panel_password' => Crypto::encrypt('pass'),
    'panel_status'   => 'active',
    'note'           => '<b>تزریق</b>',
])['id'];

$bot->reset();
$kernel->handle(cb(2222, ['n' => 'user.account']));
$accountText = $bot->allText();
check('نام کاربر escape شد', !str_contains($accountText, '<script>'), $accountText);
check('تگ اسکریپت خنثی شد', str_contains($accountText, '&lt;script&gt;') || !str_contains($accountText, 'script>'));

// نام بسته با HTML
$packages->update($pkgId, ['title' => '<b>بسته</b> & "خطر"']);
$bot->reset();
$kernel->handle(cb(2222, ['n' => 'pkg', 'id' => $pkgId]));
$pkgText = $bot->allText();
check('عنوان بسته escape شد', !str_contains($pkgText, '<b>بسته</b>'), $pkgText);

// ------------------------------------------------------------------
echo "\n▶ اعتبارسنجی ورودی‌ها\n";
// ------------------------------------------------------------------

$invalidUsernames = ['', 'a', 'ab', str_repeat('x', 100), 'name with space', 'name!@#', '../../etc/passwd', 'نام فارسی'];
foreach ($invalidUsernames as $name) {
    check("نام کاربری «" . \Pasargad\Support\Str::truncate($name, 15) . "» رد شد",
        !\Pasargad\Support\Str::isValidPanelUsername($name));
}

check('نام کاربری معتبر پذیرفته شد', \Pasargad\Support\Str::isValidPanelUsername('admin_123'));
check('نام کاربری با نقطه پذیرفته شد', \Pasargad\Support\Str::isValidPanelUsername('admin.name'));

// ورود نام کاربری نامعتبر در جریان ورود
$bot->reset();
$kernel->handle(msg(3333, 'a b c!'));
$kernel->handle(cb(3333, ['n' => 'user.login']));
$kernel->handle(msg(3333, 'bad name!'));
check('ورودی نامعتبر در جریان ورود رد شد', str_contains($bot->allText(), 'نامعتبر'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ محدودیت نرخ و عدم تکرار اجرا\n";
// ------------------------------------------------------------------

$doubleOrder = $orders->create($userRow, [
    'package_id' => $pkgId, 'package_title' => 'بستهٔ تست',
    'kind' => PackageRepository::KIND_PANEL_QUOTA, 'volume_gb' => 50,
    'duration_days' => 30, 'price_toman' => 300000,
    'status' => OrderRepository::STATUS_PAID,
    'paid_at' => time(),
]);

$limitBefore = (int) $panel->admins['normaluser']['data_limit'];
$provisioner->provision($orders->find((int) $doubleOrder['id']));
$afterFirst = (int) $panel->admins['normaluser']['data_limit'];
$provisioner->provision($orders->find((int) $doubleOrder['id']));
$afterSecond = (int) $panel->admins['normaluser']['data_limit'];

check('اولین اجرا حجم اضافه کرد', $afterFirst === $limitBefore + 1073741824 * 50, "before={$limitBefore} after={$afterFirst}");
check('اجرای دوباره حجم اضافه نکرد', $afterSecond === $afterFirst, "first={$afterFirst} second={$afterSecond}");

// ------------------------------------------------------------------
echo "\n▶ کنترل دسترسی سفارش در پنل مدیریت\n";
// ------------------------------------------------------------------

// سوپرادمین می‌تواند سفارش کاربر دیگر را ببیند
$bot->reset();
$kernel->handle(cb($adminId, ['n' => 'admin.order.view', 'id' => (int) $doubleOrder['id']]));
check('سوپرادمین سفارش هر کاربری را می‌بیند', str_contains($bot->allText(), 'جزئیات سفارش'), $bot->allText());

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);