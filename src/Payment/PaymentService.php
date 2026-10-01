<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * مدیریت پرداخت: انتخاب درگاه، شروع پرداخت، تأیید (دستی یا خودکار) و
 * راه‌اندازی اعلام خودکار بسته پس از تأیید.
 */
final class PaymentService
{
    private OrderRepository $orders;
    private Provisioner $provisioner;
    private Settings $settings;
    private FeatureFlags $flags;
    private ?object $notifier = null;

    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct(
        ?OrderRepository $orders = null,
        ?Provisioner $provisioner = null,
        ?Settings $settings = null,
        ?FeatureFlags $flags = null
    ) {
        $this->orders     = $orders ?? new OrderRepository();
        $this->provisioner = $provisioner ?? new Provisioner();
        $this->settings   = $settings ?? new Settings();
        $this->flags      = $flags ?? new FeatureFlags($this->settings);

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

        // ثبت تلاش پرداخت در دیتابیس
        $this->orders->createPayment((int) $order['id'], [
            'method'        => $method,
            'amount_toman'  => (int) $order['price_toman'],
            'amount_usd'    => $result['amount_usd'] ?? null,
            'currency'      => $result['currency'] ?? 'IRR',
            'external_id'   => $result['reference'] ?? null,
            'status'        => ($result['requires_review'] ?? false) ? 'waiting' : 'pending',
            'raw_payload'   => isset($result['raw']) ? json_encode($result['raw'], JSON_UNESCAPED_UNICODE) : null,
        ]);

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
        $this->orders->update((int) $order['id'], [
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
            $this->orders->update($orderId, [
                'status'        => OrderRepository::STATUS_FAILED,
                'review_admin_id' => $adminId,
                'review_note'   => Str::truncate($note, 200),
                'error'         => 'پرداخت توسط سوپرادمین رد شد.',
                'next_attempt_at' => null,
                'attempts'      => 99,
            ]);

            $payment = $this->orders->lastPayment($orderId);
            if ($payment !== null) {
                $this->orders->updatePayment((int) $payment['id'], ['status' => 'failed']);
            }

            return ['ok' => true, 'message' => 'سفارش رد شد.', 'applied' => false];
        }

        // تأیید: پرداخت paid شود و بسته خودکار اعمال گردد.
        if (!$this->orders->markPaid($orderId, 'card2card', (string) ($order['code'] ?? ''))) {
            $current = $this->orders->find($orderId);
            if (in_array((string) ($current['status'] ?? ''), [OrderRepository::STATUS_PAID, OrderRepository::STATUS_APPLIED], true)) {
                return ['ok' => true, 'message' => 'این سفارش قبلاً تأیید شده بود.', 'applied' => false];
            }
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
     * @return array<string, mixed>
     */
    public function handleIpn(array $payload, array $headers): array
    {
        $gateway = $this->gateway(NowPaymentsGateway::NAME);
        if (!$gateway instanceof NowPaymentsGateway) {
            return ['ok' => false, 'message' => 'درگاه ارز دیجیتال فعال نیست.'];
        }

        if (!$gateway->verifyIpnSignature($headers, (string) json_encode($payload))) {
            Logger::warning('IPN signature mismatch', ['headers' => array_keys($headers)]);
            return ['ok' => false, 'message' => 'امضای IPN معتبر نیست.'];
        }

        $paymentId = (string) ($payload['payment_id'] ?? '');
        $orderRef  = (string) ($payload['order_id'] ?? '');

        $payment = $paymentId !== '' ? $this->orders->findPaymentByExternal($paymentId) : null;
        $order   = null;

        if ($payment !== null) {
            $order = $this->orders->find((int) $payment['order_id']);
        } elseif ($orderRef !== '') {
            $order = $this->orders->findByCode($orderRef);
        }

        if ($order === null) {
            Logger::warning('IPN for unknown order', ['payment_id' => $paymentId, 'order_id' => $orderRef]);
            return ['ok' => false, 'message' => 'سفارش مرتبط یافت نشد.'];
        }

        $status = $gateway->checkStatus(['external_id' => $paymentId]);

        if ($payment !== null) {
            $this->orders->updatePayment((int) $payment['id'], [
                'status'       => $status['status'],
                'raw_payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        }

        if (!$status['paid']) {
            return ['ok' => true, 'message' => 'وضعیت پرداخت: ' . $status['message']];
        }

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
    private function notifyUserApplied(array $order, array $result): void
    {
        if ($this->notifier === null) {
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

        $this->notifier->notifyUser((int) $order['user_id'], $message);
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