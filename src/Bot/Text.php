<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Str;

/**
 * تمام متن‌های ربات به‌صورت متمرکز، تا ویرایش فارسی ساده باشد.
 */
final class Text
{
    public static function welcome(string $name, bool $linked): string
    {
        $lines = [
            '👋 <b>سلام ' . Str::escape($name) . '!</b>',
            '',
            'به <b>ربات نمایندگان پاسارگاد</b> خوش آمدید.',
            '',
        ];

        $lines[] = $linked
            ? 'حساب شما به پنل متصل است. از منوی زیر کارهایتان را انجام دهید.'
            : 'برای شروع، حساب پنل خود را متصل کنید تا بتوانید بسته بخرید و سرویس بگیرید.';

        return implode("\n", $lines);
    }

    public static function mainMenu(bool $isAdmin, bool $linked, bool $shopOpen = true): string
    {
        $lines = ['🏠 <b>منوی اصلی</b>', ''];

        if (!$linked) {
            $lines[] = '⚠️ حساب شما هنوز به پنل وصل نشده است.';
            $lines[] = 'برای خرید بسته ابتدا باید وارد شوید.';
            return implode("\n", $lines);
        }

        if (!$shopOpen) {
            $lines[] = '🛒 فروشگاه موقتاً بسته است. لطفاً کمی بعد مراجعه کنید.';
        }

        $lines[] = 'از دکمه‌های زیر استفاده کنید 👇';

        return implode("\n", $lines);
    }

    public static function account(array $user): string
    {
        $statusLabels = [
            'active'   => '✅ فعال',
            'limited'  => '⚠️ محدود (سقف حجم پر شده)',
            'disabled' => '⛔️ غیرفعال',
            'revoked'  => '🔑 نیازمند ورود مجدد',
            'pending'  => '⏳ متصل نشده',
        ];

        $status = (string) ($user['panel_status'] ?? 'pending');
        $limit  = (int) ($user['panel_data_limit'] ?? 0);
        $used   = (int) ($user['panel_used'] ?? 0);
        $credit = (int) ($user['user_credit'] ?? 0);

        $lines = [
            '👤 <b>حساب من</b>',
            '',
            '🆔 نام کاربری پنل: <code>' . Str::escape((string) ($user['panel_username'] ?? '—')) . '</code>',
            '📊 وضعیت: ' . ($statusLabels[$status] ?? $status),
        ];

        if ($limit > 0) {
            $percent = $used > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0;
            $lines[] = '💾 حجم کل: <b>' . Str::formatBytes($limit) . '</b>';
            $lines[] = '📥 مصرف: <b>' . Str::formatBytes($used) . '</b> (' . Str::faNumber($percent) . '٪)';
            $lines[] = self::progressBar($percent);
        } else {
            $lines[] = '💾 حجم کل: <b>نامحدود</b>';
        }

        if ($credit > 0) {
            $lines[] = '🎁 اعتبار ساخت کاربر: <b>' . Str::formatBytes($credit) . '</b>';
            if ($user['user_credit_expire'] !== null) {
                $lines[] = '⏳ انقضای اعتبار: ' . Str::date((int) $user['user_credit_expire']);
            }
        }

        if ($user['granted_expire_at'] !== null) {
            $lines[] = '📅 اعتبار حجم خریداری‌شده تا: ' . Str::date((int) $user['granted_expire_at']);
        }

        $lines[] = '';
        $lines[] = '🧾 سفارش‌ها: <b>' . Str::faNumber((int) ($user['orders_count'] ?? 0)) . '</b>';
        $lines[] = '💰 مجموع خرید: <b>' . Str::formatToman((int) ($user['total_paid'] ?? 0)) . '</b>';

        if ($user['panel_synced_at'] !== null) {
            $lines[] = '';
            $lines[] = '🕒 آخرین بروزرسانی از پنل: ' . Str::date((int) $user['panel_synced_at']);
        }

        return implode("\n", $lines);
    }

    public static function progressBar(int $percent): string
    {
        $percent = max(0, min(100, $percent));
        $filled  = (int) round($percent / 5);
        $bar     = str_repeat('▰', $filled) . str_repeat('▱', max(0, 20 - $filled));

        return $bar . ' ' . Str::faNumber($percent) . '٪';
    }

