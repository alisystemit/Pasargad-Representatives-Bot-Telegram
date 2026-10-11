<?php

declare(strict_types=1);

namespace Pasargad\Panel;

use Pasargad\Support\Config;

/**
 * کلاینت ساختگی پنل برای تست‌ها — بدون تماس شبکه.
 *
 * وضعیت ادمین‌ها را در آرایه نگه می‌دارد و رفتار خطای قابل تنظیم دارد
 * تا مسیرهای خطا و تلاش مجدد هم تست شوند.
 *
 * ⚠️ **قانون مهم: کلاینت ساختگی نباید متدی را override کند که والدش
 * مسیرش را می‌سازد.** اگر `getAdmin()` را اینجا بازنویسی کنیم، تست‌ها سبز
 * می‌مانند حتی وقتی کلاینت واقعی دارد `GET /api/admin/{username}` می‌زند که
 * پنل با **405** رد می‌کند (این اتفاق واقعاً افتاد). به‌جای آن فقط
 * «primitive»هایی را پیاده می‌کنیم که کلاینت واقعی رویشان سوار است:
 * `getCurrentAdmin`، `listAdmins`، `listUsers`، `modifyAdmin` و…
 * در نتیجه ساخت URL، انتخاب مسیر و باز کردن پاسخ، همه کد واقعی اجرا می‌شود.
 */
final class FakePanelClient extends PasarGuardClient
{
    /** @var array<string, array<string, mixed>> */
    public array $admins = [];

    public string $loginError = '';
    public string $modifyError = '';
    public string $createUserError = '';
    public string $modifyUserError = '';

    /**
     * اگر مقداری داشته باشد، خطاهای ساختگی با این کد HTTP برگردانده می‌شوند.
     * برای تست رفتار خطاهای غیرقابل تلاش مجدد (مثل ۴۰۴) لازم است.
     */
    public int $httpStatusOverride = 0;

    /**
     * فهرست درخواست‌های ارسال‌شده به پنل — برای بررسی اینکه چند بار
     * فراخوانی انجام شده (مثلاً بررسی عدم اجرای دوباره).
     *
     * @var array<int, array{method:string, path:string}>
     */
    public array $loggedRequests = [];
    public int $modifyCalls = 0;
    public int $loginCalls = 0;

    /** @var array<int, array<string, mixed>> */
    public array $modifyRequests = [];

    /** @var array<int, array<string, mixed>> پنل کاربرانِ ساخته/تغییر‌یافته */
    public array $created = [];
    public array $modified = [];

    /** @var array<string, array<string, mixed>> وضعیت کاربران در پنل قلابی */
    public array $existingUsers = [];

    public function __construct()
    {
        // سازندهٔ والد را صدا نمی‌زنیم چون به شبکه نیاز دارد؛ ولی properties
        // والد (مثل `$baseUrl`) **typed** هستند و اگر مقدار نگیرند، هر کدی که
        // بخواهد بخواندشان با «must not be accessed before initialization» می‌میرد.
        // پس همان مقدارهای پیش‌فرض والد را دستی می‌گذاریم (بدون هیچ شبکه‌ای).
        $this->baseUrl   = Config::str('panel.base_url', 'https://us.api-system.top');
        $this->verifySsl = true;
        $this->timeout   = 5;
    }

    public function login(string $username, string $password, bool $useCache = true): string
    {
        $this->loginCalls++;
        $this->lastAuthUsername = $username;

        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        return 'fake-token-' . $username;
    }

