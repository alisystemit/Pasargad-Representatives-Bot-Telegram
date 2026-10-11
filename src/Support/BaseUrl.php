<?php

declare(strict_types=1);

namespace Pasargad\Support;

/**
 * تشخیص خودکار آدرس پایهٔ ربات.
 *
 * دکمهٔ Mini App بدون `base_url` **بی‌صدا حذف می‌شد** و کاربر فقط یک گزینهٔ
 * کم می‌دید. این کلاس جای آن حدس زدن را می‌گیرد.
 *
 * ## ترتیب اولویت
 *
 *   ۱. `base_url` در config.php  — همیشه برنده است. اگر مدیر آن را تنظیم
 *      کرده، نظر او مقدم بر هر چیزی است.
 *   ۲. هدرها و متغیرهای محیطی وب‌سرور — فقط وقتی پیکربندی وجود ندارد.
 *
 * ## چرا تشخیص از هدر امن است
 *
 * `HTTP_HOST` را هر کسی می‌تواند دلخواه بفرستد، پس مستقیم به آن اعتماد نمی‌کنیم:
 *
 * - نام میزبان باید از نظر نحوی معتبر باشد (حروف، رقم، خط تیره، نقطه).
 * - نام میزبان باید حداقل یک نقطه داشته باشد؛ یعنی دامنه یا IP باشد، نه
 *   چیزی مثل `evil` یا `<script>`.
 * - هر ردیف نامعتبر یا غیرمنتظره‌ای **رد می‌شود**، نه اینکه پاک‌سازی شود.
 *
 * نکتهٔ مهم: حتی اگر کسی `Host:` را جعل کند، تلگرام آدرس Mini App را با
 * آنچه در BotFather ثبت شده می‌سنجد و دکمه را باز نمی‌کند. پس بدترین
 * حالت یک دکمهٔ بی‌مصرف است، نه یک نشت یا دسترسی. ولی چون این اتفاق
 * باید دیده شود، هر بار که به تشخیص متکی می‌شویم در لاگ ثبتش می‌کنیم.
 */
final class BaseUrl
{
    /** منبعی که آخرین بار آدرس از آن آمد — فقط برای لاگ و تشخیص. */
    private static string $source = 'none';

    /**
     * نام میزبان معتبر است؟
     *
     * عمداً سخت‌گیر: هر چیزی که از حد معمول یک دامنه بیرون بزند رد می‌شود.
     */
    private static function isValidHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        // IPv6 در براکت — پشتیبانی می‌شود ولی قالبش جداست.
        if (str_starts_with($host, '[')) {
            return preg_match('/^\[[0-9a-f:.]+\](?::\d{1,5})?$/', $host) === 1;
        }

        // پورت جدا می‌شود و جداگانه بررسی می‌گردد.
        $colon = strrpos($host, ':');

