<?php

declare(strict_types=1);

/**
 * تست جریان‌های چندمرحله‌ای (state machine).
 *
 * باگ اصلی: `handleSessionState` برای هر نشستِ غیرتهی صدا زده می‌شد و در
 * شاخهٔ `default` نشست را پاک می‌کرد. نتیجه:
 *   • جریان «✏️ ویرایش بسته» می‌مرد → بستهٔ تکراری با قیمت جدید ساخته می‌شد.
 *   • جریان «ساخت کاربر با اعتبار» با اولین خطای اعتبارسنجی می‌شکست و
 *     نام کاربری که کاربر فرستاده بود پاک می‌شد.
 *
 * این تست‌ها هر دو مسیر را قفل می‌کنند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\SessionStore;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserProvisioner;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Crypto;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$panel    = new \Pasargad\Panel\FakePanelClient();
$bot      = new FakeBotApi();
$settings = new Settings($db);
$packages = new PackageRepository($db);
$orders   = new OrderRepository($db);
$users    = new UserRepository($db);

$provisioner = new Provisioner($panel, $orders, $users, $settings);
$sessions    = new SessionStore($db);

$kernel = new Kernel(
    $bot,
    new \Pasargad\Bot\Notifier($bot),
    $users,
    $packages,
    $orders,
    $provisioner,
    new PaymentService($orders, $provisioner, $settings),
    $settings,
    $sessions,
    $panel
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

/**
 * ساخت یک update پیام متنی.
 *
 * @return array<string, mixed>
 */
function textUpdate(int $telegramId, string $text, int $messageId = 1): array
{
    return [
        'update_id' => 1000 + $messageId,
        'message'   => [
            'message_id' => $messageId,
            'from'       => ['id' => $telegramId, 'first_name' => 'تست'],
            'chat'       => ['id' => $telegramId, 'type' => 'private'],
            'text'       => $text,
        ],
    ];
}

