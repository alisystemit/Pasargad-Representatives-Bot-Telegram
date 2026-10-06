<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;

/**
 * لاگ حسابرسی برای عملیات مالی/اداری.
 *
 * هر ردیف: چه کسی، چه کاری، روی کدام موجودیت، با چه جزئیاتی.
 */
final class AuditLogger
{
    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    public function log(?int $adminId, string $action, string $targetType, ?int $targetId = null, string $details = ''): void
    {
        try {
            $this->db->insert('admin_logs', [
                'admin_user_id' => $adminId ?? 0,
                'action'        => $action,
                'target_type'   => $targetType,
                'target_id'     => $targetId ?? 0,
                'details'       => mb_substr($details, 0, 500),
                'created_at'    => time(),
            ]);
        } catch (\Throwable $e) {
            \Pasargad\Support\Logger::warning('Audit log failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function latest(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return $this->db->all(
            'SELECT * FROM admin_logs ORDER BY id DESC LIMIT ' . $limit
        );
    }
}
