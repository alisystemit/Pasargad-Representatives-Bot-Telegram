<?php

declare(strict_types=1);

/**
 * تست بودجهٔ زمانی و نگهبان «هنگ» وبهوک.
 *
 * مسئله‌ای که این تست نگهبانی می‌کند:
 *
 *   بدترین حالت یک فراخوانی ساده به پنل، بدون سقف زمانی:
 *     login  = ۲ تلاش × ۲۰ ثانیه = ۴۰ ثانیه
 *     send   = ۲ تلاش × ۲۰ ثانیه = ۴۰ ثانیه
 *     ۴۰۱ → یک دور کامل دیگر      = ۸۰ ثانیه
 *     جمع                        = ۱۶۰ ثانیه (۲.۷ دقیقه)
 *
 *   تلگرام بعد از ۶۰ ثانیه پاسخ‌نگیری را timeout می‌کند و **همان update را
 *   دوباره می‌فرستد**. نتیجه: پردازش تکراری، بارگذاری دوبارهٔ پنل، پیام تکراری
 *   برای کاربر و در نهایت قفل شدن دکمه‌ها توسط FloodGuard — یعنی همان
 *   «هنگ کردن» و «درست فرمان ندادن».
 *
 *   این تست ثابت می‌کند که بودجه فعال است، timeout با وقت باقی‌مانده کوتاه
 *   می‌شود، تلاش مجدد بی‌فایده حذف می‌شود، و وقتی وقت تمام شد **شبکه اصلاً
 *   لمس نمی‌شود**.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';

use Pasargad\Panel\FakePanelClient;
use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Store\TestDb;
use Pasargad\Telegram\FakeBotApi;

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

/** فراخوانی متد خصوصی (برای بررسی رفتار داخلی بودجه). */
$priv = static function (object $obj, string $method, mixed ...$args): mixed {
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);

    return $m->invoke($obj, ...$args);
};

// =====================================================================
echo "\n▶ پیش‌فرض: بدون سقف (کرون و CLI)";
// =====================================================================
$c = new PasarGuardClient('https://us.api-system.top');

$check('بدون beginRequest سقفی وجود ندارد', $c->remainingSeconds() === INF,
    (string) $c->remainingSeconds());
$check('timeout مؤثر = timeout اصلی', $priv($c, 'effectiveTimeout') === 20);
$check('تلاش مجدد = ۲', $priv($c, 'effectiveAttempts') === 2);

// =====================================================================
echo "\n▶ با بودجه: سقف فعال می‌شود\n";
// =====================================================================
$c->beginRequest(45.0);

$remaining = $c->remainingSeconds();
$check('بودجهٔ ۴۵ ثانیه ثبت شد', $remaining > 43.0 && $remaining <= 45.0,
    'باقی‌مانده=' . round($remaining, 2));

$check('timeout مؤثر هنوز سقف اصلی است (وقت داریم)',
    $priv($c, 'effectiveTimeout') === 20);
$check('تلاش مجدد هنوز ۲ است (وقت داریم)', $priv($c, 'effectiveAttempts') === 2);

$quiet = true;

try {
    $priv($c, 'assertTimeLeft', '/api/admin');
} catch (PanelException) {
    $quiet = false;
}

$check('وقت کافی ⇒ نگهبان ساکت است', $quiet);

// =====================================================================
echo "\n▶ با گذشت زمان، timeout کوتاه می‌شود\n";
// =====================================================================
$c->beginRequest(12.0);
usleep(2_500_000);   // ۲.۵ ثانیه

$left = $c->remainingSeconds();
$t    = $priv($c, 'effectiveTimeout');

$check('وقت باقی‌مانده کم شد', $left < 10.0, 'باقی‌مانده=' . round($left, 2));
$check('timeout مؤثر با وقت باقی‌مانده کوتاه شد', $t < 20, 'timeout=' . $t);
$check('و به بی‌معنایی نرسید (حداقل ۱ ثانیه)', $t >= 1, 'timeout=' . $t);

