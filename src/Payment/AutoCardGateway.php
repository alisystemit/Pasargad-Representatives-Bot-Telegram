<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Store\OrderRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * ⚡️💳 کارت‌به‌کارت خودکار با فاکتور یکتا.
 *
 * تفاوت با کارت‌به‌کارت دستی (`card2card`) که سر جایش مانده:
 *   • برای هر سفارش یک «مبلغ یکتای قابل پرداخت» ساخته می‌شود: مبلغ بسته به‌علاوهٔ
 *     چند رقم آخر رندوم (مثلاً ۵۰۰٬۰۰۰ → ۵۰۰٬۳۴۷ تومان). چون مبلغ یکتاست،
 *     واریز دقیقاً همین مبلغ = همین سفارش.
 *   • کاربر رسید نمی‌فرستد؛ با دکمهٔ «🔄 بررسی وضعیت» (یا کرون) سرویس استعلام
 *     کارت صدا زده می‌شود و اگر تراکنشی با همان مبلغ در بازهٔ فاکتور پیدا شود،
 *     سفارش خودکار تأیید و بسته اجرا می‌شود. ✅
 *
 * پیش‌نیاز در `config.php` (بخش `autocard`): آدرس و کلید سرویس استعلام +
 * شماره کارت. تا وقتی `api_url` خالی است درگاه «پیکربندی‌نشده» می‌ماند و در
 * فهرست پرداخت به کاربر نشان داده نمی‌شود.
 */
final class AutoCardGateway implements PaymentGateway
{
    public const NAME = 'autocard';

    /** بازهٔ تلورانس زمانی تراکنش نسبت به ساخت فاکتور (ثانیه) */
    private const TIME_MARGIN = 300;

    public function name(): string
    {
        return self::NAME;
    }

    public function title(): string
    {
        return '⚡️💳 کارت‌به‌کارت خودکار';
    }

    /**
     * شماره کارت مخصوص پرداخت خودکار (یا همان کارت فروشگاه).
     */
    public static function cardNumber(): string
    {
        $own = trim(Config::str('autocard.card_number', ''));

        return $own !== '' ? $own : trim(Config::str('store.card_number', ''));
    }

    /**
     * آیا درگاه برای نمایش به کاربر آماده است؟
     *
     * عمداً هم کارت و هم API لازم است: بدون استعلام، «خودکار» معنا ندارد و
     * نباید در فهرست پرداخت دیده شود.
     */
    public static function isConfigured(): bool
    {
        return self::cardNumber() !== '' && trim(Config::str('autocard.api_url', '')) !== '';
    }

    public function isEnabled(): bool
    {
        return self::isConfigured();
    }

    public function requiresReview(): bool
    {
        return false;
    }

    /**
     * مهلت فاکتور (دقیقه).
     */
    public static function ttlMinutes(): int
    {
        $ttl = Config::int('autocard.ttl_minutes', 0);

        if ($ttl > 0) {
            return $ttl;
        }

        return Config::int('store.receipt_ttl_minutes', 120);
    }

