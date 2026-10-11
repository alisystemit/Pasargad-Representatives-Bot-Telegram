<?php

declare(strict_types=1);

/**
 * بررسی اجازه‌های یک کلید API.
 *
 * ## چرا این ابزار لازم است
 *
 * «کلید درست کار می‌کند» کافی نیست. یک کلید می‌تواند معتبر باشد و فقط
 * سه قابلیت از ربات را اجازه بدهد، و بقیه با ۴۰۳ بمانند — و ۴۰۳ دقیقاً
 * شبیه خطای موقت به نظر می‌رسد، پس ربات تلاش مجدد می‌کند و کاربر پول
 * داده ولی سرویسش اجرا نشده.
 *
 * دو نمونهٔ واقعی که روی همین پنل اندازه گرفته شد:
 *
 *   • کلیدی با `admins.read` ولی بدون `admins.update`
 *       ⇒ «شارژ/تمدید پنل» همیشه ۴۰۳ می‌دهد.
 *   • کلیدی بدون `admin_roles.read`
 *       ⇒ «ساخت پنل نمایندگی» نقش نمی‌تواند پیدا شود و چون `role_id`
 *         در اسپک **اجباری** است، ساخت با ۴۲۲ رد می‌شود.
 *
 * پس قبل از اینکه کسی تصمیم بگیرد رمز عبور را حذف کند، باید بداند کلید
 * دقیقاً چه کارهایی را می‌تواند انجام دهد.
 *
 * ## این ابزار هیچ‌چیز تغییر نمی‌دهد
 *
 * همهٔ بررسی‌ها فقط `GET` هستند، به‌جز دو مورد که با **بدنهٔ ناقص** فرستاده
 * می‌شوند تا سرور در مرحلهٔ اعتبارسنجی (۴۲۲) متوقف شود و چیزی ساخته نشود.
 */

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Support\Config;
use Pasargad\Support\Http;

$key = $argv[1] ?? '';

if ($key === '') {
    $key = trim((string) Config::str('panel.api_key', ''));
}

if ($key === '') {
    fwrite(STDERR, "کاربرد: php tools/panel_key_check.php <api-key>\n");
    fwrite(STDERR, "کلید را داخل کد لوگین نمی‌کنیم و فقط همین‌جا می‌خوانیم.\n");
    exit(1);
}

$base = rtrim(trim((string) Config::str('panel.base_url', '')), '/');

if ($base === '') {
    fwrite(STDERR, "panel.base_url در config.php تنظیم نشده است.\n");
    exit(1);
}

$headers = ['accept: application/json', 'authorization: ' . Pasargad\Panel\PasarGuardClient::SCHEME_API_KEY . ' ' . $key];

echo "\n" . str_repeat('═', 66) . "\n";
echo "  بررسی اجازه‌های کلید API\n";
echo str_repeat('═', 66) . "\n";
echo "  پنل: $base\n";
echo '  کلید: ' . substr($key, 0, 10) . '…' . substr($key, -4) . '  (اثر انگشت ' . substr(hash('sha256', $key), 0, 8) . ")\n\n";

/**
 * جزئیات خطا به شکل متن. پنل گاهی `detail` را آرایه می‌فرستد (مثلاً خطای
 * اعتبارسنجی بدنه) و تبدیل مستقیم آن به رشته، هشدار «Array to string
 * conversion» می‌دهد.
 */
function detailOf(array $response): string
{
    $data = $response['data'];

    if (!is_array($data)) {
        return '';
    }

    $detail = $data['detail'] ?? $data['message'] ?? '';

    if (is_array($detail)) {
        return mb_substr((string) json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 160);
    }

    if (is_scalar($detail) && (string) $detail !== '') {
        return (string) $detail;
    }

    return $response['status'] >= 400
        ? mb_substr((string) json_encode($data, JSON_UNESCAPED_UNICODE), 0, 160)
        : '';
}

/**
 * @return array{status:int, detail:string}
 */
function probe(string $base, array $headers, string $method, string $path, ?array $body = null): array
{
    $response = Http::json($method, $base . $path, $body, ['headers' => $headers, 'timeout' => 20]);

    return ['status' => $response['status'], 'detail' => detailOf($response)];
}

