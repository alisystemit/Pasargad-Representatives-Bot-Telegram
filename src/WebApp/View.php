<?php

declare(strict_types=1);

namespace Pasargad\WebApp;

use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\TicketRepository;
use Pasargad\Support\Invoice;
use Pasargad\Support\Str;

/**
 * تبدیل ردیف‌های دیتابیس به ساختارهای آمادهٔ JSON برای Mini App.
 *
 * چرا یک لایهٔ جدا؟
 *
 * • قالب نمایش (برچسب وضعیت، درصد مصرف، متن فارسی) در یک جا می‌ماند، نه در
 *   ده‌ها نقطهٔ مختلف پاسخ API — پس تغییر ظاهر یک‌جا انجام می‌شود.
 * • دادهٔ خام دیتابیس (رمز رمزنگاری‌شدهٔ پنل، ستون‌های داخلی مثل
 *   `terminal_reason`) هرگز از API بیرون نمی‌رود. آنچه بیرون می‌آید
 *   **فیلدهای انتخاب‌شده و نام‌گذاری‌شده** است.
 * • کلاینت هیچ محاسبهٔ مالی/حجمی نمی‌کند؛ همه‌چیز اینجا یک‌بار و یک‌شکل
 *   حساب می‌شود.
 */
final class View
{
    /**
     * برچسب و رنگ هر وضعیت سفارش.
     *
     * @return array{label:string, tone:string}
     */
    public static function orderStatus(string $status): array
    {
        return match ($status) {
            OrderRepository::STATUS_CREATED         => ['label' => '🆕 ساخته شده',         'tone' => 'neutral'],
            OrderRepository::STATUS_AWAITING_PAYMENT => ['label' => '⏳ در انتظار پرداخت', 'tone' => 'warn'],
            OrderRepository::STATUS_PAID            => ['label' => '💳 پرداخت‌شده',         'tone' => 'info'],
            OrderRepository::STATUS_APPLYING        => ['label' => '⚙️ در حال اجرا',       'tone' => 'info'],
            OrderRepository::STATUS_APPLIED         => ['label' => '✅ اجرا شد',           'tone' => 'ok'],
            OrderRepository::STATUS_FAILED          => ['label' => '⚠️ ناموفق',           'tone' => 'warn'],
            OrderRepository::STATUS_CANCELLED       => ['label' => '🚫 لغو شد',            'tone' => 'bad'],
            OrderRepository::STATUS_REFUNDED        => ['label' => '↩️ بازگشت وجه',       'tone' => 'bad'],
            OrderRepository::STATUS_REJECTED        => ['label' => '❌ پرداخت رد شد',      'tone' => 'bad'],
            default                                => ['label' => $status,                'tone' => 'neutral'],
        };
    }

    /**
     * وضعیت پنل به‌همراه برچسب و رنگ.
     *
     * «منقضی» از `panel_status` مستقل است: ممکن است پنل روی خودِ پنل «فعال»
     * باشد ولی قراردادش تمام شده باشد؛ آن‌وقت همین حرف آخر است و باید به کاربر
     * گفته شود.
     *
     * @param  array<string, mixed> $panel
     * @return array{key:string, label:string, tone:string}
     */
    public static function panelStatus(array $panel, int $graceDays = 3): array
    {
        if (PanelRepository::isExpired($panel)) {
            if (PanelRepository::isInGrace($panel, $graceDays)) {
                return ['key' => 'grace', 'label' => '🚨 در مهلت ارفاقی', 'tone' => 'warn'];
            }

            return ['key' => 'expired', 'label' => '⌛️ اعتبار تمام شده', 'tone' => 'bad'];
        }

        $status = (string) ($panel['panel_status'] ?? '');

        return match ($status) {
            PanelRepository::STATUS_ACTIVE   => ['key' => 'active',   'label' => '🟢 فعال',               'tone' => 'ok'],
            PanelRepository::STATUS_LIMITED  => ['key' => 'limited',  'label' => '🟡 محدود (حجم تمام)',   'tone' => 'warn'],
            PanelRepository::STATUS_DISABLED => ['key' => 'disabled', 'label' => '⛔️ غیرفعال',            'tone' => 'bad'],
            PanelRepository::STATUS_REVOKED  => ['key' => 'revoked',  'label' => '🔑 نیازمند ورود مجدد',  'tone' => 'bad'],
            default                          => [
                'key'   => $status !== '' ? $status : 'unknown',
                'label' => $status !== '' ? $status : '—',
                'tone'  => 'neutral',
            ],
        };
    }