    /**
     * تنها نقطه‌ای که واقعاً به شبکه می‌رود — اینجا بازنویسی می‌شود.
     *
     * ⚠️ عمداً `request()` و `requestWithApiKey()` را بازنویسی **نمی‌کنیم**،
     * چون آن‌ها منطق واقعی را دارند: تشخیص ۴۰۱، تلاش مجدد، و بازگشت به رمز
     * پشتیبان روی ۴۰۳. اگر آن‌ها را بازنویسی کنیم، آن منطق اصلاً اجرا نمی‌شود
     * و تست‌ها در حالی سبز می‌مانند که کد خراب است — دقیقاً همان تله‌ای که
     * بالای همین کلاس نوشته شده.
     *
     * @param array<string, mixed>|null $payload
     * @return array{status:int, data:array<string, mixed>|null, raw:string, error:string}
     */
    protected function sendWithBearer(string $method, string $path, string $token, ?array $payload): array
    {
        $this->loggedRequests[] = ['method' => $method . ' ' . $path, 'path' => $path];
        $this->authHeadersSeen[] = $this->authScheme();

        $ok = fn (array $data): array => [
            'status' => 200, 'data' => $data, 'raw' => '', 'error' => '',
        ];

        $fail = fn (int $status, string $detail): array => [
            'status' => $status, 'data' => ['detail' => $detail], 'raw' => '', 'error' => '',
        ];

        // کلید با طرح ApiKey، بدون مجوز (حالت واقعیِ کلیدهای ناقص).
        //
        // نامِ مجوز عیناً همان چیزی است که پنل واقعی می‌گوید
        // («Permission denied: admins.update») — و ربات باید **همان** را به کاربر
        // نشان دهد. اگر فیک به‌جایش مسیر بدهد، تستِ «نام مجوز نشان داده شد»
        // بی‌معنا می‌شد و یک باگ واقعی از چشم پنهان می‌ماند.
        if ($this->authScheme() === 'ApiKey' && $this->apiKeyForbidden) {
            return $fail(403, 'Permission denied: ' . $this->apiKeyForbiddenScope);
        }

        // اگر فهرست کلیدهای معتبر پر شده باشد، کلید ناشناخته ۴۰۱ می‌گیرد —
        // عیناً پنل واقعی. خالی بودن یعنی «هر کلیدی معتبر است».
        if ($this->authScheme() === 'ApiKey'
            && $this->validApiKeys !== []
            && !in_array($token, $this->validApiKeys, true)
        ) {
            return $fail(401, 'Could not validate credentials');
        }

        if ($this->loginError !== '' && $this->authScheme() === 'Bearer' && str_starts_with($path, '/api/admin/token')) {
            return $fail(401, $this->loginError);
        }

        // مسیرهایی که فقط کلاینت واقعی می‌زند و فیک باید پاسخ بدهد.
        if (str_starts_with($path, '/api/admin-roles')) {
            return $this->loginError !== ''
                ? $fail(401, $this->loginError)
                : $ok(['roles' => array_values($this->roles), 'total' => count($this->roles)]);
        }

        if ($path === '/api/admin' && $method === 'GET') {
            return $this->currentAdminResponse($fail, $token);
        }

        if (str_starts_with($path, '/api/admins')) {
            return $this->loginError !== ''
                ? $fail(401, $this->loginError)
                : $this->listAdmins('', '', $this->queryOf($path));
        }

        if (str_starts_with($path, '/api/users')) {
            return $this->listUsers('', '', $this->queryOf($path));
        }

        return $ok([]);
    }

    /**
     * @param  callable(array<string,mixed>): array<string,mixed> $fail
     * @return array{status:int, data:array<string, mixed>|null, raw:string, error:string}
     */
    private function currentAdminResponse(callable $fail, string $token = ''): array
    {
        if ($this->loginError !== '') {
            return $fail(401, $this->loginError);
        }

        if ($this->authScheme() === 'ApiKey') {
            return [
                'status' => 200, 'data' => $this->apiKeyOwner($token),
                'raw' => '', 'error' => '',
            ];
        }

        // مسیر رمز: کلاینت واقعی با GET /api/admin هر ادمینی را که *خودش*
        // هست برمی‌گرداند، پس فیک هم همین را می‌کند.
        $username = $this->lastAuthUsername;

        if ($username !== '' && isset($this->admins[$username])) {
            return ['status' => 200, 'data' => $this->admins[$username], 'raw' => '', 'error' => ''];
        }

        $first = reset($this->admins);

        return is_array($first)
            ? ['status' => 200, 'data' => $first, 'raw' => '', 'error' => '']
            : $fail(404, 'Admin not found');
    }

