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
    private int $timeout;
    private ?int $lastUpdateId = null;

    /** @var array<int, array<string, mixed>> پاسخ خطاهای اخیر برای لاگ */
    private array $errorLog = [];

    public function __construct(?string $token = null)
    {
        $this->token   = $token ?? Config::str('bot_token', '');
        $this->timeout = Config::int('http_timeout', 30);

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
        $url      = self::API_BASE . $this->token . '/' . $method;
        $attempts = 3;

        for ($i = 1; $i <= $attempts; $i++) {
            $response = Http::request('POST', $url, [
                'headers'    => ['Accept: application/json'],
                'body'       => http_build_query($params),
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
        // تضمین نهایی: اگر متن هنوز از سقف رد شده بود، دوباره شکسته می‌شود.
        // این آخرین سد دفاعی است و نباید به حذف بی‌صدای پیام منجر شود.
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            // متن از سقف رد شده پس splitText حتماً بیش از یک بخش می‌دهد.
            $pieces = $this->splitText($text, self::MAX_TEXT_LENGTH);

            Logger::warning('Message exceeded Telegram limit after split, sent first part', [
                'chat_id' => $chatId,
                'len'     => mb_strlen($text),
                'parts'   => count($pieces),
            ]);

            $retry        = $options;
            $retry['text'] = $pieces[0];

            return $this->call('sendMessage', $retry);
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
    public function splitText(string $text, int $limit = self::MAX_TEXT_LENGTH): array
    {
        $limit = max(200, $limit);

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        // سقف کاری کمی کمتر از حد تلگرام تا جای شمارنده و برچسب‌های باز
        // HTML در بخش‌های بعدی بماند.
        $limit = max(200, $limit - self::SPLIT_HEADROOM);

        $lines  = preg_split('/\R/u', $text) ?: [$text];
        $chunks = [];
        $buffer = '';

        $flush = static function (string $part) use (&$chunks): void {
            if (trim($part) === '') {
                return;
            }

            $chunks[] = self::balanceFragment($part);
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

        return $chunks === [] ? [Str::truncate($text, $limit)] : $chunks;
    }

    /**
     * شکستن یک خط بسیار بلند به قطعات زیر سقف.
     *
     * اول از مرز کلمه استفاده می‌شود و اگر یک «کلمه» خودش از سقف بزرگ‌تر باشد
     * (مثلاً یک رشتهٔ هش بدون فاصله یا base64)، به‌اجبار کاراکتر‌به‌کاراکتر
     * بریده می‌شود تا هیچ‌وقت از سقف رد نشود.
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

                foreach (mb_str_split($token, $limit) as $hard) {
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
    private static function balanceFragment(string $text): string
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

        // بستن برچسب‌هایی که تا انتهای قطعه باز مانده‌اند.
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $output .= '</' . $stack[$i] . '>';
        }

        return $output;
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