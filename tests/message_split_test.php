<?php

declare(strict_types=1);

/**
 * تست تقسیم پیام بلند و رعایت محدودیت‌های تلگرام.
 *
 * تلگرام متن بلندتر از ۴۰۹۶ کاراکتر را نمی‌پذیرد و خطای
 * «message is too long» می‌دهد. این تست‌ها تضمین می‌کنند هیچ پیامی
 * (آمار مدیریتی، فهرست سفارش‌ها، گزارش پخش همگانی) بی‌صدا حذف نشود.
 */

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/**
 * @param array<int, string> $chunks
 */
function allUnder(array $chunks, int $limit): bool
{
    foreach ($chunks as $c) {
        if (mb_strlen($c) > $limit) {
            return false;
        }
    }
    return true;
}

/**
 * @param array<int, string> $chunks
 */
function maxLen(array $chunks): int
{
    $max = 0;
    foreach ($chunks as $c) {
        $max = max($max, mb_strlen($c));
    }
    return $max;
}

/**
 * بررسی تعادل برچسب‌ها در کل پیام تقسیم‌شده.
 *
 * نکتهٔ مهم: یک بخش می‌تواند برچسب باز را از بخش قبل «به ارث» ببرد، پس
 * برچسب‌ها باید در کل دنباله شمرده شوند نه در هر بخش جداگانه.
 *
 * @param array<int, string> $chunks
 */
function balancedAcross(array $chunks, string $tag): bool
{
    $opens  = 0;
    $closes = 0;

    foreach ($chunks as $chunk) {
        $opens  += (int) preg_match_all('#<' . $tag . '(?:\s[^>]*)?>#i', $chunk);
        $closes += (int) preg_match_all('#</' . $tag . '>#i', $chunk);

        // هیچ بخشی نباید برچسب بازِ حل‌نشده داشته باشد.
        if ($opens !== $closes) {
            return false;
        }
    }

    return true;
}

$bot = new class extends BotApi {
    public function __construct() {}
};

$MAX = BotApi::MAX_TEXT_LENGTH;

// ------------------------------------------------------------------
echo "\n▶ ثابت‌های محدودیت\n";
// ------------------------------------------------------------------

check('سقف متن ۴۰۹۶ است', $MAX === 4096, (string) $MAX);
check('سقف کپشن ۱۰۲۴ است', BotApi::MAX_CAPTION_LENGTH === 1024);
check('سقف callback ۶۴ بایت است', BotApi::MAX_CALLBACK_BYTES === 64);
check('حاشیهٔ ایمنی مثبت است', BotApi::SPLIT_HEADROOM > 0, (string) BotApi::SPLIT_HEADROOM);

// ------------------------------------------------------------------
echo "\n▶ متن کوتاه دست‌نخورده می‌ماند\n";
// ------------------------------------------------------------------

check('متن کوتاه یک بخش است', $bot->splitText('سلام') === ['سلام']);
check('متن خالی یک بخش است', $bot->splitText('') === ['']);
check('متن دقیقاً ۴۰۹۶ یک بخش است', count($bot->splitText(str_repeat('ا', $MAX))) === 1);
check('متن ۴۰۹۷ چند بخش می‌شود', count($bot->splitText(str_repeat('ا', $MAX + 1))) > 1);

// ------------------------------------------------------------------
echo "\n▶ تقسیم متن سادهٔ بلند\n";
// ------------------------------------------------------------------

$plain = str_repeat('این یک خط آزمایشی است. ', 500);
$chunks = $bot->splitText($plain);
check('متن ساده شکسته شد', count($chunks) > 1, 'chunks=' . count($chunks));
check('هیچ بخشی از سقف رد نشد', allUnder($chunks, $MAX), 'max=' . maxLen($chunks));
check('هیچ بخشی خالی نیست', !in_array('', $chunks, true));

