<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Support\Config;
use Pasargad\Support\Str;

/**
 * درگاه کارت‌به‌کارت با تأیید دستی سوپرادمین.
 *
 * جریان: ربات اطلاعات کارت را می‌دهد → کاربر پرداخت می‌کند و رسید را
 * به‌صورت عکس می‌فرستد → سوپرادمین رسید را تأیید/رد می‌کند → بسته خودکار اعمال می‌شود.
 */
final class CardToCardGateway implements PaymentGateway
{
    public const NAME = 'card2card';

    public function name(): string
    {
        return self::NAME;
    }

    public function title(): string
    {
        return '💳 کارت‌به‌کارت دستی 📝';
    }

    public function isEnabled(): bool
    {
        $number = Config::str('store.card_number');

        return $number !== '';
    }

    public function requiresReview(): bool
    {
        return true;
    }

    /**
     * ساخت راهنمای پرداخت و کد پیگیری برای سفارش.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function start(array $order, int $chatId): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => 'پرداخت کارت‌به‌کارت فعال نیست.'];
        }

        $ttl     = Config::int('store.receipt_ttl_minutes', 120);
        $expire  = time() + $ttl * 60;
        $code    = (string) $order['code'];
        $amount  = (int) $order['price_toman'];

        $lines = [
            '🏦✨ <b>پرداخت کارت‌به‌کارت دستی 📝</b>',
            '',
            '📦🎁 بسته: <b>' . Str::escape((string) $order['package_title']) . '</b>',
            '💰💵 مبلغ قابل پرداخت: <b>' . Str::formatToman($amount) . '</b>',
            '🔑🧾 کد پیگیری: <code>' . Str::escape($code) . '</code>',
            '',
            '🏦 شماره کارت: <code>' . Str::escape(Config::str('store.card_number')) . '</code> 💳',
        ];

        $owner = Config::str('store.card_owner');
        if ($owner !== '') {
            $lines[] = '👤 به نام: <b>' . Str::escape($owner) . '</b> 🙏';
        }

        $bank = Config::str('store.card_bank');
        if ($bank !== '') {
            $lines[] = '🏦 بانک: ' . Str::escape($bank) . ' 🏛️';
        }

        $note = Config::str('store.card_note');
        if ($note !== '') {
            $lines[] = '';
            $lines[] = '📌 ' . Str::escape($note);
        }

        $lines[] = '';
        $lines[] = '⏱⏳ مهلت ارسال رسید: تا ' . Str::date($expire);
        $lines[] = '';
        $lines[] = '📸 پس از واریز، <b>تصویر رسید</b> یا <b>شمارهٔ پیگیری</b> را همین‌جا بفرستید! 👇';
        $lines[] = 'پس از تأیید سوپرادمین، بسته به‌صورت خودکار روی پنل شما اعمال می‌شود! ✅🤖';

        return [
            'ok'              => true,
            'message'         => implode("\n", $lines),
            'instructions'    => implode("\n", $lines),
            'reference'       => $code,
            'amount_usd'      => null,
            'currency'        => 'IRR',
            'requires_review' => true,
            'expires_at'      => $expire,
        ];
    }

    /**
     * در کارت‌به‌کارت، وضعیت فقط با تأیید دستی تعیین می‌شود.
     *
     * @param  array<string, mixed> $payment
     * @return array{status:string, paid:bool, message:string}
     */
    public function checkStatus(array $payment): array
    {
        return [
            'status'  => (string) ($payment['status'] ?? 'pending'),
            'paid'    => (string) ($payment['status'] ?? '') === 'confirmed',
            'message' => 'وضعیت کارت‌به‌کارت با تأیید سوپرادمین مشخص می‌شود.',
        ];
    }
}