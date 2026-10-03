<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Logger;

/**
 * آمار کاربران یک پنل نمایندگی.
 *
 * نماینده برای کار روزمره‌اش این را لازم دارد: «الان چند نفر روی پنلم
 * سرویس می‌گیرند؟ چند نفر غیرفعال‌اند؟» بدون این آمار باید وارد پنل شود و
 * لیست را دستی بشمارد.
 *
 * چرا **کش** می‌شود؟
 * چون شمارش یعنی یک (یا چند) درخواست شبکه به پنل. اگر هر بار که کاربر صفحهٔ
 * پنل را باز می‌کند این درخواست زده شود، هم کند است هم به پنل فشار می‌آورد
 * و هم ممکن است توکن پنل را بی‌دلیل بسوزاند. پس نتیجه در خود جدول `panels`
 * نگه داشته می‌شود و فقط اگر قدیمی باشد دوباره خوانده می‌شود.
 */
final class PanelUserStats
{
    /** حداکثر کاربری که در یک درخواست شمرده می‌شود */
    private const PAGE_SIZE = 100;

    /** سقف صفحاتی که در یک بار خوانده می‌شود (۱۰٬۰۰۰ کاربر) */
    private const MAX_PAGES = 100;

    private PanelRepository $panels;
    private Settings $settings;
    private ?PasarGuardClient $panel = null;

    public function __construct(
        ?PanelRepository $panels = null,
        ?Settings $settings = null,
        ?PasarGuardClient $panel = null
    ) {
        $this->panels   = $panels ?? new PanelRepository();
        $this->settings = $settings ?? new Settings();
        $this->panel    = $panel;
    }

    private function panelClient(): PasarGuardClient
    {
        if ($this->panel === null) {
            $this->panel = new PasarGuardClient();
        }

        return $this->panel;
    }

    /**
     * آمار از روی ردیف پنل (بدون تماس شبکه) — برای نمایش سریع.
     *
     * @param array<string, mixed> $panel
     * @return array{total:int, active:int, disabled:int, at:int}
     */
    public function cached(array $panel): array
    {
        return [
            'total'    => (int) ($panel['users_total'] ?? 0),
            'active'   => (int) ($panel['users_active'] ?? 0),
            'disabled' => (int) ($panel['users_disabled'] ?? 0),
            'at'       => (int) ($panel['stats_at'] ?? 0),
        ];
    }

    /**
     * آمار کاربران پنل با خواندن از API (و ذخیرهٔ نتیجه).
     *
     * @param  array<string, mixed> $panel
     * @return array{ok:bool, message:string, total:int, active:int, disabled:int}
     */
    public function refresh(array $panel): array
    {
        $username = trim((string) $panel['panel_username']);
        $password = $this->panels->plainPassword($panel);

        if ($username === '' || $password === '') {
            return ['ok' => false, 'message' => 'اطلاعات ورود این پنل در ربات ذخیره نشده است.',
                'total' => 0, 'active' => 0, 'disabled' => 0];
        }

        $client  = $this->panelClient();

        // مسیر سریع: ۳ شمارش ارزان به‌جای دانلود صفحه‌به‌صفحهٔ همهٔ کاربران.
        // برای پنل چند هزار کاربره یعنی ۳ درخواست به‌جای ده‌ها صفحه — وگرنه
        // وبهوک وسط آمار تایم‌اوت می‌خورد و کاربر هیچ پاسخی نمی‌گیرد («هنگ»).
        $fast = $this->fastCounts($client, $username, $password);

        if ($fast !== null) {
            [$total, $active, $off] = $fast;

            $this->panels->update((int) $panel['id'], [
                'users_total'    => $total,
                'users_active'   => $active,
                'users_disabled' => $off,
                'stats_at'       => time(),
            ]);

            return ['ok' => true, 'message' => '', 'total' => $total, 'active' => $active, 'disabled' => $off];
        }

        $offset  = 0;
        $total   = 0;
        $active  = 0;
        $off     = 0;

        try {
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $response = $client->listUsers($username, $password, [
                    'limit'  => self::PAGE_SIZE,
                    'offset' => $offset,
                ]);

                $users = is_array($response['users'] ?? null) ? $response['users'] : [];
                $count = count($users);

                foreach ($users as $user) {
                    $total++;

                    if (self::isDisabledStatus((string) ($user['status'] ?? ''))) {
                        $off++;
                    } else {
                        $active++;
                    }
                }

                // پاسخ ناقص (بدون شمارش کل) ⇒ همین‌جا متوقف می‌شویم و به
                // امید ادامه نمی‌دهیم، وگرنه در یک پنل ۵۰۰۰ کاربره هزار
                // درخواست بی‌جهت می‌زنیم.
                $reported = $response['total'] ?? null;
                $hasMore  = $count === self::PAGE_SIZE;

                if (is_numeric($reported)) {
                    $hasMore = $total < (int) $reported;
                }

                if (!$hasMore || $count === 0) {
                    break;
                }

                $offset += $count;
            }
        } catch (PanelException $e) {
            Logger::warning('Panel user stats failed', [
                'panel' => $username,
                'error' => $e->getMessage(),
            ]);

            $hint = $e->isAuthError()
                ? ' حساب اپراتور روی پنل غیرفعال یا رمز آن عوض شده است.'
                : '';

            return ['ok' => false, 'message' => 'دریافت آمار کاربران ناموفق بود.' . $hint,
                'total' => 0, 'active' => 0, 'disabled' => 0];
        }

