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

use Pasargad\Support\BaseUrl;
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
say('     اثرانگشت توکن: ' . Pasargad\WebApp\AuthDiagnostics::tokenFingerprint($trimmed));

echo "\n     ℹ️ اثرانگشت را یادداشت کنید. اگر در لاگ سرور اثرانگشتِ دیگری\n";
echo "        دیدید، یعنی سرور توکن دیگری دارد ⇒ مشکل از توکن است، نه از کد.\n";

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

    $pairs['hash'] = hash_hmac('sha256', implode("\n", $check), hash_hmac('sha256', $token, 'WebAppData', true));

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
echo "\n۴) این توکن مال کدام ربات است؟ (قطعی‌ترین تست)\n";
// ------------------------------------------------------------------
//
// HMAC فقط وقتی می‌خواند که توکن کانفیگ **دقیقاً** متعلق به همان رباتی باشد که
// مینی‌اپ را باز کرده. اینجا نام ربات را از خودِ تلگرام می‌پرسیم.

echo "  … در حال پرسیدن از تلگرام (getMe)\n";

try {
    $me = (new Pasargad\Telegram\BotApi($trimmed))->call('getMe');
    $ok = (bool) ($me['ok'] ?? false);
} catch (\Throwable $e) {
    $me = ['ok' => false, 'description' => $e->getMessage()];
    $ok = false;
}

if ($ok) {
    $username = (string) ($me['result']['username'] ?? '');
    $botId    = (int) ($me['result']['id'] ?? 0);

    verdict(true, 'توکن نزد تلگرام معتبر است');
    echo '     🆔 bot id: ' . $botId . "\n";
    echo '     🤖 نام ربات: @' . $username . "\n\n";

    $configuredName = ltrim(trim((string) Config::str('bot_username', '')), '@');

    if ($configuredName !== '' && $configuredName !== $username) {
        verdict(
            false,
            'bot_username کانفیگ با این توکن هم‌خوان است',
            "bot_username کانفیگ «{$configuredName}» است ولی این توکن مال «{$username}» است."
        );
    } else {
        verdict(true, 'bot_username کانفیگ با این توکن هم‌خوان است');
    }

    echo "\n     ⚠️  حالا در تلگرام ببینید مینی‌اپ از کدام ربات باز می‌شود.\n";
    echo "        اگر آن ربات «@{$username}» نیست ⇒ مشکل همین است: آدرس وب‌اپ\n";
    echo "        روی یک ربات ثبت شده ولی config.php توکن ربات دیگری دارد.\n";
} else {
    $code = (int) ($me['error_code'] ?? 0);
    $desc = (string) ($me['description'] ?? 'خطای نامشخص');

    verdict(
        $code === 0,
        'تلگرام در دسترس است',
        "خطای شبکه: {$desc} — بدون دسترسی به اینترنت نمی‌شود مطمئن شد."
    );

    verdict(
        false,
        'توکن نزد تلگرام معتبر است',
        "تلگرام توکن را رد کرد ({$code}): {$desc} — توکن اشتباه یا منقضی است."
    );
}

// ------------------------------------------------------------------
echo "\n۵) آدرس‌ها\n";
// ------------------------------------------------------------------

$configuredBase = rtrim(trim((string) Config::str('base_url', '')), '/');
$baseUrl        = BaseUrl::current();

say('     base_url در config: ' . ($configuredBase !== '' ? $configuredBase : '(تنظیم نشده)'));
say('     آدرس مؤثر:         ' . ($baseUrl !== '' ? $baseUrl : '(قابل تشخیص نیست)'));
say('     منبع آدرس:         ' . match (BaseUrl::source()) {
    'config' => 'config.php',
    'server' => '⚠️ تشخیص خودکار از متغیرهای سرور (چون در config نیست)',
    default  => 'هیچ‌کدام',
});

verdict(
    $baseUrl !== '' && preg_match('#^https?://#i', $baseUrl) === 1,
    'آدرس پایه قابل استفاده است',
    'هیچ آدرس پایه‌ای نه در config هست نه از هدر قابل تشخیص. '
        . 'در CLI معمولاً هیچ هدری وجود ندارد، پس این پیام در سرور طبیعی است — '
        . 'ولی اگر در وب هم دکمهٔ اپلیکیشن نمی‌آید، base_url را در config.php بگذارید.'
);

if (BaseUrl::source() === 'server') {
    say('     ℹ️  تنظیم نکردن base_url کار می‌کند ولی شکننده است: اگر ربات');
    say('        پشت پروکسی عوض شود یا هدر Host را چیزی بفرستد، آدرس');
    say('        تغییر می‌کند. یک‌بار در config.php ثابت کنید.');
}

$https = str_starts_with(strtolower($baseUrl), 'https://');

if ($https) {
    say('     ℹ️  آدرس https است؛ اگر گواهی SSL منقضی/خودامضا باشد، تلگرام و مرورگر');
    say('        رفت‌وبرگشت را می‌شکنند. گواهی را بررسی کنید.');
}

// ------------------------------------------------------------------
echo "\n۶) اگر باز هم خطا گرفتید\n";
// ------------------------------------------------------------------

echo "  • لاگ را ببینید — هر شکست یک خط با `reason` و `fix` می‌نویسد:\n";
echo "      grep 'webapp.auth' data/logs/" . date('Y-m-d') . ".log | tail -n 20\n\n";
echo "    معنی reasonها:\n";
echo "      no_header      ⇒ هدر X-Telegram-Init-Data به PHP نرسید (فایروال/پروکسی)\n";
echo "      empty          ⇒ هدر رسید ولی خالی بود (اپ بیرون از تلگرام باز شده)\n";
echo "      too_long       ⇒ initData بریده شده (سقف ۸۱۹۰ بایت آپاچی)\n";
echo "      token_placeholder / token_format ⇒ مشکل از bot_token کانفیگ\n";
echo "      no_hash / hash_format ⇒ امضا اصلاً نرسده یا بریده شده\n";
echo "      hash_mismatch  ⇒ توکن کانفیگ با رباتی که مینی‌اپ را باز کرده یکی نیست\n";
echo "                       (یا کد روی سرور با این کد فرق دارد)\n";
echo "      expired        ⇒ initData قدیمی است؛ اپ را ببندید و دوباره باز کنید\n";
echo "      unverified_call⇒ باگ در مسیر احراز هویت سرور\n";
echo "\n  • اثرانگشت توکن را با لاگ مقایسه کنید (بالا چاپ شد).\n";
echo "  • مطمئن شوید OPcache را بعد از آپلود ریست کرده‌اید.\n";

echo "\n" . str_repeat('═', 66) . "\n";
echo "  اگر همهٔ بندها سبز بود ولی باز خطا می‌گیرید، مشکل از آپلود/کش سرور است،\n";
echo "  نه از کد. نسخهٔ فایل‌های روی سرور را با مخزن مقایسه کنید.\n";
echo str_repeat('═', 66) . "\n";