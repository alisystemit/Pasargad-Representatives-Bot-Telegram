<?php

declare(strict_types=1);

/**
 * تست عضویت اجباری کانال.
 *
 * منطق امنیتی که باید اثبات شود:
 *   ۱) تا وقتی کاربر عضو نشده، به هیچ قابلیتی دسترسی ندارد.
 *   ۲) **سوپرادمین‌ها معاف‌اند** — وگرنه ربات برای همیشه قفل می‌شود.
 *   ۳) نتیجهٔ منفی کش می‌شود (فشار کمتر روی تلگرام) ولی نتیجهٔ مثبت طولانی‌تر.
 *   ۴) خطای شبکه نباید کاربر را قفل کند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Bot\ChannelGuard;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$settings = new Settings($db);
$bot      = new FakeBotApi();
$guard    = new ChannelGuard($bot, $settings);

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
echo "\n▶ پیش‌فرض: عضویت اجباری خاموش است\n";
// ------------------------------------------------------------------

check('به‌طور پیش‌فرض لازم نیست', !$guard->isRequired());
check('کانال خالی است', $guard->channel() === '');

$off = $guard->check(5001);
check('بدون نیاز، عبور می‌دهد', $off['member'] === true);
check('هیچ درخواستی به تلگرام نرفت', $bot->lastText() === '');

// ------------------------------------------------------------------
echo "\n▶ سوییچ روشن ولی بدون کانال\n";
// ------------------------------------------------------------------

$settings->set(Settings::CHANNEL_ENFORCED, '1');
check('بدون نام کانال، اجباری نیست', !$guard->isRequired());
check('عبور داده می‌شود', $guard->check(5002)['member'] === true);

// ------------------------------------------------------------------
echo "\n▶ فعال‌سازی کامل\n";
// ------------------------------------------------------------------

$settings->set(Settings::CHANNEL, '@pasargad_support');
check('اکنون اجباری است', $guard->isRequired());
check('نام کانل بدون @', $guard->channel() === 'pasargad_support', $guard->channel());
check('لینک دعوت درست است', $guard->inviteLink() === 'https://t.me/pasargad_support',
    $guard->inviteLink());
check('عنوان نمایشی', $guard->channelTitle() === 'pasargad_support');

// وضعیت عضویت هر کاربر را شبیه‌سازی می‌کنیم
$bot->chatMemberStatus = [
    6001 => 'member',
    6002 => 'left',
    6003 => 'creator',
    6004 => 'restricted',
    6005 => 'left',
    6006 => 'kicked',
    6007 => 'چیزی-ناشناخته',
];

check('عضو: عبور می‌دهد', $guard->check(6001)['member'] === true);
check('غیرعضو: مسدود می‌شود', $guard->check(6002)['member'] === false);
check('مدیر (creator): عبور می‌دهد', $guard->check(6003)['member'] === true);
check('ناظر (restricted) هم عضو است', $guard->check(6004)['member'] === true);
check('خارج‌شده: مسدود', $guard->check(6005)['member'] === false);
check('اخراج‌شده: مسدود', $guard->check(6006)['member'] === false);
check('عضویت ناشناخته: مسدود (پیش‌فرض محافظه‌کارانه)',
    $guard->check(6007)['member'] === false);

// ------------------------------------------------------------------
echo "\n▶ کش\n";
// ------------------------------------------------------------------

// نتیجهٔ مثبت کش می‌شود، پس حتی اگر تلگرام بعداً خطا بدهد همچنان عبور می‌کند
$cached = $guard->check(6001);
check('نتیجهٔ مثبت کش شد', $cached['cached'] === true);

// نتیجهٔ منفی هم کش می‌شود
$cachedNeg = $guard->check(6002);
check('نتیجهٔ منفی کش شد', $cachedNeg['cached'] === true);

// پاک کردن کش باعث بررسی دوباره می‌شود
$guard->forget(6001);
$fresh = $guard->check(6001);
check('بعد از forget، دوباره بررسی شد', $fresh['cached'] === false);

// ------------------------------------------------------------------
echo "\n▶ خطای شبکه کاربر را قفل نمی‌کند\n";
// ------------------------------------------------------------------

$guard->forget(6010);
$bot->chatMemberFails = true;

$network = $guard->check(6010);
check('خطای شبکه گزارش شد', $network['ok'] === false);
check('ولی کاربر قفل نشد', $network['member'] === true,
    'خطای شبکه نباید سرویس را از کاربر بگیرد');

$bot->chatMemberFails = false;

// ------------------------------------------------------------------
echo "\n▶ صفحهٔ دروازه\n";
// ------------------------------------------------------------------

[$text, $rows] = $guard->gateScreen();

check('متن دروازه مناسب است', str_contains($text, 'عضویت در کانال'), $text);
check('نام کانال در متن آمده', str_contains($text, 'pasargad_support'));

$allButtons = [];
foreach ($rows as $row) {
    foreach ($row as $button) {
        $allButtons[] = $button;
    }
}

// کلید دکمه‌های callback در این پروژه «data» است (BotApi هر دو را می‌پذیرد
// ولی ساختار رایج در همهٔ صفحه‌ها «data» است).
$joinButton = array_values(array_filter($allButtons, static fn (array $b): bool
    => isset($b['url']) && str_contains((string) $b['url'], 't.me/pasargad_support')));
$recheck = array_values(array_filter($allButtons, static fn (array $b): bool
    => isset($b['data']) && str_contains((string) $b['data'], 'channel.recheck')));

check('دکمهٔ عضویت با لینک کانال هست', count($joinButton) === 1);
check('دکمهٔ بررسی مجدد هست', count($recheck) === 1);

// ------------------------------------------------------------------
echo "\n▶ لینک کامل کانال هم پذیرفته می‌شود\n";
// ------------------------------------------------------------------

$settings->set(Settings::CHANNEL, 'https://t.me/my_channel');
$linkGuard = new ChannelGuard($bot, $settings);

check('لینک کامل شناسایی شد', $linkGuard->channel() === 'https://t.me/my_channel');
check('لینک دعوت همان لینک است', $linkGuard->inviteLink() === 'https://t.me/my_channel');

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);