        $this->panels->update((int) $panel['id'], [
            'users_total'    => $total,
            'users_active'   => $active,
            'users_disabled' => $off,
            'stats_at'       => time(),
        ]);

        return ['ok' => true, 'message' => '', 'total' => $total, 'active' => $active, 'disabled' => $off];
    }

    /**
     * شمارش سریع کاربران با ۳ درخواست count (به‌جای walk کامل).
     *
     * برمی‌گرداند null اگر اعداد با هم نخوانند (active + off === total) —
     * در آن صورت فراخواننده به walk کامل برمی‌گردد. این یعنی حتی اگر پنل
     * قدیمی فیلتر status را نفهمد (۴۲۲ → null) یا total را فیلترنشده بدهد،
     * نتیجهٔ غلط هرگز ذخیره نمی‌شود، فقط کمی کندتر.
     *
     * @return array{0:int,1:int,2:int}|null [total, active, off]
     */
    private function fastCounts(PasarGuardClient $client, string $username, string $password): ?array
    {
        try {
            $total = $client->countUsers($username, $password, [], $username);

            if ($total === null) {
                return null;
            }

            $active = $client->countUsers($username, $password, ['active', 'limited'], $username);

            if ($active === null) {
                return null;
            }

            $off = $client->countUsers($username, $password, ['disabled', 'expired', 'on_hold'], $username);

            if ($off === null) {
                return null;
            }
        } catch (\Throwable $e) {
            Logger::debug('Panel fast user count failed, falling back to walk', [
                'panel' => $username,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($total < 0 || $active < 0 || $off < 0 || $active + $off !== $total) {
            Logger::debug('Panel fast user count inconsistent, falling back to walk', [
                'panel'  => $username,
                'total'  => $total,
                'active' => $active,
                'off'    => $off,
            ]);

            return null;
        }

        return [$total, $active, $off];
    }

    /**
     * آمار با احترام به TTL: اگر تازه باشد از کش می‌خواند، وگرنه تازه می‌کند.
     *
     * @param  array<string, mixed> $panel
     * @return array{ok:bool, message:string, total:int, active:int, disabled:int, at:int, cached:bool}
     */
    public function stats(array $panel, bool $force = false): array
    {
        $cached = $this->cached($panel);
        $ttl    = max(1, (int) $this->settings->int(Settings::PANEL_STATS_TTL, 30)) * 60;

        if (!$force && $cached['at'] > 0 && (time() - $cached['at']) < $ttl) {
            return [
                'ok'      => true,
                'message' => '',
                'total'   => $cached['total'],
                'active'  => $cached['active'],
                'disabled' => $cached['disabled'],
                'at'      => $cached['at'],
                'cached'  => true,
            ];
        }

        $fresh = $this->refresh($panel);

        return [
            'ok'       => $fresh['ok'],
            'message'  => $fresh['message'],
            'total'    => $fresh['total'],
            'active'   => $fresh['active'],
            'disabled' => $fresh['disabled'],
            'at'       => $fresh['ok'] ? time() : $cached['at'],
            'cached'   => false,
        ];
    }

    /**
     * وضعیت‌هایی که یعنی کاربر سرویس نمی‌گیرد.
     *
     * چرا `on_hold` هم اینجاست؟ چون در پنل یعنی موقتاً تعلیق شده و سرویس
     * نمی‌گیرد — همان چیزی که برای تصمیم «چند نفر واقعاً استفاده می‌کنند»
     * لازم داریم.
     */
    public static function isDisabledStatus(string $status): bool
    {
        return in_array(
            strtolower($status),
            ['disabled', 'expired', 'on_hold', 'banned', 'inactive'],
            true
        );
    }
}