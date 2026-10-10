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

    /**
     * مهلت پایان این درخواست وبهوک (زمان پایان بر حسب Unix time).
     *
     * چرا لازم است؟
     *
     * بدترین حالتِ یک فراخوانی ساده به پنل، بدون هیچ نگهبانی:
     *     login  = ۲ تلاش × ۲۰ ثانیه = ۴۰ ثانیه
     *     send   = ۲ تلاش × ۲۰ ثانیه = ۴۰ ثانیه
     *     ۴۰۱ → یک دور کامل دیگر      = ۸۰ ثانیه
     *     جمع                        = ۱۶۰ ثانیه
     *
     * تلگرام بعد از ۶۰ ثانیه پاسخ‌نگیری را timeout می‌کند و همان update را
     * **دوباره** می‌فرستد. نتیجه: پیام دوباره پردازش می‌شود، پنل دوباره
     * بارگذاری می‌شود، کاربر پیام تکراری می‌بیند و در نهایت FloodGuard همهٔ
     * دکمه‌ها را قفل می‌کند — یعنی همان «هنگ کردن» و «درست فرمان ندادن».
     *
     * با این بودجه، قبل از تمام شدن وقت، درخواست جدید **شروع نمی‌شود** و
     * timeout مؤثر هر درخواست متناسب با وقت باقی‌مانده کوتاه می‌شود؛ نتیجه
     * یک خطای سریع و قابل‌فهم است، نه قطع وسط کار.
     */
    private float $deadline = 0.0;

    /**
     * بودجهٔ پیش‌فرض **برای کل پروسه**.
     *
     * چرا static و نه فقط روی نمونه؟
     *
     * ربات در یک درخواست وبهوک، کلاینت‌های متعددی می‌سازد — `Kernel`، و هر
     * `Store` سرویسی که خودش `new PasarGuardClient()` می‌زند (`PanelSyncer`،
     * `AccessCutoff`، `PanelUserStats`، `TestConfigService`، `AdminController`…).
     * اگر بودجه فقط روی یک نمونه می‌نشست، بقیهٔ کلاینت‌های همان درخواست
     * بدون سقف کار می‌کردند و همان ۱۶۰ ثانیهٔ کشنده برمی‌گشت.
     *
     * با static، هر کلاینتی که در همان پروسه ساخته شود سقف را به ارث می‌برد.
     * برای کرون و CLI هم بی‌اثر است، چون آن‌ها `beginRequest` صدا نمی‌زنند.
     */
    private static float $processBudget = 0.0;

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

        // سهمیهٔ کل پروسه به ارث می‌رسد (ببینیم توضیح `$processBudget`).
        $this->deadline = self::$processBudget > 0.0
            ? microtime(true) + self::$processBudget
            : 0.0;
    }

    /**
     * تعیین بودجهٔ زمانی برای **همهٔ** کلاینت‌هایی که از این لحظه ساخته می‌شوند.
     *
     * وبهوک این را یک‌بار صدا می‌زند و دیگر لازم نیست هر سرویسی که خودش
     * کلاینت می‌سازد، بودجه را بشناسد.
     */
    public static function setProcessBudget(float $seconds): void
    {
        // زیر ۵ ثانیه بی‌فایده است: هیچ درخواست واقعی در آن جا نمی‌شود.
        self::$processBudget = $seconds >= 5.0 ? $seconds : 0.0;
    }

    /** برداشتن بودجهٔ کل پروسه (برای تست و اجرای پس‌زمینه‌ای). */
    public static function clearProcessBudget(): void
    {
        self::$processBudget = 0.0;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * تعیین مهلت پایان کار با پنل.
     *
     * پیش از این، بودجه نامحدود بود و بدترین حالت یک فراخوانی ۱۶۰ ثانیه طول
     * می‌کشید (محاسبه در توضیح `$deadline`). وبهوک این را قبل از پردازش
     * صدا می‌زند؛ کرون و CLI که پس‌زمینه‌ای اجرا می‌شوند دست‌نخورده می‌مانند.
     *
     * @param float $seconds سقف ثانیه از همین لحظه
     */
    public function beginRequest(float $seconds): void
    {
        // زیر ۵ ثانیه بی‌فایده است: یک درخواست شبکه در آن جا نمی‌شود و فقط
        // باعث می‌شود کاربر پیام خطای بی‌ربط ببیند.
        $this->deadline = $seconds >= 5.0 ? microtime(true) + $seconds : 0.0;
    }

    /**
     * برداشتن بودجهٔ زمانی (کرون/CLI که پس‌زمینه‌ای‌اند محدودیت تلگرام ندارند).
     */
    public function clearRequestBudget(): void
    {
        $this->deadline = 0.0;
    }

    /** چند ثانیه تا پایان بودجه باقی مانده؟ (بدون بودجه = بی‌نهایت) */
    public function remainingSeconds(): float
    {
        if ($this->deadline <= 0.0) {
            return INF;
        }

        return max(0.0, $this->deadline - microtime(true));
    }

    /**
     * timeout مؤثر برای درخواست بعدی.
     *
     * یک ثانیه ذخیره می‌گذاریم تا خودِ تشخیص پایان وقت فرصت اجرا شود و خطای
     * تمیز بدهد، نه قطع ناگهانی.
     */
    private function effectiveTimeout(): int
    {
        $remaining = $this->remainingSeconds();

        if ($remaining === INF) {
            return $this->timeout;
        }

        return max(1, min($this->timeout, (int) floor($remaining) - 1));
    }

    /**
     * تلاش مجدد فقط وقتی که واقعاً وقت دارد.
     *
     * تلاش مجدد وقتی بی‌فایده است که وقت باقی‌مانده از یک دور کامل کمتر باشد:
     * فقط زمان کل را جلو می‌اندازد و نتیجه‌ای عوض نمی‌کند.
     */
    private function effectiveAttempts(): int
    {
        $remaining = $this->remainingSeconds();

        if ($remaining === INF) {
            return 2;
        }

        return $remaining >= $this->timeout * 2 + 1 ? 2 : 1;
    }

    /**
     * نگهبان پیش از شروع درخواست: اگر وقت نمانده، اصلاً شبکه را لمس نکن.
     */
    private function assertTimeLeft(string $path): void
    {
        // ۲ ثانیه = کمتر از یک درخواست شبکهٔ واقعی. شروع کردنش فقط یعنی
        // پاسخ بدتری (timeout به‌جای پیام روشن) برای همان مقدار وقت.
        if ($this->remainingSeconds() < 2.0) {
            // سازندهٔ اختصاصی، چون این خطا **خطای شبکه نیست** و تلاش مجدد برایش
            // بی‌فایده است (وقتی وجود ندارد). اگر با وضعیت ۰ معمولی پرتاب شود،
            // `isRetryable()` آن را قابل‌تلاش‌مجدد می‌بیند و هر بار همان‌جا
            // دوباره شکست می‌خورد.
            throw PanelException::budgetExceeded(
                'پنل در این لحظه پاسخ‌گو نیست (مهلت پردازش تمام شد). چند ثانیه دیگر دوباره تلاش کنید.',
                $path
            );
        }
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

        // پیش از هر تلاش برای توکن هم باید وقت داشته باشیم؛ وگرنه یک درخواست
        // ۴۰ ثانیه‌ای فقط برای گرفتن توکن شروع می‌شود و بعد `send()` اصلاً
        // فرصت نمی‌کند — یعنی ۴۰ ثانیه هدر رفت.
        $this->assertTimeLeft('/api/admin/token');

        $response = Http::json('POST', $this->baseUrl . '/api/admin/token', null, [
            'form' => [
                'username'   => $username,
                'password'   => $password,
                'grant_type' => 'password',
            ],
            'headers'  => ['Accept: application/json'],
            'timeout'  => $this->effectiveTimeout(),
            'attempts' => $this->effectiveAttempts(),
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
     * اطلاعات یک ادمین مشخص.
     *
     * ⚠️ **چرا `GET /api/admin/{username}` صدا زده نمی‌شود؟**
     * چون در پنل ۵ چنین مسیری وجود ندارد. بررسی زنده روی
     * `us.api-system.top` نشان می‌دهد `GET /api/admin/anything` پاسخ
     * **405 Method Not Allowed** می‌دهد (چون مسیر فقط PUT/DELETE دارد).
     * یعنی هر فراخوانی به آن، همیشه استثنا می‌داد و چهار مسیر حیاتی
     * (ثبت پنل، همگام‌سازی، خرید پنل، شارژ) همیشه خراب می‌شدند — در
     * حالی که تست‌ها سبز بودند چون کلاینت ساختگی این محدودیت را نداشت.
     *
     * دو راه درست وجود دارد:
     *   ① خودِ ادمین را می‌خواهیم  → `GET /api/admin` (ادمین جاری).
     *      چون با همان نام کاربری لاگین کرده‌ایم، پاسخ دقیقاً رکورد
     *      همان ادمین است. این مسیر را ۳ از ۴ فراخوانی استفاده می‌کنند.
     *   ② ادمین دیگری (مالک)      → `GET /api/admins?username=X` و باز کردن
     *      `admins[0]` از پاسخ.
     *
     * @return array<string, mixed>
     */
    public function getAdmin(string $targetUsername, string $username, string $password): array
    {
        $target = trim($targetUsername);

        if ($target === '') {
            throw new PanelException('نام کاربری پنل مشخص نشده است.', 404);
        }

        // حالت ① — خودِ ادمین
        if (strcasecmp($target, trim($username)) === 0) {
            return $this->getCurrentAdmin($username, $password);
        }

        // حالت ② — ادمین دیگر، فقط با اکانتی که دسترسی sudo دارد
        return $this->findAdminRow(
            $this->listAdmins($username, $password, ['username' => $target, 'limit' => 1]),
            $target
        );
    }

    /**
     * اطلاعات ادمین بر اساس شناسهٔ عددی.
     *
     * ⚠️ `GET /api/admin/by-id/{id}` هم مثل مورد قبلی **۴۰۵** می‌دهد؛ فقط
     * متدهای PUT/DELETE روی آن مسیر وجود دارند. راه درست، فیلتر `ids` روی
     * `GET /api/admins` است.
     *
     * @return array<string, mixed>
     */
    public function getAdminById(int $adminId, string $username, string $password): array
    {
        if ($adminId <= 0) {
            throw new PanelException('شناسهٔ ادمین نامعتبر است.', 404);
        }

        return $this->findAdminRow(
            $this->listAdmins($username, $password, ['ids' => $adminId, 'limit' => 1]),
            (string) $adminId
        );
    }

    /**
     * بیرون‌کشیدن ردیف درخواستی از پاسخ `{admins: [...]}`.
     *
     * اگر پنل به‌جای لیست، خودِ رکورد را برگرداند (رفتار بعضی نسخه‌ها)،
     * همان را می‌پذیریم تا تغییر نسخهٔ پنل این مسیر را نشکند.
     *
     * @param  array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function findAdminRow(array $response, string $needle): array
    {
        // شکل لیستی (GET /api/admins)
        if (isset($response['admins']) && is_array($response['admins'])) {
            foreach ($response['admins'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowUsername = strtolower(trim((string) ($row['username'] ?? '')));

                if ($rowUsername === strtolower($needle)
                    || (string) ($row['id'] ?? '') === $needle) {
                    return $row;
                }
            }

            throw new PanelException('موردی با این مشخصات در پنل پیدا نشد.', 404);
        }

        // شکل تک‌رکوردی (fallback)
        if (isset($response['username']) || isset($response['id'])) {
            return $response;
        }

        throw new PanelException('موردی با این مشخصات در پنل پیدا نشد.', 404);
    }

    /**
     * لیست ادمین‌ها (برای ابرادمین/سوپرادمین پنل).
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listAdmins(string $username, string $password, array $query = []): array
    {
        // Validation بهتر برای جلوگیری از SQL injection
        $allowedKeys = ['offset', 'limit', 'username', 'ids'];
        $filteredQuery = [];
        
        foreach ($query as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue; // کلیدهای نامعتبر نادیده گرفته می‌شوند
            }
            
            // Validation برای هر کلید
            if ($key === 'offset' || $key === 'limit') {
                $intValue = (int) $value;
                if ($intValue < 0) $intValue = 0;
                if ($key === 'limit' && $intValue > 1000) $intValue = 1000; // حداکثر limit
                $filteredQuery[$key] = $intValue;
            } elseif ($key === 'username') {
                // تنها حروف، اعداد، نقطه و خط‌فاصله
                if (preg_match('/^[a-zA-Z0-9._-]+$/', (string) $value)) {
                    $filteredQuery[$key] = (string) $value;
                }
            } elseif ($key === 'ids') {
                $filteredQuery[$key] = (int) $value;
            }
        }
        
        $query = array_merge(['offset' => 0, 'limit' => 50], $filteredQuery);

        return $this->request('GET', '/api/admins?' . http_build_query($query), $username, $password);
    }

    /**
     * ویرایش ادمین (PUT /api/admin/{username}).
 *
     * ⚠️ **چرا بدنهٔ خالی رد می‌شود؟**
     * مدل `AdminModify` هیچ فیلدی را اجباری نکرده، پس `PUT` با بدنهٔ `{}`
     * از نظر HTTP «موفق» است ولی هیچ کاری نمی‌کند. بدتر: نسخه‌هایی از پنل که
     * فیلدهای نفرستاده را `None` حساب کنند، درخواست کوتاه یعنی **پاک کردن**
     * `sub_domain`/`profile_title`/`note` نماینده. یک تماس بی‌معنی نباید بتواند
     * اطلاعات نماینده را صفر کند، پس صریح رد می‌شود.
     *
     * @param  array<string, mixed> $payload فیلدهای AdminModify
     * @return array<string, mixed>
     */
    public function modifyAdmin(string $targetUsername, array $payload, string $username, string $password): array
    {
        if ($payload === []) {
            throw new PanelException(
                'هیچ فیلدی برای ویرایش پنل ارسال نشد؛ عملیات انجام نشد.',
                0,
                ['path' => '/api/admin/' . $targetUsername],
                null,
                false,
                true   // تقصیر کاربر نیست و با تلاش مجدد درست نمی‌شود
            );
        }

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
     * ساخت ادمین جدید (POST /api/admin).
     *
     * برای «خرید پنل نمایندگی» استفاده می‌شود: ربات با اکانت owner یک حساب
     * ادمین با نقش اپراتور می‌سازد تا نماینده از داخل خود پنل دسترسی
     * عملیاتی داشته باشد.
     *
     * @param  array<string, mixed> $payload فیلدهای AdminCreate
     * @return array<string, mixed>
     */
    public function createAdmin(array $payload, string $username, string $password): array
    {
        return $this->request('POST', '/api/admin', $username, $password, $payload);
    }

    /**
     * حذف ادمین (DELETE /api/admin/{username}).
     */
    public function deleteAdmin(string $targetUsername, string $username, string $password): array
    {
        return $this->request(
            'DELETE',
            '/api/admin/' . rawurlencode($targetUsername),
            $username,
            $password
        );
    }

    /**
     * غیرفعال کردن یک کاربر پنل.
     *
     * ⚠️ **چرا `PUT /api/user/{username}/disabled` و نه `PUT /api/user/{username}`؟**
     * مدل `UserModify` هیچ فیلدی را اجباری نکرده و همه قابل‌تهی‌اند. اگر
     * نسخه‌ای از پنل فیلدهای نفرستاده را «null» حساب کند، ارسال فقط
     * `{status: "disabled"}` هم‌زمان `data_limit` و `expire` کاربر را **پاک
     * می‌کند** — یعنی قطع دسترسی عملاً به «حذف» تبدیل می‌شود و با تمدید دوباره
     * برنمی‌گردد. endpoint اختصاصی فقط یک فیلد دارد (`disabled`) و از نظر
     * ساختاری نمی‌تواند چیزی را بپاکد.
     * (آزمون زنده: `PUT /api/user/xyz/disabled → 401` یعنی مسیر و متد درست است.)
     *
     * برای «قطع دسترسی همهٔ کاربران یک نماینده پس از اتمام اعتبار» استفاده
     * می‌شود. عمداً به‌جای DELETE از تغییر وضعیت استفاده می‌کنیم چون حذف
     * اطلاعات مصرف مشتری را از بین می‌برد و در صورت تمدید دوباره قابل بازگشت
     * نیست.
     *
     * @param array<string, mixed> $payload اگر `status` چیزی غیر از `disabled`
     *                                      باشد (مثلاً فعال‌سازی دوباره)، مسیر
     *                                      عمومی با همان وضعیت استفاده می‌شود.
     */
    public function disableUser(string $targetUsername, string $username, string $password, array $payload = []): array
    {
        $target = trim($targetUsername);

        if ($target === '') {
            throw new PanelException('نام کاربری پنل مشخص نشده است.', 404);
        }

        $status = (string) ($payload['status'] ?? '');

        if ($status !== '' && $status !== 'disabled') {
            return $this->modifyUser(
                $target,
                array_merge(['status' => $status], $payload),
                $username,
                $password
            );
        }

        return $this->request(
            'PUT',
            '/api/user/' . rawurlencode($target) . '/disabled',
            $username,
            $password,
            ['disabled' => true]
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
     * ⚠️ مثل `modifyAdmin`، بدنهٔ خالی رد می‌شود: `UserModify` هم فیلد اجباری
     * ندارد و نسخه‌هایی از پنل که `None` را «پاک کردن» می‌گیرند، با `{}` حجم و
     * تاریخ انقضای مشتری را صفر می‌کنند.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function modifyUser(string $targetUsername, array $payload, string $username, string $password): array
    {
        if ($payload === []) {
            throw new PanelException(
                'هیچ فیلدی برای ویرایش کاربر ارسال نشد؛ عملیات انجام نشد.',
                0,
                ['path' => '/api/user/' . $targetUsername],
                null,
                false,
                true
            );
        }

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
     * شمارش کاربران با فیلتر وضعیت — بدون دانلود لیست.
     *
     * چرا جدا از listUsers؟ برای آمار، فقط عدد `total` لازم است؛ گرفتن
     * صفحه‌به‌صفحهٔ هزاران کاربر داخل وبهوک یعنی تایم‌اوت و «هنگ» ربات.
     * با `limit=1` فقط شمارش برمی‌گردد (۱ درخواست به‌جای N صفحه).
     *
     * نکتهٔ کوئری: فیلترهای لیستی پنل (`status=[...]`) باید به‌صورت کلید
     * تکراری (`status=a&status=b`) فرستاده شوند؛ `http_build_query` شکل
     * `status[0]=a` می‌سازد که FastAPI لیست نمی‌فهمد و ۴۲۲ می‌دهد.
     *
     * @param  array<int, string> $statuses زیرمجموعه‌ای از UserStatus (خالی = همه)
     * @return int|null شمارش، یا null اگر نامشخص بود (فراخواننده باید walk کند)
     */
    public function countUsers(string $username, string $password, array $statuses = [], ?string $admin = null): ?int
    {
        $qs = 'limit=1&offset=0';

        foreach ($statuses as $status) {
            $status = trim((string) $status);

            if ($status !== '') {
                $qs .= '&status=' . rawurlencode($status);
            }
        }

        if ($admin !== null && trim($admin) !== '') {
            $qs .= '&admin=' . rawurlencode(trim($admin));
        }

        try {
            $response = $this->request('GET', '/api/users?' . $qs, $username, $password);
        } catch (PanelException) {
            return null;
        }

        $users = $response['users'] ?? null;
        $total = $response['total'] ?? null;

        // شکل پاسخ باید دقیقاً همان UsersResponse باشد؛ وگرنه عدد
        // غیرقابل اعتماد است و null برمی‌گردد تا فراخواننده walk کند.
        if (!is_array($users) || !is_numeric($total)) {
            return null;
        }

        return max(0, (int) $total);
    }

    /**
     * غیرفعال کردن یک‌جای همهٔ کاربران یک ادمین (POST .../users/disable).
     *
     * چرا bulk و نه حلقه؟ قطع دسترسی ۲۰۰ کاربر با ۲۰۰ درخواست PUT پشت سر هم
     * در وبهوک یعنی ده‌ها ثانیه تا دقیقه‌ها تأخیر و ریسک تایم‌اوت PHP —
     * یعنی «هنگ» ربات وسط کار. این endpoint همان کار را سمت سرور در یک
     * درخواست انجام می‌دهد.
     *
     * توجه: پاسخ موفق بدنهٔ شمارشی ندارد؛ تعداد دقیق از قبل (لیست) دانسته
     * می‌شود، نه از اینجا.
     *
     * @return array<string, mixed>
     */
    public function disablePanelUsers(string $targetUsername, string $username, string $password): array
    {
        return $this->request(
            'POST',
            '/api/admin/' . rawurlencode($targetUsername) . '/users/disable',
            $username,
            $password
        );
    }

    /**
     * فهرست نقش‌های ادمین (GET /api/admin-roles).
     *
     * برای انتخاب خودکار نقش اپراتور وقتی `rep_role_id` تنظیم نشده است.
     *
     * @param  array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listRoles(string $username, string $password, array $query = []): array
    {
        $query = array_merge(['limit' => 50, 'offset' => 0], $query);

        return $this->request('GET', '/api/admin-roles?' . http_build_query($query), $username, $password);
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
        // نگهبان بودجه: اگر وقت نمانده، اصلاً شبکه لمس نمی‌شود.
        $this->assertTimeLeft($path);

        $token    = $this->login($username, $password);
        $url      = $this->baseUrl . $path;

        // اگر همین حالا بودجه تمام شد (مثلاً login وقت را خورد)، باز هم
        // درخواست را شروع نمی‌کنیم.
        $this->assertTimeLeft($path);

        $headers  = [
            'accept: application/json',
            'authorization: Bearer ' . $token,
        ];

        $timeout  = $this->effectiveTimeout();
        $attempts = $this->effectiveAttempts();

        $started = microtime(true);
        $response = Http::json($method, $url, $payload, [
            'headers'     => $headers,
            'timeout'     => $timeout,
            'verify_ssl'  => $this->verifySsl,
            'attempts'    => $attempts,
        ]);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        Logger::debug('Panel request', [
            'method'   => $method,
            'path'     => $path,
            'status'   => $response['status'],
            'ms'       => $elapsed,
            'timeout'  => $timeout,
            'attempts' => $attempts,
            'budget'   => $this->deadline > 0.0 ? round($this->remainingSeconds(), 1) : null,
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
        // ⚠️ ترتیب سطرها مهم است: قواعد قبل از این بررسی می‌شوند.
        // «Could not validate credentials» عیناً پیام واقعی FastAPI در
        // `POST /api/admin/token` است و شامل کلمهٔ `credentials` است، پس باید
        // **قبل** از سطر عمومی بیاید وگرنه کاربر پیام بی‌معنای
        // «نشست شما منقضی شده» می‌دید و رمزش را عوض می‌کرد بی‌دلیل.
        [['could not validate credentials', 'invalid credential', 'bad credentials', 'incorrect credential'], 'نام کاربری یا رمز عبور پنل اشتباه است.'],
        [['not authenticated', 'unauthorized', 'token expired'], 'نشست شما منقضی شده است. دوباره وارد شوید.'],
        // ⚠️ این سطر باید **قبل** از سطر `not allowed` باشد.
        // پیام واقعی FastAPI برای ۴۰۵ «Method Not Allowed» است و شامل
        // «not allowed» هم می‌شود؟ نه — ولی «You are not allowed to perform
        // this action» (۴۰۳) شامل «not allowed» است. اگر این سطر بعد از قاعدهٔ
        // عمومی می‌آمد، خطای «نسخهٔ پنل پشتیبانی نمی‌کند» — که یک باگ واقعی
        // در کد ماست — به کاربر نشان داده می‌شد و طوری به نظر می‌رسید که مشکل
        // از حساب اوست. الان زودتر بررسی می‌شود و فقط ۴۰۵ را می‌گیرد.
        [['method not allowed', '405 method'], 'این عملیات روی نسخهٔ فعلی پنل پشتیبانی نمی‌شود. لطفاً با پشتیبانی تماس بگیرید.'],
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
            405 => 'این عملیات روی نسخهٔ فعلی پنل پشتیبانی نمی‌شود. لطفاً با پشتیبانی تماس بگیرید.',
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
