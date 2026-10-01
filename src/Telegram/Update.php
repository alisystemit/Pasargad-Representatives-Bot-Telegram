<?php

declare(strict_types=1);

namespace Pasargad\Telegram;

/**
 * نمایندهٔ ساختارشدهٔ یک آپدیت تلگرام با دسترسی‌های کوتاه.
 */
final class Update
{
    private array $raw;
    private array $message = [];
    private array $callback = [];
    private bool $isCallback;

    public function __construct(array $raw)
    {
        $this->raw         = $raw;
        $this->message    = is_array($raw['message'] ?? null) ? $raw['message'] : [];
        $this->callback   = is_array($raw['callback_query'] ?? null) ? $raw['callback_query'] : [];
        $this->isCallback = $this->callback !== [];
    }

    public function raw(): array
    {
        return $this->raw;
    }

    public function updateId(): int
    {
        return (int) ($this->raw['update_id'] ?? 0);
    }

    public function isCallbackQuery(): bool
    {
        return $this->isCallback;
    }

    /**
     * پیام یا callback را با هم برمی‌گرداند (پیام callback هم موجود است).
     */
    private function payload(): array
    {
        return $this->isCallback ? $this->callback : $this->message;
    }

    public function chatId(): ?int
    {
        $chat = $this->payload()['chat'] ?? null;

        return is_array($chat) ? (int) ($chat['id'] ?? 0) : null;
    }

    public function userId(): ?int
    {
        $from = $this->payload()['from'] ?? null;

        return is_array($from) ? (int) ($from['id'] ?? 0) : null;
    }

    public function messageId(): ?int
    {
        $message = $this->isCallback ? ($this->callback['message'] ?? null) : $this->message;

        return is_array($message) ? (int) ($message['message_id'] ?? 0) : null;
    }

    public function text(): string
    {
        return trim((string) ($this->payload()['text'] ?? ''));
    }

    public function username(): ?string
    {
        $from = $this->payload()['from'] ?? null;
        $name = is_array($from) ? ($from['username'] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    public function firstName(): ?string
    {
        $from = $this->payload()['from'] ?? null;
        $name = is_array($from) ? ($from['first_name'] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    public function languageCode(): ?string
    {
        $from = $this->payload()['from'] ?? null;
        $code = is_array($from) ? ($from['language_code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    public function callbackData(): ?string
    {
        if (!$this->isCallback) {
            return null;
        }

        $data = $this->callback['data'] ?? null;

        return is_string($data) ? $data : null;
    }

    /**
     * تجزیهٔ callback data به آرایهٔ ساختاریافته.
     *
     * @return array<string, mixed>
     */
    public function callbackPayload(): array
    {
        $decoded = json_decode((string) $this->callbackData(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * آیا پیام یک عکس/اسکناس/رسید است؟
     */
    public function hasPhoto(): bool
    {
        return !empty($this->message['photo']);
    }

    /**
     * بزرگ‌ترین اندازهٔ عکس ارسالی.
     *
     * @return array{file_id:string, file_unique_id:string}|null
     */
    public function largestPhoto(): ?array
    {
        $photos = $this->message['photo'] ?? null;
        if (!is_array($photos) || $photos === []) {
            return null;
        }

        $best = $photos[0];
        foreach ($photos as $photo) {
            if (($photo['file_size'] ?? 0) > ($best['file_size'] ?? 0)) {
                $best = $photo;
            }
        }

        return ['file_id' => (string) ($best['file_id'] ?? ''), 'file_unique_id' => (string) ($best['file_unique_id'] ?? '')];
    }

    public function hasDocument(): bool
    {
        return !empty($this->message['document']);
    }

    public function document(): ?array
    {
        $document = $this->message['document'] ?? null;

        return is_array($document) ? $document : null;
    }

    /**
     * شمارهٔ تماس کاربر (درخواست contact).
     */
    public function contactPhone(): ?string
    {
        $contact = $this->message['contact'] ?? null;
        if (!is_array($contact)) {
            return null;
        }

        $phone = $contact['phone_number'] ?? null;

        return is_string($phone) ? $phone : null;
    }

    public function isCommand(): bool
    {
        return str_starts_with($this->text(), '/');
    }

    /**
     * نام دستور بدون @نام_ربات.
     */
    public function command(): string
    {
        $text = $this->text();
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $text) ?: [];
        $first = $parts[0] ?? '';
        $name  = explode('@', ltrim($first, '/'))[0];

        return strtolower($name);
    }

    /**
     * آرگومان‌های دستور (بدون خودِ دستور).
     *
     * @return array<int, string>
     */
    public function args(): array
    {
        $text = $this->text();
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $text) ?: [];
        array_shift($parts);   // حذف خودِ دستور

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}