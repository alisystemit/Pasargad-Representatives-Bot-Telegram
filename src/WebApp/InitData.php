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
            // ⚠️ دلیل را **یک‌بار** حساب می‌کنیم و همان را هم لاگ می‌کنیم و هم
            // به کلاینت می‌فرستیم؛ قبلاً این دو مسیر جدا بودند و برای حالت
            // «خیلی بلند» کدِ `empty` برمی‌گشت که گمراه‌کننده بود.
            $why = match (true) {
                $raw === '' && ! $transport['header_present'] => 'no_header',
                $raw === ''                                    => 'empty',
                default                                        => 'too_long',
            };

            AuthDiagnostics::logFailure($why, [
                'transport' => $transport,
                'max_length' => self::MAX_LENGTH,
            ]);

            return ['message' => 'دادهٔ ورودی تلگرام معتبر نیست.', 'reason' => $why] + $none;
        }

        // ⚠️ حتماً `trim` — و **همان مقدارِ trim‌شده** باید داخل HMAC هم استفاده شود.
        //
        // قبلاً فقط برای مقایسه با placeholder trim می‌شد ولی خودِ `$token` بدون
        // trim به HMAC می‌رفت. یک فاصله یا خط‌جدید انتهایی در `config.php` باعث
        // می‌شد امضا **همیشه** نادرست شود و کل مینی‌اپ خطای «امضای دادهٔ ورودی
        // نامعتبر است» بدهد — در حالی که توکن کاملاً درست بود.
        $token = trim($botToken ?? Config::str('bot_token', ''));

        if (in_array($token, self::PLACEHOLDER_TOKENS, true)) {
            AuthDiagnostics::logFailure('token_placeholder', ['transport' => $transport]);

            return ['message' => 'سرویس موقتاً در دسترس نیست.', 'reason' => 'token_placeholder'] + $none;
        }

        if (!AuthDiagnostics::tokenLooksValid($token)) {
            // ⚠️ این هشدار **مسیر را نمی‌شکند**. توکنِ درست هنوز می‌تواند از
            // همین فیلتر رد شود؛ ولی اگر اینجا زنگ بخورد، عملاً علتِ خطای
            // بعدی همین است.
            AuthDiagnostics::logFailure('token_format', ['transport' => $transport]);
        }

        $pairs = self::pairs($raw);

        $hash = (string) ($pairs['hash'] ?? '');

        if ($hash === '') {
            AuthDiagnostics::logFailure('no_hash', [
                'transport' => $transport,
                'keys'      => array_keys($pairs),
            ]);

            return ['message' => 'امضای دادهٔ ورودی وجود ندارد.', 'reason' => 'no_hash'] + $none;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
            AuthDiagnostics::logFailure('hash_format', [
                'transport' => $transport,
                'hash_len'  => strlen($hash),
                'keys'      => array_keys($pairs),
            ]);

            return ['message' => 'امضای دادهٔ ورودی وجود ندارد.', 'reason' => 'hash_format'] + $none;
        }

        // ------------------------------------------------------------------
        // ساخت data_check_string
        //
        // ⚠️ **هر دو** کلید `hash` و `signature` باید حذف شوند.
        //
        // طبق مستندات رسمی تلگرام (core.telegram.org/bots/webapps،
        // بخش «Validating data for Third-Party Use»):
        //
        //   "Append all received fields (except hash and signature),
        //    sorted alphabetically, in the format key=<value>"
        //
        // کلاینت‌های جدید تلگرام (نسخهٔ ۸ به بعد) کنار `hash`، فیلد
        // `signature` (امضای Ed25519 برای اعتبارسنجی شخص‌ثالث) را هم می‌فرستند.
        // هر دو امضا از **یک مجموعه فیلد یکسان** ساخته می‌شوند، پس `signature`
        // جزو رشتهٔ امضا نیست.
        //
        // کد قبلی دقیقاً برعکس عمل می‌کرد و `signature` را نگه می‌داشت؛ نتیجه
        // این بود که روی هر کلاینت جدید، امضا **همیشه** نادرست می‌شد و کل
        // Mini App با «امضای دادهٔ ورودی نامعتبر است» می‌مرد — در حالی که توکن
        // کاملاً درست بود.
        // ------------------------------------------------------------------
        unset($pairs['hash'], $pairs['signature']);
        ksort($pairs, SORT_STRING);

        $checkParts = [];
        foreach ($pairs as $key => $value) {
            $checkParts[] = $key . '=' . $value;
        }

        $checkString = implode("\n", $checkParts);

        // کلید مخفی از توکن ساخته می‌شود.
        //
        // ⚠️ **ترتیب آرگومان‌ها اینجا حیاتی است.**
        //
        // امضای PHP برابر است با: hash_hmac(algo, DATA, KEY)
        //
        // یعنی آرگومان دوم «داده» است و آرگومان سوم «کلید». پس برای
        // ساخت کلید مخفی باید داده = توکن و کلید = 'WebAppData' باشد:
        //
        //     secret = HMAC-SHA256( key: "WebAppData", message: bot_token )
        //
        // این دقیقاً همان چیزی است که تلگرام و همهٔ کتابخانه‌های مرجع
        // (python-telegram-bot، grammY، aiogram) استفاده می‌کنند، و در
        // مستندات تلگرام هم با نگارشِ «داده اول، کلید دوم» نوشته شده:
        // `HMAC_SHA256(<bot_token>, "WebAppData")`.
        //
        // این دو آرگومان قبلاً **جابه‌جا** بودند (`hash_hmac('sha256',
        // 'WebAppData', $token, true)`) و نتیجه این بود که کلید مخفی همیشه
        // اشتباه درست می‌شد ⇒ امضا هرگز نمی‌خواند، حتی با توکن کاملاً صحیح.
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $expect = hash_hmac('sha256', $checkString, $secret);

        if (!hash_equals($expect, strtolower($hash))) {
            // لاگ تشخیصی کامل. هیچ‌چیز حساس لو نمی‌رود: توکن فقط به‌صورت
            // اثرانگشت، و initData فقط به‌صورت «کلیدها + طول رشتهٔ امضا».
            //
            // `key_order` مهم‌ترین فیلد است: اگر ترتیب کلیدها با چیزی که
            // تلگرام می‌فرستد فرق کند (مثلاً به‌خاطر دستکاری کاراکتر `+` در
            // `urlencode`)، هش عوض می‌شود و ما از همین می‌فهمیم مشکل کجاست.
            AuthDiagnostics::logFailure('hash_mismatch', [
                'transport'  => $transport,
                'keys'       => array_keys($pairs),
                'key_order'  => array_keys($pairs),
                'check_string_length' => strlen($checkString),
                'has_signature_key'    => array_key_exists('signature', $pairs),
                'received_hash' => substr(strtolower($hash), 0, 16),
                'computed_hash' => substr($expect, 0, 16),
            ]);

            return ['message' => 'امضای دادهٔ ورودی نامعتبر است.', 'reason' => 'hash_mismatch'] + $none;
        }

        // ------------------------------------------------------------------
        // تازگی
        // ------------------------------------------------------------------
        $authDate = (int) ($pairs['auth_date'] ?? 0);

        if ($authDate <= 0) {
            AuthDiagnostics::logFailure('bad_auth_date', ['transport' => $transport, 'keys' => array_keys($pairs)]);

            return ['message' => 'زمان ورودی نامعتبر است.', 'reason' => 'bad_auth_date'] + $none;
        }

        $maxAge = $maxAge ?? self::DEFAULT_MAX_AGE;

        if ($maxAge > 0 && (time() - $authDate) > $maxAge) {
            AuthDiagnostics::logFailure('expired', [
                'transport' => $transport,
                'age_seconds' => time() - $authDate,
                'max_age'     => $maxAge,
            ]);

            return ['message' => 'نشست شما منقضی شده است. لطفاً اپلیکیشن را ببندید و دوباره باز کنید.', 'reason' => 'expired'] + $none;
        }

        // ------------------------------------------------------------------
        // استخراج کاربر
        // ------------------------------------------------------------------
        $user = json_decode((string) ($pairs['user'] ?? ''), true);

        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
            AuthDiagnostics::logFailure('no_user', [
                'transport' => $transport,
                'user_json_length' => strlen((string) ($pairs['user'] ?? '')),
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
