<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;
use Pasargad\Support\Str;

/**
 * مخزن سفارش‌ها و پرداخت‌ها.
 */
final class OrderRepository
{
    public const STATUS_CREATED         = 'created';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID            = 'paid';
    public const STATUS_APPLYING        = 'applying';
    public const STATUS_APPLIED         = 'applied';
    public const STATUS_FAILED          = 'failed';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_REFUNDED        = 'refunded';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * ساخت سفارش جدید.
     *
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): array
    {
        $now = time();

        $code = (string) ($data['code'] ?? Str::orderCode());
        // اطمینان از یکتا بودن کد سفارش
        $guard = 0;
        while ($this->db->first('SELECT id FROM orders WHERE code = ?', [$code]) !== null && $guard < 10) {
            $code = Str::orderCode();
            $guard++;
        }

        $id = $this->db->insert('orders', array_merge([
            'code'          => $code,
            'user_id'       => $userId,
            'status'        => self::STATUS_CREATED,
            'payment_method' => $data['payment_method'] ?? null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ], $data, ['code' => $code]));

        $order = $this->find($id);
        if ($order === null) {
            throw new \RuntimeException('سفارش پس از ایجاد قابل بازیابی نبود.');
        }

        return $order;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM orders WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        return $this->db->first('SELECT * FROM orders WHERE code = ?', [strtoupper(trim($code))]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->db->first('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /**
     * به‌روزرسانی وضعیت سفارش.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = time();
        $this->db->update('orders', $data, ['id' => $id]);
    }

    /**
     * انتقال وضعیت سفارش به «در حال اعمال».
     *
     * فقط سفارش‌هایی که هنوز اجرا نشده‌اند پذیرفته می‌شوند؛ سفارش
     * applied دوباره اجرا نمی‌شود تا حجم دو بار اضافه نشود.
     */
    public function markApplying(int $id): bool
    {
        return $this->db->run(
            'UPDATE orders SET status = :status, updated_at = :t
             WHERE id = :id AND status IN (:s1, :s2)',
            [
                'status' => self::STATUS_APPLYING,
                't'      => time(),
                'id'     => $id,
                's1'     => self::STATUS_PAID,
                's2'     => self::STATUS_FAILED,
            ]
        )->rowCount() > 0;
    }

    /**
     * علامت‌گذاری سفارش به‌عنوان پرداخت‌شده (فقط اگر قبلاً پرداخت نشده بود).
     */
    public function markPaid(int $id, ?string $paymentMethod = null, ?string $paymentRef = null): bool
    {
        $current = $this->find($id);
        if ($current === null) {
            return false;
        }

        if (in_array((string) $current['status'], [self::STATUS_PAID, self::STATUS_APPLIED], true)) {
            return false;   // قبلاً پرداخت شده — از دوباره اعمال نکن
        }

        $payload = [
            'status'         => self::STATUS_PAID,
            'paid_at'        => time(),
            'updated_at'     => time(),
            'error'          => null,
        ];

        if ($paymentMethod !== null) {
            $payload['payment_method'] = $paymentMethod;
        }
        if ($paymentRef !== null) {
            $payload['payment_ref'] = $paymentRef;
        }

        $this->db->update('orders', $payload, ['id' => $id]);

        return true;
    }

    /**
     * محاسبهٔ زمان تلاش مجدد بعدی (نمایی).
     */
    public function nextAttemptDelay(int $attempts): int
    {
        $backoff = \Pasargad\Support\Config::arr('worker.retry_backoff');
        if ($backoff === []) {
            $backoff = [30, 60, 300, 900];
        }

        $index = min(max(0, $attempts - 1), count($backoff) - 1);

        return (int) $backoff[$index];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(int $userId, int $limit = 10, int $offset = 0, ?string $status = null): array
    {
        $sql = 'SELECT * FROM orders WHERE user_id = :uid';
        $params = ['uid' => $userId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :st';
            $params['st'] = $status;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min($limit, 50)) . ' OFFSET ' . max(0, $offset);

        return $this->db->all($sql, $params);
    }

    public function countByUser(int $userId, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM orders WHERE user_id = :uid';
        $params = ['uid' => $userId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :st';
            $params['st'] = $status;
        }

        return $this->db->count($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $limit = 20, int $offset = 0, ?string $status = null, string $search = ''): array
    {
        $sql    = 'SELECT o.*, u.telegram_id, u.username AS tg_username, u.panel_username FROM orders o
                   LEFT JOIN users u ON u.id = o.user_id WHERE 1=1';
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' AND o.status = :st';
            $params['st'] = $status;
        }

        if ($search !== '') {
            $sql .= ' AND (o.code LIKE :like OR u.panel_username LIKE :like)';
            $params['like'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY o.id DESC LIMIT ' . max(1, min($limit, 100)) . ' OFFSET ' . max(0, $offset);

        return $this->db->all($sql, $params);
    }

    public function countAll(?string $status = null): int
    {
        if ($status !== null && $status !== '') {
            return $this->db->count('SELECT COUNT(*) FROM orders WHERE status = ?', [$status]);
        }

        return $this->db->count('SELECT COUNT(*) FROM orders');
    }

    /**
     * سفارش‌های آمادهٔ پردازش خودکار (paid یا failed با تلاش مجدد).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingApply(int $limit = 10): array
    {
        return $this->db->all(
            "SELECT * FROM orders
             WHERE status IN ('paid','failed')
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY
               CASE WHEN status = 'paid' THEN 0 ELSE 1 END ASC,
               id ASC
             LIMIT " . max(1, min($limit, 50)),
            ['now' => time()]
        );
    }

    /**
     * سفارش‌های در انتظار تأیید دستی رسید کارت‌به‌کارت.
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingReview(int $limit = 10, int $offset = 0): array
    {
        return $this->db->all(
            'SELECT * FROM orders
             WHERE status = :status AND receipt_file_id IS NOT NULL
             ORDER BY id DESC LIMIT ' . max(1, min($limit, 50)) . ' OFFSET ' . max(0, $offset),
            ['status' => self::STATUS_AWAITING_PAYMENT]
        );
    }

    public function countAwaitingReview(): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM orders WHERE status = ? AND receipt_file_id IS NOT NULL',
            [self::STATUS_AWAITING_PAYMENT]
        );
    }

    // ------------------------------------------------------------------
    // پرداخت‌ها
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function createPayment(int $orderId, array $data): int
    {
        $now = time();

        return $this->db->insert('payments', array_merge([
            'order_id'     => $orderId,
            'status'       => 'pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $data));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPaymentByExternal(string $externalId): ?array
    {
        return $this->db->first('SELECT * FROM payments WHERE external_id = ?', [$externalId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paymentsForOrder(int $orderId): array
    {
        return $this->db->all('SELECT * FROM payments WHERE order_id = ? ORDER BY id ASC', [$orderId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastPayment(int $orderId): ?array
    {
        return $this->db->first('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
    }

    public function updatePayment(int $paymentId, array $data): void
    {
        $data['updated_at'] = time();
        $this->db->update('payments', $data, ['id' => $paymentId]);
    }

    // ------------------------------------------------------------------
    // لاگ اعمال بسته
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $context
     */
    public function logProvision(int $orderId, string $status, string $message = '', array $context = []): void
    {
        $this->db->insert('provision_logs', [
            'order_id'   => $orderId,
            'status'     => $status,
            'request'    => isset($context['request']) ? json_encode($context['request'], JSON_UNESCAPED_UNICODE) : null,
            'response'   => isset($context['response']) ? json_encode($context['response'], JSON_UNESCAPED_UNICODE) : null,
            'message'    => Str::truncate($message, 1000),
            'created_at' => time(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function provisionLogs(int $orderId, int $limit = 5): array
    {
        return $this->db->all(
            'SELECT * FROM provision_logs WHERE order_id = ? ORDER BY id DESC LIMIT ' . max(1, min($limit, 20)),
            [$orderId]
        );
    }

    // ------------------------------------------------------------------
    // آمار
    // ------------------------------------------------------------------

    /**
     * @return array<string, int|float>
     */
    public function stats(): array
    {
        $row = $this->db->first(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status IN ('paid','applied') THEN 1 ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END), 0) AS applied,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                COALESCE(SUM(CASE WHEN status = 'awaiting_payment' THEN 1 ELSE 0 END), 0) AS awaiting,
                COALESCE(SUM(CASE WHEN status IN ('paid','applied') THEN price_toman ELSE 0 END), 0) AS revenue
             FROM orders"
        ) ?? [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'paid'     => (int) ($row['paid'] ?? 0),
            'applied'  => (int) ($row['applied'] ?? 0),
            'failed'   => (int) ($row['failed'] ?? 0),
            'awaiting' => (int) ($row['awaiting'] ?? 0),
            'revenue'  => (int) ($row['revenue'] ?? 0),
        ];
    }

    /**
     * مجموع حجم فروش‌رفته به تفکیک نوع بسته (گیگابایت).
     */
    public function soldVolume(): array
    {
        $rows = $this->db->all(
            "SELECT kind, COALESCE(SUM(volume_gb + bonus_gb), 0) AS total_gb
             FROM orders WHERE status IN ('paid','applied') GROUP BY kind"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['kind']] = (float) $row['total_gb'];
        }

        return $out;
    }
}