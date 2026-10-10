<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Str;

/**
 * 🎨 متن‌های زیبای ربات
 * 
 * این کلاس تمام متن‌های ربات را با فرمت‌بندی زیبا فراهم می‌کند
 * - جعبه‌های شیشه‌ای و تزیینی
 * - فاصلهٔ مناسب و خطوط
 * - Emoji‌های متناسب
 * - ترجمهٔ فارسی صحیح
 */
final class PrettyText
{
    /**
     * جعبهٔ عنوان با حاشیهٔ شیشه‌ای
     */
    public static function box(string $title, string $icon = '🎯'): string
    {
        $len = mb_strlen($title) + 2;
        return "┌" . str_repeat("─", $len) . "┐\n"
             . "│ " . $title . " │\n"
             . "└" . str_repeat("─", $len) . "┘";
    }

    /**
     * جدول اطلاعات با فرمت کاملاً زیبا
     */
    public static function table(array $rows, array $headers = []): string
    {
        if (empty($rows)) {
            return "📭 موردی موجود نیست";
        }

        $lines = [];

        if (!empty($headers)) {
            $lines[] = "┌─ " . implode(" ─ ", $headers) . " ─┐";
            $lines[] = "";
        }

        foreach ($rows as $row) {
            $lines[] = "  ├─ " . implode(" │ ", (array) $row);
        }

        return implode("\n", $lines);
    }

    /**
     * سطر اطلاعات با آیکون و فرمت
     */
    public static function row(string $icon, string $label, string $value = ''): string
    {
        $sep = $value !== '' ? ' ║ ' : '';
        return "  $icon  <b>$label</b>$sep<code>$value</code>";
    }

