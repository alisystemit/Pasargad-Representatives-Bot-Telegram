<?php

declare(strict_types=1);

/**
 * تست جریان کامل خرید بدون تلگرام و شبکه.
 *
 * با یک FakeBotAPI، آپدیت‌های ساختگی تلگرام را از Kernel عبور می‌دهیم و
 * بررسی می‌کنیم که پیام‌ها و کیبوردها درست ساخته شده و بسته روی پنل اعمال شده است.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Bot\Kernel;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$panel    = new FakePanelClient();
$bot      = new FakeBotApi();
$settings = new Settings($db);
$packages = new PackageRepository($db);
$orders   = new OrderRepository($db);
$users    = new UserRepository($db);

$provisioner = new Provisioner($panel, $orders, $users, $settings);
$sessions = new \Pasargad\Bot\SessionStore($db);
$kernel = new Kernel(
    $bot,
    new \Pasargad\Bot\Notifier($bot),
    $users,
    $packages,
    $orders,
    $provisioner,
    new \Pasargad\Payment\PaymentService($orders, $provisioner, $settings),
    $settings,
    $sessions,
    $panel   // پنل قلابی تا تست شبکه نزند
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

// ------------------------------------------------------------------
echo "\n▶ آماده‌سازی\n";
// ------------------------------------------------------------------

// کارت‌به‌کارت را فعال می‌کنیم تا مسیر دستی تست شود
\Pasargad\Support\Config::set('store.card_number', '6037997512345678');
\Pasargad\Support\Config::set('store.card_owner', 'علی رضایی');

$settings->set('auto_apply', '1');
$settings->set('shop_opened', '1');

$pkgId = $packages->create([
    'title'         => 'بستهٔ تست ۱۰۰ گیگ',
    'kind'          => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb'     => 100,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'sort_order'    => 1,
    'is_active'     => true,
]);

// مصرف ۲۰ گیگابایت ثبت شده تا نوار پیشرفت در حساب کاربر دیده شود
$panel->addAdmin('rep9', ['data_limit' => 0, 'used_traffic' => 1073741824 * 20]);

check('بسته ساخته شد', $pkgId > 0);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱: کاربر جدید بدون اتصال\n";
// ------------------------------------------------------------------

$bot->reset();

$kernel->handle(new Update([
    'update_id' => 1,
    'message'   => ['message_id' => 1, 'chat' => ['id' => 5000], 'from' => ['id' => 5000, 'first_name' => 'رضا'], 'text' => '/start'],
]));

check('کاربر در دیتابیس ثبت شد', $users->findByTelegramId(5000) !== null);
check('پیام خوش‌آمد ارسال شد', str_contains($bot->allText(), 'خوش آمدید'), $bot->allText());
check('منوی اتصال پنل نمایش داده شد', $bot->hasButton('اتصال به پنل'));

// کلیک روی «اتصال به پنل»
$bot->reset();
$kernel->handle(new Update([
    'update_id' => 2,
    'callback_query' => ['id' => 'cb1', 'chat_instance' => 'x', 'data' => '{"n":"user.login"}',
        'from' => ['id' => 5000], 'message' => ['message_id' => 1, 'chat' => ['id' => 5000]]],
]));
check('مرحلهٔ ورود شروع شد', str_contains($bot->allText(), 'نام کاربری ادمین پنل'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۲: ورود با نام کاربری و رمز\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 3,
    'message'   => ['message_id' => 2, 'chat' => ['id' => 5000], 'from' => ['id' => 5000], 'text' => 'rep9'],
]));
check('درخواست رمز عبور داده شد', str_contains($bot->lastText(), 'رمز عبور'), $bot->lastText());

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 4,
    'message'   => ['message_id' => 3, 'chat' => ['id' => 5000], 'from' => ['id' => 5000], 'text' => 'correct-pass'],
]));
check('ورود موفق گزارش شد', str_contains($bot->allText(), 'ورود موفق بود'), $bot->allText());
$linkedUser = $users->findByTelegramId(5000);
check('پنل به کاربر متصل شد', ($linkedUser['panel_username'] ?? null) === 'rep9');
check('رمز عبور رمزنگاری شد', (string) $linkedUser['panel_password'] !== 'correct-pass');
check('منوی خرید نمایش داده شد', $bot->hasButton('خرید بسته'));

// ورود با رمز اشتباه
$bot->reset();
$kernel->handle(new Update(['update_id' => 5, 'message' => ['message_id' => 4, 'chat' => ['id' => 5000], 'from' => ['id' => 5000], 'text' => '/login']]));
$kernel->handle(new Update(['update_id' => 6, 'message' => ['message_id' => 5, 'chat' => ['id' => 5000], 'from' => ['id' => 5000], 'text' => 'rep9']]));
$panel->loginError = 'نام کاربری نامعتبر';
$kernel->handle(new Update(['update_id' => 7, 'message' => ['message_id' => 6, 'chat' => ['id' => 5000], 'from' => ['id' => 5000], 'text' => 'wrong-pass']]));
$panel->loginError = '';
check('خطای ورود نمایش داده شد', str_contains($bot->lastText(), 'ورود ناموفق بود'), $bot->lastText());
check('دکمهٔ تلاش مجدد موجود است', $bot->hasButton('تلاش مجدد'));

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۳: مشاهدهٔ فروشگاه و انتخاب بسته\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 8,
    'callback_query' => ['id' => 'cb2', 'data' => json_encode(['n' => 'shop', 'kind' => 'panel_quota']),
        'from' => ['id' => 5000], 'message' => ['message_id' => 10, 'chat' => ['id' => 5000]]],
]));
check('فهرست بسته‌ها نمایش داده شد', str_contains($bot->lastText(), 'بسته‌های حجم پنل'), $bot->lastText());
check('دکمهٔ بسته موجود است', $bot->hasButton('بستهٔ تست'));

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 9,
    'callback_query' => ['id' => 'cb3', 'data' => json_encode(['n' => 'pkg', 'id' => $pkgId]),
        'from' => ['id' => 5000], 'message' => ['message_id' => 11, 'chat' => ['id' => 5000]]],
]));
check('جزئیات بسته نمایش داده شد', str_contains($bot->lastText(), 'تأیید خرید'), $bot->lastText());
check('قیمت درست نمایش داده شد', str_contains($bot->allText(), '۵۰۰,۰۰۰'), $bot->allText());
check('دکمهٔ خرید موجود است', $bot->hasButton('خرید این بسته'));

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۴: ثبت سفارش\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 10,
    'callback_query' => ['id' => 'cb4', 'data' => json_encode(['n' => 'pkg.buy', 'id' => $pkgId]),
        'from' => ['id' => 5000], 'message' => ['message_id' => 12, 'chat' => ['id' => 5000]]],
]));

check('سفارش ساخته شد', $orders->countByUser((int) $linkedUser['id']) === 1);
$order = $orders->listByUser((int) $linkedUser['id'], 1)[0];
check('کد سفارش تولید شد', str_starts_with((string) $order['code'], 'ORD-'));
check('منوی روش پرداخت نمایش داده شد', str_contains($bot->lastText(), 'انتخاب روش پرداخت'), $bot->lastText());
check('گزینهٔ کارت‌به‌کارت موجود است', $bot->hasButton('کارت‌به‌کارت'));

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۵: پرداخت کارت‌به‌کارت\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 11,
    'callback_query' => ['id' => 'cb5', 'data' => json_encode(['n' => 'pay', 'id' => (int) $order['id'], 'm' => 'card2card']),
        'from' => ['id' => 5000], 'message' => ['message_id' => 13, 'chat' => ['id' => 5000]]],
]));

check('راهنمای کارت‌به‌کارت نمایش داده شد', str_contains($bot->lastText(), 'شماره کارت'), $bot->lastText());
check('شمارهٔ کارت در پیام هست', str_contains($bot->lastText(), '6037997512345678'), $bot->lastText());
check('کد سفارش در پیام هست', str_contains($bot->lastText(), (string) $order['code']));
check('دکمهٔ بررسی وضعیت موجود است', $bot->hasButton('بررسی وضعیت'));

$order = $orders->find((int) $order['id']);
check('سفارش awaiting_payment شد', (string) $order['status'] === OrderRepository::STATUS_AWAITING_PAYMENT);
check('روش پرداخت ثبت شد', (string) $order['payment_method'] === 'card2card');

// کاربر رسید را ارسال می‌کند
$bot->reset();
$kernel->handle(new Update([
    'update_id' => 12,
    'message' => [
        'message_id' => 14, 'chat' => ['id' => 5000], 'from' => ['id' => 5000],
        'photo' => [['file_id' => 'PHOTO_FILE_ID', 'file_unique_id' => 'u1', 'file_size' => 100000]],
    ],
]));

check('پیام تأیید رسید به کاربر داده شد', str_contains($bot->lastText(), 'رسید شما ثبت شد'), $bot->lastText());
$order = $orders->find((int) $order['id']);
check('رسید ذخیره شد', $order['receipt_file_id'] === 'PHOTO_FILE_ID');
check('رسید برای ادمین ارسال شد', $bot->adminGotPhoto(), 'photos=' . count($bot->sentPhotos));
check('پیام تأیید به ادمین رفت', str_contains($bot->allTextWithCaptions(), 'رسید جدید'), $bot->allTextWithCaptions());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۶: تأیید سوپرادمین و اجرای خودکار\n";
// ------------------------------------------------------------------

// کانال سوپرادمین را فعال می‌کنیم
\Pasargad\Support\Config::set('super_admins', [777]);

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 13,
    'callback_query' => ['id' => 'cb6', 'data' => json_encode(['n' => 'admin.review', 'id' => (int) $order['id'], 'act' => 'approve']),
        'from' => ['id' => 777], 'message' => ['message_id' => 20, 'chat' => ['id' => 777]]],
]));

$order = $orders->find((int) $order['id']);
check('سفارش applied شد', (string) $order['status'] === OrderRepository::STATUS_APPLIED, 'status=' . $order['status']);
check('حجم روی پنل اعمال شد', (int) $panel->admins['rep9']['data_limit'] === 1073741824 * 120, 'limit=' . $panel->admins['rep9']['data_limit']);
check('حجم اجراشده ثبت شد', (int) $order['applied_volume'] === 1073741824 * 100);
check('تأیید به ادمین گزارش شد', str_contains($bot->lastText(), 'بسته با موفقیت اعمال شد'), $bot->lastText());
check('کاربر از اجرای موفق مطلع شد', str_contains($bot->allText(), 'موفقیت اجرا شد'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۷: مشاهدهٔ حساب و سفارش‌ها\n";
// ------------------------------------------------------------------

$user = $users->findByTelegramId(5000);
check('حجم کاربر در ربات به‌روز شد', (int) $user['granted_volume'] === 1073741824 * 100);
check('شمارش سفارش کاربر', (int) $user['orders_count'] === 1);
check('مجموع خرید کاربر', (int) $user['total_paid'] === 500000);

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 14,
    'callback_query' => ['id' => 'cb7', 'data' => json_encode(['n' => 'user.account']),
        'from' => ['id' => 5000], 'message' => ['message_id' => 30, 'chat' => ['id' => 5000]]],
]));
check('صفحهٔ حساب نمایش داده شد', str_contains($bot->lastText(), 'حساب من'), $bot->lastText());
check('حجم جدید در حساب دیده می‌شود', str_contains($bot->allText(), '۱۲۰ گیگابایت'), $bot->lastText());
check('نوار پیشرفت نمایش داده شد', str_contains($bot->allText(), '▰'), $bot->lastText());

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 15,
    'callback_query' => ['id' => 'cb8', 'data' => json_encode(['n' => 'order.view', 'id' => (int) $order['id']]),
        'from' => ['id' => 5000], 'message' => ['message_id' => 31, 'chat' => ['id' => 5000]]],
]));
check('جزئیات سفارش نمایش داده شد', str_contains($bot->lastText(), 'جزئیات سفارش'), $bot->lastText());
check('وضعیت اجراشده در جزئیات هست', str_contains($bot->lastText(), 'اجرا شد'), $bot->lastText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۸: دسترسی کاربر دیگر به سفارش\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 16,
    'callback_query' => ['id' => 'cb9', 'data' => json_encode(['n' => 'order.view', 'id' => (int) $order['id']]),
        'from' => ['id' => 9999], 'message' => ['message_id' => 32, 'chat' => ['id' => 9999]]],
]));
check('کاربر دیگر سفارش را نمی‌بیند', str_contains($bot->lastText(), 'پیدا نشد'), $bot->lastText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۹: مسدودسازی کاربر\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 17,
    'callback_query' => ['id' => 'cb10', 'data' => json_encode(['n' => 'admin.user.block', 'id' => (int) $user['id']]),
        'from' => ['id' => 777], 'message' => ['message_id' => 40, 'chat' => ['id' => 777]]],
]));
check('کاربر مسدود شد', (int) $users->findByTelegramId(5000)['is_blocked'] === 1);

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 18,
    'message'   => ['message_id' => 41, 'chat' => ['id' => 5000], 'from' => ['id' => 5000], 'text' => '/shop'],
]));
check('کاربر مسدود پیام مسدودیت گرفت', str_contains($bot->lastText(), 'مسدود است'), $bot->lastText());

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 19,
    'callback_query' => ['id' => 'cb11', 'data' => json_encode(['n' => 'admin.user.block', 'id' => (int) $user['id']]),
        'from' => ['id' => 777], 'message' => ['message_id' => 42, 'chat' => ['id' => 777]]],
]));
check('رفع مسدودی کار کرد', (int) $users->findByTelegramId(5000)['is_blocked'] === 0);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱۰: ساخت بسته با پیام متنی\n";
// ------------------------------------------------------------------

$bot->reset();
$kernel->handle(new Update([
    'update_id' => 20,
    'message'   => ['message_id' => 50, 'chat' => ['id' => 777], 'from' => ['id' => 777], 'text' => 'بسته ویژه تابستان | panel_quota | 300 | 45 | 1500000'],
]));
check('بسته از طریق پیام ساخته شد', str_contains($bot->allText(), 'ساخته شد'), $bot->allText());
$all = $packages->allPackages(false);
check('تعداد بسته‌ها ۲ شد', count($all) === 2, 'count=' . count($all));
$newPkg = null;
foreach ($all as $p) {
    if (str_contains((string) $p['title'], 'تابستان')) { $newPkg = $p; }
}
check('بستهٔ جدید با مقادیر درست', $newPkg !== null && (float) $newPkg['volume_gb'] === 300.0 && (int) $newPkg['price_toman'] === 1500000);
check('نوع بسته درست تشخیص داده شد', ($newPkg['kind'] ?? '') === PackageRepository::KIND_PANEL_QUOTA);

// ورودی نامعتبر
$bot->reset();
$kernel->handle(new Update([
    'update_id' => 21,
    'message'   => ['message_id' => 51, 'chat' => ['id' => 777], 'from' => ['id' => 777], 'text' => 'بسته بد | نوع_ناموجود | 100 | 30 | 500000'],
]));
check('ورودی نامعتبر رد شد', str_contains($bot->lastText(), 'فرمت ورودی نامعتبر'), $bot->lastText());
check('بستهٔ نامعتبر ساخته نشد', count($packages->allPackages(false)) === 2);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱۱: صف خودکار وقتی اجرای خودکار خاموش است\n";
// ------------------------------------------------------------------

$settings->set('auto_apply', '0');
$order2 = $orders->create((int) $user['id'], [
    'package_id'    => $pkgId,
    'package_title' => 'بستهٔ تست ۱۰۰ گیگ',
    'kind'          => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb'     => 100,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$payments = new \Pasargad\Payment\PaymentService($orders, $provisioner, $settings);
$result = $payments->applyAfterPayment((int) $order2['id']);
check('با اجرای خودکار خاموش، بسته اجرا نشد', str_contains($result['message'], 'غیرفعال'), $result['message']);
check('سفارش در حالت paid باقی ماند', (string) $orders->find((int) $order2['id'])['status'] === OrderRepository::STATUS_PAID);
$settings->set('auto_apply', '1');

// با فعال شدن دوباره، کرون بسته را اعمال می‌کند
$queue = $provisioner->processQueue(5);
$after = $orders->find((int) $order2['id']);
check('کرون بستهٔ معلق را اجرا کرد', (string) $after['status'] === OrderRepository::STATUS_APPLIED, 'status=' . $after['status']);
check('حجم روی پنل ۲۲۰ گیگ شد', (int) $panel->admins['rep9']['data_limit'] === 1073741824 * 220, 'limit=' . $panel->admins['rep9']['data_limit']);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);
