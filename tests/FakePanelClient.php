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
    public int $modifyCalls = 0;
    public int $loginCalls = 0;

    /** @var array<int, array<string, mixed>> */
    public array $modifyRequests = [];

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

        if ($this->modifyError !== '') {
            throw new PanelException($this->modifyError, 500);
        }

        if (!isset($this->admins[$targetUsername])) {
            throw new PanelException('Admin not found', 404);
        }

        if (isset($payload['data_limit'])) {
            $this->admins[$targetUsername]['data_limit'] = (int) $payload['data_limit'];
        }

        return $this->admins[$targetUsername];
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