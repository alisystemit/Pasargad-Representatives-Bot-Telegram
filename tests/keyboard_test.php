<?php

declare(strict_types=1);

/**
 * تست ساختار واقعی کیبورد و markup.
 *
 * FakeBotApi قبلاً فقط «وجود متن دکمه» را چک می‌کرد و یک لایه از نرمال‌سازی
 * را از قلم می‌انداخت؛ این تست ساختار نهایی را که واقعاً به تلگرام می‌رود
 * بررسی می‌کند تا کیبوردهای خراب (مثل ردیف‌های جابه‌جا) لو بروند.
 */

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;
use Pasargad\Telegram\Update;

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
 * بررسی می‌کند هر ردیف یک لیست از دکمه‌ها باشد و هر دکمه رشته‌ای باشد.
 *
 * @param  array<string, mixed> $markup
 */
function markupIsValid(array $markup, string $context): bool
{
    if (!isset($markup['inline_keyboard']) || !is_array($markup['inline_keyboard'])) {
        echo "      ↳ {$context}: inline_keyboard ندارد\n";
        return false;
    }

    foreach ($markup['inline_keyboard'] as $rowIndex => $row) {
        if (!is_array($row)) {
            echo "      ↳ {$context}: ردیف {$rowIndex} آرایه نیست\n";
            return false;
        }

        foreach ($row as $colIndex => $button) {
            if (!is_array($button)) {
                echo "      ↳ {$context}: دکمه [{$rowIndex}][{$colIndex}] آرایه نیست\n";
                return false;
            }

            if (!isset($button['text']) || !is_string($button['text']) || $button['text'] === '') {
                echo "      ↳ {$context}: دکمه [{$rowIndex}][{$colIndex}] متن ندارد\n";
                return false;
            }

            // هر دکمه باید یا callback_data داشته باشد یا url (دقیقاً یکی)
            $hasData = isset($button['callback_data']);
            $hasUrl  = isset($button['url']);

            if ($hasData && $hasUrl) {
                echo "      ↳ {$context}: دکمه «{$button['text']}» هم callback_data دارد هم url\n";
                return false;
            }

            if (!$hasData && !$hasUrl) {
                echo "      ↳ {$context}: دکمه «{$button['text']}» نه callback_data دارد نه url\n";
                return false;
            }

            // نباید کلید داخلی 'style' به تلگرام نشت کند
            if (isset($button['style']) || isset($button['data'])) {
                echo "      ↳ {$context}: کلید داخلی (style/data) در markup لو رفت\n";
                return false;
            }

            // callback_data باید زیر ۶۴ بایت باشد
            if ($hasData && strlen((string) $button['callback_data']) > 64) {
                echo "      ↳ {$context}: callback_data دکمه «{$button['text']}» بیش از ۶۴ بایت است\n";
                return false;
            }
        }
    }

    return true;
}

/**
 * استخراج متن همهٔ دکمه‌های یک markup.
 *
 * @param array<string, mixed> $markup
 * @return array<int, string>
 */
function buttonTexts(array $markup): array
{
    $out = [];
    foreach ($markup['inline_keyboard'] ?? [] as $row) {
        foreach ((array) $row as $button) {
            if (is_array($button) && isset($button['text'])) {
                $out[] = (string) $button['text'];
            }
        }
    }
    return $out;
}

$bot = new class extends BotApi {
    public function __construct() {}

    public function buildMarkup(array $keyboard): ?array
    {
        return parent::buildMarkup($keyboard);
    }
};

// ------------------------------------------------------------------
echo "\n▶ ساختار Keyboard::back\n";
// ------------------------------------------------------------------

$markup = $bot->buildMarkup(Keyboard::back('menu', '🔙 بازگشت'));
check('back() یک ردیف می‌سازد', markupIsValid((array) $markup, 'back'));
check('back() متن درست دارد', buttonTexts((array) $markup) === ['🔙 بازگشت'], json_encode(buttonTexts((array) $markup)));
check('back() دکمهٔ یک‌دکمه‌ای دارد', count((array) $markup['inline_keyboard']) === 1);

