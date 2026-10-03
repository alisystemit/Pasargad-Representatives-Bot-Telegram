<?php

declare(strict_types=1);

namespace Pasargad\Payment;

use Pasargad\Support\Config;
use Pasargad\Support\Http;
use Pasargad\Support\Logger;

/**
 * 💳🔌 کلاینت استعلام تراکنش‌های کارت (برای کارت‌به‌کارت خودکار).
 *
 * چون درگاه بانکی واحدی در ایران وجود ندارد، آدرس و کلید سرویس استعلام
 * از `config.php` خوانده می‌شود (بخش `autocard`):
 *
 *   'autocard' => [
 *       'api_url'     => 'https://...',  // سرویس برمی‌گرداند لیست تراکنش‌ها
 *       'api_key'     => '...',
 *       'amount_unit' => 'toman',        // یا 'rial'
 *   ],
 *
 * شکل پاسخ مورد انتظار (یکی از این‌ها):
 *   • آرایهٔ مستقیم تراکنش‌ها، یا
 *   • آبجکت با یکی از کلیدهای data / transactions / items / result / payments
 *
 * هر تراکنش می‌تواند این کلیدها را داشته باشد (اولین موجود استفاده می‌شود):
 *   • مبلغ: amount | Amount | price | Price | toman | amount_toman | rial | Rial
 *   • زمان: time | date | created_at | createdAt | timestamp | datetime
 *   • شناسه: ref | rrn | RRN | tracking | followup | id
 *
 * اگر سرویس شما شکل دیگری دارد، همین کلاس نقطهٔ تطبیق است.
 */
final class AutoCardClient
{
    /**
     * @return array{ok:bool, message:string, transactions:array<int, array{amount:int, time:int, ref:string}>}
     */
    public function fetchTransactions(): array
    {
        $url = trim(Config::str('autocard.api_url', ''));

        if ($url === '') {
            return ['ok' => false, 'message' => 'آدرس API استعلام در config.php تنظیم نشده است.', 'transactions' => []];
        }

        $headers = ['Accept: application/json'];
        $apiKey  = trim(Config::str('autocard.api_key', ''));

        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $response = Http::request('GET', $url, [
            'headers'    => $headers,
            'timeout'    => max(5, Config::int('autocard.timeout', 15)),
            'verify_ssl' => Config::get('autocard.verify_ssl', true) ? true : false,
        ]);

        if ($response['error'] !== '' || $response['status'] === 0) {
            Logger::warning('AutoCard inquiry failed', ['error' => $response['error']]);

            return ['ok' => false, 'message' => 'ارتباط با سرویس استعلام برقرار نشد.', 'transactions' => []];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::warning('AutoCard inquiry bad status', ['status' => $response['status']]);

            return ['ok' => false, 'message' => 'سرویس استعلام خطا داد (کد ' . $response['status'] . ').', 'transactions' => []];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'پاسخ سرویس استعلام نامعتبر است.', 'transactions' => []];
        }

        $list = $this->extractList($decoded);

        if ($list === null) {
            return ['ok' => false, 'message' => 'فهرست تراکنش‌ها در پاسخ سرویس پیدا نشد.', 'transactions' => []];
        }

        return ['ok' => true, 'message' => '', 'transactions' => $this->normalize($list)];
    }

    /**
     * @param array<mixed> $decoded
     * @return array<int, mixed>|null
     */
    private function extractList(array $decoded): ?array
    {
        if ($decoded === []) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        foreach (['data', 'transactions', 'items', 'result', 'payments'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $candidate = $decoded[$key];

                return array_is_list($candidate) ? $candidate : [$candidate];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed> $list
     * @return array<int, array{amount:int, time:int, ref:string}>
     */
    private function normalize(array $list): array
    {
        $rial = strtolower(trim(Config::str('autocard.amount_unit', 'toman'))) === 'rial';
        $out  = [];

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $amount = $this->pickNumber($item, ['amount', 'Amount', 'price', 'Price', 'toman', 'amount_toman', 'rial', 'Rial']);

            if ($amount === null || $amount <= 0) {
                continue;
            }

            if ($rial) {
                $amount = (int) round($amount / 10);
            }

            $time = $this->pickTime($item);
            $ref  = $this->pickString($item, ['ref', 'rrn', 'RRN', 'tracking', 'followup', 'followUp', 'id']);

            $out[] = ['amount' => (int) $amount, 'time' => $time, 'ref' => $ref];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string>   $keys
     */
    private function pickNumber(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return (float) $item[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function pickTime(array $item): int
    {
        foreach (['time', 'date', 'created_at', 'createdAt', 'timestamp', 'datetime'] as $key) {
            if (!isset($item[$key])) {
                continue;
            }

            $value = $item[$key];

            if (is_numeric($value) && (int) $value > 0) {
                // میلی‌ثانیه → ثانیه
                $ts = (int) $value;

                return $ts > 9999999999 ? (int) round($ts / 1000) : $ts;
            }

            if (is_string($value) && $value !== '') {
                $ts = strtotime($value);

                if ($ts !== false && $ts > 0) {
                    return $ts;
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string>   $keys
     */
    private function pickString(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && (is_string($item[$key]) || is_numeric($item[$key]))) {
                return (string) $item[$key];
            }
        }

        return '';
    }
}
