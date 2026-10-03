<?php

declare(strict_types=1);

namespace Pasargad\Telegram;

use Pasargad\Support\Config;

/**
 * کلاینت ساختگی تلگرام برای تست جریان ربات — هیچ درخواستی ارسال نمی‌شود.
 *
 * پیام‌ها و کیبوردها را ثبت می‌کند تا تست بتواند آن‌ها را بررسی کند.
 */
final class FakeBotApi extends BotApi
{
    /** @var array<int, array{chat_id:int, text:string, keyboard:array}> */
    public array $sent = [];

    /** @var array<int, array{chat_id:int, photo:string, caption:string, keyboard:array}> */
    public array $sentPhotos = [];

    /** @var array<int, array{chat_id:int, text:string, keyboard:array}> */
    public array $edits = [];

    /** @var array<int, string> */
    public array $answeredCallbacks = [];

    /**
     * پاسخ‌های کال‌بک به‌همراه متن و وضعیت هشدار.
     *
     * @var array<int, array{id:string, text:string, alert:bool}>
     */
    public array $callbackAnswers = [];

    /**
     * وضعیت عضویت شبیه‌سازی‌شده در کانال، به تفکیک کاربر.
     *
     * مقادیر مجاز همان مقادیر Bot API هستند: creator | administrator |
     * member | restricted | left | kicked
     *
     * @var array<int, string>
     */
    public array $chatMemberStatus = [];

    /** اگر true باشد، getChatMember خطای سرور می‌دهد. */
    public bool $chatMemberFails = false;

    public function __construct()
    {
        // از سازندهٔ والد پرهیز می‌کنیم (نیازمند توکن واقعی است).
    }

    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $this->sent[] = [
            'chat_id'  => $chatId,
            'text'     => $text,
            'keyboard' => $this->decodeMarkup($options['reply_markup'] ?? null),
        ];

