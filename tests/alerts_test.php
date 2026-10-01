<?php

declare(strict_types=1);

/**
 * تست سرویس هشدارها (حجم کم، اعتبار کم، انقضای نزدیک).
 *
 * هدف: اطمینان از اینکه هشدار فقط یک‌بار ارسال می‌شود و شرایط درست تشخیص داده می‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Store\AlertService;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$settings = new Settings($db);
$settings->set(Settings::LOW_VOLUME_ALERT, '5');

$users = new UserRepository($db);
$bot   = new FakeBotApi();
$alerts = new AlertService($users, $settings, $bot);

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

$GB = 1073741824;

echo "\n▶ کاربر با حجم کافی (نباید هشدار بگیرد)\n";

$healthyId = $users->upsertByTelegram(1001, [
    'telegram_id'    => 1001,
    'panel_username' => 'healthy',
    'panel_password' => 'x',
    'panel_status'   => 'active',
    'panel_data_limit' => 100 * $GB,
    'panel_used'     => 10 * $GB,
    'user_credit'    => 50 * $GB,
])['id'];

$healthy = $users->findById($healthyId);
$bot->reset();
check('حجم سالم هشدار نمی‌دهد', !$alerts->checkLowVolume($healthy));
check('اعتبار کافی هشدار نمی‌دهد', !$alerts->checkLowCredit($healthy));
check('هیچ پیامی ارسال نشد', $bot->lastText() === '');

echo "\n▶ کاربر با حجم کم\n";

$lowId = $users->upsertByTelegram(1002, [
    'telegram_id'    => 1002,
    'panel_username' => 'lowvol',
    'panel_password' => 'x',
    'panel_status'   => 'active',
    'panel_data_limit' => 100 * $GB,
    'panel_used'     => 97 * $GB,
    'user_credit'    => 50 * $GB,
])['id'];

$lowUser = $users->findById($lowId);
$bot->reset();
check('هشدار حجم کم ارسال شد', $alerts->checkLowVolume($lowUser));
check('پیام هشدار حجم درست است', str_contains($bot->allText(), 'هشدار حجم پنل'), $bot->allText());
check('دکمهٔ خرید بسته دارد', $bot->hasButton('خرید بسته'));

$sent1 = count($bot->sent);
check('اجرای دوباره هشدار تکرار نشد', !$alerts->checkLowVolume($users->findById($lowId)));
check('پیام جدیدی ارسال نشد', count($bot->sent) === $sent1);

echo "\n▶ سقف کاهش یافته (هشدار باید دوباره ارسال شود)\n";

// سقف از ۱۰۰ به ۸۰ گیگ کاهش می‌یابد → مصرف ۹۷ گیگ از سقف فراتر رفته
$users->update($lowId, ['panel_data_limit' => 80 * $GB]);
$bot->reset();
check('با تغییر سقف، هشدار دوباره ارسال شد', $alerts->checkLowVolume($users->findById($lowId)));
check('پیام جدید ارسال شد', count($bot->sent) === 1);

// سقف بزرگ‌تر → دیگر هشدار لازم نیست
$users->update($lowId, ['panel_data_limit' => 400 * $GB]);
$bot->reset();
check('با سقف کافی، هشدار ارسال نمی‌شود', !$alerts->checkLowVolume($users->findById($lowId)));
check('پیامی ارسال نشد', count($bot->sent) === 0);

echo "\n▶ کاربر بدون اعتبار کاربر\n";

$noCreditId = $users->upsertByTelegram(1003, [
    'telegram_id'    => 1003,
    'panel_username' => 'nocredit',
    'panel_password' => 'x',
    'panel_status'   => 'active',
    'panel_data_limit' => 0,
    'panel_used'     => 0,
    'user_credit'    => 0,
])['id'];

$noCredit = $users->findById($noCreditId);
$bot->reset();
check('هشدار اتمام اعتبار ارسال شد', $alerts->checkLowCredit($noCredit));
check('پیام درست است', str_contains($bot->allText(), 'اعتبار کاربر شما تمام شده'), $bot->allText());
check('دکمهٔ خرید اعتبار دارد', $bot->hasButton('خرید اعتبار'));

$sentBefore = count($bot->sent);
check('هشدار اعتبار تکرار نشد', !$alerts->checkLowCredit($users->findById($noCreditId)));
check('پیام تکراری ارسال نشد', count($bot->sent) === $sentBefore);

echo "\n▶ اعتبار دوباره خریداری شد (هشدار دوباره)\n";

$users->addUserCredit($noCreditId, 10 * $GB, time() + 30 * 86400);
$bot->reset();
check('با شارژ اعتبار، هشدار قطع شد', !$alerts->checkLowCredit($users->findById($noCreditId)));

echo "\n▶ انقضای نزدیک\n";

// ۱ روز مانده
$soonId = $users->upsertByTelegram(1004, [
    'telegram_id'     => 1004,
    'panel_username'  => 'expiring',
    'panel_password'  => 'x',
    'panel_status'    => 'active',
    'granted_volume'  => 100 * $GB,
    'granted_expire_at' => time() + 86400,
])['id'];

$soon = $users->findById($soonId);
$bot->reset();
check('هشدار انقضا ارسال شد', $alerts->checkExpiring($soon));
check('پیام انقضا درست است', str_contains($bot->allText(), 'یادآوری اعتبار'), $bot->allText());

$sentBefore = count($bot->sent);
check('هشدار انقضا تکرار نشد', !$alerts->checkExpiring($users->findById($soonId)));
check('پیام تکراری ارسال نشد', count($bot->sent) === $sentBefore);

// ۱۰ روز مانده — نباید هشدار بدهد
$laterId = $users->upsertByTelegram(1005, [
    'telegram_id'     => 1005,
    'panel_username'  => 'later',
    'panel_password'  => 'x',
    'panel_status'    => 'active',
    'granted_expire_at' => time() + 10 * 86400,
])['id'];

check('۱۰ روز مانده هشدار نمی‌دهد', !$alerts->checkExpiring($users->findById($laterId)));

// منقضی شده
$expiredId = $users->upsertByTelegram(1006, [
    'telegram_id'     => 1006,
    'panel_username'  => 'expired',
    'panel_password'  => 'x',
    'panel_status'    => 'active',
    'granted_expire_at' => time() - 3600,
])['id'];

$bot->reset();
check('انقضای گذشته هشدار می‌دهد', $alerts->checkExpiring($users->findById($expiredId)));
check('متن «به پایان رسیده» دارد', str_contains($bot->allText(), 'به پایان رسیده'), $bot->allText());

echo "\n▶ اجرای دسته‌ای\n";

$bot->reset();
$result = $alerts->runAll();
check('همهٔ کاربران بررسی شدند', $result['checked'] >= 6, 'checked=' . $result['checked']);
check('نتیجه آماری برگشت', is_array($result) && isset($result['low_volume'], $result['low_credit'], $result['expiring']));

echo "\n▶ کاربران بدون محدودیت نادیده گرفته می‌شوند\n";

$unlimitedId = $users->upsertByTelegram(1007, [
    'telegram_id'    => 1007,
    'panel_username' => 'unlimited',
    'panel_password' => 'x',
    'panel_status'   => 'active',
    'panel_data_limit' => 0,
    'panel_used'     => 500 * $GB,
])['id'];

$bot->reset();
check('حجم نامحدود هشدار نمی‌دهد', !$alerts->checkLowVolume($users->findById($unlimitedId)));
check('پیامی ارسال نشد', count($bot->sent) === 0);

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);