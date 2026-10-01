<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Support\Config;
use Pasargad\Support\Http;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * درگاه ارز دیجیتال NOWPayments با تأیید خودکار (IPN + بررسی دوره‌ای).
 *
 * جریان: ساخت فاکتور → کاربر مبلغ را واریز می‌کند → IPN به nowpayments_ipn.php
 * می‌رسد و سفارش به‌صورت خودکار پرداخت‌شده علامت می‌خورد و بسته اعمال می‌گردد.
 */
final class NowPaymentsGateway implements PaymentGateway
{
    public const NAME = 'nowpayments';

    private const BASE_LIVE  = 'https://api.nowpayments.io/v1';
    private const BASE_SANDBOX = 'https://api-sandbox.nowpayments.io/v1';

    public function name(): string
    {
        return self::NAME;
    }

    public function title(): string
    {
        return '🪙 ارز دیجیتال';
    }

    public function isEnabled(): bool
    {
        return Config::str('nowpayments.api_key') !== '';
    }

    public function requiresReview(): bool
    {
        return false;
    }

    public function sandbox(): bool
    {
        return Config::bool('nowpayments.sandbox', false);
    }

    public function baseUrl(): string
    {
        return $this->sandbox() ? self::BASE_SANDBOX : self::BASE_LIVE;
    }

    /**
     * ساخت فاکتور پرداخت.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function start(array $order, int $chatId): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'درگاه ارز دیجیتال پیکربندی نشده است.'];
        }

        $tomanPerUsd = max(1.0, Config::float('store.toman_per_usd', 100000.0));
        $amountToman = max(0, (int) $order['price_toman']);

        // گِرد کردن به بالا تا کاربر هرگز کمتر از قیمت واقعی پرداخت نکند.
        // گِرد کردن به پایین یا round معمولی یعنی از دست دادن پول در هر سفارش.
        $amountUsd = ceil($amountToman / $tomanPerUsd * 100) / 100;

        // حداقل قابل پرداخت در NOWPayments یک دلار است. سفارش‌های خیلی کوچک
        // (کمتر از یک دلار) به همان یک دلار افزایش می‌یابند و مبلغ واقعی
        // پرداختی در پیام به کاربر اعلام می‌شود تا فاکتور مبهم نباشد.
        $minimumUsd   = (float) Config::str('nowpayments.min_amount_usd', '1');
        $minimumUsd   = $minimumUsd > 0 ? $minimumUsd : 1.0;
        $isBelowMinimum = $amountUsd < $minimumUsd;

        if ($isBelowMinimum) {
            $amountUsd = $minimumUsd;
        }

        $callbackBase = rtrim(Config::str('base_url'), '/');
        $payload      = [
            'price_amount'      => $amountUsd,
            'price_currency'    => 'usd',
            'pay_currency'      => Config::str('nowpayments.pay_currency', 'btc'),
            'order_id'          => (string) $order['code'],
            // توجه: این مقدار عمداً فقط ASCII است. دلیل: امضای HMAC که
            // NOWPayments روی بدنهٔ خام IPN می‌سازد با هر بایت فرق می‌کند و
            // متن یونیکد می‌تواند باعث رد شدن اعلان شود (اگرچه اکنون از
            // بدنهٔ خام استفاده می‌کنیم، باز هم طول را بی‌جهت زیاد می‌کند).
            'order_description' => 'Order ' . (string) $order['code'],
            'ipn_callback_url'  => $callbackBase . '/nowpayments_ipn.php',
            'success_callback_url' => 'https://t.me/' . ltrim((string) Config::str('bot_username', ''), '@'),
        ];

        $response = $this->call('post', '/payment', $payload);

        $paymentId = $response['payment_id'] ?? null;

        if ($response['error'] !== '' || $paymentId === null) {
            Logger::error('NowPayments create failed', ['error' => $response['error']]);

            return ['ok' => false, 'message' => 'ساخت فاکتور پرداخت ناموفق بود: ' . ($response['error'] ?: 'خطای نامشخص')];
        }

        // نکتهٔ حیاتی: pay_address یک آدرس کیف‌پول (مثلاً bc1q…) است، نه URL!
        // اگر همین به‌عنوان لینک دکمهٔ پرداخت استفاده شود، filter_var آن را
        // URL نمی‌شناسد و buildMarkup دکمه را حذف می‌کند → کاربر هیچ راهی
        // برای پرداخت نمی‌بیند ولی پیام «موفق» دریافت می‌کند.
        //
        // NOWPayments فیلد payment_url را مخصوصاً برای صفحهٔ پرداخت می‌فرستد.
        $payUrl = $response['payment_url'] ?? ($response['pay_url'] ?? '');

        if (!is_string($payUrl) || $payUrl === '') {
            // نسخه‌های قدیمی‌تر فقط pay_address می‌فرستند؛ آن را هم بررسی می‌کنیم.
            $payUrl = (string) ($response['pay_address'] ?? '');
        }

        $instructions = implode("\n", [
            '🪙 <b>پرداخت با ارز دیجیتال</b>',
            '',
            '📦 بسته: <b>' . Str::escape((string) $order['package_title']) . '</b>',
            '💰 مبلغ سفارش: <b>' . Str::formatToman($amountToman) . '</b>',
        ]);

        if ($isBelowMinimum) {
            $instructions .= "\n⚠️ حداقل پرداخت درگاه <b>" . Str::formatToman((int) ceil($minimumUsd * $tomanPerUsd))
                . '</b> است، بنابراین مبلغ پرداختی افزایش یافت.';
        } else {
            $instructions .= "\n💵 معادل: <b>" . Str::faNumber($amountUsd, 2) . ' دلار</b>';
        }

        $instructions .= implode("\n", [
            '',
            'پس از واریز، پرداخت به‌صورت <b>خودکار</b> تأیید و بسته روی پنل شما اعمال می‌شود. ✅',
            '⏱ وضعیت پرداخت را می‌توانید با دکمهٔ «🔄 بررسی وضعیت» هم بزنید.',
        ]);

        if ($payUrl === '' || !filter_var($payUrl, FILTER_VALIDATE_URL)) {
            Logger::error('NowPayments returned no usable payment URL', [
                'payment_id' => (string) $paymentId,
                'has_url'    => $payUrl !== '',
            ]);

            return [
                'ok'      => false,
                'message' => 'درگاه ارز دیجیتال آدرس پرداخت برنگرداند. لطفاً روش دیگری را انتخاب کنید.',
            ];
        }

        return [
            'ok'              => true,
            'message'         => $instructions,
            'instructions'    => $instructions,
            'pay_url'         => $payUrl,
            'reference'       => (string) $paymentId,
            'amount_usd'      => $amountUsd,
            'currency'        => 'USD',
            'requires_review' => false,
            'raw'             => $response['data'] ?? [],
        ];
    }

    /**
     * بررسی وضعیت فاکتور از API (پشتیبان IPN).
     *
     * @param  array<string, mixed> $payment
     * @return array{status:string, paid:bool, message:string, reference?:string, raw?:array<string, mixed>}
     */
    public function checkStatus(array $payment): array
    {
        $paymentId = (string) ($payment['external_id'] ?? '');
        if ($paymentId === '') {
            return ['status' => 'unknown', 'paid' => false, 'message' => 'شناسهٔ فاکتور ثبت نشده است.'];
        }

        $response = $this->call('get', '/payment/' . rawurlencode($paymentId));

        if ($response['error'] !== '') {
            return [
                'status'  => (string) ($payment['status'] ?? 'pending'),
                'paid'    => false,
                'message' => 'بررسی وضعیت ممکن نشد؛ کمی بعد دوباره تلاش کنید.',
            ];
        }

        $data     = is_array($response['data']) ? $response['data'] : [];
        $status   = (string) ($data['payment_status'] ?? 'unknown');
        $map      = $this->mapStatus($status);

        return [
            'status'    => $map,
            'paid'      => $map === 'confirmed',
            'message'   => $this->statusMessage($status),
            'reference' => $paymentId,
            'raw'       => $data,
        ];
    }

