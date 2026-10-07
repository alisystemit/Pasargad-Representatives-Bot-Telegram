<?php

declare(strict_types=1);

namespace Pasargad\Telegram;

use Pasargad\Support\Config;
use Pasargad\Support\Http;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * پوشش نازک روی Bot API تلگرام.
 *
 * همهٔ متدها آرایهٔ پارامتر برمی‌گردانند و خطا را throw نمی‌کنند؛
 * بررسی خطا با کلید 'ok' انجام می‌شود تا ربات در برابر خطاهای شبکه مقاوم باشد.
 */
class BotApi
{
    private const API_BASE = 'https://api.telegram.org/bot';

    private string $token;
    private string $apiBase;
    private int $timeout;
    private ?int $lastUpdateId = null;

        /** @var array<int, array<string, mixed>> پاسخ خطاهای اخیر برای لاگ */
    private array $errorLog = [];

    public function __construct(?string $token = null)
    {
        $this->token   = $token ?? Config::str('bot_token', '');
        $this->timeout = Config::int('http_timeout', 30);

        // پایهٔ سفارشی فقط وقتی تنظیم شده باشد. برای سرور Bot API خودی یا
        // تست بدون اینترنت. آدرس نهایی مثل تلگرام است:
        //   {پایه}/bot{توکن}/{متد}
        // لازم نیست کاربر خودش «/bot» را بنویسد؛ هر دو حالت پذیرفته می‌شود:
        //   http://127.0.0.1:8100    ← خودکار «/bot» اضافه می‌شود
        //   http://127.0.0.1:8100/bot
        $custom = rtrim(trim(Config::str('telegram_api_base', '')), '/');
        if ($custom !== '' && !str_ends_with($custom, '/bot')) {
            $custom .= '/bot';
        }
        $this->apiBase = $custom !== '' ? $custom : self::API_BASE;

        if ($this->token === '' || $this->token === 'PUT_BOT_TOKEN_HERE') {
            throw new \RuntimeException('توکن ربات در تنظیمات (bot_token) تعریف نشده است.');
        }
    }

    /**
     * فراخوانی متد Bot API.
     *
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $url      = $this->apiBase . $this->token . '/' . $method;
        $attempts = 3;

        // تلگرام پارامترهای تودرتو (مثل reply_markup) را فقط به‌صورت JSON قبول
        // می‌کند؛ http_build_query روی آرایهٔ تودرتو شکل «reply_markup[...][...]=»
        // می‌سازد که تلگرام بی‌صدا ردش می‌کند و کیبورد اصلاً دیده نمی‌شود.
        $flat = [];
        foreach ($params as $key => $value) {
            $flat[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        }

        for ($i = 1; $i <= $attempts; $i++) {
            $response = Http::request('POST', $url, [
                'headers'    => ['Accept: application/json'],
                'body'       => http_build_query($flat),
                'form'       => null,
                'timeout'    => $this->timeout,
                'verify_ssl' => true,
            ]);

            if ($response['error'] !== '' || $response['status'] === 0) {
                $this->rememberError($method, $response['error'] !== '' ? $response['error'] : 'timeout');
                if ($i < $attempts) {
                    usleep(500000 * $i);
                    continue;
                }

                return ['ok' => false, 'error_code' => 0, 'description' => 'ارتباط با تلگرام برقرار نشد.'];
            }

            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                $this->rememberError($method, 'invalid json');
                return ['ok' => false, 'error_code' => $response['status'], 'description' => 'پاسخ نامعتبر از تلگرام.'];
            }

            if (!($decoded['ok'] ?? false)) {
                $description = (string) ($decoded['description'] ?? 'خطای نامشخص');
                $this->rememberError($method, $description);

                // 429 یعنی محدودیت نرخ درخواست؛ باید صبر کنیم.
                if (($decoded['error_code'] ?? 0) === 429) {
                    $retryAfter = (int) ($decoded['parameters']['retry_after'] ?? 2);
                    sleep(min($retryAfter, 10));
                    if ($i < $attempts) {
                        continue;
                    }
                }

                return $decoded;
            }

            return $decoded;
        }

        return ['ok' => false, 'error_code' => 0, 'description' => 'ارسال پیام ناموفق بود.'];
    }

    /**
     * ارسال پیام.
     *
     * نکتهٔ حیاتی: تلگرام متن بلندتر از ۴۰۹۶ کاراکتر را نمی‌پذیرد و خطای
     * «message is too long» می‌دهد. پیام‌های بلند (آمار مدیریتی، فهرست
     * سفارش‌ها، گزارش پخش همگانی) به‌صورت خودکار به چند پیام شکسته و
     * ارسال می‌شوند تا چیزی بی‌صدا حذف نشود.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> نتیجهٔ آخرین بخش
     */
    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $options['chat_id'] = $chatId;
        $options['parse_mode'] ??= 'HTML';
        $options['disable_web_page_preview'] ??= true;

