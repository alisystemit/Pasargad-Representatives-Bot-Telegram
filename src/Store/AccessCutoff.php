<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * قطع دسترسی همهٔ کاربران یک پنل پس از اتمام اعتبار نمایندگی.
 *
 * چرا لازم است؟ نماینده خودش کاربرهای مشتریانش را داخل پنل ساخته است. وقتی
 * اعتبار پنل تمام می‌شود، اگر کاربران آن پنل فعال بمانند، همچنان سرویس می‌گیرند
 * بدون اینکه کسی پرداخت کرده باشد — یعنی درآمد از دست رفته.
 *
 * چرا با تأیید ادمین و نه کاملاً خودکار؟
 * عملیات «غیرفعال کردن ده‌ها کاربر» برگشت‌پذیر نیست. اگر تاریخ انقضا به
 * اشتباه محاسبه شده باشد (یا خریدار ۱۰ دقیقه بعد از انقضا شارژ کند)، قطع
 * خودکار سرویس مشتریان را می‌بندد و آسیب می‌زند. پس:
 *   • کرون **تشخیص** می‌دهد و به ادمین پیام می‌دهد (با دکمهٔ اقدام).
 *   • ادمین با یک کلیک تأیید می‌کند و ربات اجرا می‌کند.
 *
 * چرا ربات به‌جای خودِ ادمین؟ چون ۲۰۰ کاربر را دستی در پنل غیرفعال کردن
 * عملی نیست و نمایندهٔ رقیب می‌تواند بد از آن استفاده کند.
 */
final class AccessCutoff
{
    /**
     * حداکثر تعداد کاربری که در یک اجرا قطع می‌شود.
     *
     * سقف عمدی است: اجرای ۵۰۰۰ درخواست در یک بار پردازش وبهوک/کرون هم به
     * پنل فشار می‌آورد هم ممکن است از تایم‌اوت تلگرام رد شود. بقیه در اجرای
     * بعدی (کرون یا دکمهٔ ادمین) انجام می‌شود.
     */
    public const MAX_PER_RUN = 200;

    /**
     * تعداد کل کاربرانی که در هر درخواست لیست خوانده می‌شود.
     */
    private const PAGE_SIZE = 100;

    private PanelRepository $panels;
    private ?PasarGuardClient $panel;

    public function __construct(?PanelRepository $panels = null, ?PasarGuardClient $panel = null)
    {
        $this->panels = $panels ?? new PanelRepository();
        $this->panel  = $panel;
    }

    private function panelClient(): PasarGuardClient
    {
        if ($this->panel === null) {
            $this->panel = new PasarGuardClient();
        }

        return $this->panel;
    }

    /**
     * فهرست کاربران یک پنل (برای نمایش به ادمین قبل از قطع).
     *
     * @param  array<string, mixed> $panelRow
     * @return array{ok:bool, message:string, users?:array<int, array<string, mixed>>}
     */
    public function listPanelUsers(array $panelRow, int $limit = 20): array
    {
        $username = trim((string) $panelRow['panel_username']);
        $password = $this->panels->plainPassword($panelRow);

        if ($username === '' || $password === '') {
            return ['ok' => false, 'message' => 'اطلاعات ورود این پنل در ربات ذخیره نشده است.'];
        }

        try {
            // فیلتر admin یعنی فقط کاربران همین نماینده، نه همهٔ پنل.
            // (کلید ناشناخته برای نسخه‌های قدیمی نادیده گرفته می‌شود.)
            $response = $this->panelClient()->listUsers($username, $password, [
                'limit'  => max(1, min($limit, self::PAGE_SIZE)),
                'offset' => 0,
                'admin'  => $username,
            ]);
        } catch (PanelException $e) {
            if ($e->isAuthError()) {
                return [
                    'ok'      => false,
                    'message' => 'ورود به پنل ممکن نشد؛ حساب اپراتور روی پنل غیرفعال یا حذف شده است. '
                        . 'در این حالت کاربران باید **دستی** از پنل غیرفعال شوند.',
                ];
            }

            return ['ok' => false, 'message' => 'دریافت لیست کاربران ناموفق بود: ' . $e->getMessage()];
        }

        $users = is_array($response['users'] ?? null) ? $response['users'] : [];

        return ['ok' => true, 'message' => '', 'users' => $users];
    }

