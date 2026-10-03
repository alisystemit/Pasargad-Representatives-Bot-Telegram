<?php

declare(strict_types=1);

/**
 * تست «دریافت تست کانفیگ».
 *
 * قابلیت: ربات روی **پنل خودِ نماینده** یک یوزر رایگان کوتاه‌عمر می‌سازد تا
 * نماینده بتواند سرویس را قبل از فروش به مشتری واقعی امتحان کند.
 *
 * مهم‌ترین بخش این تست، **محدودیت‌ها** است. بدون آن‌ها این قابلیت تبدیل به
 * «ماشین ساخت اکانت رایگان» می‌شود و به پنل نماینده آسیب می‌زند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestConfigRepository;
use Pasargad\Store\TestConfigService;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$settings = new Settings($db);
$users    = new UserRepository($db);
$panels   = new PanelRepository($db);
$panel    = new FakePanelClient();

$service = new TestConfigService($panels, new TestConfigRepository($db), $settings, $panel);

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

$GB = 1073741824;

// ------------------------------------------------------------------
echo "\n▶ تنظیمات اولیه\n";
// ------------------------------------------------------------------

check('قابلیت پیش‌فرض روشن است', $service->isEnabled());
check('حجم پیش‌فرض ۱ گیگ است', $service->defaultBytes() === 1 * $GB, 'got=' . $service->defaultBytes());
check('مدت پیش‌فرض ۱ روز است', $service->defaultDays() === 1);

// سقف حجم: تنظیم اشتباه نباید یوزر نامحدود بدهد
$settings->set(Settings::TEST_CONFIG_VOLUME_GB, '0');
check('حجم صفر به ۱ گیگ اصلاح می‌شود (نه نامحدود)', $service->defaultBytes() === 1 * $GB);

$settings->set(Settings::TEST_CONFIG_VOLUME_GB, '9999');
check('حجم غول‌آسا به سقف ۱۰ گیگ محدود می‌شود',
    $service->defaultBytes() === TestConfigService::MAX_BYTES, 'got=' . $service->defaultBytes());

$settings->set(Settings::TEST_CONFIG_VOLUME_GB, '1');

// ------------------------------------------------------------------
echo "\n▶ ساخت یک پنل فعال برای نماینده\n";
// ------------------------------------------------------------------

$user = $users->upsertByTelegram(3001, ['telegram_id' => 3001]);
$uid  = (int) $user['id'];

$panelId = $panels->create($uid, 'rep3001', 'panelpass', [
    'panel_status'     => PanelRepository::STATUS_ACTIVE,
    'data_limit'       => 100 * $GB,
    'access_expire_at' => time() + 30 * 86400,
    'login_url'        => 'https://panel.example.com',
]);

$row = $panels->find($panelId);

$guard = $service->canIssue($row);
check('پنل فعال اجازهٔ تست می‌دهد', $guard['ok'] === true, $guard['message']);

// ------------------------------------------------------------------
echo "\n▶ صدور کانفیگ تست\n";
// ------------------------------------------------------------------

$result = $service->issue($row);
check('کانفیگ تست ساخته شد', $result['ok'] === true, (string) ($result['message'] ?? ''));

$details = (array) ($result['details'] ?? []);
check('یوزر با پیشوند t_ ساخته شد', str_starts_with((string) ($details['username'] ?? ''), 't_rep3001'),
    'username=' . (string) ($details['username'] ?? ''));
check('حجم روی پنل اعمال شد', (int) ($details['data_limit'] ?? 0) === 1 * $GB);
check('انقضا یک روز بعد است', (int) ($details['expire_at'] ?? 0) > time() + 86000);
check('در جدول ثبت شد', (int) ($details['config_id'] ?? 0) > 0);
check('یوزر واقعاً روی پنل ساخته شد', isset($panel->existingUsers[(string) $details['username']]));
check('یوزر تست فعال است', ($panel->existingUsers[(string) $details['username']]['status'] ?? '') === 'active');

// ------------------------------------------------------------------
echo "\n▶ محدودیت فاصلهٔ زمانی (ضد سوءاستفاده)\n";
// ------------------------------------------------------------------

$settings->set(Settings::TEST_CONFIG_COOLDOWN, '30');

$second = $service->canIssue($panels->find($panelId));
check('درخواست دوم فوراً رد می‌شود', $second['ok'] === false, $second['message']);
check('پیام، زمان انتظار را می‌گوید', str_contains($second['message'], 'دقیقه'), $second['message']);
check('یوزر دومی ساخته نشد', count($panel->created) === 1, 'created=' . count($panel->created));

$block = $service->issue($panels->find($panelId));
check('issue دوم هم رد می‌شود', $block['ok'] === false);

// ------------------------------------------------------------------
echo "\n▶ سقف تعداد کانفیگ فعال\n";
// ------------------------------------------------------------------

$settings->set(Settings::TEST_CONFIG_COOLDOWN, '0');
$settings->set(Settings::TEST_CONFIG_MAX, '2');

$r2 = $service->issue($panels->find($panelId));
check('کانفیگ دوم ساخته شد', $r2['ok'] === true, (string) ($r2['message'] ?? ''));

$r3 = $service->canIssue($panels->find($panelId));
check('کانفیگ سوم رد می‌شود (سقف ۲)', $r3['ok'] === false, $r3['message']);
check('پیام سقف را توضیح می‌دهد', str_contains($r3['message'], '۲ کانفیگ تست فعال'), $r3['message']);

// ------------------------------------------------------------------
echo "\n▶ غیرفعال کردن دستی، جای را باز می‌کند\n";
// ------------------------------------------------------------------

$configs = new TestConfigRepository($db);
$list    = $configs->listByUser($uid);
$first   = $list[count($list) - 1];   // قدیمی‌ترین

$off = $service->disable($first);
check('غیرفعال کردن موفق بود', $off['ok'] === true, (string) $off['message']);
check('وضعیت روی پنل disabled شد',
    ($panel->existingUsers[(string) $first['panel_username']]['status'] ?? '') === 'disabled');
check('در دیتابیس disabled شد',
    (string) $configs->find((int) $first['id'])['status'] === TestConfigRepository::STATUS_DISABLED);

$again = $service->disable($first);
check('غیرفعال کردن دوباره بی‌اثر است', $again['ok'] === false);

check('حالا کانفیگ سوم ممکن است', $service->canIssue($panels->find($panelId))['ok'] === true);

// ------------------------------------------------------------------
echo "\n▶ پنل منقضی/غیرفعال تست نمی‌دهد\n";
// ------------------------------------------------------------------

$expiredPanelId = $panels->create($uid, 'rep3001b', 'pass', [
    'panel_status'     => PanelRepository::STATUS_ACTIVE,
    'data_limit'       => 10 * $GB,
    'access_expire_at' => time() - 100,
]);

$g = $service->canIssue($panels->find($expiredPanelId));
check('پنل منقضی رد می‌شود', $g['ok'] === false, $g['message']);
check('پیام به تمدید اشاره دارد', str_contains($g['message'], 'تمدید'), $g['message']);

$disabledPanelId = $panels->create($uid, 'rep3001c', 'pass', [
    'panel_status'     => PanelRepository::STATUS_DISABLED,
    'data_limit'       => 10 * $GB,
    'access_expire_at' => time() + 30 * 86400,
]);

$g2 = $service->canIssue($panels->find($disabledPanelId));
check('پنل غیرفعال رد می‌شود', $g2['ok'] === false);

// ------------------------------------------------------------------
echo "\n▶ سوییچ کلی\n";
// ------------------------------------------------------------------

$settings->set(Settings::TEST_CONFIG_ENABLED, '0');
check('با سوییچ خاموش، قابلیت غیرفعال است', !$service->isEnabled());
check('با سوییچ خاموش، canIssue رد می‌کند',
    $service->canIssue($panels->find($panelId))['ok'] === false);
$settings->set(Settings::TEST_CONFIG_ENABLED, '1');

// ------------------------------------------------------------------
echo "\n▶ انقضای کانفیگ تست\n";
// ------------------------------------------------------------------

$configs->update((int) $first['id'], [
    'status'     => TestConfigRepository::STATUS_ACTIVE,
    'expire_at'  => time() - 10,
]);
$marked = $configs->markExpired();
check('کانفیگ منقضی علامت خورد', $marked >= 1, 'marked=' . $marked);
check('وضعیتش expired شد',
    (string) $configs->find((int) $first['id'])['status'] === TestConfigRepository::STATUS_EXPIRED);
check('کانفیگ منقضی در شمارش فعال نیست',
    $configs->countActiveByUser($uid) < (int) $db->count('SELECT COUNT(*) FROM test_configs WHERE user_id = ?', [$uid]));

// ------------------------------------------------------------------
echo "\n▶ نام کاربری یکتا\n";
// ------------------------------------------------------------------

$names = [];
for ($i = 0; $i < 30; $i++) {
    $names[] = $service->generateTestUsername('rep3001');
}
check('نام‌ها یکتا هستند', count($names) === count(array_unique($names)), 'uniq=' . count(array_unique($names)));
check('نام‌ها ASCII هستند', count(array_filter($names, static fn (string $n): bool
    => preg_match('/^[A-Za-z0-9_]+$/', $n) === 1)) === 30);
check('نام کوتاه است', max(array_map('strlen', $names)) <= 32,
    'max=' . max(array_map('strlen', $names)));

// ------------------------------------------------------------------
echo "\n▶ پاک‌سازی رکوردهای قدیمی\n";
// ------------------------------------------------------------------

$db->run('UPDATE test_configs SET issued_at = ? WHERE id = 1', [time() - 200 * 86400]);
$deleted = $configs->prune(90);
check('رکورد قدیمی پاک شد', $deleted === 1, 'deleted=' . $deleted);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);