// بودجهٔ ۱۲ ثانیه با timeout ۲۰ ⇒ تلاش مجدد بی‌فایده ⇒ باید حذف شود
$check('تلاش مجدد بی‌فایده حذف شد', $priv($c, 'effectiveAttempts') === 1,
    'attempts=' . $priv($c, 'effectiveAttempts'));

// =====================================================================
echo "\n▶ وقت تمام شد ⇒ شبکه اصلاً لمس نمی‌شود\n";
// =====================================================================
$c->beginRequest(6.0);
usleep(4_800_000);   // کمتر از ۲ ثانیه باقی می‌ماند

$check('باقی‌مانده زیر آستانهٔ ۲ ثانیه است', $c->remainingSeconds() < 2.0,
    'باقی‌مانده=' . round($c->remainingSeconds(), 2));

$refused = false;
$message = '';

try {
    $priv($c, 'assertTimeLeft', '/api/admin');
} catch (PanelException $e) {
    $refused = true;
    $message = $e->getMessage();
}

$check('نگهبان درخواست را رد کرد', $refused, 'بدون استثنا رد شد');
$check('و پیامش فارسی و قابل‌فهم به کاربر است',
    $refused && str_contains($message, 'پنل') && str_contains($message, 'تلاش کنید'),
    $message);

// باید قابل‌ترجمه باشد نه یک استثنای فنی
$check('و جزئیات فنی به کاربر نشت نمی‌کند',
    $refused && !str_contains($message, '/api/admin'),
    $message);

// =====================================================================
echo "\n▶ فراخوانی واقعی وقتی بودجه تمام است ⇒ استثنای سریع\n";
// =====================================================================
//
// این مهم‌ترین بخش تست است: ثابت می‌کند مسیر واقعی (نه فقط متد خصوصی)
// وقتی بودجه تمام شده، **شبکه را لمس نمی‌کند**. بدون این، یک فراخوانی
// واقعی ۴۰ ثانیه معطلی می‌کرد و بعد تازه می‌فهمیدیم وقت نمانده.

$live = new class('https://panel.invalid.test') extends PasarGuardClient {
    /** تعداد تلاش برای گرفتن توکن — یعنی نخستین تماس واقعی با شبکه. */
    public int $loginCalls = 0;

    /**
     * چرا `login` و نه `request`/`send`؟
     *
     * `login()` **نخستین** کاری است که کلاینت برای صحبت با پنل انجام می‌دهد و
     * `public` است، پس قابل قلاب. اگر بودجه تمام باشد، نگهبان باید **قبل از
     * این** متوقف شود؛ پس صفر بودن این شمارنده اثبات می‌کند شبکه اصلاً لمس
     * نشده. (`send()` خصوصی است و قابل قلاب نیست، و `request()` را قلاب‌کردن
     * دقیقاً نگهبانِ داخل `send()` را دور می‌زد و تست بی‌معنی می‌شد.)
     */
    public function login(string $username, string $password, bool $useCache = true): string
    {
        $this->loginCalls++;

        // اگر نگهبان کار نکند، به شبکهٔ واقعی می‌خوریم و تست طول می‌کشد.
        throw new PanelException('نباید این‌جا می‌رسید — شبکه لمس شد', 0);
    }
};

$live->beginRequest(5.0);
usleep(3_400_000);   // باقی‌مانده ≈ ۱.۶ ثانیه ⇒ زیر آستانه

$threw = false;
$msg   = '';
$err   = null;

try {
    $live->getAdmin('x', 'x', 'p');
} catch (PanelException $e) {
    $threw = true;
    $err   = $e;
    $msg   = $e->getMessage();
}

$check('فراخوانی واقعی سریع متوقف شد', $threw, 'استثنا نداد');
$check('و هرگز به شبکه نرسید (login صدا زده نشد)',
    $live->loginCalls === 0,
    'loginCalls=' . $live->loginCalls . ' — نگهبان کار نکرد');
