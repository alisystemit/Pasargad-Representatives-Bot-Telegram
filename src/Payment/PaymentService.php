<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * مدیریت پرداخت: انتخاب درگاه، شروع پرداخت، تأیید (دستی یا خودکار) و
 * راه‌اندازی اعلام خودکار بسته پس از تأیید.
 */
final class PaymentService
{
    /**
     * حداکثر اختلاف مجاز بین مبلغ سفارش و مبلغ اعلام‌شده در IPN (نسبت).
     *
     * صفر نیست چون کارمزد درگاه و نوسان نرخ ارز بین لحظهٔ ثبت سفارش و لحظهٔ
     * پرداخت، مبلغ نهایی را کمی جابه‌جا می‌کنند. مقدار ۲٪ برای کاربران ایرانی
     * و ارزهای دیجیتال کافی است و در عین حال جلوی پرداخت‌های کاملاً متفاوت را
     * می‌گیرد.
     */
    public const IPN_AMOUNT_TOLERANCE = 0.02;

    private OrderRepository $orders;
    private Provisioner $provisioner;
    private Settings $settings;
    private FeatureFlags $flags;
    private UserRepository $users;
    private ?object $notifier = null;

    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct(
        ?OrderRepository $orders = null,
        ?Provisioner $provisioner = null,
        ?Settings $settings = null,
        ?FeatureFlags $flags = null,
        ?UserRepository $users = null
    ) {
        $this->orders     = $orders ?? new OrderRepository();
        $this->provisioner = $provisioner ?? new Provisioner();
        $this->settings   = $settings ?? new Settings();
        $this->flags      = $flags ?? new FeatureFlags($this->settings);
        $this->users      = $users ?? new UserRepository();

        $this->registerGateway(new CardToCardGateway());
        $this->registerGateway(new NowPaymentsGateway());
    }

    public function flags(): FeatureFlags
    {
        return $this->flags;
    }