// ------------------------------------------------------------------
echo "\n▶ حفظ محتوا هنگام تقسیم\n";
// ------------------------------------------------------------------

$stripped = preg_replace('/\s+/u', '', implode('', $chunks));
check('محتوای متن حفظ شد', $stripped === preg_replace('/\s+/u', '', $plain),
    'got=' . mb_strlen($stripped) . ' want=' . mb_strlen(preg_replace('/\s+/u', '', $plain)));

// ------------------------------------------------------------------
echo "\n▶ حفظ برچسب‌های HTML باز\n";
// ------------------------------------------------------------------

$html = '<b>' . str_repeat('خط با تگ باز ', 400) . '</b>';
$htmlChunks = $bot->splitText($html);
check('متن HTML شکسته شد', count($htmlChunks) > 1, 'chunks=' . count($htmlChunks));
check('هیچ بخشی از سقف رد نشد', allUnder($htmlChunks, $MAX), 'max=' . maxLen($htmlChunks));

check('تگ <b> در همهٔ بخش‌ها متعادل است', balancedAcross($htmlChunks, 'b'));

// ------------------------------------------------------------------
echo "\n▶ برچسب‌های تودرتو\n";
// ------------------------------------------------------------------

$nested = '<b><i>' . str_repeat('متن تودرتو ', 700) . '</i></b>';
$nestedChunks = $bot->splitText($nested);
check('تگ <b> تودرتو متعادل است', balancedAcross($nestedChunks, 'b'));
check('تگ <i> تودرتو متعادل است', balancedAcross($nestedChunks, 'i'));
check('بخش تودرتو از سقف رد نشد', allUnder($nestedChunks, $MAX), 'max=' . maxLen($nestedChunks));

// ------------------------------------------------------------------
echo "\n▶ خط بدون فاصلهٔ بسیار بلند\n";
// ------------------------------------------------------------------

$noSpace = str_repeat('x', 9000);
$noSpaceChunks = $bot->splitText($noSpace);
check('خط بدون فاصله شکسته شد', count($noSpaceChunks) > 1, 'chunks=' . count($noSpaceChunks));
check('قطعه‌ها زیر سقض‌اند', allUnder($noSpaceChunks, $MAX), 'max=' . maxLen($noSpaceChunks));

// ------------------------------------------------------------------
echo "\n▶ توکن بلند بدون فاصله داخل متن\n";
// ------------------------------------------------------------------

$mixed = str_repeat('متن ', 100) . str_repeat('Q', 8000) . str_repeat(' پایان', 10);
$mixedChunks = $bot->splitText($mixed);
check('متن مختلط شکسته شد', count($mixedChunks) > 1, 'chunks=' . count($mixedChunks));
check('قطعه‌های مختلط زیر سقف‌اند', allUnder($mixedChunks, $MAX), 'max=' . maxLen($mixedChunks));
check('ابتدا و انتها حفظ شد',
    str_starts_with(implode('', $mixedChunks), 'متن ')
    && str_ends_with(implode('', $mixedChunks), 'پایان')
);

// ------------------------------------------------------------------
echo "\n▶ UTF-8 چندبایتی\n";
// ------------------------------------------------------------------

$multi = str_repeat('الف', 9000);
$multiChunks = $bot->splitText($multi);
$valid = true;
foreach ($multiChunks as $c) {
    if (!mb_check_encoding($c, 'UTF-8')) {
        $valid = false;
    }
}
check('کاراکتر چندبایتی وسط کلمه نشکست', $valid);
check('بخش‌های چندبایتی زیر سقف‌اند', allUnder($multiChunks, $MAX), 'max=' . maxLen($multiChunks));

// ------------------------------------------------------------------
echo "\n▶ لینک بلند\n";
// ------------------------------------------------------------------

