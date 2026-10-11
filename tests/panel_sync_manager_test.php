<?php

declare(strict_types=1);

/**
 * تست لایهٔ «آیا اصلاً باید همگام کنیم؟».
 *
 * ## چرا این تست هست
 *
 * `PanelSyncManager` قبلاً یک مهجور کامل بود: موتور همگام‌سازیِ موازی، ادغامِ
 * داده، و گزارشِ آمار — که هیچ‌کدام پیاده نشده بودند و اصلاً صدا زده نمی‌شدند.
 *
 * حالا که به کرون وصل شده، باید ثابت کنیم که واقعاً کارِ مفیدی می‌کند:
 * پنلِ همین الان همگام‌شده را دوباره نمی‌فرستد، و پنلِ کهنه را می‌فرستد.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';

use Pasargad\Panel\PanelSyncManager;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

$users  = new UserRepository($db);
$panels = new PanelRepository($db);
PanelSyncManager::usePanels($panels);

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
        echo '     ↳ ' . mb_substr(str_replace("\n", ' ', $detail), 0, 300) . "\n";
    }
}

function makePanel(UserRepository $users, PanelRepository $panels, string $name, int $telegramId, array $extra = []): int
{
    $uid = (int) $users->upsertByTelegram($telegramId, ['telegram_id' => $telegramId])['id'];

    return $panels->create($uid, $name, '', array_merge([
        'panel_status' => PanelRepository::STATUS_ACTIVE,
        'data_limit'   => 1073741824,
        'api_key'      => 'pg_key_' . $name,
        'login_url'    => 'https://panel.example.com',
    ], $extra));
}

echo "\n▶ ۱) پنلِ هرگز همگام‌نشده باید در فهرستِ «نیازمند» باشد\n";

$neverSynced = makePanel($users, $panels, 'sync_never', 9401);

check('پنلِ تازه نیاز به همگام دارد', PanelSyncManager::needsSync($neverSynced),
    'synced_at=' . var_export($panels->find($neverSynced)['synced_at'] ?? null, true));

echo "\n▶ ۲) پنلی که همین الان همگام شده نباید دوباره برود\n";

$fresh = makePanel($users, $panels, 'sync_fresh', 9402);

$panels->update($fresh, ['synced_at' => time() - 30]);   // ۳۰ ثانیه پیش

check('پنلِ ۳۰ ثانیه‌ای نیاز به همگام ندارد', !PanelSyncManager::needsSync($fresh));

$freshRow  = (array) $panels->find($fresh);
$dueForOne = PanelSyncManager::dueForSync([$freshRow]);

check('در فهرستِ «نیازمند» هم نیست', $dueForOne === [], 'count=' . count($dueForOne));

echo "\n▶ ۳) پنلِ کهنه باید دوباره برود\n";

$stale = makePanel($users, $panels, 'sync_stale', 9403);

$panels->update($stale, ['synced_at' => time() - (PanelSyncManager::MIN_SYNC_INTERVAL + 60)]);

check('پنلِ کهنه نیاز به همگام دارد', PanelSyncManager::needsSync($stale));

echo "\n▶ ۴) مرزِ دقیقِ فاصله\n";

// نزدیکِ مرز، ولی با حاشیهٔ کافی که عبورِ یک ثانیه نتیجه را عوض نکند.
//
// ⚠️ دقیقاً روی مرز نوشتن یعنی تستِ ناپایدار: بین `time()` در آزمون و
// `time()` داخل کلاس ممکن است یک ثانیه بگذرد و پنل ناگهان «نیازمند» شود.
// این تست باید یک رفتار را بسنجد، نه سرعت ساعت را.
//
// برای خودِ شرطِ مرز (`>` نه `>=`) آزمون جداگانه‌ای وجود ندارد چون ذاتاً
// لحظه‌ای است؛ رفتارِ درست و قابل‌اتکا این است که «کمی زیرِ فاصله ⇒ تازه».
$nearEdge = makePanel($users, $panels, 'sync_edge', 9404);
$panels->update($nearEdge, ['synced_at' => time() - (PanelSyncManager::MIN_SYNC_INTERVAL - 30)]);

check('کمی زیرِ فاصله، هنوز نیاز ندارد', !PanelSyncManager::needsSync($nearEdge));

$justOver = makePanel($users, $panels, 'sync_over', 9405);
$panels->update($justOver, ['synced_at' => time() - (PanelSyncManager::MIN_SYNC_INTERVAL + 30)]);

check('کمی فراتر از فاصله، نیاز دارد', PanelSyncManager::needsSync($justOver));

echo "\n▶ ۵) پالایشِ فهرست\n";

$batch = [
    (array) $panels->find($neverSynced),
    (array) $panels->find($fresh),
    (array) $panels->find($stale),
    (array) $panels->find($nearEdge),
    (array) $panels->find($justOver),
];
$due   = PanelSyncManager::dueForSync($batch);

$dueIds = array_map(static fn (array $p): int => (int) $p['id'], $due);

check('فقط پنل‌های نیازمند رد شدند', count($dueIds) === 3, 'due=' . implode(',', $dueIds));
check('پنلِ تازه حذف شد', !in_array($fresh, $dueIds, true));
check('پنلِ کهنه و تازه‌ساخت هستند',
    in_array($stale, $dueIds, true) && in_array($neverSynced, $dueIds, true));

$limited = PanelSyncManager::dueForSync($batch, 1);
check('سقفِ تعداد رعایت شد', count($limited) === 1, 'n=' . count($limited));

echo "\n▶ ۶) گزارشِ وضعیت — باید اعدادِ واقعی بدهد\n";

$report = PanelSyncManager::getSyncStatusReport($batch);

check('تعداد کل درست است', ($report['total_panels'] ?? -1) === 5, json_encode($report, JSON_UNESCAPED_UNICODE));
check('شمارِ «به‌تازگی» درست است', ($report['synced_recently'] ?? -1) === 2, json_encode($report, JSON_UNESCAPED_UNICODE));
check('شمارِ «در انتظار» درست است', ($report['pending_sync'] ?? -1) === 3, json_encode($report, JSON_UNESCAPED_UNICODE));
check('فاصلهٔ همگام‌سازی گزارش می‌شود', ($report['interval'] ?? 0) === PanelSyncManager::MIN_SYNC_INTERVAL);

echo "\n▶ ۷) پنلِ بدون کلید باید شمرده و گزارش شود\n";

// پنلِ قدیمیِ رمز-محور: بعد از حذف رمز، بی‌سروصدا از کار می‌افتد. تنها راهِ
// دیدنشان همین شمارنده است.
$noKey = $panels->create(
    (int) $users->upsertByTelegram(9406, ['telegram_id' => 9406])['id'],
    'sync_nokey',
    'old-password',
    ['panel_status' => PanelRepository::STATUS_ACTIVE]
);

$rep2 = PanelSyncManager::getSyncStatusReport([$panels->find($noKey)]);

check('پنلِ بدون کلید شمرده شد', ($rep2['unlinked'] ?? -1) === 1, json_encode($rep2, JSON_UNESCAPED_UNICODE));
check('و در فهرستِ نیازمند هم هست',
    PanelSyncManager::needsSync($noKey, $panels->find($noKey)));

echo "\n" . str_repeat('─', 56) . "\n";
echo 'نتیجه: ' . $passed . ' موفق، ' . $failed . " ناموفق\n";
echo str_repeat('─', 56) . "\n";

exit($failed === 0 ? 0 : 1);