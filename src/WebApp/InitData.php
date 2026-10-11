<?php

declare(strict_types=1);

namespace Pasargad\WebApp;

use Pasargad\Support\Config;

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
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ هستهٔ اعتبارسنجی زیر **عیناً** از پروژهٔ `botsaz-faxima`
 * (`app/api.php`، تابع `auth`) کپی شده است، چون آن پیاده‌سازی در عمل با
 * تلگرام کار می‌کند. اینجا عمداً هیچ «بهبود» یا تفسیری اضافه نشده — هر
 * انحرافی از این توالی دقیقاً یعنی خطای «امضای دادهٔ ورودی نامعتبر است».
 *
 * روش رسمی تلگرام (HMAC-SHA256):
 *   ۱) رشتهٔ کوئری را با `parse_str` تجزیه کن.
 *   ۲) `hash` را بردار و از مجموعه حذف کن.
 *   ۳) بقیهٔ کلیدها را **الفبایی** مرتب کن و به شکل `key=value` با
 *      جداکنندهٔ `\n` بچسبان → `data_check_string`.
 *   ۴) کلید مخفی: `secret = HMAC-SHA256(key: "WebAppData", data: bot_token)`
 *   ۵) هش واقعی: `HMAC-SHA256(key: secret, data: data_check_string)`
 *   ۶) با `hash_equals` مقایسه کن.
 *
 * دو نکته که بارها باعث خطا شده‌اند:
 *
 *   • **فقط `hash` حذف می‌شود، نه `signature`.** کلاینت‌های جدید تلگرام کنار
 *     `hash` فیلد `signature` را هم می‌فرستند و آن هم جزو داده‌های امضاشده
 *     حساب می‌شود. حذفش باعث نادرست شدن هش می‌شود.
 *   • **ترتیب آرگومان‌های `hash_hmac`.** امضای PHP برابر است با
 *     `hash_hmac(algo, DATA, KEY)`، پس داده = توکن و کلید = `'WebAppData'`.
 *
 * علاوه بر امضا، **تازگی** داده هم بررسی می‌شود: `initData` یک روزه عملاً یک
 * توکن نشستی دائمی است، پس بدون سقف زمانی یک initData ضبط‌شده ماه‌ها بعد هم
 * کار می‌کند. اختلاف ساعت هم کنترل می‌شود (داده‌ای که خیلی جلوتر از زمان
 * سرور باشد، دست‌کاری‌شده یا ساعت سرور خیلی عقب است).
 */
final class InitData
{
    /** حداکثر سن قابل‌قبول initData (ثانیه). */
    public const DEFAULT_MAX_AGE = 86400;   // ۲۴ ساعت

    /** حداکثر طول رشتهٔ ورودی؛ ورودی بلندتر یعنی حمله یا باگ. */
    private const MAX_LENGTH = 8192;

    /** حداکثر اختلاف ساعت مجاز (ثانیه). */
    private const CLOCK_SKEW = 300;

    /** نگهبان توکن خالی/نمونه — همان‌هایی که BotApi هم رد می‌کند. */
    private const PLACEHOLDER_TOKENS = ['', 'PUT_BOT_TOKEN_HERE'];

