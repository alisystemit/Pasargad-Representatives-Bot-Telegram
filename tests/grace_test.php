<?php

declare(strict_types=1);

/**
 * تست مهلت ارفاقی (grace period) پس از انقضای پنل.
 *
 * چرا این تست مهم است؟
 *   مهلت ارفاقی مرز بین «قرارداد تمام شد» و «باید سرویس مردم را قطع کرد» است.
 *   یک باگ اینجا یعنی یا قطع زودهنگام سرویس مردم (شکایت و آسیب به برند)، یا
 *   فراموشی قطع دسترسی (سرویس رایگان برای مشتری).
 *
 * سناریوها:
 *   • قبل از انقضا: هیچ رفتار ویژه‌ای نیست.
 *   • انقضا گذشته ولی در مهلت: هشدار اضطراری، **بدون** درخواست قطع دسترسی.
 *   • مهلت تمام شد: هشدار «تمام شد» + درخواست قطع دسترسی به مدیر.
 *   • تنظیم ۰ روز = رفتار قدیمی (قطع فوری).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Store\AlertService;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$settings = new Settings($db);
$settings->set(Settings::EXPIRE_WARN_DAYS, '3');
$settings->set(Settings::EXPIRE_GRACE_DAYS, '3');
$settings->set(Settings::CUTOFF_ON_EXPIRE, '1');

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

$GRACE = 3 * 86400;

/**
 * ثبت اعلان‌های ادمین تا بتوانیم تست کنیم پیام به مدیر هم رفته.
 */
final class AdminSpy
{
    /** @var array<int, string> */
    public array $calls = [];

    public function notifyAdmins(string $text, ?array $button = null): void
    {
        $this->calls[] = $text;
    }
}

/**
 * @return array{0:int, 1:array<string, mixed>}
 */
function makePanel(int $telegramId, string $panelUsername, ?int $expireAt): array
{
    global $db, $users, $panels;

    $user = $users->upsertByTelegram($telegramId, ['telegram_id' => $telegramId]);
    $uid  = (int) $user['id'];

    $panelId = $panels->create($uid, $panelUsername, 'secret', [
        'panel_status'     => PanelRepository::STATUS_ACTIVE,
        'data_limit'       => 100 * 1073741824,
        'used_traffic'     => 10 * 1073741824,
        'access_expire_at' => $expireAt,
        'login_url'        => 'https://panel.example.com',
    ]);

    $row = $panels->find($panelId);
    $row['telegram_id'] = $telegramId;

    return [$uid, $row];
}

// =====================================================================
echo "\n▶ محاسبات مهلت ارفاقی (توابع خالص)\n";
// =====================================================================

$now = time();

$alive   = ['access_expire_at' => $now + 10 * 86400];
$justNow = ['access_expire_at' => $now - 3600];          // ۱ ساعت پیش
$old     = ['access_expire_at' => $now - 10 * 86400];    // ۱۰ روز پیش

check('پنل معتبر منقضی نیست', !PanelRepository::isExpired($alive));
check('پنل گذشته منقضی است', PanelRepository::isExpired($justNow));
check('پنل بدون انقضا هیچ‌وقت منقضی نمی‌شود',
    !PanelRepository::isExpired(['access_expire_at' => null]));

check('پنل تازه‌منقضی در مهلت ارفاقی است', PanelRepository::isInGrace($justNow, 3));
check('پنل ۱۰ روزهٔ منقضی در مهلل ارفاقی نیست', !PanelRepository::isInGrace($old, 3));
check('پنل معتبر در مهلت ارفاقی نیست', !PanelRepository::isInGrace($alive, 3));

check('پنل ۱۰ روزه گذشته، مهلتش تمام شده', PanelRepository::isGraceOver($old, 3));
check('پنل ۱ ساعته گذشته، مهلتش تمام نشده', !PanelRepository::isGraceOver($justNow, 3));
check('پنل معتبر مهلتش تمام نشده', !PanelRepository::isGraceOver($alive, 3));