$check('و پیامش دربارهٔ مهلت پردازش است',
    $threw && str_contains($msg, 'مهلت پردازش'), $msg);
$check('و خطای بودجه شناخته می‌شود، نه خطای شبکه',
    $err !== null && $err->isBudgetExhausted());
$check('و تلاش‌مجددناپذیر است', $err !== null && !$err->isRetryable());

// =====================================================================
echo "\n▶ برداشتن بودجه\n";
// =====================================================================
$c->clearRequestBudget();

$check('برداشتن بودجه کار می‌کند', $c->remainingSeconds() === INF);
$check('timeout به حالت اصلی برگشت', $priv($c, 'effectiveTimeout') === 20);
$check('تلاش مجدد به ۲ برگشت', $priv($c, 'effectiveAttempts') === 2);

// =====================================================================
echo "\n▶ مقادیر بی‌معنا نادیده گرفته می‌شوند\n";
// =====================================================================
foreach ([0.0, 1.0, 3.9, -10.0] as $bad) {
    $x = new PasarGuardClient('https://us.api-system.top');
    $x->beginRequest($bad);

    $check(sprintf('beginRequest(%.1f) ⇒ بدون سقف (کمتر از ۵ ثانیه بی‌فایده است)', $bad),
        $x->remainingSeconds() === INF);
}

// =====================================================================
echo "\n▶ Kernel بودجه را به کلاینت تازه منتقل می‌کند\n";
// =====================================================================
//
// بدون این، `panelClient()` یک کلاینت **تازه** می‌سازد و بودجهٔ تعیین‌شده را
// فراموش می‌کرد — یعنی اولین فراخوانی در هر مسیر بدون سقف اجرا می‌شد.

// BotApi قلابی تا ساخت Kernel شبکه نخواهد؛ مثل بقیهٔ تست‌ها.
$kernel = new Pasargad\Bot\Kernel(new FakeBotApi());
$kernel->beginPanelBudget(30.0);

$client = new ReflectionMethod($kernel, 'panelClient');
$client->setAccessible(true);

$panelClient = $client->invoke($kernel);
$check('panelClient() بودجه دارد', $panelClient->remainingSeconds() < 30.0,
    'باقی‌مانده=' . round($panelClient->remainingSeconds(), 1));

$check('و همان نمونه دوباره برگردانده می‌شود (کش)',
    $client->invoke($kernel) === $panelClient);

// =====================================================================
echo "\n▶ سورس ربات بودجه را صدا می‌زند\n";
// =====================================================================
//
// نگهبان یکپارچگی: اگر کسی خط را از bot.php حذف کند، بودجه بی‌اثر می‌ماند و
// تست‌های رفتاری بالا باز هم سبز می‌شوند. این تست جلویش را می‌گیرد.

$botSource = (string) file_get_contents(dirname(__DIR__) . '/bot.php');
$withoutDocs = (string) preg_replace('#//.*$#m', '', $botSource);

$check('bot.php تابع beginPanelBudget را صدا می‌زند',
    str_contains($withoutDocs, 'beginPanelBudget'),
    'خط بودجه در bot.php پیدا نشد — بودجه بی‌اثر است!');

$check('و این صدا زدن قبل از handle() است',
    (int) strpos($withoutDocs, 'beginPanelBudget') < (int) strpos($withoutDocs, '->handle('),
    'ترتیب اشتباه است: بودجه باید قبل از پردازش اعمال شود');

$check('تنظیم پیش‌فرض بودجه زیر ۶۰ ثانیهٔ تلگرام است',
    (int) Pasargad\Support\Config::int('panel.webhook_budget_seconds', 45) < 60,
    'بودجه=' . Pasargad\Support\Config::int('panel.webhook_budget_seconds', 45));