// ------------------------------------------------------------------
echo "\n▶ ساختار Keyboard::close\n";
// ------------------------------------------------------------------

$markup = $bot->buildMarkup(Keyboard::close());
check('close() ساختار درست دارد', markupIsValid((array) $markup, 'close'));
check('close() دادهٔ close دارد', ($markup['inline_keyboard'][0][0]['callback_data'] ?? '') === 'close');

// ------------------------------------------------------------------
echo "\n▶ ساختار Keyboard::link\n";
// ------------------------------------------------------------------

$markup = $bot->buildMarkup(Keyboard::link('📞 پشتیبانی', 'https://t.me/test'));
check('link() ساختار درست دارد', markupIsValid((array) $markup, 'link'));
check('link() به‌جای callback_data از url استفاده می‌کند', ($markup['inline_keyboard'][0][0]['url'] ?? '') === 'https://t.me/test');
check('link() کلید callback_data ندارد', !isset($markup['inline_keyboard'][0][0]['callback_data']));

// ------------------------------------------------------------------
echo "\n▶ ساختار Keyboard::rows\n";
// ------------------------------------------------------------------

// شکل ۱: هر ردیف آرایه‌ای از دکمه‌ها
$markup = $bot->buildMarkup(Keyboard::rows([
    [
        ['text' => 'الف', 'data' => 'a'],
        ['text' => 'ب', 'data' => 'b'],
    ],
    [['text' => 'ج', 'data' => 'c']],
]));
check('rows() چند ردیف می‌سازد', markupIsValid((array) $markup, 'rows-multi'));
check('rows() تعداد ردیف‌ها درست است', count((array) $markup['inline_keyboard']) === 2);
check('rows() ردیف اول دو دکمه دارد', count((array) $markup['inline_keyboard'][0]) === 2);

// شکل ۲: دکمهٔ تکی (بدون پوش دیگر)
$markup = $bot->buildMarkup(Keyboard::rows([
    ['text' => 'تکی', 'data' => 'x'],
]));
check('rows() دکمهٔ تکی را می‌پذیرد', markupIsValid((array) $markup, 'rows-single-button'));
check('rows() دکمهٔ تکی در یک ردیف است', count((array) $markup['inline_keyboard']) === 1);
check('rows() متن دکمهٔ تکی حفظ شد', buttonTexts((array) $markup) === ['تکی']);

// شکل ۳: ترکیبی (مثل استفادهٔ واقعی در پروژه)
$markup = $bot->buildMarkup(Keyboard::rows([
    [
        ['text' => '۱', 'data' => 'p1'],
        ['text' => '۲', 'data' => 'p2'],
    ],
    Keyboard::back('menu'),
]));
check('rows() ترکیب دکمه و back() کار می‌کند', markupIsValid((array) $markup, 'rows-mixed'));
check('rows() ترکیب ۳ دکمه در ۲ ردیف دارد', count((array) $markup['inline_keyboard']) === 2);
check('rows() ردیف دوم همان back است', buttonTexts((array) $markup)[2] === '🔙 بازگشت');

// شکل ۴: ورودی نامعتبر (رشته در آرایه)
$markup = $bot->buildMarkup(Keyboard::rows([
    'not-an-array',
    ['text' => 'سالم', 'data' => 'ok'],
]));
check('rows() ورودی نامعتبر را نادیده می‌گیرد', markupIsValid((array) $markup, 'rows-invalid'));
check('rows() فقط ردیف سالم را نگه داشت', count((array) $markup['inline_keyboard']) === 1);

// آرایهٔ خالی
check('rows([]) آرایهٔ خالی می‌دهد', Keyboard::rows([]) === []);
check('buildMarkup([]) null می‌دهد', $bot->buildMarkup([]) === null);

