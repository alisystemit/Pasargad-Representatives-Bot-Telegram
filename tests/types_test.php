<?php

declare(strict_types=1);

/**
 * تست «نوع‌های حل‌نشده» — نگهبانی در برابر یک دسته باگِ کاملاً بی‌صدا.
 *
 * ## باگی که این تست می‌گیرد
 *
 * در `PanelCenter` سه متد با `Update $update` نوشته شده بود، ولی کلاسِ واقعی
 * در فضای‌نام `Telegram` است و `use Pasargad\Telegram\Update;` جا افتاده بود.
 *
 * نتیجه:
 *   • `php -l` سالم بود — چون خطا **نحوی** نیست.
 *   • کلاس بدون مشکل لود می‌شد — چون PHP نوع را تا زمان **صدا زدن** بررسی
 *     نمی‌کند.
 *   • هیچ تستی نمی‌مرد، چون آن سه دکمه اصلاً کلیک نمی‌شدند.
 *   • در محیط واقعی، سه دکمهٔ «حذف خودکارِ کانفیگ تست» **می‌مردند** و کاربر
 *     فقط «⚠️ خطایی رخ داد» می‌دید.
 *
 * یعنی یک امضای اشتباه، قابلیتی کامل را از کار انداخت و هیچ ابزار رایجی
 * (lint، تست، بازبینی چشمی) آن را نشان نداد.
 *
 * ## روش
 *
 * به‌جای تجزیهٔ متن، از Reflection استفاده می‌شود: هر کلاس بارگذاری و نوعِ
 * پارامترها، خروجی و خصوصیت‌ها رزولو می‌شود. هرچه رزولو نشود، **قطعاً** در
 * زمان اجرا خطا می‌دهد.
 *
 * این روش صفر خطای کاذب دارد — برخلاف بازبینی متنی که هر کلمهٔ بزرگِ داخل
 * رشته و کامنت را کلاس فرض می‌کرد.
 */

require_once __DIR__ . '/../bootstrap.php';

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
        echo '     ↳ ' . str_replace("\n", "\n       ", mb_substr($detail, 0, 400)) . "\n";
    }
}

echo "\n▶ ۱) همهٔ کلاس‌های پروژه بارگذاری می‌شوند\n";

$src     = realpath(__DIR__ . '/../src');
$classes = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

foreach ($rii as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $rel     = substr($file->getPathname(), strlen($src) + 1);
    $fqClass = 'Pasargad\\' . str_replace(['/', '.php'], ['\\', ''], $rel);

    if (!class_exists($fqClass) && !interface_exists($fqClass)) {
        check('کلاس بارگذاری شد: ' . $rel, false, $fqClass);
        continue;
    }

    $classes[] = $fqClass;
}

check('همهٔ کلاس‌ها بدون خطا بارگذاری شدند', count($classes) > 50, 'count=' . count($classes));

echo "\n▶ ۲) هر نوعِ امضا باید قابل رزول باشد\n";

$unresolved = [];
$checked    = 0;

foreach ($classes as $class) {
    $rc = new ReflectionClass($class);

    /** @return array<int, array{0:string, 1:string}> */
    $collect = static function (?ReflectionType $type, string $where) use (&$collect): array {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return [];
        }

        $name = ltrim($type->getName(), '\\');

        return in_array($name, ['self', 'static', 'parent'], true) ? [] : [[$name, $where]];
    };

    foreach ($rc->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;   // متدِ ارث‌برده‌شده، مال فایل دیگری است
        }

        $types = $collect($method->getReturnType(), 'خروجی');

        foreach ($method->getParameters() as $parameter) {
            foreach ($collect($parameter->getType(), 'پارامتر $' . $parameter->getName()) as $t) {
                $types[] = $t;
            }
        }

        foreach ($types as [$name, $where]) {
            $checked++;

            if (!class_exists($name) && !interface_exists($name)) {
                $unresolved[] = str_replace('\\', '/', substr($class, strlen('Pasargad\\')))
                    . '::' . $method->getName()
                    . '  →  ' . $name . ' (' . $where . ')';
            }
        }
    }

    foreach ($rc->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        foreach ($collect($property->getType(), 'خصوصیت $' . $property->getName()) as [$name, $where]) {
            $checked++;

            if (!class_exists($name) && !interface_exists($name)) {
                $unresolved[] = str_replace('\\', '/', substr($class, strlen('Pasargad\\')))
                    . '::$' . $property->getName() . '  →  ' . $name . ' (' . $where . ')';
            }
        }
    }
}

check('تعداد قابل‌توجهی نوع بررسی شد', $checked > 200, 'checked=' . $checked);
check('هیچ نوعِ حل‌نشده‌ای نیست', $unresolved === [],
    implode("\n", array_unique($unresolved)));

echo "\n" . str_repeat('─', 56) . "\n";
echo 'نتیجه: ' . $passed . ' موفق، ' . $failed . " ناموفق\n";
echo str_repeat('─', 56) . "\n";

exit($failed === 0 ? 0 : 1);