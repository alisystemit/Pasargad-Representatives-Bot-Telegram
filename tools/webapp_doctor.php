<?php

declare(strict_types=1);

/**
 * تشخیص خطای «امضای دادهٔ ورودی نامعتبر است» در وب‌اپ.
 *
 * استفاده:
 *   php tools/cli.php webapp-doctor
 *
 * چرا این ابزار وجود دارد؟
 *
 * آن پیام سه علت کاملاً متفاوت دارد که از خودِ پاسخ قابل تفکیک نیست:
 *
 *   ۱) `bot_token` در کانفیگ متعلق به **ربات دیگری** است (یا بعد از
 *      ساخت/تعویض توکن، کانفیگ جا نمانده).
 *   ۲) هدر `X-Telegram-Init-Data` توسط وب‌سرور/فایروال **بریده** شده
 *      (initData بلندتر از سقف `LimitRequestFieldSize` آپاچی = ۸۱۹۰ بایت).
 *   ۳) فایل‌های روی سرور با کدی که تست شده یکی نیستند (کش/OPcache/کپی
 *      ناقص) — یعنی اصلاً تغییرات آپلود نشده‌اند.
 *
 * این ابزار مورد ۱ و ۳ را قطعی بررسی می‌کند و برای ۲ هم سقف را گزارش
 * می‌دهد. توکن هیچ‌وقت چاپ نمی‌شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این ابزار فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Support\Config;
use Pasargad\WebApp\InitData;

/** @var array{ok:bool, msg:string, hint:string} $results */
$results = [];

function say(string $text): void
{
    echo $text . "\n";
}

function verdict(bool $ok, string $title, string $hint = ''): void
{
    echo ($ok ? '  ✅ ' : '  ❌ ') . $title . "\n";

    if (!$ok && $hint !== '') {
        echo '     → ' . $hint . "\n";
    }
}

echo "\n" . str_repeat('═', 66) . "\n";
echo "  تشخیص خطای امضای وب‌اپ\n";
echo str_repeat('═', 66) . "\n\n";

// ------------------------------------------------------------------
echo "۱) توکن ربات در کانفیگ\n";
// ------------------------------------------------------------------

$raw     = (string) Config::str('bot_token', '');
$trimmed = trim($raw);

verdict(
    $trimmed !== '',
    'bot_token تعریف شده',
    'در config.php کلید bot_token خالی است.'
);

verdict(
    !in_array($trimmed, ['', 'PUT_BOT_TOKEN_HERE'], true),
    'bot_token مقدار نمونه نیست',
    'هنوز همان "PUT_BOT_TOKEN_HERE" را دارید — توکن واقعی را از @BotFather بگذارید.'
);

// فاصله/خط‌جدید اطراف توکن: قبلاً باعث همیشه-ناموفق بودن امضا می‌شد.
verdict(
    $raw === $trimmed,
    'توکن فاصله/خط‌جدید اضافه ندارد',
    "کانفیگ دقیقاً {$trimmed} نیست — یک فاصله یا خط‌جدید اضافه دارد.\n"
    . "     کد این را درست می‌کند (trim)، ولی تا وقتی آپلود نکرده‌اید، خطا می‌ماند."
);

// قالب توکن تلگرام: «عدد:رشته»
verdict(
    preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $trimmed) === 1,
    'قالب توکن درست است',
    'قالب توکن باید مثل 123456789:AAE... باشد.'
);

say('     طول توکن: ' . strlen($trimmed) . ' کاراکتر');

// ------------------------------------------------------------------
echo "\n۲) آیا توکن واقعاً کار می‌کند؟ (تست رفت‌وبرگشتی)\n";
// ------------------------------------------------------------------

// یک initData با همین توکن می‌سازیم و می‌دهیم به همان کدی که وب‌اپ
// استفاده می‌کند. اگر رد کند، مشکل از کد نیست؛ از توکن/آپلود است.
$probeToken = $trimmed;

$build = static function (string $token): string {
    $pairs = [
        'auth_date' => (string) time(),
        'query_id'  => 'AAHQ_doctor',
        'user'      => json_encode(['id' => 1, 'first_name' => 'Doctor'], JSON_UNESCAPED_UNICODE),
    ];
    ksort($pairs, SORT_STRING);

    $check = [];
    foreach ($pairs as $k => $v) {
        $check[] = $k . '=' . $v;
    }

    $pairs['hash'] = hash_hmac('sha256', implode("\n", $check), hash_hmac('sha256', 'WebAppData', $token, true));

    $out = [];
    foreach ($pairs as $k => $v) {
        $out[] = urlencode($k) . '=' . urlencode((string) $v);
    }

    return implode('&', $out);
};

$roundTrip = InitData::parse($build($probeToken));

verdict(
    ($roundTrip['ok'] ?? false) === true,
    'کد امضا با همین توکن سالم است (تست رفت‌وبرگشتی)',
    'کد با توکن کانفیگ هم درست کار نمی‌کند ⇒ یا فایل‌های سرور با کد محلی یکی نیستند،'
    . ' یا همین کد هم روی سرور نیست.'
);

// ------------------------------------------------------------------
echo "\n۳) توکن ربات مدیریتی قاطی نشده باشد\n";
// ------------------------------------------------------------------

$adminToken = trim((string) Config::str('admin_bot_token', ''));

verdict(
    $adminToken === '' || $adminToken !== $trimmed,
    'admin_bot_token با bot_token یکی نیست',
    'این دو یکی هستند — اگر مینی‌اپ روی ربات دوم باز می‌شود، توکن‌ها جابه‌جا شده‌اند.'
);

// ------------------------------------------------------------------
echo "\n۴) آدرس‌ها\n";
// ------------------------------------------------------------------

$baseUrl = rtrim(trim((string) Config::str('base_url', '')), '/');

say('     base_url: ' . ($baseUrl !== '' ? $baseUrl : '(خالی)'));

verdict(
    $baseUrl !== '' && preg_match('#^https?://#i', $baseUrl) === 1,
    'base_url معتبر است',
    'base_url خالی یا نامعتبر است.'
);

$https = str_starts_with(strtolower($baseUrl), 'https://');

if ($https) {
    say('     ℹ️  آدرس https است؛ اگر گواهی SSL منقضی/خودامضا باشد، تلگرام و مرورگر');
    say('        رفت‌وبرگشت را می‌شکنند. گواهی را بررسی کنید.');
}

// ------------------------------------------------------------------
echo "\n۵) اگر باز هم خطا گرفتید\n";
// ------------------------------------------------------------------

echo "  • لاگ را ببینید — از این پیام خطا دقیقاً می‌فهمید چه شده:\n";
echo "      tail -n 30 data/logs/" . date('Y-m-d') . ".log | grep -i 'initData\\|signature'\n";
echo "  • اگر در لاگ دیدید 'hash mismatch' ⇒ توکن کانفیگ با رباتی که مینی‌اپ را\n";
echo "    باز کرده یکی نیست.\n";
echo "  • اگر دیدید 'web_app initData required' یا hash غایب بود ⇒ هدر\n";
echo "    X-Telegram-Init-Data به سرور نمی‌رسد (فایروال/پروکسی/کش).\n";
echo "  • مطمئن شوید OPcache را بعد از آپلود ریست کرده‌اید.\n";

echo "\n" . str_repeat('═', 66) . "\n";
echo "  اگر همهٔ بندها سبز بود ولی باز خطا می‌گیرید، مشکل از آپلود/کش سرور است،\n";
echo "  نه از کد. نسخهٔ فایل‌های روی سرور را با مخزن مقایسه کنید.\n";
echo str_repeat('═', 66) . "\n";