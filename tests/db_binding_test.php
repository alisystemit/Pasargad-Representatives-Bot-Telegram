<?php

declare(strict_types=1);

/**
 * نوعِ پارامترهای کوئری — یک بمبِ خاموش در لایهٔ دیتابیس.
 *
 * ## ماجرا
 *
 * `PDOStatement::execute($array)` **همهٔ مقادیر را رشته** می‌بندد
 * (`PDO::PARAM_STR`). بیشتر کوئری‌ها این را لو نمی‌دهند، چون SQLite وقتی یک
 * **ستون** را با مقدارِ متنی مقایسه می‌کند، affinity ستون را اعمال می‌کند و
 * `'2'` را به `2` تبدیل می‌کند. پس `WHERE user_id = :id` درست کار می‌کرد.
 *
 * ولی سمتِ چپِ مقایسه که یک **عبارت** باشد نه ستون، هیچ affinity ندارد:
 *
 *     WHERE COALESCE(paid_at, created_at) >= :since
 *
 * در SQLite ترتیبِ نوع‌ها `NULL < INTEGER/REAL < TEXT < BLOB` است، یعنی هر TEXT
 * از هر INTEGER بزرگ‌تر. پس این شرط **همیشه FALSE** بود و کوئری بی‌صدا صفر
 * ردیف برمی‌گرداند — نه خطا، نه استثنا.
 *
 * در عمل این یعنی «کمیسیون معرفی پرداخت نمی‌شود» که هیچ‌کس نمی‌فهمد چرا.
 *
 * ## چرا این تست جدا است
 *
 * این باگ وقتی خودش را نشان می‌دهد که یک کوئریِ خاصِ یک مسیرِ خاص شکست بخورد.
 * آن‌وقت کسی فکر می‌کند مشکل از منطقِ کمیسیون است. این تست **خودِ رفتارِ
 * bind** را قفل می‌کند تا کوئریِ بعدیِ مشابه نیازی به کشفِ دوباره نداشته باشد.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';

use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
Settings::flush();
(new Migrator($db))->migrate();

$dbx = Db::instance();

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    echo "  ❌ {$label}" . ($extra !== '' ? "\n      → " . $extra : '') . "\n";
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  نوعِ پارامترهای کوئری\n";
echo str_repeat('═', 62) . "\n";

$stamp = 1790000000;

// ------------------------------------------------------------------
// داده‌های آزمایشی
// ------------------------------------------------------------------
//
// ⚠️ `orders.user_id` به **کلید داخلیِ** `users(id)` وصل است، نه به
// `telegram_id`. اگر telegram_id را بگذاریم، FOREIGN KEY می‌ترکد و کلِ فایل
// می‌میرد — که هیچ ربطی به چیزی که این تست می‌سنجد ندارد.
$dbx->run("INSERT INTO users (telegram_id, first_name, wallet_balance, created_at, updated_at)
           VALUES (7001, 'Numeric', 500, ?, ?)", [$stamp, $stamp]);
$dbx->run("INSERT INTO users (telegram_id, first_name, wallet_balance, created_at, updated_at)
           VALUES (7002, 'Text', 500, ?, ?)", [$stamp, $stamp]);
$dbx->run("INSERT INTO users (telegram_id, first_name, wallet_balance, created_at, updated_at)
           VALUES (7003, 'Null', 500, ?, ?)", [$stamp, $stamp]);

$uid = static fn (int $telegramId): int => (int) Db::instance()
    ->value('SELECT id FROM users WHERE telegram_id = ?', [$telegramId]);

foreach ([7001, 7002, 7003] as $tg) {
    $dbx->run(
        "INSERT INTO orders (code, user_id, package_title, kind, price_toman, status, created_at, updated_at, paid_at)
         VALUES (?, ?, 'P', 'agency', 100000, 'paid', ?, ?, ?)",
        ['ORD-' . $tg, $uid($tg), $stamp, $stamp, $stamp + 500]
    );
}

// ------------------------------------------------------------------
echo "\n▶ ستون در برابر پارامتر (مسیرِ قدیمی — باید کار کند)\n";
// ------------------------------------------------------------------

$n = $dbx->count('SELECT COUNT(*) FROM orders WHERE user_id = :uid', ['uid' => $uid(7001)]);
check('مقایسه با ستون عددی کار می‌کند', $n === 1, "count={$n}");

$s = $dbx->count('SELECT COUNT(*) FROM users WHERE first_name = :name', ['name' => 'Text']);
check('مقایسه با ستون متنی کار می‌کند', $s === 1, "count={$s}");

// کلید با `:` و بدون `:` باید یکی باشند.
$withColon    = $dbx->count('SELECT COUNT(*) FROM orders WHERE user_id = :uid', [':uid' => $uid(7001)]);
$withoutColon = $dbx->count('SELECT COUNT(*) FROM orders WHERE user_id = :uid', ['uid' => $uid(7001)]);
check('کلید با «:» و بدون «:» یکسان کار می‌کند',
    $withColon === 1 && $withoutColon === 1, ":={$withColon} plain={$withoutColon}");

// ------------------------------------------------------------------
echo "\n▶ عبارت در برابر پارامتر (بومیِ باگ)\n";
// ------------------------------------------------------------------

// ⭐️ این همان شکلی است که سیستمِ کمیسیون را بی‌صدا از کار انداخته بود.
$expr = $dbx->count(
    'SELECT COUNT(*) FROM orders WHERE user_id = :uid AND COALESCE(paid_at, created_at) >= :since',
    ['uid' => $uid(7001), 'since' => $stamp]
);
check('شرطِ COALESCE با پارامتر عددی کار می‌کند', $expr === 1, "count={$expr}");

$expr2 = $dbx->count(
    'SELECT COUNT(*) FROM orders WHERE user_id = :uid AND COALESCE(paid_at, created_at) >= :since',
    ['uid' => $uid(7001), 'since' => $stamp + 1000]
);
check('و عددِ بزرگ‌تر واقعاً رد می‌کند', $expr2 === 0, "count={$expr2}");

$expr3 = $dbx->count(
    'SELECT COUNT(*) FROM orders WHERE user_id = :uid AND paid_at + 0 >= :since',
    ['uid' => $uid(7001), 'since' => $stamp]
);
check('و روی عبارتِ حسابی هم درست است', $expr3 === 1, "count={$expr3}");

// مقایسه‌های دیگری که همین مشکل را دارند
$case = $dbx->count(
    "SELECT COUNT(*) FROM orders WHERE user_id = :uid AND status IN ('paid','applied')",
    ['uid' => $uid(7001)]
);
check('فهرستِ IN با پارامترِ عددی کار می‌کند', $case === 1, "count={$case}");

// 'Text' و 'Null' هر دو ۴ حرف دارند.
$len = $dbx->count(
    'SELECT COUNT(*) FROM users WHERE LENGTH(first_name) = :n',
    ['n' => 4]
);
check('مقایسهٔ نتیجهٔ تابع با عدد کار می‌کند', $len === 2, "count={$len}");

// ------------------------------------------------------------------
echo "\n▶ عددِ اعشاری و null\n";
// ------------------------------------------------------------------

$dbx->run(
    "INSERT INTO users (telegram_id, first_name, wallet_balance, created_at, updated_at)
     VALUES (7004, 'Float', 12.75, ?, ?)",
    [$stamp, $stamp]
);

// ۱۲٫۷۵ > ۱۲ درست است؛ سه کیف پولِ ۵۰۰ هم. یعنی هر چهار ردیف.
$float = $dbx->count('SELECT COUNT(*) FROM users WHERE wallet_balance > :min', ['min' => 12]);
check('عددِ اعشاری در دیتابیس با آستانهٔ عددی درست مقایسه می‌شود', $float === 4,
    "count={$float}");

// و آستانه‌ای که باید فقط ۱۲٫۷۵ را رد کند، نه ۵۰۰ها را.
$float2 = $dbx->count('SELECT COUNT(*) FROM users WHERE wallet_balance BETWEEN :lo AND :hi', ['lo' => 12, 'hi' => 13]);
check('بازهٔ عددی روی ستونِ اعشاری درست کار می‌کند', $float2 === 1, "count={$float2}");

$nullRow = $dbx->first('SELECT referee_user_id FROM referrals WHERE referee_user_id = :rid', ['rid' => null]);
check('پارامتر null قابل bind است', $nullRow === null);

// ⚠️ روی `referrals` شمرده نمی‌شود چون جدول خالی است و `COUNT(*)` صفر می‌دهد —
// که ربطی به bind ندارد. `users` پر است، پس مبنای درستی است.
$nullEq = $dbx->count('SELECT COUNT(*) FROM users WHERE :p IS NULL', ['p' => null]);
check('مقایسه با پارامتر null کار می‌کند', $nullEq === 4, "count={$nullEq}");

$nullNe = $dbx->count('SELECT COUNT(*) FROM users WHERE :p IS NOT NULL', ['p' => 5]);
check('و پارامترِ غیرِ null هم درست تشخیص داده می‌شود', $nullNe === 4, "count={$nullNe}");

// ------------------------------------------------------------------
echo "\n▶ پارامترِ جایگزینی باید هنوز کار کند\n";
// ------------------------------------------------------------------
//
// ستون‌های متنیِ کاربرپسنده (کد سفارش، نام) با پارامترِ عددی هم باید درست
// باشند، چون affinity ستونِ TEXT عدد را به رشته تبدیل می‌کند.
$byText = $dbx->first(
    'SELECT telegram_id FROM users WHERE first_name = :name',
    ['name' => 'Text']
);
check('متن با پارامترِ رشته‌ای پیدا می‌شود', (int) ($byText['telegram_id'] ?? 0) === 7002,
    json_encode($byText));

$numAsText = $dbx->count('SELECT COUNT(*) FROM users WHERE telegram_id = :id', ['id' => '7002']);
check('ستونِ عددی با پارامترِ رشته‌ای هم درست است (به‌خاطر affinity)',
    $numAsText === 1, "count={$numAsText}");

// ------------------------------------------------------------------
echo "\n▶ بازنویسی واقعی: آیا رفتارِ ذخیره‌سازی سالم است؟\n";
// ------------------------------------------------------------------
//
// bind دستی نباید چیزی را خراب کند. یک مسیر واقعیِ نوشتن/خواندن.
$orders = new OrderRepository();
$pkgs   = new PackageRepository();

$pkgId = $pkgs->create([
    'title' => 'بستهٔ تستِ bind', 'kind' => PackageRepository::KIND_AGENCY,
    'volume_gb' => 10, 'duration_days' => 30, 'price_toman' => 450000, 'is_active' => 1,
]);

// ⚠️ `package_title` و `kind` ستون‌های `NOT NULL` هستند که خودِ
// `OrderRepository::create()` پرشان نمی‌کند — لایهٔ بالاتر (Kernel/Api) این
// کار را می‌کند. فراموشی‌شان یک `PDOException` می‌دهد که **کلِ فایل** را
// می‌کُشد، نه فقط یک assertion.
$order = $orders->create($uid(7001), [
    'user_id' => $uid(7001), 'package_id' => $pkgId, 'code' => 'BIND-TEST-1',
    'package_title' => 'بستهٔ تستِ bind', 'kind' => PackageRepository::KIND_AGENCY,
    'status' => OrderRepository::STATUS_CREATED,
    'price_toman' => 450000, 'original_price_toman' => 450000, 'discount_toman' => 0,
]);

$read = $orders->find((int) $order['id']);
check('سفارشِ نوشته‌شده درست خوانده می‌شود',
    $read !== null && (int) $read['price_toman'] === 450000,
    json_encode($read, JSON_UNESCAPED_UNICODE));
check('و user_id هم درست ذخیره شده', (int) $read['user_id'] === $uid(7001));

// پارامترِ رشته‌ای برای کدِ سفارش (کاربر معمولاً این‌طور می‌فرستد)
$byCode = $orders->findByCode('BIND-TEST-1');
check('جست‌وجو با کدِ رشته‌ای کار می‌کند', $byCode !== null && (int) $byCode['id'] === (int) $order['id']);

// ------------------------------------------------------------------
echo "\n" . str_repeat('─', 62) . "\n";
echo "  نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);