    public static function loginAskUsername(): string
    {
        return implode("\n", [
            '🔐 <b>اتصال به پنل</b>',
            '',
            'برای خرید بسته باید به پنل وصل شوید.',
            '',
            '۱️⃣ نام کاربری ادمین پنل خود را ارسال کنید.',
            '',
            'ℹ️ نام کاربری همان چیزی است که در پنل با آن وارد می‌شوید.',
        ]);
    }

    public static function loginAskPassword(): string
    {
        return implode("\n", [
            '🔑 <b>رمز عبور پنل</b>',
            '',
            '۲️⃣ رمز عبور حساب ادمین خود را بفرستید.',
            '',
            '🔒 رمز شما به‌صورت <b>رمزنگاری‌شده</b> ذخیره می‌شود و فقط برای',
            'اعمال خودکار بسته‌ها روی پنل استفاده می‌گردد.',
            '',
            'پس از ارسال، این پیام را از حافظهٔ چت خود پاک کنید.',
        ]);
    }

    public static function loginSuccess(array $admin): string
    {
        $limit = (int) ($admin['data_limit'] ?? 0);
        $used  = (int) ($admin['used_traffic'] ?? 0);

        $lines = [
            '✅ <b>ورود موفق بود!</b>',
            '',
            '🆔 حساب: <code>' . Str::escape((string) ($admin['username'] ?? '')) . '</code>',
            '💾 حجم: <b>' . Str::formatBytes($limit) . '</b>',
            '📥 مصرف: <b>' . Str::formatBytes($used) . '</b>',
        ];

        if (isset($admin['role']['name'])) {
            $lines[] = '🎭 نقش: ' . Str::escape((string) $admin['role']['name']);
        }

        $lines[] = '';
        $lines[] = 'حالا می‌توانید از فروشگاه بسته بخرید. 🛒';

        return implode("\n", $lines);
    }

    public static function loginFailed(string $message): string
    {
        return "❌ <b>ورود ناموفق بود</b>\n\n" . Str::escape($message)
            . "\n\nلطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.";
    }

    public static function shopList(string $kind): string
    {
        $title = $kind === \Pasargad\Store\PackageRepository::KIND_USER_CREDIT
            ? '🎁 بسته‌های اعتبار کاربر'
            : '📦 بسته‌های حجم پنل';

        return $title . "\n\nیکی از بسته‌های زیر را انتخاب کنید 👇";
    }

    public static function packageDetails(array $package): string
    {
        $isCredit = $package['kind'] === \Pasargad\Store\PackageRepository::KIND_USER_CREDIT;

        $lines = [
            '📦 <b>' . Str::escape((string) $package['title']) . '</b>',
            '',
        ];

        if (($package['description'] ?? '') !== '') {
            $lines[] = Str::escape((string) $package['description']);
            $lines[] = '';
        }

        $lines[] = '💾 حجم: <b>' . Str::faNumber((float) $package['volume_gb'], (float) $package['volume_gb'] < 10 ? 1 : 0) . ' گیگابایت</b>';

        if ((float) ($package['bonus_gb'] ?? 0) > 0) {
            $lines[] = '🎁 هدیه: <b>+' . Str::faNumber((float) $package['bonus_gb'], 1) . ' گیگابایت</b>';
        }

        if ((int) $package['duration_days'] > 0) {
            $lines[] = '📅 اعتبار: <b>' . Str::faNumber((int) $package['duration_days']) . ' روز</b>';
        }

        $lines[] = '';
        $lines[] = '💰 قیمت: <b>' . Str::formatToman((int) $package['price_toman']) . '</b>';
        $lines[] = '';

        $lines[] = $isCredit
            ? 'ℹ️ این بسته به‌عنوان <b>اعتبار ساخت کاربر</b> به شما داده می‌شود و'
                . ' می‌توانید با آن برای مشتریان خود کاربر بسازید.'
            : 'ℹ️ این بسته <b>مستقیماً</b> حجم حساب پنل شما را افزایش می‌دهد.';

        return implode("\n", $lines);
    }

