<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;
use Pasargad\Support\Str;

/**
 * مخزن تیکت پشتیبانی.
 *
 * چرا تیکت و نه فقط پیام خصوصی به مدیر؟
 *   • پیام خصوصی گم می‌شود؛ تیکت تاریخچه دارد و وضعیتش مشخص است.
 *   • نماینده می‌تواند ببیند پاسخی آمده یا نه، بدون اینکه مدیر را مزاحم کند.
 *   • مدیر می‌تواند تیکت‌های باز را صف کند و ببیند کدام مشتری هنوز جواب
 *     نگرفته است.
 *
 * هر تیکت چند پیام دارد (`ticket_messages`) تا گفت‌وگو یک‌طرفه نباشد.
 */
final class TicketRepository
{
    public const STATUS_OPEN     = 'open';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_CLOSED   = 'closed';

    public const CATEGORIES = [
        'payment'   => '💳 مشکل پرداخت',
        'panel'     => '🖥 مشکل پنل یا ورود',
        'account'   => '👤 مشکل حساب نمایندگی',
        'test'      => '🧪 تست کانفیگ',
        'suggest'   => '💡 پیشنهاد و انتقاد',
        'other'     => '📩 سایر موارد',
    ];

    public const CATEGORY_KEYS = ['payment', 'panel', 'account', 'test', 'suggest', 'other'];

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? (self::CATEGORIES['other']);
    }

    /**
     * ساخت تیکت + اولین پیام.
     *
     * @return int شناسهٔ تیکت
     */
    public function create(int $userId, string $category, string $body): int
    {
        $body = trim($body);

        $firstLine = Str::truncate($body, 60);

        return $this->db->transaction(function () use ($userId, $category, $body, $firstLine): int {
            $ticketId = $this->db->insert('tickets', [
                'user_id'    => $userId,
                'category'   => in_array($category, self::CATEGORY_KEYS, true) ? $category : 'other',
                'subject'    => $firstLine,
                'status'     => self::STATUS_OPEN,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            $this->db->insert('ticket_messages', [
                'ticket_id'  => $ticketId,
                'from_side'  => 'user',
                'body'       => $body,
                'created_at' => time(),
            ]);

            return $ticketId;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM tickets WHERE id = ?', [$id]);
    }

    /**
     * تیکت با بررسی مالکیت (برای نماینده).
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->db->first('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /**
     * تیکت‌های یک کاربر (تازه‌ترین اول).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(int $userId, int $limit = 10): array
    {
        return $this->db->all(
            'SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT '
            . max(1, min($limit, 50)),
            [$userId]
        );
    }

    /**
     * تیکت‌های باز برای صف پشتیبانی.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOpen(int $limit = 20): array
    {
        return $this->db->all(
            "SELECT t.*, u.telegram_id, u.first_name
             FROM tickets t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.status IN ('open','answered')
               AND u.is_blocked = 0
             ORDER BY CASE t.status WHEN 'open' THEN 0 ELSE 1 END, t.id DESC
             LIMIT " . max(1, min($limit, 50))
        );
    }

    public function countOpen(): int
    {
        return $this->db->count("SELECT COUNT(*) FROM tickets WHERE status = 'open'");
    }

    public function countAll(): int
    {
        return $this->db->count('SELECT COUNT(*) FROM tickets');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $ticketId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id ASC LIMIT '
            . max(1, min($limit, 200)),
            [$ticketId]
        );
    }

    /**
     * افزودن پیام از سمت کاربر (تیکت دوباره باز می‌شود).
     */
    public function replyAsUser(int $ticketId, string $body): bool
    {
        return $this->addMessage($ticketId, 'user', $body, true);
    }

    /**
     * افزودن پیام از سمت مدیر (وضعیت «answered» می‌شود).
     */
    public function replyAsAdmin(int $ticketId, string $body): bool
    {
        return $this->addMessage($ticketId, 'admin', $body, false);
    }

    /**
     * @param bool $reopen آیا تیکت بسته‌شده دوباره باز شود؟
     */
    private function addMessage(int $ticketId, string $side, string $body, bool $reopen): bool
    {
        $body = trim($body);

        if ($body === '') {
            return false;
        }

        return $this->db->transaction(function () use ($ticketId, $side, $body, $reopen): bool {
            $ticket = $this->find($ticketId);

            if ($ticket === null) {
                return false;
            }

            $this->db->insert('ticket_messages', [
                'ticket_id'  => $ticketId,
                'from_side'  => $side,
                'body'       => $body,
                'created_at' => time(),
            ]);

            $patch = ['updated_at' => time()];

            if ($side === 'admin') {
                $patch['status']         = self::STATUS_ANSWERED;
                $patch['admin_reply_at'] = time();
            } elseif ($reopen && $ticket['status'] === self::STATUS_CLOSED) {
                $patch['status'] = self::STATUS_OPEN;
            }

            $this->db->update('tickets', $patch, ['id' => $ticketId]);

            return true;
        });
    }

    public function close(int $ticketId): bool
    {
        return $this->db->update('tickets', [
            'status'     => self::STATUS_CLOSED,
            'closed_at'  => time(),
            'updated_at' => time(),
        ], ['id' => $ticketId]) > 0;
    }

    public function reopen(int $ticketId): bool
    {
        return $this->db->update('tickets', [
            'status'     => self::STATUS_OPEN,
            'closed_at'  => null,
            'updated_at' => time(),
        ], ['id' => $ticketId]) > 0;
    }

    /**
     * برچسب وضعیت فارسی.
     *
     * @param array<string, mixed> $ticket
     */
    public static function statusLabel(array $ticket): string
    {
        return match ((string) $ticket['status']) {
            self::STATUS_ANSWERED => '✅ پاسخ داده شده',
            self::STATUS_CLOSED   => '🔒 بسته شده',
            default               => '🕓 در انتظار پاسخ',
        };
    }
}