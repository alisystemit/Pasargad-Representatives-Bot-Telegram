<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Logger;

/**
 * احراز هویت با پنلی که **کاربر** وصل کرده است.
 *
 * ## ترتیب اولویت
 *
 * **کلید API اول، رمز عبور فقط پشتیبان.** این تصادفی نیست:
 *
 * - کلید، کاربر را مجبور نمی‌کند رمز مدیریتی‌اش را به ربات بدهد. (با
 *   `panel.self` قبلاً مجبور بود.)
 * - ولی کلیدها معمولاً مجوز ناقص دارند. اندازه‌گیری روی یک کلید واقعی:
 *   `admins.create` و `users.read` مجاز بود، اما `admins.update` و
 *   `admin_roles.read` نبود — یعنی **شارژ و تمدید پنل** با کلید ۴۰۳ می‌دهد.
 *
 * پس حذف کامل رمز به قیمت از کار افتادن شارژ تمام می‌شود، و حذف کامل کلید
 * یعنی کاربر باید رمز مدیریتی‌اش را لو بدهد. راه‌حل: اول با کلید امتحان
 * کن، و **فقط اگر پنل گفت «دسترسی نداری»** همان یک عملیات را با رمز تکرار
 * کن. این منطق در `PasarGuardClient::retryWithFallback()` است، پس همهٔ
 * مسیرها (ساخت، شارژ، آمار، قطع دسترسی) یک‌باره درست می‌شوند.
 *
 * ## چرا `api_key` ستون جدا دارد
 *
 * اگر کلید و رمز در یک ستون بودند، رمزگشایی‌شان قابل تفکیک نبود. حالا هر
 * دو جدا رمزنگاری می‌شوند و یک ردیف می‌تواند هر دو را داشته باشد.
 */
final class PanelAccess
{
    public const MODE_PASSWORD = 'password';
    public const MODE_API_KEY  = 'apikey';

    private PanelRepository $panels;

    public function __construct(?PanelRepository $panels = null)
    {
        $this->panels = $panels ?? new PanelRepository();
    }

