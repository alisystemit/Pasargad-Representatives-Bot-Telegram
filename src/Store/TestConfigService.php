<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * «دریافت تست کانفیگ» — ساخت یوزر رایگان کوتاه‌عمر روی پنل خودِ نماینده.
 *
 * مدل کار: نماینده یک پنل فعال دارد و می‌خواهد قبل از فروش به مشتری واقعی،
 * سرویس را امتحان کند. ربات روی **همان پنل** یک یوزر با حجم و زمان کوچک
 * می‌سازد و لینک/اطلاعاتش را می‌دهد.
 *
 * چرا این قابلیت باید محدود باشد؟
 * بدون محدودیت، هر فراخوانی یک یوزر رایگان روی پنل می‌ساخت و ربات تبدیل به
 * ماشین ساخت اکانت رایگان می‌شد. سه سد وجود دارد:
 *   ۱) سقف تعداد کانفیگ فعال همزمان برای هر کاربر
 *   ۲) فاصلهٔ زمانی حداقلی بین دو دریافت
 *   ۳) سوییچ کلی «تست کانفیگ» در پنل مدیریت
 *
 * همچنین هر کانفیگ با یک پسوند مشخص روی پنل ساخته می‌شود تا نماینده بتواند
 * آن‌ها را بشناسد و بعداً پاک کند.
 */
final class TestConfigService
{
    /**
     * حداقل حجم کانفیگ تست (بایت) — یک گیگابایت.
     *
     * چرا این کف وجود دارد؟ `data_limit = 0` در پنل یعنی «نامحدود».
     * اگر تنظیم حجم تست صفر یا خیلی کوچک باشد، تبدیل به بایت صفر می‌شود و
     * نماینده یک یوزر **نامحدود رایگان** روی پنلش می‌گیرد.
     */
    public const MIN_BYTES = 1073741824;

    /**
     * سقف حجم کانفیگ تست (بایت) — ۱۰ گیگابایت.
     *
     * حفاظ در برابر اشتباه تنظیم: یک کانفیگ تست ۱ ترابایتی عملاً یک بستهٔ
     * رایگان است.
     */
    public const MAX_BYTES = 10737418240;

    private PanelRepository $panels;
    private TestConfigRepository $configs;
    private Settings $settings;
    private ?PasarGuardClient $panel;

    public function __construct(
        ?PanelRepository $panels = null,
        ?TestConfigRepository $configs = null,
        ?Settings $settings = null,
        ?PasarGuardClient $panel = null
    ) {
        $this->panels  = $panels ?? new PanelRepository();
        $this->configs = $configs ?? new TestConfigRepository();
        $this->settings = $settings ?? new Settings();
        $this->panel   = $panel;
    }

    private function panelClient(): PasarGuardClient
    {
        if ($this->panel === null) {
            $this->panel = new PasarGuardClient();
        }

        return $this->panel;
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool(Settings::TEST_CONFIG_ENABLED, true);
    }

    /**
     * حجم پیش‌فرض کانفیگ تست (بایت).
     *
     * نکته: چون data_limit=0 در پنل یعنی «نامحدود»، صفر اصلاً به کاربر داده
     * نمی‌شود و به ۱ گیگابایت برگردانده می‌شود.
     */
    public function defaultBytes(): int
    {
        $gb = (float) $this->settings->get(Settings::TEST_CONFIG_VOLUME_GB, '1');
        $bytes = Str::gbToBytes($gb);

        return max(self::MIN_BYTES, min($bytes, self::MAX_BYTES));
    }

    public function defaultDays(): int
    {
        return max(1, $this->settings->int(Settings::TEST_CONFIG_DAYS, 1));
    }

    /**
     * شناسهٔ پنل ثابت تست که ادمین تعیین کرده (۰ = پنل خود کاربر).
     */
    public function defaultPanelId(): int
    {
        return max(0, $this->settings->int(Settings::TEST_CONFIG_PANEL_ID, 0));
    }

    /**
     * پنل مؤثر برای ساخت تست: اگر ادمین پنل خاصی تعیین کرده باشد همان،
     * وگرنه پنل خود کاربر.
     *
     * @param  array<string, mixed> $panel پنل انتخاب‌شدهٔ کاربر
     * @return array<string, mixed>
     */
    public function effectivePanel(array $panel): array
    {
        $fixedId = $this->defaultPanelId();

        if ($fixedId <= 0) {
            return $panel;
        }

        $fixed = $this->panels->find($fixedId);

        return $fixed ?? $panel;
    }

