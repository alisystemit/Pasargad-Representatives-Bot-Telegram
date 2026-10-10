<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Str;

/**
 * تمام متن‌های ربات به‌صورت متمرکز، تا ویرایش فارسی ساده باشد.
 */
final class Text
{
    public static function welcome(string $name, bool $hasPanels): string
    {
        // متن خوش‌آمد سفارشی ادمین (اگر تنظیم شده باشد)
        try {
            $settings = new \Pasargad\Store\Settings();
            $customWelcome = trim((string) $settings->get(\Pasargad\Store\Settings::WELCOME_TEXT, ''));
            if ($customWelcome !== '') {
                return str_replace('{name}', Str::escape($name), $customWelcome);
            }
        } catch (\Throwable $e) {
            // در تست‌ها ممکن است دیتابیس نباشد — متن پیش‌فرض
        }

        $lines = [
            '╔══════════════════════════════════╗',
            '║  👋 سلام ' . str_pad(Str::escape($name), 22) . '║',
            '║              🌹  ✨              ║',
            '╚══════════════════════════════════╝',
            '',
            '🌐 به <b>ربات نمایندگان پنل 🚀</b> خوش آمدید!',
            '────────────────────────────────────',
            '',
        ];

        $lines[] = $hasPanels
            ? '✅ <b>پنل‌های شما فعال‌اند!</b> 🎯' . "\n" .
              '   آماده‌اید برای شروع؟ 👇'
            : '🛒 <b>برای شروع سفارش ثبت کنید! 💎</b>' . "\n" .
              '   • پنل نمایندگی جدید بخرید 🖥️' . "\n" .
              '   • یا پنل موجود را متصل کنید 🔌';

        return implode("\n", $lines);
    }

    public static function mainMenu(bool $isAdmin, bool $hasPanels, bool $shopOpen = true): string
    {
        $lines = [
            '┌─────────────────────────────────┐',
            '│  🏠 منوی اصلی 🌟                │',
            '└─────────────────────────────────┘',
            '',
        ];

        if (!$hasPanels) {
            $lines[] = '⚠️  <b>هنوز پنل نمایندگی ندارید!</b>';
            $lines[] = '🛒 برای شروع یک بسته بخرید 💎';
            return implode("\n", $lines);
        }

        if (!$shopOpen) {
            $lines[] = '🔴 <b>فروشگاه موقتاً بسته است</b>';
            $lines[] = '⏳ لطفاً کمی بعد مراجعه کنید 🙏';
            return implode("\n", $lines);
        }

        $lines[] = '👇 <b>از دکمه‌های زیر استفاده کنید:</b>';

        return implode("\n", $lines);
    }

    /**
     * خلاصهٔ حساب کاربر (سطح کاربر، نه سطح پنل).
     *
     * @param array<string, mixed>            $user
     * @param array<int, array<string, mixed>> $panels
     */
    public static function account(array $user, array $panels = []): string
    {
        $lines = [
            '👤✨ <b>حساب من 🎫</b>',
            '',
            '🆔📱 تلگرام: <code>' . (int) ($user['telegram_id'] ?? 0) . '</code>',
            '👤📝 نام: ' . Str::escape((string) ($user['first_name'] ?? '—')),
        ];

        if (($user['username'] ?? null) !== null) {
            $lines[] = '📱💬 یوزرنیم: @' . Str::escape((string) $user['username']);
        }

        $lines[] = '';
        $lines[] = '💰👛 کیف پول: <b>' . Str::formatToman((int) ($user['wallet_balance'] ?? 0)) . '</b> ✨';
        $lines[] = '⭐ امتیاز وفاداری: <b>' . Str::faNumber((int) ($user['loyalty_points'] ?? 0)) . '</b>';
        $lines[] = '';
        $lines[] = '🖥️🌐 <b>پنل‌های من: ' . Str::faNumber(count($panels)) . ' 🎯</b>';

        if ($panels === []) {
            $lines[] = '📭😔 پنلی ثبت نشده است! 🈳';
        } else {
            $totalLimit = 0;
            $totalUsed  = 0;

            foreach ($panels as $panel) {
                $totalLimit += (int) $panel['data_limit'];
                $totalUsed  += (int) $panel['used_traffic'];

                $lines[] = '• ' . \Pasargad\Store\PanelRepository::statusLabel($panel) . ' '
                    . Str::escape((string) $panel['panel_username'])
                    . ' — ' . Str::formatBytes((int) $panel['data_limit']);
            }

            $lines[] = '';
            $lines[] = '💾 مجموع سقف: <b>' . Str::formatBytes($totalLimit) . '</b> 📦';
            $lines[] = '📥 مجموع مصرف: <b>' . Str::formatBytes($totalUsed) . '</b> 📊';
        }

        $lines[] = '';
        $lines[] = '🧾✨ سفارش‌ها: <b>' . Str::faNumber((int) ($user['orders_count'] ?? 0)) . '</b> 📦';
        $lines[] = '💰✨ مجموع خرید: <b>' . Str::formatToman((int) ($user['total_paid'] ?? 0)) . '</b> 🎉';
        $lines[] = '📅🗓 عضویت: ' . Str::date((int) $user['created_at']) . ' 🎫';

        return implode("\n", $lines);
    }