    public static function confirmPurchase(array $package): string
    {
        return implode("\n", [
            '🛒 <b>تأیید خرید</b>',
            '',
            '📦 بسته: <b>' . Str::escape((string) $package['title']) . '</b>',
            '💾 حجم: <b>' . Str::faNumber((float) $package['volume_gb'], 1) . ' گیگابایت</b>',
            '📅 اعتبار: <b>' . Str::faNumber((int) $package['duration_days']) . ' روز</b>',
            '💰 مبلغ: <b>' . Str::formatToman((int) $package['price_toman']) . '</b>',
            '',
            'مطمئن هستید؟ می‌خواهید پرداخت را ادامه دهید؟',
        ]);
    }

    public static function orderCreated(array $order): string
    {
        return implode("\n", [
            '🧾 <b>سفارش شما ثبت شد</b>',
            '',
            'کد سفارش: <code>' . Str::escape((string) $order['code']) . '</code>',
            '📦 بسته: ' . Str::escape((string) $order['package_title']),
            '💰 مبلغ: <b>' . Str::formatToman((int) $order['price_toman']) . '</b>',
            '',
            'روش پرداخت را انتخاب کنید 👇',
        ]);
    }

    public static function paymentMethods(): string
    {
        return "💳 <b>انتخاب روش پرداخت</b>\n\nکدام روش را ترجیح می‌دهید؟";
    }

