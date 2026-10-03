<?php

declare(strict_types=1);

/**
 * تست قطع دسترسی کاربران پس از اتمام اعتبار پنل نمایندگی.
 *
 * سناریو: اعتبار پنل نماینده تمام می‌شود؛ کاربرانی که نماینده خودش داخل
 * پنل ساخته باید غیرفعال شوند، وگرنه سرویس رایگان در حال ارائه است.
 *
 * بررسی می‌شود که عملیات:
 *   • روی **همهٔ** کاربران فعال انجام شود
 *   • کاربران از قبل غیرفعال را دست نزند (بی‌جهت PUT نزند)
 *   • با پنلی که ورودش ممکن نیست، صریحاً «کار دستی» اعلام کند نه وانمود موفق
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\AccessCutoff;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$users  = new UserRepository($db);
$panels = new PanelRepository($db);
$panelClient = new FakePanelClient();

$cutoff = new AccessCutoff($panels, $panelClient);

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

// ------------------------------------------------------------------
echo "\n▶ ساخت پنل با چند کاربر\n";
// ------------------------------------------------------------------

$user = $users->upsertByTelegram(4001, ['telegram_id' => 4001]);
$uid  = (int) $user['id'];

$panelId = $panels->create($uid, 'rep4001', 'pass', [
    'panel_status'     => PanelRepository::STATUS_ACTIVE,
    'data_limit'       => 100 * 1073741824,
    'access_expire_at' => time() - 3600,   // منقضی‌شده
]);

$panel = $panels->find($panelId);

$panelClient->addAdmin('rep4001', ['data_limit' => 100 * 1073741824]);

// ۳ کاربر فعال، ۲ کاربر غیرفعال/منقضی
$panelClient->existingUsers = [
    'active1'  => ['username' => 'active1',  'status' => 'active',   'used_traffic' => 5],
    'active2'  => ['username' => 'active2',  'status' => 'active',   'used_traffic' => 0],
    'active3'  => ['username' => 'active3',  'status' => 'limited',  'used_traffic' => 99],
    'dead1'    => ['username' => 'dead1',    'status' => 'disabled', 'used_traffic' => 12],
    'dead2'    => ['username' => 'dead2',    'status' => 'expired',  'used_traffic' => 3],
];

// ------------------------------------------------------------------
echo "\n▶ پیش‌نمایش\n";
// ------------------------------------------------------------------

$preview = $cutoff->listPanelUsers($panel, 20);
check('لیست کاربران خوانده شد', $preview['ok'] === true, (string) $preview['message']);
check('۵ کاربر برگشت', count($preview['users']) === 5, 'n=' . count($preview['users']));

$activeCount = count(array_filter(
    $preview['users'],
    static fn (array $u): bool => !in_array((string) $u['status'], ['disabled', 'expired', 'on_hold'], true)
));
check('۳ کاربر فعال شناسایی شد', $activeCount === 3, 'n=' . $activeCount);

// ------------------------------------------------------------------
echo "\n▶ اجرای قطع دسترسی\n";
// ------------------------------------------------------------------

$before = count($panelClient->modified);
$result = $cutoff->cutoff($panel);

check('عملیات موفق بود', $result['ok'] === true, (string) $result['message']);
check('۳ کاربر غیرفعال شد', (int) $result['details']['disabled'] === 3,
    'disabled=' . $result['details']['disabled']);
check('۲ کاربر از قبل غیرفعال رد شد', (int) $result['details']['skipped'] === 2,
    'skipped=' . $result['details']['skipped']);
check('هیچ خطایی نبود', $result['details']['failed'] === []);

foreach (['active1', 'active2', 'active3'] as $name) {
    check("کاربر {$name} غیرفعال شد",
        ($panelClient->existingUsers[$name]['status'] ?? '') === 'disabled',
        'status=' . ($panelClient->existingUsers[$name]['status'] ?? 'NULL'));
}

foreach (['dead1', 'dead2'] as $name) {
    check("کاربر {$name} دست‌نخورده ماند",
        ($panelClient->existingUsers[$name]['status'] ?? '') !== 'active');
}

// ------------------------------------------------------------------
echo "\n▶ ثبت وضعیت قطع در دیتابیس\n";
// ------------------------------------------------------------------

$after = $panels->find($panelId);
check('زمان قطع ثبت شد', $after['cutoff_done_at'] !== null);
check('تعداد کاربران قطع‌شده ثبت شد', (int) $after['cutoff_count'] === 3,
    'count=' . $after['cutoff_count']);

// اجرای دوباره نباید دوباره PUT بفرستد
$requestsBefore = count($panelClient->modified);
$again = $cutoff->cutoff($panels->find($panelId));
check('اجرای دوباره چیزی را خراب نکرد', $again['ok'] === true);
check('همه از قبل غیرفعال‌اند', (int) $again['details']['disabled'] === 0,
    'disabled=' . $again['details']['disabled']);

// ------------------------------------------------------------------
echo "\n▶ پنل بدون کاربر\n";
// ------------------------------------------------------------------

$emptyId = $panels->create($uid, 'rep4002', 'pass', [
    'panel_status' => PanelRepository::STATUS_ACTIVE,
    'data_limit'   => 1073741824,
]);
$emptyPanel = $panels->find($emptyId);
$panelClient->existingUsers = [];

$r = $cutoff->cutoff($emptyPanel);
check('پنل خالی موفق گزارش می‌شود', $r['ok'] === true);
check('پیام «کاربر فعالی ندارد»', str_contains((string) $r['message'], 'کاربر فعالی ندارد'),
    (string) $r['message']);

// ------------------------------------------------------------------
echo "\n▶ پنلی که ورودش ممکن نیست → کار دستی\n";
// ------------------------------------------------------------------

$brokenId = $panels->create($uid, 'rep4003', 'pass', [
    'panel_status' => PanelRepository::STATUS_REVOKED,
    'data_limit'   => 1073741824,
]);

$panelClient->loginError = 'invalid credentials';

$broken = $cutoff->cutoff($panels->find($brokenId));
check('عملیات ناموفق گزارش شد', $broken['ok'] === false);
check('نیاز به اقدام دستی علامت خورد', ($broken['details']['needs_manual'] ?? false) === true);
check('پیام به ادمین می‌گوید دستی عمل کند', str_contains((string) $broken['message'], 'دستی'),
    (string) $broken['message']);
check('درخواست قطع ثبت شد', $panels->find($brokenId)['cutoff_requested_at'] !== null);

$previewBroken = $cutoff->listPanelUsers($panels->find($brokenId));
check('پیش‌نمایش هم صریح است', $previewBroken['ok'] === false);
check('پیام پیش‌نمایش راهنمایی دارد', str_contains((string) $previewBroken['message'], 'دستی'));

$panelClient->loginError = '';

// ------------------------------------------------------------------
echo "\n▶ پنل بدون رمز در ربات\n";
// ------------------------------------------------------------------

$noPassId = $panels->create($uid, 'rep4004', '', [
    'panel_status' => PanelRepository::STATUS_ACTIVE,
    'data_limit'   => 1073741824,
]);

$noPass = $cutoff->cutoff($panels->find($noPassId));
check('بدون رمز، عملیات انجام نمی‌شود', $noPass['ok'] === false);
check('دلیل گفته می‌شود', str_contains((string) $noPass['message'], 'ورود'),
    (string) $noPass['message']);

// ------------------------------------------------------------------
echo "\n▶ سقف اجرا در هر بار\n";
// ------------------------------------------------------------------

// MAX_PER_RUN عمداً محدود است تا یک اجرای طولانی، تایم‌اوت تلگرام/کرون ندهد
check('سقف اجرا منطقی است', AccessCutoff::MAX_PER_RUN <= 500,
    'max=' . AccessCutoff::MAX_PER_RUN);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);