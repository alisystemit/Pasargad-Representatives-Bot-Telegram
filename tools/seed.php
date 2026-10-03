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
 *
 * دو خانواده:
 *   • `agency` — خرید پنل نمایندگی تازه (حساب اپراتور جدید ساخته می‌شود).
 *     قیمت‌ها بالاتر است چون شامل راه‌اندازی حساب می‌شود.
 *   • `topup`  — شارژ/تمدید پنل‌های موجود؛ ارزان‌تر چون حساب از قبل هست.
 *
 * قیمت‌ها به تومان‌اند و نمونه‌اند؛ حتماً قبل از فروش واقعی بازبینی‌شوند.
 *
 * نکتهٔ مهم: `max_per_user` روی ۰ (نامحدود) گذاشته شده تا هر نماینده بتواند
 * چند پنل بخرد. اگر محدودیت می‌خواهید، این عدد را در پنل مدیریت تغییر دهید.
 */
$defaults = [
    // ---- خرید پنل نمایندگی ----
    [
        'title'         => '🥉 پنل نمایندگی برنزی',
        'kind'          => PackageRepository::KIND_AGENCY,
        'volume_gb'     => 100,
        'duration_days' => 30,
        'price_toman'   => 750000,
        'sort_order'    => 10,
        'description'   => 'پنل نمایندگی تازه با نقش اپراتور، ۱۰۰ گیگابایت و اعتبار ۳۰ روز.',
    ],
    [
        'title'         => '🥈 پنل نمایندگی نقره‌ای',
        'kind'          => PackageRepository::KIND_AGENCY,
        'volume_gb'     => 300,
        'duration_days' => 30,
        'price_toman'   => 1800000,
        'sort_order'    => 20,
        'description'   => 'پنل نمایندگی با نقش اپراتور، ۳۰۰ گیگابایت و اعتبار ۳۰ روز.',
    ],
    [
        'title'         => '🥇 پنل نمایندگی طلایی',
        'kind'          => PackageRepository::KIND_AGENCY,
        'volume_gb'     => 700,
        'duration_days' => 60,
        'bonus_gb'      => 100,
        'price_toman'   => 3900000,
        'sort_order'    => 30,
        'description'   => 'پنل نمایندگی با ۷۰۰ گیگابایت (۱۰۰ گیگ هدیه) و اعتبار ۶۰ روز.',
    ],

    // ---- شارژ / تمدید پنل موجود ----
    [
        'title'         => '⚡️ شارژ ۵۰ گیگابایت',
        'kind'          => PackageRepository::KIND_TOPUP,
        'volume_gb'     => 50,
        'duration_days' => 30,
        'price_toman'   => 350000,
        'sort_order'    => 110,
        'description'   => 'افزودن ۵۰ گیگابایت و ۳۰ روز اعتبار به یکی از پنل‌های شما.',
    ],
    [
        'title'         => '⚡️ شارژ ۱۵۰ گیگابایت',
        'kind'          => PackageRepository::KIND_TOPUP,
        'volume_gb'     => 150,
        'duration_days' => 30,
        'price_toman'   => 850000,
        'sort_order'    => 120,
        'description'   => 'افزودن ۱۵۰ گیگابایت و ۳۰ روز اعتبار به یکی از پنل‌های شما.',
    ],
    [
        'title'         => '⚡️ شارژ ۵۰۰ گیگابایت',
        'kind'          => PackageRepository::KIND_TOPUP,
        'volume_gb'     => 500,
        'duration_days' => 60,
        'bonus_gb'      => 50,
        'price_toman'   => 2500000,
        'sort_order'    => 130,
        'description'   => 'افزودن ۵۰۰ گیگابایت (۵۰ گیگ هدیه) و ۶۰ روز اعتبار.',
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
        'پنل'      => 'panel',
        'نمایندگی' => 'agency',
        'بسته'     => 'package',
        'گیگابایت' => 'gb',
        'شارژ'     => 'topup',
        'برنزی'    => 'bronze',
        'نقره‌ای'   => 'silver',
        'نقره ای'  => 'silver',
        'طلایی'    => 'gold',
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