    /**
     * قطع دسترسی همهٔ کاربران فعال یک پنل.
     *
     * سه نگهبان در برابر «هنگ» دارد:
     *   ۱) **مسیر گروهی**: `POST /api/admin/{username}/users/disable` که سمت
     *      سرور و در **یک** درخواست همهٔ کاربران فعال را غیرفعال می‌کند. بدون
     *      آن، پنلی با ۵۰۰ کاربر یعنی ۵۰۰ درخواست PUT پشت‌سرهم در یک وبهوک —
     *      یعنی قطعی تایم‌اوت و «هنگ» ربات. اگر پنل این مسیر را نپذیرد
     *      (403 روی نقش اپراتور)، به مسیر تکی برمی‌گردیم.
     *   ۲) صفحه‌بندی کامل در مسیر تکی (نه فقط ۱۰۰ تای اول — قبلاً بقیه بی‌صدا
     *      می‌ماندند) ولی سقف MAX_PER_RUN برای هر اجرا.
     *   ۳) بودجهٔ زمانی: اگر به سقف نزدیک شدیم، بقیه برای اجرای بعدی
     *      (کرون/دکمهٔ ادمین) می‌ماند و تعدادشان صریح گزارش می‌شود.
     *
     * @param  array<string, mixed> $panelRow
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    public function cutoff(array $panelRow): array
    {
        $username = trim((string) $panelRow['panel_username']);
        $password = $this->panels->plainPassword($panelRow);
        $panelId  = (int) $panelRow['id'];

        if ($username === '' || $password === '') {
            return [
                'ok'      => false,
                'message' => 'اطلاعات ورود این پنل در ربات ذخیره نشده؛ قطع دسترسی ممکن نیست.',
                'details' => ['needs_manual' => true],
            ];
        }

        $client = $this->panelClient();

        // ------------------------------------------------------------------
        // مسیر سریع: یک درخواست به‌جای N درخواست
        // ------------------------------------------------------------------
        $bulk = $this->cutoffBulk($client, $username, $password);

        if ($bulk !== null) {
            $this->panels->markCutoffDone($panelId, $bulk['disabled']);

            $message = $bulk['disabled'] > 0
                ? '✅ ' . Str::faNumber($bulk['disabled']) . ' کاربر یک‌جا غیرفعال شد (روش گروهی پنل).'
                : 'ℹ️ این پنل هیچ کاربر فعالی ندارد.';

            if ($bulk['skipped'] > 0) {
                $message .= "\nℹ️ " . Str::faNumber($bulk['skipped']) . ' کاربر از قبل غیرفعال بود.';
            }

            return [
                'ok'      => true,
                'message' => $message,
                'details' => [
                    'disabled'  => $bulk['disabled'],
                    // «از قبل غیرفعال» یعنی **پیش از** این عمل چنین بودند،
                    // نه وضعیت بعدش (که همه غیرفعال‌اند و عدد گمراه‌کننده می‌شد).
                    'skipped'   => $bulk['skipped'],
                    'failed'    => [],
                    'total'     => $bulk['disabled'] + $bulk['skipped'],
                    'remaining' => 0,
                    'method'    => 'bulk',
                ],
            ];
        }

        // ------------------------------------------------------------------
        // مسیر تکی: صفحه‌بندی + سقف اجرا + بودجهٔ زمانی
        // ------------------------------------------------------------------
        return $this->cutoffOneByOne($client, $panelRow, $panelId, $username, $password);
    }

    /**
     * تلاش برای قطع گروهی. اگر پنل پذیرفت، `null` برنمی‌گردد.
     *
     * @return array{disabled:int, skipped:int}|null
     */
    private function cutoffBulk(PasarGuardClient $client, string $username, string $password): ?array
    {
        // قبل از عمل، شمارش وضعیت‌ها تا گزارش صادقانه باشد (پنل بدنهٔ شمارشی
        // نمی‌دهد و «غیرفعال شد» بدون عدد یعنی ادعای بی‌سند).
        $before = $this->countStatuses($client, $username, $password);

        try {
            $client->disablePanelUsers($username, $username, $password);
        } catch (PanelException $e) {
            // ۴۰۳ یعنی نقش اپراتور اجازهٔ این کار را ندارد، ۴۰۴/۴۰۵ یعنی مسیر
            // در این نسخه از پنل نیست — هر دو یعنی «مسیر گروهی در دسترس نیست».
            //
            // خطای احراز هویت هم عمداً همین‌جا به مسیر تکی می‌رود: آنجا برای
            // `isAuthError()` یک شاخهٔ درست وجود دارد که پنل را «باطل‌شده»
            // علامت می‌زند و صریحاً می‌گوید کار باید دستی انجام شود. اینجا
            // فقط می‌خواهیم بدانیم آیا راه سریع هست یا نه.
            Logger::info('Bulk cutoff unavailable, falling back to per-user', [
                'panel'  => $username,
                'status' => $e->httpStatus(),
                'error'  => $e->getMessage(),
            ]);

            return null;
        }

        $after = $this->countStatuses($client, $username, $password);

        // اگر شمارش ممکن نشد، عدد را نمی‌سازیم — صادقانه «نامشخص» می‌گوییم.
        if ($before === null || $after === null) {
            return ['disabled' => 0, 'skipped' => 0];
        }

        $disabled = max(0, $before['active'] - $after['active']);

        // «از قبل غیرفعال» = قبل از عمل غیرفعال بوده‌اند. نه بعدش.
        return ['disabled' => $disabled, 'skipped' => $before['off']];
    }

    /**
     * شمارش کاربران فعال/غیرفعال یک پنل با سه درخواست سبک.
     *
     * @return array{active:int, off:int}|null
     */
    private function countStatuses(PasarGuardClient $client, string $username, string $password): ?array
    {
        $active = $client->countUsers($username, $password, ['active', 'limited'], $username);
        $off    = $client->countUsers($username, $password, ['disabled', 'expired', 'on_hold'], $username);

        if ($active === null || $off === null) {
            return null;
        }

        return ['active' => $active, 'off' => $off];
    }

