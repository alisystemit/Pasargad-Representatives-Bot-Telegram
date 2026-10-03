<?php

declare(strict_types=1);

/**
 * تست دروازهٔ ربات مدیریتی جدا + سیاست نگهداری بکاپ.
 *
 * این تنها جایی است که «چه کسی اجازه دارد به ربات مدیریتی پیام بدهد» تعیین
 * می‌شود. یک باگ اینجا یعنی هر کسی می‌تواند بیاید `admin.*` را صدا بزند،
 * سفارش تأیید کند، پنل نماینده‌ای را قطع کند و کیف پول خودش را شارژ کند.
 *
 * سه دروازه باید جدا آزموده شوند (و جدا گزارش شوند):
 *   ۱) پیکربندی‌نشدن ربات دوم ⇒ ۵۰۳ (نه ۴۰۳ — یعنی «قابل استفاده نیست»)
 *   ۲) توکن امنیتی غلط ⇒ ۴۰۳
 *   ۳) فرستندهٔ غیرسوپرادمین ⇒ نادیده گرفته می‌شود، نه خطا
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';

use Pasargad\Bot\AdminWebhook;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Support\Backup;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;

$db = TestDb::boot();
(new Migrator($db))->migrate();

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

/**
 * ذخیره و بازگرداندن یک کلید کانفیگ (هر تست کانفیگ را خراب نکند).
 */
function withConfig(string $key, mixed $value, callable $fn): void
{
    $previous = Config::get($key, null);
    Config::set($key, $value);

    try {
        $fn();
    } finally {
        Config::set($key, $previous);
    }
}

// =====================================================================
echo "\n▶ دروازهٔ ۱: ربات دوم پیکربندی نشده\n";
// =====================================================================

withConfig('admin_bot_token', '', static function (): void {
    check('ربات دوم پیکربندی‌نشده شناسایی شد', !AdminWebhook::isConfigured());

    $status = AdminWebhook::status();
    check('وضعیت «آماده نیست» است', $status['ready'] === false);
    check('کد ۵۰۳ برمی‌گرداند (پیکربندی ناقص است، نه حمله)', $status['status'] === 503,
        (string) $status['status']);
    check('پیام خطا دقیق است', $status['error'] === 'admin_bot_token is not configured');
});

withConfig('admin_bot_token', '123:ABC', static function (): void {
    check('ربات دوم پیکربندی‌شده شناسایی شد', AdminWebhook::isConfigured());

    $status = AdminWebhook::status();
    check('ولی secret ندارد ⇒ هنوز آماده نیست', $status['ready'] === false);
    check('و خطا دربارهٔ secret است',
        $status['error'] === 'admin_webhook_secret is not configured', $status['error']);
});

// =====================================================================
echo "\n▶ دروازهٔ ۲: توکن امنیتی\n";
// =====================================================================

withConfig('admin_bot_token', '123:ABC', static function (): void {
    withConfig('admin_webhook_secret', AdminWebhook::PLACEHOLDER_SECRET, static function (): void {
        check('مقدار نمونه «تنظیم‌نشده» حساب می‌شود', !AdminWebhook::hasValidSecret());
        check('و با آن حتی توکن درست هم رد می‌شود',
            AdminWebhook::secretMatches(AdminWebhook::PLACEHOLDER_SECRET) === false);
    });

    withConfig('admin_webhook_secret', '', static function (): void {
        check('secret خالی «تنظیم‌نشده» است', !AdminWebhook::hasValidSecret());
    });
});