check('با مهلت صفر، ۱ ساعت گذشته هم بلافاصله تمام است',
    PanelRepository::isGraceOver($justNow, 0));
check('با مهلت صفر، هیچ پنلی «در مهلت» نیست',
    !PanelRepository::isInGrace($justNow, 0));

check('پایان مهلت = انقضا + مهلت',
    PanelRepository::graceDeadline($justNow, 3) === (int) $justNow['access_expire_at'] + $GRACE);

$graceLeft = PanelRepository::graceDaysLeft(['access_expire_at' => $now - 86400], 3);
check('روز باقی‌ماندهٔ مهلت محاسبه شد', $graceLeft !== null && $graceLeft >= 1 && $graceLeft <= 3,
    'left=' . var_export($graceLeft, true));

check('پنل بدون انقضا پایان مهلت ندارد',
    PanelRepository::graceDeadline(['access_expire_at' => null], 3) === null);

// =====================================================================
echo "\n▶ مهلت صفر = رفتار قدیمی\n";
// =====================================================================

$settings->set(Settings::EXPIRE_GRACE_DAYS, '0');

[, $fastPanel] = makePanel(1101, 'nograce', $now - 3600);
check('بدون مهلت، پنل بلافاصله «مهلت تمام‌شده» است',
    PanelRepository::isGraceOver($fastPanel, 0));

$settings->set(Settings::EXPIRE_GRACE_DAYS, '3');

// =====================================================================
echo "\n▶ پنل در مهلت ارفاقی: هشدار اضطراری، بدون قطع دسترسی\n";
// =====================================================================

$admin = new AdminSpy();
$alerts->setNotifier($admin);

[, $inGrace] = makePanel(1201, 'ingrace', $now - 2 * 86400);

$bot->reset();
$admin->calls = [];

check('هشدار مهلت ارفاقی ارسال شد', $alerts->checkGrace($inGrace, 3));
check('پیام به خریدار رفت', $bot->lastTextFor(1201) !== ''
    && str_contains($bot->allText(), 'مهلت ارفاقی'), $bot->allText());
check('مدیر هم مطلع شد', $admin->calls !== []
    && str_contains($admin->calls[0], 'مهلت ارفاقی'), implode(' | ', $admin->calls));
check('دکمهٔ تمدید به کاربر داده شد', $bot->hasButton('تمدید فوری'));

// تکرار نباید پیام دوباره بفرستد
$bot->reset();
check('هشدار تکراری ارسال نمی‌شود', !$alerts->checkGrace($inGrace, 3));
check('پیام دومی نرفت', count($bot->sent) === 0);

// =====================================================================
echo "\n▶ پنل در مهلت: درخواست قطع دسترسی داده نمی‌شود\n";
// =====================================================================

$bot->reset();
$admin->calls = [];

check('در مهلت ارفاقی، درخواست قطع داده نمی‌شود', !$alerts->requestCutoff($inGrace));
check('هیچ اعلانی برای قطع نرفت', $admin->calls === []);

check('و «انقضای قطع‌شده» هم در مهلت ارفاقی گفته نمی‌شود', !$alerts->checkExpired($inGrace, 3));

// =====================================================================
echo "\n▶ پایان مهلت: هشدار نهایی + درخواست قطع دسترسی\n";
// =====================================================================

[, $overdue] = makePanel(1301, 'overdue', $now - 10 * 86400);

$bot->reset();
$admin->calls = [];

$runAll = $alerts->runAll();

check('پنل سررسیدشده دیده شد', $runAll['checked'] >= 2, json_encode($runAll));
check('هشدار «پایان مهلت» ارسال شد', $runAll['expired'] === 1, json_encode($runAll));
check('پنل در مهلل، درخواست قطع نگرفت', !($db->first(
    'SELECT * FROM panels WHERE panel_username = ?',
    ['ingrace']
)['cutoff_requested_at'] ?? null) !== null);
check('درخواست قطع دسترسی داده شد', $runAll['cutoff_requested'] >= 1, json_encode($runAll));