$link = '<a href="https://example.com/' . str_repeat('x', 5000) . '">کلیک</a>';
$linkChunks = $bot->splitText($link);
check('تگ <a> متعادل ماند (' . count($linkChunks) . ' بخش)', balancedAcross($linkChunks, 'a'));
check('بخش‌های لینک زیر سقف‌اند', allUnder($linkChunks, $MAX), 'max=' . maxLen($linkChunks));

// ------------------------------------------------------------------
echo "\n▶ تعداد زیاد خط\n";
// ------------------------------------------------------------------

$manyLines = implode("\n", array_fill(0, 900, 'خط شماره'));
$manyChunks = $bot->splitText($manyLines);
check('متن چندخطی شکسته شد', count($manyChunks) > 1, 'chunks=' . count($manyChunks));
check('قطعه‌ها زیر سقف‌اند', allUnder($manyChunks, $MAX), 'max=' . maxLen($manyChunks));
check('تعداد خطوط حفظ شد',
    substr_count(implode("\n", $manyChunks), 'خط شماره') === 900,
    'lines=' . substr_count(implode("\n", $manyChunks), 'خط شماره'));

// ------------------------------------------------------------------
echo "\n▶ حد سفارشی\n";
// ------------------------------------------------------------------

$custom = $bot->splitText(str_repeat('الف ', 3000), 500);
check('سقف سفارشی رعایت شد', maxLen($custom) <= 500, 'max=' . maxLen($custom));
check('سقف خیلی کوچک هم کرش نکرد', is_array($bot->splitText(str_repeat('a', 5000), 1)));
check('متن کوتاه با سقف سفارشی یک بخش است', $bot->splitText('ok', 500) === ['ok']);

// ------------------------------------------------------------------
echo "\n▶ ویرایش پیام بلند نباید خطا بدهد\n";
// ------------------------------------------------------------------

$sent = [];
$fake = new class extends BotApi {
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function __construct() {}

    public function call(string $method, array $params = [], int $attempts = 3): array
    {
        $this->sent[] = ['method' => $method] + $params;
        return ['ok' => true, 'result' => ['message_id' => 1]];
    }
};

// sendMessage باید چند فراخوانی بکند نه یکی
$result = $fake->sendMessage(12345, '<b>' . str_repeat('متن طولانی ', 600) . '</b>', [
    'reply_markup' => ['inline_keyboard' => [[['text' => 'دکمه', 'callback_data' => 'x']]]],
]);
check('پیام بلند چند بخش ارسال شد', count($fake->sent) > 1, 'calls=' . count($fake->sent));
check('همهٔ فراخوانی‌ها sendMessage هستند',
    count(array_filter($fake->sent, static fn (array $s): bool => $s['method'] === 'sendMessage')) === count($fake->sent));

$lengthsOk = true;
foreach ($fake->sent as $call) {
    if (mb_strlen((string) $call['text']) > $MAX) {
        $lengthsOk = false;
    }
}
check('هیچ بخش ارسالی از سقف رد نشد', $lengthsOk);

// فقط آخرین بخش باید کیبورد داشته باشد
$withKb = 0;
foreach ($fake->sent as $call) {
    if (isset($call['reply_markup'])) {
        $withKb++;
    }
}
check('کیبورد فقط روی آخرین بخش است', $withKb === 1, 'withMarkup=' . $withKb);

// پیام کوتاه: یک فراخوانی
$fake2 = new class extends BotApi {
    public array $sent = [];
    public function __construct() {}
    public function call(string $method, array $params = [], int $attempts = 3): array
    {
        $this->sent[] = ['method' => $method] + $params;
        return ['ok' => true, 'result' => ['message_id' => 1]];
    }
};
$fake2->sendMessage(1, 'کوتاه', ['reply_markup' => ['inline_keyboard' => [[['text' => 'x', 'callback_data' => 'y']]]]]);
check('پیام کوتاه یک فراخوانی', count($fake2->sent) === 1, 'calls=' . count($fake2->sent));
check('کیبورد پیام کوتاه حفظ شد', isset($fake2->sent[0]['reply_markup']));

