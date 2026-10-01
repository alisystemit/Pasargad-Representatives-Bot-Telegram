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
 *   2. اطلاعات فعلی ادمین از پنل خوانده می‌شود.
 *   3. حجم جدید = حجم فعلی + حجم بسته (و در صورت محدودیت، بالا بردن سقف).
 *   4. با PUT /api/admin/{username} اعمال می‌شود.
 *   5. در صورت خطا، سفارش برای تلاش مجدد صف می‌شود و سوپرادمین مطلع می‌گردد.
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
 * **برای بسته‌های نوع user_credit**، حجم به‌جای اعمال مستقیم روی حساب ادمین،
 * به‌صورت اعتبار ساخت کاربر در دیتابیس ربات نگهداری می‌شود.
 */
final class Provisioner
{
    private PasarGuardClient $panel;
    private OrderRepository $orders;
    private UserRepository $users;
    private Settings $settings;

    public function __construct(
        ?PasarGuardClient $panel = null,
        ?OrderRepository $orders = null,
        ?UserRepository $users = null,
        ?Settings $settings = null
    ) {
        $this->panel    = $panel ?? new PasarGuardClient();
        $this->orders   = $orders ?? new OrderRepository();
        $this->users    = $users ?? new UserRepository();
        $this->settings = $settings ?? new Settings();
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

        // قفل کردن سفارش تا دو درخواست همزمان دوباره اعمال نکنند.
        if (!$this->orders->markApplying($orderId)) {
            $current = $this->orders->find($orderId);
            $status  = (string) ($current['status'] ?? 'unknown');

            if ($this->orders->isTerminal($status) || $status === OrderRepository::STATUS_APPLYING) {
                return ['ok' => false, 'message' => 'سفارش در حال پردازش است یا قبلاً اجرا شده.', 'details' => ['status' => $status]];
            }
        }

        // اگر قبلاً روی پنل اعمال شده، فقط کارتابلی را نهایی می‌کنیم.
        if ((int) ($order['panel_applied'] ?? 0) === 1) {
            return $this->finalizeAlreadyApplied($order, $orderId);
        }

        try {
            $result = match ((string) $order['kind']) {
                PackageRepository::KIND_USER_CREDIT => $this->applyUserCredit($order, $user),
                default                          => $this->applyPanelQuota($order, $user),
            };
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
            'next_attempt_at' => null,
            'attempts'        => 0,
        ]);

        $this->users->refreshOrderStats($userId);
        $this->orders->logProvision($orderId, 'success', 'بسته با موفقیت اعمال شد.', $result['details']);

        Logger::info('Package provisioned', [
            'order_id' => $orderId,
            'user_id'  => $userId,
            'bytes'    => $appliedBytes,
        ]);