// =====================================================================
echo "\n▶ خطای بودجه با خطای شبکه اشتباه گرفته نشود\n";
// =====================================================================
//
// هر دو وضعیت ۰ دارند، ولی رفتارشان فرق می‌کند:
//   • خطای شبکه   → تلاش مجدد شاید درست کند
//   • بودجهٔ تمام  → تلاش مجدد قطعاً شکست می‌خورد، چون وقتی نمانده
//
// اگر تفکیک نشوند، `Provisioner` سفارش را بی‌دلیل «پایانی» می‌کرد یا بی‌نهایت
// در صف نگه می‌داشت.

$budgetErr = PanelException::budgetExceeded('تمام شد', '/api/admin');
$netErr    = new PanelException('ارتباط برقرار نشد', 0);

$check('خطای بودجه، تلاش‌مجددناپذیر است', !$budgetErr->isRetryable());
$check('و پرچم اختصاصی‌اش روشن است', $budgetErr->isBudgetExhausted());

$check('خطای شبکه همچنان تلاش‌مجددناپذیر… نیست (قابل‌تلاش‌مجدد است)',
    $netErr->isRetryable(), 'خطای شبکه باید قابل تلاش مجدد بماند');
$check('و پرچم بودجه‌اش خاموش است', !$netErr->isBudgetExhausted());

$check('۵۰۰ همچنان قابل‌تلاش‌مجدد است',
    (new PanelException('خطای سرور', 500))->isRetryable());
$check('۴۰۴ تلاش‌مجددناپذیر است',
    !(new PanelException('پیدا نشد', 404))->isRetryable());
$check('۴۰۳ عملیاتی تلاش‌مجددناپذیر است',
    !(new PanelException('دسترسی نداری', 403))->isRetryable());

// =====================================================================
echo "\n▶ بدنهٔ خالی ⇒ درخواست بی‌معنی به پنل نمی‌رود\n";
// =====================================================================
//
// `AdminModify` و `UserModify` هیچ فیلد اجباری ندارند، پس `PUT` با `{}` از نظر
// HTTP موفق است. ولی نسخه‌هایی از پنل که فیلدهای نفرستاده را null می‌گیرند،
// با این کار sub_domain/note نماینده یا data_limit مشتری را پاک می‌کنند.

$live2 = new class('https://panel.invalid.test') extends PasarGuardClient {
    public int $requestCalls = 0;

    public function request(string $method, string $path, string $username, string $password, ?array $payload = null): array
    {
        $this->requestCalls++;

        return [
            'status' => 200,
            'data'   => ['username' => 'rep1'],
            'raw'    => '{"username":"rep1"}',
            'error'  => '',
        ];
    }
};

$emptyRejectedAdmin = false;
$emptyRejectedUser  = false;

$before = $live2->requestCalls;

try {
    $live2->modifyAdmin('rep1', [], 'owner', 'pass');
} catch (PanelException) {
    $emptyRejectedAdmin = true;
}

$check('modifyAdmin با بدنهٔ خالی درخواست نفرستاد', $live2->requestCalls === $before,
    'requestCalls=' . $live2->requestCalls);

$before = $live2->requestCalls;

try {
    $live2->modifyUser('cust1', [], 'owner', 'pass');
} catch (PanelException) {
    $emptyRejectedUser = true;
}

$check('modifyUser با بدنهٔ خالی درخواست نفرستاد', $live2->requestCalls === $before,
    'requestCalls=' . $live2->requestCalls);

$check('و استثنایش قابل‌فهم بود', $emptyRejectedAdmin && $emptyRejectedUser);

// ولی بدنهٔ پُر مثل همیشه می‌رود
$before = $live2->requestCalls;
$live2->modifyAdmin('rep1', ['data_limit' => 100], 'owner', 'pass');
$check('بدنهٔ پُر همچنان ارسال می‌شود', $live2->requestCalls === $before + 1);