    /**
     * وضعیت حجم یک پنل.
     *
     * قرارداد پنل: `data_limit = 0` یعنی **نامحدود**، نه «حجم صفر». پس
     * نامحدود جدا برمی‌گردد تا کلاینت نوار پیشرفت صفر درصد نشان ندهد که
     * یعنی «ظرفیت پر شده».
     *
     * @param  array<string, mixed> $panel
     * @return array{limit:int, used:int, percent:?int, unlimited:bool, left:?int, limit_text:string, used_text:string}
     */
    public static function panelTraffic(array $panel): array
    {
        $limit = max(0, (int) ($panel['data_limit'] ?? 0));
        $used  = max(0, (int) ($panel['used_traffic'] ?? 0));

        $unlimited = $limit <= 0;
        $percent   = $unlimited ? null : ($used > 0 ? min(100, (int) round($used / $limit * 100)) : 0);

        return [
            'limit'      => $limit,
            'used'       => $used,
            'percent'    => $percent,
            'unlimited'  => $unlimited,
            'left'       => $unlimited ? null : max(0, $limit - $used),
            'limit_text' => $unlimited ? 'نامحدود ♾️' : Str::formatBytes($limit),
            'used_text'  => Str::formatBytes($used),
        ];
    }

    /**
     * فهرست کارت‌های پنل یک کاربر.
     *
     * @param  array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function panelCards(array $panels, int $graceDays = 3): array
    {
        $out = [];

        foreach ($panels as $panel) {
            $out[] = self::panelCard($panel, $graceDays);
        }

        return $out;
    }

    /**
     * کارت خلاصهٔ یک پنل (بدون رمز — رمز فقط در صفحهٔ جزئیات).
     *
     * @param array<string, mixed> $panel
     * @return array<string, mixed>
     */
    public static function panelCard(array $panel, int $graceDays = 3): array
    {
        $traffic  = self::panelTraffic($panel);
        $status   = self::panelStatus($panel, $graceDays);
        $expireAt = self::nullableInt($panel, 'access_expire_at');

        $graceLeft = PanelRepository::isInGrace($panel, $graceDays)
            ? max(0, (int) PanelRepository::graceDaysLeft($panel, $graceDays))
            : null;

        $label = trim((string) ($panel['label'] ?? ''));

        return [
            'id'          => (int) ($panel['id'] ?? 0),
            'username'    => (string) ($panel['panel_username'] ?? ''),
            'label'       => $label !== '' ? $label : null,
            'status'      => $status,
            'traffic'     => $traffic,
            'users'       => [
                'total'    => max(0, (int) ($panel['users_total'] ?? 0)),
                'active'   => max(0, (int) ($panel['users_active'] ?? 0)),
                'disabled' => max(0, (int) ($panel['users_disabled'] ?? 0)),
                'limit'    => max(0, (int) ($panel['user_limit'] ?? 0)),
                'label'    => PackageRepository::userLimitLabel($panel['user_limit'] ?? 0),
                'at'       => (int) ($panel['stats_at'] ?? 0),
            ],
            'days_left'   => PanelRepository::daysLeft($panel),
            'grace_left'  => $graceLeft,
            'expire_at'   => $expireAt,
            'expire_text' => Str::date($expireAt),
            'synced_at'   => (int) ($panel['synced_at'] ?? 0),
            'subscribed'  => (int) ($panel['is_subscribed'] ?? 0) === 1,
            'is_default'  => (int) ($panel['is_default'] ?? 0) === 1,
            'expired'     => PanelRepository::isExpired($panel),
            'usable'      => PanelRepository::isUsable($panel),
        ];
    }