    /** نام کاربری‌ای که آخرین بار برای احراز هویت استفاده شد. */
    public string $lastAuthUsername = '';

    /**
     * @return array<string, mixed>
     */
    private function queryOf(string $path): array
    {
        $query = [];

        if (str_contains($path, '?')) {
            parse_str((string) substr($path, strpos($path, '?') + 1), $query);
        }

        return $query;
    }

    /**
     * ادمینی که کلید API به او تعلق دارد.
     *
     * روی پنل واقعی، `GET /api/admin` با کلید، ادمینِ متناظر با همان کلید را
     * برمی‌گرداند (کلید به یک حساب وصل است).
     *
     * @return array<string, mixed>
     */
    public function apiKeyOwner(string $token = ''): array
    {
        // ⭐️ اول از روی **خودِ کلید** نگاه می‌کنیم، نه از ترتیب اضافه‌شدن ادمین‌ها.
        //
        // روی پنل واقعی هر کلید به یک حساب وصل است، پس `GET /api/admin` ادمینِ
        // همان کلید را برمی‌گرداند. اگر اینجا «اولین ادمین» برگردانده شود،
        // شارژ پنلِ کاربر دوم روی سقفِ پنلِ کاربر اول اعمال می‌شود — یعنی یک
        // تست سبز و رفتار کاملاً غلط در محیط واقعی. (این دقیقاً همان تلهٔ
        // «تست‌دوبل» است که دو بار امروز گرفتیم.)
        if ($token !== '' && isset($this->apiKeyOwners[$token])) {
            $bound = $this->apiKeyOwners[$token];

            if (isset($this->admins[$bound])) {
                return $this->admins[$bound];
            }
        }

        $username = $this->apiKeyUsername;

        if ($username === '') {
            $first    = reset($this->admins);
            $username = is_array($first) ? (string) ($first['username'] ?? '') : '';
        }

        if ($username !== '' && isset($this->admins[$username])) {
            return $this->admins[$username];
        }

        $first = reset($this->admins);

        return is_array($first) ? $first : ['username' => 'api', 'status' => 'active'];
    }

    /**
     * کلید → نام ادمینی که آن کلید متعلق به اوست.
     *
     * @var array<string, string>
     */
    public array $apiKeyOwners = [];

    /** اتصال یک کلید به یک ادمین (همان کاری که خودِ پنل انجام می‌دهد). */
    public function bindApiKey(string $apiKey, string $panelUsername): void
    {
        if ($apiKey === '' || $panelUsername === '') {
            return;
        }

        $this->apiKeyOwners[$apiKey] = $panelUsername;

        // کلیدِ وصل‌شده خودبه‌خود معتبر است. بدون این، هر تستی که
        // `validApiKeys` را دستی پر کرده باید کلیدهای fixture را هم دستی
        // اضافه کند و یکی فراموش شود ⇒ ۴۰۱ و شکستِ بی‌ربط.
        if ($this->validApiKeys !== [] && !in_array($apiKey, $this->validApiKeys, true)) {
            $this->validApiKeys[] = $apiKey;
        }
    }

    /** نام ادمینی که کلید API به او تعلق دارد (خالی = اولین ادمین). */
    public string $apiKeyUsername = '';

    /**
     * اگر true باشد، کلید API برای **هر** عملیاتی ۴۰۳ می‌گیرد.
     *
     * برای شبیه‌سازی دقیق کلیدهای واقعیِ ناقص‌مجوز لازم است — روی پنل
     * واقعی اندازه گرفته شد که کلید می‌تواند بخواند و بسازد ولی
     * `admins.update` را ندارد، پس شارژ پنل ۴۰۳ می‌دهد.
     *
     * مسیر رمز (نام کاربری + رمز) در این حالت همچنان کار می‌کند، چون برای
     * رمز چنین محدودیتی وجود ندارد.
     */
    public bool $apiKeyForbidden = false;

