<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;
use Pasargad\Support\Str;

/**
 * مخزن بسته‌های فروشگاه.
 *
 * دو نوع بسته وجود دارد:
 *   - panel_quota : حجم مستقیماً روی حساب ادمین خریدار در پنل اعمال می‌شود.
 *   - user_credit : حجم به‌عنوان اعتبار ساخت/تمدید کاربران برای ادمین نگه داشته می‌شود.
 */
final class PackageRepository
{
    public const KIND_PANEL_QUOTA = 'panel_quota';
    public const KIND_USER_CREDIT = 'user_credit';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM packages WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM packages WHERE slug = ?', [$slug]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activePackages(): array
    {
        return $this->db->all(
            'SELECT * FROM packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activePackagesByKind(string $kind): array
    {
        return $this->db->all(
            'SELECT * FROM packages WHERE is_active = 1 AND kind = ? ORDER BY sort_order ASC, id ASC',
            [$kind]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allPackages(bool $onlyActive = false): array
    {
        if ($onlyActive) {
            return $this->activePackages();
        }

        return $this->db->all('SELECT * FROM packages ORDER BY sort_order ASC, id ASC');
    }

    /**
     * بستهٔ سفارشی (بدون ذخیره در جدول) — برای خرید با حجم دلخواه.
     *
     * @param array<string, mixed> $data
     */
    public function makeCustom(array $data): array
    {
        return array_merge([
            'id'            => 0,
            'slug'          => 'custom',
            'title'         => 'بستهٔ سفارشی',
            'description'   => null,
            'kind'          => self::KIND_PANEL_QUOTA,
            'volume_gb'     => 0.0,
            'duration_days' => 0,
            'price_toman'   => 0,
            'bonus_gb'      => 0.0,
            'is_active'     => 1,
            'max_per_user'  => 0,
        ], $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = time();

        return $this->db->insert('packages', [
            'slug'          => $this->uniqueSlug((string) ($data['slug'] ?? $data['title'] ?? 'package')),
            'title'         => trim((string) ($data['title'] ?? 'بسته')),
            'description'   => $data['description'] ?? null,
            'kind'          => $data['kind'] ?? self::KIND_PANEL_QUOTA,
            'volume_gb'     => (float) ($data['volume_gb'] ?? 0),
            'duration_days' => (int) ($data['duration_days'] ?? 30),
            'price_toman'   => (int) ($data['price_toman'] ?? 0),
            'bonus_gb'      => (float) ($data['bonus_gb'] ?? 0),
            'sort_order'    => (int) ($data['sort_order'] ?? 0),
            'is_active'     => (int) (bool) ($data['is_active'] ?? true),
            'max_per_user'  => (int) ($data['max_per_user'] ?? 0),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $payload = ['updated_at' => time()];

        // فیلدهای عددی صحیح — فقط is_active بولی است، بقیه عدد واقعی‌اند.
        // (اشتباه قبلی: (int)(bool) قیمت ۵۰۰۰۰۰ را به ۱ تبدیل می‌کرد.)
        $intFields = ['duration_days', 'price_toman', 'sort_order', 'max_per_user'];

        // فیلدهای اعشاری
        $floatFields = ['volume_gb', 'bonus_gb'];

        // فیلدهای رشته‌ای
        $stringFields = ['title', 'description', 'kind'];

        foreach ($intFields as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = max(0, (int) $data[$field]);
            }
        }

        foreach ($floatFields as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = max(0.0, (float) $data[$field]);
            }
        }

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = (string) $data[$field];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = (int) (bool) $data['is_active'];
        }

        if (array_key_exists('slug', $data)) {
            $payload['slug'] = $this->uniqueSlug((string) $data['slug'], $id);
        }

        if (count($payload) === 1) {
            return;
        }

        $this->db->update('packages', $payload, ['id' => $id]);
    }

    public function toggle(int $id): bool
    {
        $package = $this->find($id);
        if ($package === null) {
            return false;
        }

        $newState = (int) $package['is_active'] === 1 ? 0 : 1;
        $this->db->update('packages', ['is_active' => $newState, 'updated_at' => time()], ['id' => $id]);

        return true;
    }

    public function delete(int $id): void
    {
        $this->db->delete('packages', ['id' => $id]);
    }

    /**
     * تعداد بسته‌های خریداری‌شده و اعمال‌شدهٔ یک کاربر از یک نوع مشخص.
     */
    public function purchasedCount(int $userId, string $kind): int
    {
        return $this->db->count(
            "SELECT COUNT(*) FROM orders
             WHERE user_id = ? AND kind = ? AND status IN ('paid','applied')",
            [$userId, $kind]
        );
    }

    /**
     * حجم کل خریداری‌شدهٔ یک کاربر (برای اعمال سقف سقف‌گذاری اختیاری).
     */
    public function purchasedVolume(int $userId, string $kind): float
    {
        return (float) $this->db->value(
            "SELECT COALESCE(SUM(volume_gb + bonus_gb), 0) FROM orders
             WHERE user_id = ? AND kind = ? AND status IN ('paid','applied')",
            [$userId, $kind]
        );
    }

    /**
     * ساخت slug یکتا و خوانا.
     */
    private function uniqueSlug(string $base, int $ignoreId = 0): string
    {
        $base = strtolower(trim($base));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $base) ?? 'package';
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'package';
        }

        $slug = Str::truncate($slug, 40, '');
        $candidate = $slug;
        $suffix = 1;

        while (true) {
            $existing = $this->db->first('SELECT id FROM packages WHERE slug = ? AND id != ?', [$candidate, $ignoreId]);
            if ($existing === null) {
                return $candidate;
            }
            $suffix++;
            $candidate = $slug . '-' . $suffix;
        }
    }
}