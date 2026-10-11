<?php

declare(strict_types=1);

/**
 * تست تلاشِ دوبارهٔ آپدیت‌های شکست‌خورده.
 *
 * ## چرا این تست هست
 *
 * قابلیتی که اضافه شد بدون تست، دقیقاً همان چیزی می‌شد که قبلاً کدِ بلااستفاده
 * بود: نوشته، وصل، و بی‌صدا کار نکند.
 *
 * سه چیز باید اثبات شود:
 *   ۱) آپدیتِ شکست‌خورده واقعاً **پارک** می‌شود و از `processed_updates` پاک
 *      می‌شود (وگرنه replay همیشه «تکراری» می‌خورد).
 *   ۲) replay موفق، رکورد را پاک می‌کند.
 *   ۳) replay ناموفقِ مکرر، بعد از سقفِ تلاش **متوقف** می‌شود — وگرنه یک باگِ
 *      پایدار صف را برای همیشه پر می‌کند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';

use Pasargad\Bot\WebhookReliability;
use Pasargad\Store\TestDb;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();
WebhookReliability::useDb($db);

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

$db->run('DELETE FROM webhook_dead_letters');
$db->run('DELETE FROM processed_updates');

echo "\n▶ ۱) پارک‌کردن آپدیتِ شکست‌خورده\n";

WebhookReliability::recordFailure(4242, new RuntimeException('پنل در دسترس نبود'), [
    'update_id' => 4242,
    'message'   => ['text' => 'سلام'],
]);

$parked = $db->first('SELECT * FROM webhook_dead_letters WHERE event_id = ?', ['4242']);

check('رکورد در صندوق پارک شد', $parked !== null);
check('شمارشِ تلاش ۱ است', (int) ($parked['attempts'] ?? 0) === 1, 'attempts=' . var_export($parked['attempts'] ?? null, true));
check('خطا ذخیره شد', str_contains((string) ($parked['last_error'] ?? ''), 'پنل'),
    (string) ($parked['last_error'] ?? 'NULL'));
check('بدنهٔ آپدیت ذخیره شد', str_contains((string) ($parked['payload'] ?? ''), 'سلام'),
    (string) ($parked['payload'] ?? 'NULL'));

echo "\n▶ ۲) ردیفِ «پردازش‌شده» باید پاک شود\n";

// این همان چیزی است که replay را ممکن می‌کند. اگر این ردیف بماند،
// FloodGuard آپدیت را «تکراری» می‌گیرد و هرگز اجرا نمی‌شود.
$db->run(
    'INSERT OR IGNORE INTO processed_updates (update_id, fingerprint, created_at) VALUES (?, ?, ?)',
    [4242, 'msg:test', time()]
);

check('قبل از پارک، ردیفِ پردازش‌شده هست',
    $db->first('SELECT update_id FROM processed_updates WHERE update_id = ?', [4242]) !== null);

WebhookReliability::recordFailure(4242, new RuntimeException('دوباره'), ['update_id' => 4242]);

check('پس از شکست، ردیفِ پردازش‌شده پاک شد',
    $db->first('SELECT update_id FROM processed_updates WHERE update_id = ?', [4242]) === null);

check('شمارشِ تلاش زیاد شد',
    (int) ($db->first('SELECT attempts FROM webhook_dead_letters WHERE event_id = ?', ['4242'])['attempts'] ?? 0) === 2);

echo "\n▶ ۳) پخش دوبارهٔ موفق\n";

$dispatched = [];

$stats = WebhookReliability::replay(static function (array $payload) use (&$dispatched): void {
    $dispatched[] = $payload;
});

check('یک آپدیت تلاش شد', ($stats['attempted'] ?? 0) === 1, json_encode($stats, JSON_UNESCAPED_UNICODE));
check('یکی واقعاً به dispatcher رسید', count($dispatched) === 1, 'count=' . count($dispatched));
check('محتوای آپدیت درست رسید', str_contains(json_encode($dispatched[0] ?? [], JSON_UNESCAPED_UNICODE), 'update_id'));

check('رکورد از صف پاک شد',
    $db->first('SELECT event_id FROM webhook_dead_letters WHERE event_id = ?', ['4242']) === null);

echo "\n▶ ۴) پخش دوبارهٔ ناموفق باید بعد از سقفِ تلاش متوقف شود\n";

WebhookReliability::recordFailure(5151, new RuntimeException('اول'), ['update_id' => 5151, 'message' => ['text' => 'x']]);

$rounds = 0;
$seen   = 0;

while ($rounds < 10) {
    $rounds++;

    $s = WebhookReliability::replay(static function () use (&$seen): void {
        $seen++;
        throw new RuntimeException('هنوز خراب است');
    });

    if (($s['attempted'] ?? 0) === 0) {
        break;
    }
}

$row = $db->first('SELECT * FROM webhook_dead_letters WHERE event_id = ?', ['5151']);

check('رکورد باقی ماند (برای بررسی ادمین)', $row !== null);
check('به سقفِ تلاش رسید', (int) ($row['attempts'] ?? 0) === WebhookReliability::MAX_ATTEMPTS,
    'attempts=' . (int) ($row['attempts'] ?? 0) . ' max=' . WebhookReliability::MAX_ATTEMPTS);

// ⚠️ تحویلِ اولیهٔ تلگرام خودش یکی از آن ۳ تلاش است، پس فقط ۲ بار پخشِ دوباره
// انجام می‌شود. اگر ۳ بار می‌شد، یعنی شمارش دوبار انجام می‌شود و آپدیتی که
// با یک تلاشِ دیگر اجرا می‌شد، هرگز دوباره تلاش نمی‌شد.

check('دیگر تلاشِ بیهوده نمی‌شود', $seen === WebhookReliability::MAX_ATTEMPTS - 1,
    'dispatch_calls=' . $seen . ' (انتظار: ' . (WebhookReliability::MAX_ATTEMPTS - 1) . ')');

// بعد از سقف، نباید دوباره اجرا شود — این همان چیزی است که صف را پر می‌کرد.
$after = WebhookReliability::replay(static function () use (&$seen): void { $seen++; });

check('پس از سقف، اصلاً تلاش نمی‌شود', ($after['attempted'] ?? 0) === 0 && $seen === WebhookReliability::MAX_ATTEMPTS - 1,
    'attempted=' . ($after['attempted'] ?? 0) . ' seen=' . $seen);

// و رکورد به‌عنوان «تکراری» علامت خورده تا حتی اگر کسی دستی پاکش کرد، دوباره اجرا نشود.
check('به‌عنوان پردازش‌شده ثبت شد تا دیگر تکراری شمرده شود',
    $db->first('SELECT update_id FROM processed_updates WHERE update_id = ?', [5151]) !== null);

echo "\n▶ ۵) رکوردِ بدون بدنه نباید در صف بماند\n";

$db->run(
    'INSERT INTO webhook_dead_letters (event_id, method, attempts, last_error, payload, created_at, updated_at)
     VALUES (?, ?, 1, ?, ?, ?, ?)',
    ['9999', 'update', 'بدنه خراب شد', '', time(), time()]
);

$s = WebhookReliability::replay(static function (array $p): void {
    throw new RuntimeException('نباید صدا زده شود');
});

check('رکوردِ بی‌بدنه رها شد', ($s['dropped'] ?? 0) === 1, json_encode($s, JSON_UNESCAPED_UNICODE));
check('از جدول پاک شد',
    $db->first('SELECT event_id FROM webhook_dead_letters WHERE event_id = ?', ['9999']) === null);

echo "\n▶ ۶) آمار و پاک‌سازی\n";

WebhookReliability::recordFailure(7001, new RuntimeException('a'), ['update_id' => 7001]);
$st = WebhookReliability::stats();

check('آمار در دسترس است', ($st['available'] ?? false) === true, json_encode($st, JSON_UNESCAPED_UNICODE));
check('تعدادِ در انتظار درست است', ($st['pending'] ?? -1) === 1, json_encode($st, JSON_UNESCAPED_UNICODE));
check('تعدادِ ازکارافتاده جدا شمرده می‌شود', ($st['exhausted'] ?? -1) === 1, json_encode($st, JSON_UNESCAPED_UNICODE));

// رکوردِ قدیمیِ ازکارافتاده نباید خودکار پاک شود — همان چیزی است که ادمین باید ببیند.
$db->run(
    "UPDATE webhook_dead_letters SET updated_at = ? WHERE event_id = ?",
    [time() - 60 * 86400, '5151']
);

$purged = WebhookReliability::purge(30);

check('رکوردِ ازکارافتادهٔ قدیمی حفظ شد', $purged === 0, 'purged=' . $purged);
check('هنوز در جدول است',
    $db->first('SELECT event_id FROM webhook_dead_letters WHERE event_id = ?', ['5151']) !== null);

echo "\n" . str_repeat('─', 56) . "\n";
echo 'نتیجه: ' . $passed . ' موفق، ' . $failed . " ناموفق\n";
echo str_repeat('─', 56) . "\n";

exit($failed === 0 ? 0 : 1);