    /**
     * کلید API رمزگشایی‌شدهٔ این ردیف (یا رشتهٔ خالی).
     */
    public function apiKeyOf(array $panel): string
    {
        $encrypted = (string) ($panel['api_key'] ?? '');

        if ($encrypted === '') {
            return '';
        }

        try {
            return Crypto::decrypt($encrypted);
        } catch (\Throwable $e) {
            Logger::error('Cannot decrypt panel api key', [
                'panel_id' => $panel['id'] ?? null,
                'error'    => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * این پنل با کدام روش **اول** حرف می‌زند؟
     *
     * اولویت با کلید API است — ولی اگر ردیف قدیمی باشد که فقط رمز دارد،
     * همان رمز است.
     *
     * @param array<string, mixed> $panel
     */
    public static function modeOf(array $panel): string
    {
        if (trim((string) ($panel['api_key'] ?? '')) !== '') {
            return self::MODE_API_KEY;
        }

        $mode = strtolower(trim((string) ($panel['auth_mode'] ?? '')));

        return $mode === self::MODE_API_KEY ? self::MODE_API_KEY : self::MODE_PASSWORD;
    }

    public static function isApiKey(array $panel): bool
    {
        return self::modeOf($panel) === self::MODE_API_KEY;
    }

    /**
     * آدرس پنل این ردیف (خالی یعنی «از `panel.base_url` سراسری»).
     */
    public function baseUrlOf(array $panel): string
    {
        $baseUrl = rtrim(trim((string) ($panel['base_url'] ?? '')), '/');

        return $baseUrl !== ''
            ? $baseUrl
            : rtrim(trim((string) Config::str('panel.base_url', '')), '/');
    }

    /**
     * اطلاعات لازم برای حرف زدن با پنل یک ردیف `panels`.
     *
     * @param  array<string, mixed> $panel
     * @return array{base_url:string, username:string, credential:string, mode:string}|null
     *         ‌null یعنی این ردیف قابل استفاده نیست (آدرس یا راز ندارد).
     */
    public function credentialsOf(array $panel): ?array
    {
        $apiKey = $this->apiKeyOf($panel);
        $mode   = self::modeOf($panel);

        $credential = $mode === self::MODE_API_KEY ? $apiKey : $this->panels->plainPassword($panel);

        if ($credential === '') {
            return null;
        }

        $baseUrl = $this->baseUrlOf($panel);

        if ($baseUrl === '') {
            return null;
        }

        return [
            'base_url'   => $baseUrl,
            // در مسیر کلید API نام کاربری معنی ندارد؛ خودِ کلید نقش را دارد.
            'username'   => trim((string) ($panel['panel_username'] ?? '')),
            'credential' => $credential,
            'mode'       => $mode,
        ];
    }

    /**
     * اطلاعات پشتیبان (رمز عبور) — فقط اگر ردیف **هم کلید دارد و هم رمز**.
     *
     * برای ردیفی که فقط رمز دارد، `null` است چون اصلاً پشتیبانی در کار نیست.
     *
     * @param  array<string, mixed> $panel
     * @return array{0:string, 1:string}|null [username, password]
     */
    public function fallbackOf(array $panel): ?array
    {
        if (!self::isApiKey($panel)) {
            return null;
        }

        $password = $this->panels->plainPassword($panel);
        $username = trim((string) ($panel['panel_username'] ?? ''));

        return ($username !== '' && $password !== '') ? [$username, $password] : null;
    }

    /**
     * کلاینت آماده برای این پنل، با هدر احراز هویت مناسب روش همان ردیف.
     *
     * ⚠️ کلید API داخل کلاینت به‌عنوان «توکن» نگه داشته می‌شود ولی
     * **هیچ‌وقت لاگ نمی‌شود** — نه اینجا، نه در `PasarGuardClient`.
     */
    public function clientFor(array $panel): ?PasarGuardClient
    {
        $credentials = $this->credentialsOf($panel);

        if ($credentials === null) {
            return null;
        }

        return $this->applyCredentials(new PasarGuardClient($credentials['base_url']), $panel, $credentials);
    }

    /**
     * نشاندن اطلاعات (کلید اول، رمز پشتیبان) روی یک کلاینت آماده.
     *
     * جدا شده تا `clientForPanel()` همین قاعده را برای کلاینت تزریق‌شده هم
     * اجرا کند — وگرنه کلاینت تست/وبهوک بدون کلید کار می‌کرد و با ردیفِ
     * کلیددار ۴۰۳ می‌خورد.
     *
     * @param array{base_url:string, username:string, credential:string, mode:string} $credentials
     */
    private function applyCredentials(
        PasarGuardClient $client,
        array $panel,
        array $credentials
    ): PasarGuardClient {
        if ($credentials['mode'] === self::MODE_API_KEY) {
            $client->useStaticToken($credentials['credential']);
        }

        // رمز پشتیبان فقط وقتی لازم است که کلید ناتوان شود (۴۰۳).
        $fallback = $this->fallbackOf($panel);

        if ($fallback !== null) {
            $client->withFallbackCredential($fallback[0], $fallback[1]);
        }

        return $client;
    }

    /**
     * خواندن اطلاعات ادمین این پنل، با روش احراز هویت خودِ ردیف.
     *
     * @param  array<string, mixed> $panel
     * @param  PasarGuardClient|null $injected کلاینت تزریق‌شده؛ اگر داده شود
     *         استفاده می‌شود (قاعدهٔ یکسان با `clientForPanel()`).
     * @return array<string, mixed>
     * @throws PanelException
     */
    public function readAdmin(array $panel, ?PasarGuardClient $injected = null): array
    {
        $credentials = $this->credentialsOf($panel);

        if ($credentials === null) {
            throw new PanelException(
                'اطلاعات ورود این پنل در ربات ذخیره نشده است.',
                0,
                ['panel_id' => $panel['id'] ?? null],
                null,
                true
            );
        }

        $client = $injected ?? new PasarGuardClient($credentials['base_url']);
        $client = $this->applyCredentials($client, $panel, $credentials);

        if ($credentials['mode'] === self::MODE_API_KEY) {
            return $client->getAdminWithApiKey($credentials['credential']);
        }

        return $client->getAdmin(
            $credentials['username'],
            $credentials['username'],
            $credentials['credential']
        );
    }

    /**
     * پنلی که ساخت پنل نمایندگی باید روی آن انجام شود.
     *
     * اولویت: پنل پیش‌فرض کاربر، وگرنه نخستین پنل فعالش.
     *
     * چرا پیش‌فرض؟ چون کاربر ممکن است چند پنل وصل کرده باشد و «پنلی که
     * می‌خواهد زیر آن نمایندگی بسازد» مشخصاً همان است که پیش‌فرض علامت
     * خورده — حدسِ «اولین ردیف» در آن حالت به پنل اشتباه اشاره می‌کند.
     *
     * @param  array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function creatorPanelFor(array $user): ?array
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0) {
            return null;
        }

        $panels = $this->panels->listByUser($userId);

        foreach ($panels as $panel) {
            if ((int) ($panel['is_default'] ?? 0) === 1) {
                return $panel;
            }
        }

        foreach ($panels as $panel) {
            if ($this->credentialsOf($panel) !== null) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * کلاینتی که سرویس‌ها باید با آن کار کنند.
     *
     * قاعده در یک جا جمع شده تا سه سرویس (`PanelSyncer`، `AccessCutoff`،
     * `PanelUserStats`) هرکدام نسخهٔ خودشان را ننویسند:
     *
     *   • اگر کلاینتی از بیرون تزریق شده باشد (کلاینت جعلیِ تست‌ها، یا
     *     کلاینتی که وبهوک با بودجهٔ زمانی ساخته) **همان** استفاده می‌شود.
     *     دور ریختنش یعنی تست‌ها به پنل واقعی وصل می‌شوند و در محیطی که
     *     دسترسی شبکه نیست همه‌چیز با خطای «رمز اشتباه» می‌افتد.
     *
     *   • وگرنه کلاینتی ساخته می‌شود که آدرس و روش احراز هویت خودِ همین
     *     ردیف را دارد — نه آدرس سراسری.
     *
     * @param  array<string, mixed> $panelRow
     * @return PasarGuardClient|null
     */
    public function clientForPanel(array $panelRow, ?PasarGuardClient $injected = null): ?PasarGuardClient
    {
        if ($injected === null) {
            return $this->clientFor($panelRow);
        }

        $credentials = $this->credentialsOf($panelRow);

        if ($credentials === null) {
            return $injected;
        }

        return $this->applyCredentials($injected, $panelRow, $credentials);
    }

    /**
     * ثبت اینکه این ردیف با کدام روش وصل شده است.
     */
    public function rememberMode(int $panelId, string $mode): void
    {
        $this->panels->update($panelId, [
            'auth_mode' => $mode === self::MODE_API_KEY ? self::MODE_API_KEY : self::MODE_PASSWORD,
        ]);

        Logger::info('Panel auth mode recorded', [
            'panel_id' => $panelId,
            'mode'     => $mode,
        ]);
    }
}