        return $result;
    }

    /**
     * اگر بسته قبلاً روی پنل اعمال شده ولی ثبت نهایی ناتمام مانده، آن را
     * بدون ارسال دوبارهٔ درخواست به پنل نهایی می‌کند.
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
    // اجرای بستهٔ panel_quota (افزایش حجم خود ادمین)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $order
     * @param  array<string, mixed> $user
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function applyPanelQuota(array $order, array $user): array
    {
        $orderId = (int) $order['id'];

        $credentials = $this->credentialsOf($user);
        if ($credentials === null) {
            return $this->failTerminal(
                $orderId,
                'panel_unlinked',
                'حساب پنل این کاربر قطع شده است؛ لطفاً دوباره وارد شود.'
            );
        }

        [$panelUsername, $password] = $credentials;

        $userId = (int) $user['id'];

        $packageBytes = Str::gbToBytes((float) $order['volume_gb'] + (float) ($order['bonus_gb'] ?? 0));
        if ($packageBytes <= 0) {
            return $this->failTerminal($orderId, 'invalid_volume', 'حجم این سفارش نامعتبر است.');
        }

        // ------------------------------------------------------------------
        // idempotency: هدف مطلق (absolute target) یک‌بار محاسبه و ذخیره می‌شود.
        //
        // بدون این کار، اگر PUT موفق شود ولی مرحلهٔ بعد (مثلاً ذخیره در
        // دیتابیس) خطا بدهد، تلاش مجدد سقفِ از قبل افزایش‌یافته را می‌خواند و
        // حجم را **دو برابر** اعمال می‌کند.
        // ------------------------------------------------------------------
        $storedTarget = isset($order['target_limit']) && $order['target_limit'] !== null
            ? (int) $order['target_limit']
            : 0;

        $admin        = $this->panel->getAdmin($panelUsername, $panelUsername, $password);
        $usedTraffic  = (int) ($admin['used_traffic'] ?? 0);
        $currentLimit = isset($admin['data_limit']) && is_numeric($admin['data_limit'])
            ? (int) $admin['data_limit']
            : 0;

        if ($storedTarget > 0) {
            // این سفارش قبلاً هدفش محاسبه شده — همان مقدار دوباره استفاده می‌شود.
            $baseLimit = max(0, $storedTarget - $packageBytes);
            $newLimit  = $storedTarget;

            Logger::info('Reusing previously computed target limit', [
                'order_id' => $orderId,
                'target'   => $newLimit,
            ]);
        } else {
            // اگر قبلاً مصرف بیشتری از سقف فعلی ثبت شده، از آن شروع می‌کنیم.
            $baseLimit = max($currentLimit, $usedTraffic);
            $newLimit  = $baseLimit + $packageBytes;

            // ذخیرهٔ هدف به‌صورت اتمیک (compare-and-swap) تا اگر دو پروسه
            // همزمان اجرا کنند، فقط یکی برنده شود.
            if (!$this->orders->reserveTargetLimit($orderId, $newLimit)) {
                return [
                    'ok'      => false,
                    'message' => 'سفارش همزمان در حال پردازش است.',
                    'details' => [],
                ];
            }

            // تازه محاسبه شده → هدف را در شیء سفارش هم به‌روز می‌کنیم.
            $order['target_limit'] = $newLimit;
        }

        // اعمال روی پنل. چون هدف مطلق است، اجرای دوباره همان سقف را
        // دوباره می‌نویسد و حجم اضافه نمی‌شود.
        $response = $this->panel->modifyAdmin($panelUsername, [
            'data_limit' => $newLimit,
        ], $panelUsername, $password);

        // پنل ممکن است پاسخ ناقص بدهد؛ فقط کلیدهای موجود را merge می‌کنیم تا
        // موجودی صفر نشود و هشدار حجم از کار نیفتد.
        $this->users->syncPanelState(
            $userId,
            array_merge($admin, is_array($response) ? $response : [])
        );

        // علامت‌گذاری اینکه روی پنل اعمال شده — از این لحظه تلاش مجدد
        // نباید دوباره PUT بفرستد.
        $this->orders->markPanelApplied($orderId, $newLimit);

        // ثبت حجم هدیه‌شده در ربات برای نمایش به کاربر
        $durationDays = (int) $order['duration_days'];
        $expireAt     = $durationDays > 0 ? time() + $durationDays * 86400 : null;
        $this->users->addGrantedVolume($userId, $packageBytes, $expireAt);

        $details = [
            'panel_username' => $panelUsername,
            'before_limit'   => $baseLimit,
            'after_limit'    => $newLimit,
            'used_traffic'   => $usedTraffic,
            'applied_bytes'  => $packageBytes,
            'expire_at'      => $expireAt,
        ];

        $this->orders->logProvision($orderId, 'panel_quota', 'افزایش حجم حساب ادمین انجام شد.', [
            'request'  => ['data_limit' => $newLimit],
            'response' => $details,
        ]);

        return [
            'ok'      => true,
            'message' => 'حجم به حساب پنل شما اضافه شد.',
            'details' => $details,
        ];
    }

    // ------------------------------------------------------------------
    // اجرای بستهٔ user_credit (اعتبار ساخت کاربر)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $order
     * @param  array<string, mixed> $user
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    private function applyUserCredit(array $order, array $user): array
    {
        $packageBytes = Str::gbToBytes((float) $order['volume_gb'] + (float) ($order['bonus_gb'] ?? 0));
        if ($packageBytes <= 0) {
            return $this->failTerminal((int) $order['id'], 'invalid_volume', 'حجم اعتبار این سفارش نامعتبر است.');
        }

        $userId     = (int) $user['id'];
        $durationDays = (int) $order['duration_days'];
        $expireAt   = $durationDays > 0 ? time() + $durationDays * 86400 : null;

        // اعتبار به‌صورت اتمیک افزوده می‌شود تا دو پروسهٔ همزمان (مثلاً IPN و
        // دکمهٔ «بررسی وضعیت») یک بسته را دوبار اضافه نکنند.
        if (!$this->orders->markPanelApplied((int) $order['id'], 0)) {
            // قبلاً اعمال شده — فقط نهایی‌سازی.
            return [
                'ok'      => true,
                'message' => 'بسته قبلاً اعمال شده بود.',
                'details' => ['already_applied' => true],
            ];
        }

        $this->users->addUserCredit($userId, $packageBytes, $expireAt);

        $details = [
            'applied_bytes' => $packageBytes,
            'credit_total'  => (int) ($this->users->findById($userId)['user_credit'] ?? 0),
            'expire_at'     => $expireAt,
        ];

        $this->orders->logProvision((int) $order['id'], 'user_credit', 'اعتبار ساخت کاربر افزوده شد.', $details);

        return [
            'ok'      => true,
            'message' => 'اعتبار ساخت کاربر به حساب شما اضافه شد.',
            'details' => $details,
        ];
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /**
     * بررسی اینکه آیا خودکارسازی فعال است.
     */
    public function autoApplyEnabled(): bool
    {
        return $this->settings->bool(Settings::AUTO_APPLY, true);
    }

    /**
     * رمز عبور رمزگشایی‌شدهٔ کاربر.
     *
     * @param  array<string, mixed> $user
     * @return array{0:string, 1:string}|null [panelUsername, password]
     */
    public function credentialsOf(array $user): ?array
    {
        $panelUsername = trim((string) ($user['panel_username'] ?? ''));
        $encrypted     = (string) ($user['panel_password'] ?? '');

        if ($panelUsername === '' || $encrypted === '') {
            return null;
        }

        try {
            $password = Crypto::decrypt($encrypted);
        } catch (\Throwable $e) {
            Logger::error('Cannot decrypt panel password', [
                'user_id' => $user['id'] ?? null,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }

        return [$panelUsername, $password];
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

        // خطای احراز هویت: تلاش مجدد بی‌فایده است، باید کاربر دوباره لاگین کند.
        if ($e->isAuthError()) {
            $this->users->update((int) $order['user_id'], [
                'panel_status' => 'revoked',
                'updated_at'   => time(),
            ]);

            $this->orders->markTerminal($orderId, OrderRepository::STATUS_FAILED, 'auth_error');

            return [
                'ok'      => false,
                'message' => 'اطلاعات ورود پنل نامعتبر شده است. کاربر باید دوباره وارد شود.',
                'details' => ['need_relogin' => true],
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

    /**
     * همگام‌سازی وضعیت یک کاربر از پنل (برای دکمهٔ «بروزرسانی»).
     *
     * @param  array<string, mixed> $user
     * @return array{ok:bool, message:string}
     */
    public function syncUser(array $user): array
    {
        $credentials = $this->credentialsOf($user);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'ابتدا باید به پنل وارد شوید.'];
        }

        [$panelUsername, $password] = $credentials;

        try {
            $admin = $this->panel->getAdmin($panelUsername, $panelUsername, $password);
            $this->users->syncPanelState((int) $user['id'], $admin);

            return ['ok' => true, 'message' => 'اطلاعات پنل بروزرسانی شد.'];
        } catch (PanelException $e) {
            if ($e->isAuthError()) {
                $this->users->update((int) $user['id'], ['panel_status' => 'revoked', 'updated_at' => time()]);
                return ['ok' => false, 'message' => 'اطلاعات ورود نامعتبر شده؛ دوباره وارد شوید.'];
            }

            return ['ok' => false, 'message' => 'بروزرسانی ممکن نشد: ' . $e->getMessage()];
        }
    }
}