    /**
     * متن خوش‌آمد زیبا
     */
    public static function welcome(string $name, bool $hasPanels): string
    {
        $greeting = "سلام " . Str::escape($name);
        
        $lines = [
            self::box($greeting, '👋'),
            '',
            '🌐 به <b>ربات نمایندگان پنل</b> خوش آمدید! 🚀',
            '════════════════════════════════════════',
            '',
        ];

        if ($hasPanels) {
            $lines[] = '✅ <b>شما پنل نمایندگی فعالی دارید!</b> 🎯';
            $lines[] = '';
            $lines[] = '📋 کدام کار را می‌خواهید انجام دهید؟ 👇';
        } else {
            $lines[] = '🛒 <b>برای شروع یک پنل بخرید!</b> 💎';
            $lines[] = '';
            $lines[] = '👉 <b>۲ راه برای شروع:</b>';
            $lines[] = '';
            $lines[] = '   1️⃣  <b>پنل نمایندگی جدید</b>';
            $lines[] = '       🖥️ یک حساب ادمین جدید در پنل';
            $lines[] = '       📊 تمام ابزار مدیریت بسته';
            $lines[] = '';
            $lines[] = '   2️⃣  <b>پنل موجود</b>';
            $lines[] = '       🔗 پنل قبلی را متصل کنید';
            $lines[] = '       🔌 نیاز به نام‌کاربری و رمز دارید';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * منوی اصلی زیبا
     */
    public static function mainMenu(bool $isAdmin, bool $hasPanels, bool $shopOpen = true): string
    {
        $lines = [
            self::box('منوی اصلی', '🏠'),
            '',
        ];

        if (!$hasPanels) {
            $lines[] = '⚠️  <b>هنوز پنل نمایندگی ندارید!</b>';
            $lines[] = '════════════════════════════════════════';
            $lines[] = '';
            $lines[] = '🛒 برای شروع یک بسته بخرید 💎';
            return implode("\n", $lines);
        }

        if (!$shopOpen) {
            $lines[] = '🔴 <b>فروشگاه موقتاً بسته است</b>';
            $lines[] = '⏳ لطفاً کمی بعد مراجعه کنید 🙏';
            return implode("\n", $lines);
        }

        $lines[] = '════════════════════════════════════════';
        $lines[] = '';
        $lines[] = '👇 <b>انتخاب کنید:</b>';

        return implode("\n", $lines);
    }

    /**
     * حساب کاربر با فرمت زیبا
     */
    public static function account(array $user, array $panels = []): string
    {
        $name = Str::escape((string) ($user['first_name'] ?? 'کاربر'));
        
        $lines = [
            self::box("حساب کاربری - $name", '👤'),
            '',
            '📋 <b>اطلاعات شخصی:</b>',
            '════════════════════════════════════════',
            self::row('🆔', 'آیدی تلگرام', (string) ($user['telegram_id'] ?? '—')),
            self::row('👤', 'نام', Str::escape((string) ($user['first_name'] ?? '—'))),
        ];

        if (($user['username'] ?? null) !== null) {
            $lines[] = self::row('📱', 'یوزرنیم', '@' . Str::escape((string) $user['username']));
        }

        $lines[] = '';
        $lines[] = '💰 <b>موجودی و آمار:</b>';
        $lines[] = '════════════════════════════════════════';
        $lines[] = self::row('👛', 'کیف پول', Str::formatToman((int) ($user['wallet_balance'] ?? 0)));
        $lines[] = self::row('⭐', 'امتیاز وفاداری', Str::faNumber((int) ($user['loyalty_points'] ?? 0)));
        $lines[] = self::row('📦', 'تعداد سفارش', Str::faNumber((int) ($user['orders_count'] ?? 0)));
        $lines[] = self::row('💸', 'مجموع خرید', Str::formatToman((int) ($user['total_paid'] ?? 0)));

        $lines[] = '';
        $lines[] = '🖥️ <b>پنل‌های من: ' . Str::faNumber(count($panels)) . '</b>';
        $lines[] = '════════════════════════════════════════';

        if ($panels === []) {
            $lines[] = '📭 پنلی ثبت نشده است';
        } else {
            $totalLimit = 0;
            $totalUsed = 0;

            foreach ($panels as $panel) {
                $totalLimit += (int) $panel['data_limit'];
                $totalUsed += (int) $panel['used_traffic'];

                $status = \Pasargad\Store\PanelRepository::statusLabel($panel);
                $username = Str::escape((string) $panel['panel_username']);
                $limit = Str::formatBytes((int) $panel['data_limit']);

                $lines[] = "  ├─ $status <b>$username</b>";
                $lines[] = "  │  └─ 💾 $limit";
            }

            $lines[] = '';
            $lines[] = '📊 <b>خلاصه:</b>';
            $lines[] = '  • مجموع سقف: ' . Str::formatBytes($totalLimit);
            $lines[] = '  • مجموع مصرف: ' . Str::formatBytes($totalUsed);
        }

        $lines[] = '';
        $lines[] = '📅 عضویت از: ' . Str::date((int) $user['created_at']);

        return implode("\n", $lines);
    }

    /**
     * فروشگاه با فرمت زیبا
     */
    public static function shop(string $kind): string
    {
        $isAgency = $kind === \Pasargad\Store\PackageRepository::KIND_AGENCY;

        $lines = [
            self::box($isAgency ? 'پنل نمایندگی' : 'شارژ و تمدید', '🛒'),
            '',
        ];

        if ($isAgency) {
            $lines[] = '🎉 <b>با خرید این بسته:</b>';
            $lines[] = '  ✓ حساب اپراتور جدید در پنل';
            $lines[] = '  ✓ دسترسی کامل به مدیریت';
            $lines[] = '  ✓ امکان فروش پنل‌های فرعی';
            $lines[] = '  ✓ درآمد از هر بستهٔ فروختهٔ شما';
        } else {
            $lines[] = '⚡ <b>با خرید این بسته:</b>';
            $lines[] = '  ✓ حجم و اعتبار پنل‌های موجود';
            $lines[] = '  ✓ افزایش سقف کاربران';
            $lines[] = '  ✓ تمدید پنل‌های منقضی';
        }

        $lines[] = '';
        $lines[] = '👇 <b>یکی از بسته‌های زیر را انتخاب کنید:</b>';

        return implode("\n", $lines);
    }

    /**
     * پنل سفارش با تمام جزئیات
     */
    public static function orderDetails(array $order): string
    {
        $lines = [
            self::box('جزئیات سفارش', '🧾'),
            '',
            '📋 <b>اطلاعات سفارش:</b>',
            '════════════════════════════════════════',
            self::row('🔖', 'کد سفارش', Str::escape((string) $order['code'])),
            self::row('📦', 'بسته', Str::escape((string) $order['package_title'])),
            self::row('📊', 'وضعیت', self::statusLabel((string) $order['status'])),
            '',
            '💰 <b>مبلغ:</b>',
            '════════════════════════════════════════',
        ];

        $price = (int) ($order['price_toman'] ?? 0);
        $discount = (int) ($order['discount_toman'] ?? 0);

        if ($discount > 0) {
            $original = (int) ($order['original_price_toman'] ?? 0);
            if ($original < $price + $discount) {
                $original = $price + $discount;
            }

            $lines[] = self::row('💰', 'قیمت پایه', Str::formatToman($original));

            if (!empty($order['coupon_code'])) {
                $lines[] = self::row('🎟️', 'کد تخفیف', Str::escape((string) $order['coupon_code']));
            }

            $lines[] = self::row('🎉', 'تخفیف', '−' . Str::formatToman($discount));
        }

        $lines[] = self::row('💵', 'پرداختی', '<b>' . Str::formatToman($price) . '</b>');

        if ((int) ($order['applied_volume'] ?? 0) > 0) {
            $lines[] = '';
            $lines[] = '📊 <b>نتایج:</b>';
            $lines[] = '════════════════════════════════════════';
            $lines[] = self::row('💾', 'حجم اجراشده', Str::formatBytes((int) $order['applied_volume']));
        }

        if (!empty($order['error'])) {
            $lines[] = '';
            $lines[] = '⚠️ <b>خطا:</b>';
            $lines[] = '════════════════════════════════════════';
            $lines[] = Str::escape((string) $order['error']);
        }

        $lines[] = '';
        $lines[] = '🕒 <b>تاریخچه:</b>';
        $lines[] = '════════════════════════════════════════';
        $lines[] = '  • ثبت: ' . Str::date((int) ($order['created_at'] ?? 0));

        if (($order['paid_at'] ?? null) !== null) {
            $lines[] = '  • پرداخت: ' . Str::date((int) $order['paid_at']);
        }

        if (($order['applied_at'] ?? null) !== null) {
            $lines[] = '  • اجرا: ' . Str::date((int) $order['applied_at']);
        }

        return implode("\n", $lines);
    }

    /**
     * برچسب وضعیت سفارش
     */
    public static function statusLabel(string $status): string
    {
        $labels = [
            'created' => '🆕 ایجاد شده',
            'awaiting_payment' => '⏳ منتظر پرداخت',
            'paid' => '💰 پرداخت‌شده',
            'applying' => '⚙️ در حال اجرا',
            'applied' => '✅ اجرا‌شده',
            'failed' => '❌ ناموفق',
            'rejected' => '🚫 رد‌شده',
            'cancelled' => '🚫 لغو',
            'refunded' => '↩️ بازگشت',
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * نیتر پیشرفت
     */
    public static function progressBar(int $percent): string
    {
        $percent = max(0, min(100, $percent));
        $filled = (int) round($percent / 5);
        $bar = str_repeat('▰', $filled) . str_repeat('▱', max(0, 20 - $filled));

        return $bar . ' <b>' . Str::faNumber($percent) . '٪</b>';
    }

    /**
     * پیام خطا
     */
    public static function error(string $message, string $icon = '❌'): string
    {
        return $icon . ' <b>خطا:</b> ' . $message;
    }

    /**
     * پیام موفقیت
     */
    public static function success(string $message, string $icon = '✅'): string
    {
        return $icon . ' <b>موفق:</b> ' . $message;
    }

    /**
     * پیام هشدار
     */
    public static function warning(string $message, string $icon = '⚠️'): string
    {
        return $icon . ' <b>هشدار:</b> ' . $message;
    }

    /**
     * جدول درخت‌گونه
     */
    public static function treeItem(string $text, int $level = 0): string
    {
        $indent = str_repeat('  ', $level);
        $prefix = $level === 0 ? '├─' : '└─';
        return $indent . $prefix . ' ' . $text;
    }
}