// ------------------------------------------------------------------
echo "\n▶ ترکیب back و دکمهٔ url در یک کیبورد\n";
// ------------------------------------------------------------------

$markup = $bot->buildMarkup([
    [['text' => '📞 پشتیبانی', 'url' => 'https://t.me/s', 'style' => 'url']],
    Keyboard::back('menu'),
]);
check('کیبورد ترکیبی url + back معتبر است', markupIsValid((array) $markup, 'combo'));
check('کلید style به markup نشت نکرد', !isset($markup['inline_keyboard'][0][0]['style']));

// ------------------------------------------------------------------
echo "\n▶ Keyboard::line\n";
// ------------------------------------------------------------------

$line = Keyboard::line([
    ['text' => '۱', 'data' => 'a'],
    ['text' => '۲', 'data' => 'b'],
    ['text' => '۳', 'data' => 'c'],
], 2);

check('line() با perRow=2 دو ردیف می‌سازد', count($line) === 2, 'count=' . count($line));
check('line() ردیف اول دو دکمه', count($line[0]) === 2);
check('line() ردیف دوم یک دکمه', count($line[1]) === 1);

$markup = $bot->buildMarkup($line);
check('line() خروجی معتبر می‌سازد', markupIsValid((array) $markup, 'line'));

// ------------------------------------------------------------------
echo "\n▶ Keyboard::append\n";
// ------------------------------------------------------------------

$kb = Keyboard::rows([
    [['text' => 'الف', 'data' => 'a']],
]);
$kb = Keyboard::append($kb, ['text' => 'ب', 'data' => 'b']);
check('append() ردیف اضافه می‌کند', count($kb) === 2, 'count=' . count($kb));
check('append() markup معتبر می‌سازد', markupIsValid((array) $bot->buildMarkup($kb), 'append'));

// append با ورودی نامعتبر نباید ردیف خالی اضافه کند
$kb2 = Keyboard::append($kb, []);
check('append() با ورودی خالی چیزی اضافه نمی‌کند', count($kb2) === 2);

// ------------------------------------------------------------------
echo "\n▶ Keyboard::confirmYesNo\n";
// ------------------------------------------------------------------

$markup = $bot->buildMarkup(Keyboard::confirmYesNo('y', 'n'));
check('confirmYesNo() یک ردیف با دو دکمه', count((array) $markup['inline_keyboard']) === 1 && count((array) $markup['inline_keyboard'][0]) === 2);
check('confirmYesNo() ساختار درست', markupIsValid((array) $markup, 'confirm'));

// ------------------------------------------------------------------
echo "\n▶ دکمهٔ ناقص (بدون مقصد)\n";
// ------------------------------------------------------------------

// دکمه‌ای که نه data دارد نه url باید به‌شکل امن نادیده گرفته شود
$markup = $bot->buildMarkup([
    [['text' => 'بدون مقصد']],
    [['text' => 'سالم', 'data' => 'ok']],
]);
check('دکمهٔ بدون مقصد ساختار را خراب نمی‌کند', markupIsValid((array) $markup, 'no-target'));

$texts = buttonTexts((array) $markup);
check('دکمهٔ بدون مقصد به callback_data تبدیل شد', in_array('بدون مقصد', $texts, true), json_encode($texts));
check('دکمهٔ سالم هم سالم ماند', count($texts) === 2);

// ------------------------------------------------------------------
echo "\n▶ طول callback_data\n";
// ------------------------------------------------------------------

$longData = str_repeat('x', 100);
$markup = $bot->buildMarkup([[['text' => 'بلند', 'data' => $longData]]]);
$finalData = (string) ($markup['inline_keyboard'][0][0]['callback_data'] ?? '');
check('callback_data بلند بریده شد', strlen($finalData) <= 64, 'len=' . strlen($finalData));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);