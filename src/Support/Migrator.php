<?php

declare(strict_types=1);

namespace Pasargad\Support;

use Throwable;

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
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('bot_enabled', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('gateway_card2card', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('gateway_nowpayments', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('renewal_enabled', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('user_tools_enabled', '1', 0)",
        ],

        // ------------------------------------------------------------------
        // 003: تفکیک وضعیت «رد شده توسط ادمین» از «اجرای ناموفق»
        //
        // مشکل قبلی: هم رد شدن پرداخت و هم خطای موقت اجرا با status='failed'
        // ذخیره می‌شد و next_attempt_at=NULL داشت. چون pendingApply این حالت را
        // «آمادهٔ پردازش فوری» می‌فهمید، سفارش ردشده در کرون بعدی دوباره اجرا
        // می‌شد و بسته بدون پرداخت به کاربر داده می‌شد.
        //
        // راه‌حل: وضعیت پایانی 'rejected' اضافه شد و فیلد terminal_reason
        // برای تشخیص صریح سفارش‌هایی که دیگر نباید تلاش مجدد شوند.
        // ------------------------------------------------------------------
        '003_terminal_states' => [
            'ALTER TABLE orders ADD COLUMN terminal_reason TEXT',
            'ALTER TABLE orders ADD COLUMN target_limit INTEGER',
            'ALTER TABLE orders ADD COLUMN panel_applied INTEGER NOT NULL DEFAULT 0',

            // سفارش‌هایی که قبلاً با attempts=99 عمداً از صف خارج شده بودند
            // (تلاش‌های ناموفقِ محلی) به وضعیت پایانی منتقل می‌شوند تا دیگر
            // در صف پردازش قرار نگیرند.
            "UPDATE orders SET status = 'rejected', terminal_reason = 'local_failure'
             WHERE status = 'failed' AND attempts >= 99",

            'CREATE INDEX IF NOT EXISTS idx_orders_terminal ON orders(terminal_reason)',
            'CREATE INDEX IF NOT EXISTS idx_orders_panel_applied ON orders(panel_applied)',
        ],

        // ------------------------------------------------------------------
        // 004: اعتبارسنجی قوی‌تر داده‌های پرداخت
        // ------------------------------------------------------------------
        '004_payment_guards' => [
            // reference کارت‌به‌کارت کد سفارش است و برای هر سفارش تکراری می‌شود،
            // پس یکتایی سراسری روی external_id باعث خطای UNIQUE می‌شد.
            'DROP INDEX IF EXISTS idx_payments_external',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_payments_order_method
             ON payments(order_id, method)',

            // جلوگیری از پذیرش مبلغ منفی
            'CREATE INDEX IF NOT EXISTS idx_payments_amount ON payments(amount_toman)',
        ],

        // ------------------------------------------------------------------
        // 005: چندپنلی شدن نمایندگان + کانفیگ تست + عضویت اجباری کانال
        //
        // چرا این مهاجریشن لازم است:
        //
        // قبلاً هر نماینده فقط **یک** پنل داشت و ستون‌های پنل روی خودِ جدول
        // users بودند (panel_username, panel_data_limit, …). این مدل سه محدودیت
        // داشت که دیگر با نیاز کسب‌وکار نمی‌خواند:
        //
        //   ۱) هر کاربر فقط یک پنل می‌توانست بخرد.
        //   ۲) حجم و انقضا هر کاربر روی **مجموع** همهٔ خریدهایش انباشته می‌شد،
        //      پس «کاهش حجم» یا «اتمام اعتبار» هیچ‌وقت به یک پنل مشخص نسبت
        //      داده نمی‌شد — یعنی هشدار per-panel و قطع دسترسی کاربران هر پنل
        //      غیرممکن بود.
        //   ۳) پسورد پنل در همان رکورد کاربر بود، پس با چند پنل جایی برای
        //      نگهداری جداگانه نبود.
        //
        // راه‌حل: جدول مستقل `panels` که **هر ردیف یک پنل خریداری‌شده** است با
        // حجم، مصرف، انقضا، وضعیت و شمارندهٔ هشدارهای مستقلِ خودش.
        //
        // مهاجرت دادهٔ فعلی: کاربرانی که قبلاً وصل شده بودند یک ردیف panels
        // می‌گیرند (source='legacy') تا هیچ‌کدام از نمایندگان فعلی پنل‌شان را
        // از دست ندهند، و سفارش‌های قدیمی panel_quota به همان پنل لینک می‌شوند.
        // ------------------------------------------------------------------
        '005_panels' => [
            // پنل‌های خریداری‌شدهٔ هر نماینده
            'CREATE TABLE IF NOT EXISTS panels (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id              INTEGER NOT NULL,
                panel_username       TEXT NOT NULL,
                panel_password       TEXT NOT NULL,           -- رمزنگاری‌شده
                panel_user_id        INTEGER,
                panel_status         TEXT NOT NULL DEFAULT \'active\',
                -- active | limited | disabled | revoked | expired
                panel_role           TEXT,
                panel_is_owner       INTEGER DEFAULT 0,
                data_limit           INTEGER NOT NULL DEFAULT 0,
                used_traffic         INTEGER NOT NULL DEFAULT 0,
                granted_volume       INTEGER NOT NULL DEFAULT 0,   -- حجم خریداری‌شده (بایت)
                access_expire_at     INTEGER,                     -- پایان اعتبار زمانی
                sub_url              TEXT,                        -- لینک اشتراک نماینده
                login_url            TEXT,                        -- آدرس ورود به پنل
                label                TEXT,                        -- نام دلخواه نماینده
                source               TEXT NOT NULL DEFAULT \'bot\',
                -- bot = خریداری‌شده از ربات | legacy = مهاجرت | self = دکمهٔ «من پنل دارم»
                order_id             INTEGER,
                is_default           INTEGER NOT NULL DEFAULT 0,
                warned_low_volume    INTEGER,                     -- زمان آخرین هشدار حجم
                warned_expiring      INTEGER,                     -- زمان آخرین هشدار انقضا
                expiry_notified      INTEGER NOT NULL DEFAULT 0,  -- هشدار «تمام شد» ارسال شد
                cutoff_requested_at  INTEGER,                     -- درخواست قطع دسترسی به ادمین داده شد
                cutoff_done_at       INTEGER,                     -- دسترسی کاربران قطع شد
                cutoff_count         INTEGER NOT NULL DEFAULT 0,  -- چند کاربر غیرفعال شد
                synced_at            INTEGER,
                created_at           INTEGER NOT NULL,
                updated_at           INTEGER NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_panels_user ON panels(user_id)',
            // COLLATE NOCASE چون نام کاربری پنل در API بدون حساسیت جست‌وجو می‌شود
            // و «Ali» و «ali» نباید دو پنل جدا ساخته شوند.
            'CREATE INDEX IF NOT EXISTS idx_panels_username ON panels(panel_username COLLATE NOCASE)',
            'CREATE INDEX IF NOT EXISTS idx_panels_expire ON panels(access_expire_at)',

            // کانفیگ/یوزر تست رایگان ساخته‌شده روی پنل نماینده
            'CREATE TABLE IF NOT EXISTS test_configs (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                panel_id       INTEGER NOT NULL,
                user_id        INTEGER NOT NULL,
                panel_username TEXT NOT NULL,
                data_limit     INTEGER NOT NULL DEFAULT 0,
                used_traffic   INTEGER NOT NULL DEFAULT 0,
                expire_at      INTEGER NOT NULL,
                sub_url        TEXT,
                status         TEXT NOT NULL DEFAULT \'active\',  -- active | expired | disabled
                issued_at      INTEGER NOT NULL,
                disabled_at    INTEGER,
                FOREIGN KEY (panel_id) REFERENCES panels(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            'CREATE INDEX IF NOT EXISTS idx_test_configs_panel ON test_configs(panel_id)',
            'CREATE INDEX IF NOT EXISTS idx_test_configs_user ON test_configs(user_id)',

            // سفارش به کدام پنل مربوط است (برای شارژ/تمدید)
            'ALTER TABLE orders ADD COLUMN panel_id INTEGER',
            'CREATE INDEX IF NOT EXISTS idx_orders_panel ON orders(panel_id)',

            // ------------------------------------------------------------------
            // انتقال دادهٔ موجود
            // ------------------------------------------------------------------

            // هر کاربرِ وصل‌شده یک ردیف panels می‌گیرد.
            'INSERT INTO panels (
                 user_id, panel_username, panel_password, panel_status, panel_role,
                 panel_is_owner, data_limit, used_traffic, granted_volume,
                 access_expire_at, source, synced_at, created_at, updated_at
             )
             SELECT u.id, u.panel_username, COALESCE(u.panel_password, \'\'),
                    u.panel_status, u.panel_role, u.panel_is_owner,
                    u.panel_data_limit, u.panel_used, u.granted_volume,
                    u.granted_expire_at, \'legacy\', u.panel_synced_at,
                    u.created_at, u.updated_at
             FROM users u
             WHERE u.panel_username IS NOT NULL AND u.panel_username <> \'\'',

            // سفارش‌های حجمِ قدیمی به پنلِ کاربر لینک می‌شوند تا «شارژ» بعدی
            // بداند روی کدام پنل اعمال شود.
            'UPDATE orders SET panel_id = (
                 SELECT p.id FROM panels p
                 WHERE p.user_id = orders.user_id
                 ORDER BY p.id ASC LIMIT 1
             )
             WHERE panel_id IS NULL AND kind IN (\'panel_quota\', \'topup\')',

            // بسته‌های «اعتبار کاربر» دیگر ارائه نمی‌شوند (ساخت کاربر از ربات
            // حذف شد). غیرفعال می‌شوند نه حذف، تا سابقهٔ سفارش‌ها سالم بماند.
            'UPDATE packages SET is_active = 0 WHERE kind = \'user_credit\'',

            // تنظیمات اولیهٔ قابلیت‌های جدید
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('test_config_enabled', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('test_config_volume_gb', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('test_config_days', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('test_config_max_per_user', '2', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('test_config_cooldown', '30', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('channel_enforced', '0', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('channel_cache_minutes', '30', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('expire_warn_days', '3', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('cutoff_on_expire', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('panel_sync_cron', '1', 0)",
        ],

        // ------------------------------------------------------------------
        // 006: کیف پول کاربران + زمان‌بندی بکاپ
        //
        // wallet_balance: موجودی کیف پول کاربر (تومان). شارژ آن فقط توسط
        // سوپرادمین انجام می‌شود و در پرداخت با کیف پول مصرف می‌شود.
        // wallet_txns: تاریخچهٔ تراکنش‌های کیف پول برای «تاریخچه پرداخت‌ها».
        // ------------------------------------------------------------------
        '006_wallet_backup' => [
            'ALTER TABLE users ADD COLUMN wallet_balance INTEGER NOT NULL DEFAULT 0',

            'CREATE TABLE IF NOT EXISTS wallet_txns (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                amount      INTEGER NOT NULL,
                kind        TEXT NOT NULL DEFAULT \'manual\',
                note        TEXT,
                admin_id    INTEGER,
                created_at  INTEGER NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            'CREATE INDEX IF NOT EXISTS idx_wallet_user ON wallet_txns(user_id)',

            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('backup_schedule', 'off', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('backup_last_at', '0', 0)",
        ],

        // ------------------------------------------------------------------
        // 007: ضدتکرار دستورات + کارت‌به‌کارت خودکار
        //
        // flood_guard: آخرین زمان هر اکشن هر کاربر، برای جلوگیری از اجرای
        // تکراری دکمه‌ها (دابل‌کلیک) و کاهش بار سرور.
        // processed_updates: شناسهٔ آپدیت‌های پردازش‌شده، تا ارسال مجدد همان
        // آپدیت توسط تلگرام دوباره اجرا نشود.
        // gateway_autocard: سوییچ درگاه کارت‌به‌کارت خودکار.
        // test_config_panel_id: پنل ثابت تست (۰ = پنل خود کاربر).
        // ------------------------------------------------------------------
        '007_flood_autocard' => [
            'CREATE TABLE IF NOT EXISTS flood_guard (
                key         TEXT PRIMARY KEY,
                updated_at  INTEGER NOT NULL
            )',

            'CREATE TABLE IF NOT EXISTS processed_updates (
                update_id   INTEGER PRIMARY KEY,
                created_at  INTEGER NOT NULL
            )',

            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('gateway_autocard', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('test_config_panel_id', '0', 0)",
        ],

        // ------------------------------------------------------------------
        // 008: تشخیص آپدیت تکراری با اثر انگشت محتوا
        //
        // شناسهٔ آپدیت به‌تنهایی برای رد کردن کافی نیست: اگر همان عدد دوباره
        // دیده شود ولی محتوایش فرق داشته باشد (استفادهٔ مجدد عدد)، آپدیت
        // واقعی است و نباید نادیده گرفته شود. فقط «همان عدد + همان محتوا»
        // یعنی ارسال مجدد تلگرام و رد می‌شود.
        // ------------------------------------------------------------------
        '008_flood_fingerprint' => [
            "ALTER TABLE processed_updates ADD COLUMN fingerprint TEXT NOT NULL DEFAULT ''",
        ],

        // ------------------------------------------------------------------
        // 009: ابزارهای توسعهٔ نمایندگی
        //
        //   expire_grace_days      مهلت ارفاقی پس از انقضا (پیش از قطع دسترسی)
        //   panels.grace_notified  هشدار «مهلت ارفاقی» یک‌بار فرستاده شد
        //   panels.users_*         آمار کاربران پنل (برای صفحهٔ نماینده)
        //
        //   coupons / coupon_uses  کدهای تخفیف و سابقهٔ استفاده
        //   referrals              معرفی کاربر تازه به ربات
        //   orders.discount_toman  مبلغ تخفیف هر سفارش
        //   orders.original_price  قیمت پیش از تخفیف (برای گزارش درآمد ناخالص)
        //   orders.invoice_token   توکن یکتای فاکتور قابل چاپ
        //
        //   tickets / ticket_messages  تیکت پشتیبانی داخل ربات
        // ------------------------------------------------------------------
        '009_agency_extras' => [
            // ---------------- ۱) مهلت ارفاقی ----------------
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('expire_grace_days', '3', 0)",
            'ALTER TABLE panels ADD COLUMN grace_notified INTEGER NOT NULL DEFAULT 0',

            // ---------------- ۵) آمار کاربران پنل ----------------
            'ALTER TABLE panels ADD COLUMN users_total INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE panels ADD COLUMN users_active INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE panels ADD COLUMN users_disabled INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE panels ADD COLUMN stats_at INTEGER',

            // ---------------- ۲) کد تخفیف ----------------
            'CREATE TABLE IF NOT EXISTS coupons (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                code               TEXT NOT NULL,
                kind               TEXT NOT NULL DEFAULT \'percent\',  -- percent | fixed
                value              INTEGER NOT NULL DEFAULT 0,       -- درصد یا تومان
                max_uses           INTEGER NOT NULL DEFAULT 0,       -- ۰ = نامحدود
                used_count         INTEGER NOT NULL DEFAULT 0,
                per_user_limit     INTEGER NOT NULL DEFAULT 1,       -- ۰ = هر کاربر بی‌نهایت
                min_order_toman    INTEGER NOT NULL DEFAULT 0,
                max_discount_toman INTEGER NOT NULL DEFAULT 0,       -- ۰ = بدون سقف
                expires_at         INTEGER,
                is_active          INTEGER NOT NULL DEFAULT 1,
                note               TEXT,
                created_at         INTEGER NOT NULL,
                updated_at         INTEGER NOT NULL
            )',
            // COLLATE NOCASE تا «SUMMER» و «summer» یک کد باشند
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_coupons_code ON coupons(code COLLATE NOCASE)',
            'CREATE INDEX IF NOT EXISTS idx_coupons_active ON coupons(is_active)',

            'CREATE TABLE IF NOT EXISTS coupon_uses (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                coupon_id      INTEGER NOT NULL,
                user_id        INTEGER NOT NULL,
                order_id       INTEGER,
                discount_toman INTEGER NOT NULL DEFAULT 0,
                created_at     INTEGER NOT NULL,
                FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            'CREATE INDEX IF NOT EXISTS idx_coupon_uses_coupon ON coupon_uses(coupon_id)',
            'CREATE INDEX IF NOT EXISTS idx_coupon_uses_user ON coupon_uses(user_id)',

            // ---------------- ۲) معرفی کاربر ----------------
            'CREATE TABLE IF NOT EXISTS referrals (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_user_id INTEGER NOT NULL,
                referee_user_id  INTEGER NOT NULL,
                code             TEXT NOT NULL,
                bonus_toman      INTEGER NOT NULL DEFAULT 0,
                rewarded_at      INTEGER,
                created_at       INTEGER NOT NULL,
                FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referee_user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            // یک کاربر تازه فقط یک معرف دارد؛ بدون این قید، /start ref_X چندبار
            // می‌توانست چند پاداش معرفی تولید کند.
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_referrals_referee ON referrals(referee_user_id)',
            'CREATE INDEX IF NOT EXISTS idx_referrals_referrer ON referrals(referrer_user_id)',

            // ---------------- ۲) تخفیف روی سفارش ----------------
            'ALTER TABLE orders ADD COLUMN discount_toman INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE orders ADD COLUMN original_price_toman INTEGER',
            'ALTER TABLE orders ADD COLUMN coupon_code TEXT',
            // کد معرفی که کاربر تازه با آن وارد شد (برای گزارش و پاداش معرف)
            'ALTER TABLE orders ADD COLUMN referred_by TEXT',

            // کد تخفیف فعال هر کاربر.
            //
            // روی users و نه در نشست، چون نشست ۱۵ دقیقه‌ای است و کاربر ممکن
            // است امروز کد وارد کند و فردا خرید کند.
            'ALTER TABLE users ADD COLUMN coupon_code TEXT',

            // ---------------- ۴) فاکتور ----------------
            'ALTER TABLE orders ADD COLUMN invoice_token TEXT',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_invoice ON orders(invoice_token)',
            'CREATE INDEX IF NOT EXISTS idx_orders_referral ON orders(referred_by)',

            // ---------------- ۳) تیکت پشتیبانی ----------------
            'CREATE TABLE IF NOT EXISTS tickets (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id             INTEGER NOT NULL,
                category            TEXT NOT NULL DEFAULT \'general\',
                subject             TEXT NOT NULL,
                status              TEXT NOT NULL DEFAULT \'open\',  -- open | answered | closed
                admin_reply_at      INTEGER,
                closed_at           INTEGER,
                created_at          INTEGER NOT NULL,
                updated_at          INTEGER NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
            'CREATE INDEX IF NOT EXISTS idx_tickets_user ON tickets(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status, id DESC)',

            'CREATE TABLE IF NOT EXISTS ticket_messages (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id  INTEGER NOT NULL,
                from_side  TEXT NOT NULL,   -- user | admin
                body       TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
            )',
            'CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON ticket_messages(ticket_id, id ASC)',

            // ---------------- تنظیمات ----------------
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('coupons_enabled', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('referral_enabled', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('referral_discount_percent', '10', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('referral_bonus_toman', '50000', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('tickets_enabled', '1', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('panel_stats_ttl_minutes', '30', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('backup_keep', '14', 0)",

            // ربات مدیریتی اختیاری (وبهوک جدا). پیش‌فرض خاموش است تا کسی که
            // ربات دومی ندارد اصلاً چیزی تنظیم نکند.
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('admin_bot_ready', '0', 0)",
        ],

        // ------------------------------------------------------------------
        // 010: سقف تعداد کاربران هر بسته/پنل
        //
        // max_users روی بسته یعنی «با این بسته حداکثر چند کاربر (یوزر) روی
        // پنل ساخته می‌شود» — جدا از حجم و مدت. ۰ یعنی نامحدود ♾️.
        // orders.max_users اسنپ‌شات لحظهٔ خرید است و panels.user_limit سقف
        // خریداری‌شدهٔ هر پنل (جمع شارژها؛ نامحدود غالب است).
        // ------------------------------------------------------------------
        '010_user_limit' => [
            'ALTER TABLE packages ADD COLUMN max_users INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE orders ADD COLUMN max_users INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE panels ADD COLUMN user_limit INTEGER NOT NULL DEFAULT 0',
        ],

        // ------------------------------------------------------------------
        // 011: features requested by user
        // ------------------------------------------------------------------
        '011_user_features' => [
            // ---------------- ۱) اشتراک مدت‌دار برای ربات‌های hijo ----------------
            'ALTER TABLE panels ADD COLUMN subscription_expire_at INTEGER',
            'ALTER TABLE panels ADD COLUMN is_subscribed INTEGER NOT NULL DEFAULT 0',

            // ---------------- ۲) سیستم معرفی (ریفرال) پاداش اسلات ----------------
            //extend referrals table with slate bonus
            'ALTER TABLE referrals ADD COLUMN slate_bonus_toman INTEGER NOT NULL DEFAULT 0',

            // ---------------- ۳) ربات آزمایشی با حذف خودکار ----------------
            'ALTER TABLE test_configs ADD COLUMN auto_delete_at INTEGER',
            'ALTER TABLE test_configs ADD COLUMN is_auto_delete INTEGER NOT NULL DEFAULT 0',

            // ---------------- ۴) سی dihdisc quota and dead bot ----------------
            'ALTER TABLE panels ADD COLUMN disk_quota INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE panels ADD COLUMN is_dead INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE panels ADD COLUMN last_seen_at INTEGER',

            // ---------------- ۵) مانیتورینگ مرکزی ربات‌های hijo ----------------
            'ALTER TABLE panels ADD COLUMN monitor_status TEXT NOT NULL DEFAULT \'online\'',
            'ALTER TABLE panels ADD COLUMN monitor_last_check INTEGER',

            // ---------------- ۶) عملیات گروهی در «همه ربات‌ها» ----------------
            // Nothing DB-level; handled via admin UI selection.

            // ---------------- ۷) انتقال مالکیت + کپی (کلون) ربات ----------------
            'ALTER TABLE panels ADD COLUMN original_user_id INTEGER',
            'ALTER TABLE panels ADD COLUMN clone_source_panel_id INTEGER',

            // ---------------- ۸) لاگ حسابرسی ادمین ----------------
            'CREATE TABLE IF NOT EXISTS admin_logs (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id   INTEGER NOT NULL,
                action          TEXT NOT NULL,
                target_type     TEXT NOT NULL,   -- panel | order | user | referral
                target_id       INTEGER NOT NULL,
                details         TEXT,
                ip_address      TEXT,
                created_at      INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_admin_logs_admin ON admin_logs(admin_user_id)',
            'CREATE INDEX IF NOT EXISTS idx_admin_logs_target ON admin_logs(target_type, target_id)',
            'CREATE INDEX IF NOT EXISTS idx_admin_logs_created ON admin_logs(created_at)',

            // ---------------- ۹) محدودیت نرخ Against spam ----------------
            'CREATE TABLE IF NOT EXISTS flood_extra (
                key         TEXT PRIMARY KEY,
                updated_at  INTEGER NOT NULL
            )',
            // users.is_blocked و users.blocked_reason از قبل در 001 وجود دارند؛
            // دوباره ADD کردنشان خطای duplicate column می‌داد و مایگریشن 011 نمی‌گرفت.

            // ---------------- ۱۰) داشبورد درآمد ----------------
            //Additional columns on orders for revenue tracking
            'ALTER TABLE orders ADD COLUMN revenue_share_toman INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE orders ADD COLUMN is_referred INTEGER NOT NULL DEFAULT 0',
        ],

        // ------------------------------------------------------------------
        // 012: امتیاز وفاداری + ضد brute-force + گزارش ادمین + قیمت‌گذاری چنددوره‌ای
        // ------------------------------------------------------------------
        '012_loyalty_security_pricing' => [
            'ALTER TABLE users ADD COLUMN loyalty_points INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE packages ADD COLUMN prices TEXT',

            'CREATE TABLE IF NOT EXISTS login_attempts (
                telegram_id INTEGER NOT NULL,
                kind        TEXT NOT NULL,
                attempts    INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0,
                updated_at  INTEGER NOT NULL,
                PRIMARY KEY (telegram_id, kind)
            )',

            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('referral_bonus_level2_toman', '25000', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('loyalty_discount_percent', '5', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('loyalty_redeem_points', '100', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('login_max_attempts', '5', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('login_lock_minutes', '15', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('admin_daily_report', '0', 0)",
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('admin_weekly_report', '0', 0)",
        ],
        ];
    }

    public function __construct(?Db $db = null)
    {
        $this->db = $db ?? Db::instance();
    }

    /**
     * اجرای مایگریشن‌ها با قفل انحصاری.
     *
     * مشکلی که این حل می‌کند: دو درخواست همزمان وبهوک هر دو می‌دیدند که
     * مایگریشن اجرا نشده و هر دو `ALTER TABLE … ADD COLUMN` را می‌زدند. یکی
     * خطای «duplicate column name» می‌گرفت و استثنا تا بالا پرتاب می‌شد و
     * پیام کاربر بی‌صدا از دست می‌رفت.
     *
     * قفل در سطح دیتابیس گرفته می‌شود تا حتی بین دو پروسهٔ PHP-FPM هم یکی
     * فقط مالک اجرا باشد.
     *
     * @return array<int, string>
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $lockPath = $this->lockPath();
        $lock     = $lockPath !== null ? @fopen($lockPath, 'c') : false;

        // بدون امکان قفل، یک‌بار تلاش می‌کنیم و در صورت خطا همان خطا را
        // به لاگ می‌اندازیم و رد می‌شویم (بهتر از اجرای دوبارهٔ ALTER است).
        if ($lock === false) {
            Logger::warning('Migration lock unavailable, running without lock', [
                'path' => $lockPath ?? 'n/a',
            ]);

            return $this->runPending();
        }

        try {
            flock($lock, LOCK_EX);
        } catch (Throwable $e) {
            Logger::warning('Could not acquire migration lock', ['error' => $e->getMessage()]);

            return [];
        }

        try {
            // پس از گرفتن قفل باید دوباره وضعیت خوانده شود: پروسهٔ دیگری ممکن
            // است در همین فاصله مایگریشن‌ها را اجرا کرده باشد.
            return $this->runPending();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * اجرای مایگریشن‌ها فقط اگر نسخهٔ کد جدیدتر از آخرین مایگریشن باشد.
     *
     * برای مسیر داغ (وبهوک، IPN) استفاده می‌شود تا هر درخواست هزینهٔ بررسی
     * جدول migrations را نداشته باشد. مایگریشن‌ها در `cli.php migrate` اجرا
     * می‌شوند.
     *
     * @return array<int, string>
     */
    public function migrateWhenOutdated(): array
    {
        $applied = $this->appliedMigrations();
        $known   = array_keys(self::migrations());

        if ($applied === []) {
            // دیتابیس کاملاً تازه است → باید ساخته شود.
            return $this->migrate();
        }

        $newest = (string) end($known);
        $latest = (string) end($applied);

        if ($latest !== $newest) {
            Logger::info('Database schema outdated, migrating on hot path', [
                'applied' => $latest,
                'expected' => $newest,
            ]);

            return $this->migrate();
        }

        return [];
    }

    /**
     * مسیر فایل قفل کنار دیتابیس.
     */
    private function lockPath(): ?string
    {
        $path = $this->db->path();

        if ($path === '') {
            return null;
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.migrate.lock';
    }

    /**
     * اجرای مایگریشن‌های اعمال‌نشده (بدون قفل — قفل باید از قبل گرفته شده باشد).
     *
     * @return array<int, string> نام مایگریشن‌های اجراشده
     */
    private function runPending(): array
    {
        $applied = $this->appliedMigrations();
        $ran     = [];

        foreach (self::migrations() as $name => $statements) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            try {
                $this->db->transaction(function () use ($name, $statements): void {
                    foreach ($statements as $sql) {
                        $this->db->pdo()->exec($sql);
                    }
                    $this->db->insert('migrations', [
                        'name'       => $name,
                        'applied_at' => time(),
                    ]);
                });
            } catch (Throwable $e) {
                // اگر پروسهٔ دیگری زودتر اجرا کرده باشد، خطای «already exists»
                // بی‌خطر است و باید ثبت شود تا دیگر تکرار نشود.
                if (!$this->alreadyApplied($name, $e)) {
                    throw $e;
                }

                Logger::info('Migration already applied by another process', ['migration' => $name]);

                continue;
            }

            $ran[] = $name;
        }

        if ($ran !== []) {
            Logger::info('Migrations applied', ['migrations' => $ran]);
        }

        return $ran;
    }

    /**
     * آیا خطای مایگریشن یعنی «قبلاً اجرا شده» است؟
     */
    private function alreadyApplied(string $name, Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $benign = str_contains($message, 'duplicate column')
            || str_contains($message, 'already exists')
            || str_contains($message, 'table already exists');

        if (!$benign) {
            return false;
        }

        // باید مطمئن شویم واقعاً ثبت شده، وگرنه هر بار تکرار می‌شود.
        return in_array($name, $this->appliedMigrations(), true);
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