    /**
     * مسیر تکی: صفحه‌بندی کامل + سقف اجرا + بودجهٔ زمانی.
     *
     * @param  array<string, mixed> $panelRow
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function cutoffOneByOne(
        PasarGuardClient $client,
        array $panelRow,
        int $panelId,
        string $username,
        string $password
    ): array {
        // بودجهٔ زمانی این اجرا؛ با احتیاط از سقف PHP کمتر تا fatal ندهیم.
        $maxExec  = (int) ini_get('max_execution_time');
        $deadline = microtime(true) + ($maxExec > 0 ? max(5, min(20, $maxExec - 10)) : 20);

        $targets   = [];   // نام‌های کاربری برای غیرفعال‌سازی (حداکثر MAX_PER_RUN)
        $skipped   = 0;
        $remaining = 0;     // فعال‌های دیده‌شدهٔ مازاد بر سقف اجرا
        $truncated = false; // صفحه‌های خوانده‌نشده مانده است
        $seen      = 0;

        try {
            $offset = 0;

            while (true) {
                $response = $client->listUsers($username, $password, [
                    'limit'  => self::PAGE_SIZE,
                    'offset' => $offset,
                    'admin'  => $username,
                ]);

                $users = is_array($response['users'] ?? null) ? $response['users'] : [];

                if ($users === []) {
                    break;
                }

                $seen += count($users);

                foreach ($users as $user) {
                    $target = trim((string) ($user['username'] ?? ''));
                    $status = (string) ($user['status'] ?? '');

                    if ($target === '') {
                        continue;
                    }

                    // کاربری که همین حالا غیرفعال است، کاری برایش نیست.
                    if (in_array($status, ['disabled', 'expired', 'on_hold'], true)) {
                        $skipped++;
                        continue;
                    }

                    if (count($targets) < self::MAX_PER_RUN) {
                        $targets[] = $target;
                    } else {
                        $remaining++;
                    }
                }

                $offset += count($users);

                $reported = $response['total'] ?? null;

                if (is_numeric($reported) && $seen >= (int) $reported) {
                    break;
                }

                if (count($users) < self::PAGE_SIZE) {
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $truncated = true;
                    break;
                }
            }
        } catch (PanelException $e) {
            // اگر خودِ حساب اپراتور دیگر کار نکند، ربات نمی‌تواند کاربرانش را
            // ببیند. صریح بگوییم کار دستی لازم است، نه اینکه وانمود کنیم موفق شد.
            $this->panels->update($panelId, ['cutoff_requested_at' => time()]);

            return [
                'ok'      => false,
                'message' => 'ورود به پنل این نماینده ممکن نشد: ' . $e->getMessage() . "\n\n"
                    . '⚠️🔒 کاربران این پنل باید **دستی** از پنل غیرفعال شوند! 👇',
                'details' => ['needs_manual' => true],
            ];
        }

        $disabled = 0;
        $failed   = [];

        foreach ($targets as $target) {
            // بودجهٔ زمانی موقع اجرا هم چک می‌شود تا حلقهٔ PUT وسط راه نمیرد.
            if (microtime(true) >= $deadline) {
                $remaining += count($targets) - $disabled - count($failed);
                $truncated = true;
                break;
            }

            try {
                $client->disableUser($target, $username, $password);
                $disabled++;
            } catch (PanelException $e) {
                $failed[] = $target . ' (' . $e->getMessage() . ')';
                Logger::warning('Could not disable panel user', [
                    'panel'  => $username,
                    'target' => $target,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        // اگر صفحه‌های نخوانده مانده، تعداد باقی‌مانده دقیق نیست — همان را بگوییم.
        if ($truncated) {
            $remainingMessage = ' (تخمینی — ادامه در اجرای بعدی)';
        } else {
            $remainingMessage = '';
        }

        $this->panels->markCutoffDone($panelId, $disabled);

        $message = '✅ ' . Str::faNumber($disabled) . ' کاربر غیرفعال شد.';

        if ($skipped > 0) {
            $message .= "\nℹ️ " . Str::faNumber($skipped) . ' کاربر از قبل غیرفعال بود.';
        }

        if ($failed !== []) {
            $message .= "\n⚠️ " . Str::faNumber(count($failed)) . ' کاربر غیرفعال نشد.';
        }

        if ($remaining > 0) {
            $message .= "\n⏳ " . Str::faNumber($remaining) . ' کاربر برای اجرای بعدی ماند' . $remainingMessage . '.';
        }

        if ($disabled === 0 && $skipped === 0 && $remaining === 0) {
            $message = 'ℹ️ این پنل هیچ کاربر فعالی ندارد.';
        }

        return [
            'ok'      => $failed === [],
            'message' => $message,
            'details' => [
                'disabled'  => $disabled,
                'skipped'   => $skipped,
                'failed'    => $failed,
                'total'     => $seen,
                'remaining' => $remaining,
            ],
        ];
    }
}