// =====================================================================
echo "\n▶ syncMany(): سقف زمانی کل اجرای کرون\n";
// =====================================================================
//
// کرون تا ۲۰۰ پنل را بررسی می‌کند و هر نماینده نام کاربری خودش را دارد، پس
// کش توکن بین پنل‌ها به کار نمی‌آید و هر کدام احراز هویت جدا می‌خواهند.
// با پنل کند (۸۰ ثانیه برای هر پنل) یعنی ۲۰۰ × ۸۰ = بیش از ۴ ساعت؛ کرون
// بعدی روی همان زمان اجرا می‌شود و پروسه‌های نیمه‌تمام انباشته می‌شوند.

$syncerPanel = new class('https://panel.invalid.test') extends PasarGuardClient {
    /** @var array<string, array<string, mixed>> */
    public array $fakeAdmins = [];

    public function login(string $u, string $p, bool $c = true): string
    {
        usleep(30_000);
        return 'stub';
    }

    public function request(string $m, string $path, string $u, string $p, ?array $pl = null): array
    {
        usleep(30_000);

        if (str_ends_with($path, '/api/admin')) {
            return [
                'status' => 200,
                'data'   => $this->fakeAdmins[$u] ?? ['username' => $u, 'data_limit' => 0],
                'raw'    => '{}',
                'error'  => '',
            ];
        }

        return ['status' => 200, 'data' => [], 'raw' => '{}', 'error' => ''];
    }
};

$repo    = new Pasargad\Store\PanelRepository($db);
$users   = new Pasargad\Store\UserRepository($db);
$syncUid = (int) $users->upsertByTelegram(778, ['telegram_id' => 778])['id'];

for ($i = 1; $i <= 40; $i++) {
    $syncerPanel->fakeAdmins['rep' . $i] = [
        'username'     => 'rep' . $i,
        'data_limit'   => 1073741824,
        'used_traffic' => $i,
    ];

    $repo->create($syncUid, 'rep' . $i, 'pass', [
        'panel_status' => 'active',
        'data_limit'   => 1073741824,
    ]);
}

$watchable = $repo->listWatchable(50);

$generous = (new Pasargad\Store\PanelSyncer($repo, $syncerPanel))->syncMany($watchable, 600.0);
$check('با بودجهٔ کافی همهٔ پنل‌ها بررسی می‌شوند',
    $generous['checked'] === count($watchable) && $generous['skipped'] === 0,
    "checked={$generous['checked']} skipped={$generous['skipped']}");
$check('و سقف مصرف نشد', $generous['budget_used'] === false);

$t      = microtime(true);
$tight  = (new Pasargad\Store\PanelSyncer($repo, $syncerPanel))->syncMany($watchable, 0.4);
$spent  = microtime(true) - $t;

$check('با بودجهٔ کم، اجرا متوقف شد', $tight['budget_used'] === true,
    json_encode($tight));
$check('و باقی‌مانده صریحاً شمرده شد', $tight['skipped'] > 0,
    "skipped={$tight['skipped']}");
$check('و جمع «بررسی‌شده + جامانده» = کل پنل‌هاست',
    $tight['checked'] + $tight['skipped'] === count($watchable),
    "{$tight['checked']} + {$tight['skipped']} ≠ " . count($watchable));
$check('و زمان واقعی از بودجه بیشتر نشد', $spent < 2.0,
    sprintf('زمان=%.2f ثانیه با بودجهٔ ۰.۴', $spent));

// صفر یعنی «بی‌سقف» — کرون که پس‌زمینه‌ای است و باید همه را ببیند
$unbounded = (new Pasargad\Store\PanelSyncer($repo, $syncerPanel))->syncMany($watchable, 0.0);
$check('بودجهٔ صفر = بدون سقف (همه بررسی شدند)',
    $unbounded['skipped'] === 0 && $unbounded['checked'] === count($watchable),
    "skipped={$unbounded['skipped']}");

// تنظیم پیش‌فرض کرون باید بین کرون و وبهوک فرق داشته باشد
$check('سقف کرون بیشتر از سقف وبهوک است (کرون پس‌زمینه‌ای است)',
    (int) Pasargad\Support\Config::int('panel.cron_budget_seconds', 600)
        > (int) Pasargad\Support\Config::int('panel.webhook_budget_seconds', 45));