    /**
     * جزئیات کامل یک پنل — شامل اطلاعات ورود.
     *
     * چرا رمز نمایش داده می‌شود؟ چون نماینده برای کار روزمرهٔ خودش به آن نیاز
     * دارد و ربات آن را رمزنگاری نگه داشته است. این دقیقاً همان تصمیمی است که
     * در ربات گرفته شده؛ رفتار متفاوت در اپلیکیشن یعنی دو نسخهٔ متضاد از یک
     * محصول.
     *
     * @param array<string, mixed> $panel
     * @return array<string, mixed>
     */
    public static function panelDetail(array $panel, string $password, int $graceDays = 3): array
    {
        $card      = self::panelCard($panel, $graceDays);
        $loginUrl  = PanelRepository::loginUrl(
            isset($panel['login_url']) && $panel['login_url'] !== null
                ? (string) $panel['login_url']
                : null
        );
        $role      = trim((string) ($panel['panel_role'] ?? ''));
        $sub       = trim((string) ($panel['sub_url'] ?? ''));
        $diskQuota = (int) ($panel['disk_quota'] ?? 0);

        return array_merge($card, [
            'login_url'      => $loginUrl !== '' ? $loginUrl : null,
            'password'       => $password !== '' ? $password : null,
            'role'           => $role !== '' ? $role : null,
            'disk_quota'     => $diskQuota,
            'disk_text'      => $diskQuota > 0 ? Str::formatBytes($diskQuota) : 'نامحدود ♾️',
            'sub_url'        => $sub !== '' ? $sub : null,
            'granted_gb'     => round((int) ($panel['granted_volume'] ?? 0) / 1073741824, 2),
            'order_id'       => self::nullableInt($panel, 'order_id'),
            'source'         => (string) ($panel['source'] ?? ''),
            'sub_expire_at'  => self::nullableInt($panel, 'subscription_expire_at'),
            'cutoff'         => [
                'requested_at' => self::nullableInt($panel, 'cutoff_requested_at'),
                'done_at'      => self::nullableInt($panel, 'cutoff_done_at'),
                'count'        => max(0, (int) ($panel['cutoff_count'] ?? 0)),
            ],
        ]);
    }

