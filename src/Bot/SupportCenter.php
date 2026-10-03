<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TicketRepository;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * تیکت پشتیبانی داخل ربات.
 *
 * چرا تیکت و نه پیام مستقیم به مدیر؟
 *   ۱) **گم نمی‌شود.** پیام مستقیم در یک چت گم می‌شود و هیچ‌وقت معلوم
 *      نمی‌شود جواب داده شده یا نه.
 *   ۲) **صف دارد.** مدیر می‌بیند کدام مشتری هنوز منتظر است.
 *   ۳) **دوطرفه است.** مشتری می‌تواند زیر پاسخ مدیر بنویسد بدون اینکه
 *      پنل ادمین را باز کند.
 *
 * جریان کاربر:
 *   🎫 پشتیبانی → انتخاب موضوع → نوشتن متن → تیکت ساخته می‌شود و ادمین
 *   پیام می‌گیرد. بعد با «📋 تیکت‌های من» وضعیت و پاسخ‌ها را می‌بیند.
 */
final class SupportCenter
{
    private BotApi $bot;
    private Notifier $notifier;
    private TicketRepository $tickets;
    private Settings $settings;
    private FeatureFlags $flags;
    private SessionStore $sessions;

    public function __construct(
        BotApi $bot,
        Notifier $notifier,
        ?TicketRepository $tickets = null,
        ?Settings $settings = null,
        ?FeatureFlags $flags = null,
        ?SessionStore $sessions = null
    ) {
        $this->bot       = $bot;
        $this->notifier  = $notifier;
        $this->tickets   = $tickets ?? new TicketRepository();
        $this->settings  = $settings ?? new Settings();
        $this->flags     = $flags ?? new FeatureFlags($this->settings);
        $this->sessions  = $sessions ?? new SessionStore();
    }

    // ------------------------------------------------------------------
    // سمت کاربر
    // ------------------------------------------------------------------