// =====================================================================
echo "\n▶ پیام‌های خطای پنل درست ترجمه می‌شوند\n";
// =====================================================================
//
// یک باگ واقعی: قاعدهٔ `not allowed` (برای ۴۰۳) **قبل** از قاعدهٔ
// `method not allowed` بود. نتیجه: خطای «Method Not Allowed» — که یعنی کد
// ما با نسخهٔ پنل هماهنگ نیست، یعنی تقصیر کاربر نیست — به کاربر نشان داده
// می‌شد با این متن: «دسترسی لازم را ندارید». کاربر می‌رفت پشتیبانی و می‌گفت
// «حسابم مشکل دارد» در حالی که باید به ما گزارش می‌داد.
//
// نکتهٔ دوم: «Could not validate credentials» پیام واقعی FastAPI برای ورود
// اشتباه است و شامل کلمهٔ `credentials` می‌شود؛ اگر قاعدهٔ عمومیِ رمز عبور
// زودتر بیاید، کاربر پیام بی‌معنای «نشست منقضی شده» می‌گرفت و بی‌دلیل رمزش
// را عوض می‌کرد.

$translate = new ReflectionMethod(PasarGuardClient::class, 'translate');
$httpError = new ReflectionMethod(PasarGuardClient::class, 'httpErrorMessage');
$probe     = new PasarGuardClient('https://panel.invalid.test');

$expectations = [
    ['Could not validate credentials',                401, 'نام کاربری یا رمز عبور'],
    ['Not authenticated',                             401, 'منقضی شده'],
    ['You are not allowed to perform this action',    403, 'دسترسی لازم'],
    ['Method Not Allowed',                            405, 'پشتیبانی نمی‌شود'],
    ['Admin not found',                               404, 'پیدا نشد'],
    ['admin already exists',                          409, 'از قبل وجود دارد'],
];

foreach ($expectations as [$detail, $status, $needle]) {
    $out = $translate->invoke($probe, $detail, $status, 'fallback');

    $check(sprintf('[%d] «%s» درست ترجمه شد', $status, $detail),
        str_contains($out, $needle),
        "دریافت: «{$out}» — انتظار: «{$needle}»");
}

// ۴۰۵ نباید هرگز «دسترسی ندارید» بدهد، چون مشکل از حساب کاربر نیست
$msg405 = $httpError->invoke($probe, ['status' => 405, 'data' => null, 'raw' => '', 'error' => '']);
$check('۴۰۵ به کاربر می‌گوید با پشتیبانی تماس بگیرد، نه اینکه حسابش مشکل دارد',
    str_contains($msg405, 'پشتیبانی') && !str_contains($msg405, 'دسترسی لازم'),
    $msg405);

// نگهبان ترتیب: قاعدهٔ ۴۰۵ باید در آرایه **قبل** از قاعدهٔ not allowed باشد
$rules = (new ReflectionClass(PasarGuardClient::class))->getConstant('MESSAGE_RULES');

$at405   = null;
$atAllow = null;

foreach ($rules as $i => [$needles]) {
    foreach ($needles as $n) {
        if ($at405 === null && str_contains($n, 'method not allowed')) {
            $at405 = $i;
        }
        if ($atAllow === null && $n === 'not allowed') {
            $atAllow = $i;
        }
    }
}

$check('قاعدهٔ method not allowed قبل از not allowed تعریف شده',
    $at405 !== null && $atAllow !== null && $at405 < $atAllow,
    "۴۰۵ در سطر {$at405}، not allowed در سطر {$atAllow}");

// و قاعدهٔ رمز عبور باید قبل از «نشست منقضی» باشد
$atCred = null;
$atSess = null;

foreach ($rules as $i => [$needles]) {
    foreach ($needles as $n) {
        if ($atCred === null && str_contains($n, 'credentials')) {
            $atCred = $i;
        }
        if ($atSess === null && $n === 'not authenticated') {
            $atSess = $i;
        }
    }
}