    /**
     * یک بستهٔ فروشگاه برای کارت خرید.
     *
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    public static function package(array $package, int $purchasedCount = 0): array
    {
        $periods = PackageRepository::periodOptions($package);

        $options = [];
        foreach ($periods as $period) {
            $options[] = [
                'days'       => (int) $period['duration_days'],
                'days_text'  => Str::faNumber((int) $period['duration_days']) . ' روز',
                'price'      => (int) $period['price_toman'],
                'price_text' => Str::formatToman((int) $period['price_toman']),
            ];
        }

        $volumeGb  = (float) ($package['volume_gb'] ?? 0);
        $bonusGb   = (float) ($package['bonus_gb'] ?? 0);
        $maxPerUser = max(0, (int) ($package['max_per_user'] ?? 0));

        return [
            'id'              => (int) $package['id'],
            'title'           => (string) $package['title'],
            'description'     => trim((string) ($package['description'] ?? '')) !== ''
                ? (string) $package['description']
                : null,
            'kind'            => (string) $package['kind'],
            'kind_label'      => PackageRepository::kindLabel((string) $package['kind']),
            'volume_gb'       => round($volumeGb, 2),
            'bonus_gb'        => round($bonusGb, 2),
            'volume_text'     => $volumeGb > 0
                ? Str::faNumber($volumeGb, $volumeGb < 10 ? 1 : 0) . ' گیگابایت'
                : '—',
            'bonus_text'      => $bonusGb > 0
                ? '+' . Str::faNumber($bonusGb, 1) . ' گیگابایت هدیه 🎁'
                : null,
            'duration_days'   => (int) ($package['duration_days'] ?? 30),
            'max_users'       => max(0, (int) ($package['max_users'] ?? 0)),
            'max_users_label' => PackageRepository::userLimitLabel($package['max_users'] ?? 0),
            'creates_panel'   => PackageRepository::createsPanel((string) $package['kind']),
            'periods'         => $options,
            'min_price'       => $options === []
                ? 0
                : min(array_map(static fn (array $o): int => (int) $o['price'], $options)),
            'max_per_user'    => $maxPerUser,
            'purchased'       => $purchasedCount,
            'limit_reached'   => $maxPerUser > 0 && $purchasedCount >= $maxPerUser,
        ];
    }

    /**
     * یک سفارش برای فهرست/کارت.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function order(array $order): array
    {
        $amounts = Invoice::amounts($order);

        $coupon   = trim((string) ($order['coupon_code'] ?? ''));
        $referred = trim((string) ($order['referred_by'] ?? ''));
        $method   = trim((string) ($order['payment_method'] ?? ''));
        $ref      = trim((string) ($order['payment_ref'] ?? ''));
        $error    = trim((string) ($order['error'] ?? ''));
        $code     = (string) ($order['code'] ?? '');
        $title    = (string) ($order['package_title'] ?? '');
        $kind     = (string) ($order['kind'] ?? '');
        $created  = (int) ($order['created_at'] ?? 0);

        return [
            'id'             => (int) ($order['id'] ?? 0),
            'code'           => $code,
            'title'          => $title,
            'kind'           => $kind,
            'kind_label'     => PackageRepository::kindLabel($kind),
            'status'         => self::orderStatus((string) $order['status']),
            'volume_gb'      => round((float) ($order['volume_gb'] ?? 0) + (float) ($order['bonus_gb'] ?? 0), 2),
            'duration_days'  => (int) ($order['duration_days'] ?? 0),
            'price'          => $amounts['price'],
            'original'       => $amounts['original'],
            'discount'       => $amounts['discount'],
            'price_text'     => Str::formatToman($amounts['price']),
            'original_text'  => Str::formatToman($amounts['original']),
            'discount_text'  => Str::formatToman($amounts['discount']),
            'coupon'         => $coupon !== '' ? $coupon : null,
            'referred'       => $referred !== '' ? $referred : null,
            'panel_id'       => isset($order['panel_id']) && $order['panel_id'] !== null ? (int) $order['panel_id'] : null,
            'payment_method' => $method !== '' ? Invoice::paymentMethod($order) : null,
            'payment_ref'    => $ref !== '' ? $ref : null,
            'pay_url'        => self::safeUrl($order['payment_payload'] ?? null),
            'created_at'     => $created,
            'created_text'   => Str::date($created),
            'paid_at'        => isset($order['paid_at']) && $order['paid_at'] !== null ? (int) $order['paid_at'] : null,
            'paid_text'      => isset($order['paid_at']) && $order['paid_at'] !== null
                ? Str::date((int) $order['paid_at'])
                : null,
            'attempts'       => (int) ($order['attempts'] ?? 0),
            'error'          => $error !== '' ? Str::truncate($error, 240) : null,
            'has_receipt'    => trim((string) ($order['receipt_file_id'] ?? '')) !== '',
            'invoice_ready'  => Invoice::isPayable($order),
        ];
    }

    /**
     * جزئیات کامل سفارش + اقدام‌های مجاز.
     *
     * اقدام‌ها را **سرور** تعیین می‌کند نه کلاینت، تا کلاینت نتواند با دستکاری
     * خودش کاری را نمایش دهد که سرور اجازه‌اش را نمی‌دهد (یا برعکس، دکمه‌ای را
     * نبیند که سرور اجازه می‌دهد).
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $extra فیلدهای اضافه (مثل invoice_url)
     * @return array<string, mixed>
     */
    public static function orderDetail(array $order, array $extra = []): array
    {
        $status = (string) ($order['status'] ?? '');
        $method = (string) ($order['payment_method'] ?? '');

        $actions = [];

        if ($status === OrderRepository::STATUS_CREATED) {
            $actions[] = 'pay';
        }

        if ($status === OrderRepository::STATUS_AWAITING_PAYMENT) {
            $actions[] = 'check';

            // رسید فقط برای کارت‌به‌کارت دستی معنا دارد؛ برای بقیه، وضعیت
            // خودکار تأیید می‌شود و عکس بی‌معنی است.
            if ($method === 'card2card') {
                $actions[] = 'receipt';
            }
        }

        return array_merge(self::order($order), [
            'next_attempt_at' => isset($order['next_attempt_at']) && $order['next_attempt_at'] !== null
                ? (int) $order['next_attempt_at']
                : null,
            'panel_applied'   => (int) ($order['panel_applied'] ?? 0) === 1,
            'after_limit'     => isset($order['after_limit']) && $order['after_limit'] !== null
                ? (int) $order['after_limit']
                : null,
            'actions'         => $actions,
            'invoice_url'     => $extra['invoice_url'] ?? null,
            'invoice_text'    => self::invoiceText($order),
        ], $extra);
    }