        $chunks = $this->splitText($text, self::MAX_TEXT_LENGTH);

        if (count($chunks) <= 1) {
            return $this->deliver($chatId, $text, $options, false);
        }

        $last      = [];
        $total     = count($chunks);
        $lastIndex = $total - 1;

        foreach ($chunks as $index => $chunk) {
            $part = $options;

            // کیبورد فقط روی آخرین بخش می‌ماند. اگر روی بخش‌های قبلی هم
            // باشد، دکمه‌ها روی پیام‌های قدیمی می‌مانند و کاربر با کلیک روی
            // آن‌ها به صفحه‌ای می‌رسد که دیگر با متن هم‌خوان نیست.
            unset($part['reply_markup']);

            if ($index === $lastIndex && isset($options['reply_markup'])) {
                $part['reply_markup'] = $options['reply_markup'];
            }

            // در این نقطه $total همیشه بیش از ۱ است (پیام کوتاه قبلاً برگشته).
            // شمارنده کمک می‌کند کاربر بداند پیام ادامه دارد.
            $part['text'] = ($index + 1) . '/' . $total . "\n\n" . $chunk;

            $last = $this->deliver($chatId, $part['text'], $part, $index > 0);
        }

        return $last;
    }

    /**
     * تلاش برای ارسال؛ در صورت خطای HTML یک‌بار با متن ساده تکرار می‌شود.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function deliver(int $chatId, string $text, array $options, bool $isFollowUp): array
    {
        // تضمین نهایی: اگر متن هنوز از سقف رد شده بود، دوباره شکسته و **همهٔ**
        // بخش‌ها فرستاده می‌شوند. فرستادن فقط بخش اول یعنی حذف بی‌صدای بقیهٔ
        // پیام — دقیقاً همان چیزی که این کد باید جلویش را بگیرد.
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $pieces = $this->splitText($text, self::MAX_TEXT_LENGTH);
            $total  = count($pieces);
            $last   = [];

            Logger::warning('Message exceeded Telegram limit after split', [
                'chat_id' => $chatId,
                'len'     => mb_strlen($text),
                'parts'   => $total,
            ]);

            foreach ($pieces as $index => $piece) {
                $part = $options;
                unset($part['reply_markup']);

                if ($index === $total - 1 && isset($options['reply_markup'])) {
                    $part['reply_markup'] = $options['reply_markup'];
                }

                $part['text'] = ($index + 1) . '/' . $total . "\n\n" . $piece;

                $last = $this->call('sendMessage', $part);
            }

            return $last;
        }

        // هیچ‌وقت بدون text به تلگرام نفرست؛ همیشه خودتِ متن را بنشان
        $options['text'] = $text;

        // اگر خودِ متن خالی باشد، تلگرام همان خطای message text is empty می‌دهد
        if (trim($text) === '') {
            Logger::warning('sendMessage primary skipped: empty text', [
                'chat_id' => $chatId,
            ]);

            return ['ok' => false, 'error_code' => 0, 'description' => 'متن پیام خالی است.'];
        }

        $result = $this->call('sendMessage', $options);

        if ($result['ok'] ?? false) {
            return $result;
        }

        $description = (string) ($result['description'] ?? '');

        if (str_contains($description, "can't parse entities") || str_contains($description, 'Unsupported start tag')) {
            $plain = $options;
            unset($plain['parse_mode']);
            $plain['text'] = $this->stripHtml($text);

            $result = $this->call('sendMessage', $plain);

            if ($result['ok'] ?? false) {
                return $result;
            }

            $description = (string) ($result['description'] ?? '');
        }

        // اگر به هر دلیلی باز هم بلند بود (مثلاً کاراکترهای چندبایتی)،
        // یک بار دیگر تقسیم می‌کنیم — به‌جای اینکه پیام کلاً حذف شود.
        if ($isFollowUp === false && str_contains($description, 'too long')) {
            $halved = $this->splitText($text, max(500, (int) (strlen($text) / 2)));

            if (count($halved) > 1) {
                $retry = $options;
                $retry['text'] = $halved[0];

                return $this->call('sendMessage', $retry);
            }
        }

        Logger::warning('sendMessage failed', [
            'chat_id' => $chatId,
            'error'   => $description,
            'text_len' => mb_strlen($text),
        ]);

        return $result;
    }

    /**
     * تقسیم متن بلند به بخش‌های قابل ارسال، با حفظ ساختار خطی و برچسب HTML.
     *
     * نکتهٔ مهم: متن HTML مثل <b>…</b> اگر وسط بریده شود، تلگرام خطای
     * «can't parse entities» می‌دهد. بنابراین هر برچسب باز در هر بخش
     * دوباره بسته می‌شود.
     *
     * @return array<int, string>
     */
    public function splitText(string $text, int $limit = self::MAX_TEXT_LENGTH, int $depth = 0): array
    {
        $limit = max(200, $limit);

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        // سقف سخت تلگرام — هیچ بخشی نباید از این رد شود.
        $hardLimit = $limit;

        // سقف کاری: جای شمارندهٔ «۳/۱۲» و برچسب‌های بستهٔ HTML که
        // balanceFragment اضافه می‌کند باید از قبل کنار گذاشته شود.
        $limit = max(200, $limit - self::SPLIT_HEADROOM);

        $lines  = preg_split('/\R/u', $text) ?: [$text];
        $chunks = [];
        $buffer = '';

        $flush = function (string $part, int $depth = 0) use (&$chunks, $hardLimit): void {
            if (trim($part) === '') {
                return;
            }

            // budget: حداکثر کاراکتری که بستن برچسب‌های باز می‌تواند اضافه کند.
            $budget   = self::SPLIT_HEADROOM;
            $balanced = self::balanceFragment($part, $budget);

            if (mb_strlen($balanced) <= $hardLimit || $depth >= 2) {
                $chunks[] = mb_strlen($balanced) > $hardLimit
                    ? Str::truncate($balanced, $hardLimit)
                    : $balanced;

                return;
            }

            // هنوز از سقف رد شده → یک بار دیگر با سقف کوچک‌تر تقسیم می‌کنیم.
            // سقف `depth` جلوی بازگشت بی‌نهایت را می‌گیرد.
            foreach ($this->splitText($balanced, $hardLimit - $budget, $depth + 1) as $piece) {
                $chunks[] = self::balanceFragment($piece, $budget);
            }
        };

        foreach ($lines as $line) {
            // ---- خط از سقف بلندتر است: با کلمه‌به‌کلمه می‌شکنیم ----
            if (mb_strlen($line) > $limit) {
                $flush($buffer);
                $buffer = '';

                foreach (self::splitLongLine($line, $limit) as $piece) {
                    $flush($piece);
                }

                continue;
            }

            $candidate = $buffer === '' ? $line : $buffer . "\n" . $line;

            if (mb_strlen($candidate) > $limit) {
                $flush($buffer);
                $buffer = $line;
                continue;
            }

            $buffer = $candidate;
        }

        $flush($buffer);

        if ($chunks === []) {
            return [Str::truncate($text, $hardLimit)];
        }

        // آخرین تضمین: اگر باز هم بخشی از سقف سخت رد شده بود، کوتاه می‌شود.
        foreach ($chunks as $index => $chunk) {
            if (mb_strlen($chunk) > $hardLimit) {
                $chunks[$index] = Str::truncate($chunk, $hardLimit);
            }
        }

        return $chunks;
    }

    /**
     * شکستن یک خط بسیار بلند به قطعات زیر سقف.
     *
     * اول از مرز کلمه استفاده می‌شود و اگر یک «کلمه» خودش از سقف بزرگ‌تر باشد
     * (مثلاً یک رشتهٔ هش بدون فاصله یا base64)، به‌اجبار کاراکتر‌به‌کاراکتر
     * بریده می‌شود تا هیچ‌وقت از سقف رد نشود.
     *
     * نکتهٔ حیاتی: اگر بریدن داخل یک برچسب HTML اتفاق بیفتد (مثلاً وسط
     * `href="…"` یک لینک ۵۰۰۰ کاراکتری)، تلگرام خطای parse می‌دهد و در
     * fallback متن ساده هم برچسب ناقص به کاربر نشان داده می‌شود. پس قبل از
     * بریدن اجباری، برش تا آخرین `>` انجام می‌شود.
     *
     * @return array<int, string>
     */
    private static function splitLongLine(string $line, int $limit): array
    {
        $pieces = [];

        // قطعهٔ جاری با مرز کلمه
        $carry = '';

        // مرزهای ترجیحی: فاصله و نقطه‌گذاری فارسی/انگلیسی
        $tokens = preg_split('/(?<=[\s\x{060C}\x{061B}.,،؛:!?])/u', $line) ?: [$line];

        foreach ($tokens as $token) {
            if (mb_strlen($token) > $limit) {
                // کلمه/توکن خودش بیش از حد است → بریدن اجباری
                if ($carry !== '') {
                    $pieces[] = $carry;
                    $carry    = '';
                }

                foreach (self::hardSplit($token, $limit) as $hard) {
                    $pieces[] = $hard;
                }

                continue;
            }

            if (mb_strlen($carry . $token) > $limit && $carry !== '') {
                $pieces[] = $carry;
                $carry    = '';
            }

            $carry .= $token;
        }

        if ($carry !== '') {
            $pieces[] = $carry;
        }

        return $pieces;
    }

    /**
     * بریدن اجباری یک توکن بلند، بدون شکستن داخل برچسب HTML.
     *
     * اگر نقطهٔ برش داخل یک برچسب باز بیفتد (یعنی تعداد `>` کمتر از تعداد `<`
     * تا آن نقطه)، برش تا نزدیک‌ترین `>` بعدی هل داده می‌شود. اگر حتی یک
     * برچسبِ بلندتر از سقف باشد (مثلاً لینک فوق‌العاده طولانی)، برچسب به‌طور
     * کامل حذف می‌شود تا متن قابل ارسال بماند.
     *
     * @return array<int, string>
     */
    private static function hardSplit(string $token, int $limit): array
    {
        $pieces = [];

        while ($token !== '') {
            if (mb_strlen($token) <= $limit) {
                $pieces[] = $token;
                break;
            }

            $slice    = mb_substr($token, 0, $limit);
            $openTags = substr_count($slice, '<');
            $closeTags = substr_count($slice, '>');

            if ($openTags > $closeTags) {
                // نقطهٔ برش داخل یک برچسب است.
                $nextEnd = strpos($token, '>', $limit);

                if ($nextEnd !== false && $nextEnd - $limit < 200) {
                    // برچسب تا ۲۰۰ کاراکتر بعد تمام می‌شود → تا همان‌جا می‌بریم.
                    $slice = mb_substr($token, 0, $nextEnd + 1);
                } else {
                    // برچسب غیرعادی بلند است → کل بلوک باز تا `>` بعدی حذف می‌شود.
                    $scan = 0;
                    while (($nextEnd = strpos($token, '>', $scan)) !== false && $nextEnd < $limit) {
                        $scan = $nextEnd + 1;
                    }

                    if ($nextEnd === false) {
                        // بسته‌شدنی در کار نیست → باقیمانده متن ساده است.
                        $slice = $slice . "\n⚠️ بخشی از پیام به‌دلیل طول زیاد حذف شد.";
                        $token = '';
                        $pieces[] = $slice;
                        break;
                    }

                    $skipTo = $nextEnd + 1;

                    // متن قبل از برچسب باقی می‌ماند، برچسب حذف می‌شود.
                    $before = mb_substr($token, 0, $scan > 0 ? $scan - 1 : 0);
                    $before = rtrim($before);

                    if ($before !== '') {
                        $pieces[] = $before;
                    }

                    $token = (string) mb_substr($token, $skipTo);
                    continue;
                }
            }

            $pieces[] = $slice;
            $token    = (string) mb_substr($token, mb_strlen($slice));
        }

        return array_values(array_filter($pieces, static fn (string $p): bool => trim($p) !== ''));
    }

    /**
     * برچسب‌های HTML مجاز در پیام‌های این ربات.
     */
    private const HTML_TAGS = 'b|i|u|s|code|pre|a|tg-spoiler|blockquote';

    /**
     * تبدیل یک قطعهٔ متن به HTML معتبر و مستقل.
     *
     * دو کار لازم است:
     *
     *   ۱) بستن برچسب‌هایی که در همین قطعه باز شده ولی بسته نشده‌اند.
     *   ۲) حذف برچسب‌های بستهٔ «یتیم» که باز شدنشان در بخش قبلی بوده است.
     *
     * نکتهٔ حیاتی: تلگرام هر پیام را جداگانه پارس می‌کند. یک ‎</b>‎ تنها
     * باعث خطای «can't parse entities: Unsupported start tag» می‌شود و
     * **کل پیام ارسال نمی‌شود** — نه فقط یک خط از آن.
     */
    private static function balanceFragment(string $text, int $maxExtra = 128): string
    {
        $pattern = '#<(/?)(' . self::HTML_TAGS . ')(?:\s[^>]*)?>#i';

        $found = preg_match_all(
            $pattern,
            $text,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        if ($found === 0 || $found === false) {
            return $text;
        }

        $stack     = [];
        $output    = '';
        $lastClose = 0;

        foreach ($matches as $match) {
            $offset = (int) $match[0][1];
            $raw    = (string) $match[0][0];
            $tag    = strtolower((string) $match[2][0]);

            // متن بین دو برچسب، عیناً منتقل می‌شود.
            $output    .= substr($text, $lastClose, $offset - $lastClose);
            $lastClose = $offset + strlen($raw);

            // ---- برچسب باز ----
            if ((string) $match[1][0] !== '/') {
                $stack[] = $tag;
                $output .= $raw;
                continue;
            }

            // ---- برچسب بسته ----
            $position = array_search($tag, $stack, true);

            if ($position === false) {
                // برچسب بستهٔ یتیم: حذف می‌شود تا پیام معتبر بماند.
                continue;
            }

            // هر برچسبی که بعد از این باز شده بود باید اول بسته شود
            // (وگرنه تلگرام خطای «must be closed» می‌دهد).
            for ($i = count($stack) - 1; $i > $position; $i--) {
                $output .= '</' . $stack[$i] . '>';
            }

            array_splice($stack, $position);

            $output .= '</' . $tag . '>';
        }

        $output .= substr($text, $lastClose);

        // ---- برچسب‌هایی که تا انتهای قطعه باز مانده‌اند ----
        if ($stack === []) {
            return $output;
        }

        // بستن N برچسب باز یعنی حداکثر N×۱۲ بایت اضافه. اگر این از بودجه
        // بیشتر شود (مثلاً ۴۰۰ برچسب باز)، به‌جای سرریز کردن سقف پیام،
        // خودِ برچسب‌های باز حذف می‌شوند — از دست رفتن یک تگ بی‌ضرر است،
        // سرریز کردن سقف پیام یا بازگشت بی‌نهایت نه.
        if (strlen($output) + self::closersLength($stack) > $maxExtra) {
            return self::stripUnclosedOpens($output, $stack);
        }

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $output .= '</' . $stack[$i] . '>';
        }

        return $output;
    }

    /**
     * طول متنی که بستن این برچسب‌ها اضافه می‌کند.
     *
     * @param array<int, string> $tags
     */
    private static function closersLength(array $tags): int
    {
        $length = 0;

        foreach ($tags as $tag) {
            $length += strlen($tag) + 3;   // ‎</tag>‎
        }

        return $length;
    }

    /**
     * حذف آخرین برچسب‌های باز از متن (وقتی بستنشان ممکن نیست).
     *
     * برچسب‌ها از انتهای متن و به ترتیب معکوس پشته حذف می‌شوند تا ساختار
     * بقیهٔ متن سالم بماند.
     *
     * @param array<int, string> $stack
     */
    private static function stripUnclosedOpens(string $text, array $stack): string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $tag = $stack[$i];

            // آخرین occurrence برچسب بازِ این نوع را حذف می‌کنیم.
            $position = strripos($text, '<' . $tag);

            // فقط اگر باز باشد (یعنی جفت متناظر بعدش نباشد)
            while ($position !== false) {
                $end = strpos($text, '>', $position);

                if ($end === false) {
                    break;
                }

                $inner = substr($text, $position + strlen($tag) + 1, $end - $position - strlen($tag) - 1);

                if ($inner !== '' && ($inner[0] === ' ' || $inner[0] === "\t")) {
                    // دارای صفت است → شکل کامل <tag ...> را پیدا کن
                    $position = strripos(substr($text, 0, $position), '<' . $tag);
                    continue;
                }

                $text    = substr($text, 0, $position) . substr($text, $end + 1);
                break;
            }
        }

        return $text;
    }

    /**
     * ویرایش پیام.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): array
    {
        // ویرایش متن بلندتر از سقف تلگرام خطا می‌دهد؛ کوتاه می‌شود تا پیام
        // قبلی خراب نشود (برخلاف sendMessage که تقسیم می‌کند).
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = Str::truncate($text, self::MAX_TEXT_LENGTH);
        }

        $result = $this->call('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $options));

        // پیام تغییری نکرده است — خطا محسوب نمی‌شود.
        $description = (string) ($result['description'] ?? '');
        if (!$result['ok'] && str_contains($description, 'message is not modified')) {
            return ['ok' => true];
        }

        if (!$result['ok']) {
            $plain = $this->call('editMessageText', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'text'         => $this->stripHtml($text),
                'reply_markup' => $options['reply_markup'] ?? null,
            ]);

            if ($plain['ok'] ?? false) {
                return $plain;
            }
        }

        return $result;
    }

    /**
     * ویرایش متن همراه با کیبورد اینلاین — متد پرکاربرد داخل ربات.
     *
     * @param  array<int, array<int, array<string, mixed>>> $keyboard ساختار
     *         [[ ['text' => '…', 'data' => '…'], … ], …]
     */
    public function edit(int $chatId, int $messageId, string $text, array $keyboard = []): array
    {
        return $this->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $this->buildMarkup($keyboard),
        ]);
    }

    /**
     * ارسال عکس با کپشن اختیاری.
     *
     * @param  array<int, array<int, array<string, mixed>>> $keyboard
     * @return array<string, mixed>
     */
    public function sendPhoto(int $chatId, string $photo, string $caption = '', array $keyboard = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo'   => $photo,
        ];

        if ($caption !== '') {
            // کپشن تلگرام حداکثر ۱۰۲۴ کاراکتر است.
            $params['caption']   = Str::truncate($caption, self::MAX_CAPTION_LENGTH);
            $params['parse_mode'] = 'HTML';
        }

        $markup = $this->buildMarkup($keyboard);
        if ($markup !== null) {
            $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
        }

        $result = $this->call('sendPhoto', $params);

        if (!($result['ok'] ?? false) && $caption !== '') {
            // اگر کپشن HTML مشکل‌ساز بود، بدون فرمت HTML دوباره تلاش می‌کنیم.
            $description = (string) ($result['description'] ?? '');
            if (str_contains($description, "can't parse entities") || str_contains($description, 'Unsupported start tag')) {
                $plain = $params;
                unset($plain['parse_mode']);
                $plain['caption'] = $this->stripHtml($caption);

                return $this->call('sendPhoto', $plain);
            }
        }

        return $result;
    }

    /**
     * ارسال فایل (سند) با آپلود multipart — برای بکاپ دیتابیس.
     *
     * BotApi::call فقط urlencoded می‌فرستد و برای آپلود فایل مناسب نیست،
     * پس اینجا مستقیم با cURL ارسال می‌شود.
     *
     * @param  array<int, array<int, array<string, mixed>>> $keyboard
     * @return array<string, mixed>
     */
    public function sendDocument(int $chatId, string $filePath, string $caption = '', array $keyboard = []): array
    {
        if (!is_file($filePath) || !function_exists('curl_init')) {
            return ['ok' => false, 'description' => 'فایل یا cURL در دسترس نیست.'];
        }

        $url = self::API_BASE . $this->token . '/sendDocument';

        $params = [
            'chat_id' => (string) $chatId,
            'caption' => Str::truncate($caption, self::MAX_CAPTION_LENGTH),
            'parse_mode' => 'HTML',
            'document' => new \CURLFile($filePath, 'application/x-sqlite3', basename($filePath)),
        ];

        $markup = $this->buildMarkup($keyboard);
        if ($markup !== null) {
            $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
        }

        $curl = curl_init();
        if ($curl === false) {
            return ['ok' => false, 'description' => 'راه‌اندازی cURL ناموفق بود.'];
        }

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_POSTFIELDS     => $params,
        ]);

        $raw = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'description' => $err !== '' ? $err : 'ارسال فایل ناموفق بود.'];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'پاسخ نامعتبر از تلگرام.'];
    }

    /**
     * پاسخ سریع به callback query.
     *
     * شناسهٔ callback از سمت تلگرام یک رشتهٔ عددی است، پس string پذیرفته می‌شود.
     */
    public function answerCallback(string $callbackQueryId, string $text = '', bool $alert = false): void
    {
        if ($callbackQueryId === '') {
            return;
        }

        $params = ['callback_query_id' => $callbackQueryId];
        if ($text !== '') {
            $params['text']       = Str::truncate($text, 190);
            $params['show_alert'] = $alert;
        }

        $this->call('answerCallbackQuery', $params);
    }

    /**
     * دریافت اطلاعات کاربر از طریق دکمهٔ شمارهٔ تماس.
     *
     * @return array<string, mixed>|null
     */
    public function getChat(int $chatId): ?array
    {
        $result = $this->call('getChat', ['chat_id' => $chatId]);

        return ($result['ok'] ?? false) ? (array) $result['result'] : null;
    }

    /**
     * حذف پیام (برای پاک‌سازی پیام‌های موقت).
     */
    public function deleteMessage(int $chatId, int $messageId): bool
    {
        $result = $this->call('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);

        return (bool) ($result['ok'] ?? false);
    }

    /**
     * تنظیم دستورهای ربات برای منوی slash.
     *
     * @param array<int, array{command:string, description:string}> $commands
     */
    public function setMyCommands(array $commands): bool
    {
        $result = $this->call('setMyCommands', ['commands' => json_encode($commands, JSON_UNESCAPED_UNICODE)]);

        return (bool) ($result['ok'] ?? false);
    }

    /**
     * خواندن آپدیت‌ها (حالت long polling) — برای تست و اجرای CLI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout = 25, int $limit = 50): array
    {
        $result = $this->call('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $timeout,
            'limit'           => $limit,
            'allowed_updates' => json_encode(['message', 'callback_query', 'edited_message']),
        ]);

        if (!($result['ok'] ?? false)) {
            return [];
        }

        $updates = is_array($result['result']) ? $result['result'] : [];
        foreach ($updates as $update) {
            $id = (int) ($update['update_id'] ?? 0);
            if ($id > $this->lastUpdateId) {
                $this->lastUpdateId = $id;
            }
        }

        return $updates;
    }

    public function lastUpdateId(): int
    {
        return $this->lastUpdateId ?? 0;
    }

    /**
     * حداکثر طول متن پیام تلگرام (بر حسب کاراکتر).
     */
    public const MAX_TEXT_LENGTH = 4096;

    /**
     * حداکثر طول کپشن عکس/ویدیو تلگرام.
     */
    public const MAX_CAPTION_LENGTH = 1024;

    /**
     * حداکثر طول callback_data تلگرام (بر حسب بایت).
     */
    public const MAX_CALLBACK_BYTES = 64;

    /**
     * فضای رزروشده هنگام شکستن متن.
     *
     * پس از شکستن، به هر بخش این موارد اضافه می‌شود و باید جا شوند:
     *   • شمارندهٔ «۳/۱۲» برای پیام چندبخشی
     *   • برچسب‌های بستهٔ HTML مثل ‎</b>‎ و ‎</a>‎
     *
     * بدون این حاشیه، بخش آخر می‌تواند از سقف ۴۰۹۶ رد شود و تلگرام آن را
     * رد کند — یعنی بخشی از پیام هرگز ارسال نمی‌شود.
     */
    public const SPLIT_HEADROOM = 128;

    /**
     * ساخت ساختار reply_markup از آرایهٔ کیبورد.
     *
     * @param  array<int, array<int, array<string, mixed>>> $keyboard
     * @return array<string, mixed>|null
     */
    public function buildMarkup(array $keyboard): ?array
    {
        $rows = $this->normalizeKeyboard($keyboard);

        if ($rows === []) {
            return null;
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * نرمال‌سازی کیبورد به ساختار قطعی تلگرام.
     *
     * این تنها نقطه‌ای است که خروجی نهایی ساخته می‌شود، بنابراین هر ناسازگاری
     * ورودی اینجا گرفته می‌شود تا پیام خراب (کیبورد بی‌دکمه، دکمهٔ بی‌متن،
     * callback_data بلندتر از ۶۴ بایت) هرگز به تلگرام نرود.
     *
     * @param  array<int, mixed> $keyboard
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function normalizeKeyboard(array $keyboard): array
    {
        $rows = [];

        foreach ($keyboard as $row) {
            if (!is_array($row)) {
                continue;
            }

            $buttons = [];

            foreach ($row as $button) {
                $normalized = $this->normalizeButton($button);

                if ($normalized !== null) {
                    $buttons[] = $normalized;
                }
            }

            if ($buttons !== []) {
                $rows[] = $buttons;
            }
        }

        return $rows;
    }

    /**
     * نرمال‌سازی یک دکمه؛ اگر دکمه معتبر نباشد null برمی‌گرداند.
     *
     * @param  mixed $button
     * @return array<string, mixed>|null
     */
    private function normalizeButton($button): ?array
    {
        if (!is_array($button)) {
            return null;
        }

        $text = trim((string) ($button['text'] ?? ''));

        // دکمهٔ بدون متن در تلگرام خطا می‌دهد.
        if ($text === '') {
            return null;
        }

        $style = (string) ($button['style'] ?? 'default');

        // دکمهٔ پرداخت مستقیم تلگرام
        if ($style === 'pay') {
            return ['text' => $text, 'pay' => true];
        }

        // دکمهٔ لینک بیرونی
        if ($style === 'url' || isset($button['url'])) {
            $url = trim((string) ($button['url'] ?? ''));

            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                Logger::warning('Skipping button with invalid url', ['text' => $text, 'url' => $url]);
                return null;
            }

            return ['text' => $text, 'url' => $url];
        }

        // دکمهٔ Mini App
        //
        // ⚠️ این شاخه **قبل** از شاخهٔ callback می‌آید. قبلاً نبود و دکمهٔ
        // «📱 اپلیکیشن وب» در منوی اصلی به‌جای باز شدن اپ، یک
        // `callback_data: noop` می‌ساخت و بی‌صدا هیچ کاری نمی‌کرد.
        if (isset($button['web_app'])) {
            $url = trim((string) $button['web_app']);

            if (!$this->isTrustedWebAppUrl($url)) {
                Logger::warning('Skipping button with untrusted web_app url', ['text' => $text, 'url' => $url]);
                return null;
            }

            return ['text' => $text, 'web_app' => $url];
        }

        // دکمهٔ callback
        $data = trim((string) ($button['data'] ?? $button['callback_data'] ?? ''));

        if ($data === '') {
            $data = 'noop';
        }

        // تلگرام callback_data را به ۶۴ بایت محدود می‌کند.
        if (strlen($data) > self::MAX_CALLBACK_BYTES) {
            $data = substr($data, 0, 52) . '~' . substr(sha1($data), 0, 10);
        }

        return ['text' => $text, 'callback_data' => $data];
    }

    /**
     * آیا این URL برای دکمهٔ Mini App قابل اعتماد است؟
     *
     * سه شرط، و هر سه لازم‌اند:
     *   ۱) HTTPS — تلگرام در غیر این صورت دکمه را رد می‌کند و در برخی نسخه‌ها
     *      کل کیبورد را باطل می‌کند.
     *   ۲) مبدأ == `base_url` کانفیگ (یعنی سرور خودِ ربات).
     *   ۳) ویژگی `userinfo`/`fragment` نداشته باشد؛
     *      `https://example.com@evil.tld/` در نگاه اول مبدأ ما را دارد ولی
     *      در واقع به `evil.tld` می‌رود. تلگرام این را «باز کردن لینک در
     *      Mini Appِ ما» نشان می‌دهد ولی کاربر را جای دیگری می‌برد.
     */
    private function isTrustedWebAppUrl(string $url): bool
    {
        if ($url === '' || preg_match('#^https://#i', $url) !== 1) {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $base = trim(Config::str('base_url', ''));

        if ($base === '') {
            return false;
        }

        $baseHost = parse_url($base, PHP_URL_HOST);

        if (!is_string($baseHost) || $baseHost === '') {
            return false;
        }

        $host   = strtolower((string) $parts['host']);
        $baseOk = strtolower($baseHost);

        if ($host !== $baseOk && !str_ends_with($host, '.' . $baseOk)) {
            Logger::warning('WebApp host does not match base_url', ['host' => $host, 'base_host' => $baseOk]);
            return false;
        }

        // اجازهٔ میزبانی روی زیردامنهٔ رسمی تلگرام هم هست (مثلاً وب‌اپ‌هایی که
        // روی pages.dev سرو می‌شوند ولی به تلهگرام وصل‌اند) — ولی فقط وقتی
        // که `base_url` خودش به آن دامنه اشاره کند، که در شرط بالا بررسی شد.
        unset($parts);

        return true;
    }

    /**
     * کلیدواژهٔ نشانه‌گذاری که callback data مجاز است (۶۴ بایت).
     */
    public static function encodeData(string $namespace, array $params = []): string
    {
        $payload = ['n' => $namespace];
        foreach ($params as $key => $value) {
            $payload[$key] = $value;
        }

        $data = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        return strlen($data) <= 64 ? $data : (string) json_encode(['n' => $namespace, 'i' => $params['id'] ?? null], JSON_UNESCAPED_UNICODE);
    }

    /**
     * تبدیل HTML به متن ساده (برای fallback تلگرام).
     */
    private function stripHtml(string $text): string
    {
        $text = preg_replace('#</?(b|i|u|s|code|pre|a)\b[^>]*>#i', '', $text) ?? $text;

        return html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * نگهداری آخرین خطاها برای گزارش‌ها.
     */
    private function rememberError(string $method, string $description): void
    {
        $this->errorLog[] = ['method' => $method, 'error' => $description, 'at' => time()];

        if (count($this->errorLog) > 20) {
            array_shift($this->errorLog);
        }

        Logger::warning('Telegram API error', ['method' => $method, 'error' => $description]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentErrors(): array
    {
        return $this->errorLog;
    }
}