    public static function progressBar(int $percent): string
    {
        $percent = max(0, min(100, $percent));
        $filled  = (int) round($percent / 5);
        $bar     = str_repeat('▰', $filled) . str_repeat('▱', max(0, 20 - $filled));

        return $bar . ' ' . Str::faNumber($percent) . '٪';
    }

    // ------------------------------------------------------------------
    // فروشگاه
    // ------------------------------------------------------------------

    public static function shopList(string $kind): string
    {
        $isAgency = $kind === \Pasargad\Store\PackageRepository::KIND_AGENCY;

        $title = $isAgency ? '🖥️✨ بسته‌های پنل نمایندگی 🌟💎' : '⚡️🔋 بسته‌های شارژ و تمدید پنل 🔄💳';

        return $title . "\n\n"
            . ($isAgency
                ? '🎉 با خرید این بسته‌ها یک حساب اپراتور تازه در پنل برای شما ساخته می‌شود! 👑🚀'
                : '🔋✨ حجم و اعتبار پنل‌های موجود شما افزایش پیدا می‌کند! 📈🎁')
            . "\n\n👇🎯 یکی از بسته‌های زیر را انتخاب کنید 👇✨";
    }

    public static function packageDetails(array $package): string
    {
        $isAgency = $package['kind'] === \Pasargad\Store\PackageRepository::KIND_AGENCY;

        $lines = [
            '📦✨ <b>' . Str::escape((string) $package['title']) . ' 🌟</b>',
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

        $lines[] = '👥 سقف کاربران: <b>' . \Pasargad\Store\PackageRepository::userLimitLabel($package['max_users'] ?? 0) . '</b> 🎯';

        $lines[] = '';
        $lines[] = '💰 قیمت: <b>' . Str::formatToman((int) $package['price_toman']) . '</b> 💵';
        $lines[] = '';

        $lines[] = $isAgency
            ? 'ℹ️ این بسته یک <b>پنل نمایندگی تازه</b> برای شما می‌سازد: حساب ادمین با نقش '
                . 'اپراتور در پنل، به‌همراه آدرس ورود، نام کاربری و رمز عبور.'
            : 'ℹ️ این بسته <b>حجم و اعتبار</b> یکی از پنل‌های موجود شما را افزایش می‌دهد.';

        return implode("\n", $lines);
    }

    /**
     * @param string $panelUsername نام پنل هدف (فقط برای بستهٔ شارژ)
     */
    public static function confirmPurchase(array $package, string $panelUsername = ''): string
    {
        $lines = [
            '🛒✨ <b>تأیید خرید ✅</b>',
            '',
            '📦🎁 بسته: <b>' . Str::escape((string) $package['title']) . '</b>',
            '💾 حجم: <b>' . Str::faNumber((float) $package['volume_gb'], 1) . ' گیگابایت</b> 📦',
            '📅 اعتبار: <b>' . Str::faNumber((int) $package['duration_days']) . ' روز</b> ⏳',
            '👥 سقف کاربران: <b>' . \Pasargad\Store\PackageRepository::userLimitLabel($package['max_users'] ?? 0) . '</b> 🎯',
            '💰 مبلغ: <b>' . Str::formatToman((int) $package['price_toman']) . '</b> 💵',
        ];

        if ($panelUsername !== '') {
            $lines[] = '🖥️ پنل مقصد: <code>' . Str::escape($panelUsername) . '</code> 🌐';
        }

        $lines[] = '';
        $lines[] = '👇 مطمئن هستید؟ می‌خواهید پرداخت را ادامه دهید؟ 💳✅';

        return implode("\n", $lines);
    }

    public static function paymentMethods(): string
    {
        return "💳✨ <b>انتخاب روش پرداخت 💰</b>\n\n💎 کدام روش را ترجیح می‌دهید؟ 👇😊";
    }

    // ------------------------------------------------------------------
    // سفارش‌ها
    // ------------------------------------------------------------------

    public static function orderList(array $orders): string
    {
        if ($orders === []) {
            return '📭😔 هنوز سفارشی ثبت نکرده‌اید! 🛒👇';
        }

        $lines = ['🧾✨ <b>سفارش‌های من 📦</b>', ''];

        foreach ($orders as $order) {
            $lines[] = '🔖 <b>' . Str::escape((string) $order['code']) . '</b>';
            $lines[] = '📦🎁 ' . Str::escape((string) $order['package_title']);
            $lines[] = '💰 ' . Str::formatToman((int) $order['price_toman'])
                . '  •  ' . self::statusLabel((string) $order['status']);
            $lines[] = '🕒📅 ' . Str::date((int) $order['created_at']);
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

    public static function statusLabel(string $status): string
    {
        return self::statusLabels()[$status] ?? $status;
    }

    public static function orderDetails(array $order): string
    {
        $lines = [
            '🧾✨ <b>جزئیات سفارش 📋</b>',
            '',
            '🔖 کد: <code>' . Str::escape((string) $order['code']) . '</code>',
            '📦🎁 ' . Str::escape((string) $order['package_title']),
        ];

        // تخفیف باید اینجا هم دیده شود، نه فقط در فاکتور.
        //
        // دلیل: کاربری که با کد ۲۰٪ خرید کرده، در این صفحه فقط مبلغ کم‌شده را
        // می‌بیند و نمی‌داند چرا. بعداً شکایت می‌کند که «قیمت سایت با فاکتور
        // فرق دارد» — و حق دارد چون هیچ‌جا علتش نوشته نشده.
        $price    = (int) ($order['price_toman'] ?? 0);
        $discount = (int) ($order['discount_toman'] ?? 0);

        if ($discount > 0) {
            $original = (int) ($order['original_price_toman'] ?? 0);

            if ($original < $price + $discount) {
                $original = $price + $discount;
            }

            $lines[] = '💰 قیمت پایه: ' . Str::formatToman($original);

            if (!empty($order['coupon_code'])) {
                $lines[] = '🎟️ کد تخفیف: <code>' . Str::escape((string) $order['coupon_code']) . '</code>';
            } elseif (!empty($order['referred_by'])) {
                $lines[] = '🎁 پاداش معرفی اعمال شد';
            }

            $lines[] = '🎉 تخفیف: <b>−' . Str::formatToman($discount) . '</b>';
        }

        $lines[] = '💰💵 ' . Str::formatToman($price);
        $lines[] = '📊 وضعیت: ' . self::statusLabel((string) $order['status']);

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

        if ((int) ($order['attempts'] ?? 0) > 0 && ($order['status'] ?? '') === 'failed') {
            $lines[] = '🔁 تلاش‌های ناموفق: ' . Str::faNumber((int) $order['attempts']);

            if (($order['next_attempt_at'] ?? null) !== null) {
                $lines[] = '⏱ تلاش بعدی: ' . Str::date((int) $order['next_attempt_at']);
            }
        }

        $lines[] = '';
        $lines[] = '🕒 ثبت: ' . Str::date((int) ($order['created_at'] ?? 0)) . ' 📝';

        // `??` نه `<>`: این متد با آرایه‌های ناقص هم صدا زده می‌شود و کلید
        // غایب نباید Warning بسازد (که در تست دیده شد).
        if (($order['paid_at'] ?? null) !== null) {
            $lines[] = '💳 پرداخت: ' . Str::date((int) $order['paid_at']) . ' ✅';
        }

        if (($order['applied_at'] ?? null) !== null) {
            $lines[] = '✅ اجرا: ' . Str::date((int) $order['applied_at']) . ' 🎉';
        }

        if (($order['payment_method'] ?? null) !== null && trim((string) $order['payment_method']) !== '') {
            $lines[] = '💳 روش: ' . Str::escape((string) $order['payment_method']);
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // عمومی
    // ------------------------------------------------------------------

    public static function notFound(): string
    {
        return '🤷😔 موردی پیدا نشد! 🔍';
    }

    /**
     * پیام شکست در بررسی اطلاعات ورود پنل (جریان «من پنل دارم»).
     */
    public static function loginFailed(string $message): string
    {
        return "❌🔑 <b>اطلاعات پنل درست نیست! 😔</b>\n\n" . Str::escape($message)
            . "\n\n🔍 نام کاربری و رمز عبور را بررسی کنید و دوباره تلاش کنید! 🔄";
    }

    public static function shopClosed(): string
    {
        return "🛒🔴 <b>فروشگاه موقتاً بسته است! 😴</b>\n\n"
            . "در حال حاضر امکان ثبت سفارش جدید وجود ندارد! ⏳\n"
            . "برای اطلاع از زمان بازگشایی با پشتیبانی در تماس باشید. 📞🙏";
    }

    public static function blocked(string $reason): string
    {
        return "🚫⛔️ <b>دسترسی شما مسدود است! 🔒</b>\n\n"
            . '📝 دلیل: ' . Str::escape($reason)
            . "\n\nبرای اطلاعات بیشتر با پشتیبانی تماس بگیرید. 📞🙏";
    }

    public static function onlyAdmins(): string
    {
        return '⛔️🛠 این بخش مخصوص سوپرادمین است! 👑';
    }

    public static function support(): string
    {
        try {
            $settings = new \Pasargad\Store\Settings();
            $custom = trim((string) $settings->get(\Pasargad\Store\Settings::SUPPORT_TEXT, ''));
            if ($custom !== '') {
                return $custom;
            }
        } catch (\Throwable $e) {
        }
        $link = \Pasargad\Support\Config::str('notifications.support_link');

        return "📞✨ <b>پشتیبانی 💬🆘</b>\n\n"
            . '📩 در صورت هر مشکلی با ما در تماس باشید: 🫶✨' . "\n"
            . ($link !== '' ? $link : '—');
    }

    public static function help(): string
    {
        try {
            $settings = new \Pasargad\Store\Settings();
            $custom = trim((string) $settings->get(\Pasargad\Store\Settings::HELP_TEXT, ''));
            if ($custom !== '') {
                return $custom;
            }
        } catch (\Throwable $e) {
        }
        return implode("\n", [
            'ℹ️✨ <b>راهنمای ربات 🤖📖</b>',
            '',
            '🌟 این ربات مخصوص نمایندگان پنل است و کارهای زیر را انجام می‌دهد: 🎯',
            '',
            '1️⃣ خرید <b>🖥️ پنل نمایندگی 👑</b>؛ حساب اپراتور شما در پنل ساخته می‌شود 🚀',
            '2️⃣ نمایش کامل اطلاعات پنل: 📋 آدرس ورود، نام کاربری، رمز، حجم و زمان ⏳',
            '3️⃣ شارژ و تمدید هر یک از پنل‌های شما (هر کاربر چند پنل می‌تواند داشته باشد) 🔋💳',
            '4️⃣ دریافت <b>🧪 تست کانفیگ 🎁</b> روی پنل خودتان ✨',
            '5️⃣ اتصال پنلی که از قبل دارید با دکمهٔ «🔗 من پنل دارم» 🔌',
            '6️⃣ پرداخت با کارت‌به‌کارت 💳 یا ارز دیجیتال 🪙 و اجرای خودکار پس از تأیید ✅',
            '7️⃣ مشاهده 💰 کیف پول 👛 و 🧾 تاریخچه پرداخت‌ها 📊',
            '',
            '⚠️⛔️ پس از اتمام اعتبار پنل، دسترسی همهٔ کاربران آن پنل قطع می‌شود! 🔒',
            '',
            '📜 دستورها: ⌨️',
            '/start — 🏠 منوی اصلی ✨',
            '/shop — 🛒 فروشگاه 💎',
            '/panels — 🖥️ پنل‌های من 🌐',
            '/account — 👤 حساب من 🎫',
            '/orders — 🧾 سفارش‌های من 📦',
            '/payments — 💳 تاریخچه پرداخت‌ها 📊',
            '/wallet — 💰 کیف پول 👛',
            '/test — 🧪 کانفیگ‌های تست 🎁',
            '/rules — 📜 قوانین و شرایط ⚖️',
            '/buy — 🛒 خرید با کد سفارش 🎫',
            '/login — 🔗 ثبت پنل موجود 🔌',
            '/help — ℹ️ همین راهنما 📖',
        ]);
    }

    /**
     * متن کیف پول کاربر 💰
     *
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $txns
     */
    public static function wallet(array $user, array $txns = []): string
    {
        $lines = [
            '💰✨ <b>کیف پول من 👛</b> ✨💰',
            '',
            '💵 موجودی فعلی: <b>' . Str::formatToman((int) ($user['wallet_balance'] ?? 0)) . '</b> 🪙',
            '',
        ];

        if ($txns === []) {
            $lines[] = '📭 هنوز تراکنشی ثبت نشده است! 😊';
        } else {
            $lines[] = '📊 <b>آخرین تراکنش‌ها: 🧾</b>';
            $lines[] = '';
            foreach (array_slice($txns, 0, 10) as $txn) {
                $amount = (int) ($txn['amount'] ?? 0);
                $icon = $amount >= 0 ? '🟢➕' : '🔴➖';
                $lines[] = $icon . ' <b>' . Str::formatToman($amount) . '</b> 📅 ' . Str::date((int) ($txn['created_at'] ?? 0));
                if (!empty($txn['note'])) {
                    $lines[] = '   📝 ' . Str::escape((string) $txn['note']);
                }
            }
        }

        $lines[] = '';
        $lines[] = 'ℹ️💡 برای شارژ کیف پول با پشتیبانی در تماس باشید! 📞✨';

        return implode("\n", $lines);
    }

    /**
     * تاریخچه پرداخت‌های کاربر 🧾
     *
     * @param array<int, array<string, mixed>> $payments
     */
    public static function paymentHistory(array $payments): string
    {
        if ($payments === []) {
            return '💳📭 هنوز پرداختی ثبت نکرده‌اید! 😊🛒';
        }

        $lines = ['💳✨ <b>تاریخچه پرداخت‌ها 🧾📊</b>', ''];
        foreach (array_slice($payments, 0, 15) as $payment) {
            $statusIcon = match ((string) ($payment['status'] ?? '')) {
                'confirmed', 'finished' => '✅',
                'failed', 'expired' => '❌',
                default => '⏳',
            };
            $lines[] = $statusIcon . ' 💰 <b>' . Str::formatToman((int) ($payment['amount_toman'] ?? 0)) . '</b>'
                . ' • 💳 ' . Str::escape((string) ($payment['method'] ?? '—'))
                . ' • 📅 ' . Str::date((int) ($payment['created_at'] ?? 0));
            if (!empty($payment['external_id'])) {
                $lines[] = '   🔗 <code>' . Str::escape((string) $payment['external_id']) . '</code>';
            }
        }

        return implode("\n", $lines);
    }
}