/**
 * ساخت یک update کلیک روی دکمه.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function callbackUpdate(int $telegramId, string $payload, int $messageId = 1): array
{
    return [
        'update_id'      => 2000 + $messageId,
        'callback_query' => [
            'id'      => 'cb' . $messageId,
            'from'    => ['id' => $telegramId, 'first_name' => 'تست'],
            'message' => [
                'message_id' => $messageId,
                'chat'       => ['id' => $telegramId, 'type' => 'private'],
            ],
            'data'    => $payload,
        ],
    ];
}

// ------------------------------------------------------------------
echo "\n▶ آماده‌سازی\n";
// ------------------------------------------------------------------

$settings->set('shop_opened', '1');
$settings->set('auto_apply', '1');

$superAdmin = 900001;
$settings->set('super_admins', (string) $superAdmin);
\Pasargad\Support\Config::set('super_admins', [$superAdmin]);

$pkgId = $packages->create([
    'title' => 'بستهٔ اصلی', 'kind' => PackageRepository::KIND_PANEL_QUOTA,
    'volume_gb' => 100, 'duration_days' => 30, 'price_toman' => 500000, 'is_active' => true,
]);

// =====================================================================
echo "\n▶ سناریو ۱: ویرایش بسته باید ویرایش کند، نه بستهٔ تکراری بسازد\n";
// =====================================================================

// ادمین روی «✏️ ویرایش» کلیک می‌کند
$kernel->handle(new Update(callbackUpdate($superAdmin,
    json_encode(['n' => 'admin.pkg.edit', 'id' => $pkgId], JSON_UNESCAPED_UNICODE), 10)));

$state = $sessions->get($superAdmin);
check('نشست ویرایش ساخته شد', ($state['step'] ?? '') === 'pkg:edit', 'step=' . ($state['step'] ?? 'null'));
check('شناسهٔ بسته در نشست ذخیره شد', (int) ($state['package_id'] ?? 0) === (int) $pkgId,
    'package_id=' . ($state['package_id'] ?? 'null'));

$before = count($packages->allPackages());
$rowBefore = $packages->find((int) $pkgId);
check('قیمت قبل از ویرایش ۵۰۰۰۰۰ است', (int) $rowBefore['price_toman'] === 500000,
    'price=' . $rowBefore['price_toman']);

// حالا ادمین مشخصات جدید را می‌فرستد
$kernel->handle(new Update(textUpdate($superAdmin,
    'بستهٔ ویرایش‌شده | panel_quota | 250 | 60 | 750000', 11)));

$after = count($packages->allPackages());
check('بستهٔ جدیدی ساخته نشد', $after === $before, "before=$before after=$after");

$rowAfter = $packages->find((int) $pkgId);
check('عنوان به‌روزرسانی شد', (string) $rowAfter['title'] === 'بستهٔ ویرایش‌شده', 'title=' . $rowAfter['title']);
check('قیمت ۷۵۰۰۰۰ شد', (int) $rowAfter['price_toman'] === 750000, 'price=' . $rowAfter['price_toman']);
check('حجم ۲۵۰ شد', (float) $rowAfter['volume_gb'] === 250.0, 'vol=' . $rowAfter['volume_gb']);
check('مدت ۶۰ شد', (int) $rowAfter['duration_days'] === 60, 'days=' . $rowAfter['duration_days']);
check('بسته فعال ماند', (int) $rowAfter['is_active'] === 1);

check('نشست بعد از ویرایش پاک شد', $sessions->get($superAdmin) === null,
    'step=' . ($sessions->get($superAdmin)['step'] ?? 'null'));

// آیا بستهٔ فعال دیگری با قیمت متفاوت ساخته شد؟
$all = $packages->allPackages();
$duplicate = null;
foreach ($all as $p) {
    if ((int) $p['id'] !== (int) $pkgId && (int) $p['price_toman'] === 750000) {
        $duplicate = $p;
    }
}
check('بستهٔ تکراری با قیمت جدید ساخته نشد', $duplicate === null,
    $duplicate !== null ? ('id=' . $duplicate['id'] . ' title=' . $duplicate['title']) : '');

// =====================================================================
echo "\n▶ سناریو ۲: جریان اعتبار کاربر با خطای اعتبارسنجی نباید بشکند\n";
// =====================================================================

$buyerId = 800001;
$buyer = $users->upsertByTelegram($buyerId, [
    'telegram_id'    => $buyerId,
    'panel_username' => 'buyer_admin',
    'panel_password' => Crypto::encrypt('pass'),
    'panel_status'   => 'active',
    'user_credit'    => 100 * 1073741824,
])['id'];

$panel->addAdmin('buyer_admin', ['data_limit' => 0, 'used_traffic' => 0]);

// کاربر وارد جریان ساخت کاربر می‌شود
$kernel->handle(new Update(callbackUpdate($buyerId,
    json_encode(['n' => 'uc.new'], JSON_UNESCAPED_UNICODE), 20)));

$state = $sessions->get($buyerId);
check('مرحلهٔ نام کاربری شروع شد', ($state['step'] ?? '') === 'user_credit:username', 'step=' . ($state['step'] ?? 'null'));

// نام کاربری معتبر می‌فرستد
$kernel->handle(new Update(textUpdate($buyerId, 'customer_one', 21)));

$state = $sessions->get($buyerId);
check('به مرحلهٔ حجم رفت', ($state['step'] ?? '') === 'user_credit:volume', 'step=' . ($state['step'] ?? 'null'));
check('نام کاربری در نشست ماند', (string) ($state['username'] ?? '') === 'customer_one',
    'username=' . ($state['username'] ?? 'null'));

// ---- حجم نامعتبر (زیر حداقل) → نباید نشست را از بین ببرد ----
$kernel->handle(new Update(textUpdate($buyerId, '0.5', 22)));

check('پیام حداقل حجم داده شد', str_contains($bot->lastTextFor($buyerId), '۱ گیگابایت'), $bot->lastTextFor($buyerId));

$state = $sessions->get($buyerId);
check('نشست بعد از خطای حجم باقی ماند', $state !== null, 'null');
check('نام کاربری بعد از خطا حفظ شد', (string) ($state['username'] ?? '') === 'customer_one',
    'username=' . ($state['username'] ?? 'null'));
check('مرحله همچنان حجم است', ($state['step'] ?? '') === 'user_credit:volume', 'step=' . ($state['step'] ?? 'null'));

// ---- حجم بیش از اعتبار → نباید نشست را از بین ببرد ----
$bot->reset();
$kernel->handle(new Update(textUpdate($buyerId, '5000', 23)));

check('پیام اعتبار ناکافی داده شد', str_contains($bot->lastTextFor($buyerId), 'اعتبار کافی ندارید'),
    $bot->lastTextFor($buyerId));

$state = $sessions->get($buyerId);
check('نشست بعد از خطای اعتبار باقی ماند', $state !== null);
check('نام کاربری بعد از خطای اعتبار حفظ شد', (string) ($state['username'] ?? '') === 'customer_one',
    'username=' . ($state['username'] ?? 'null'));

// ---- حالا حجم درست → باید جلو برود ----
$bot->reset();
$kernel->handle(new Update(textUpdate($buyerId, '10', 24)));

$state = $sessions->get($buyerId);
check('به مرحلهٔ مدت رفت', ($state['step'] ?? '') === 'user_credit:duration', 'step=' . ($state['step'] ?? 'null'));
check('حجم در نشست ذخیره شد', (float) ($state['volume'] ?? 0) === 10.0, 'volume=' . ($state['volume'] ?? 'null'));
check('نام کاربری حفظ شد', (string) ($state['username'] ?? '') === 'customer_one');

// ---- مدت زمان نامعتبر → نشست بماند ----
$kernel->handle(new Update(textUpdate($buyerId, '0', 25)));
$state = $sessions->get($buyerId);
check('نشست بعد از مدت نامعتبر باقی ماند', ($state['step'] ?? '') === 'user_credit:duration',
    'step=' . ($state['step'] ?? 'null'));

// ---- مدت درست → کاربر ساخته شود ----
$bot->reset();
$kernel->handle(new Update(textUpdate($buyerId, '30', 26)));

check('کاربر روی پنل ساخته شد', isset($panel->existingUsers['customer_one']),
    'existing=' . implode(',', array_keys($panel->existingUsers)));
check('سقف ۱۰ گیگ اعمال شد', (int) ($panel->existingUsers['customer_one']['data_limit'] ?? 0) === 10 * 1073741824,
    'limit=' . ($panel->existingUsers['customer_one']['data_limit'] ?? 0));

$creditLeft = (int) $users->findById($buyer)['user_credit'];
check('اعتبار کسر شد', $creditLeft === 90 * 1073741824,
    'credit=' . ($creditLeft / 1073741824) . 'GB');
check('نشست بعد از اتمام پاک شد', $sessions->get($buyerId) === null);

// =====================================================================
echo "\n▶ سناریو ۳: لغو جریان با دکمهٔ انصراف\n";
// =====================================================================

$kernel->handle(new Update(callbackUpdate($buyerId,
    json_encode(['n' => 'uc.new'], JSON_UNESCAPED_UNICODE), 30)));
check('جریان تازه شروع شد', ($sessions->get($buyerId)['step'] ?? '') === 'user_credit:username');

$kernel->handle(new Update(textUpdate($buyerId, '', 31)));   // پیام خالی = انصراف
check('با پیام خالی لغو شد', $sessions->get($buyerId) === null,
    'step=' . ($sessions->get($buyerId)['step'] ?? 'null'));

// =====================================================================
echo "\n▶ سناریو ۴: نشست یک کاربر به کاربر دیگر نشت نمی‌کند\n";
// =====================================================================

$otherId = 800002;
$users->upsertByTelegram($otherId, [
    'telegram_id'    => $otherId,
    'panel_username' => 'other_admin',
    'panel_password' => Crypto::encrypt('pass'),
    'panel_status'   => 'active',
    'user_credit'    => 50 * 1073741824,
]);
$panel->addAdmin('other_admin', ['data_limit' => 0, 'used_traffic' => 0]);

$kernel->handle(new Update(callbackUpdate($buyerId,
    json_encode(['n' => 'uc.new'], JSON_UNESCAPED_UNICODE), 40)));
$kernel->handle(new Update(textUpdate($buyerId, 'leaked_user', 41)));

check('نشست خریدار مراحل را طی کرد', ($sessions->get($buyerId)['step'] ?? '') === 'user_credit:volume',
    'step=' . ($sessions->get($buyerId)['step'] ?? 'null'));

$otherState = $sessions->get($otherId);
check('کاربر دوم نشستی ندارد', $otherState === null, json_encode($otherState));

// کاربر دوم نباید بتواند از مرحلهٔ کاربر اول رد شود
$kernel->handle(new Update(textUpdate($otherId, '10', 42)));
check('کاربر دوم نشستی نساخت', $sessions->get($otherId) === null,
    'step=' . ($sessions->get($otherId)['step'] ?? 'null'));

// =====================================================================
echo "\n▶ سناریو ۵: نشست ناشناختهٔ pkg:edit پاک نمی‌شود بی‌دلیل\n";
// =====================================================================

$sessions->set($superAdmin, ['step' => 'pkg:edit', 'package_id' => $pkgId]);

// پیام عادی (بدون |) نباید نشست را پاک کند
$kernel->handle(new Update(textUpdate($superAdmin, 'سلام', 50)));
$still = $sessions->get($superAdmin);
check('نشست pkg:edit با پیام عادی از بین نرفت', ($still['step'] ?? '') === 'pkg:edit',
    'step=' . ($still['step'] ?? 'null'));
check('شناسهٔ بسته حفظ شد', (int) ($still['package_id'] ?? 0) === (int) $pkgId);

$sessions->clear($superAdmin);

// =====================================================================
echo "\n▶ سناریو ۶: بستن فروشگاه با دکمهٔ قدیمی دور زده نمی‌شود\n";
// =====================================================================

$shopId = 800003;
$shopUser = $users->upsertByTelegram($shopId, [
    'telegram_id' => $shopId, 'panel_username' => 'shop_admin',
    'panel_password' => Crypto::encrypt('pass'), 'panel_status' => 'active',
]);
$panel->addAdmin('shop_admin', ['data_limit' => 0, 'used_traffic' => 0]);

// صفحهٔ بسته باز می‌شود (فروشگاه باز است)
$bot->reset();
$kernel->handle(new Update(callbackUpdate($shopId,
    json_encode(['n' => 'pkg', 'id' => $pkgId], JSON_UNESCAPED_UNICODE), 60)));
check('صفحهٔ بسته باز شد', $bot->hasButton('خرید این بسته'), $bot->lastTextFor($shopId));

// ادمین فروشگاه را می‌بندد
$settings->set('shop_opened', '0');

// حالا کاربر روی دکمهٔ «خرید این بسته» کلیک می‌کند (هنوز در چت هست)
$ordersBefore = (int) $db->count('SELECT COUNT(*) FROM orders');
$bot->reset();
$kernel->handle(new Update(callbackUpdate($shopId,
    json_encode(['n' => 'pkg.buy', 'id' => $pkgId], JSON_UNESCAPED_UNICODE), 61)));

check('پیام «فروشگاه بسته» داده شد', str_contains($bot->lastTextFor($shopId), 'بسته است'),
    $bot->lastTextFor($shopId));
$ordersAfter = (int) $db->count('SELECT COUNT(*) FROM orders');
check('سفارشی ساخته نشد', $ordersAfter === $ordersBefore,
    'before=' . $ordersBefore . ' after=' . $ordersAfter);

// صفحهٔ بسته هم باید بسته باشد
$bot->reset();
$kernel->handle(new Update(callbackUpdate($shopId,
    json_encode(['n' => 'pkg', 'id' => $pkgId], JSON_UNESCAPED_UNICODE), 62)));
check('صفحهٔ بسته هم بسته شد', str_contains($bot->lastTextFor($shopId), 'بسته است'));
check('دکمهٔ خرید نمایش داده نشد', !$bot->hasButton('خرید این بسته'));

$settings->set('shop_opened', '1');

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);