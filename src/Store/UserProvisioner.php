<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * ساخت و تمدید کاربر روی پنل با استفاده از «اعتبار کاربر» خریداری‌شده.
 *
 * این سرویس مکمل بسته‌های نوع user_credit است: ادمین نماینده با حجمی که
 * خریداری کرده، برای مشتریان خود کاربر می‌سازد یا تمدید می‌کند.
 *
 * قاعدهٔ کسر اعتبار: فقط وقتی پنل با موفقیت پاسخ داد، اعتبار کسر می‌شود.
 * اگر پنل خطا دهد، اعتبار دست‌نخورده می‌ماند تا ضرری به کاربر نرسد.
 */
final class UserProvisioner
{
    private PasarGuardClient $panel;
    /**
     * حداقل حجم قابل سفارش (بایت) — یک گیگابایت.
     *
     * چرا؟ مقدار data_limit صفر در پنل یعنی «نامحدود». اگر کاربر عددی مثل
     * 0.0000000001 بفرستد، تبدیل به بایت صفر می‌شود، بررسی اعتبار رد نمی‌شود
     * (چون 0 > 0 غلط است) و کاربر نامحدود را رایگان می‌گیرد.
     */
    public const MIN_BYTES = 1073741824;

    /**
     * حداکثر حجم قابل سفارش در یک درخواست (۱۰ ترابایت).
     */
    public const MAX_BYTES = 10995116277760;

    private UserRepository $users;

    public function __construct(UserRepository $users, ?PasarGuardClient $panel = null)
    {
        $this->users = $users;
        $this->panel = $panel ?? new PasarGuardClient();
    }

    public function panel(): PasarGuardClient
    {
        return $this->panel;
    }

    /**
     * ساخت کاربر جدید با اعتبار موجود.
     *
     * @param  array<string, mixed> $adminUser رکورد کاربر ربات (ادمین خریدار)
     * @return array{ok:bool, message:string, username?:string, data?:array<string, mixed>}
     */
    public function createUser(array $adminUser, string $username, float $volumeGb, int $days): array
    {
        $username = trim($username);

        if (!Str::isValidPanelUsername($username)) {
            return ['ok' => false, 'message' => 'نام کاربری نامعتبر است. فقط حروف انگلیسی، عدد، _ و - مجاز است.'];
        }

        // اعتبارسنجی بر اساس بایتِ نهایی، نه عدد اعشاری ورودی.
        // عددی مثل 0.0000000001 به صفر بایت تبدیل می‌شود که در پنل
        // «نامحدود» است، پس باید همین‌جا رد شود.
        $needed = Str::gbToBytes($volumeGb);

        if ($needed < self::MIN_BYTES) {
            return ['ok' => false, 'message' => 'حداقل حجم قابل سفارش ۱ گیگابایت است.'];
        }

        if ($needed > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'حداکثر حجم در یک درخواست ۱۰ ترابایت است.'];
        }

        if ($days <= 0) {
            return ['ok' => false, 'message' => 'مدت زمان باید بزرگ‌تر از صفر باشد.'];
        }

