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
 * ## فقط کلید API
 *
 * ربات دیگر هیچ‌جا رمز عبور نمی‌گیرد و ذخیره نمی‌کند. هر نماینده از داخل
 * پنل خودش یک کلید می‌سازد و همین را به ربات می‌دهد.
 *
 * دلیلش فقط راحتی کاربر نیست، یک مزیت امنیتی واقعی دارد: با کلید می‌شود
 * مجوزها را دقیقاً محدود کرد و کلید را هر وقت خواست باطل کرد، بدون اینکه
 * مجبور شویم رمز اصلی پنل را جایی نگه داریم.
 *
 * ## هزینه‌ای که آگاهانه پذیرفته شد
 *
 * کلیدهای API معمولاً مجوز ناقص دارند. اندازه‌گیری روی یک کلید واقعی:
 * `admins.create` و `users.read` مجاز بود، اما `admins.update` و
 * `admin_roles.read` نبود — یعنی **شارژ/تمدید پنل** با آن کلید ۴۰۳ می‌دهد.
 *
 * این خطا **پنهان نمی‌شود**: `permissionDeniedMessage()` نام همان مجوزِ
 * کم را به کاربر می‌گوید تا کلیدی با دسترسی درست بسازد. بدیلِ ساختن پیام
 * مبهم این بود که کاربر پول بدهد و بی‌صدا سرویسش اجرا نشود.
 *
 * ## ردیف‌های قدیمی
 *
 * ستون `panel_password` برای ردیف‌های قبلی سر جایش می‌ماند تا داده‌ای پاک
 * نشود، ولی **دیگر خوانده نمی‌شود**. پنلی که فقط رمز داشته، «وصل نیست» شمرده
 * می‌شود و کاربر باید کلید بسازد — که همان چیزی است که خواسته شد.
 */
final class PanelAccess
{
    public const MODE_API_KEY = 'apikey';

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
     * آیا این ردیف کلید دارد و قابل استفاده است؟
     *
     * ⚠️ عمداً `auth_mode` را نادیده می‌گیرد: در نسخهٔ قبلی، رمزِ ذخیره‌شده
     * با `auth_mode = apikey` کافی شمرده می‌شد. حالا که رمز حذف شده، فقط
     * وجودِ کلید مهم است — وگرنه پنلی که فقط رمز دارد به‌اشتباه «وصل»
     * شمرده می‌شد و بعداً همه‌چیز ۴۰۳ می‌داد.
     *
     * @param array<string, mixed> $panel
     */
    public static function isApiKey(array $panel): bool
    {
        return trim((string) ($panel['api_key'] ?? '')) !== '';
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

        if ($apiKey === '') {
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
            'credential' => $apiKey,
            'mode'       => self::MODE_API_KEY,
        ];
    }

    /**
     * کلاینت آماده برای این پنل.
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
        $client->useStaticToken($credentials['credential']);

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

        return $client->getAdminWithApiKey($credentials['credential']);
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
     * این ردیف با کلید وصل شده است.
     *
     * ⚠️ پارامتر `$mode` عمداً نادیده گرفته می‌شود: از وقتی ربات فقط کلید
     * می‌پذیرد، «روش دیگری» وجود ندارد. ستون `auth_mode` هم برای ردیف‌های
     * قدیمی نگه داشته می‌شود ولی دیگر تصمیمی بر اساسش گرفته نمی‌شود.
     *
     * @param string $mode نادیده گرفته می‌شود (سازگاری با فراخوانی‌های قدیمی)
     */
    public function rememberMode(int $panelId, string $mode = self::MODE_API_KEY): void
    {
        $this->panels->update($panelId, ['auth_mode' => self::MODE_API_KEY]);

        Logger::info('Panel auth mode recorded', [
            'panel_id' => $panelId,
            'mode'     => self::MODE_API_KEY,
        ]);
    }
}
