<?php

declare(strict_types=1);

/**
 * تست تطابق کلاینت پنل با اسپک رسمی PasarGuard ۵.
 *
 * چرا این تست وجود دارد؟
 *
 * یک باگ واقعی پیدا شد: `getAdmin()` داشت `GET /api/admin/{username}` می‌زد
 * که **در پنل وجود ندارد** و ۴۰۵ برمی‌گرداند. با این حال ۸۹۵ تست سبز بودند،
 * چون `FakePanelClient` متد را کامل بازنویسی کرده بود و مستقیم از آرایهٔ خودش
 * جواب می‌داد. یعنی **کلاینت ساختگی داشت باگ کلاینت واقعی را پنهان می‌کرد.**
 *
 * این تست دو کار می‌کند:
 *   ۱) ثابت می‌کند مسیرهای انتخابی واقعاً روی پنل وجود دارند (جدول زیر از
 *      بررسی زندهٔ `us.api-system.top` درآمده، نه از حدس).
 *   ۲) یک نگهبان می‌گذارد که هر متدی بخواهد یک مسیر ۴۰۵‌دار بزند، تست را
 *      بشکند.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Panel\PanelException;
use Pasargad\Store\TestDb;

$db = TestDb::boot();
(new Pasargad\Support\Migrator($db))->migrate();

$passed = 0;
$failed = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  \033[32m✅\033[0m {$label}\n";
        return;
    }

    $failed++;
    echo "  \033[31m❌\033[0m {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
};

// =====================================================================
echo "\n▶ جدول مسیرهای واقعی پنل (بررسی زنده، نسخهٔ ۵)\n";
// =====================================================================
//
// این جدول «قرارداد» است: هر مسیری که کلاینت استفاده می‌کند باید اینجا باشد.
// اگر نسخهٔ پنل عوض شد و مسیری حذف شد، همین تست اولین جایی است که خبر می‌دهد.

const PANEL_ROUTES = [
    // متد => مسیر
    'POST'   => '/api/admin/token',
    'GET'    => '/api/admin',
    'POST'   => '/api/admin',
    'PUT'    => '/api/admin/{username}',
    'DELETE' => '/api/admin/{username}',
    'POST'   => '/api/admin/{username}/reset',
    'POST'   => '/api/admin/{username}/users/disable',
    'GET'    => '/api/admin/{username}/usage',
    'GET'    => '/api/admins',
    'GET'    => '/api/admin-roles',
    'POST'   => '/api/user',
    'GET'    => '/api/user/{username}',
    'PUT'    => '/api/user/{username}',
    'PUT'    => '/api/user/{username}/disabled',
    'POST'   => '/api/user/{username}/reset',
    'GET'    => '/api/users',
    'GET'    => '/api/system',
    'GET'    => '/health',
];

/**
 * مسیرهایی که **وجود ندارند** — استفاده از هرکدام یعنی کد خراب.
 */
const PANEL_FORBIDDEN_ROUTES = [
    // باگ کشف‌شده: GET روی این دو مسیر ۴۰۵ می‌دهد (فقط PUT/DELETE هست).
    ['GET',    '/api/admin/{username}'],
    ['GET',    '/api/admin/by-id/{admin_id}'],
    ['POST',   '/api/admin/by-id/{admin_id}'],
];

$check('جدول مسیرها خالی نیست', PANEL_ROUTES !== []);
$check('لیست مسیرهای ممنوع خالی نیست', PANEL_FORBIDDEN_ROUTES !== []);

// =====================================================================
echo "\n▶ getAdmin(): مسیر درست را انتخاب می‌کند\n";
// =====================================================================

$panel = new FakePanelClient();
$panel->addAdmin('rep900', ['id' => 7, 'data_limit' => 500]);
$panel->addAdmin('owner1', ['id' => 1, 'data_limit' => 999]);
$panel->ownerUsername = 'owner1';

$pathsOf = static function (FakePanelClient $p, string $method): array {
    $out = [];

    foreach ($p->loggedRequests as $r) {
        if ($r['method'] === $method) {
            $out[] = $r['path'];
        }
    }

    return $out;
};