    /**
     * نام مجوزی که فیک برای ۴۰۳ برمی‌گرداند.
     *
     * پیش‌فرض `admins.update` چون همان چیزی است که روی پنل واقعی اندازه گرفته
     * شد و شارژ/تمدید را از کار می‌اندازد.
     */
    public string $apiKeyForbiddenScope = 'admins.update';

    /**
     * کلیدهای معتبر. خالی = هر کلیدی معتبر است.
     *
     * لازم است تا تست بتواند «کلید اشتباه» را واقعاً شبیه‌سازی کند؛ بدون آن
     * هر رشته‌ای کلید معتبر شمرده می‌شد و تستِ «کلید غلط چیزی ذخیره نکند»
     * بی‌معنا می‌شد.
     *
     * @var array<int, string>
     */
    public array $validApiKeys = [];

    /** @var array<int, string> طرح هدری که هر درخواست با آن رفته */
    public array $authHeadersSeen = [];

    /**
     * ادمین جاری — معادل `GET /api/admin`.
     *
     * پاسخ یک رکورد تنهاست (نه لیست)، عیناً مثل پنل واقعی؛ پس اگر کلاینت واقعی
     * روزی مسیر اشتباه بزند یا پاسخ را اشتباه باز کند، همین‌جا لو می‌رود.
     *
     * @return array<string, mixed>
     */
    /**
     * ساخت کلید API برای یک ادمین (POST /api/api_key).
     *
     * ⭐️ بازنویسی **لازم** است: کلاینت واقعی از `requestWithApiKey()` استفاده
     * می‌کند که مستقیم به `sendWithBearer` می‌زند. پس اگر اینجا بازنویسی
     * نشود، ساخت کلید به شبکهٔ واقعی می‌رود و همهٔ تست‌های خرید پنل می‌میرند.
     *
     * @param  array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    public function requestWithApiKey(string $method, string $path, string $apiKey, ?array $payload = null): array
    {
        if ($path === '/api/api_key' && $method === 'POST') {
            $this->loggedRequests[] = ['method' => 'createApiKey', 'path' => $path];

            if ($this->apiKeyForbidden) {
                throw new PanelException('Permission denied: api_keys.create', 403);
            }

            $adminId = (int) ($payload['admin_id'] ?? 0);

            // کلید ساختگی ولی قطعی — دو بار ساخته شود فرق کند، پس تست‌ها
            // می‌توانند بفهمند واقعاً کلید تازه صادر شده.
            $issued = 'pg_key_issued_' . $adminId . '_' . (count($this->issuedKeys) + 1);

            // روی پنل واقعی، کلید تازه به همان `admin_id` وصل می‌شود. بدون این
            // اتصال، `GET /api/admin` با کلید تازه ادمینِ اشتباهی برمی‌گرداند
            // و تست‌های بعدی (شارژ، آمار، قطع دسترسی) روی پنل غلط اجرا می‌شوند.
            $owner = '';

            foreach ($this->admins as $name => $row) {
                if ((int) ($row['id'] ?? 0) === $adminId) {
                    $owner = (string) $name;
                    break;
                }
            }

            if ($owner !== '') {
                $this->bindApiKey($issued, $owner);
            }

            $this->issuedKeys[] = ['admin_id' => $adminId, 'api_key' => $issued, 'name' => $payload['name'] ?? 'bot'];

            return [
                'id'         => count($this->issuedKeys) + 900,
                'admin_id'   => $adminId,
                'name'       => (string) ($payload['name'] ?? 'bot'),
                'api_key'    => $issued,
                'status'     => 'active',
            ];
        }

        return parent::requestWithApiKey($method, $path, $apiKey, $payload);
    }

    /** @var array<int, array<string, mixed>> کلیدهای صادرشده */
    public array $issuedKeys = [];

