<?php

declare(strict_types=1);

namespace Pasargad\Panel;

use Pasargad\Support\Config;
use Pasargad\Support\Http;
use Pasargad\Support\Logger;

/**
 * کلاینت REST پنل PasarGuard (نسخهٔ ۵).
 *
 * احراز هویت با POST /api/admin/token (نام کاربری + رمز → access_token).
 * برای درخواست‌های بعدی هدر Authorization: Bearer استفاده می‌شود و در
 * صورت گرفتن 401، توکن یک‌بار دیگر ساخته و درخواست تکرار می‌گردد.
 */
class PasarGuardClient
{
    private string $baseUrl;
    private bool $verifySsl;
    private int $timeout;

    /** @var array<string, string> کش توکن: کلید md5(baseUrl|username) */
    private array $tokenCache = [];

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl   = rtrim($baseUrl ?? Config::str('panel.base_url', 'https://us.api-system.top'), '/');
        $this->verifySsl = Config::bool('panel.verify_ssl', true);
        $this->timeout   = Config::int('panel.timeout', 20);

        if ($this->baseUrl === '') {
            throw new PanelException('آدرس پنل در تنظیمات تعریف نشده است (panel.base_url).');
        }
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // ------------------------------------------------------------------
    // احراز هویت
    // ------------------------------------------------------------------

    /**
     * دریافت access_token با نام کاربری و رمز عبور.
     */
    public function login(string $username, string $password, bool $useCache = true): string
    {
        $username = trim($username);
        $cacheKey = $this->tokenCacheKey($username);

        if ($useCache && isset($this->tokenCache[$cacheKey])) {
            return $this->tokenCache[$cacheKey];
        }

        $response = Http::json('POST', $this->baseUrl . '/api/admin/token', null, [
            'form' => [
                'username'   => $username,
                'password'   => $password,
                'grant_type' => 'password',
            ],
            'headers'  => ['Accept: application/json'],
            'timeout'  => $this->timeout,
            'verify_ssl' => $this->verifySsl,
        ]);

        $token = $response['data']['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            // علامت‌گذاری: این خطا از مسیر صدور توکن آمده، پس ۴۰۳ اینجا
            // واقعاً یعنی اطلاعات ورود غلط است (برخلاف ۴۰۳ روی مسیرهای
            // عملیاتی که فقط یعنی «نقش کاربر اجازه ندارد»).
            $exception = $this->toException(
                $response,
                $this->authErrorMessage($response, 'ورود به پنل ناموفق بود. نام کاربری یا رمز عبور را بررسی کنید.')
            );

            throw new PanelException(
                $exception->getMessage(),
                $exception->httpStatus(),
                $exception->payload(),
                $exception,
                true   // onTokenEndpoint
            );
        }

        $this->tokenCache[$cacheKey] = $token;

        Logger::info('Panel login successful', ['username' => $username]);

        return $token;
    }

    public function forgetToken(string $username): void
    {
        unset($this->tokenCache[$this->tokenCacheKey($username)]);
    }

