<?php

declare(strict_types=1);

/**
 * گزارش سلامت کامل پروژه — برای عیب‌یابی و پشتیبانی.
 *
 * استفاده: php tools/healthcheck.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این ابزار فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Payment\CardToCardGateway;
use Pasargad\Payment\NowPaymentsGateway;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Db;
use Pasargad\Support\Migrator;
use Pasargad\Support\Str;

$ok  = 0;
$bad = 0;
$warn = 0;

function line(string $status, string $label, string $detail = ''): void
{
    global $ok, $bad, $warn;

    $icons = ['ok' => '✅', 'warn' => '⚠️ ', 'bad' => '❌'];
    $icon  = $icons[$status] ?? '•';

    if ($status === 'ok') {
        $ok++;
    } elseif ($status === 'warn') {
        $warn++;
    } else {
        $bad++;
    }

    echo $icon . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}

echo PHP_EOL . "════════════════════════════════════════" . PHP_EOL;
echo "  گزارش سلامت ربات نمایندگان پنل 💚";
echo PHP_EOL . "════════════════════════════════════════" . PHP_EOL . PHP_EOL;

// ------------------------------------------------------------------
echo "▶ تنظیمات\n";
// ------------------------------------------------------------------

$token = Config::str('bot_token');
line($token !== '' && $token !== 'PUT_BOT_TOKEN_HERE' ? 'ok' : 'bad', 'توکن ربات', $token !== '' ? mb_substr($token, 0, 12) . '…' : 'تنظیم نشده');

$admins = Config::arr('super_admins');
line($admins !== [] ? 'ok' : 'bad', 'سوپرادمین‌ها', count($admins) . ' عدد');

$baseUrl = Config::str('base_url');
line($baseUrl !== '' && str_starts_with($baseUrl, 'https') ? 'ok' : 'warn', 'آدرس پروژه', $baseUrl !== '' ? $baseUrl : 'تنظیم نشده (SSL لازم است)');

// نکته: bot.php روی توکن تنظیم‌نشده یا مقدار نمونه «fail closed» می‌کند و ۴۰۳
// می‌دهد. پس این مورد خطاست نه هشدار — تا وقتی درست نشود، ربات اصلاً کار نمی‌کند.
$secret = Config::str('webhook_secret');

if ($secret === '' || $secret === 'CHANGE-THIS-RANDOM-SECRET') {
    line('bad', 'توکن امنیتی وبهوک', $secret === ''
        ? 'تنظیم نشده — وبهوک همهٔ درخواست‌ها را رد می‌کند (۴۰۳)'
        : 'هنوز مقدار نمونه است — وبهوک همهٔ درخواست‌ها را رد می‌کند (۴۰۳)');
} else {
    line('ok', 'توکن امنیتی وبهوک', 'تنظیم شده');
}

try {
    Crypto::available();
    $roundtrip = Crypto::decrypt(Crypto::encrypt('test')) === 'test';
    line($roundtrip ? 'ok' : 'bad', 'رمزنگاری رمز پنل', $roundtrip ? 'libsodium' : 'خراب');
} catch (\Throwable $e) {
    line('bad', 'رمزنگاری رمز پنل', $e->getMessage());
}

$cryptoKey = Config::str('crypto_key');
line($cryptoKey !== '' && $cryptoKey !== 'CHANGE-THIS-TO-A-LONG-RANDOM-STRING-32+CHARS' ? 'ok' : 'warn',
    'کلید رمزنگاری', $cryptoKey !== '' && strlen($cryptoKey) >= 32 ? 'طول مناسب' : 'کوتاه یا پیش‌فرض');

// ------------------------------------------------------------------
echo PHP_EOL . "▶ دیتابیس\n";
// ------------------------------------------------------------------

try {
    $db = Db::instance();
    line('ok', 'اتصال SQLite', Config::str('db.path'));

    $journalMode = $db->pdo()->query('PRAGMA journal_mode')->fetchColumn();
    line($journalMode === 'wal' ? 'ok' : 'warn', 'حالت journal', (string) $journalMode);

    $applied = (new Migrator($db))->appliedMigrations();
    line($applied !== [] ? 'ok' : 'bad', 'مایگریشن‌ها', implode(', ', $applied));

    $orders  = new OrderRepository($db);
    $users   = new UserRepository($db);
    $panels  = new PanelRepository($db);
    $packages = new PackageRepository($db);

    line('ok', 'تعداد کاربران', (string) $users->countAll());
    line('ok', 'تعداد پنل‌های نمایندگی', (string) $panels->countAll());
    line('ok', 'تعداد نمایندگان', (string) count($users->listLinkedAdmins()));
    line('ok', 'تعداد بسته‌های فعال', (string) count($packages->activePackages()));
    line('ok', 'تعداد سفارش‌ها', (string) $orders->countAll());

    $pendingApply = count($orders->pendingApply(50));
    line($pendingApply === 0 ? 'ok' : 'warn', 'صف اجرای بسته', $pendingApply === 0 ? 'خالی' : "{$pendingApply} مورد در انتظار");

    $awaiting = $orders->countAwaitingReview();
    line($awaiting === 0 ? 'ok' : 'warn', 'رسیدهای در انتظار تأیید', (string) $awaiting);

    $failed = $orders->countAll(OrderRepository::STATUS_FAILED);
    line($failed === 0 ? 'ok' : 'warn', 'سفارش‌های ناموفق', (string) $failed);

    // پنل‌های منقضی که کاربرانشان هنوز فعال‌اند = درآمد از دست رفته.
    $expiredPending = 0;
    foreach ($panels->listAll(200) as $row) {
        if (PanelRepository::isExpired($row) && $row['cutoff_done_at'] === null) {
            $expiredPending++;
        }
    }

    line($expiredPending === 0 ? 'ok' : 'warn', 'پنل‌های منقضی بدون قطع دسترسی',
        $expiredPending === 0 ? 'ندارد' : "{$expiredPending} پنل — از پنل مدیریت اقدام کنید");
} catch (\Throwable $e) {
    line('bad', 'دیتابیس', $e->getMessage());
}

// ------------------------------------------------------------------
echo PHP_EOL . "▶ پنل\n";
// ------------------------------------------------------------------

$panelUrl = Config::str('panel.base_url');
line($panelUrl !== '' ? 'ok' : 'bad', 'آدرس پنل', $panelUrl);

try {
    $panel  = new PasarGuardClient();
    $health = $panel->health();
    line('ok', 'دسترسی به پنل', 'سرویس پاسخ داد: ' . json_encode($health, JSON_UNESCAPED_UNICODE));
} catch (\Throwable $e) {
    line('bad', 'دسترسی به پنل', $e->getMessage());
}

$ownerUser = Config::str('panel.owner_username');
$ownerPass = Config::str('panel.owner_password');
if ($ownerUser !== '' && $ownerPass !== '') {
    try {
        $panel  = new PasarGuardClient();
        $result = $panel->testConnection($ownerUser, $ownerPass);
        line($result['ok'] ? 'ok' : 'warn', 'ورود به پنل با حساب سازنده', $result['message']);
    } catch (\Throwable $e) {
        line('warn', 'ورود به پنل با حساب سازنده', $e->getMessage());
    }
} else {
    // این فقط یک هشدار نیست: بدون این اکانت، بستهٔ «پنل نمایندگی» قابل فروش
    // نیست چون ربات نمی‌تواند حساب اپراتور جدید بسازد.
    line('bad', 'حساب سازندهٔ پنل',
        'panel.owner_username/password تنظیم نشده — خرید پنل نمایندگی غیرفعال است');
}

// ------------------------------------------------------------------
echo PHP_EOL . "▶ پرداخت\n";
// ------------------------------------------------------------------

$card = new CardToCardGateway();
line($card->isEnabled() ? 'ok' : 'warn', 'کارت‌به‌کارت (کانفیگ)',
    $card->isEnabled() ? Config::str('store.card_number') : 'تنظیم نشده');

$np = new NowPaymentsGateway();
line($np->isEnabled() ? 'ok' : 'warn', 'ارز دیجیتال (کانفیگ)',
    $np->isEnabled() ? 'NOWPayments فعال' : 'تنظیم نشده');

if ($np->isEnabled()) {
    line(Config::str('nowpayments.ipn_secret') !== '' ? 'ok' : 'bad',
        'کلید IPN', Config::str('nowpayments.ipn_secret') !== '' ? 'تنظیم شده' : 'بدون این کلید IPN تأیید نمی‌شود');

    // نرخ تبدیل باید معنادار باشد؛ نرخ صفر یا منفی یعنی محاسبهٔ مبلغ دلاری
    // خراب می‌شود و همهٔ سفارش‌ها به حداقل ۱ دلار می‌خورند.
    $rate = Config::float('store.toman_per_usd', 0.0);
    line($rate > 0 ? 'ok' : 'bad', 'نرخ تومان/دلار',
        $rate > 0 ? (string) (int) $rate : 'store.toman_per_usd نامعتبر است');

    // اگر حداقل پرداخت درگاه از کمینهٔ سفارش بیشتر باشد، هر سفارش کوچک
    // گران‌تر از قیمت اعلام‌شده خواهد بود.
    $minOrderToman = Config::int('store.min_order_toman', 50000);
    $minUsd        = (float) Config::str('nowpayments.min_amount_usd', '1');
    $minUsdToman   = (int) ceil($minUsd * max(1.0, $rate));

    line($minOrderToman >= $minUsdToman ? 'ok' : 'warn', 'حداقل سفارش در برابر حداقل درگاه',
        $minOrderToman >= $minUsdToman
            ? 'هم‌خوان است'
            : ('کمینهٔ سفارش ' . $minOrderToman . ' تومان کمتر از حداقل درگاه ' . $minUsdToman . ' تومان است'));
}

// ------------------------------------------------------------------
echo PHP_EOL . "▶ سوییچ‌های پنل مدیریت\n";
// ------------------------------------------------------------------

try {
    $flags = new \Pasargad\Store\FeatureFlags(new Settings(Db::instance()));

    line($flags->isBotEnabled() ? 'ok' : 'warn', 'کل ربات',
        $flags->isBotEnabled() ? 'فعال' : '⚠️ غیرفعال — کاربران پیام تعیین‌شده را می‌بینند');

    foreach ($flags->gatewayStatuses() as $name => $status) {
        $label = match ($name) {
            'card2card'   => 'سوییچ کارت‌به‌کارت',
            'autocard'    => 'سوییچ کارت‌به‌کارت خودکار',
            default       => 'سوییچ ارز دیجیتال',
        };
        $note  = $status['enabled'] ? 'فعال' : 'غیرفعال';

        if (!$status['configured']) {
            $note .= ' (در کانفیگ پیکربندی نشده)';
        }

        line($status['enabled'] ? 'ok' : 'warn', $label, $note);
    }

    line($flags->isRenewalEnabled() ? 'ok' : 'warn', 'تمدید',
        $flags->isRenewalEnabled() ? 'فعال' : 'غیرفعال');

    line($flags->isPanelSyncEnabled() ? 'ok' : 'warn', 'همگام‌سازی خودکار پنل‌ها',
        $flags->isPanelSyncEnabled() ? 'فعال' : 'غیرفعال');

    line($flags->isTestConfigEnabled() ? 'ok' : 'warn', 'دریافت تست کانفیگ',
        $flags->isTestConfigEnabled() ? 'فعال' : 'غیرفعال');

    line($flags->isCutoffOnExpireEnabled() ? 'ok' : 'warn', 'قطع دسترسی پس از انقضا',
        $flags->isCutoffOnExpireEnabled() ? 'فعال' : 'غیرفعال');

    line($flags->isChannelEnforced() ? 'ok' : 'warn', 'عضویت اجباری کانال',
        $flags->isChannelEnforced() ? 'اجباری' : 'غیرفعال');

    $notice = $flags->disabledNotice();
    line('ok', 'متن غیرفعالی', mb_strlen($notice) . ' کاراکتر');
} catch (\Throwable $e) {
    line('warn', 'سوییچ‌ها', $e->getMessage());
}

// ------------------------------------------------------------------
echo PHP_EOL . "▶ وبهوک\n";
// ------------------------------------------------------------------

$botPhp     = realpath(__DIR__ . '/../bot.php');
$adminPhp   = realpath(__DIR__ . '/../admin.php');
$invoicePhp = realpath(__DIR__ . '/../invoice.php');
$ipnPhp     = realpath(__DIR__ . '/../nowpayments_ipn.php');

line($botPhp !== false ? 'ok' : 'bad', 'فایل وبهوک موجود', $botPhp !== false ? $botPhp : 'یافت نشد');
line($ipnPhp !== false ? 'ok' : 'bad', 'فایل IPN موجود', $ipnPhp !== false ? $ipnPhp : 'یافت نشد');
line($invoicePhp !== false ? 'ok' : 'bad', 'صفحهٔ فاکتور موجود', $invoicePhp !== false ? $invoicePhp : 'یافت نشد');

if ($baseUrl !== '') {
    $expectedHook = rtrim($baseUrl, '/') . '/bot.php';
    $expectedIpn  = rtrim($baseUrl, '/') . '/nowpayments_ipn.php';
    line('ok', 'آدرس وبهوک', $expectedHook);
    line('ok', 'آدرس IPN', $expectedIpn);
    line('ok', 'آدرس فاکتور', rtrim($baseUrl, '/') . '/invoice.php?t=<token>');
}

// ------------------------------------------------------------------
// ربات مدیریتی جدا (اختیاری)
//
// سه وضعیت ممکن است و هر سه سالم‌اند:
//   • خالی  → قابلیت غیرفعال است (حالت پیش‌فرض)
//   • توکن هست ولی secret نه → تنظیم ناقص: admin.php ۵۰۳ می‌دهد
//   • هر دو هست → آمادهٔ `set-webhook-admin`
// ------------------------------------------------------------------

$adminToken = trim(Config::str('admin_bot_token', ''));

if ($adminToken === '') {
    line('ok', 'ربات مدیریتی', 'غیرفعال (اختیاری — admin_bot_token خالی است)');
} else {
    $adminSecret = trim(Config::str('admin_webhook_secret', ''));

    $secretOk = $adminSecret !== '' && $adminSecret !== 'CHANGE-THIS-RANDOM-SECRET';

    line($secretOk ? 'ok' : 'bad', 'ربات مدیریتی', $secretOk
        ? 'آماده — php tools/cli.php set-webhook-admin'
        : 'admin_webhook_secret تنظیم نشده — admin.php همهٔ درخواست‌ها را رد می‌کند (۵۰۳)');

    line($adminPhp !== false ? 'ok' : 'bad', 'فایل admin.php', $adminPhp !== false ? $adminPhp : 'یافت نشد');
}

// ------------------------------------------------------------------
echo PHP_EOL . "▶ بکاپ\n";
// ------------------------------------------------------------------

try {
    $keep  = \Pasargad\Support\Backup::keepCount();
    $files = \Pasargad\Support\Backup::list();
    $size  = 0;

    foreach ($files as $file) {
        $size += (int) @filesize($file);
    }

    line($files !== [] ? 'ok' : 'warn', 'نسخه‌های بکاپ',
        count($files) . ' فایل • ' . Str::formatBytes($size) . ' • سقف ' . $keep);

    if ($files !== [] && $files === array_slice($files, 0, $keep)) {
        line('ok', 'سیاست نگهداری', 'سقف رعایت شده');
    }
} catch (\Throwable $e) {
    line('warn', 'بکاپ', $e->getMessage());
}

// ------------------------------------------------------------------
echo PHP_EOL . "▶ کرون\n";
// ------------------------------------------------------------------

$worker = realpath(__DIR__ . '/../cron/worker.php');
line($worker !== false ? 'ok' : 'bad', 'اسکریپت worker', $worker !== false ? $worker : 'یافت نشد');

// ------------------------------------------------------------------
echo PHP_EOL . "▶ تنظیمات فروشگاه\n";
// ------------------------------------------------------------------

try {
    $settings = new Settings(Db::instance());
    line($settings->bool(Settings::SHOP_OPENED, true) ? 'ok' : 'warn', 'فروشگاه',
        $settings->bool(Settings::SHOP_OPENED, true) ? 'باز' : 'بسته');
    line('ok', 'اجرای خودکار', $settings->bool(Settings::AUTO_APPLY, true) ? 'فعال' : 'غیرفعال');
} catch (\Throwable $e) {
    line('warn', 'تنظیمات فروشگاه', $e->getMessage());
}

// ------------------------------------------------------------------
echo PHP_EOL . "════════════════════════════════════════" . PHP_EOL;
echo "  {$ok} موفق • {$warn} هشدار • {$bad} خطا";
echo PHP_EOL . "════════════════════════════════════════" . PHP_EOL . PHP_EOL;

exit($bad === 0 ? 0 : 1);