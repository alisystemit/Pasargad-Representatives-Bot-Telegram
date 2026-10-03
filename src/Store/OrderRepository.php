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
    public const STATUS_REJECTED        = 'rejected';   // پایانی: نباید دوباره اجرا شود

    /**
     * وضعیت‌هایی که یعنی سفارش «تمام شده» و هرگز نباید اجرا شود.
     *
     * rejected  : پرداخت توسط سوپرادمین رد شد
     * cancelled : لغو توسط کاربر یا مدیر
     * refunded  : بازگشت وجه
     * applied   : با موفقیت اجرا شد
     *
     * @var array<int, string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    /**
     * آیا این وضعیت پایانی است (اجرای دوباره ممنوع)؟
     */
    public function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * اتصال دیتابیس این مخزن (برای کلاس‌هایی مثل Invoice که باید روی
     * همین دیتابیس کوئری بزنند).
     */
    public function db(): Db
    {
        return $this->db;
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
     * انتقال وضعیت سفارش به «در حال اعمال» — تنها دروازهٔ ورود به اجرای بسته.
     *
     * این متد **مرجع نهایی** تصمیم «آیا این سفارش قابل اجرا است؟» است و
     * compare-and-swap است، بنابراین دو پروسهٔ همزمان فقط یکی موفق می‌شوند.
     *
     * شرط‌های لازم (همه باید برقرار باشند):
     *   • status ∈ (paid, failed)     — نه created و نه awaiting_payment
     *   • terminal_reason IS NULL     — سفارش با دلیل پایانی متوقف نشده باشد
     *   • panel_applied = 0           — قبلاً روی پنل اعمال نشده باشد
     *   • paid_at IS NOT NULL         — **واقعاً پرداخت شده باشد**
     *
     * شرط `paid_at` حیاتی است: pendingApply آن را دارد، اما provision() هم
     * مستقیم از مسیر «اجرای دستی ادمین» و «پس از پرداخت» صدا زده می‌شود.
     * بدون آن، سفارشی که فقط status=paid دارد ولی paid_at ندارد (یعنی
     * پرداخت نشده) می‌توانست بستهٔ رایگان بگیرد.
     */
    public function markApplying(int $id): bool
    {
        return $this->db->run(
            'UPDATE orders SET status = :status, updated_at = :t
             WHERE id = :id
               AND status IN (:s1, :s2)
               AND terminal_reason IS NULL
               AND panel_applied = 0
               AND paid_at IS NOT NULL',
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
        $payload = [
            'status'      => self::STATUS_PAID,
            'paid_at'     => time(),
            'updated_at'  => time(),
            'error'       => null,
            // پاک کردن هر دلیل پایانی قبلی تا این سفارش دوباره پردازش شود.
            'terminal_reason' => null,
        ];

        if ($paymentMethod !== null) {
            $payload['payment_method'] = $paymentMethod;
        }
        if ($paymentRef !== null) {
            $payload['payment_ref'] = $paymentRef;
        }

        // شرط در همان UPDATE اعمال می‌شود تا دو درخواست همزمان
        // (مثلاً دو بار رسیدن IPN) هرگز هر دو true برنگردانند.
        $sets      = [];
        $params    = [];
        foreach ($payload as $column => $value) {
            $sets[]              = $column . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $params['id'] = $id;

        return $this->db->run(
            'UPDATE orders SET ' . implode(', ', $sets) . '
             WHERE id = :id
               AND status NOT IN (\'paid\', \'applied\', \'applying\', \'rejected\', \'cancelled\', \'refunded\')',
            $params
        )->rowCount() > 0;
    }

    /**
     * علامت‌گذاری سفارش با وضعیت پایانی (رد شده / لغو شده / بازگشت وجه).
     *
     * terminal_reason پر می‌شود تا حتی اگر کسی اشتباهاً status را عوض کند،
     * pendingApply دیگر آن را برنمی‌دارد.
     */
    public function markTerminal(int $id, string $status, string $reason = '', ?string $note = null): void
    {
        $this->db->update('orders', [
            'status'          => $status,
            'terminal_reason' => $reason !== '' ? $reason : $status,
            'next_attempt_at' => null,
            'error'           => Str::truncate($note ?? $reason, 500),
            'updated_at'      => time(),
        ], ['id' => $id]);
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
     * ذخیرهٔ اتمیک سقف هدف — اگر قبلاً ثبت شده باشد false برمی‌گرداند.
     *
     * compare-and-swap تضمین می‌کند دو پروسهٔ همزمان هرگز دو هدف متفاوت
     * برای یک سفارش ننویسند (وگرنه حجم می‌توانست دوباره اعمال شود).
     */
    public function reserveTargetLimit(int $orderId, int $targetLimit): bool
    {
        return $this->db->run(
            'UPDATE orders SET target_limit = :t, updated_at = :now
             WHERE id = :id AND target_limit IS NULL',
            ['t' => $targetLimit, 'now' => time(), 'id' => $orderId]
        )->rowCount() > 0;
    }

    /**
     * علامت‌گذاری اینکه بسته روی پنل (یا اعتبار) اعمال شده است.
     *
     * اگر قبلاً علامت خورده باشد false برمی‌گرداند تا از اجرای دوباره جلوگیری شود.
     */
    public function markPanelApplied(int $orderId, int $afterLimit): bool
    {
        return $this->db->run(
            'UPDATE orders SET panel_applied = 1, after_limit = :a, updated_at = :now
             WHERE id = :id AND panel_applied = 0',
            ['a' => $afterLimit, 'now' => time(), 'id' => $orderId]
        )->rowCount() > 0;
    }

    /**
     * اجرای یک واحد کار در تراکنش دیتابیس.
     *
     * برای عملیات چندمرحله‌ای که باید اتمیک باشند؛ مثل «بررسی سقف خرید کاربر
     * و ساخت سفارش» که بدون تراکنش در دو درخواست همزمان هر دو از سقف عبور
     * می‌کنند.
     *
     * @param callable():mixed $callback
     * @return mixed خروجی callback یا null در صورت throw
     */
    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    /**
     * سفارش‌های آمادهٔ پردازش خودکار.
     *
     * نکتهٔ امنیتی حیاتی: سفارش‌های «رد شده توسط ادمین» و «لغو شده» نباید
     * هرگز از این مسیر عبور کنند. همچنین سفارشی که قبلاً روی پنل اعمال شده
     * (panel_applied = 1) نباید دوباره اجرا شود حتی اگر وضعیتش عوض شود.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingApply(int $limit = 10): array
    {
        return $this->db->all(
            "SELECT * FROM orders
             WHERE status IN ('paid','failed')
               AND terminal_reason IS NULL
               AND panel_applied = 0
               AND attempts < :max_attempts
               AND paid_at IS NOT NULL
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY
               CASE WHEN status = 'paid' THEN 0 ELSE 1 END ASC,
               id ASC
             LIMIT " . max(1, min($limit, 50)),
            [
                'now'          => time(),
                'max_attempts' => \Pasargad\Support\Config::int('worker.max_attempts', 5),
            ]
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
     * ثبت یا به‌روزرسانی تلاش پرداخت برای یک سفارش و روش.
     *
     * به‌جای createPayment استفاده می‌شود چون کاربر ممکن است چند بار روی
     * دکمهٔ پرداخت بزند؛ ثبت تکراری نباید خطای UNIQUE بدهد.
     *
     * @param  array<string, mixed> $data
     * @return int شناسهٔ رکورد پرداخت
     */
    public function upsertPayment(int $orderId, array $data): int
    {
        $existing = $this->db->first(
            'SELECT id FROM payments WHERE order_id = ? AND method = ? LIMIT 1',
            [$orderId, (string) ($data['method'] ?? '')]
        );

        if ($existing !== null) {
            $data['updated_at'] = time();
            $this->db->update('payments', $data, ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        return $this->createPayment($orderId, $data);
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

    /**
     * تاریخچهٔ پرداخت‌های یک کاربر (برای «تاریخچه پرداخت‌ها» 🧾).
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentsForUser(int $userId, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT p.* FROM payments p
              INNER JOIN orders o ON o.id = p.order_id
              WHERE o.user_id = ?
              ORDER BY p.id DESC LIMIT ' . max(1, min($limit, 30)),
            [$userId]
        );
    }

    public function updatePayment(int $paymentId, array $data): void
    {
        $data['updated_at'] = time();
        $this->db->update('payments', $data, ['id' => $paymentId]);
    }

    /**
     * سفارش‌های کارت‌به‌کارت خودکار در انتظار تأیید (برای کرون).
     *
     * فقط سفارش‌هایی که پرداختشان هنوز باز است (pending/waiting) برمی‌گردند؛
     * فاکتورهای منقضی‌شده یا تأییدشده دوباره استعلام نمی‌شوند تا بار
     * بیهوده به سرویس استعلام وارد نشود.
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingAutoCard(int $limit = 20): array
    {
        return $this->db->all(
            "SELECT o.* FROM orders o
              WHERE o.status = :st AND o.payment_method = :method
                AND EXISTS (
                    SELECT 1 FROM payments p
                    WHERE p.order_id = o.id AND p.method = :method
                      AND p.status IN ('pending', 'waiting')
                )
              ORDER BY o.id ASC LIMIT " . max(1, min($limit, 50)),
            ['st' => self::STATUS_AWAITING_PAYMENT, 'method' => \Pasargad\Payment\AutoCardGateway::NAME]
        );
    }

    /**
     * آیا مبلغ یکتایی در فاکتور باز دیگری استفاده شده است؟
     */
    public function existsPendingAutoCardAmount(int $amount, ?int $excludeOrderId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM payments p
                 INNER JOIN orders o ON o.id = p.order_id
                 WHERE p.method = :method AND p.amount_toman = :amount
                   AND p.status IN ('pending', 'waiting')
                   AND o.status = :st";
        $params = [
            'method' => \Pasargad\Payment\AutoCardGateway::NAME,
            'amount' => $amount,
            'st'     => self::STATUS_AWAITING_PAYMENT,
        ];

        if ($excludeOrderId !== null && $excludeOrderId > 0) {
            $sql .= ' AND p.order_id != :ex';
            $params['ex'] = $excludeOrderId;
        }

        return $this->db->count($sql, $params) > 0;
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