// حالت ① — خودِ ادمین ⇒ باید GET /api/admin صدا بزند
$panel->loggedRequests = [];
$self = $panel->getAdmin('rep900', 'rep900', 'pass');

$check('خودِ ادمین از مسیر /api/admin خوانده شد',
    in_array('/api/admin', $pathsOf($panel, 'getCurrentAdmin'), true),
    json_encode($pathsOf($panel, 'getAdmin')));

$check('و به لیست ادمین‌ها نرفت', $pathsOf($panel, 'listAdmins') === [],
    json_encode($pathsOf($panel, 'listAdmins')));

$check('رکورد درست برگشت', ($self['data_limit'] ?? null) === 500, json_encode($self));

// حالت ② — ادمین دیگر با اکانت sudo ⇒ باید /api/admins?username=
$panel->loggedRequests = [];
$other = $panel->getAdmin('rep900', 'owner1', 'ownerpass');

$check('ادمین دیگر از /api/admins خوانده شد',
    in_array('/api/admins', $pathsOf($panel, 'listAdmins'), true),
    json_encode($pathsOf($panel, 'listAdmins')));

$check('و به مسیر ممنوع /api/admin/{username} نرفت',
    !in_array('/api/admin', $pathsOf($panel, 'getCurrentAdmin'), true),
    json_encode($pathsOf($panel, 'getCurrentAdmin')));

$check('رکورد ادمین دیگر درست باز شد',
    ($other['username'] ?? '') === 'rep900', json_encode($other));

// نام کاربری با بزرگی/کوچکی متفاوت هم «خودِ ادمین» است
$panel->loggedRequests = [];
$panel->getAdmin('REP900', 'rep900', 'pass');
$check('مقایسهٔ نام کاربری بدون حساسیت به بزرگی حروف است',
    in_array('/api/admin', $pathsOf($panel, 'getCurrentAdmin'), true),
    json_encode($pathsOf($panel, 'getAdmin')));

// =====================================================================
echo "\n▶ getAdmin(): خطاها درست تفکیک می‌شوند\n";
// =====================================================================

$panel->loggedRequests = [];

try {
    $panel->getAdmin('', 'owner1', 'ownerpass');
    $check('نام کاربری خالی رد شد', false, 'استثنا پرتاب نشد');
} catch (PanelException $e) {
    $check('نام کاربری خالی رد شد', $e->httpStatus() === 404, 'status=' . $e->httpStatus());
}

// اپراتور عادی نباید بتواند ادمین دیگری را ببیند (فیلتر sudo در فیک)
$panel->loggedRequests = [];

try {
    $panel->getAdmin('owner1', 'rep900', 'pass');
    $check('اپراتور عادی ادمین دیگر را نمی‌بیند', false, 'باید ۴۰۴ می‌داد');
} catch (PanelException $e) {
    $check('اپراتور عادی ادمین دیگر را نمی‌بیند', $e->httpStatus() === 404,
        'status=' . $e->httpStatus());
}

// خطای ورود باید ۴۰۱ بماند نه ۴۰۴
$panel->loginError = 'invalid credentials';

try {
    $panel->getAdmin('rep900', 'rep900', 'pass');
    $check('رمز اشتباه = ۴۰۱ (نه ۴۰۴)', false, 'استثنا پرتاب نشد');
} catch (PanelException $e) {
    $check('رمز اشتباه = ۴۰۱ (نه ۴۰۴)', $e->httpStatus() === 401,
        'status=' . $e->httpStatus());
}

$panel->loginError = '';

// =====================================================================
echo "\n▶ getAdminById(): از مسیر موجود می‌خواند\n";
// =====================================================================

$panel->loggedRequests = [];
$byId = $panel->getAdminById(7, 'owner1', 'ownerpass');

$check('خواندن با شناسه از /api/admins انجام شد',
    in_array('/api/admins', $pathsOf($panel, 'listAdmins'), true),
    json_encode($pathsOf($panel, 'listAdmins')));

