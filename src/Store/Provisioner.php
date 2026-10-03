<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * سرویس اعمال خودکار بستهٔ خریداری‌شده روی پنل.
 *
 * این سرویس قلب «خودکار بودن» خرید است:
 *   1. سفارش پرداخت‌شده گرفته می‌شود.
 *   2. بسته به نوعش روی پنل اعمال می‌شود:
 *        • `agency` → یک حساب ادمین (اپراتور) تازه روی پنل ساخته می‌شود.
 *        • `topup`  → سقف حجم و تاریخ اعتبار یکی از پنل‌های موجود بالا می‌رود.
 *   3. وضعیت در جدول panels ثبت می‌شود تا هشدار حجم/انقضا و قطع دسترسی
 *      کاربران در آینده ممکن باشد.
 *
 * ## تضمین‌های مالی
 *
 * **idempotency:** سقف هدف (absolute target) یک‌بار محاسبه و در
 * `orders.target_limit` ذخیره می‌شود. اگر اجرا بعد از PUT شکست بخورد،
 * تلاش مجدد همان سقف را دوباره می‌نویسد نه سقفِ افزایش‌یافته را؛ پس حجم
 * هرگز دو بار اعمال نمی‌شود.
 *
 * **عدم اجرای سفارش ردشده:** سفارش‌هایی که سوپرادمین پرداختشان را رد کرده
 * وضعیت پایانی می‌گیرند (`rejected`) و هرگز وارد صف اجرا نمی‌شوند.
 *
 * **ساخت پنل یک‌بار:** برای بستهٔ `agency` از پرچم `panel_applied` به‌عنوان
 * compare-and-swap استفاده می‌شود، ولی چون ساخت پنل یک عملیات سمت‌پنل است،
 * قبل از علامت‌گذاری، خودِ ردیف panels هم ساخته می‌شود تا اگر PUT بعداً خطا
 * داد، همان اکانت وجود داشته باشد و تلاش مجدد نسخهٔ تکراری نسازد.
 */
final class Provisioner
{
    private PasarGuardClient $panel;
    private OrderRepository $orders;
    private PanelRepository $panels;
    private UserRepository $users;
    private Settings $settings;
    private AgencyService $agency;

    public function __construct(
        ?PasarGuardClient $panel = null,
        ?OrderRepository $orders = null,
        ?UserRepository $users = null,
        ?Settings $settings = null,
        ?PanelRepository $panels = null,
        ?AgencyService $agency = null
    ) {
        $this->panel   = $panel ?? new PasarGuardClient();
        $this->orders  = $orders ?? new OrderRepository();
        $this->users   = $users ?? new UserRepository();
        $this->settings = $settings ?? new Settings();
        $this->panels  = $panels ?? new PanelRepository();

        // کلاینت پنل باید به AgencyService هم برسد، وگرنه خودش یک کلاینت
        // واقعی می‌سازد و به شبکه وصل می‌شود — یعنی تست‌ها و هر حالتی که
        // کلاینت جعلی تزریق شده، بی‌صدا از کلاینت واقعی رد می‌شوند.
        $this->agency = $agency ?? new AgencyService($this->panel);
    }

    public function panels(): PanelRepository
    {
        return $this->panels;
    }