    /**
     * تأیید امضای IPN (HMAC-SHA512 با ipn_secret).
     *
     * @param array<string, string> $headers هدرهای درخواست با کلید lowercase
     */
    public function verifyIpnSignature(array $headers, string $body): bool
    {
        $secret = Config::str('nowpayments.ipn_secret', '');
        if ($secret === '') {
            return false;
        }

        $signature = strtolower($headers['x-signature'] ?? '');
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $body, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * تبدیل وضعیت NOWPayments به وضعیت داخلی.
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'finished', 'confirmed' => 'confirmed',
            'failed', 'expired', 'refunded' => 'failed',
            'waiting', 'pending', 'partially_paid' => 'waiting',
            default => 'unknown',
        };
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'finished', 'confirmed'  => 'پرداخت با موفقیت تأیید شد.',
            'partially_paid'         => 'پرداخت ناقص است؛ مبلغ باقی‌مانده را واریز کنید.',
            'waiting', 'pending'     => 'پرداخت در انتظار تأیید شبکه است.',
            'failed'                 => 'پرداخت ناموفق بود.',
            'expired'                => 'مهلت فاکتور به پایان رسید.',
            'refunded'               => 'پرداخت برگشت خورد.',
            default                  => 'وضعیت پرداخت نامشخص است.',
        };
    }

    /**
     * فراخوانی API درگاه.
     *
     * @param  array<string, mixed> $payload
     * @return array{error:string, data:array<string, mixed>|null}
     */
    private function call(string $method, string $path, array $payload = []): array
    {
        $url    = $this->baseUrl() . $path;
        $apiKey = Config::str('nowpayments.api_key');

        $result = Http::json(strtoupper($method), $url, $method === 'post' ? $payload : null, [
            'headers' => [
                'accept: application/json',
                'x-api-key: ' . $apiKey,
            ],
            'timeout' => 25,
        ]);

        if ($result['error'] !== '' || $result['status'] >= 400) {
            $message = $result['data']['message'] ?? $result['error'] ?? ('HTTP ' . $result['status']);
            Logger::warning('NowPayments API error', ['path' => $path, 'message' => $message]);

            return ['error' => is_string($message) ? $message : 'خطای نامشخص درگاه', 'data' => null];
        }

        return ['error' => '', 'data' => is_array($result['data']) ? $result['data'] : []];
    }
}