        return ['ok' => true, 'result' => ['message_id' => count($this->sent)]];
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): array
    {
        $this->edits[] = [
            'chat_id'  => $chatId,
            'text'     => $text,
            'keyboard' => $this->decodeMarkup($options['reply_markup'] ?? null),
        ];

        return ['ok' => true, 'result' => ['message_id' => $messageId]];
    }

    public function sendPhoto(int $chatId, string $photo, string $caption = '', array $options = []): array
    {
        $this->sentPhotos[] = [
            'chat_id'  => $chatId,
            'photo'    => $photo,
            'caption'  => $caption,
            'keyboard' => $this->decodeMarkup(is_string($options['reply_markup'] ?? null) ? json_decode((string) $options['reply_markup'], true) : ($options['reply_markup'] ?? null)),
        ];

        return ['ok' => true, 'result' => ['message_id' => count($this->sentPhotos)]];
    }

    public function call(string $method, array $params = []): array
    {
        if ($method === 'answerCallbackQuery') {
            $id = (string) ($params['callback_query_id'] ?? '');

            $this->answeredCallbacks[] = $id;
            $this->callbackAnswers[]   = [
                'id'    => $id,
                'text'  => (string) ($params['text'] ?? ''),
                'alert' => (bool) ($params['show_alert'] ?? false),
            ];
        }

        // ------------------------------------------------------------------
        // شبیه‌سازی دروازهٔ عضویت کانال.
        //
        // بدون این، همهٔ تست‌های عضویت اجباری «عضو نیست» می‌دیدند چون
        // پاسخ خالی یعنی status ناموجود، و هیچ‌وقت مسیر «عضو شد» آزموده
        // نمی‌شد — یعنی مهم‌ترین شاخهٔ کد بی‌آزمایش می‌ماند.
        // ------------------------------------------------------------------
        if ($method === 'getChatMember') {
            if ($this->chatMemberFails) {
                return ['ok' => false, 'error_code' => 500, 'description' => 'Internal Server Error'];
            }

            $userId = (int) ($params['user_id'] ?? 0);

            return [
                'ok'     => true,
                'result' => ['status' => $this->chatMemberStatus[$userId] ?? 'left'],
            ];
        }

        return ['ok' => true, 'result' => []];
    }

    public function answerCallback(string $callbackQueryId, string $text = '', bool $alert = false): void
    {
        $this->answeredCallbacks[] = $callbackQueryId;
        $this->callbackAnswers[]   = ['id' => $callbackQueryId, 'text' => $text, 'alert' => $alert];
    }

    public function deleteMessage(int $chatId, int $messageId): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // کمکی‌های بررسی برای تست
    // ------------------------------------------------------------------

    public function reset(): void
    {
        $this->sent       = [];
        $this->sentPhotos = [];
        $this->edits      = [];
        $this->answeredCallbacks = [];
        $this->callbackAnswers   = [];
    }

    /**
     * متن آخرین پاسخِ کال‌بک (حباب هشدار).
     */
    public function lastCallbackAnswer(): string
    {
        return $this->callbackAnswers === [] ? '' : (string) end($this->callbackAnswers)['text'];
    }

    /**
     * متن آخرین پیام ارسال‌شده (یا ویرایش‌شده).
     */
    public function lastText(): string
    {
        if ($this->sent !== []) {
            return (string) end($this->sent)['text'];
        }

        if ($this->edits !== []) {
            return (string) end($this->edits)['text'];
        }

        return '';
    }

    /**
     * متن همهٔ پیام‌های ارسال‌شده (برای تست‌هایی که چند پیام پشت سر هم دارند).
     */
    public function allText(): string
    {
        $parts = array_map(static fn (array $m): string => (string) $m['text'], $this->sent);

        foreach ($this->edits as $edit) {
            $parts[] = (string) $edit['text'];
        }

        return implode("\n=====\n", $parts);
    }

    /**
     * آخرین پیام ارسالی به یک چت مشخص.
     */
    public function lastTextFor(int $chatId): string
    {
        for ($i = count($this->sent) - 1; $i >= 0; $i--) {
            if ((int) $this->sent[$i]['chat_id'] === $chatId) {
                return (string) $this->sent[$i]['text'];
            }
        }

        return '';
    }

    public function lastAdminText(): string
    {
        foreach (Config::arr('super_admins') as $adminId) {
            $text = $this->lastTextFor((int) $adminId);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * همهٔ متن‌ها شامل کپشن عکس‌ها (برای بررسی اعلان رسید به ادمین).
     */
    public function allTextWithCaptions(): string
    {
        $parts = [$this->allText()];

        foreach ($this->sentPhotos as $photo) {
            $parts[] = (string) $photo['caption'];
        }

        return implode("\n=====\n", $parts);
    }

    /**
     * آخرین اعلان ارسالی به کاربر عادی (اولین چت غیرادمین).
     */
    public function lastUserNotice(): string
    {
        $admins = array_map('intval', Config::arr('super_admins'));

        for ($i = count($this->sent) - 1; $i >= 0; $i--) {
            if (!in_array((int) $this->sent[$i]['chat_id'], $admins, true)) {
                return (string) $this->sent[$i]['text'];
            }
        }

        return '';
    }

    public function userGotSuccessNotice(): bool
    {
        return str_contains($this->lastUserNotice(), 'موفقیت اجرا شد');
    }

    public function adminGotPhoto(): bool
    {
        return $this->sentPhotos !== [];
    }

    /**
 * آیا دکمه‌ای با متن مشخص در پیام‌های ارسال‌شده وجود دارد؟
     */
    public function hasButton(string $needle): bool
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($this->searchKeyboard($message['keyboard'], $needle)) {
                return true;
            }
        }

        foreach (array_reverse($this->edits) as $edit) {
            if ($this->searchKeyboard($edit['keyboard'], $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     */
    private function searchKeyboard(array $keyboard, string $needle): bool
    {
        foreach ($keyboard as $row) {
            foreach ($row as $button) {
                if (str_contains((string) ($button['text'] ?? ''), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function decodeMarkup($markup): array
    {
        if ($markup === null) {
            return [];
        }

        if (is_string($markup)) {
            $markup = json_decode($markup, true);
        }

        if (!is_array($markup) || !isset($markup['inline_keyboard'])) {
            return [];
        }

        return (array) $markup['inline_keyboard'];
    }
}