    /**
     * نقطهٔ شروع: انتخاب موضوع یا دیدن تیکت‌های قبلی.
     *
     * @param array<string, mixed> $user
     */
    public function start(int $chatId, array $user): void
    {
        if (!$this->flags->isTicketsEnabled()) {
            $this->disabled($chatId);
            return;
        }

        $open = count(array_filter(
            $this->tickets->listByUser((int) $user['id'], 20),
            static fn (array $t): bool => $t['status'] !== TicketRepository::STATUS_CLOSED
        ));

        $lines = ['🎫✨ <b>پشتیبانی ربات</b>', ''];

        if ($open > 0) {
            $lines[] = '📋 تیکت باز شما: <b>' . Str::faNumber($open) . '</b>';
            $lines[] = '';
        }

        $lines[] = 'برای طرح مشکل یا سؤال، موضوع را انتخاب کنید و متن خود را بنویسید.';
        $lines[] = '';
        $lines[] = '💡 پاسخ مدیر همین‌جا و به‌صورت پیام برای شما فرستاده می‌شود.';

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '➕ تیکت جدید', 'data' => BotApi::encodeData('ticket.new')]],
                [['text' => '📋 تیکت‌های من', 'data' => BotApi::encodeData('ticket.list')]],
                Keyboard::back('menu'),
            ])),
        ]);
    }

    /**
     * انتخاب موضوع تیکت.
     */
    public function askCategory(int $chatId, int $telegramId): void
    {
        if (!$this->flags->isTicketsEnabled()) {
            $this->disabled($chatId);
            return;
        }

        $this->sessions->set($telegramId, ['step' => 'ticket:category']);

        $rows = [];

        foreach (TicketRepository::CATEGORIES as $key => $label) {
            $rows[] = [[
                'text' => $label,
                'data' => BotApi::encodeData('ticket.topic', ['c' => $key]),
            ]];
        }

        $rows[] = Keyboard::back('menu', '🔙 بازگشت به منو');

        $this->bot->sendMessage($chatId, '🎫 موضوع تیکت را انتخاب کنید 👇', [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($rows)),
        ]);
    }

    /**
     * درخواست نوشتن متن تیکت.
     */
    public function askBody(int $chatId, int $telegramId, int $ticketId = 0, string $category = 'other'): void
    {
        if (!$this->flags->isTicketsEnabled()) {
            $this->disabled($chatId);
            return;
        }

        $this->sessions->set($telegramId, [
            'step'     => 'ticket:body',
            'ticket_id' => $ticketId,
            'category' => in_array($category, TicketRepository::CATEGORY_KEYS, true) ? $category : 'other',
        ]);

        $title = $ticketId > 0
            ? '💬 پاسخ خود را برای تیکت #' . Str::faNumber($ticketId) . ' بنویسید:'
            : '✍️ پیام خود را بنویسید (نام پنل، شمارهٔ سفارش یا مشکل را ذکر کنید):';

        $this->bot->sendMessage($chatId, $title, [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '❌ انصراف', 'data' => BotApi::encodeData('menu')]],
            ])),
        ]);
    }

    /**
     * ثبت تیکت تازه یا پاسخ کاربر به تیکت موجود.
     *
     * @param array<string, mixed> $user
     */
    public function submit(int $chatId, array $user, string $category, string $body, int $ticketId = 0): void
    {
        $userId = (int) $user['id'];

        if ($ticketId > 0) {
            $ticket = $this->tickets->findForUser($ticketId, $userId);

            if ($ticket === null) {
                $this->bot->sendMessage($chatId, Text::notFound());
                return;
            }

            $this->tickets->replyAsUser($ticketId, $body);

            $this->bot->sendMessage($chatId, '✅ پاسخ شما ثبت شد. به‌زودی پاسخ می‌دهیم. ⏳', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🧾 مشاهدهٔ تیکت', 'data' => BotApi::encodeData('ticket.view', ['id' => $ticketId])]],
                    Keyboard::back('menu'),
                ])),
            ]);

            $this->notifyAdminsOfReply($ticket, $body);

            return;
        }

        $id = $this->tickets->create($userId, $category, $body);
        $ticket = $this->tickets->find($id);

        $this->bot->sendMessage($chatId, implode("\n", [
            '✅ تیکت شما ثبت شد. 🎫',
            '',
            '🔢 شمارهٔ تیکت: <code>#' . Str::faNumber($id) . '</code>',
            '📂 موضوع: ' . TicketRepository::categoryLabel($category),
            '',
            'پاسخ مدیر از همین‌جا فرستاده می‌شود. ⏳',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '🧾 مشاهدهٔ تیکت', 'data' => BotApi::encodeData('ticket.view', ['id' => $id])]],
                [['text' => '➕ تیکت جدید', 'data' => BotApi::encodeData('ticket.new')]],
                Keyboard::back('menu'),
            ])),
        ]);

        $this->notifyAdminsOfNew($ticket, $user);
    }

    /**
     * فهرست تیکت‌های کاربر.
     *
     * @param array<string, mixed> $user
     */
    public function showMyTickets(int $chatId, array $user): void
    {
        $items = $this->tickets->listByUser((int) $user['id'], 10);

        if ($items === []) {
            $this->bot->sendMessage($chatId, '📭📋 تا این لحظه تیکتی ثبت نکرده‌اید! 😌', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '➕ ثبت تیکت', 'data' => BotApi::encodeData('ticket.new')]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return;
        }

        $lines = ['📋✨ <b>تیکت‌های من</b>', ''];
        $keyboard = [];

        foreach ($items as $ticket) {
            $lines[] = TicketRepository::statusLabel($ticket) . ' #'
                . Str::faNumber((int) $ticket['id']) . ' — '
                . Str::truncate((string) $ticket['subject'], 34);
            $lines[] = '   📂 ' . TicketRepository::categoryLabel((string) $ticket['category'])
                . ' • ' . Str::dateShort((int) $ticket['created_at']);

            $keyboard[] = [[
                'text' => TicketRepository::statusLabel($ticket) . ' #' . Str::faNumber((int) $ticket['id']),
                'data' => BotApi::encodeData('ticket.view', ['id' => (int) $ticket['id']]),
            ]];
        }

        $keyboard[] = [['text' => '➕ تیکت جدید', 'data' => BotApi::encodeData('ticket.new')]];
        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    /**
     * نمایش یک تیکت با همهٔ پیام‌ها.
     *
     * @param array<string, mixed> $user
     */
    public function showTicket(int $chatId, array $user, int $ticketId): void
    {
        $ticket = $this->tickets->findForUser($ticketId, (int) $user['id']);

        if ($ticket === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->bot->sendMessage($chatId, $this->threadText($ticket), [
            'reply_markup' => $this->bot->buildMarkup($this->ticketKeyboard($ticket)),
        ]);
    }

    /**
     * بستن تیکت توسط خود کاربر.
     *
     * @param array<string, mixed> $user
     */
    public function closeTicket(int $chatId, array $user, int $ticketId): void
    {
        $ticket = $this->tickets->findForUser($ticketId, (int) $user['id']);

        if ($ticket === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->tickets->close($ticketId);

        $fresh = $this->tickets->find($ticketId) ?? $ticket;

        $this->bot->sendMessage($chatId, $this->threadText($fresh), [
            'reply_markup' => $this->bot->buildMarkup($this->ticketKeyboard($fresh)),
        ]);
    }

    /**
     * متن کامل یک تیکت (تیکت + همهٔ پیام‌ها).
     *
     * @param array<string, mixed> $ticket
     */
    public function threadText(array $ticket): string
    {
        $lines = [
            '🎫✨ <b>تیکت #' . Str::faNumber((int) $ticket['id']) . '</b>',
            '📂 موضوع: ' . TicketRepository::categoryLabel((string) $ticket['category']),
            '📶 وضعیت: ' . TicketRepository::statusLabel($ticket),
            '📅 ثبت: ' . Str::date((int) $ticket['created_at']),
            '',
            '💬✨ <b>گفت‌وگو</b>',
        ];

        foreach ($this->tickets->messages((int) $ticket['id'], 30) as $message) {
            $who = (string) $message['from_side'] === 'admin' ? '🛠 مدیریت' : '👤 شما';

            $lines[] = '';
            $lines[] = $who . ' — ' . Str::dateShort((int) $message['created_at']);
            $lines[] = Str::escape(Str::truncate((string) $message['body'], 600));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function ticketKeyboard(array $ticket): array
    {
        $ticketId = (int) $ticket['id'];
        $closed   = (string) $ticket['status'] === TicketRepository::STATUS_CLOSED;

        $rows = [];

        if (!$closed) {
            $rows[] = [[
                'text' => '💬 پاسخ دادن',
                'data' => BotApi::encodeData('ticket.reply', ['id' => $ticketId]),
            ]];
            $rows[] = [[
                'text' => '🔒 بستن تیکت',
                'data' => BotApi::encodeData('ticket.close', ['id' => $ticketId]),
            ]];
        }

        $rows[] = [['text' => '📋 تیکت‌های من', 'data' => BotApi::encodeData('ticket.list')]];
        $rows[] = Keyboard::back('menu');

        return Keyboard::rows($rows);
    }

    private function disabled(int $chatId): void
    {
        $this->bot->sendMessage($chatId, '🎫😴 سیستم تیکت موقتاً غیرفعال است! برای ارتباط فوری از پشتیبانی تماس بگیرید. 📞');
    }

    // ------------------------------------------------------------------
    // اعلان به مدیر
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $ticket
     * @param array<string, mixed> $user
     */
    private function notifyAdminsOfNew(?array $ticket, array $user): void
    {
        if ($ticket === null) {
            return;
        }

        $telegramId = (int) ($user['telegram_id'] ?? 0);

        $this->notifier->notifyAdmins(implode("\n", [
            '🎫 <b>تیکت جدید پشتیبانی</b>',
            '',
            '🔢 شماره: <code>#' . Str::faNumber((int) $ticket['id']) . '</code>',
            '👤 کاربر: <code>' . $telegramId . '</code>'
                . (($user['first_name'] ?? '') !== '' ? ' (' . Str::escape((string) $user['first_name']) . ')' : ''),
            '📂 موضوع: ' . TicketRepository::categoryLabel((string) $ticket['category']),
            '',
            '💬 ' . Str::escape(Str::truncate((string) $ticket['subject'], 300)),
        ]), [
            'text' => '🧾 باز کردن تیکت',
            'data' => BotApi::encodeData('admin.ticket.view', ['id' => (int) $ticket['id']]),
        ]);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function notifyAdminsOfReply(array $ticket, string $body): void
    {
        $this->notifier->notifyAdmins(implode("\n", [
            '💬 <b>پاسخ جدید کاربر به تیکت #' . Str::faNumber((int) $ticket['id']) . '</b>',
            '',
            '💬 ' . Str::escape(Str::truncate($body, 300)),
        ]), [
            'text' => '🧾 باز کردن تیکت',
            'data' => BotApi::encodeData('admin.ticket.view', ['id' => (int) $ticket['id']]),
        ]);
    }
}