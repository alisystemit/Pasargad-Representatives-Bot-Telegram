<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Logger;

/**
 * احراز هویت با پنلی که **کاربر** وصل کرده است.
 *
 * ## چرا این کلاس لازم است
 *
 * خرید «پنل نمایندگی» قبلاً همیشه با اکانت owner سراسریِ `config.php` انجام
 * می‌شد، روی `panel.base_url`. یعنی پنلی که کاربر وصل کرده **هیچ نقشی در
 * ساخت نداشت** — حتی اگر روی دامنهٔ خودش نصب بوده باشد.
 *
 * این کلاس یک منبع واحد برای پاسخ به این پرسش است: «برای این ردیف
 * `panels` با چه آدرسی و با چه روش احراز هویتی باید با پنل حرف بزنم؟»
 * همهٔ مسیرها (ساخت پنل نمایندگی، همگام‌سازی، آمار) باید از همین جا
 * بخوانند، وگرنه دوباره سه نسخهٔ متفاوت از همین منطق نوشته می‌شود — که
 * دقیقاً همان اتفاقی است که افتاد.
 *
 * ## چرا `auth_mode` لازم است
 *
 * پنلی که با **کلید API** وصل شده، با رمز عبور کار نمی‌کند: `login()`
 * یک `POST /api/admin/token` با grant_type=password می‌فرستد و پنل آن را
 * رد می‌کند. بدون دانستن اینکه هر ردیف با چه روشی وصل شده، هر عملیاتی روی
 * آن پنل شکست می‌خورد.
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
     * این پنل با کدام روش وصل شده؟
     *
     * `NULL` در ستون یعنی ردیف قدیمی است که فقط رمز عبور دارد.
     */
    public static function modeOf(array $panel): string
    {
        $mode = strtolower(trim((string) ($panel['auth_mode'] ?? '')));

        return $mode === self::MODE_API_KEY ? self::MODE_API_KEY : self::MODE_PASSWORD;
    }

    public static function isApiKey(array $panel): bool
    {
        return self::modeOf($panel) === self::MODE_API_KEY;
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
        $credential = $this->panels->plainPassword($panel);

        if ($credential === '') {
            return null;
        }

        // آدرس خالی یعنی «پنل پیش‌فرض سراسری» — رفتار قدیمی حفظ می‌شود.
        $baseUrl = rtrim(trim((string) ($panel['base_url'] ?? '')), '/');

        if ($baseUrl === '') {
            $baseUrl = rtrim(trim((string) \Pasargad\Support\Config::str('panel.base_url', '')), '/');
        }

        if ($baseUrl === '') {
            return null;
        }

        return [
            'base_url'   => $baseUrl,
            // در مسیر کلید API نام کاربری معنی ندارد؛ خودِ کلید نقش را دارد.
            'username'   => trim((string) ($panel['panel_username'] ?? '')),
            'credential' => $credential,
            'mode'       => self::modeOf($panel),
        ];
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

        $client = new PasarGuardClient($credentials['base_url']);

        if ($credentials['mode'] === self::MODE_API_KEY) {
            $client->useStaticToken($credentials['credential']);
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
        if ($injected !== null) {
            // کلاینت تزریق‌شده روی ردیفِ کلیددار هم باید با همان طرح احراز
            // هویت کار کند، وگرنه `login()` با نام کاربری «api» و کلید به‌عنوان
            // رمز زده می‌شود و همیشه ۴۰۱ می‌گیرد.
            $credential = $this->credentialsOf($panelRow);

            if ($credential !== null
                && $credential['mode'] === self::MODE_API_KEY
                && !$injected->hasStaticToken()
            ) {
                $injected->useStaticToken($credential['credential']);
            }

            return $injected;
        }

        return $this->clientFor($panelRow);
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