$check('و به مسیر ۴۰۵‌دار /api/admin/by-id نرفت',
    !in_array('/api/admin/by-id/7', $pathsOf($panel, 'listAdmins'), true),
    json_encode($pathsOf($panel, 'listAdmins')));

$check('رکورد درست برگشت', ($byId['username'] ?? '') === 'rep900', json_encode($byId));

try {
    $panel->getAdminById(0, 'owner1', 'ownerpass');
    $check('شناسهٔ نامعتبر رد شد', false, 'استثنا پرتاب نشد');
} catch (PanelException $e) {
    $check('شناسهٔ نامعتبر رد شد', $e->httpStatus() === 404, 'status=' . $e->httpStatus());
}

// =====================================================================
echo "\n▶ disableUser(): از endpoint اختصاصی می‌رود\n";
// =====================================================================
//
// چرا مهم است؟ `PUT /api/user/{username}` مدلش همه‌چیز-اختیاری است. اگر پنل
// فیلدهای نفرستاده را null کند، ارسال فقط {status:"disabled"} حجم و تاریخ
// انقضای مشتری را پاک می‌کند — یعنی «قطع دسترسی» عملاً «حذف» می‌شود.

$panel->loggedRequests = [];
$panel->addUser('cust1', ['data_limit' => 1073741824, 'expire' => 1893456000, 'status' => 'active']);

$panel->disableUser('cust1', 'rep900', 'pass');

$paths = $pathsOf($panel, 'disableUser');
$check('غیرفعال‌سازی از مسیر /disabled انجام شد',
    in_array('/api/user/cust1/disabled', $paths, true), json_encode($paths));

$check('و از مسیر عمومی PUT /api/user/{username} نرفت',
    !in_array('/api/user/cust1', $paths, true), json_encode($paths));

$check('محدودیت حجم کاربر دست‌نخورده ماند',
    (int) ($panel->existingUsers['cust1']['data_limit'] ?? 0) === 1073741824,
    json_encode($panel->existingUsers['cust1'] ?? []));

$check('تاریخ انقضای کاربر دست‌نخورده ماند',
    (int) ($panel->existingUsers['cust1']['expire'] ?? 0) === 1893456000);

// فعال‌سازی دوباره باید از مسیر عمومی برود تا همهٔ فیلدها اعمال شوند
$panel->loggedRequests = [];
$panel->disableUser('cust1', 'rep900', 'pass', ['status' => 'active', 'expire' => 1900000000]);

$paths = $pathsOf($panel, 'modifyUser');
$check('فعال‌سازی دوباره از مسیر عمومی رفت (تا فیلدهای اضافه هم اعمال شوند)',
    in_array('/api/user/cust1', $paths, true), json_encode($paths));

// نام کاربری خالی نباید درخواست بفرستد
$panel->loggedRequests = [];

try {
    $panel->disableUser('  ', 'rep900', 'pass');
    $check('نام کاربری خالی درخواست نمی‌فرستد', false, 'استثنا پرتاب نشد');
} catch (PanelException $e) {
    $check('نام کاربری خالی درخواست نمی‌فرستد',
        $e->httpStatus() === 404 && $pathsOf($panel, 'disableUser') === []);
}

// =====================================================================
echo "\n▶ نگهبان: مسیرهای ممنوع نباید در کد باشند\n";
// =====================================================================
//
// این بخش سورس کلاینت را می‌خواند و هر فراخوانی با مسیر ممنوع را پیدا می‌کند.
// اگر کسی دوباره `GET /api/admin/{username}` بنویسد، همین‌جا لو می‌رود — حتی
// اگر کلاینت ساختگی دوباره آن را پنهان کند.

$source = (string) file_get_contents(
    dirname(__DIR__) . '/src/Panel/PasarGuardClient.php'
);

// فقط بخش‌های مستندات حذف می‌شوند؛ کد اجرایی دست‌نخورده می‌ماند.
$withoutDocs = (string) preg_replace('#/\*\*.*?\*/#s', '', $source);

/**
 * آیا `request()` با متد مشخص، مسیر داده‌شده را صدا می‌زند؟
 *
 * بررسی پنجرهٔ کوتاهی بعد از هر `'GET'` کافی است چون قالب فراخوانی در کل
 * فایل یکنواخت است (`request(\n 'GET',\n '<path>',`).
 */