    /**
     * پردازش یک سفارش پرداخت‌شده و اعمال آن روی پنل.
     *
     * @param  array<string, mixed> $order
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    public function provision(array $order): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId === 0) {
            return ['ok' => false, 'message' => 'شناسهٔ سفارش نامعتبر است.', 'details' => []];
        }

        // سفارش‌های با وضعیت پایانی هرگز اجرا نمی‌شوند.
        if ($this->orders->isTerminal((string) ($order['status'] ?? ''))) {
            return [
                'ok'      => false,
                'message' => 'این سفارش قبلاً نهایی شده و دوباره اجرا نمی‌شود.',
                'details' => ['status' => $order['status'] ?? ''],
            ];
        }

        $userId = (int) ($order['user_id'] ?? 0);
        $user   = $this->users->findById($userId);

        if ($user === null) {
            return $this->failTerminal($orderId, 'user_missing', 'کاربر مرتبط با سفارش پیدا نشد.');
        }

        if (!empty($user['is_blocked'])) {
            return $this->failTerminal($orderId, 'user_blocked', 'این کاربر مسدود شده است و سرویسی برایش اعمال نمی‌شود.');
        }

        // ------------------------------------------------------------------
        // قفل اتمیک سفارش — و دروازهٔ ایمنی این تابع.
        //
        // markApplying() فقط وقتی true می‌دهد که سفارش واقعاً «پرداخت‌شده و
        // آمادهٔ اجرا» باشد: status ∈ (paid, failed) و بدون terminal_reason و
        // بدون panel_applied و با paid_at.
        //
        // نباید در صورت false ادامه داد: هر دلیل دیگری برای false یعنی
        // سفارش از یکی از این حالت‌ها خارج است (نهایی‌شده، در حال اجرا،
        // بدون paid_at، یا قبلاً اعمال شده). عبور از این نقطه در آن حالت‌ها
        // یعنی اعمال بستهٔ رایگان.
        // ------------------------------------------------------------------
        if (!$this->orders->markApplying($orderId)) {
            $current = $this->orders->find($orderId);

            if ($current === null) {
                return ['ok' => false, 'message' => 'سفارش یافت نشد.', 'details' => []];
            }

            // تازه از پایگاه‌داده خوانده می‌شود، نه از آرایهٔ کهنهٔ فراخوان.
            if ((int) ($current['panel_applied'] ?? 0) === 1) {
                return $this->finalizeAlreadyApplied($current, $orderId);
            }

            $status = (string) ($current['status'] ?? 'unknown');

            Logger::info('Provision skipped — order not in applyable state', [
                'order_id' => $orderId,
                'status'   => $status,
                'reason'   => $current['terminal_reason'] ?? null,
            ]);

            return [
                'ok'      => false,
                'message' => $status === OrderRepository::STATUS_APPLYING
                    ? 'این سفارش هم‌اکنون در حال اجراست.'
                    : 'این سفارش قابل اجرا نیست.',
                'details' => ['status' => $status, 'reason' => $current['terminal_reason'] ?? null],
            ];
        }

        if ((int) ($order['panel_applied'] ?? 0) === 1) {
            return $this->finalizeAlreadyApplied($order, $orderId);
        }

        try {
            $result = $this->applyByKind($order, $user);
        } catch (PanelException $e) {
            return $this->handlePanelException($order, $e);
        } catch (\Throwable $e) {
            Logger::error('Provision failed with unexpected error', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            return $this->fail($orderId, 'خطای داخلی هنگام اعمال بسته: ' . $e->getMessage());
        }

        if (!($result['ok'] ?? false)) {
            // خطاهای محلی خودشان ثبت شده‌اند و نباید دوباره شمرده شوند.
            if ($result['details']['fatal'] ?? false) {
                return $result;
            }

            return $this->fail($orderId, (string) $result['message']);
        }

        // ثبت موفقیت
        $appliedBytes = (int) ($result['details']['applied_bytes'] ?? 0);
        $this->orders->update($orderId, [
            'status'          => OrderRepository::STATUS_APPLIED,
            'applied_at'      => time(),
            'error'           => null,
            'terminal_reason' => null,
            'applied_volume'  => $appliedBytes,
            'before_limit'    => $result['details']['before_limit'] ?? null,
            'after_limit'     => $result['details']['after_limit'] ?? null,
            'panel_id'        => $result['details']['panel_id'] ?? ($order['panel_id'] ?? null),
            'next_attempt_at' => null,
            'attempts'        => 0,
        ]);

        $this->users->refreshOrderStats((int) $user['id']);
        $this->orders->logProvision($orderId, 'success', 'بسته با موفقیت اعمال شد.', $result['details']);

        Logger::info('Package provisioned', [
            'order_id' => $orderId,
            'user_id'  => (int) $user['id'],
            'kind'     => (string) $order['kind'],
            'bytes'    => $appliedBytes,
        ]);

        return $result;
    }

    /**
     * انتخاب مسیر اجرا بر اساس نوع بسته.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $user
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function applyByKind(array $order, array $user): array
    {
        return match ((string) $order['kind']) {
            PackageRepository::KIND_AGENCY => $this->applyAgency($order, $user),

            // سفارش‌های قدیمی panel_quota همان «شارژ» هستند.
            PackageRepository::KIND_PANEL_QUOTA,
            PackageRepository::KIND_TOPUP  => $this->applyTopup($order, $user),

            default => $this->failTerminal(
                (int) $order['id'],
                'kind_retired',
                'این نوع بسته دیگر ارائه نمی‌شود. لطفاً با پشتیبانی تماس بگیرید تا وجه شما بررسی شود.'
            ),
        };
    }

    /**
     * اگر بسته قبلاً اعمال شده ولی ثبت نهایی ناتمام مانده، آن را نهایی می‌کند.
     *
     * @param  array<string, mixed> $order
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function finalizeAlreadyApplied(array $order, int $orderId): array
    {
        $userId = (int) $order['user_id'];

        $this->orders->update($orderId, [
            'status'          => OrderRepository::STATUS_APPLIED,
            'applied_at'      => $order['applied_at'] ?? time(),
            'error'           => null,
            'terminal_reason' => null,
            'next_attempt_at' => null,
            'attempts'        => 0,
        ]);

        $this->users->refreshOrderStats($userId);
        $this->orders->logProvision($orderId, 'success', 'ثبت نهایی سفارش پس از اجرای قبلی تکمیل شد.');

        return [
            'ok'      => true,
            'message' => 'بسته قبلاً روی پنل اعمال شده بود.',
            'details' => ['already_applied' => true],
        ];
    }

    /**
     * پردازش صف بسته‌های آماده (توسط کرون یا بلافاصله بعد از پرداخت).
     *
     * @return array{processed:int, succeeded:int, failed:int}
     */
    public function processQueue(int $limit = 10): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($this->orders->pendingApply($limit) as $order) {
            $processed++;
            $result = $this->provision($order);

            if ($result['ok']) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    // ------------------------------------------------------------------
    // بستهٔ agency — ساخت پنل نمایندگی تازه
    // ------------------------------------------------------------------

    /**
     * ساخت حساب اپراتور تازه روی پنل برای خریدار.
     *
     * @param  array<string, mixed> $order
     * @param  array<string, mixed> $user
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function applyAgency(array $order, array $user): array
    {
        $orderId = (int) $order['id'];

        $bytes = Str::gbToBytes((float) $order['volume_gb'] + (float) ($order['bonus_gb'] ?? 0));

        if ($bytes <= 0) {
            return $this->failTerminal($orderId, 'invalid_volume', 'حجم این سفارش نامعتبر است.');
        }

        // ------------------------------------------------------------------
        // مسیر ۱: پنل از قبل ساخته شده (تلاش مجدد) → فقط نهایی‌سازی.
        //
        // سنجهٔ «ساخته شده» خودِ وجود ردیف در جدول panels است، نه یک پرچم
        // در دیتابیس. دلیل: اگر ساخت روی پنل موفق شود ولی ثبت محلی شکست
        // بخورد، پرچم صفر می‌ماند و سفارش دوباره اجرا می‌شود؛ آن‌وقت باید
        // بتوانیم همان حساب را بازیابی کنیم نه اینکه حساب دوم بسازیم
        // (AgencyService این بازیابی را با نام کاربری قطعی انجام می‌دهد).
        // ------------------------------------------------------------------
        $existingId = (int) ($order['panel_id'] ?? 0);

        if ($existingId > 0) {
            $existing = $this->panels->find($existingId);

            if ($existing !== null) {
                $this->orders->markPanelApplied($orderId, (int) $existing['data_limit']);
                $this->orders->update($orderId, ['panel_id' => $existingId]);

                return [
                    'ok'      => true,
                    'message' => 'پنل قبلاً ساخته شده بود.',
                    'details' => [
                        'panel_id'        => $existingId,
                        'panel_username'  => (string) $existing['panel_username'],
                        'already_applied' => true,
                        'applied_bytes'   => $bytes,
                        'after_limit'     => (int) $existing['data_limit'],
                    ],
                ];
            }
        }

        // ------------------------------------------------------------------
        // مسیر ۲: ساخت واقعی.
        //
        // نکتهٔ حیاتی: اینجا **قبل** از ساخت، پرچم panel_applied ست نمی‌شود.
        //
        // اگر آن را از قبل می‌ستادیم و ساخت شکست می‌خورد، fail() می‌دید
        // panel_applied=1 و سفارش را «قبلاً اعمال شده» علامت می‌زد — یعنی
        // کاربر پول داده، پنلی نگرفته، ولی همه‌جا «موفق» نشان داده می‌شد.
        // قفل همزمانیِ اجرا را همان markApplying در provision() می‌گیرد که
        // وضعیت را applying می‌کند و پروسهٔ دوم دیگر وارد نمی‌شود.
        // ------------------------------------------------------------------
        $result = $this->agency->createPanel($user, $order, $bytes, (int) $order['duration_days']);

        if (!$result['ok']) {
            $isFatal = (bool) ($result['details']['fatal'] ?? false);

            $this->orders->logProvision($orderId, 'failed', (string) $result['message']);

            if ($isFatal) {
                // بدون اکانت سازنده، تلاش مجدد هم بی‌فایده است.
                $this->orders->markTerminal(
                    $orderId,
                    OrderRepository::STATUS_FAILED,
                    'agency_not_configured',
                    (string) $result['message']
                );

                return [
                    'ok'      => false,
                    'message' => (string) $result['message'],
                    'details' => ['fatal' => true],
                ];
            }

            // خطای موقت: panel_applied صفر مانده، پس تلاش مجدد کار می‌کند و
            // AgencyService در صورت ساخته‌شدن قبلی، آن را بازیابی می‌کند.
            return ['ok' => false, 'message' => (string) $result['message'], 'details' => []];
        }

        $details = $result['details'];
        $panelId = (int) ($details['panel_id'] ?? 0);

        $this->orders->markPanelApplied($orderId, $panelId > 0 ? (int) $details['after_limit'] : 0);

        $details['applied_bytes'] = $bytes;
        $details['before_limit']   = 0;
        $details['after_limit']    = $bytes;

        $this->orders->update($orderId, [
            'panel_id'     => $panelId,
            'after_limit'  => $bytes,
            'target_limit' => $bytes,
        ]);

        $this->orders->logProvision($orderId, PackageRepository::KIND_AGENCY, 'پنل نمایندگی ساخته شد.', [
            'request'  => ['volume_bytes' => $bytes, 'days' => (int) $order['duration_days']],
            'response' => ['panel_id' => $panelId, 'username' => $details['panel_username'] ?? null],
        ]);

        return [
            'ok'      => true,
            'message' => (string) $result['message'],
            'details' => $details,
        ];
    }

    // ------------------------------------------------------------------
    // بستهٔ topup — شارژ/تمدید یک پنل موجود
    // ------------------------------------------------------------------

    /**
     * افزایش سقف حجم و تمدید اعتبار یکی از پنل‌های خریدار.
     *
     * @param  array<string, mixed> $order
     * @param  array<string, mixed> $user
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function applyTopup(array $order, array $user): array
    {
        $orderId = (int) $order['id'];

        $panel = $this->targetPanelOf($order, $user);

        if ($panel === null) {
            return $this->failTerminal(
                $orderId,
                'panel_missing',
                'پنلی برای این سفارش پیدا نشد. لطفاً با پشتیبانی تماس بگیرید.'
            );
        }

        $panelId   = (int) $panel['id'];
        $bytes     = Str::gbToBytes((float) $order['volume_gb'] + (float) ($order['bonus_gb'] ?? 0));
        $days      = (int) $order['duration_days'];
        $expireAt  = $days > 0 ? time() + $days * 86400 : null;

        if ($bytes <= 0 && $expireAt === null) {
            return $this->failTerminal($orderId, 'invalid_volume', 'حجم یا مدت این سفارش نامعتبر است.');
        }

        $credentials = $this->credentialsOf($panel);
        if ($credentials === null) {
            return $this->failTerminal(
                $orderId,
                'panel_unlinked',
                'اطلاعات ورود این پنل در ربات موجود نیست؛ لطفاً پنل را دوباره ثبت کنید.'
            );
        }

        [$panelUsername, $password] = $credentials;

        // ------------------------------------------------------------------
        // idempotency: هدف مطلق (absolute target) یک‌بار محاسبه و ذخیره می‌شود.
        //
        // بدون این کار، اگر PUT موفق شود ولی مرحلهٔ بعد (ذخیره در دیتابیس) خطا
        // بدهد، تلاش مجدد سقفِ از قبل افزایش‌یافته را می‌خواند و حجم را
        // **دو برابر** اعمال می‌کند.
        // ------------------------------------------------------------------
        $storedTarget = isset($order['target_limit']) && $order['target_limit'] !== null
            ? (int) $order['target_limit']
            : 0;

        $admin        = $this->panel->getAdmin($panelUsername, $panelUsername, $password);
        $usedTraffic  = (int) ($admin['used_traffic'] ?? 0);
        $currentLimit = isset($admin['data_limit']) && is_numeric($admin['data_limit'])
            ? (int) $admin['data_limit']
            : 0;

        $baseLimit = max($currentLimit, $usedTraffic);
        $newLimit  = $baseLimit + $bytes;

        if ($storedTarget > 0) {
            $baseLimit = max(0, $storedTarget - $bytes);
            $newLimit  = $storedTarget;

            Logger::info('Reusing previously computed target limit', [
                'order_id' => $orderId,
                'target'   => $newLimit,
            ]);
        } elseif (!$this->orders->reserveTargetLimit($orderId, $newLimit)) {
            return ['ok' => false, 'message' => 'سفارش همزمان در حال پردازش است.', 'details' => []];
        }

        // اعمال روی پنل. چون هدف مطلق است، اجرای دوباره همان سقف را
        // دوباره می‌نویسد و حجم اضافه نمی‌شود.
        $response = $bytes > 0
            ? $this->panel->modifyAdmin($panelUsername, ['data_limit' => $newLimit], $panelUsername, $password)
            : [];

        // پنل ممکن است پاسخ ناقص بدهد؛ فقط کلیدهای موجود merge می‌شوند تا
        // موجودی صفر نشود و هشدار حجم از کار نیفتد.
        $this->panels->syncFromPanel($panelId, array_merge($admin, is_array($response) ? $response : []));

        // علامت‌گذاری اینکه روی پنل اعمال شد — از این لحظه تلاش مجدد نباید
        // دوباره PUT بفرستد. مقدار برگشتی یعنی «این تلاش اولین اعمال بود» و
        // فقط همان یک‌بار سقف کاربران جمع می‌شود تا تلاش مجددِ پس از موفقیت
        // ناقص، سقف را دو بار بالا نبرد. (حجم با هدف مطلق idempotent است.)
        $freshlyApplied = $this->orders->markPanelApplied($orderId, $newLimit);
        $this->orders->update($orderId, ['panel_id' => $panelId]);

        // سقف پنل همین حالا از پاسخ پنل sync شد؛ اینجا فقط «چقدر خریده شد» و
        // «تا کی اعتبار دارد» ثبت می‌شود. جمع‌زدن دوبارهٔ data_limit باعث
        // می‌شد حجم هر شارژ دو برابر اعمال شود.
        $this->panels->addGranted($panelId, $bytes, $expireAt);

        // سقف کاربران: جمع شارژها؛ نامحدود (۰) غالب است — نه پنل نامحدود را
        // محدود می‌کنیم، نه با بستهٔ نامحدود پنل محدود را محدود نگه می‌داریم.
        $orderUsers = max(0, (int) ($order['max_users'] ?? 0));
        $panelUsers = max(0, (int) ($panel['user_limit'] ?? 0));
        $newUserLimit = ($orderUsers <= 0 || $panelUsers <= 0) ? 0 : ($panelUsers + $orderUsers);

        if ($freshlyApplied && $newUserLimit !== $panelUsers) {
            $this->panels->update($panelId, ['user_limit' => $newUserLimit]);
        }

        $details = [
            'panel_id'       => $panelId,
            'panel_username' => $panelUsername,
            'before_limit'   => $baseLimit,
            'after_limit'    => $newLimit,
            'used_traffic'   => $usedTraffic,
            'applied_bytes'  => $bytes,
            'user_limit'     => $newUserLimit,
            'expire_at'      => $expireAt,
        ];

        $this->orders->logProvision($orderId, PackageRepository::KIND_TOPUP, 'شارژ پنل انجام شد.', [
            'request'  => ['data_limit' => $newLimit, 'user_limit' => $newUserLimit],
            'response' => $details,
        ]);

        return [
            'ok'      => true,
            'message' => 'پنل شما شارژ شد.',
            'details' => $details,
        ];
    }

    /**
     * پنل هدفِ یک سفارش شارژ.
     *
     * اولویت با `orders.panel_id` است (کاربر در صفحهٔ خرید پنل را انتخاب کرده)
     * و در نبود آن، پنل پیش‌فرض/تازه‌ترین کاربر.
     *
     * @param  array<string, mixed> $order
     * @param  array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private function targetPanelOf(array $order, array $user): ?array
    {
        $panelId = (int) ($order['panel_id'] ?? 0);

        if ($panelId > 0) {
            $panel = $this->panels->find($panelId);

            // ----------------------------------------------------------------
            // پنلِ کاربرِ دیگر: هرگز نباید شارژ شود.
            //
            // این بررسی اینجا (لایهٔ Provisioner) تکرار می‌شود، نه فقط در
            // Kernel. دلیل: Provisioner را کرون و IPN هم صدا می‌زنند و آنجا
            // هیچ بررسیِ مالکیتی در مسیر UI وجود ندارد. اگر فقط در Kernel
            // چک کنیم، یک شناسهٔ دست‌کاری‌شده در رکورد سفارش کافی بود تا
            // پنل یک نمایندهٔ دیگر شارژ شود.
            // ----------------------------------------------------------------
            if ($panel !== null && (int) $panel['user_id'] !== (int) $user['id']) {
                return null;
            }

            if ($panel !== null) {
                return $panel;
            }
        }

        return $this->panels->primaryForUser($user);
    }

    // ------------------------------------------------------------------
    // کمکی
    // ------------------------------------------------------------------

    public function autoApplyEnabled(): bool
    {
        return $this->settings->bool(Settings::AUTO_APPLY, true);
    }

    /**
     * رمز عبور رمزگشایی‌شدهٔ یک پنل.
     *
     * @param  array<string, mixed> $panel
     * @return array{0:string, 1:string}|null [panelUsername, password]
     */
    public function credentialsOf(array $panel): ?array
    {
        $panelUsername = trim((string) ($panel['panel_username'] ?? ''));
        $password      = $this->panels->plainPassword($panel);

        if ($panelUsername === '' || $password === '') {
            return null;
        }

        return [$panelUsername, $password];
    }

    /**
     * همگام‌سازی وضعیت پنل‌های یک کاربر (برای دکمهٔ «بروزرسانی»).
     *
     * @param  array<string, mixed> $user
     * @return array{ok:bool, message:string, results?:array<int, array<string, mixed>>}
     */
    public function syncUserPanels(array $user): array
    {
        $panels = $this->panels->listByUser((int) $user['id']);

        if ($panels === []) {
            return ['ok' => false, 'message' => 'هنوز هیچ پنلی برای شما ثبت نشده است.'];
        }

        $syncer = new PanelSyncer($this->panels, $this->panel);
        $result = $syncer->syncMany($panels);

        if ($result['synced'] === 0) {
            return [
                'ok'      => false,
                'message' => 'بروزرسانی هیچ پنلی ممکن نشد. اطلاعات ورود را بررسی کنید.',
                'results' => $result,
            ];
        }

        return [
            'ok'      => true,
            'message' => Str::faNumber($result['synced']) . ' پنل بروزرسانی شد.',
            'results' => $result,
        ];
    }

    /**
     * هندل خطاهای پنل با تصمیم‌گیری دربارهٔ تلاش مجدد.
     *
     * @param  array<string, mixed> $order
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function handlePanelException(array $order, PanelException $e): array
    {
        $orderId     = (int) $order['id'];
        $attempts    = (int) $order['attempts'] + 1;
        $maxAttempts = Config::int('worker.max_attempts', 5);

        $this->orders->logProvision($orderId, 'error', $e->getMessage(), [
            'response' => $e->payload(),
            'status'   => $e->httpStatus(),
        ]);

        // خطای احراز هویت: تلاش مجدد بی‌فایده است، پنل باید دوباره ثبت شود.
        if ($e->isAuthError()) {
            $panelId = (int) ($order['panel_id'] ?? 0);

            if ($panelId > 0) {
                $this->panels->update($panelId, ['panel_status' => PanelRepository::STATUS_REVOKED]);
            }

            $this->orders->markTerminal($orderId, OrderRepository::STATUS_FAILED, 'auth_error');

            return [
                'ok'      => false,
                'message' => 'اطلاعات ورود پنل نامعتبر شده است. کاربر باید پنل را دوباره ثبت کند.',
                'details' => ['need_relogin' => true],
            ];
        }

        // خطای دسترسی (۴۰۳ روی مسیر عملیاتی): اطلاعات ورود سالم است، فقط
        // نقش کاربر اجازهٔ این عملیات را ندارد.
        //
        // نباید پنل را از کاربر جدا کنیم (isAuthError این کار را می‌کند و
        // کاربر بی‌دلیل از فروشگاه بیرون می‌افتد) و نباید بی‌نهایت تلاش مجدد
        // کنیم چون تا وقتی نقش عوض نشود نتیجه فرقی نمی‌کند.
        if ($e->isPermissionError()) {
            $this->orders->markTerminal(
                $orderId,
                OrderRepository::STATUS_FAILED,
                'permission_denied',
                'خطای پنل: ' . $e->getMessage()
            );

            return [
                'ok'      => false,
                'message' => 'حساب پنل شما اجازهٔ این عملیات را ندارد: ' . $e->getMessage()
                    . ' لطفاً با پشتیبانی تماس بگیرید.',
                'details' => ['permission_denied' => true],
            ];
        }

        // خطای غیرقابل تلاش مجدد (۴۰۰/۴۰۴/۴۰۹/۴۲۲) یا اتمام سقف تلاش:
        // سفارش پایانی می‌شود تا دیگر بی‌نهایت در صف نچرخد.
        if (!$e->isRetryable() || $attempts >= $maxAttempts) {
            $reason = $e->isRetryable() ? 'max_attempts' : 'non_retryable';

            $this->orders->markTerminal(
                $orderId,
                OrderRepository::STATUS_FAILED,
                $reason,
                'خطای پنل: ' . $e->getMessage()
            );

            return [
                'ok'      => false,
                'message' => 'خطای پنل: ' . $e->getMessage(),
                'details' => ['retryable' => $e->isRetryable(), 'attempts' => $attempts],
            ];
        }

        $delay = $this->orders->nextAttemptDelay($attempts);
        $this->orders->update($orderId, [
            'status'          => OrderRepository::STATUS_FAILED,
            'attempts'        => $attempts,
            'error'           => $e->getMessage(),
            'next_attempt_at' => time() + $delay,
        ]);

        Logger::warning('Provision scheduled for retry', [
            'order_id' => $orderId,
            'attempts' => $attempts,
            'delay'    => $delay,
        ]);

        return [
            'ok'      => false,
            'message' => 'خطای موقت از پنل؛ اعمال بسته ' . Str::duration($delay) . ' دیگر تکرار می‌شود.',
            'details' => ['retry_in' => $delay, 'attempts' => $attempts],
        ];
    }

    /**
     * @param  array<string, mixed> $extra
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function fail(int $orderId, string $message, array $extra = []): array
    {
        $current     = $this->orders->find($orderId);
        $attempts    = (int) ($current['attempts'] ?? 0) + 1;
        $maxAttempts = Config::int('worker.max_attempts', 5);

        // اگر بسته قبلاً روی پنل اعمال شده، دیگر نباید تلاش مجدد شود چون
        // ممکن است حجم دوباره اعمال شود؛ فقط کارتابلی را نهایی می‌کنیم.
        if ((int) ($current['panel_applied'] ?? 0) === 1) {
            $this->orders->update($orderId, [
                'status'          => OrderRepository::STATUS_APPLIED,
                'terminal_reason' => null,
                'next_attempt_at' => null,
                'attempts'        => 0,
                'updated_at'      => time(),
            ]);

            return ['ok' => true, 'message' => 'بسته روی پنل اعمال شده بود؛ فقط ثبت نهایی انجام شد.', 'details' => $extra];
        }

        $this->orders->update($orderId, [
            'status'          => OrderRepository::STATUS_FAILED,
            'error'           => Str::truncate($message, 500),
            'attempts'        => $attempts,
            'next_attempt_at' => $attempts < $maxAttempts ? time() + $this->orders->nextAttemptDelay($attempts) : null,
        ]);

        $this->orders->logProvision($orderId, 'failed', $message);

        return ['ok' => false, 'message' => $message, 'details' => $extra];
    }

    /**
     * خطایی که ربطی به پنل ندارد و تلاش مجدد بی‌فایده است.
     *
     * @param  array<string, mixed> $extra
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function failTerminal(int $orderId, string $reason, string $message, array $extra = []): array
    {
        $this->orders->markTerminal($orderId, OrderRepository::STATUS_FAILED, $reason, $message);
        $this->orders->logProvision($orderId, 'failed', $message);

        return ['ok' => false, 'message' => $message, 'details' => array_merge($extra, ['fatal' => true])];
    }
}