    /**
     * متن HTML فاکتور، اگر سفارش حداقل چیزهای لازم را داشته باشد.
     *
     * `Invoice::telegramText()` به `code`/`package_title`/`kind` نیاز دارد و
     * روی ورودی ناقص warning می‌دهد. در API همیشه رکورد کامل داریم، ولی
     * این تابع در تست‌ها هم با آرایه‌های ناقص صدا زده می‌شود — و یک warning
     * یعنی خروجی JSON خراب (چون متن هشدار قبل از JSON چاپ می‌شود).
     *
     * @param array<string, mixed> $order
     */
    private static function invoiceText(array $order): string
    {
        if (!isset($order['code'], $order['package_title'], $order['kind'])) {
            return '';
        }

        return Invoice::telegramText($order);
    }

    /**
     * یک تیکت پشتیبانی.
     *
     * @param array<string, mixed> $ticket
     * @return array<string, mixed>
     */
    public static function ticket(array $ticket): array
    {
        return [
            'id'           => (int) $ticket['id'],
            'category'     => (string) $ticket['category'],
            'category_label' => TicketRepository::categoryLabel((string) $ticket['category']),
            'subject'      => (string) $ticket['subject'],
            'status'       => (string) $ticket['status'],
            'status_label' => TicketRepository::statusLabel($ticket),
            'open'         => (string) $ticket['status'] !== TicketRepository::STATUS_CLOSED,
            'created_at'   => (int) $ticket['created_at'],
            'created_text' => Str::date((int) $ticket['created_at']),
            'updated_at'   => (int) $ticket['updated_at'],
        ];
    }

    /**
     * پیام‌های یک تیکت برای نمایش گفت‌وگو.
     *
     * @param  array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public static function ticketMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $fromAdmin = (string) ($message['from_side'] ?? '') === 'admin';

            $out[] = [
                'id'     => (int) $message['id'],
                'side'   => $fromAdmin ? 'admin' : 'user',
                'author' => $fromAdmin ? '🛠 مدیریت' : '👤 شما',
                'body'   => (string) $message['body'],
                'at'     => (int) $message['created_at'],
                'text'   => Str::date((int) $message['created_at']),
            ];
        }

        return $out;
    }

    /**
     * یک تراکنش کیف پول.
     *
     * @param array<string, mixed> $txn
     * @return array<string, mixed>
     */
    public static function walletTxn(array $txn): array
    {
        $amount = (int) ($txn['amount'] ?? 0);

        $labels = [
            'manual'     => 'شارژ توسط مدیریت',
            'referral'   => 'پاداش معرفی',
            'wallet_pay' => 'پرداخت با کیف پول',
        ];

        $kind = (string) ($txn['kind'] ?? 'manual');
        $note = trim((string) ($txn['note'] ?? ''));

        return [
            'id'         => (int) $txn['id'],
            'amount'     => $amount,
            'text'       => ($amount >= 0 ? '+' : '−') . Str::formatToman(abs($amount)),
            'credit'     => $amount >= 0,
            'kind'       => $kind,
            'kind_label' => $labels[$kind] ?? $kind,
            'note'       => $note !== '' ? $note : null,
            'at'         => (int) $txn['created_at'],
            'date'       => Str::date((int) $txn['created_at']),
        ];
    }

