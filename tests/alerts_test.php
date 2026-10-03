<?php

declare(strict_types=1);

/**
 * تست سرویس هشدارهای پنل (حجم کم، انقضای نزدیک، انقضای قطع‌شده).
 *
 * هدف: اطمینان از سه چیز
 *   ۱) هشدار **برای هر پنل** جداگانه است، نه برای مجموع کاربر.
 *   ۲) هشدار به خریدار **و** مدیر می‌رسد.
 *   ۳) هر هشدار فقط یک‌بار ارسال می‌شود مگر شرایط عوض شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Store\AlertService;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Crypto;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\FakeBotApi;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$settings = new Settings($db);
$settings->set(Settings::LOW_VOLUME_ALERT, '5');
$settings->set(Settings::EXPIRE_WARN_DAYS, '3');

$users  = new UserRepository($db);
$panels = new PanelRepository($db);
$bot    = new FakeBotApi();

$alerts = new AlertService($panels, $settings, $bot);

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

/**
 * ساخت کاربر + یک پنل با وضعیت دلخواه.
 *
 * @param array<string, mixed> $panelOverrides
 * @return array{0:int, 1:array<string, mixed>} شناسهٔ کاربر، رکورد پنل
 */
function makePanel(int $telegramId, string $panelUsername, array $panelOverrides = []): array
{
    global $db, $users, $panels;

    $user = $users->upsertByTelegram($telegramId, ['telegram_id' => $telegramId]);
    $uid  = (int) $user['id'];

    $panelId = $panels->create($uid, $panelUsername, 'secret', array_merge([
        'panel_status'   => PanelRepository::STATUS_ACTIVE,
        'data_limit'     => 100 * 1073741824,
        'used_traffic'   => 0,
        'login_url'      => 'https://panel.example.com',
    ], $panelOverrides));

    return [$uid, $panels->find($panelId)];
}

// ------------------------------------------------------------------
echo "\n▶ پنل سالم — نباید هشدار بگیرد\n";
// ------------------------------------------------------------------

[$healthyUid, $healthy] = makePanel(1001, 'healthy', ['used_traffic' => 10 * $GB]);
$healthy['telegram_id'] = 1001;

$bot->reset();
check('حجم سالم هشدار نمی‌دهد', !$alerts->checkLowVolume($healthy));
check('انقضای ندارد هشدار نمی‌دهد', !$alerts->checkExpiring($healthy));
check('هیچ پیامی ارسال نشد', count($bot->sent) === 0);

// ------------------------------------------------------------------
echo "\n▶ حجم کم — هشدار به خریدار و مدیر\n";
// ------------------------------------------------------------------

[$lowUid, $low] = makePanel(1002, 'lowvol', ['used_traffic' => 97 * $GB]);
$low['telegram_id'] = 1002;

$adminNotice = [];
$alerts->setNotifier(new class ($adminNotice) {
    /** @var array<int, string> */
    public array $calls = [];

    public function __construct(array &$sink)
    {
        $this->sink =& $sink;
    }

    /** @var array<int, string> */
    private array $sink = [];

    public function notifyAdmins(string $text, ?array $button = null): void
    {
        $this->calls[] = $text;
    }
});

$bot->reset();
check('هشدار حجم کم ارسال شد', $alerts->checkLowVolume($low));
check('پیام به خریدار درست است', str_contains($bot->allText(), 'هشدار حجم پنل'), $bot->allText());
check('دکمهٔ شارژ دارد', $bot->hasButton('شارژ پنل'));
check('نام پنل در پیام هست', str_contains($bot->allText(), 'lowvol'));

$sent1 = count($bot->sent);
check('اجرای دوباره هشدار تکرار نشد', !$alerts->checkLowVolume($panels->find((int) $low['id'])));
check('پیام جدیدی ارسال نشد', count($bot->sent) === $sent1);

// ------------------------------------------------------------------
echo "\n▶ هشدار حجم per-panel است، نه per-user\n";
// ------------------------------------------------------------------

// کاربر ۱۰۰۲ یک پنل دوم و سالم دارد. نباید هشدار بگیرد چون مشکل پنل اول است.
$panels->create($lowUid, 'lowvol-2', 'secret', [
    'panel_status' => PanelRepository::STATUS_ACTIVE,
    'data_limit'   => 100 * $GB,
    'used_traffic' => 1 * $GB,
]);

$second = $panels->listByUser($lowUid)[0];   // تازه‌ترین
check('پنل دوم سالم هشدار نمی‌دهد', !$alerts->checkLowVolume($second));