        // اتصال پنل قبل از بررسی اعتبار بررسی می‌شود تا پیام درستی به کاربر برسد.
        $credentials = $this->credentialsOf($adminUser);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'ابتدا باید به پنل وارد شوید (/login).'];
        }

        [$panelUsername, $password] = $credentials;

        $credit = $this->availableCredit($adminUser);

        if ($credit < $needed) {
            return [
                'ok'      => false,
                'message' => 'اعتبار کافی ندارید. نیاز: ' . Str::formatBytes($needed) . ' — موجودی: ' . Str::formatBytes($credit),
            ];
        }

        $expire = time() + $days * 86400;

        try {
            $response = $this->panel->createUser([
                'username'    => $username,
                'data_limit'  => $needed,
                'expire'      => $expire,
                'status'      => 'active',
                'note'        => 'ساخته‌شده توسط ربات نمایندگان (توسط ' . $panelUsername . ')',
            ], $panelUsername, $password);
        } catch (PanelException $e) {
            Logger::warning('Create user on panel failed', [
                'panel_username' => $panelUsername,
                'target'         => $username,
                'error'          => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'ساخت کاربر ناموفق بود: ' . $e->getMessage()];
        }

        // فقط بعد از موفقیت پنل، اعتبار کسر می‌شود.
        $consumed = $this->users->consumeUserCredit((int) $adminUser['id'], $needed);
        if (!$consumed) {
            Logger::error('Credit consumption failed after successful panel call', [
                'user_id' => $adminUser['id'],
                'bytes'   => $needed,
            ]);
        }

        $remaining = $consumed
            ? (int) ($this->users->findById((int) $adminUser['id'])['user_credit'] ?? 0)
            : $credit - $needed;

        return [
            'ok'       => true,
            'message'  => 'کاربر <code>' . $username . '</code> ساخته شد.',
            'username' => $username,
            'data'     => [
                'response'  => $response,
                'bytes'     => $needed,
                'expire'    => $expire,
                'remaining' => $remaining,
            ],
        ];
    }

    /**
     * تمدید یا اصلاح حجم یک کاربر موجود.
     *
     * حجم جدید = حجم فعلی + حجم درخواستی (نه جایگزینی)، تا اعتبار تلف نشود.
     *
     * @param  array<string, mixed> $adminUser
     * @return array{ok:bool, message:string, username?:string}
     */
    public function extendUser(array $adminUser, string $username, float $volumeGb, int $days): array
    {
        $username = trim($username);

        if (!Str::isValidPanelUsername($username)) {
            return ['ok' => false, 'message' => 'نام کاربری نامعتبر است.'];
        }

        $additional = Str::gbToBytes($volumeGb);

        if ($volumeGb != 0.0 && ($additional < self::MIN_BYTES || $additional > self::MAX_BYTES)) {
            return ['ok' => false, 'message' => 'حجم باید بین ۱ گیگابایت تا ۱۰ ترابایت باشد.'];
        }

        if ($additional === 0 && $days <= 0) {
            return ['ok' => false, 'message' => 'حجم یا مدت زمان باید بزرگ‌تر از صفر باشد.'];
        }

        if ($days < 0) {
            return ['ok' => false, 'message' => 'مدت زمان نمی‌تواند منفی باشد.'];
        }

        // اتصال پنل قبل از بررسی اعتبار بررسی می‌شود تا پیام درستی به کاربر برسد.
        $credentials = $this->credentialsOf($adminUser);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'ابتدا باید به پنل وارد شوید (/login).'];
        }

        [$panelUsername, $password] = $credentials;

        $credit = $this->availableCredit($adminUser);

        if ($additional > $credit) {
            return [
                'ok'      => false,
                'message' => 'اعتبار کافی ندارید. نیاز: ' . Str::formatBytes($additional) . ' — موجودی: ' . Str::formatBytes($credit),
            ];
        }

        try {
            $existing = $this->panel->getUser($username, $panelUsername, $password);

            $currentLimit = (int) ($existing['data_limit'] ?? 0);
            $currentUsed  = (int) ($existing['used_traffic'] ?? 0);
            $currentExpire = (int) ($existing['expire'] ?? 0);

            $payload = [];

            if ($additional > 0) {
                // سقف صفر یعنی «نامحدود». اگر کاربر نامحدود بود و حجم اضافه
                // می‌کنیم، باید از مصرف فعلی شروع کنیم نه از صفر، وگرنه سقف
                // جدید زیر مصرف واقعی می‌افتد و کاربر سرویسش قطع می‌شود.
                $base = $currentLimit > 0 ? $currentLimit : $currentUsed;
                $payload['data_limit'] = $base + $additional;
            }

            if ($days > 0) {
                $base = $currentExpire > time() ? $currentExpire : time();
                $payload['expire'] = $base + $days * 86400;
            }

            if ($payload === []) {
                return ['ok' => false, 'message' => 'هیچ تغییری برای اعمال وجود ندارد.'];
            }

            // اگر کاربر قبلاً منقضی یا غیرفعال بوده، دوباره فعال می‌شود.
            $status = (string) ($existing['status'] ?? '');
            if (in_array($status, ['expired', 'disabled', 'on_hold'], true)) {
                $payload['status'] = 'active';
            }

            $response = $this->panel->modifyUser($username, $payload, $panelUsername, $password);
        } catch (PanelException $e) {
            Logger::warning('Extend user on panel failed', [
                'panel_username' => $panelUsername,
                'target'         => $username,
                'error'          => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'تمدید ناموفق بود: ' . $e->getMessage()];
        }

        if ($additional > 0 && !$this->users->consumeUserCredit((int) $adminUser['id'], $additional)) {
            Logger::error('Credit consumption failed after successful panel call', [
                'user_id' => $adminUser['id'],
                'bytes'   => $additional,
            ]);
        }

        $changes = [];
        if (isset($payload['data_limit'])) {
            $changes[] = 'حجم: ' . Str::formatBytes($currentLimit) . ' ← ' . Str::formatBytes($payload['data_limit']);
        }
        if (isset($payload['expire'])) {
            $changes[] = 'انقضا: ' . Str::date($payload['expire']);
        }

        return [
            'ok'       => true,
            'message'  => 'کاربر <code>' . $username . '</code> تمدید شد.',
            'username' => $username,
            'data'     => [
                'response' => $response,
                'changes'  => $changes,
            ],
        ];
    }

    /**
     * لیست کاربران پنل برای نمایش به ادمین نماینده.
     *
     * @param  array<string, mixed> $adminUser
     * @return array{ok:bool, message:string, users?:array<int, array<string, mixed>>}
     */
    public function listUsers(array $adminUser, string $search = ''): array
    {
        $credentials = $this->credentialsOf($adminUser);
        if ($credentials === null) {
            return ['ok' => false, 'message' => 'ابتدا باید به پنل وارد شوید (/login).'];
        }

        [$panelUsername, $password] = $credentials;

        $query = ['limit' => 20];
        if ($search !== '') {
            $query['search'] = $search;
        }

        try {
            $response = $this->panel->listUsers($panelUsername, $password, $query);
        } catch (PanelException $e) {
            return ['ok' => false, 'message' => 'دریافت لیست کاربران ناموفق بود: ' . $e->getMessage()];
        }

        $users = is_array($response['users'] ?? null) ? $response['users'] : [];

        return ['ok' => true, 'message' => '', 'users' => $users];
    }

    /**
     * اعتبار قابل استفادهٔ کاربر، با در نظر گرفتن انقضا.
     *
     * اگر اعتبار منقضی شده باشد، صفر برگردانده می‌شود تا کاربر نتواند از
     * اعتبار Consumed-شدهٔ قدیمی استفاده کند.
     *
     * @param array<string, mixed> $adminUser
     */
    private function availableCredit(array $adminUser): int
    {
        $credit = (int) ($adminUser['user_credit'] ?? 0);
        $expire = $adminUser['user_credit_expire'] ?? null;

        if ($expire !== null && (int) $expire <= time()) {
            return 0;
        }

        return max(0, $credit);
    }

    /**
     * @param  array<string, mixed> $adminUser
     * @return array{0:string, 1:string}|null
     */
    private function credentialsOf(array $adminUser): ?array
    {
        $panelUsername = trim((string) ($adminUser['panel_username'] ?? ''));
        $encrypted     = (string) ($adminUser['panel_password'] ?? '');

        if ($panelUsername === '' || $encrypted === '') {
            return null;
        }

        try {
            return [$panelUsername, \Pasargad\Support\Crypto::decrypt($encrypted)];
        } catch (\Throwable) {
            return null;
        }
    }
}