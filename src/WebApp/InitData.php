<?php

declare(strict_types=1);

namespace Pasargad\WebApp;

use Pasargad\Support\Config;
use Pasargad\Support\Logger;

/**
 * اعتبارسنجی `Telegram.WebApp.initData` و استخراج کاربر از آن.
 *
 * چرا این کلاس حیاتی است؟
 *
 * `initDataUnsafe` (چیزی که کلاینت به‌صورت پیش‌فرض می‌خواند) **امضا نشده** و
 * کاملاً در اختیار کلاینت است. هر کسی می‌تواند آن را در کنسول مرورگر عوض کند و
 * خود را جای هر کاربری جا بزند. پس اگر API ما به `initDataUnsafe` تکیه کند،
 * عملاً بدون احراز هویت است: هر کسی می‌تواند پنل‌های دیگران، سفارش‌ها و رمز
 * پنلشان را بخواند.
 *
 * روش رسمی تلگرام: HMAC-SHA256.
 *   ۱) از رشتهٔ کوئری، `hash` (و `signature`) را جدا می‌کنیم.
 *   ۲) بقیهٔ کلیدها را **lexicographically** مرتب و به شکل `key=value` با
 *      جداکنندهٔ `\n` به هم می‌چسبانیم → `data_check_string`.
 *   ۳) کلید مخفی: `secret = HMAC_SHA256(key = "WebAppData", data = bot_token)`
 *   ۴) `hash` واقعی: `HMAC_SHA256(key = secret, data = data_check_string)`
 *   ۵) با `hash_equals` مقایسه می‌کنیم.
 *
 * علاوه بر امضا، **تازگی** داده هم بررسی می‌شود: `initData` یک روزه عملاً
 * یک توکن نشستی دائمی است. بدون سقف زمانی، یک initData ضبط‌شده ماه‌ها بعد هم
 * کار می‌کند.
 */
final class InitData
{
    /** حداکثر سن قابل‌قبول initData (ثانیه). */
    public const DEFAULT_MAX_AGE = 86400;   // ۲۴ ساعت

    /** حداکثر طول رشتهٔ ورودی؛ ورودی بلندتر یعنی حمله یا باگ. */
    private const MAX_LENGTH = 8192;

    /** نگهبان توکن خالی/نمونه — همان‌هایی که BotApi هم رد می‌کند. */
    private const PLACEHOLDER_TOKENS = ['', 'PUT_BOT_TOKEN_HERE'];

    /**
     * تجزیه و اعتبارسنجی initData.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     user: array<string, mixed>,
     *     auth_date: int,
     *     start_param: string,
     *     query_id: string
     * }
     */
    public static function parse(?string $raw, ?int $maxAge = null, ?string $botToken = null): array
    {
        $none = [
            'ok'          => false,
            'message'     => 'دادهٔ ورودی تلگرام معتبر نیست.',
            'user'        => [],
            'auth_date'   => 0,
            'start_param' => '',
            'query_id'    => '',
        ];

        $raw = trim((string) $raw);

        if ($raw === '' || strlen($raw) > self::MAX_LENGTH) {
            return $none;
        }

        $token = $botToken ?? Config::str('bot_token', '');

        if (in_array(trim($token), self::PLACEHOLDER_TOKENS, true)) {
            Logger::error('initData rejected: bot_token is not configured');

            return ['message' => 'سرویس موقتاً در دسترس نیست.'] + $none;
        }

        $pairs = self::pairs($raw);

        $hash = (string) ($pairs['hash'] ?? '');

        if ($hash === '' || preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
            return ['message' => 'امضای دادهٔ ورودی وجود ندارد.'] + $none;
        }

        // ------------------------------------------------------------------
        // ساخت data_check_string
        //
        // ⚠️ فقط `hash` حذف می‌شود، نه `signature`: طبق اسنپ‌شات تلگرام، کلید
        // `signature` هم در امضای قدیمی (HMAC) جزو رشتهٔ امضاشده است. حذفش
        // باعث می‌شود هش همیشه اشتباه شود و کل Mini App از کار بیفتد.
        // ------------------------------------------------------------------
        unset($pairs['hash']);
        ksort($pairs, SORT_STRING);

        $checkParts = [];
        foreach ($pairs as $key => $value) {
            $checkParts[] = $key . '=' . $value;
        }

        $checkString = implode("\n", $checkParts);

        $secret = hash_hmac('sha256', 'WebAppData', $token, true);
        $expect = hash_hmac('sha256', $checkString, $secret);

        if (!hash_equals($expect, strtolower($hash))) {
            Logger::warning('initData signature mismatch', [
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
                'keys'  => array_keys($pairs),
            ]);

            return ['message' => 'امضای دادهٔ ورودی نامعتبر است.'] + $none;
        }

        // ------------------------------------------------------------------
        // تازگی
        // ------------------------------------------------------------------
        $authDate = (int) ($pairs['auth_date'] ?? 0);

        if ($authDate <= 0) {
            return ['message' => 'زمان ورودی نامعتبر است.'] + $none;
        }

        $maxAge = $maxAge ?? self::DEFAULT_MAX_AGE;

        if ($maxAge > 0 && (time() - $authDate) > $maxAge) {
            return ['message' => 'نشست شما منقضی شده است. لطفاً اپلیکیشن را ببندید و دوباره باز کنید.'] + $none;
        }

        // ------------------------------------------------------------------
        // استخراج کاربر
        // ------------------------------------------------------------------
        $user = json_decode((string) ($pairs['user'] ?? ''), true);

        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
            return ['message' => 'اطلاعات کاربر در دادهٔ ورودی یافت نشد.'] + $none;
        }

        return [
            'ok'          => true,
            'message'     => '',
            'user'        => $user,
            'auth_date'   => $authDate,
            'start_param' => trim((string) ($pairs['start_param'] ?? '')),
            'query_id'    => trim((string) ($pairs['query_id'] ?? '')),
        ];
    }

    /**
     * تجزیهٔ رشتهٔ کوئری به آرایه، با رمزگشایی درست `+`.
     *
     * `parse_str` روی نام‌هایی مثل `a[b]` آرایه می‌سازد و روی کلید تکراری آخر
     * را نگه می‌دارد؛ هر دو برای این کار غلط‌اند. پس دستی و ساده پارس می‌کنیم.
     *
     * @return array<string, string>
     */
    private static function pairs(string $raw): array
    {
        $out = [];

        foreach (explode('&', $raw) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            $eq = strpos($chunk, '=');

            if ($eq === false) {
                $key = urldecode($chunk);
                $val = '';
            } else {
                $key = urldecode(substr($chunk, 0, $eq));
                $val = urldecode(substr($chunk, $eq + 1));
            }

            if ($key === '') {
                continue;
            }

            $out[$key] = $val;
        }

        return $out;
    }
}
