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
     * ارسال پیام (با پشتیبانی از متن بلند و ارسال مجدد بخش‌ها).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $options['chat_id'] = $chatId;
        $options['text']    = $text;
        $options['parse_mode'] ??= 'HTML';
        $options['disable_web_page_preview'] ??= true;

        $result = $this->call('sendMessage', $options);

        if ($result['ok'] ?? false) {
            return $result;
        }

        // در صورت خطای HTML، یک‌بار با متن ساده تلاش می‌کنیم.
        $description = (string) ($result['description'] ?? '');
        if (str_contains($description, "can't parse entities") || str_contains($description, 'Unsupported start tag')) {
            $plain = $options;
            unset($plain['parse_mode']);
            $plain['text'] = $this->stripHtml($text);

            return $this->call('sendMessage', $plain);
        }

        return $result;
    }

    /**
     * ویرایش پیام.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): array
    {
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
            $params['caption']   = Str::truncate($caption, 1024);
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
        if (strlen($data) > 64) {
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