        if ($colon !== false) {
            $port = substr($host, $colon + 1);
            $host = substr($host, 0, $colon);

            if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
                return false;
            }
        }

        // بدون نقطه یعنی دامنه نیست (و «localhost» هم برای Mini App بی‌معناست).
        if (!str_contains($host, '.')) {
            return false;
        }

        // فقط a-z 0-9 نقطه و خط تیره؛ هیچ کاراکتر دیگری (اسپیس، @، ، ، < …).
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }

        // برچسب‌های خالی یا شروع/پایان با خط تیره → نامعتبر.
        foreach (explode('.', $host) as $label) {
            if ($label === '' || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
        }

        // «..» یعنی برچسب خالی در میانه.
        return !str_contains($host, '..');
    }

    /**
     * پروتکل درخواست جاری.
     *
     * `X-Forwarded-Proto` فقط وقتی پذیرفته می‌شود که مقدارش واقعاً `http`
     * یا `https` باشد؛ فهرست پروکسی‌ها را نمی‌کشیم چون ممکن است
     * کارفرما نباشیم.
     */
    private static function detectScheme(): string
    {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');

        // فقط اولین مقدار: «https, http» یعنی زنجیرهٔ پروکسی‌ها.
        $first = strtolower(trim(explode(',', $forwarded)[0] ?? ''));

        if ($first === 'https' || $first === 'http') {
            return $first;
        }

        $https = $_SERVER['HTTPS'] ?? '';

        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return 'https';
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return 'https';
        }

        return 'http';
    }

    /**
     * نام میزبان درخواست جاری، اگر معتبر باشد.
     */
    private static function detectHost(): string
    {
        foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_HOST', 'SERVER_NAME'] as $key) {
            $value = trim((string) ($_SERVER[$key] ?? ''));

            // X-Forwarded-Host هم می‌تواند چند مقدار داشته باشد.
            $first = trim(explode(',', $value)[0] ?? '');

            if ($first !== '' && self::isValidHost($first)) {
                return strtolower($first);
            }
        }

        return '';
    }

    /**
     * پورت پیش‌فرض را از انتهای آدرس حذف می‌کند
     * (https://x.com:443 همان https://x.com است).
     */
    private static function stripDefaultPort(string $url, string $scheme): string
    {
        return preg_replace(
            '/^(https?):\/\/(.+):' . ($scheme === 'https' ? '443' : '80') . '$/',
            '$1://$2',
            $url
        ) ?? $url;
    }

    /**
     * آدرس پایهٔ ربات، بدون اسلیم انتهایی.
     *
     * رشتهٔ خالی یعنی «هیچ آدرس قابل‌اعتمادی در دسترس نیست».
     */
    public static function detect(): string
    {
        // ---- ۱) پیکربندی، که همیشه برنده است ----
        $configured = rtrim(trim(Config::str('base_url', '')), '/');

        if ($configured !== '') {
            if (preg_match('#^https://#i', $configured) === 1) {
                self::$source = 'config';

                return $configured;
            }

            // تنظیم نامعتبر است، ولی حذفش نمی‌کنیم — فقط هشدار می‌دهیم تا
            // ادامه بدهیم و دکمهٔ اپ برگردد.
            Logger::warning('base_url in config is not an https URL; falling back to detection', [
                'base_url' => $configured,
            ]);
        }

        // ---- ۲) تشخیص از محیط وب‌سرور ----
        $host = self::detectHost();

        if ($host === '') {
            self::$source = 'none';

            return '';
        }

        $url = self::stripDefaultPort(self::detectScheme() . '://' . $host, self::detectScheme());

        self::$source = 'server';

        // اگر این بار از محیط آمده، باید دیده شود: یعنی پیکربندی ناقص است.
        Logger::info('base_url auto-detected from server variables', [
            'base_url' => $url,
            'hint'     => 'برای پایدار شدن، base_url را در config.php تنظیم کنید.',
        ]);

        return $url;
    }

    /**
     * آدرس پایه یا رشتهٔ خالی — بدون نوشتن لاگ.
     *
     * برای مسیرهای پرتکرار که نباید هر بار لاگ پر کنند (مثل ساخت لینک).
     */
    public static function current(): string
    {
        $configured = rtrim(trim(Config::str('base_url', '')), '/');

        if ($configured !== '' && preg_match('#^https://#i', $configured) === 1) {
            self::$source = 'config';

            return $configured;
        }

        $host = self::detectHost();

        if ($host === '') {
            self::$source = 'none';

            return '';
        }

        $scheme = self::detectScheme();
        self::$source = 'server';

        return self::stripDefaultPort($scheme . '://' . $host, $scheme);
    }

    /**
     * نام میزبان آدرس پایه، برای مقایسه با هدر امنیتی تلگرام.
     */
    public static function host(): string
    {
        $url = self::current();

        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * منبع آخرین آدرس: `config` | `server` | `none`.
     */
    public static function source(): string
    {
        return self::$source;
    }

    /**
     * تست تشخیص خودکار، مستقل از وضعیت واقعی سرور.
     *
     * @param array<string, mixed> $server متغیرهای محیطی شبیه‌سازی‌شده
     */
    public static function fromEnvironment(array $server): string
    {
        $backup = $_SERVER;

        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }

        try {
            return self::current();
        } finally {
            $_SERVER = $backup;
        }
    }
}