    public function getCurrentAdmin(string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'getCurrentAdmin', 'path' => '/api/admin'];

        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        if (!isset($this->admins[$username])) {
            throw new PanelException('Admin not found', 404);
        }

        return $this->admins[$username];
    }

    /**
     * لیست ادمین‌ها — معادل `GET /api/admins` با فیلترهای واقعی.
     *
     * شکل پاسخ `{admins, total, active, disabled, limited}` عیناً از اسپک پنل
     * گرفته شده تا `findAdminRow()` در کلاینت واقعی روی دادهٔ درست تست شود.
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listAdmins(string $username, string $password, array $query = []): array
    {
        $this->loggedRequests[] = ['method' => 'listAdmins', 'path' => '/api/admins'];

        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        // اپراتور عادی فقط خودش را می‌بیند؛ اکانت sudo همه را. این تفاوت مهم
        // است: مسیر «بازیابی پنل پس از خطای ذخیره» در AgencyService با **اکانت
        // مالک** صدا زده می‌شود و باید بتواند ادمین دیگری را ببیند.
        $isSudo = $this->ownerUsername !== '' && $username === $this->ownerUsername;

        $all = array_values($this->admins);

        if (!$isSudo) {
            $all = array_values(array_filter(
                $all,
                static fn (array $a): bool => (string) ($a['username'] ?? '') === $username
            ));
        }

        foreach (['username', 'usernames'] as $key) {
            if (empty($query[$key])) {
                continue;
            }

            $wanted = array_map('strtolower', array_map('strval', (array) $query[$key]));
            $all    = array_values(array_filter(
                $all,
                static fn (array $a): bool => in_array(strtolower((string) ($a['username'] ?? '')), $wanted, true)
            ));
        }

        if (!empty($query['ids'])) {
            $wanted = array_map('intval', (array) $query['ids']);
            $all    = array_values(array_filter(
                $all,
                static fn (array $a): bool => in_array((int) ($a['id'] ?? 0), $wanted, true)
            ));
        }

        $isOff = static fn (array $a): bool => (string) ($a['status'] ?? '') === 'disabled';

        return [
            'admins'   => array_slice(
                $all,
                max(0, (int) ($query['offset'] ?? 0)),
                max(1, (int) ($query['limit'] ?? 50))
            ),
            'total'    => count($all),
            'active'   => count(array_filter($all, static fn (array $a): bool => !$isOff($a))),
            'disabled' => count(array_filter($all, $isOff)),
            'limited'  => count(array_filter(
                $all,
                static fn (array $a): bool => (string) ($a['status'] ?? '') === 'limited'
            )),
        ];
    }

    /**
     * نام کاربری اکانتی که در فیک «sudo» فرض می‌شود (همهٔ ادمین‌ها را می‌بیند).
     */
    public string $ownerUsername = '';

    /**
     * مسیر انتخابی **به والد سپرده می‌شود** تا کد واقعی اجرا شود.
     *
     * نسخهٔ قبلی این متد را کامل بازنویسی می‌کرد و مستقیم `admins[$target]`
     * برمی‌گرداند؛ نتیجه: تست‌ها سبز بودند در حالی که کلاینت واقعی داشت
     * `GET /api/admin/{username}` می‌زد که پنل با ۴۰۵ رد می‌کرد.
     */
    public function getAdmin(string $targetUsername, string $username, string $password): array
    {
        $this->loggedRequests[] = [
            'method' => 'getAdmin',
            'path'   => strcasecmp(trim($targetUsername), trim($username)) === 0
                ? '/api/admin'
                : '/api/admins?username=' . rawurlencode(trim($targetUsername)),
        ];

        return parent::getAdmin($targetUsername, $username, $password);
    }

    public function modifyAdmin(string $targetUsername, array $payload, string $username, string $password): array
    {
        $this->modifyCalls++;
        $this->modifyRequests[] = $payload;
        $this->loggedRequests[] = ['method' => 'modifyAdmin', 'path' => '/api/admin/' . $targetUsername];

        if ($this->modifyError !== '') {
            throw new PanelException($this->modifyError, $this->httpStatusOverride ?: 500);
        }

        if (!isset($this->admins[$targetUsername])) {
            throw new PanelException('Admin not found', 404);
        }

        if (isset($payload['data_limit'])) {
            $this->admins[$targetUsername]['data_limit'] = (int) $payload['data_limit'];
        }

        return $this->admins[$targetUsername];
    }

    // ------------------------------------------------------------------
    // کاربران (برای تست اعتبار ساخت کاربر)
    // ------------------------------------------------------------------

    public function createUser(array $payload, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'createUser', 'path' => '/api/user'];

        if ($this->createUserError !== '') {
            throw new PanelException($this->createUserError, $this->httpStatusOverride ?: 500);
        }

        $this->created[] = $payload;

        $this->existingUsers[(string) ($payload['username'] ?? '')] = array_merge([
            'status'       => 'active',
            'used_traffic' => 0,
            'data_limit'   => 0,
            'expire'       => 0,
        ], $payload);

        return $this->existingUsers[(string) $payload['username']];
    }

    /**
 * غیرفعال کردن یک کاربر — دو مسیر متفاوت، عیناً مثل کلاینت واقعی.
 *
 * نکتهٔ مهم این پیاده‌سازی: مسیر `/disabled` فقط `status` را عوض می‌کند و
 * **هیچ فیلد دیگری را پاک نمی‌کند**. این تفاوت عمدی است: اگر روزی مسیر
 * اشتباه (`PUT /api/user/{username}`) برگردد، اینجا با از دست رفتن
 * `data_limit` لو می‌رود — که همان چیزی است که در پنل واقعی ممکن است اتفاق
 * بیفتد.
 *
 * @param array<string, mixed> $payload
 */
