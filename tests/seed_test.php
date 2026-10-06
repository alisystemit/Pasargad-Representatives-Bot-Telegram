<?php

declare(strict_types=1);

/**
 * تستِ بذرِ فروشگاه: `tools/seed.php`.
 *
 * باگی که این تست نگهبانی می‌کند:
 *
 *   ساختِ slug از عنوانِ فارسی بود و `uniqueSlug` حروف فارسی را حذف می‌کرد؛
 *   یعنی در دیتابیس «package»، «package-2»… می‌نشست، ولی seed دوباره با
 *   `slugify(title)` می‌گشت و هیچ‌وقت پیدا نمی‌کرد → هر بار اجرای نصب شش
 *   بستهٔ تکراری می‌ساخت (فروشگاه بعد از چند نصب ۱۲، ۱۸، … بسته داشت).
 *
 * دو سناریو پوشش داده می‌شود:
 *   ۱) دیتابیس تازه ← دقیقاً ۶ بسته ساخته می‌شود.
 *   ۲) دیتابیس قدیمی با slugهای «package-۲» ← با عنوان پیدا می‌شود و تکراری نمی‌سازد.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';

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

function packageCount(): int
{
    $row = \Pasargad\Support\Db::instance()->first('SELECT COUNT(*) AS c FROM packages');

    return (int) ($row['c'] ?? -1);
}

// ------------------------------------------------------------------

\Pasargad\Store\TestDb::boot();

// require می‌کنیم، نه exec؛ چون اجرای مستقیم به دیتابیس واقعی config.php
// می‌زند. گارد داخل seed.php باید مانع از اجرای خودکار شود.
require_once __DIR__ . '/../tools/seed.php';

// ------------------------------------------------------------------
echo "\n▶ اجرای اول: دیتابیس خالی باید دقیقاً ۶ بسته بسازد\n";
// ------------------------------------------------------------------

$first = pasargad_seed_packages();

check('شش بسته ساخته شد', $first['created'] === 6, json_encode($first, JSON_UNESCAPED_UNICODE));
check('هیچ‌چیز رد نشد', $first['skipped'] === 0, json_encode($first, JSON_UNESCAPED_UNICODE));
check('تعداد بسته‌ها ۶ است', packageCount() === 6, 'تعداد: ' . packageCount());

// ------------------------------------------------------------------
echo "\n▶ اجرای دوم: نباید هیچ بستهٔ تکراری بسازد\n";
// ------------------------------------------------------------------

$second = pasargad_seed_packages();

check('هیچ بستهٔ جدیدی ساخته نشد', $second['created'] === 0, json_encode($second, JSON_UNESCAPED_UNICODE));
check('شش بسته رد شد', $second['skipped'] === 6, json_encode($second, JSON_UNESCAPED_UNICODE));
check('هنوز ۶ بسته هست', packageCount() === 6, 'تعداد: ' . packageCount());

// ------------------------------------------------------------------
echo "\n▶ اجرای سوم با --force: به‌روزرسانی، نه افزودن\n";
// ------------------------------------------------------------------

$third = pasargad_seed_packages(true);

check('شش بسته به‌روزرسانی شد', $third['updated'] === 6, json_encode($third, JSON_UNESCAPED_UNICODE));
check('بسته‌ای اضافه نشد', packageCount() === 6, 'تعداد: ' . packageCount());

// ------------------------------------------------------------------
echo "\n▶ slugها باید لاتین و پایدار باشند (وگرنه دوباره پیدا نمی‌شوند)\n";
// ------------------------------------------------------------------

$db = \Pasargad\Support\Db::instance();

$slugs = array_column($db->all('SELECT slug FROM packages'), 'slug');
check('slug تکراری نیست', count($slugs) === count(array_unique($slugs)), implode('، ', $slugs));

$persian = array_filter($slugs, static fn(string $s): bool => preg_match('/[\x{0600}-\x{06FF}]/u', $s) === 1);
check('هیچ slug فارسی‌ای نمانده', $persian === [], implode('، ', $persian));

$bySlug = $db->first('SELECT id FROM packages WHERE slug = ?', ['panel-agency-bronze']);
check('slug پایدار «panel-agency-bronze» ساخته شد', $bySlug !== null, implode('، ', $slugs));

// ------------------------------------------------------------------
echo "\n▶ دیتابیس قدیمی: slugهای «package-۲» باید با عنوان پیدا شوند\n";
// ------------------------------------------------------------------

$rows = $db->all('SELECT id, title FROM packages ORDER BY id ASC');

// شبیه‌سازی خروجی نسخه‌های قدیمی: slug از عنوان فارسی ساخته می‌شد و
// حروف فارسی حذف می‌شدند → «package»، «package-2»…
foreach ($rows as $i => $row) {
    // «package» برای اولی و «package-N» برای بقیه تا حتی روی دیتابیسِ
    // خراب (پر از تکراری) هم به یکتایی slug برخورد نکنیم.
    $slug = $i === 0 ? 'package' : 'package-' . (int) $row['id'];
    $db->run('UPDATE packages SET slug = ? WHERE id = ?', [$slug, (int) $row['id']]);
}

$fourth = pasargad_seed_packages();

check('روی دیتابیس قدیمی بستهٔ جدیدی ساخته نشد', $fourth['created'] === 0, json_encode($fourth, JSON_UNESCAPED_UNICODE));
check('تعداد بسته‌ها هنوز ۶ است', packageCount() === 6, 'تعداد: ' . packageCount());

// ------------------------------------------------------------------
echo "\nنتیجه: {$passed} موفق، {$failed} ناموفق\n";
exit($failed === 0 ? 0 : 1);