    /**
     * بررسی امکان دریافت کانفیگ تست، بدون ساختن چیزی.
     *
     * @param  array<string, mixed> $panel پنل انتخاب‌شدهٔ کاربر
     * @param  int|null             $requesterUserId شناسهٔ داخلی درخواست‌کننده
     *                              (وقتی پنل ثابت ادمین استفاده می‌شود با مالک پنل فرق دارد)
     * @return array{ok:bool, message:string, wait_minutes?:int}
     */
    public function canIssue(array $panel, ?int $requesterUserId = null): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'message' => '⚙️😴 دریافت تست کانفیگ موقتاً غیرفعال است! 🙏'];
        }

        $panel = $this->effectivePanel($panel);

        if (!PanelRepository::isUsable($panel)) {
            return [
                'ok'      => false,
                'message' => '⚠️😔 اعتبار این پنل تمام شده یا غیرفعال است؛ ابتدا آن را تمدید کنید! 🔋',
            ];
        }

        $password = $this->panels->plainPassword($panel);
        if ($password === '') {
            return [
                'ok'      => false,
                'message' => '🔑🔒 اطلاعات ورود این پنل در ربات موجود نیست! لطفاً پنل را دوباره ثبت کنید. 📝',
            ];
        }

        $userId = $requesterUserId ?? (int) $panel['user_id'];

        $max = $this->settings->int(Settings::TEST_CONFIG_MAX, 2);
        if ($max > 0 && $this->configs->countActiveByUser($userId) >= $max) {
            return [
                'ok'      => false,
                'message' => '⚠️🛑 شما هم‌اکنون ' . Str::faNumber($max) . ' کانفیگ تست فعال دارید! 😔 '
                    . 'ابتدا یکی را غیرفعال کنید یا صبر کنید تا اعتبارش تمام شود. ⏳',
            ];
        }

        $cooldown = $this->settings->int(Settings::TEST_CONFIG_COOLDOWN, 30);
        if ($cooldown > 0) {
            $last    = $this->configs->lastIssuedAt($userId);
            $elapsed = (int) floor((time() - $last) / 60);

            if ($last > 0 && $elapsed < $cooldown) {
                return [
                    'ok'            => false,
                    'message'       => '⏳ بین دو دریافت کانفیگ تست باید ' . Str::faNumber($cooldown)
                        . ' دقیقه فاصله باشد! ⏰ ' . Str::faNumber($cooldown - $elapsed) . ' دقیقهٔ دیگر صبر کنید. 🙏',
                    'wait_minutes'  => $cooldown - $elapsed,
                ];
            }
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * ساخت یوزر تست روی پنل نماینده.
     *
     * @param  array<string, mixed> $panel پنل انتخاب‌شدهٔ کاربر
     * @param  int|null             $requesterUserId شناسهٔ داخلی درخواست‌کننده
     * @return array{ok:bool, message:string, details?:array<string, mixed>}
     */
    public function issue(array $panel, ?int $requesterUserId = null): array
    {
        $guard = $this->canIssue($panel, $requesterUserId);

        if (!$guard['ok']) {
            return ['ok' => false, 'message' => $guard['message']];
        }

        $panel        = $this->effectivePanel($panel);
        $panelId      = (int) $panel['id'];
        $requesterId  = $requesterUserId ?? (int) $panel['user_id'];
        $owner        = trim((string) $panel['panel_username']);
        $password     = $this->panels->plainPassword($panel);
        $bytes        = $this->defaultBytes();
        $days         = $this->defaultDays();
        $expireAt     = time() + $days * 86400;
        $testUsername = $this->generateTestUsername($owner);

        try {
            $response = $this->panelClient()->createUser([
                'username'   => $testUsername,
                'data_limit' => $bytes,
                'expire'     => $expireAt,
                'status'     => 'active',
                'note'       => 'تست ربات — پنل ' . $owner,
            ], $owner, $password);
        } catch (PanelException $e) {
            Logger::warning('Test config creation failed', [
                'panel_id'  => $panelId,
                'panel_user'=> $owner,
                'error'     => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => '❌😔 ساخت کانفیگ تست ناموفق بود: ' . $e->getMessage()];
        }

        $subUrl = PanelRepository::extractSubUrl($response);

        $configId = $this->configs->create($panelId, $requesterId, $testUsername, [
            'data_limit' => $bytes,
            'expire_at'  => $expireAt,
            'sub_url'    => $subUrl,
        ]);

        return [
            'ok'      => true,
            'message' => 'کانفیگ تست ساخته شد.',
            'details' => [
                'config_id' => $configId,
                'username'  => $testUsername,
                'data_limit'=> $bytes,
                'expire_at' => $expireAt,
                'sub_url'   => $subUrl,
            ],
        ];
    }

    /**
     * غیرفعال کردن یک کانفیگ تست (کاربر یا ادمین).
     *
     * @return array{ok:bool, message:string}
     */
    public function disable(array $config): array
    {
        // ------------------------------------------------------------------
        // رکورد تازه از دیتابیس خوانده می‌شود، نه همان آرایهٔ ورودی.
        //
        // دلیل: ممکن است بین «خواندن توسط ربات» و «کلیک کاربر» یک بار دیگر
        // غیرفعال شده باشد. اگر به وضعیت کهنه اعتماد کنیم، دوباره به پنل
        // درخواست می‌فرستیم و کاربر پیام «موفق» می‌بیند در حالی که فقط یک
        // PUT تکراری رفته است.
        // ------------------------------------------------------------------
        $id       = (int) ($config['id'] ?? 0);
        $fresh    = $id > 0 ? $this->configs->find($id) : null;

        if ($fresh === null) {
            return ['ok' => false, 'message' => 'این کانفیگ دیگر در ربات ثبت نیست.'];
        }

        $config = $fresh;

        if ((string) $config['status'] !== TestConfigRepository::STATUS_ACTIVE) {
            return ['ok' => false, 'message' => 'این کانفیگ از قبل غیرفعال است.'];
        }

        // کانفیگ منقضی‌شده هم نباید دوباره به پنل درخواست بفرستد.
        if ((int) $config['expire_at'] <= time()) {
            $this->configs->disable((int) $config['id']);

            return ['ok' => true, 'message' => 'این کانفیگ پیش‌تر منقضی شده بود.'];
        }

        $panel = $this->panels->find((int) $config['panel_id']);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'پنل این کانفیگ دیگر در ربات ثبت نشده است.'];
        }

        $owner    = trim((string) $panel['panel_username']);
        $password = $this->panels->plainPassword($panel);

        if ($password !== '') {
            try {
                $this->panelClient()->disableUser((string) $config['panel_username'], $owner, $password);
            } catch (PanelException $e) {
                Logger::warning('Could not disable test config on panel', [
                    'config' => (string) $config['panel_username'],
                    'error'  => $e->getMessage(),
                ]);

                return [
                    'ok'      => false,
                    'message' => 'غیرفعال کردن روی پنل ناموفق بود: ' . $e->getMessage(),
                ];
            }
        }

        $this->configs->disable((int) $config['id']);

        return ['ok' => true, 'message' => 'کانفیگ تست غیرفعال شد.'];
    }

    /**
     * پسوندهای مصرف‌شده در همین پروسه (جلوگیری از تکرار رندوم در یک اجرا).
     *
     * @var array<int, true>
     */
    private static array $usedSuffixes = [];

    /**
     * نام کاربری یکتای کانفیگ تست.
     *
     * الگو: `t_{پنل}_{عدد}` — کوتاه، ASCII و قابل تشخیص برای نماینده.
     * حداکثر ۱۰ بار پسوند اضافه می‌شود و در نهایت به random برمی‌گردیم.
     */
    public function generateTestUsername(string $panelUsername, int $attempt = 0): string
    {
        $base = 't_' . $panelUsername;
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $base) ?: 't_user';
        $base = Str::truncate($base, 20, '');

        if ($attempt === 0) {
            // ۴ رقم تصادفی تا تکراری نشود؛ اگر در همین پروسه قبلاً آمده بود،
            // دوباره می‌غلتانیم تا تست‌های پشت سر هم فلاکی نشوند.
            $guard = 0;

            do {
                $suffix = random_int(100, 9999);
                $guard++;
            } while (isset(self::$usedSuffixes[$suffix]) && $guard < 50);

            self::$usedSuffixes[$suffix] = true;

            return $base . $suffix;
        }

        return Str::truncate($base, 54, '') . $attempt;
    }
}