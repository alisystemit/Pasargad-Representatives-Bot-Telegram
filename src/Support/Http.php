<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * کلاینت HTTP سبک روی cURL (با fallback به stream) برای تماس با پنل و درگاه پرداخت.
 */
final class Http
{
    /**
     * @param array<string, mixed>  $options
     * @return array{status:int, body:string, headers:array<string,string>, error:string, duration_ms:int}
     */
    public static function request(string $method, string $url, array $options = []): array
    {
        $timeout     = (int) ($options['timeout'] ?? 20);
        $verifySsl   = (bool) ($options['verify_ssl'] ?? true);
        $body        = $options['body'] ?? null;
        $headers     = (array) ($options['headers'] ?? []);
        $formParams  = $options['form'] ?? null;

        if ($formParams !== null) {
            $body       = http_build_query($formParams);
            $headers[]  = 'Content-Type: application/x-www-form-urlencoded';
        }

        $startedAt = microtime(true);

        if (function_exists('curl_init')) {
            $response = self::viaCurl($method, $url, $headers, $body, $timeout, $verifySsl);
        } else {
            $response = self::viaStream($method, $url, $headers, $body, $timeout, $verifySsl);
        }

        $response['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return $response;
    }

    /**
     * @return array{status:int, body:string, headers:array<string,string>, error:string}
     */
    private static function viaCurl(string $method, string $url, array $headers, ?string $body, int $timeout, bool $verifySsl): array
    {
        $curl = curl_init();
        if ($curl === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'راه‌اندازی cURL ناموفق بود.'];
        }

        $responseHeaders = [];

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null && $body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw  = curl_exec($curl);
        $errNo = curl_errno($curl);
        $err  = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($errNo !== 0) {
            return ['status' => 0, 'body' => '', 'headers' => $responseHeaders, 'error' => $err];
        }

        return [
            'status'  => $status,
            'body'    => is_string($raw) ? $raw : '',
            'headers' => $responseHeaders,
            'error'   => '',
        ];
    }

    /**
     * @return array{status:int, body:string, headers:array<string,string>, error:string}
     */
    private static function viaStream(string $method, string $url, array $headers, ?string $body, int $timeout, bool $verifySsl): array
    {
        $contextOptions = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ];

        if ($body !== null && $body !== '') {
            $contextOptions['http']['content'] = $body;
        }

        $context = stream_context_create($contextOptions);

        // $http_response_header متغیری است که file_get_contents در همین scope
        // می‌سازد؛ اگر درخواست شکست خورده باشد ممکن است اصلاً ساخته نشود.
        $raw = @file_get_contents($url, false, $context);

        /** @var array<int, string> $headersOut */
        $headersOut = $http_response_header ?? [];

        $status  = 0;
        $headers = [];
        foreach ($headersOut as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        if ($raw === false && $status === 0) {
            return ['status' => 0, 'body' => '', 'headers' => $headers, 'error' => 'ارتباط با سرور برقرار نشد.'];
        }

        return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'headers' => $headers, 'error' => ''];
    }

    /**
     * درخواست JSON با تلاش مجدد در صورت خطای شبکه/سرور.
     *
     * @param  array<string, mixed>|string|null $payload آرایهٔ داده یا JSON از پیش کدگذاری‌شده
     * @param  array<string, mixed>             $options
     * @return array{status:int, data:array<string, mixed>|null, raw:string, error:string}
     */
    public static function json(string $method, string $url, $payload = null, array $options = []): array
    {
        $headers = (array) ($options['headers'] ?? []);
        $headers[] = 'Accept: application/json';

        if ($payload !== null && !isset($options['form'])) {
            $headers[] = 'Content-Type: application/json';
            $encoded   = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);

            // اگر json_encode شکست بخورد (داده نامعتبر مثلاً UTF-8 خراب)،
            // بدنهٔ خالی فرستادن بهتر از ارسال JSON ناقص است.
            if ($encoded === false) {
                return ['status' => 0, 'data' => null, 'raw' => '', 'error' => 'کدگذاری JSON درخواست ناموفق بود.'];
            }

            $body = $encoded;
        } else {
            $body = null;
        }

        $attempts = max(1, (int) ($options['attempts'] ?? 2));
        $response = ['status' => 0, 'data' => null, 'raw' => '', 'error' => ''];

        for ($i = 1; $i <= $attempts; $i++) {
            $result = self::request($method, $url, [
                'headers'    => $headers,
                'body'       => $body,
                'form'       => $options['form'] ?? null,
                'timeout'    => (int) ($options['timeout'] ?? 20),
                'verify_ssl' => (bool) ($options['verify_ssl'] ?? true),
            ]);

            if ($result['error'] === '' && $result['status'] > 0) {
                $response = [
                    'status' => $result['status'],
                    'data'   => json_decode($result['body'], true),
                    'raw'    => $result['body'],
                    'error'  => '',
                ];
                break;
            }

            $response = [
                'status' => $result['status'],
                'data'   => null,
                'raw'    => $result['body'],
                'error'  => $result['error'] !== '' ? $result['error'] : 'خطای شبکه (status=' . $result['status'] . ')',
            ];

            if ($i < $attempts) {
                usleep(400000 * $i);
            }
        }

        return $response;
    }
}