public function disableUser(string $targetUsername, string $username, string $password, array $payload = []): array
{
    $target = trim($targetUsername);

    if ($target === '') {
        throw new PanelException('نام کاربری پنل مشخص نشده است.', 404);
    }

    $status = (string) ($payload['status'] ?? '');

    if ($status !== '' && $status !== 'disabled') {
        // مسیر عمومی: کل رکورد با همهٔ فیلدهای داده‌شده بازنویسی می‌شود.
        $this->loggedRequests[] = ['method' => 'disableUser', 'path' => '/api/user/' . $target];

        return $this->modifyUser($target, array_merge(['status' => $status], $payload), $username, $password);
    }

    $this->loggedRequests[] = ['method' => 'disableUser', 'path' => '/api/user/' . $target . '/disabled'];

    if ($this->loginError !== '') {
        throw new PanelException($this->loginError, 401);
    }

    if (!isset($this->existingUsers[$target])) {
        throw new PanelException('Not found', 404);
    }

    // فقط status تغییر می‌کند — data_limit و expire دست‌نخورده می‌مانند.
    $this->existingUsers[$target]['status'] = 'disabled';

    return $this->existingUsers[$target];
}

public function modifyUser(string $targetUsername, array $payload, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'modifyUser', 'path' => '/api/user/' . $targetUsername];

        if ($this->modifyUserError !== '') {
            throw new PanelException($this->modifyUserError, $this->httpStatusOverride ?: 500);
        }

        $this->modified[] = array_merge(['username' => $targetUsername], $payload);

        $this->existingUsers[$targetUsername] = array_merge(
            $this->existingUsers[$targetUsername] ?? [],
            $payload
        );

        return $this->existingUsers[$targetUsername];
    }

    public function getUser(string $targetUsername, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'getUser', 'path' => '/api/user/' . $targetUsername];

        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        if (!isset($this->existingUsers[$targetUsername])) {
            throw new PanelException('Not found', 404);
        }

        return $this->existingUsers[$targetUsername];
    }

    public function listUsers(string $username, string $password, array $query = []): array
    {
        $this->loggedRequests[] = ['method' => 'listUsers', 'path' => '/api/users'];

        // ------------------------------------------------------------------
        // خطای ورود باید روی همهٔ مسیرهای احراز‌شده اعمال شود.
        //
        // کلاینت واقعی در send() اول login() می‌زند و خطای ۴۰۱/۴۰۳ همان‌جا
        // پرتاب می‌شود. اگر اینجا شبیه‌سازی نشود، تست‌های «حساب اپراتور روی
        // پنل غیرفعال شده» ناخواسته **موفق** می‌شوند و مسیر خطایی که دقیقاً
        // برایش نوشته شده اصلاً اجرا نمی‌شود.
        // ------------------------------------------------------------------
        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        $limit  = max(1, (int) ($query['limit'] ?? 50));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $all = array_values($this->existingUsers);

        // فیلتر وضعیت مثل API واقعی (رشته یا آرایه). فیلتر admin در فیک
        // نادیده گرفته می‌شود چون مالکیت کاربران در فیک ثبت نیست.
        if (isset($query['status']) && $query['status'] !== '' && $query['status'] !== null) {
            $wanted = array_map('strtolower', array_map('strval', (array) $query['status']));
            $all    = array_values(array_filter(
                $all,
                static fn (array $u): bool => in_array(strtolower((string) ($u['status'] ?? '')), $wanted, true)
            ));
        }

        $page = array_slice($all, $offset, $limit);

        return [
            'users' => $page,
            'total' => count($all),
        ];
    }

    /**
     * شمارش کاربران (شبیه‌سازی countUsers واقعی: همان فیلتر listUsers).
     */
    public function countUsers(string $username, string $password, array $statuses = [], ?string $admin = null): ?int
    {
        if ($this->loginError !== '') {
            return null;
        }

        $query = ['limit' => 1, 'offset' => 0];

        if ($statuses !== []) {
            $query['status'] = $statuses;
        }

        $response = $this->listUsers($username, $password, $query);

        return isset($response['total']) && is_numeric($response['total'])
            ? (int) $response['total']
            : null;
    }

    /**
     * غیرفعال‌سازی یک‌جای کاربران (شبیه‌سازی POST .../users/disable).
     *
     * @return array<string, mixed>
     */
    public function disablePanelUsers(string $targetUsername, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'disablePanelUsers', 'path' => '/api/admin/' . $targetUsername . '/users/disable'];

        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        foreach ($this->existingUsers as $name => $user) {
            if (!in_array((string) ($user['status'] ?? ''), ['disabled', 'expired', 'on_hold'], true)) {
                $this->existingUsers[$name]['status'] = 'disabled';
            }
        }

        // پنل واقعی بدنهٔ شمارشی برنمی‌گرداند؛ پس اینجا هم خالی.
        return [];
    }

    /**
     * فهرست نقش‌ها (شبیه‌سازی GET /api/admin-roles).
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listRoles(string $username, string $password, array $query = []): array
    {
        $this->loggedRequests[] = ['method' => 'listRoles', 'path' => '/api/admin-roles'];

        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        $roles = array_values($this->roles);

        return ['roles' => $roles, 'total' => count($roles)];
    }

    /**
     * ثبت یک ادمین ساختگی.
     *
     * @param array<string, mixed> $overrides
     */
    public function addAdmin(string $username, array $overrides = []): void
    {
        $this->admins[$username] = array_merge([
            'id'            => 1,
            'username'      => $username,
            'status'        => 'active',
            'data_limit'    => 0,
            'used_traffic'  => 0,
            'role'          => ['id' => 1, 'name' => 'admin', 'is_owner' => false],
            'is_limited'    => false,
            'is_disabled'   => false,
        ], $overrides);
    }

    /**
     * ثبت یک کاربر end-user ساختگی.
     *
     * لازم است چون `listUsers` از `existingUsers` می‌خواند نه از `admins`:
     * این دو در پنل واقعی هم دو موجودیت جدا هستند (ادمین = اپراتور،
     * کاربر = مشتریِ آن اپراتور).
     *
     * @param array<string, mixed> $overrides
     */
    public function addUser(string $username, array $overrides = []): void
    {
        $this->existingUsers[$username] = array_merge([
            'id'           => count($this->existingUsers) + 1,
            'username'     => $username,
            'status'       => 'active',
            'data_limit'   => 1073741824,
            'used_traffic' => 0,
        ], $overrides);
    }

    /**
     * پاک کردن همهٔ کاربران end-user (برای سناریوهایی که لیست خالی می‌خواهند).
     */
    public function clearUsers(): void
    {
        $this->existingUsers = [];
    }

    // ------------------------------------------------------------------
    // ساخت ادمین (برای خرید «پنل نمایندگی»)
    // ------------------------------------------------------------------

    public string $createAdminError = '';

    /** @var array<int, array<string, mixed>> ادمین‌های ساخته‌شده */
    public array $createdAdmins = [];

    /** @var array<int, array<string, mixed>> نقش‌های موجود پنل */
    public array $roles = [
        1 => ['id' => 1, 'name' => 'admin', 'is_owner' => true],
        2 => ['id' => 2, 'name' => 'operator', 'is_owner' => false],
    ];

    public function createAdmin(array $payload, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'createAdmin', 'path' => '/api/admin'];
        $this->createdAdmins[]   = array_merge(['owner' => $username], $payload);

        if ($this->createAdminError !== '') {
            throw new PanelException($this->createAdminError, $this->httpStatusOverride ?: 500);
        }

        $target = (string) ($payload['username'] ?? '');

        if ($target === '') {
            throw new PanelException('username is required', 422);
        }

        // مثل API واقعی: role_id اجباری است (اسپک AdminCreate).
        if (!isset($payload['role_id']) || !is_numeric($payload['role_id']) || (int) $payload['role_id'] <= 0) {
            throw new PanelException('role_id is required', 422);
        }

        // سیاست رمز پنل واقعی (که با آزمون زنده کشف شد): دست‌کم ۲ حرف بزرگ.
        //
        // این بازرسی عمداً سخت‌گیر است. نسخهٔ قبلی `generatePassword()` فقط یک
        // حرف بزرگ تضمین می‌کرد و روی پنل واقعی همهٔ خریدهای «پنل نمایندگی»
        // با ۴۲۲ می‌افتادند — ولی چون فیک این قاعده را نداشت، تست‌ها سبز
        // می‌ماندند. دقیقاً همان نوع باگی که این فایل جلویش را می‌گیرد.
        $password = (string) ($payload['password'] ?? '');

        if (preg_match_all('/[A-Z]/', $password) < 2) {
            throw new PanelException(
                'Value error, Password must contain at least 2 uppercase letters',
                422
            );
        }

        if (preg_match_all('/\d/', $password) < 2) {
            throw new PanelException('Value error, Password must contain at least 2 digits', 422);
        }

        // پنل واقعی روی نام کاربری تکراری خطای ۴۰۹ می‌دهد.
        if (isset($this->admins[$target])) {
            throw new PanelException('admin already exists', 409);
        }

        $role = $this->roles[(int) ($payload['role_id'] ?? 1)]
            ?? ['id' => $payload['role_id'] ?? 1, 'name' => 'operator', 'is_owner' => false];

        $this->addAdmin($target, [
            'id'         => count($this->admins) + 100,
            'data_limit' => (int) ($payload['data_limit'] ?? 0),
            'password'   => $payload['password'] ?? null,
            'note'       => $payload['note'] ?? null,
            'role'       => $role,
        ]);

        return $this->admins[$target];
    }

    public function deleteAdmin(string $targetUsername, string $username, string $password): array
    {
        unset($this->admins[$targetUsername]);

        return ['ok' => true];
    }

    /**
     * ثبت یک نقش قابل استفاده در payload ساخت ادمین.
     *
     * @param array<string, mixed> $role
     */
    public function addRole(int $id, array $role): void
    {
        $this->roles[$id] = $role;
    }
}