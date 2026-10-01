<?php

declare(strict_types=1);

namespace Pasargad\Panel;

use Pasargad\Support\Config;

/**
 * کلاینت ساختگی پنل برای تست‌ها — بدون تماس شبکه.
 *
 * وضعیت ادمین‌ها را در آرایه نگه می‌دارد و رفتار خطای قابل تنظیم دارد
 * تا مسیرهای خطا و تلاش مجدد هم تست شوند.
 */
final class FakePanelClient extends PasarGuardClient
{
    /** @var array<string, array<string, mixed>> */
    public array $admins = [];

    public string $loginError = '';
    public string $modifyError = '';
    public string $createUserError = '';
    public string $modifyUserError = '';

    /**
     * اگر مقداری داشته باشد، خطاهای ساختگی با این کد HTTP برگردانده می‌شوند.
     * برای تست رفتار خطاهای غیرقابل تلاش مجدد (مثل ۴۰۴) لازم است.
     */
    public int $httpStatusOverride = 0;

    /**
     * فهرست درخواست‌های ارسال‌شده به پنل — برای بررسی اینکه چند بار
     * فراخوانی انجام شده (مثلاً بررسی عدم اجرای دوباره).
     *
     * @var array<int, array{method:string, path:string}>
     */
    public array $loggedRequests = [];
    public int $modifyCalls = 0;
    public int $loginCalls = 0;

    /** @var array<int, array<string, mixed>> */
    public array $modifyRequests = [];

    /** @var array<int, array<string, mixed>> پنل کاربرانِ ساخته/تغییر‌یافته */
    public array $created = [];
    public array $modified = [];

    /** @var array<string, array<string, mixed>> وضعیت کاربران در پنل قلابی */
    public array $existingUsers = [];

    public function __construct()
    {
        // از فراخوانی سازندهٔ والد پرهیز می‌کنیم چون به شبکه نیاز دارد.
    }

    public function login(string $username, string $password, bool $useCache = true): string
    {
        $this->loginCalls++;

        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        return 'fake-token-' . $username;
    }

    public function getAdmin(string $targetUsername, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'getAdmin', 'path' => '/api/admin/' . $targetUsername];
        if ($this->loginError !== '') {
            throw new PanelException($this->loginError, 401);
        }

        if (!isset($this->admins[$targetUsername])) {
            throw new PanelException('Admin not found', 404);
        }

        return $this->admins[$targetUsername];
    }

    public function modifyAdmin(string $targetUsername, array $payload, string $username, string $password): array
    {
        $this->modifyCalls++;
        $this->modifyRequests[] = $payload;
        $this->loggedRequests[] = ['method' => 'modifyAdmin', 'path' => '/api/admin/' . $targetUsername];

        if ($this->modifyError !== '') {
            throw new PanelException($this->modifyError, $this->httpStatusOverride ?: 500);
        }

        if (!isset($this->admins[$targetUsername])) {
            throw new PanelException('Admin not found', 404);
        }

        if (isset($payload['data_limit'])) {
            $this->admins[$targetUsername]['data_limit'] = (int) $payload['data_limit'];
        }

        return $this->admins[$targetUsername];
    }

    // ------------------------------------------------------------------
    // کاربران (برای تست اعتبار ساخت کاربر)
    // ------------------------------------------------------------------

    public function createUser(array $payload, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'createUser', 'path' => '/api/user'];

        if ($this->createUserError !== '') {
            throw new PanelException($this->createUserError, $this->httpStatusOverride ?: 500);
        }

        $this->created[] = $payload;

        $this->existingUsers[(string) ($payload['username'] ?? '')] = array_merge([
            'status'       => 'active',
            'used_traffic' => 0,
            'data_limit'   => 0,
            'expire'       => 0,
        ], $payload);

        return $this->existingUsers[(string) $payload['username']];
    }

    public function modifyUser(string $targetUsername, array $payload, string $username, string $password): array
    {
        $this->loggedRequests[] = ['method' => 'modifyUser', 'path' => '/api/user/' . $targetUsername];

        if ($this->modifyUserError !== '') {
            throw new PanelException($this->modifyUserError, $this->httpStatusOverride ?: 500);
        }

        $this->modified[] = array_merge(['username' => $targetUsername], $payload);

        $this->existingUsers[$targetUsername] = array_merge(
            $this->existingUsers[$targetUsername] ?? [],
            $payload
        );

        return $this->existingUsers[$targetUsername];
    }

    public function getUser(string $targetUsername, string $username, string $password): array
    {
        if (!isset($this->existingUsers[$targetUsername])) {
            throw new PanelException('Not found', 404);
        }

        return $this->existingUsers[$targetUsername];
    }

    public function listUsers(string $username, string $password, array $query = []): array
    {
        return [
            'users' => array_values($this->existingUsers),
            'total' => count($this->existingUsers),
        ];
    }

    /**
     * ثبت یک ادمین ساختگی.
     *
     * @param array<string, mixed> $overrides
     */
    public function addAdmin(string $username, array $overrides = []): void
    {
        $this->admins[$username] = array_merge([
            'id'            => 1,
            'username'      => $username,
            'status'        => 'active',
            'data_limit'    => 0,
            'used_traffic'  => 0,
            'role'          => ['id' => 1, 'name' => 'admin', 'is_owner' => false],
            'is_limited'    => false,
            'is_disabled'   => false,
        ], $overrides);
    }
}