    public function registerGateway(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->name()] = $gateway;
    }

    /**
     * @param  object $notifier سرویس اعلان (برای جلوگیری از وابستگی چرخشی)
     */
    public function setNotifier(object $notifier): void
    {
        $this->notifier = $notifier;
    }

    public function gateway(string $name): ?PaymentGateway
    {
        return $this->gateways[$name] ?? null;
    }

    /**
     * فهرست درگاه‌های آمادهٔ استفاده برای کاربر.
     *
     * درگاه باید هم در کانفیگ پیکربندی شده باشد (isEnabled)
     * و هم سوییچ آن در پنل روشن باشد.
     *
     * @return array<string, PaymentGateway>
     */
    public function activeGateways(): array
    {
        return array_filter(
            $this->gateways,
            fn (PaymentGateway $g): bool => $g->isEnabled() && $this->flags->isGatewayEnabled($g->name())
        );
    }

    /**
     * همهٔ درگاه‌ها به‌همراه وضعیت سوییچ — برای نمایش به سوپرادمین.
     *
     * @return array<string, array{gateway:PaymentGateway, configured:bool, enabled:bool}>
     */
    public function gatewayStates(): array
    {
        $result = [];

        foreach ($this->gateways as $name => $gateway) {
            $result[$name] = [
                'gateway'    => $gateway,
                'configured' => $gateway->isEnabled(),
                'enabled'    => $this->flags->isGatewayEnabled($name),
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // شروع پرداخت
    // ------------------------------------------------------------------

    /**
     * شروع پرداخت یک سفارش با درگاه انتخابی.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function startPayment(array $order, string $method, int $chatId): array
    {
        $gateway = $this->gateway($method);

        if ($gateway === null || !$gateway->isEnabled()) {
            return ['ok' => false, 'message' => 'روش پرداخت انتخابی در دسترس نیست.'];
        }

        $status = (string) ($order['status'] ?? '');

        // سفارشی که قبلاً پرداخت یا نهایی شده، دوباره پرداخت نمی‌شود.
        if (in_array($status, [
            OrderRepository::STATUS_PAID,
            OrderRepository::STATUS_APPLYING,
            OrderRepository::STATUS_APPLIED,
            OrderRepository::STATUS_REJECTED,
            OrderRepository::STATUS_REFUNDED,
        ], true)) {
            return [
                'ok'      => false,
                'message' => 'این سفارش قبلاً پرداخت شده یا نهایی شده است.',
                'already' => true,
            ];
        }

        // سوییچ پنل: حتی اگر در کانفیگ باشد، سوپرادمین می‌تواند آن را خاموش کند.
        if (!$this->flags->isGatewayEnabled($method)) {
            return ['ok' => false, 'message' => 'این روش پرداخت موقتاً غیرفعال شده است. لطفاً روش دیگری را انتخاب کنید.'];
        }

        if ((int) $order['price_toman'] < 1) {
            return ['ok' => false, 'message' => 'مبلغ سفارش نامعتبر است.'];
        }

        $result = $gateway->start($order, $chatId);

        if (!($result['ok'] ?? false)) {
            return $result;
        }

        // ثبت تلاش پرداخت در دیتابیس.
        // اگر برای همین سفارش و همین روش قبلاً پرداختی ثبت شده، همان را
        // به‌روزرسانی می‌کنیم تا خطای UNIQUE رخ ندهد.
        try {
            $this->orders->upsertPayment((int) $order['id'], [
                'method'        => $method,
                'amount_toman'  => (int) $order['price_toman'],
                'amount_usd'    => $result['amount_usd'] ?? null,
                'currency'      => $result['currency'] ?? 'IRR',
                'external_id'   => $result['reference'] ?? null,
                'status'        => ($result['requires_review'] ?? false) ? 'waiting' : 'pending',
                'raw_payload'   => isset($result['raw']) ? json_encode($result['raw'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Failed to record payment attempt', [
                'order_id' => (int) $order['id'],
                'method'   => $method,
                'error'    => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'ثبت تلاش پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.'];
        }

        // به‌روزرسانی سفارش
        $orderUpdate = [
            'status'         => OrderRepository::STATUS_AWAITING_PAYMENT,
            'payment_method' => $method,
            'payment_ref'    => $result['reference'] ?? null,
            'payment_payload' => $result['pay_url'] ?? null,
        ];
        $this->orders->update((int) $order['id'], $orderUpdate);

        // اطلاع‌رسانی به ادمین دربارهٔ سفارش جدید
        $this->notifyAdminNewOrder($order, $method);

        $result['order_id'] = (int) $order['id'];
        $result['method']   = $method;

        return $result;
    }

    // ------------------------------------------------------------------
    // تأیید دستی (کارت‌به‌کارت)
    // ------------------------------------------------------------------

    /**
     * ثبت رسید کارت‌به‌کارت توسط کاربر.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function submitReceipt(array $order, string $fileId, ?string $reference = null): array
    {
        $orderId = (int) $order['id'];

        // ------------------------------------------------------------------
        // محافظت امنیتی: رسید فقط برای سفارش کارت‌به‌کارت معتبر است.
        //
        // بدون این بررسی، کاربر می‌توانست روی سفارش ارز دیجیتالِ پرداخت‌نشده
        // هر عکسی (حتاً یک اسکرین‌شات بی‌ربط) بفرستد، ادمین دکمهٔ «تأیید» را
        // ببیند و بسته بدون هیچ پرداختی اعمال شود.
        // ------------------------------------------------------------------
        $status = (string) ($order['status'] ?? '');

        if ($status !== OrderRepository::STATUS_AWAITING_PAYMENT) {
            return [
                'ok'      => false,
                'message' => 'این سفارش در وضعیت «در انتظار پرداخت کارت‌به‌کارت» نیست.',
            ];
        }

        if ((string) ($order['payment_method'] ?? '') !== CardToCardGateway::NAME) {
            return [
                'ok'      => false,
                'message' => 'برای این سفارش نیازی به ارسال رسید نیست؛ پرداخت شما به‌صورت '
                    . 'خودکار تأیید می‌شود. اگر پرداخت انجام نشده، از دکمهٔ «🔄 بررسی وضعیت» استفاده کنید.',
            ];
        }

        if ($this->orders->isTerminal($status)) {
            return [
                'ok'      => false,
                'message' => 'این سفارش نهایی شده و امکان ثبت رسید ندارد.',
            ];
        }

        $this->orders->update($orderId, [
            'receipt_file_id'  => $fileId,
            'receipt_photo_id' => $reference,
            'updated_at'       => time(),
        ]);

        $this->notifyAdminReceipt($order, $fileId, $reference);

        return [
            'ok'      => true,
            'message' => 'رسید شما ثبت شد و در حال بررسی توسط سوپرادمین است. ✅',
        ];
    }

    /**
     * تأیید یا رد دستی پرداخت کارت‌به‌کارت توسط سوپرادمین.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function reviewOrder(array $order, bool $approved, int $adminId, string $note = ''): array
    {
        $orderId = (int) $order['id'];

        if (!$approved) {
            // ------------------------------------------------------------------
            // محافظت: سفارشی که پرداختش تأیید و بسته‌اش اجرا شده نباید «رد» شود.
            //
            // دکمهٔ «❌ رد پرداخت» در پیام تلگرام ادمین باقی می‌ماند. اگر ادمین
            // بعد از تأیید، روی همان دکمهٔ قدیمی کلیک کند، بدون این بررسی وضعیت
            // به rejected می‌رفت و کاربری که بسته را تحویل گرفته بود پیام
            // «پرداخت رد شد» می‌دید و آمارش هم صفر می‌شد.
            // ------------------------------------------------------------------
            $current = $this->orders->find($orderId);
            $status  = (string) ($current['status'] ?? '');

            if ($status === OrderRepository::STATUS_APPLIED) {
                return [
                    'ok'      => false,
                    'message' => 'این سفارش قبلاً تأیید و بستهٔ آن روی پنل اعمال شده است؛ قابل رد کردن نیست.',
                    'applied' => true,
                ];
            }

            if ($status === OrderRepository::STATUS_APPLYING) {
                return [
                    'ok'      => false,
                    'message' => 'این سفارش هم‌اکنون در حال اجراست؛ لطفاً چند لحظه بعد بررسی کنید.',
                    'applied' => false,
                ];
            }

            if ($status !== OrderRepository::STATUS_AWAITING_PAYMENT) {
                return [
                    'ok'      => false,
                    'message' => 'این سفارش در وضعیت «' . $status . '» است و قابل بررسی نیست.',
                    'applied' => false,
                ];
            }

            // «رد پرداخت» یک وضعیت پایانی است: نباید هرگز دوباره اجرا شود،
            // وگرنه کرون در اجرای بعدی بسته را بدون پرداخت به کاربر می‌دهد.
            $this->orders->markTerminal(
                $orderId,
                OrderRepository::STATUS_REJECTED,
                'payment_rejected',
                $note !== '' ? $note : 'پرداخت توسط سوپرادمین رد شد.'
            );

            $this->orders->update($orderId, [
                'review_admin_id' => $adminId,
            ]);

            $payment = $this->orders->lastPayment($orderId);
            if ($payment !== null) {
                $this->orders->updatePayment((int) $payment['id'], ['status' => 'failed']);
            }

            return ['ok' => true, 'message' => 'سفارش رد شد.', 'applied' => false];
        }

        // تأیید: پرداخت paid شود و بسته خودکار اعمال گردد.
        if (!$this->orders->markPaid($orderId, CardToCardGateway::NAME, (string) ($order['code'] ?? ''))) {
            $current = $this->orders->find($orderId);
            $status  = (string) ($current['status'] ?? '');

            if (in_array($status, [OrderRepository::STATUS_PAID, OrderRepository::STATUS_APPLIED], true)) {
                return ['ok' => true, 'message' => 'این سفارش قبلاً تأیید شده بود.', 'applied' => false];
            }

            // سفارش‌های نهایی (ردشده/لغوشده/بازگشت وجه) نباید دوباره تأیید شوند؛
            // دکمهٔ تأییدِ قدیمی در چت همچنان قابل کلیک است.
            if ($this->orders->isTerminal($status)) {
                $labels = [
                    OrderRepository::STATUS_REJECTED => 'این پرداخت قبلاً توسط سوپرادمین رد شده است.',
                    OrderRepository::STATUS_CANCELLED => 'این سفارش لغو شده است.',
                    OrderRepository::STATUS_REFUNDED  => 'وجه این سفارش بازگردانده شده است.',
                ];

                return [
                    'ok'      => false,
                    'message' => $labels[$status] ?? 'این سفارش نهایی شده و قابل تأیید نیست.',
                    'applied' => false,
                ];
            }

            return ['ok' => false, 'message' => 'تغییر وضعیت سفارش ممکن نشد.', 'applied' => false];
        }

        $this->orders->update($orderId, [
            'review_admin_id' => $adminId,
            'review_note'     => Str::truncate($note, 200),
        ]);

        $payment = $this->orders->lastPayment($orderId);
        if ($payment !== null) {
            $this->orders->updatePayment((int) $payment['id'], ['status' => 'confirmed', 'confirmed_at' => time()]);
        }

        $applied = $this->applyAfterPayment($orderId);

        return [
            'ok'      => true,
            'message' => $applied['ok']
                ? 'پرداخت تأیید و بسته با موفقیت اعمال شد. ✅'
                : 'پرداخت تأیید شد، اما اجرای بسته به تعویق افتاد: ' . $applied['message'],
            'applied' => $applied['ok'],
        ];
    }

    // ------------------------------------------------------------------
    // تأیید خودکار (IPN)
    // ------------------------------------------------------------------

    /**
     * پردازش IPN درگاه ارز دیجیتال.
     *
     * @param  array<string, mixed> $payload
     * @param  array<string, string> $headers
     * @param  string                $rawBody بدنهٔ خام و دقیقاً همان بایت‌هایی که
     *                              NOWPayments امضا کرده است. حیاتی است: اگر به‌جای
     *                              آن از json_encode روی آرایهٔ decode‌شده استفاده شود،
     *                              escapes یونیکد و اسلش فرق می‌کند و امضا هرگز
     *                              مطابقت نمی‌دهد — یعنی **هیچ پرداختی** تأیید نمی‌شود.
     * @return array<string, mixed>
     */
    public function handleIpn(array $payload, array $headers, string $rawBody = ''): array
    {
        $gateway = $this->gateway(NowPaymentsGateway::NAME);
        if (!$gateway instanceof NowPaymentsGateway) {
            return ['ok' => false, 'message' => 'درگاه ارز دیجیتال فعال نیست.'];
        }

        // اگر بدنهٔ خام در دسترس نیست، بازسازی آن قابل اتکا نیست (escapeها فرق
        // می‌کنند) پس باید رد شود نه اینکه با امضای نادرست مقایسه شود.
        $signedBody = $rawBody !== '' ? $rawBody : null;

        if ($signedBody === null || !$gateway->verifyIpnSignature($headers, $signedBody)) {
            Logger::warning('IPN signature mismatch', ['headers' => array_keys($headers)]);
            return ['ok' => false, 'message' => 'امضای IPN معتبر نیست.'];
        }

        $paymentId = (string) ($payload['payment_id'] ?? '');
        $orderRef  = (string) ($payload['order_id'] ?? '');

        if ($paymentId === '') {
            return ['ok' => false, 'message' => 'شناسهٔ پرداخت در اعلان وجود ندارد.'];
        }

        // ------------------------------------------------------------------
        // پیدا کردن سفارش فقط از طریق رکورد پرداخت معتبر.
        //
        // نباید از order_id داخل payload استفاده کرد: این رشته کنترل‌نشده است و
        // اگر رکورد پرداخت پیدا نشود (مثلاً کاربر پرداخت را دوباره آغاز کرده و
        // external_id بازنویسی شده) باعث می‌شد وضعیت یک پرداخت به سفارشی دیگر
        // نسبت داده شود.
        // ------------------------------------------------------------------
        $payment = $this->orders->findPaymentByExternal($paymentId);

        if ($payment === null) {
            Logger::warning('IPN has no matching payment record', [
                'payment_id' => $paymentId,
                'order_id'   => $orderRef,
            ]);

            return ['ok' => false, 'message' => 'پرداخت مرتبط یافت نشد.'];
        }

        $order = $this->orders->find((int) $payment['order_id']);

        if ($order === null) {
            Logger::warning('IPN points to a missing order', ['payment_id' => $paymentId]);
            return ['ok' => false, 'message' => 'سفارش مرتبط یافت نشد.'];
        }

        // ------------------------------------------------------------------
        // بررسی وضعیت از خودِ payload.
        //
        // عمداً از checkStatus() استفاده نمی‌کنیم: آن یک درخواست شبکهٔ
        // همگام است و اگر API درگاه در همان لحظه خطا بدهد (۵xx یا تایم‌اوت)،
        // ما ۲۰۰ به NOWPayments برمی‌گردانیم، آن‌ها اعلان را تحویل‌شده می‌بینند
        // و دیگر تکرار نمی‌کنند — در حالی که سفارش برای همیشه پرداخت‌نشده می‌ماند.
        // ------------------------------------------------------------------
        $remoteStatus = strtolower(trim((string) ($payload['payment_status'] ?? '')));

        // توجه: «partially_paid» در NOWPayments یعنی کمتر از مبلغ فاکتور
        // واریز شده — یعنی پرداخت‌نشده از نظر ما. نباید بسته را فعال کند.
        $paid = in_array($remoteStatus, ['finished', 'confirmed'], true);

        $this->orders->updatePayment((int) $payment['id'], [
            'status'      => $remoteStatus !== '' ? $remoteStatus : 'unknown',
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        if (!$paid) {
            Logger::info('IPN received but payment not settled', [
                'payment_id'     => $paymentId,
                'status'         => $remoteStatus,
                'underpaid'      => $remoteStatus === 'partially_paid',
                'expected'       => (int) $order['price_toman'],
                'actually_paid'  => $payload['pay_amount'] ?? null,
            ]);

            $message = 'وضعیت پرداخت: ' . ($remoteStatus !== '' ? $remoteStatus : 'نامشخص');

            if ($remoteStatus === 'partially_paid') {
                $message = 'پرداخت کمتر از مبلغ فاکتور بود. لطفاً باقی‌مانده را واریز کنید.';
            }

            return ['ok' => true, 'paid' => false, 'message' => $message];
        }

        // ------------------------------------------------------------------
        // بررسی مبلغ: بدون این، یک پرداخت ناقص یا اشتباه می‌تواند بستهٔ
        // ۵۰۰ هزار تومانی را فعال کند.
        // ------------------------------------------------------------------
        if (!$this->ipnAmountMatchesOrder($order, $payload, $payment)) {
            Logger::error('IPN amount mismatch — payment NOT credited', [
                'order_id'   => (int) $order['id'],
                'payment_id' => $paymentId,
                'expected'   => (int) $order['price_toman'],
                'got'        => $payload['pay_amount'] ?? $payload['price_amount'] ?? null,
                'currency'   => $payload['pay_currency'] ?? $payload['price_currency'] ?? null,
            ]);

            return ['ok' => false, 'message' => 'مبلغ پرداخت با مبلغ سفارش مطابقت ندارد.'];
        }

        // markPaid خودش compare-and-swap است، پس تکراری بودن IPN بی‌خطر است.
        if (!$this->orders->markPaid((int) $order['id'], NowPaymentsGateway::NAME, $paymentId)) {
            return ['ok' => true, 'message' => 'پرداخت قبلاً ثبت شده بود.'];
        }

        $applied = $this->applyAfterPayment((int) $order['id']);

        return [
            'ok'      => true,
            'message' => $applied['ok'] ? 'پرداخت تأیید و بسته اعمال شد.' : 'پرداخت تأیید شد. ' . $applied['message'],
            'applied' => $applied['ok'],
        ];
    }

    /**
     * آیا مبلغ اعلام‌شده در IPN با مبلغ سفارش می‌خواند؟
     *
     * مقایسه با نرخ تبدیل تنظیمات انجام می‌شود و یک درصد خطای مجاز دارد، چون
     * کارمزد درگاه و نوسان نرخ بین زمان ثبت سفارش و پرداخت، مبلغ را کمی جابه‌جا
     * می‌کنند. بدون این تلورانس، همهٔ پرداخت‌ها رد می‌شدند.
     *
     * اگر هیچ مبلغی در payload نباشد، true برگردانده می‌شود تا نسخه‌های قدیمی‌تر
     * درگاه که مبلغ را نمی‌فرستند همچنان کار کنند (امضا همچنان معتبر است).
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $payload
     */
    private function ipnAmountMatchesOrder(array $order, array $payload, ?array $paymentRow = null): bool
    {
        $expectedToman = (int) $order['price_toman'];

        if ($expectedToman <= 0) {
            return true;
        }

        // ------------------------------------------------------------------
        // نکتهٔ حیاتی: باید از price_amount استفاده شود، نه pay_amount.
        //
        // درگاه دو مبلغ متفاوت می‌فرستد:
        //   price_amount / price_currency → مبلغ فاکتور به دلار (همان که ما
        //                                    درخواست دادیم، مثلاً 5.00 USD)
        //   pay_amount   / pay_currency   → مقدار واقعی پرداخت‌شده به ارز
        //                                    دیجیتال (مثلاً 0.00012 BTC)
        //
        // مقایسهٔ pay_amount با قیمت تومانی یعنی 0.00012 × 100000 = 12 تومان
        // در برابر 500000 تومان → همهٔ پرداخت‌های واقعی رد می‌شدند.
        // ------------------------------------------------------------------
        $invoiceAmount = $payload['price_amount'] ?? null;

        if ($invoiceAmount === null || !is_numeric($invoiceAmount)) {
            Logger::warning('IPN has no price_amount field, relying on signature only', [
                'order_id'   => (int) $order['id'],
                'payment_id' => $payload['payment_id'] ?? null,
            ]);

            return true;
        }

        $currency = strtoupper(trim((string) ($payload['price_currency'] ?? 'USD')));
        $paid     = (float) $invoiceAmount;

        if ($currency === 'IRR' || $currency === 'IRT' || $currency === 'TOMAN') {
            $paidToman = (int) round($paid);
        } else {
            // نرخ را از همان چیزی می‌گیریم که فاکتور با آن ساخته شد، نه از
            // کانفیگ فعلی — چون ممکن است نرخ بین ثبت سفارش و پرداخت عوض شده
            // باشد و در آن حالت مقایسهٔ ناعادلانه رد می‌شد.
            $rate = (float) ($paymentRow['amount_usd'] ?? 0) > 0.0
                ? (float) $paymentRow['amount_usd']
                : $paid;

            $paidToman = $rate > 0.0
                ? (int) round($expectedToman * ($paid / $rate))
                : 0;
        }

        $tolerance = (int) ceil($expectedToman * self::IPN_AMOUNT_TOLERANCE);

        // نسبت پرداخت‌شده به مبلغ فاکتور — عدد خواناتر برای لاگ
        $billedUsd = (float) ($paymentRow['amount_usd'] ?? 0.0);

        Logger::info('IPN amount compared', [
            'order_id'      => (int) $order['id'],
            'expected_toman' => $expectedToman,
            'billed_usd'    => $billedUsd,
            'ipn_price'     => $paid,
            'currency'      => $currency,
            'actually_paid' => $payload['pay_amount'] ?? null,
            'pay_currency'  => $payload['pay_currency'] ?? null,
        ]);

        return abs($paidToman - $expectedToman) <= $tolerance;
    }

    /**
     * اعمال خودکار بسته پس از تأیید پرداخت (اگر فعال باشد).
     *
     * @return array{ok:bool, message:string, details?:array<string, mixed>}
     */
    public function applyAfterPayment(int $orderId): array
    {
        if (!$this->provisioner->autoApplyEnabled()) {
            return ['ok' => true, 'message' => 'اجرای خودکار غیرفعال است؛ بسته توسط ادمین اعمال می‌شود.'];
        }

        $order = $this->orders->find($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }

        $result = $this->provisioner->provision($order);

        if ($result['ok']) {
            $this->notifyUserApplied($order, $result);
        } else {
            $this->notifyAdminProvisionFailed($order, $result['message']);
        }

        return $result;
    }

    /**
     * بررسی دستی وضعیت پرداخت (دکمهٔ «بررسی مجدد»).
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function checkAndMaybeApply(array $order): array
    {
        $payment = $this->orders->lastPayment((int) $order['id']);
        if ($payment === null) {
            return ['ok' => false, 'paid' => false, 'message' => 'پرداختی برای این سفارش ثبت نشده است.'];
        }

        $gateway = $this->gateway((string) $payment['method']);
        if ($gateway === null) {
            return ['ok' => false, 'paid' => false, 'message' => 'روش پرداخت ناشناخته است.'];
        }

        $status = $gateway->checkStatus($payment);

        $this->orders->updatePayment((int) $payment['id'], ['status' => $status['status']]);

        if (!$status['paid']) {
            return ['ok' => true, 'paid' => false, 'message' => $status['message']];
        }

        if ($this->orders->markPaid((int) $order['id'], (string) $payment['method'], (string) ($status['reference'] ?? $payment['external_id'] ?? ''))) {
            $applied = $this->applyAfterPayment((int) $order['id']);

            return [
                'ok'      => true,
                'paid'    => true,
                'message' => 'پرداخت تأیید شد. ' . ($applied['ok'] ? 'بسته اعمال شد. ✅' : $applied['message']),
            ];
        }

        return ['ok' => true, 'paid' => true, 'message' => 'پرداخت قبلاً تأیید شده است.'];
    }

    // ------------------------------------------------------------------
    // اعلان‌ها
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $order
     */
    private function notifyAdminNewOrder(array $order, string $method): void
    {
        if ($this->notifier === null) {
            return;
        }

        $message = "🛒 <b>سفارش جدید</b>\n\n"
            . 'کد سفارش: <code>' . Str::escape((string) $order['code']) . "</code>\n"
            . 'بسته: ' . Str::escape((string) $order['package_title']) . "\n"
            . 'مبلغ: <b>' . Str::formatToman((int) $order['price_toman']) . "</b>\n"
            . 'روش پرداخت: ' . Str::escape($method);

        $this->notifier->notifyAdmins($message, [
            'text' => '🧾 سفارش‌ها',
            'data' => \Pasargad\Telegram\BotApi::encodeData('admin.orders', ['status' => 'awaiting_payment']),
        ]);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function notifyAdminReceipt(array $order, string $fileId, ?string $reference): void
    {
        if ($this->notifier === null) {
            return;
        }

        $message = "🧾 <b>رسید جدید برای تأیید</b>\n\n"
            . 'کد سفارش: <code>' . Str::escape((string) $order['code']) . "</code>\n"
            . 'مبلغ: <b>' . Str::formatToman((int) $order['price_toman']) . "</b>";

        if ($reference !== null && $reference !== '') {
            $message .= "\nشمارهٔ پیگیری: <code>" . Str::escape($reference) . '</code>';
        }

        $this->notifier->notifyAdminsWithPhoto($fileId, $message, [
            'text' => '✅ تأیید رسید',
            'data' => \Pasargad\Telegram\BotApi::encodeData('admin.review', ['id' => (int) $order['id'], 'act' => 'approve']),
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $result
     */
    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $result
     */
    private function notifyUserApplied(array $order, array $result): void
    {
        if ($this->notifier === null) {
            return;
        }

        // نکتهٔ مهم: order['user_id'] شناسهٔ داخلی دیتابیس است، نه آیدی تلگرام.
        // باید از طریق UserRepository به telegram_id رسید.
        $telegramId = $this->telegramIdOf((int) $order['user_id']);

        if ($telegramId === null) {
            Logger::warning('Cannot notify user about applied package', [
                'user_id' => $order['user_id'] ?? null,
            ]);

            return;
        }

        $details = (array) ($result['details'] ?? []);
        $message = "✅ <b>بستهٔ شما با موفقیت اجرا شد</b>\n\n"
            . 'کد سفارش: <code>' . Str::escape((string) $order['code']) . "</code>\n"
            . 'بسته: ' . Str::escape((string) $order['package_title']) . "\n";

        if (isset($details['after_limit'])) {
            $message .= 'حجم جدید حساب شما: <b>' . Str::formatBytes((int) $details['after_limit']) . "</b>\n";
        } elseif (isset($details['credit_total'])) {
            $message .= 'اعتبار ساخت کاربر شما: <b>' . Str::formatBytes((int) $details['credit_total']) . "</b>\n";
        }

        $this->notifier->notifyUser($telegramId, $message);
    }

    /**
     * تبدیل شناسهٔ داخلی کاربر به آیدی تلگرام.
     *
     * لازم است چون orders.user_id کلید خارجی جدول users است، نه chat id تلگرام.
     * اگر این تبدیل انجام نشود، اعلان‌ها به چت اشتباهی (با آیدی کوچک) ارسال می‌شود.
     */
    private function telegramIdOf(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $row = $this->users->findById($userId);

        if ($row === null) {
            return null;
        }

        $telegramId = (int) ($row['telegram_id'] ?? 0);

        return $telegramId > 0 ? $telegramId : null;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function notifyAdminProvisionFailed(array $order, string $message): void
    {
        if ($this->notifier === null) {
            return;
        }

        $text = "⚠️ <b>اجرای خودکار بسته ناموفق بود</b>\n\n"
            . 'کد سفارش: <code>' . Str::escape((string) $order['code']) . "</code>\n"
            . 'خطا: ' . Str::escape($message);

        $this->notifier->notifyAdmins($text, [
            'text' => '🔁 تلاش دوباره',
            'data' => \Pasargad\Telegram\BotApi::encodeData('admin.retry', ['id' => (int) $order['id']]),
        ]);
    }
}