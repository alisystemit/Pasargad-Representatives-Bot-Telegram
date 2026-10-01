<?php

declare(strict_types=1);

/**
 * ساخت بسته‌های پیش‌فرض فروشگاه.
 *
 * استفاده: php tools/seed.php [--force]
 *   --force : بسته‌های موجود را هم به‌روزرسانی می‌کند
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این ابزار فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Store\PackageRepository;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;

$force = in_array('--force', $argv, true);

$db = Db::instance();
(new Migrator($db))->migrate();

$repo = new PackageRepository($db);

/**
 * بسته‌های پیش‌فرض.
 * قیمت‌ها به تومان هستند و از نسبت حجم/قیمت پروژه‌های مشابه گرفته شده‌اند.
 */
$defaults = [
    // ---- بسته‌های افزایش مستقیم حجم پنل ----
    [
        'title'         => '🥉 بسته برنزی',
        'kind'          => PackageRepository::KIND_PANEL_QUOTA,
        'volume_gb'     => 50,
        'duration_days' => 30,
        'price_toman'   => 350000,
        'sort_order'    => 10,
        'description'   => 'افزایش ۵۰ گیگابایت به حجم حساب پنل شما با اعتبار ۳۰ روز.',
    ],
    [
        'title'         => '🥈 بسته نقره‌ای',
        'kind'          => PackageRepository::KIND_PANEL_QUOTA,
        'volume_gb'     => 100,
        'duration_days' => 30,
        'price_toman'   => 600000,
        'sort_order'    => 20,
        'description'   => 'افزایش ۱۰۰ گیگابایت به حجم حساب پنل شما با اعتبار ۳۰ روز.',
    ],
    [
        'title'         => '🥇 بسته طلایی',
        'kind'          => PackageRepository::KIND_PANEL_QUOTA,
        'volume_gb'     => 200,
        'duration_days' => 30,
        'price_toman'   => 1100000,
        'sort_order'    => 30,
        'description'   => 'افزایش ۲۰۰ گیگابایت به حجم حساب پنل شما با اعتبار ۳۰ روز.',
    ],
    [
        'title'         => '💎 بسته الماس',
        'kind'          => PackageRepository::KIND_PANEL_QUOTA,
        'volume_gb'     => 500,
        'duration_days' => 60,
        'bonus_gb'      => 50,
        'price_toman'   => 2400000,
        'sort_order'    => 40,
        'description'   => 'افزایش ۵۰۰ گیگابایت با ۵۰ گیگابایت هدیه و اعتبار ۶۰ روز.',
    ],

    // ---- بسته‌های اعتبار ساخت کاربر ----
    [
        'title'         => '🎁 اعتبار کاربر ۵۰ گیگ',
        'kind'          => PackageRepository::KIND_USER_CREDIT,
        'volume_gb'     => 50,
        'duration_days' => 30,
        'price_toman'   => 320000,
        'sort_order'    => 110,
        'description'   => 'اعتبار ساخت یا تمدید کاربران مشتریان با ۵۰ گیگابایت.',
    ],
    [
        'title'         => '🎁 اعتبار کاربر ۲۰۰ گیگ',
        'kind'          => PackageRepository::KIND_USER_CREDIT,
        'volume_gb'     => 200,
        'duration_days' => 60,
        'bonus_gb'      => 20,
        'price_toman'   => 1150000,
        'sort_order'    => 120,
        'description'   => 'اعتبار ساخت یا تمدید کاربران با ۲۰۰ گیگابایت و ۲۰ گیگابایت هدیه.',
    ],
];

$created = 0;
$skipped = 0;
$updated = 0;

foreach ($defaults as $package) {
    $slug = slugify((string) $package['title']);
    $existing = $repo->findBySlug($slug);

    if ($existing !== null) {
        if ($force) {
            $repo->update((int) $existing['id'], $package);
            $updated++;
            echo "🔄 به‌روزرسانی: {$package['title']}\n";
        } else {
            $skipped++;
            echo "⏭  وجود دارد (برای به‌روزرسانی --force بزنید): {$package['title']}\n";
        }
        continue;
    }

    $repo->create($package);
    $created++;
    echo "✅ ساخته شد: {$package['title']}\n";
}

echo "\nخلاصه: {$created} ساخته، {$updated} به‌روزرسانی، {$skipped} رد شد\n";

function slugify(string $text): string
{
    // تبدیل عنوان فارسی به یک کلید لاتین پایدار برای جلوگیری از تکرار
    $map = [
        'بسته'       => 'package',
        'اعتبار'     => 'credit',
        'کاربر'      => 'user',
        'گیگ'        => 'gb',
        'برنزی'      => 'bronze',
        'نقره‌ای'     => 'silver',
        'نقره ای'     => 'silver',
        'طلایی'      => 'gold',
        'الماس'      => 'diamond',
        'برنزی'      => 'bronze',
    ];

    foreach ($map as $fa => $en) {
        $text = str_replace($fa, ' ' . $en . ' ', $text);
    }

    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
    $slug = trim($slug, '-');

    // اگر متن فارسی باقی ماند، از هش استفاده می‌کنیم
    if ($slug === '' || preg_match('/[\x{0600}-\x{06FF}]/u', $slug) === 1) {
        return 'pkg-' . substr(md5((string) $text), 0, 10);
    }

    return strtolower($slug);
}