    public static function orderList(array $orders): string
    {
        if ($orders === []) {
            return '📭 هنوز سفارشی ثبت نکرده‌اید.';
        }

        $statusLabels = [
            'created'          => '🆕 ایجاد شده',
            'awaiting_payment' => '⏳ در انتظار پرداخت',
            'paid'             => '💰 پرداخت شده (در حال اجرا)',
            'applying'         => '⚙️ در حال اجرا',
            'applied'          => '✅ اجرا شد',
            'failed'           => '❌ ناموفق',
            'rejected'         => '🚫 پرداخت رد شد',
            'cancelled'        => '🚫 لغو شده',
            'refunded'         => '↩️ بازگشت وجه',
        ];

        $lines = ['🧾 <b>سفارش‌های من</b>', ''];

        foreach ($orders as $order) {
            $lines[] = '<b>' . Str::escape((string) $order['code']) . '</b>';
            $lines[] = '📦 ' . Str::escape((string) $order['package_title']);
            $lines[] = '💰 ' . Str::formatToman((int) $order['price_toman'])
                . '  •  ' . ($statusLabels[(string) $order['status']] ?? (string) $order['status']);
            $lines[] = '🕒 ' . Str::date((int) $order['created_at']);
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /**
     * برچسب فارسی وضعیت سفارش — تنها منبع حقیقت برای نمایش وضعیت.
     *
     * نگه‌داشتن یک نقشهٔ واحد مهم است: هر وضعیت جدیدی که به OrderRepository
     * اضافه شود، اینجا هم دیده می‌شود و کاربر وضعیت خام نمی‌بیند.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'created'          => '🆕 ایجاد شده',
            'awaiting_payment' => '⏳ در انتظار پرداخت',
            'paid'             => '💰 پرداخت شده — در صف اجرا',
            'applying'         => '⚙️ در حال اجرا روی پنل',
            'applied'          => '✅ با موفقیت اجرا شد',
            'failed'           => '❌ ناموفق',
            'rejected'         => '🚫 پرداخت توسط مدیریت رد شد',
            'cancelled'        => '🚫 لغو شده',
            'refunded'         => '↩️ بازگشت وجه',
        ];
    }

    /**
     * برچسب وضعیت با بازگشت به خود وضعیت در صورت ناشناخته بودن.
     */
    public static function statusLabel(string $status): string
    {
        return self::statusLabels()[$status] ?? $status;
    }

    public static function orderDetails(array $order): string
    {
        $statusLabels = [
            'created'          => '🆕 ایجاد شده',
            'awaiting_payment' => '⏳ در انتظار پرداخت',
            'paid'             => '💰 پرداخت شده — در صف اجرا',
            'applying'         => '⚙️ در حال اجرا روی پنل',
            'applied'          => '✅ با موفقیت اجرا شد',
            'failed'           => '❌ ناموفق',
            'rejected'         => '🚫 پرداخت رد شد',
            'cancelled'        => '🚫 لغو شده',
            'refunded'         => '↩️ بازگشت وجه',
        ];

        $lines = [
            '🧾 <b>جزئیات سفارش</b>',
            '',
            'کد: <code>' . Str::escape((string) $order['code']) . '</code>',
            '📦 ' . Str::escape((string) $order['package_title']),
            '💰 ' . Str::formatToman((int) $order['price_toman']),
            '📊 وضعیت: ' . ($statusLabels[(string) $order['status']] ?? (string) $order['status']),
        ];

        if ((int) ($order['applied_volume'] ?? 0) > 0) {
            $lines[] = '💾 حجم اجراشده: <b>' . Str::formatBytes((int) $order['applied_volume']) . '</b>';
        }

        if (!empty($order['before_limit']) && !empty($order['after_limit'])) {
            $lines[] = '🔄 سقف حجم: ' . Str::formatBytes((int) $order['before_limit'])
                . ' ← <b>' . Str::formatBytes((int) $order['after_limit']) . '</b>';
        }

        if (!empty($order['error'])) {
            $lines[] = '⚠️ خطا: ' . Str::escape((string) $order['error']);
        }

        if ((int) ($order['attempts'] ?? 0) > 0 && $order['status'] === 'failed') {
            $lines[] = '🔁 تلاش‌های ناموفق: ' . Str::faNumber((int) $order['attempts']);
            if ($order['next_attempt_at'] !== null) {
                $lines[] = '⏱ تلاش بعدی: ' . Str::date((int) $order['next_attempt_at']);
            }
        }

        $lines[] = '';
        $lines[] = '🕒 ثبت: ' . Str::date((int) $order['created_at']);

        if ($order['paid_at'] !== null) {
            $lines[] = '💳 پرداخت: ' . Str::date((int) $order['paid_at']);
        }
        if ($order['applied_at'] !== null) {
            $lines[] = '✅ اجرا: ' . Str::date((int) $order['applied_at']);
        }

        return implode("\n", $lines);
    }

    public static function notFound(): string
    {
        return '🤷 موردی پیدا نشد.';
    }

    public static function blocked(string $reason): string
    {
        return "🚫 <b>دسترسی شما مسدود است</b>\n\n"
            . 'دلیل: ' . Str::escape($reason)
            . "\n\nبرای اطلاعات بیشتر با پشتیبانی تماس بگیرید.";
    }

    public static function onlyAdmins(): string
    {
        return '⛔️ این بخش مخصوص سوپرادمین است.';
    }

    public static function support(): string
    {
        $link = \Pasargad\Support\Config::str('notifications.support_link');

        return "📞 <b>پشتیبانی</b>\n\n"
            . 'در صورت هر مشکلی با ما در تماس باشید:\n'
            . ($link !== '' ? $link : '—');
    }

    public static function help(): string
    {
        return implode("\n", [
            'ℹ️ <b>راهنمای ربات</b>',
            '',
            'این ربات برای نمایندگان و ادمین‌های پنل پاسارگاد است و کارهای زیر را انجام می‌دهد:',
            '',
            '۱️⃣ اتصال حساب پنل با نام کاربری و رمز عبور',
            '۲️⃣ خرید بستهٔ حجمی (افزایش مستقیم سقف حجم پنل شما)',
            '۳️⃣ خرید بستهٔ اعتبار برای ساخت کاربران مشتریان',
            '۴️⃣ پرداخت با کارت‌به‌کارت یا ارز دیجیتال',
            '۵️⃣ اجرای <b>خودکار</b> بسته روی پنل بلافاصله پس از تأیید پرداخت',
            '',
            'دستورها:',
            '/start — منوی اصلی',
            '/shop — فروشگاه',
            '/account — حساب من',
            '/orders — سفارش‌های من',
            '/buy — خرید با کد سفارش',
            '/login — اتصال مجدد به پنل',
            '/logout — قطع اتصال پنل',
            '/help — همین راهنما',
        ]);
    }
}