    /**
     * ساخت فاکتور یکتا با مبلغ یکتا.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function start(array $order, int $chatId): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'پرداخت خودکار فعال نیست. ❌'];
        }

        $base = (int) $order['price_toman'];

        if ($base < 1) {
            return ['ok' => false, 'message' => 'مبلغ سفارش نامعتبر است. ❌'];
        }

        $unique  = $this->uniqueAmount($base, (int) $order['id']);
        $invoice = Str::orderCode('AC');
        $ttl     = self::ttlMinutes();
        $expire  = time() + $ttl * 60;

        $lines = [
            '🏦⚡️ <b>پرداخت خودکار کارت‌به‌کارت 💳</b>',
            '',
            '📦🎁 بسته: <b>' . Str::escape((string) $order['package_title']) . '</b>',
            '🔑🧾 کد فاکتور: <code>' . Str::escape($invoice) . '</code>',
            '',
            '💵✨ مبلغ دقیق یکتای قابل پرداخت: 💵',
            '💰 <b>' . Str::formatToman($unique) . '</b> 💰',
            '',
            '🏦 شماره کارت: <code>' . Str::escape(self::cardNumber()) . '</code>',
        ];

        $owner = trim(Config::str('autocard.card_owner', Config::str('store.card_owner', '')));
        if ($owner !== '') {
            $lines[] = '👤 به نام: <b>' . Str::escape($owner) . '</b> 🙏';
        }

        $lines[] = '';
        $lines[] = '⏱⏳ مهلت پرداخت: تا ' . Str::date($expire);
        $lines[] = '';
        $lines[] = '⚠️🎯 دقیقاً همین مبلغ را واریز کنید! چند رقم آخر رندوم است تا فاکتور شما یکتا شود. ✨';
        $lines[] = '📝 رسید لازم نیست! بعد از واریز، دکمهٔ «🔄 بررسی وضعیت» را بزنید تا خودکار تأیید شود. ✅🤖';
        $lines[] = '💡 کرون هم هر چند دقیقه خودکار بررسی می‌کند. ⏰';

        return [
            'ok'              => true,
            'message'         => implode("\n", $lines),
            'instructions'    => implode("\n", $lines),
            'reference'       => $invoice,
            'amount_toman'    => $unique,
            'currency'        => 'IRR',
            'requires_review' => false,
            'expires_at'      => $expire,
        ];
    }

    /**
     * بررسی وضعیت از روی استعلام کارت (برای دکمهٔ بررسی و کرون).
     *
     * @param  array<string, mixed> $payment رکورد جدول payments
     * @return array{status:string, paid:bool, message:string, reference?:string}
     */
    public function checkStatus(array $payment): array
    {
        $expected = (int) ($payment['amount_toman'] ?? 0);
        $since    = (int) ($payment['created_at'] ?? 0);
        $ttl      = self::ttlMinutes() * 60;

        if ($expected <= 0) {
            return ['status' => 'failed', 'paid' => false, 'message' => 'مبلغ فاکتور نامعتبر است. ❌'];
        }

        if ($since > 0 && (time() - $since) > $ttl) {
            return [
                'status'  => 'expired',
                'paid'    => false,
                'message' => '⏳⌛️ مهلت این فاکتور تمام شد! 😔 لطفاً پرداخت را از اول شروع کنید تا مبلغ یکتای جدید بگیرید. 🔄',
            ];
        }

        $result = (new AutoCardClient())->fetchTransactions();

        if (!($result['ok'] ?? false)) {
            return [
                'status'  => (string) ($payment['status'] ?? 'pending'),
                'paid'    => false,
                'message' => '⚠️🔌 استعلام خودکار فعلاً ممکن نیست! (' . ($result['message'] ?? '') . ') کمی بعد دوباره «🔄 بررسی وضعیت» را بزنید. 🙏',
            ];
        }

        foreach ($result['transactions'] as $txn) {
            if ((int) $txn['amount'] !== $expected) {
                continue;
            }

            $txnTime = (int) $txn['time'];

            // تراکنش باید حوالی همین فاکتور باشد، نه واریز قدیمیِ مبلغ مشابه.
            if ($since > 0 && $txnTime > 0 && ($txnTime < $since - self::TIME_MARGIN || $txnTime > time() + self::TIME_MARGIN)) {
                continue;
            }

            Logger::info('AutoCard payment matched', [
                'payment_id' => (int) ($payment['id'] ?? 0),
                'amount'     => $expected,
                'ref'        => $txn['ref'],
            ]);

            return [
                'status'    => 'confirmed',
                'paid'      => true,
                'message'   => 'پرداخت خودکار تأیید شد! ✅🎉',
                'reference' => $txn['ref'] !== '' ? $txn['ref'] : (string) ($payment['external_id'] ?? ''),
            ];
        }

        return [
            'status'  => 'pending',
            'paid'    => false,
            'message' => '⏳💳 هنوز واریزی با مبلغ یکتای فاکتور پیدا نشد! دقیقاً <b>' . Str::formatToman($expected) . '</b> واریز کنید و دوباره بررسی کنید. 🔍',
        ];
    }

    /**
     * مبلغ یکتا: قیمت + چند رقم آخر رندوم، بدون تداخل با فاکتورهای باز.
     */
    private function uniqueAmount(int $base, int $orderId): int
    {
        $orders = new OrderRepository();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $base + random_int(100, 999);

            if (!$orders->existsPendingAutoCardAmount($candidate, $orderId)) {
                return $candidate;
            }
        }

        // در بدترین حالت یک بازهٔ بزرگ‌تر امتحان می‌شود تا حتماً یکتا شود.
        return $base + random_int(1000, 9999);
    }
}