/**
 * بررسی می‌کند آیا سرور اجازه را دارد یا فقط بدنه را رد کرده است.
 *
 * ⚠️ این تفاوت حیاتی است و با حدس زدن معلوم نمی‌شود:
 *
 *   ۴۰۳ ⇒ کلید مجوز ندارد
 *   ۴۲۲ ⇒ مجوز دارد و بدنه ناقص بود (چیزی ساخته نشد — کار درست همین است)
 *
 * در FastAPI ترتیب حل وابستگی‌ها و اعتبارسنجی بدنه بین نسخه‌ها فرق می‌کند،
 * پس «۴۲۲ یعنی حتماً مجاز دارد» تضمین نیست. برای همین روش‌های نوشتنی یک
 * بار با بدنهٔ کامل هم امتحان می‌شوند و پاسخ بررسی می‌شود.
 *
 * @param array<string, mixed> $fullBody
 * @return array{allowed:bool, note:string}
 */
function canWrite(string $base, array $headers, string $method, string $path, array $fullBody): array
{
    $invalid = probe($base, $headers, $method, $path, []);

    if ($invalid['status'] === 403 || $invalid['status'] === 401) {
        return ['allowed' => false, 'note' => $invalid['detail']];
    }

    // بدنهٔ کامل ولی با نام کاربری‌ای که **حتماً** تکراری است: نه ساخته
    // می‌شود، نه چیزی تغییر می‌کند — ولی مجوز و اعتبار بدنه هر دو آزموده
    // می‌شوند. اگر مجوز نبود، ۴۰۳ می‌آید؛ اگر بدنه مشکل داشت، ۴۲۲.
    $full = probe($base, $headers, $method, $path, $fullBody);

    if ($full['status'] === 403 || $full['status'] === 401) {
        return ['allowed' => false, 'note' => $full['detail']];
    }

    if ($full['status'] === 422) {
        return ['allowed' => false, 'note' => 'بدنه پذیرفته نشد: ' . $full['detail']];
    }

    // هر پاسخ دیگری یعنی از مجوز **و** اعتبارسنجی بدنه رد شده و به منطق
    // کاری پنل رسیده — مثلاً ۴۰۹ «نام کاربری تکراری است». پس مجوز هست.
    return ['allowed' => true, 'note' => ''];
}

/**
 * @param array{status:int, detail:string} $result
 */
function verdict(array $result, bool $allowed): string
{
    if ($allowed) {
        return $result['status'] >= 200 && $result['status'] < 300 ? '✅ مجاز' : '❌ خطای غیرمنتظره';
    }

    if ($result['status'] === 403) {
        return '⛔️ مجوز ندارد (۴۰۳)';
    }

    if ($result['status'] === 401) {
        return '⛔️ کلید نامعتبر یا منقضی (۴۰۱)';
    }

    if ($result['status'] === 422) {
        return '✅ مجاز (بدنه ناقص بود، پس چیزی ساخته نشد)';
    }

    return '⚠️ ' . $result['status'] . ' — ' . mb_substr($result['detail'], 0, 80);
}

// ------------------------------------------------------------------
echo "۱) احراز هویت\n";
// ------------------------------------------------------------------

$me = probe($base, $headers, 'GET', '/api/admin');

if ($me['status'] === 401 || $me['status'] === 403) {
    echo '  ❌ کلید کار نمی‌کند: ' . $me['detail'] . "\n";
    echo "     اگر تازه ساخته‌اید، مطمئن شوید با طرح هدر درست فرستاده می‌شود\n";
    echo "     (پنل 'ApiKey' می‌خواهد، نه 'Bearer').\n";
    exit(1);
}

echo "  ✅ کلید معتبر است.\n\n";

// ------------------------------------------------------------------
echo "۲) قابلیت‌هایی که ربات به آن‌ها نیاز دارد\n";
// ------------------------------------------------------------------

// نام کاربریِ یک ادمینِ موجود را می‌خوانیم تا در پروبِ «ساخت ادمین» از آن
// استفاده کنیم. با نام تکراری، پنل قطعاً ۴۰۹ می‌دهد و **هیچ اکانتی ساخته
// نمی‌شود** — ولی مجوز و اعتبار بدنه هر دو آزموده می‌شوند. این تنها راهِ
// بی‌خطر برای فهمیدنِ مجوز نوشتن است.
$existingUsername = '';