// پیام کوتاه بدون کیبورد نباید reply_markup اضافه کند
$fake2b = new class extends BotApi {
    public array $sent = [];
    public function __construct() {}
    public function call(string $method, array $params = [], int $attempts = 3): array
    {
        $this->sent[] = ['method' => $method] + $params;
        return ['ok' => true, 'result' => ['message_id' => 1]];
    }
};
$fake2b->sendMessage(1, 'بدون کیبورد');
check('پیام بدون کیبورد، reply_markup نمی‌گیرد', !isset($fake2b->sent[0]['reply_markup']));

// ------------------------------------------------------------------
echo "\n▶ بازسازی متن کامل\n";
// ------------------------------------------------------------------

$orig = '<b>عنوان</b>' . "\n" . str_repeat('متن گزارش با جزئیات. ', 300);
$split = $bot->splitText($orig);
$rejoined = implode("\n", $split);
// برچسب‌های بستهٔ مصنوعیِ تقسیم‌کننده از هر دو طرف حذف می‌شوند
$normOrig   = str_replace('</b>', '', preg_replace('/\s+/u', '', $orig));
$normJoined = str_replace('</b>', '', preg_replace('/\s+/u', '', $rejoined));
check('محتوای گزارش پس از تقسیم حفظ شد', $normJoined === $normOrig,
    'got=' . mb_strlen($normJoined) . ' want=' . mb_strlen($normOrig));

// ------------------------------------------------------------------
echo "\n▶ بریدن نباید داخل برچسب HTML اتفاق بیفتد\n";
// ------------------------------------------------------------------

// لینکی که کل آن یک «کلمه» بدون فاصله است و از سقف بلندتر است
$hugeLink = '<a href="https://example.com/' . str_repeat('x', 5000) . '">کلیک</a>';
$linkSplit = $bot->splitText($hugeLink);

check('لینک بلند شکسته شد', count($linkSplit) > 1, 'chunks=' . count($linkSplit));
check('بخش‌های لینک زیر سقف‌اند', allUnder($linkSplit, $MAX), 'max=' . maxLen($linkSplit));

// هیچ بخشی نباید برچسب نیمه‌کاره داشته باشد.
// یک برچسب ناقص یعنی «<» بدون «>» متناظر در همان بخش.
$partialTags = 0;
foreach ($linkSplit as $chunk) {
    $partialTags += substr_count($chunk, '<') - substr_count($chunk, '>');
}
check('برچسب نیمه‌کاره باقی نماند', $partialTags <= 0, 'delta=' . $partialTags);

// برچسب بازِ تنها (بدون بسته‌شدن) نباید متن را نابود کند
$unclosed = '<b>' . str_repeat('متن بدون بستن ', 800);
$unclosedSplit = $bot->splitText($unclosed);
check('برچسب باز متن بلند شکسته شد', count($unclosedSplit) > 1, 'chunks=' . count($unclosedSplit));
check('بخش‌های برچسب باز زیر سقف‌اند', allUnder($unclosedSplit, $MAX), 'max=' . maxLen($unclosedSplit));

$openOk = true;
foreach ($unclosedSplit as $chunk) {
    if (substr_count($chunk, '<b>') !== substr_count($chunk, '</b>')) {
        $openOk = false;
    }
}
check('برچسب باز در هر بخش بسته شد', $openOk);

// ------------------------------------------------------------------
echo "\n▶ تعداد زیاد برچسب باز در یک پنجره\n";
// --------------------------------------------------------------------