$adminText = implode("\n", $admin->calls);
check('مدیر دکمهٔ قطع دسترسی گرفت',
    str_contains($adminText, 'قطع دسترسی') && str_contains($adminText, 'overdue'), $adminText);

$cutoff = $db->first('SELECT * FROM panels WHERE panel_username = ?', ['overdue']);
check('زمان درخواست قطع ثبت شد', $cutoff['cutoff_requested_at'] !== null);
check('پیام به خریدار رفت',
    str_contains($bot->allText(), 'مهلت ارفاقی') || str_contains($bot->allText(), 'دسترسی کاربران'));

// اجرای دوباره نباید هشدار تکراری بدهد
$bot->reset();
$runAgain = $alerts->runAll();
check('اجرای دوباره چیزی تکرار نکرد',
    $runAgain['expired'] === 0 && $runAgain['cutoff_requested'] === 0, json_encode($runAgain));

// =====================================================================
echo "\n▶ تمدید پنل، هشدارهای قبلی را باطل می‌کند\n";
// =====================================================================

$panels->extendExpiry((int) $overdue['id'], time() + 30 * 86400);
$renewed = $panels->find((int) $overdue['id']);

check('پنل تمدیدشده دیگر منقضی نیست', !PanelRepository::isExpired($renewed));
check('و در مهلت ارفاقی هم نیست', !PanelRepository::isInGrace($renewed, 3));

$bot->reset();
$runRenewed = $alerts->runAll();
check('پنل تمدیدشده هشدار انقضا نمی‌گیرد', $runRenewed['expired'] === 0, json_encode($runRenewed));

// =====================================================================
echo "\n▶ panel.isUsable و برچسب وضعیت با مهلت ارفاقی\n";
// =====================================================================

[, $gracePanel] = makePanel(1401, 'graceusable', $now - 2 * 86400);
$gracePanel['panel_status'] = PanelRepository::STATUS_ACTIVE;

// isUsable عمداً همچنان «منقضی = غیرقابل استفاده» است، چون قرارداد تمام شده؛
// ولی برچسب باید مهلت ارفاقی را نشان دهد نه «قطع دسترسی».
check('پنل در مهلت ارفاقی برای خرید قابل استفاده نیست', !PanelRepository::isUsable($gracePanel));
check('برچسب وضعیت همچنان انقضا را می‌گوید',
    str_contains(PanelRepository::statusLabel($gracePanel), 'اعتبار تمام شده'),
    PanelRepository::statusLabel($gracePanel));

// =====================================================================
echo "\n▶ listWatchable پنجرهٔ کافی دارد\n";
// =====================================================================

$watchable = $panels->listWatchable(200, 60);
$names = array_column($watchable, 'panel_username');

check('پنل ۱۰ روزهٔ منقضی در فهرست دیده می‌شود (پنجرهٔ ۶۰ روزه)',
    in_array('overdue', $names, true), implode(',', $names));
check('پنل در مهلل ارفاقی هم دیده می‌شود',
    in_array('ingrace', $names, true), implode(',', $names));

// پنلی که ۴۰ روز پیش منقضی شده: با پنجرهٔ ۶۰ روزه دیده می‌شود ولی با
// پنجرهٔ ۵ روزه نه — این دقیقاً همان چیزی است که پنجرهٔ کرون را کنترل می‌کند.
makePanel(1501, 'ancient', $now - 40 * 86400);

$longWindow  = array_column($panels->listWatchable(200, 60), 'panel_username');
$shortWindow = array_column($panels->listWatchable(200, 5), 'panel_username');

check('پنجرهٔ ۶۰ روزه، پنل ۴۰ روزهٔ منقضی را می‌بیند',
    in_array('ancient', $longWindow, true), implode(',', $longWindow));
check('پنجرهٔ ۵ روزه، پنل ۴۰ روزهٔ منقضی را نمی‌بیند',
    !in_array('ancient', $shortWindow, true), implode(',', $shortWindow));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);