$adminsResponse = Http::json('GET', $base . '/api/admins?limit=1', null, ['headers' => $headers, 'timeout' => 20]);
$firstAdmin = is_array($adminsResponse['data']['admins'][0] ?? null)
    ? $adminsResponse['data']['admins'][0]
    : null;

if ($firstAdmin !== null) {
    $existingUsername = (string) ($firstAdmin['username'] ?? '');
}

$probeUsername = $existingUsername !== '' ? $existingUsername : '__probe__not-a-real-admin__';

if ($existingUsername === '') {
    echo "  ℹ️  نتوانستم یک نام کاربریِ موجود بخوانم؛ با نام ساختگی پروب می‌زنم،\n";
    echo "     پس نتیجهٔ سطر «ساخت پنل نمایندگی» در این اجرا قطعی نیست.\n";
}

/** @var array<int, array{label:string, probe:array{status:int,detail:string}|null, write:array<string,mixed>|null}> */
$checks = [
    [
        'label' => 'خواندن ادمین‌ها (فهرست پنل‌های نمایندگان)',
        'probe' => probe($base, $headers, 'GET', '/api/admins?limit=1'),
        'write' => null,
    ],
    [
        'label' => 'خواندن ادمین جاری (تست اتصال)',
        'probe' => probe($base, $headers, 'GET', '/api/admin'),
        'write' => null,
    ],
    [
        'label' => 'ساخت پنل نمایندگی',
        'probe' => null,
        'write' => [
            'method' => 'POST',
            'path'   => '/api/admin',
            // نام کاربریِ موجود ⇒ پنل ۴۰۹ می‌دهد و چیزی ساخته نمی‌شود.
            'body'   => [
                'username'   => $probeUsername,
                // پنل سیاست رمز سخت‌گیرانه دارد. رمز پروب باید همهٔ بندها را
                // رعایت کند (طول، ۲ حرف بزرگ، ۲ رقم) وگرنه ۴۲۲ می‌گیریم و
                // نمی‌توانیم فرقِ «مجوز ندارم» و «رمزم ضعیف بود» را بفهمیم.
                'password'   => 'Qwert9!ZyReno7',
                // نقش ۱ نقش مالک است و پنل ساخت با آن را رد می‌کند، پس پروب
                // هیچ‌وقت به بررسی تکراری‌بودن نمی‌رسد. نقش ۲ (غیرمالک) می‌گذارد
                // به مرحلهٔ بعد برسد و ۴۰۹ بدهد.
                'role_id'    => 2,
                'data_limit' => 1,
                'status'     => 'active',
            ],
        ],
    ],
    [
        'label' => 'خواندن نقش‌ها (برای دادن نقش اپراتور)',
        'probe' => probe($base, $headers, 'GET', '/api/admin-roles?limit=1'),
        'write' => null,
    ],
    [
        'label' => 'ویرایش ادمین = شارژ/تمدید حجم',
        'probe' => null,
        'write' => [
            'method' => 'PUT',
            'path'   => '/api/admin/__probe__not-a-real-admin__',
            'body'   => ['data_limit' => 1],
        ],
    ],
    [
        'label' => 'خواندن کاربران (آمار)',
        'probe' => probe($base, $headers, 'GET', '/api/users?limit=1'),
        'write' => null,
    ],
    [
        'label' => 'غیرفعال کردن گروهی کاربران (قطع دسترسی)',
        'probe' => null,
        'write' => [
            'method' => 'POST',
            'path'   => '/api/admin/__probe__not-a-real-admin__/users/disable',
            'body'   => [],
        ],
    ],
    // ⚠️ این سطر حیاتی است و قبلاً جا افتاده بود.
    //
    // ربات بعد از ساخت هر پنل نمایندگی، برای ادمینِ تازه یک کلید دیگر می‌سازد
    // (`POST /api/api_key`) و از آن برای شارژ/تمدید/آمار استفاده می‌کند — چون
    // دیگر رمز نمی‌پرسد.
    //
    // بدون این مجوز، خرید **بعد** از ساختِ موفقِ ادمین می‌شکند. یعنی خریدار
    // پول می‌دهد، پنل روی سرور ساخته می‌شود، ولی ربات آن را تحویل نمی‌دهد و
    // سفارش «ناموفق» می‌شود. بدون این بررسی، همه‌چیز سبز به نظر می‌رسید.
    //
    // پروب بی‌خطر است: بدنهٔ خالی ⇒ ۴۲۲ (چیزی ساخته نمی‌شود) و مجوز هم آزموده
    // می‌شود؛ بدنهٔ کامل با `admin_id` نامعتبر ⇒ ۴۲۲ و باز هم چیزی ساخته نمی‌شود.
    [
        'label' => 'ساخت کلید برای پنل نمایندگی',
        'probe' => probe($base, $headers, 'GET', '/api/api_keys?limit=1'),
        'write' => [
            'method' => 'POST',
            'path'   => '/api/api_key',
            'body'   => [
                'name'     => 'probe-not-created',
                'admin_id' => 999999999,   // ادمینِ ناموجود ⇒ ۴۲۲، نه ساختِ واقعی
            ],
        ],
    ],
];

