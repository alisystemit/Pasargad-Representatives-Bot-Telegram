<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * مایگریشن دیتابیس SQLite.
 *
 * هر مایگریشن یک آرایهٔ دستور SQL است که فقط یک‌بار اجرا می‌شود و
 * نسخهٔ اعمال‌شده در جدول `migrations` ثبت می‌گردد.
 */
final class Migrator
{
    private Db $db;

    /**
     * مایگریشن‌های دیتابیس؛ هر کلید یک نسخه و هر مقدار آرایه‌ای از دستورات SQL است.
     *
     * @return array<string, array<int, string>>
     */
    private static function migrations(): array
    {
        return [
        '001_core' => [
            'CREATE TABLE IF NOT EXISTS settings (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL,
                updated_at  INTEGER NOT NULL
            )',

            // کاربران ربات (نمایندگان/ادمین‌های پنل)
            'CREATE TABLE IF NOT EXISTS users (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id       INTEGER NOT NULL UNIQUE,
                username          TEXT,
                first_name        TEXT,
                language_code     TEXT,
                panel_username    TEXT,
                panel_user_id     INTEGER,
                panel_password    TEXT,           -- رمزنگاری‌شده با libsodium/OpenSSL
                panel_status      TEXT DEFAULT \'pending\', -- pending | active | revoked
                panel_data_limit  INTEGER DEFAULT 0,
                panel_used        INTEGER DEFAULT 0,
                panel_synced_at   INTEGER,
                panel_role        TEXT,
                panel_is_owner    INTEGER DEFAULT 0,
                granted_volume    INTEGER DEFAULT 0,   -- حجم کل هدیه/خرید (بایت)
                granted_expire_at INTEGER,             -- پایان اعتبار حجم در دیتابیس ربات
                user_credit       INTEGER DEFAULT 0,   -- اعتبار ساخت کاربر (بایت)
                user_credit_expire INTEGER,            -- پایان اعتبار ساخت کاربر
                note              TEXT,
                is_blocked        INTEGER NOT NULL DEFAULT 0,
                blocked_reason    TEXT,
                orders_count      INTEGER NOT NULL DEFAULT 0,
                total_paid        INTEGER NOT NULL DEFAULT 0,
                last_seen_at      INTEGER,
                created_at        INTEGER NOT NULL,
                updated_at        INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_users_panel_username ON users(panel_username)',
            'CREATE INDEX IF NOT EXISTS idx_users_status ON users(panel_status)',

            // بسته‌های فروشگاه (محصولات)
            'CREATE TABLE IF NOT EXISTS packages (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                slug          TEXT NOT NULL UNIQUE,
                title         TEXT NOT NULL,
                description   TEXT,
                kind          TEXT NOT NULL DEFAULT \'panel_quota\', -- panel_quota | user_credit
                volume_gb     REAL NOT NULL DEFAULT 0,
                duration_days INTEGER NOT NULL DEFAULT 30,
                price_toman   INTEGER NOT NULL DEFAULT 0,
                bonus_gb      REAL NOT NULL DEFAULT 0,
                sort_order    INTEGER NOT NULL DEFAULT 0,
                is_active     INTEGER NOT NULL DEFAULT 1,
                max_per_user  INTEGER NOT NULL DEFAULT 0,  -- 0 = بدون سقف
                created_at    INTEGER NOT NULL,
                updated_at    INTEGER NOT NULL
            )',

            // سفارش‌ها
            'CREATE TABLE IF NOT EXISTS orders (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                code              TEXT NOT NULL UNIQUE,
                user_id           INTEGER NOT NULL,
                package_id        INTEGER,
                package_title     TEXT NOT NULL,
                kind              TEXT NOT NULL,
                volume_gb         REAL NOT NULL DEFAULT 0,
                bonus_gb          REAL NOT NULL DEFAULT 0,
                duration_days     INTEGER NOT NULL DEFAULT 0,
                price_toman       INTEGER NOT NULL DEFAULT 0,
                status            TEXT NOT NULL DEFAULT \'created\',
                -- created | awaiting_payment | paid | applying | applied | failed | cancelled | refunded
                payment_method    TEXT,                    -- card2card | nowpayments
                payment_ref       TEXT,
                payment_payload   TEXT,
                receipt_file_id   TEXT,
                receipt_photo_id  TEXT,
                review_admin_id   INTEGER,
                review_note       TEXT,
                error             TEXT,
                attempts          INTEGER NOT NULL DEFAULT 0,
                next_attempt_at   INTEGER,
                applied_volume    INTEGER DEFAULT 0,
                before_limit      INTEGER,
                after_limit       INTEGER,
                created_at        INTEGER NOT NULL,
                updated_at        INTEGER NOT NULL,
                paid_at           INTEGER,
                applied_at        INTEGER,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)',
            'CREATE INDEX IF NOT EXISTS idx_orders_next_attempt ON orders(next_attempt_at)',

            // پرداخت‌ها (هر تلاش پرداخت یک رکورد)
            'CREATE TABLE IF NOT EXISTS payments (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id      INTEGER NOT NULL,
                method        TEXT NOT NULL,
                amount_toman  INTEGER NOT NULL DEFAULT 0,
                amount_usd    REAL DEFAULT 0,
                currency      TEXT,
                external_id   TEXT,
                status        TEXT NOT NULL DEFAULT \'pending\', -- pending | waiting | confirmed | failed | expired
                raw_payload   TEXT,
                created_at    INTEGER NOT NULL,
                updated_at    INTEGER NOT NULL,
                confirmed_at  INTEGER,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            )',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_payments_external ON payments(external_id) WHERE external_id IS NOT NULL',
            'CREATE INDEX IF NOT EXISTS idx_payments_order ON payments(order_id)',

            // لاگ رویدادهای پنل (اعمال خودکار بسته)
            'CREATE TABLE IF NOT EXISTS provision_logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id    INTEGER NOT NULL,
                status      TEXT NOT NULL,
                request     TEXT,
                response    TEXT,
                message     TEXT,
                created_at  INTEGER NOT NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            )',
            'CREATE INDEX IF NOT EXISTS idx_provision_logs_order ON provision_logs(order_id)',
        ],

        '002_bot_users' => [
            // جدول لاگ عمومی + تنظیمات کلیدی اولیه
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('shop_opened', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('auto_apply', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('low_volume_alert', '5', 0)",
        ],
        ];
    }

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * اجرای همهٔ مایگریشن‌های اعمال‌نشده.
     *
     * @return array<int, string> نام مایگریشن‌های اجراشده
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();
        $ran     = [];

        foreach (self::migrations() as $name => $statements) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $this->db->transaction(function () use ($name, $statements): void {
                foreach ($statements as $sql) {
                    $this->db->pdo()->exec($sql);
                }
                $this->db->insert('migrations', [
                    'name'       => $name,
                    'applied_at' => time(),
                ]);
            });

            $ran[] = $name;
        }

        if ($ran !== []) {
            Logger::info('Migrations applied', ['migrations' => $ran]);
        }

        return $ran;
    }

    /**
     * @return array<int, string>
     */
    public function appliedMigrations(): array
    {
        if (!$this->db->tableExists('migrations')) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->db->all('SELECT name FROM migrations ORDER BY id ASC')
        );
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE,
                applied_at  INTEGER NOT NULL
            )'
        );
    }
}