    /**
     * تجزیه و اعتبارسنجی initData.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     reason: string,
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
            'reason'      => '',
            'user'        => [],
            'auth_date'   => 0,
            'start_param' => '',
            'query_id'    => '',
        ];

        // سطح انتقال: هدر رسید؟ چقدر بود؟ چه هدرهایی دیده شد؟
        $transport = AuthDiagnostics::transportFacts($_SERVER);

        $raw = trim((string) $raw);

        if ($raw === '' || strlen($raw) > self::MAX_LENGTH) {
            $why = match (true) {
                $raw === '' && ! $transport['header_present'] => 'no_header',
                $raw === ''                                    => 'empty',
                default                                        => 'too_long',
            };

            AuthDiagnostics::logFailure($why, [
                'transport'  => $transport,
                'max_length' => self::MAX_LENGTH,
            ]);

            return ['message' => 'دادهٔ ورودی تلگرام معتبر نیست.', 'reason' => $why] + $none;
        }

        // ⚠️ حتماً `trim` — و **همان مقدارِ trim‌شده** باید داخل HMAC هم برود.
        // یک فاصله یا خط‌جدید انتهایی در `config.php` باعث می‌شد امضا همیشه
        // نادرست شود، در حالی که توکن کاملاً درست بود.
        $token = trim($botToken ?? Config::str('bot_token', ''));

        if (in_array($token, self::PLACEHOLDER_TOKENS, true)) {
            AuthDiagnostics::logFailure('token_placeholder', ['transport' => $transport]);

            return ['message' => 'سرویس موقتاً در دسترس نیست.', 'reason' => 'token_placeholder'] + $none;
        }

        if (!AuthDiagnostics::tokenLooksValid($token)) {
            // ⚠️ این هشدار **مسیر را نمی‌شکند**؛ فقط در لاگ ثبت می‌شود.
            AuthDiagnostics::logFailure('token_format', ['transport' => $transport]);
        }

        // ------------------------------------------------------------------
        // ۱) تجزیه — عیناً مثل botsaz-faxima
        // ------------------------------------------------------------------
        parse_str($raw, $p);

        $hash = (string) ($p['hash'] ?? '');

        // ۲) فقط `hash` حذف می‌شود.
        $hadSignature = array_key_exists('signature', $p);
        unset($p['hash']);

        if ($hash === '') {
            AuthDiagnostics::logFailure('no_hash', ['transport' => $transport, 'keys' => array_keys($p)]);

            return ['message' => 'امضای دادهٔ ورودی وجود ندارد.', 'reason' => 'no_hash'] + $none;
        }

        // ۳) مرتب‌سازی الفبایی و ساخت data_check_string
        ksort($p);

        $pairs = [];
        foreach ($p as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $checkString = implode("\n", $pairs);

        // ۴ و ۵) کلید مخفی و هش واقعی
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $calc   = hash_hmac('sha256', $checkString, $secret);

        // ۶) مقایسهٔ مقاوم در برابر timing attack
        if (!hash_equals($calc, strtolower($hash))) {
            AuthDiagnostics::logFailure('hash_mismatch', [
                'transport'           => $transport,
                'keys'                => array_keys($p),
                'check_string_length' => strlen($checkString),
                'had_signature'       => $hadSignature,
                'received_hash'       => substr(strtolower($hash), 0, 16),
                'computed_hash'       => substr($calc, 0, 16),
            ]);

            return ['message' => 'امضای دادهٔ ورودی نامعتبر است.', 'reason' => 'hash_mismatch'] + $none;
        }

        // ------------------------------------------------------------------
        // تازگی + اختلاف ساعت
        // ------------------------------------------------------------------
        $authDate = (int) ($p['auth_date'] ?? 0);

        if ($authDate <= 0) {
            AuthDiagnostics::logFailure('bad_auth_date', ['transport' => $transport, 'keys' => array_keys($p)]);

            return ['message' => 'زمان ورودی نامعتبر است.', 'reason' => 'bad_auth_date'] + $none;
        }

        $maxAge = $maxAge ?? self::DEFAULT_MAX_AGE;

        if ($authDate > time() + self::CLOCK_SKEW) {
            AuthDiagnostics::logFailure('expired', [
                'transport' => $transport,
                'skew'      => $authDate - time(),
            ]);

            return ['message' => 'زمان ورودی نامعتبر است.', 'reason' => 'expired'] + $none;
        }

        if ($maxAge > 0 && (time() - $authDate) > $maxAge) {
            AuthDiagnostics::logFailure('expired', [
                'transport'   => $transport,
                'age_seconds' => time() - $authDate,
                'max_age'     => $maxAge,
            ]);

            return ['message' => 'نشست شما منقضی شده است. لطفاً اپلیکیشن را ببندید و دوباره باز کنید.', 'reason' => 'expired'] + $none;
        }

        // ------------------------------------------------------------------
        // استخراج کاربر
        // ------------------------------------------------------------------
        $user = json_decode((string) ($p['user'] ?? '{}'), true);

        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
            AuthDiagnostics::logFailure('no_user', [
                'transport'       => $transport,
                'user_json_length' => strlen((string) ($p['user'] ?? '')),
            ]);

            return ['message' => 'اطلاعات کاربر در دادهٔ ورودی یافت نشد.', 'reason' => 'no_user'] + $none;
        }

        AuthDiagnostics::logSuccess(['user_id' => (int) $user['id']]);

        return [
            'ok'          => true,
            'message'     => '',
            'reason'      => 'ok',
            'user'        => $user,
            'auth_date'   => $authDate,
            'start_param' => trim((string) ($p['start_param'] ?? '')),
            'query_id'    => trim((string) ($p['query_id'] ?? '')),
        ];
    }
}