    /**
     * یک رکورد پرداخت برای تاریخچه.
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public static function payment(array $payment): array
    {
        $status = (string) ($payment['status'] ?? '');

        return [
            'id'          => (int) $payment['id'],
            'order_id'    => (int) $payment['order_id'],
            'method'      => (string) ($payment['method'] ?? ''),
            'method_label' => Invoice::paymentMethod($payment),
            'amount'      => (int) ($payment['amount_toman'] ?? 0),
            'amount_text' => Str::formatToman((int) ($payment['amount_toman'] ?? 0)),
            'status'      => $status,
            'status_label' => match ($status) {
                'pending'   => '⏳ ثبت شده',
                'waiting'   => '📝 در انتظار تأیید',
                'confirmed' => '✅ تأیید شده',
                'failed'    => '❌ ناموفق',
                'expired'   => '⌛️ منقضی',
                default     => $status,
            },
            'reference'   => trim((string) ($payment['external_id'] ?? '')) !== ''
                ? (string) $payment['external_id']
                : null,
            'at'          => (int) ($payment['created_at'] ?? 0),
            'date'        => Str::date((int) ($payment['created_at'] ?? 0)),
        ];
    }

    /**
     * یک کانفیگ تست.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function testConfig(array $config): array
    {
        $expireAt = (int) ($config['expire_at'] ?? 0);
        $status   = (string) ($config['status'] ?? '');
        $autoAt   = (int) ($config['auto_delete_at'] ?? 0);
        $sub      = trim((string) ($config['sub_url'] ?? ''));

        return [
            'id'         => (int) $config['id'],
            'panel_id'   => (int) $config['panel_id'],
            'username'   => (string) $config['panel_username'],
            'status'     => $status,
            'active'     => $status === 'active' && $expireAt > time(),
            'data_limit' => (int) ($config['data_limit'] ?? 0),
            'limit_text' => Str::formatBytes((int) ($config['data_limit'] ?? 0)),
            'used'       => (int) ($config['used_traffic'] ?? 0),
            'used_text'  => Str::formatBytes((int) ($config['used_traffic'] ?? 0)),
            'expire_at'  => $expireAt,
            'expire_text' => Str::date($expireAt),
            'days_left'  => max(0, (int) ceil(($expireAt - time()) / 86400)),
            'sub_url'    => $sub !== '' ? $sub : null,
            'auto_delete' => (int) ($config['is_auto_delete'] ?? 0) === 1,
            'auto_delete_at' => $autoAt > 0 ? $autoAt : null,
            'auto_delete_text' => $autoAt > 0 ? Str::date($autoAt) : null,
            'issued_at'  => (int) $config['issued_at'],
        ];
    }

    /**
     * یک درگاه پرداخت آمادهٔ نمایش.
     *
     * @return array{name:string, title:string, review:bool}
     */
    public static function gateway(string $name, string $title, bool $requiresReview): array
    {
        return [
            'name'   => $name,
            'title'  => $title,
            'review' => $requiresReview,
        ];
    }

    /**
     * خواندن یک ستونِ «قابل تهی» به‌صورت `?int`.
     *
     * چرا کمکی؟ ستون‌هایی مثل `access_expire_at`، `panel_id` و `paid_at`
     * مقدار `NULL` معنادار دارند (نامحدود / ندارد / پرداخت‌نشده). نوشتن
     * `$row['x'] === null ? null : (int) $row['x']` در ده‌ها جا یعنی یک
     * بار `$row['x']` وقتی کلید وجود ندارد warning می‌دهد — و چون متن هشدار
     * قبل از JSON چاپ می‌شود، کل پاسخ API خراب می‌شود.
     *
     * @param array<string, mixed> $row
     */
    private static function nullableInt(array $row, string $key): ?int
    {
        // `isset` خودش NULL و کلیدِ غایب را هر دو false می‌گیرد، پس شرط `=== null`
        // بعدش اضافه است و فقط مقادیر خالی/صفرِ غیرمنتظره را می‌گیرد.
        if (!isset($row[$key]) || $row[$key] === '') {
            return null;
        }

        return (int) $row[$key];
    }

    /**
     * فقط URL امن (http/https) برای باز کردن در کلاینت.
     *
     * `payment_payload` از پاسخ درگاه می‌آید؛ اگر روزی درگاهی مقدار عجیبی
     * برگرداند، نباید به کلاینت به‌عنوان لینک قابل کلیک نشت کند (مثلاً
     * `javascript:` که `openLink` می‌پذیرد).
     *
     * @param mixed $url
     */
    public static function safeUrl($url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        return $url;
    }
}