    /**
     * تست اتصال پنل (برای صفحهٔ سلامت یا نصب).
     *
     * @return array{ok:bool, message:string, data:array<string, mixed>|null}
     */
    public function testConnection(string $username, string $password): array
    {
        try {
            $this->login($username, $password, false);
            $info = $this->getCurrentAdmin($username, $password);

            return [
                'ok'      => true,
                'message' => 'اتصال به پنل برقرار شد.',
                'data'    => $info,
            ];
        } catch (PanelException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    // ------------------------------------------------------------------
    // اطلاعات ادمین
    // ------------------------------------------------------------------

    /**
     * اطلاعات ادمین جاری (GET /api/admin).
     *
     * @return array<string, mixed>
     */
    public function getCurrentAdmin(string $username, string $password): array
    {
        return $this->request('GET', '/api/admin', $username, $password);
    }

    /**
     * اطلاعات یک ادمین مشخص (GET /api/admin/{username}).
     *
     * @return array<string, mixed>
     */
    public function getAdmin(string $targetUsername, string $username, string $password): array
    {
        return $this->request(
            'GET',
            '/api/admin/' . rawurlencode($targetUsername),
            $username,
            $password
        );
    }

    /**
     * اطلاعات ادمین بر اساس شناسهٔ عددی (GET /api/admin/by-id/{admin_id}).
     *
     * @return array<string, mixed>
     */
    public function getAdminById(int $adminId, string $username, string $password): array
    {
        return $this->request('GET', '/api/admin/by-id/' . $adminId, $username, $password);
    }

    /**
     * لیست ادمین‌ها (برای ابرادمین/سوپرادمین پنل).
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listAdmins(string $username, string $password, array $query = []): array
    {
        $query = array_merge(['offset' => 0, 'limit' => 50], $query);

        return $this->request('GET', '/api/admins?' . http_build_query($query), $username, $password);
    }

    /**
     * ویرایش ادمین (PUT /api/admin/{username}).
     *
     * @param  array<string, mixed> $payload فیلدهای AdminModify
     * @return array<string, mixed>
     */
    public function modifyAdmin(string $targetUsername, array $payload, string $username, string $password): array
    {
        return $this->request(
            'PUT',
            '/api/admin/' . rawurlencode($targetUsername),
            $username,
            $password,
            $payload
        );
    }

    /**
     * صفر کردن مصرف ادمین (POST /api/admin/{username}/reset).
     */
    public function resetAdminUsage(string $targetUsername, string $username, string $password): array
    {
        return $this->request(
            'POST',
            '/api/admin/' . rawurlencode($targetUsername) . '/reset',
            $username,
            $password
        );
    }

    /**
     * آمار مصرف ادمین (GET /api/admin/{username}/usage).
     *
     * @param array<string, mixed> $query
     */
    public function getAdminUsage(string $targetUsername, string $username, string $password, array $query = []): array
    {
        $query = array_merge(['period' => 'day'], $query);

        return $this->request(
            'GET',
            '/api/admin/' . rawurlencode($targetUsername) . '/usage?' . http_build_query($query),
            $username,
            $password
        );
    }

    // ------------------------------------------------------------------
    // کاربران (برای ساخت کاربر از اعتبار خریداری‌شده)
    // ------------------------------------------------------------------

    /**
     * ساخت کاربر جدید (POST /api/user).
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createUser(array $payload, string $username, string $password): array
    {
        return $this->request('POST', '/api/user', $username, $password, $payload);
    }

    /**
     * ویرایش کاربر (PUT /api/user/{username}).
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function modifyUser(string $targetUsername, array $payload, string $username, string $password): array
    {
        return $this->request(
            'PUT',
            '/api/user/' . rawurlencode($targetUsername),
            $username,
            $password,
            $payload
        );
    }

    /**
     * اطلاعات کاربر (GET /api/user/{username}).
     *
     * @return array<string, mixed>
     */
    public function getUser(string $targetUsername, string $username, string $password): array
    {
        return $this->request(
            'GET',
            '/api/user/' . rawurlencode($targetUsername),
            $username,
            $password
        );
    }

    /**
     * لیست کاربران با فیلتر اختیاری.
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listUsers(string $username, string $password, array $query = []): array
    {
        $query = array_merge(['offset' => 0, 'limit' => 50], $query);

        return $this->request('GET', '/api/users?' . http_build_query($query), $username, $password);
    }

    /**
     * صفر کردن مصرف کاربر (POST /api/user/{username}/reset).
     */
    public function resetUserUsage(string $targetUsername, string $username, string $password): array
    {
        return $this->request(
            'POST',
            '/api/user/' . rawurlencode($targetUsername) . '/reset',
            $username,
            $password
        );
    }

    // ------------------------------------------------------------------
    // سیستم
    // ------------------------------------------------------------------

    /**
     * آمار کلی سیستم (GET /api/system).
     *
     * @return array<string, mixed>
     */
    public function getSystemStats(string $username, string $password): array
    {
        return $this->request('GET', '/api/system', $username, $password);
    }

    /**
     * بررسی سلامت سرویس (GET /health) — بدون نیاز به احراز هویت.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $response = Http::json('GET', $this->baseUrl . '/health', null, [
            'timeout'     => min($this->timeout, 10),
            'verify_ssl'  => $this->verifySsl,
        ]);

        if ($response['error'] !== '' || $response['status'] >= 400) {
            throw $this->toException($response, 'پنل در دسترس نیست.');
        }

        return is_array($response['data']) ? $response['data'] : [];
    }

    // ------------------------------------------------------------------
    // لایهٔ داخلی درخواست
    // ------------------------------------------------------------------

    /**
     * ارسال درخواست با توکن و تلاش مجدد خودکار در صورت 401.
     *
     * @param  array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, string $username, string $password, ?array $payload = null): array
    {
        $response = $this->send($method, $path, $username, $password, $payload);

        if ($response['status'] === 401) {
            Logger::info('Panel token expired, re-login', ['path' => $path, 'username' => $username]);
            $this->forgetToken($username);
            $response = $this->send($method, $path, $username, $password, $payload);
        }

        if ($response['error'] !== '' || $response['status'] === 0) {
            Logger::error('Panel request failed', [
                'method'   => $method,
                'path'     => $path,
                'status'   => $response['status'],
                'error'    => $response['error'],
            ]);

            throw $this->toException($response, 'ارتباط با پنل برقرار نشد. کمی بعد دوباره تلاش کنید.');
        }

        if ($response['status'] >= 400) {
            Logger::warning('Panel returned an error', [
                'method' => $method,
                'path'   => $path,
                'status' => $response['status'],
            ]);

            throw $this->toException($response, $this->httpErrorMessage($response));
        }

        $data = $response['data'];
        if (!is_array($data)) {
            // بعضی endpointها (مثل reset) پاسخ خالی برمی‌گردانند.
            return ['ok' => true];
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, data:array<string, mixed>|null, raw:string, error:string}
     */
    private function send(string $method, string $path, string $username, string $password, ?array $payload): array
    {
        $token    = $this->login($username, $password);
        $url      = $this->baseUrl . $path;
        $headers  = [
            'accept: application/json',
            'authorization: Bearer ' . $token,
        ];

        $started = microtime(true);
        $response = Http::json($method, $url, $payload, [
            'headers'     => $headers,
            'timeout'     => $this->timeout,
            'verify_ssl'  => $this->verifySsl,
            'attempts'    => 2,
        ]);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        Logger::debug('Panel request', [
            'method'   => $method,
            'path'     => $path,
            'status'   => $response['status'],
            'ms'       => $elapsed,
        ]);

        return $response;
    }

    /**
     * تبدیل پاسخ خطا به استثنا با پیام فارسی.
     *
     * پیام خام پنل انگلیسی است و نباید مستقیم به کاربر نمایش داده شود؛
     * برای خطاهای شناخته‌شده پیام فارسی جایگزین می‌گردد و برای خطاهای
     * اعتبارسنجی (422) جزئیات فنی نگه داشته می‌شود چون به رفع مشکل کمک می‌کند.
     *
     * @param array{status:int, data:array<string, mixed>|null, raw:string, error:string} $response
     */
    private function toException(array $response, string $fallback): PanelException
    {
        $detail = $this->detailOf($response);

        return new PanelException(
            $detail === null ? $fallback : $this->translate($detail, $response['status'], $fallback),
            $response['status'],
            $response['data']
        );
    }

    /**
     * استخراج جزئیات خطا از بدنهٔ پاسخ پنل (detail یا message).
     *
     * @param array{status:int, data:array<string, mixed>|null, raw:string, error:string} $response
     */
    private function detailOf(array $response): ?string
    {
        $data = $response['data'];
        if (!is_array($data)) {
            return null;
        }

        $detail = $data['detail'] ?? $data['message'] ?? null;

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        if (is_array($detail)) {
            $parts = [];
            foreach ($detail as $messages) {
                $parts[] = is_array($messages)
                    ? implode('، ', array_map('strval', $messages))
                    : (string) $messages;
            }

            if ($parts !== []) {
                return implode(' | ', $parts);
            }
        }

        return null;
    }

    /**
     * قوانین ترجمهٔ پیام‌های پنل به فارسی.
     *
     * @var array<int, array{0: array<int, string>, 1: string}>
     */
    private const MESSAGE_RULES = [
        [['incorrect', 'invalid credential', 'username', 'password', 'bad credentials'], 'نام کاربری یا رمز عبور اشتباه است.'],
        [['unauthorized', 'not authenticated', 'token expired'], 'نشست شما منقضی شده است. دوباره وارد شوید.'],
        [['forbidden', 'permission', 'not allowed'], 'دسترسی لازم برای این عملیات را ندارید.'],
        [['not found', 'does not exist', 'no such'], 'موردی با این مشخصات پیدا نشد.'],
        [['already exists', 'duplicate', 'is taken'], 'این مورد از قبل وجود دارد.'],
        [['conflict'], 'این عملیات با وضعیت فعلی تداخل دارد.'],
        [['disabled'], 'این حساب غیرفعال است.'],
        [['limited'], 'حساب شما به دلیل اتمام حجم محدود شده است.'],
        [['expired'], 'اعتبار این حساب به پایان رسیده است.'],
        [['rate limit', 'too many requests'], 'درخواست‌های زیادی ارسال شده است؛ کمی بعد تلاش کنید.'],
        [['internal server error', 'server error'], 'خطای داخلی سرور پنل. کمی بعد دوباره تلاش کنید.'],
        [['bad gateway', 'service unavailable', 'gateway timeout', 'unreachable'], 'پنل موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.'],
        [['connection', 'network', 'resolve host', 'timed out'], 'ارتباط با سرور پنل برقرار نشد. اینترنت یا تنظیمات را بررسی کنید.'],
    ];

    /**
     * تبدیل پیام انگلیسی پنل به پیام فارسی قابل فهم برای کاربر.
     */
    private function translate(string $detail, int $status, string $fallback): string
    {
        // اگر پیام از قبل فارسی باشد، دست‌نخورده می‌ماند.
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $detail) === 1) {
            return $detail;
        }

        // خطاهای اعتبارسنجی: جزئیات فنی نگه داشته می‌شود ولی با مقدمهٔ فارسی.
        if ($status === 422) {
            return 'داده‌های ارسالی نامعتبر است. جزئیات: ' . $detail;
        }

        $lower = strtolower($detail);

        foreach (self::MESSAGE_RULES as [$needles, $message]) {
            if ($this->containsAny($lower, $needles)) {
                return $message;
            }
        }

        return $fallback;
    }