// اما اگر مصرف پنل دوم بالا برود، هشدار مخصوص خودش می‌رود.
$panels->update((int) $second['id'], ['used_traffic' => 99 * $GB]);
$bot->reset();
check('پنل دوم با حجم کم هشدار داد', $alerts->checkLowVolume($panels->find((int) $second['id'])));
check('هشدار به پنل درست بود', str_contains($bot->allText(), 'lowvol-2'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ سقف تغییر کند → هشدار دوباره\n";
// ------------------------------------------------------------------

$panels->update((int) $low['id'], ['data_limit' => 80 * $GB]);
$bot->reset();
check('با کاهش سقف، هشدار دوباره رفت', $alerts->checkLowVolume($panels->find((int) $low['id'])));

$panels->update((int) $low['id'], ['data_limit' => 400 * $GB]);
$bot->reset();
check('با سقف کافی، هشدار نمی‌رود', !$alerts->checkLowVolume($panels->find((int) $low['id'])));
check('پیامی ارسال نشد', count($bot->sent) === 0);

// ------------------------------------------------------------------
echo "\n▶ انقضای نزدیک\n";
// ------------------------------------------------------------------

[$soonUid, $soon] = makePanel(1004, 'expiring', [
    'access_expire_at' => time() + 86400,
    'granted_volume'   => 100 * $GB,
]);
$soon['telegram_id'] = 1004;

$bot->reset();
check('هشدار انقضا ارسال شد', $alerts->checkExpiring($soon));
check('پیام انقضا درست است', str_contains($bot->allText(), 'یادآوری اعتبار'), $bot->allText());
check('دکمهٔ تمدید دارد', $bot->hasButton('تمدید'));

$sentBefore = count($bot->sent);
check('هشدار انقضا تکرار نشد', !$alerts->checkExpiring($panels->find((int) $soon['id'])));
check('پیام تکراری ارسال نشد', count($bot->sent) === $sentBefore);

[$laterUid, $later] = makePanel(1005, 'later', ['access_expire_at' => time() + 10 * 86400]);
check('۱۰ روز مانده هشدار نمی‌دهد', !$alerts->checkExpiring($later));

// ------------------------------------------------------------------
echo "\n▶ انقضای گذشته → هشدار «تمام شد» + درخواست قطع دسترسی\n";
// ------------------------------------------------------------------

[$expiredUid, $expired] = makePanel(1006, 'expired', ['access_expire_at' => time() - 10 * 86400]);
$expired['telegram_id'] = 1006;

$bot->reset();
check('انقضای گذشته هشدار می‌دهد', $alerts->checkExpired($expired));
check('متن پایان مهلت ارفاقی دارد', str_contains($bot->allText(), 'مهلت ارفاقی پنل شما تمام شد'), $bot->allText());

$after = $panels->find((int) $expired['id']);
check('پرچم expiry_notified ثبت شد', (int) $after['expiry_notified'] === 1);

check('درخواست قطع دسترسی ثبت شد', $alerts->requestCutoff($after));
check('درخواست دوباره تکرار نشد', !$alerts->requestCutoff($panels->find((int) $expired['id'])));

$bot->reset();
check('هشدار انقضا تکرار نمی‌شود', !$alerts->checkExpired($panels->find((int) $expired['id'])));

// انقضای گذشته نباید «انقضای نزدیک» هم بدهد (مسیر جدا و گمراه‌کننده است)
$bot->reset();
check('برای پنل منقضی، هشدار «نزدیک» نمی‌رود', !$alerts->checkExpiring($panels->find((int) $expired['id'])));

// اگر نماینده تمدید کند، هشدار «تمام شد» نباید دوباره برود
$panels->extendExpiry((int) $expired['id'], time() + 30 * 86400);
$bot->reset();
check('پس از تمدید، هشدار تمام‌شد تکرار نشد', !$alerts->checkExpired($panels->find((int) $expired['id'])));

// ------------------------------------------------------------------
echo "\n▶ سوییچ قطع دسترسی خاموش\n";
// ------------------------------------------------------------------

$settings->set(Settings::CUTOFF_ON_EXPIRE, '0');
[$exp2Uid, $exp2] = makePanel(1008, 'expired-2', ['access_expire_at' => time() - 10]);
check('با سوییچ خاموش، درخواست قطع داده نمی‌شود', !$alerts->requestCutoff($exp2));
$settings->set(Settings::CUTOFF_ON_EXPIRE, '1');

$panels->markCutoffDone((int) $exp2['id'], 3);
check('پنل با قطع انجام‌شده دوباره درخواست نمی‌دهد', !$alerts->requestCutoff($panels->find((int) $exp2['id'])));

// ------------------------------------------------------------------
echo "\n▶ پنل نامحدود\n";
// ------------------------------------------------------------------

[$unlUid, $unl] = makePanel(1009, 'unlimited', [
    'data_limit'       => 0,
    'used_traffic'     => 500 * $GB,
    'access_expire_at' => null,
]);
$bot->reset();
check('حجم نامحدود هشدار نمی‌دهد', !$alerts->checkLowVolume($unl));
check('بدون انقضا هشدار نمی‌دهد', !$alerts->checkExpiring($unl));
check('پیامی ارسال نشد', count($bot->sent) === 0);

// ------------------------------------------------------------------
echo "\n▶ اجرای دسته‌ای\n";
// ------------------------------------------------------------------

$bot->reset();
$result = $alerts->runAll();

check('همهٔ پنل‌ها بررسی شدند', $result['checked'] > 0, 'checked=' . $result['checked']);
check('کلیدهای آماری موجودند',
    isset($result['low_volume'], $result['expiring'], $result['expired'], $result['cutoff_requested']));

// ------------------------------------------------------------------
echo "\n▶ کاربر مسدود نادیده گرفته می‌شود\n";
// ------------------------------------------------------------------

[$blockedUid, $blocked] = makePanel(1010, 'blocked-panel', ['used_traffic' => 99 * $GB]);
$users->setBlocked($blockedUid, true, 'تست');

check('پنل کاربر مسدود در فهرست بررسی نیست',
    !in_array((int) $blocked['id'], array_map(
        static fn (array $p): int => (int) $p['id'],
        $panels->listWatchable(200)
    ), true));

// ------------------------------------------------------------------
echo "\n▶ رمز پنل قابل رمزگشایی است\n";
// ------------------------------------------------------------------

$decrypted = $panels->plainPassword($panels->find((int) $low['id']));
check('رمز ذخیره‌شده رمزگشایی می‌شود', $decrypted === 'secret', 'got=' . $decrypted);
check('رمز خام در دیتابیس نیست',
    !str_contains((string) ($panels->find((int) $low['id'])['panel_password'] ?? ''), 'secret'));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);