// ۴۰۰ برچسب باز در یک خط بلند → balanceFragment باید برچسب‌های زیادی
// ببندد و نباید از سقف سخت رد شود.
$manyTags = str_repeat('<b>متن', 900);
$manyChunks = $bot->splitText($manyTags);
check('متن با برچسب‌های زیاد شکسته شد', count($manyChunks) > 1, 'chunks=' . count($manyChunks));
check('هیچ بخشی از سقف سخت رد نشد', allUnder($manyChunks, $MAX), 'max=' . maxLen($manyChunks));
check('اندازهٔ بخش‌ها همه زیر سقف‌اند', maxLen($manyChunks) < $MAX,
    'max=' . maxLen($manyChunks) . ' limit=' . $MAX);

// ------------------------------------------------------------------
echo "\n▶ Str::truncate هرگز از سقف رد نمی‌شود\n";
// ------------------------------------------------------------------

check('truncate دقیقاً سقف را رعایت می‌کند',
    mb_strlen(Str::truncate(str_repeat('ا', 5000), $MAX)) <= $MAX,
    'len=' . mb_strlen(Str::truncate(str_repeat('ا', 5000), $MAX)));

check('truncate کوتاه‌تر از متن را دست‌نخورده می‌گذارد',
    Str::truncate('کوتاه', 100) === 'کوتاه');

check('truncate با سقف خیلی کوچک کرش نمی‌کند',
    is_string(Str::truncate(str_repeat('ا', 100), 1)));

check('truncate با سقف صفر کرش نمی‌کند',
    is_string(Str::truncate(str_repeat('ا', 100), 0)));

foreach ([10, 100, 1000, 4096] as $cap) {
    $out = Str::truncate(str_repeat('الف', $cap * 2), $cap);
    check("truncate با سقف {$cap} در محدوده ماند", mb_strlen($out) <= $cap, 'len=' . mb_strlen($out));
}

// ------------------------------------------------------------------
echo "\n▶ sendMessage هیچ بخشی را حذف نمی‌کند\n";
// ------------------------------------------------------------------

$flood = new class extends BotApi {
    public array $sent = [];
    public function __construct() {}
    public function call(string $method, array $params = [], int $attempts = 3): array
    {
        $this->sent[] = ['method' => $method] + $params;
        return ['ok' => true, 'result' => ['message_id' => 1]];
    }
};

// پیام با تعداد بسیار زیاد برچسب باز — سخت‌ترین حالت برای متعادل‌سازی
$flood->sendMessage(1, str_repeat('<b>متن', 900));

$maxSent = 0;
$allOk = true;
foreach ($flood->sent as $call) {
    $len = mb_strlen((string) $call['text']);
    $maxSent = max($maxSent, $len);

    if ($len > $MAX) {
        $allOk = false;
    }
}
check('پیام سیل‌آسا در چند بخش رفت', count($flood->sent) > 1, 'calls=' . count($flood->sent));
check('هیچ بخش ارسالی از سقف ۴۰۹۶ رد نشد', $allOk, 'maxSent=' . $maxSent);

// با کیبورد هم نباید همهٔ بخش‌ها کیبورد بگیرند
$flood2 = new class extends BotApi {
    public array $sent = [];
    public function __construct() {}
    public function call(string $method, array $params = [], int $attempts = 3): array
    {
        $this->sent[] = ['method' => $method] + $params;
        return ['ok' => true, 'result' => ['message_id' => 1]];
    }
};

$flood2->sendMessage(1, str_repeat('<b>متن', 900), [
    'reply_markup' => ['inline_keyboard' => [[['text' => 'x', 'callback_data' => 'y']]]],
]);

$withKb = 0;
foreach ($flood2->sent as $call) {
    if (isset($call['reply_markup'])) {
        $withKb++;
    }
}
check('کیبورد فقط یک بخش گرفت', $withKb === 1, 'withMarkup=' . $withKb);
check('بخش‌های سیل‌آسا هم زیر سقف‌اند',
    (function () use ($flood2): bool {
        foreach ($flood2->sent as $call) {
            if (mb_strlen((string) $call['text']) > 4096) {
                return false;
            }
        }
        return true;
    })());

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);