    /**
     * آیا هر کدام از عبارت‌ها در متن وجود دارد؟
     *
     * @param array<int, string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * پیام فارسی برای خطاهای ورود.
     *
     * @param array{status:int, data:array<string, mixed>|null, raw:string, error:string} $response
     */
    private function authErrorMessage(array $response, string $fallback): string
    {
        $fallbacks = match ($response['status']) {
            401     => 'نام کاربری یا رمز عبور پنل اشتباه است.',
            403     => 'این حساب اجازهٔ دسترسی به این بخش از پنل را ندارد.',
            404     => 'این حساب در پنل پیدا نشد. نام کاربری را بررسی کنید.',
            429     => 'درخواست‌های زیادی به پنل ارسال شده است؛ کمی بعد دوباره تلاش کنید.',
            default => $fallback,
        };

        $detail = $this->detailOf($response);

        return $detail === null
            ? $fallbacks
            : $this->translate($detail, $response['status'], $fallbacks);
    }

    /**
     * پیام فارسی برای خطاهای HTTP عمومی.
     *
     * @param array{status:int, data:array<string, mixed>|null, raw:string, error:string} $response
     */
    private function httpErrorMessage(array $response): string
    {
        return match ($response['status']) {
            404 => 'موردی با این مشخصات در پنل پیدا نشد.',
            409 => 'این عملیات با وضعیت فعلی پنل تداخل دارد.',
            422 => 'داده‌های ارسالی نامعتبر است.',
            429 => 'درخواست‌های زیادی به پنل ارسال شده است؛ کمی بعد دوباره تلاش کنید.',
            default => 'خطای پنل (کد ' . $response['status'] . '). لطفاً کمی بعد دوباره تلاش کنید.',
        };
    }

    private function tokenCacheKey(string $username): string
    {
        return md5($this->baseUrl . '|' . strtolower(trim($username)));
    }
}