$allowed = 0;
$total   = 0;

echo "\n";

foreach ($checks as $check) {
    $label = $check['label'];

    if ($check['probe'] !== null) {
        $result = $check['probe'];
        $ok     = $result['status'] >= 200 && $result['status'] < 300;
        $note   = $result['detail'];
    } else {
        $write = $check['write'];
        $verdict = canWrite($base, $headers, $write['method'], $write['path'], $write['body']);
        $ok     = $verdict['allowed'];
        $note   = $verdict['note'];
    }

    $total++;

    if ($ok) {
        $allowed++;
    }

    printf("  %-44s %s\n", $label, $ok ? '✅ مجاز' : '⛔️ مجوز ندارد');

    if (!$ok && $note !== '') {
        printf("  %-44s   ↳ %s\n", '', mb_substr($note, 0, 110));
    }
}

echo "\n" . str_repeat('─', 66) . "\n";
printf("  نتیجه: %d از %d قابلیت\n", $allowed, $total);
echo str_repeat('─', 66) . "\n";

// ------------------------------------------------------------------
echo "\n۳) نتیجه برای ربات\n";
// ------------------------------------------------------------------

$roleOk   = probe($base, $headers, 'GET', '/api/admin-roles?limit=1');
$updateOk = canWrite($base, $headers, 'PUT', '/api/admin/__probe__not-a-real-admin__', ['data_limit' => 1]);

$repRoleId = (int) Config::int('panel.rep_role_id', 0);

if ($roleOk['status'] === 403 && $repRoleId <= 0) {
    echo "  ⛔️ <b>خرید «پنل نمایندگی» کار نمی‌کند.</b>\n";
    echo "     دلیل: کلید نقش‌ها را نمی‌بیند و `role_id` هم تنظیم نشده،\n";
    echo "     ولی `role_id` در اسپک پنل اجباری است ⇒ ساخت با ۴۲۲ رد می‌شود.\n\n";
    echo "     یکی از این دو را انجام دهید:\n";
    echo "       الف) کلیدی با مجوز `admin_roles.read` بسازید\n";
    echo "       ب) در config.php مقدار `panel.rep_role_id` را دستی بگذارید\n";
} elseif ($roleOk['status'] === 403) {
    echo "  ✅ ساخت پنل نمایندگی می‌تواند کار کند — به‌خاطر `panel.rep_role_id`\n";
    echo "     که در config.php روی $repRoleId تنظیم شده است.\n";
} else {
    echo "  ✅ ساخت پنل نمایندگی می‌تواند کار کند (نقش‌ها خوانده می‌شوند).\n";
}

if ($updateOk['allowed'] === false) {
    echo "\n  ⛔️ <b>خرید «شارژ/تمدید پنل» کار نمی‌کند.</b>\n";
    echo "     دلیل: کلید مجوز `admins.update` را ندارد و شارژ یعنی PUT روی ادمین.\n";
    echo "     کاربر پول می‌دهد ولی حجمش اضافه نمی‌شود.\n\n";
    echo "     کلیدی با مجوز `admins.update` بسازید.\n";
} else {
    echo "\n  ✅ شارژ/تمدید پنل می‌تواند کار کند.\n";
}

echo "\n";
echo '  یادآوری: ' . Pasargad\Panel\PasarGuardClient::SCHEME_API_KEY
   . " یعنی هدر درست «authorization: ApiKey <کلید>» است؛ «Bearer» همیشه ۴۰۱ می‌دهد.\n\n";