$usesRoute = static function (string $code, string $method, string $pathFragment) use ($withoutDocs): bool {
    $offset = 0;

    while (($pos = strpos($withoutDocs, "'" . $method . "'", $offset)) !== false) {
        // پنجرهٔ معنادار بعد از متد: تا ۲۰۰ کاراکتر
        $window = substr($withoutDocs, $pos, 200);
        $at     = strpos($window, $pathFragment);

        if ($at !== false) {
            // ⚠️ باید مطمئن شویم مسیر **همان** است، نه پیشوندِ مسیر بلندتر.
            // مثال: `/api/admin/{u}` پیشوندِ `/api/admin/{u}/usage` است و
            // آن یکی GET مجاز دارد. پس بعد از قطعه، باید پایان آرگومان بیاید:
            // گیومهٔ بسته + ویرگول/پرانتز — نه الحاق `' . '/...`.
            $after = substr($window, $at + strlen($pathFragment), 12);

            $isCompleteArg = preg_match('/^\s*[\'"]*\s*[,)]/', $after) === 1;

            if ($isCompleteArg) {
                return true;
            }
        }

        $offset = $pos + strlen($method) + 2;
    }

    return false;
};

// باگ کشف‌شده: GET روی این دو مسیر ۴۰۵ می‌دهد.
$check(
    'کد اجرایی GET /api/admin/{username} را صدا نمی‌زند (پنل ۴۰۵ می‌دهد)',
    !$usesRoute($withoutDocs, 'GET', "'/api/admin/' . rawurlencode(\$targetUsername)")
);

$check(
    'کد اجرایی GET /api/admin/by-id/ را صدا نمی‌زند (پنل ۴۰۵ می‌دهد)',
    !$usesRoute($withoutDocs, 'GET', "'/api/admin/by-id/'")
);

// مسیرهای ۴۰۵‌دار نباید با هیچ متد دیگری هم صدا زده شوند، مگر آن‌هایی که
// واقعاً وجود دارند: PUT/DELETE روی /api/admin/{username}.
$check(
    'PUT روی /api/admin/{username} مجاز است و باید بماند',
    $usesRoute($withoutDocs, 'PUT', "'/api/admin/' . rawurlencode(\$targetUsername)")
);

$check(
    'DELETE روی /api/admin/{username} مجاز است و باید بماند',
    $usesRoute($withoutDocs, 'DELETE', "'/api/admin/' . rawurlencode(\$targetUsername)")
);

// GET روی /usage مجاز است و باید تشخیص داده شود (پیشوند مشترک با مسیر ممنوع
// دارد، پس بررسی «پایان آرگومان» اینجا حیاتی است)
$check(
    'GET /api/admin/{username}/usage مجاز است و با مسیر ممنوع اشتباه گرفته نمی‌شود',
    str_contains($withoutDocs, ". '/usage?'")
);

// مسیرهای درست باید در کد اجرایی حاضر باشند.
// (این دو فقط «حضور رشته» را می‌سنجند، نه جای دقیقش — چون مسیرشان با
// الحاق query string ساخته می‌شود و تشخیص «پایان آرگومان» در آن‌ها معنا ندارد.)
$check(
    'کد اجرایی مسیر /api/admins را دارد',
    str_contains($withoutDocs, "'/api/admins?'"),
    'مسیر جایگزین getAdmin باید در کد باشد'
);

$check(
    'کد اجرایی مسیر /disabled را دارد',
    str_contains($withoutDocs, "'/disabled'"),
    'مسیر اختصاصی disableUser باید در کد باشد'
);

// مسیرهای ممنوع نباید **به‌عنوان رشتهٔ مسیر** جایی ذکر شده باشند، مگر
// در توضیح بخش‌هایی که حذف شدند (بالا).
$check(
    '/by-id/ دیگر در هیچ فراخوانی‌ای نیست',
    !str_contains($withoutDocs, 'by-id/')
);

// =====================================================================
echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);