$check('قاعدهٔ رمز عبور قبل از «نشست منقضی» تعریف شده',
    $atCred !== null && $atSess !== null && $atCred < $atSess,
    "رمز در سطر {$atCred}، نشست در سطر {$atSess}");

// =====================================================================
echo "\n▶ سهمیهٔ کل پروسه به کلاینت‌های تازه می‌رسد\n";
// =====================================================================
//
// این مهم‌ترین بخش ضدهنگ است. در یک درخواست وبهوک، چند سرویس کلاینت پنل خودشان
// می‌سازند (`PanelSyncer`، `AccessCutoff`، `PanelUserStats`، `TestConfigService`،
// `AdminController`…). اگر بودجه فقط روی یک نمونه می‌نشست، بقیهٔ کلاینت‌های
// **همان درخواست** بدون سقف کار می‌کردند و همان ۱۶۰ ثانیهٔ کشنده برمی‌گشت.

PasarGuardClient::clearProcessBudget();

$fresh1 = new PasarGuardClient('https://panel.invalid.test');
$check('پیش از تعیین سهمیه، نمونهٔ تازه بی‌سقف است',
    $fresh1->remainingSeconds() === INF);

PasarGuardClient::setProcessBudget(45.0);
$fresh2 = new PasarGuardClient('https://panel.invalid.test');
$fresh3 = new PasarGuardClient('https://another.invalid.test');

$check('نمونهٔ تازه سهمیه را ارث برد', $fresh2->remainingSeconds() < 45.0,
    'باقی‌مانده=' . round($fresh2->remainingSeconds(), 1));
$check('و برای هر نمونه جداگانه اعمال می‌شود', $fresh3->remainingSeconds() < 45.0,
    'باقی‌مانده=' . round($fresh3->remainingSeconds(), 1));
$check('و نمونهٔ قبل از تعیین دست‌نخورده ماند', $fresh1->remainingSeconds() === INF);

PasarGuardClient::clearProcessBudget();
$after = new PasarGuardClient('https://panel.invalid.test');
$check('برداشتن سهمیه کار می‌کند', $after->remainingSeconds() === INF);

// مقادیر بی‌معنا
foreach ([0.0, 1.0, 4.9, -5.0] as $bad) {
    PasarGuardClient::setProcessBudget($bad);
    $x = new PasarGuardClient('https://panel.invalid.test');

    $check(sprintf('setProcessBudget(%.1f) نادیده گرفته می‌شود', $bad),
        $x->remainingSeconds() === INF);
}

PasarGuardClient::clearProcessBudget();

// Kernel باید سهمیهٔ کل پروسه را تنظیم کند، نه فقط کلاینت خودش را
$kernel2 = new Pasargad\Bot\Kernel(new FakeBotApi());
$kernel2->beginPanelBudget(30.0);

$serviceClient = new PasarGuardClient('https://service.invalid.test');
$serviceLeft   = $serviceClient->remainingSeconds();

$check('کلاینتِ سرویس‌های دیگر هم سقف دارد',
    $serviceLeft > 0.0 && $serviceLeft <= 30.0,
    'باقی‌مانده=' . round($serviceLeft, 3));

PasarGuardClient::clearProcessBudget();

// =====================================================================
echo "\n▶ Fake هم بودجه را می‌پذیرد (پارادوکسِ نشتی)\n";
// =====================================================================
//
// Fake نباید بودجه را دور بزند، وگرنه تست‌های بالا بی‌معنا می‌شوند.

$fake = new FakePanelClient();
$fake->beginRequest(5.0);
usleep(3_400_000);

$fakeRefused = false;

try {
    $priv($fake, 'assertTimeLeft', '/api/admin');
} catch (PanelException) {
    $fakeRefused = true;
}

$check('Fake هم همان نگهبان را اعمال می‌کند', $fakeRefused);

// =====================================================================
echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);