withConfig('admin_bot_token', '123:ABC', static function (): void {
    withConfig('admin_webhook_secret', 'a-real-secret-value', static function (): void {
        check('secret واقعی پذیرفته می‌شود', AdminWebhook::hasValidSecret());
        check('وضعیت آماده است', AdminWebhook::status()['ready'] === true);

        check('توکن درست قبول می‌شود', AdminWebhook::secretMatches('a-real-secret-value'));
        check('توکن غلط رد می‌شود', !AdminWebhook::secretMatches('wrong'));
        check('توکن خالی رد می‌شود', !AdminWebhook::secretMatches(''));
        check('توکن null رد می‌شود', !AdminWebhook::secretMatches(null));
        check('توکن با پیشوند درست ولی ادامهٔ غلط رد می‌شود',
            !AdminWebhook::secretMatches('a-real-secret-valuE'));
        check('توکان بلندتر رد می‌شود', !AdminWebhook::secretMatches('a-real-secret-value-extra'));
    });
});

// نکتهٔ عملیاتی: اگر admin_webhook_secret عمداً برابر webhook_secret باشد،
// عملاً یک رخنه است چون هر کسی به ربات اصلی دسترسی دارد می‌تواند به ربات
// مدیریتی هم پیام بدهد. healthcheck این را یادآوری می‌کند.
withConfig('admin_bot_token', '123:ABC', static function (): void {
    withConfig('admin_webhook_secret', 'shared', static function (): void {
        withConfig('webhook_secret', 'shared', static function (): void {
            $same = Config::str('admin_webhook_secret') === Config::str('webhook_secret');
            check('تشخیص اشتراک secret دو وبهوک ممکن است', $same);
        });
    });
});

// =====================================================================
echo "\n▶ دروازهٔ ۳: فقط سوپرادمین\n";
// =====================================================================

withConfig('super_admins', [111, 222], static function (): void {
    check('اولین سوپرادمین مجاز است', AdminWebhook::allows(111));
    check('دومین سوپرادمین مجاز است', AdminWebhook::allows(222));
    check('کاربر عادی مجاز نیست', !AdminWebhook::allows(333));
    check('شناسهٔ صفر مجاز نیست', !AdminWebhook::allows(0));
    check('شناسهٔ منفی مجاز نیست', !AdminWebhook::allows(-5));
});

withConfig('super_admins', ['111', '222'], static function (): void {
    check('رشتهٔ عددی هم پذیرفته می‌شود', AdminWebhook::allows(111));
});

withConfig('super_admins', [], static function (): void {
    check('با فهرست خالی هیچ‌کس مجاز نیست', !AdminWebhook::allows(111));
});

withConfig('super_admins', ['abc'], static function (): void {
    check('ورودی نامعتبر بی‌خطر است', !AdminWebhook::allows(0));
});

// =====================================================================
echo "\n▶ سیاست نگهداری بکاپ\n";
// =====================================================================

$settings = new Settings($db);

// دقت: `Settings` یک حافظهٔ نهانِ **استاتیک** دارد، پس نوشتن مستقیم با
// SQL آن را دور می‌زند و مقدار خوانده‌شده کهنه می‌ماند. همیشه از set() استفاده کن.
check('سقف پیش‌فرض معتبر است', Backup::keepCount($settings) >= 2, (string) Backup::keepCount($settings));

$settings->set(Settings::BACKUP_KEEP, '5');
check('سقف از تنظیمات خوانده می‌شود', Backup::keepCount($settings) === 5,
    (string) Backup::keepCount($settings));

$settings->set(Settings::BACKUP_KEEP, '1');
check('سقف کمتر از ۲ به ۲ اصلاح می‌شود (نمی‌توان همهٔ نسخه‌ها را حذف کرد)',
    Backup::keepCount($settings) === 2, (string) Backup::keepCount($settings));

$settings->set(Settings::BACKUP_KEEP, '9999');
check('سقف غیرواقعی به ۲۰۰ محدود می‌شود', Backup::keepCount($settings) === 200,
    (string) Backup::keepCount($settings));

$settings->set(Settings::BACKUP_KEEP, '14');

$pruned = Backup::pruneOld($settings);
check('پاک‌سازی بدون خطا انجام شد', is_array($pruned) && isset($pruned['kept']),
    json_encode($pruned));
check('پاک‌سازی چیزی را بی‌دلیل حذف نکرد', $pruned['removed'] === 0, json_encode($pruned));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);