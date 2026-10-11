<?php

declare(strict_types=1);

/**
 * ابزارک‌های مشترک تست‌ها.
 *
 * بعد از تغییر معماری، یک نماینده دیگر «ستون‌های پنل روی جدول users» نیست؛
 * هر پنل یک ردیف مستقل در جدول `panels` است. این فایل همان ساخت را در یک
 * جا جمع می‌کند تا هر تست مجبور نباشد جزئیات را تکرار کند (و اگر مدل داده
 * عوض شد، فقط همین‌جا اصلاح شود).
 */

use Pasargad\Panel\FakePanelClient;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\UserRepository;

/**
 * ساخت یک نماینده به‌همراه یک پنل متصل (روی پنل قلابی).
 *
 * @param  array<string, mixed> $options
 * @return array{0:array<string, mixed>, 1:array<string, mixed>} [user, panel]
 */
function makeRep(
    string $panelUser,
    FakePanelClient $panel,
    UserRepository $users,
    PanelRepository $panels,
    array $options = []
): array {
    $telegramId = (int) ($options['telegram_id'] ?? (800000 + (crc32($panelUser) % 90000)));

    $uid = (int) $users->upsertByTelegram($telegramId, [
        'telegram_id' => $telegramId,
        'username'    => $options['username'] ?? null,
        'first_name'  => $options['first_name'] ?? null,
    ])['id'];

    $dataLimit = (int) ($options['data_limit'] ?? 0);
    $used      = (int) ($options['used_traffic'] ?? 0);

    $panel->addAdmin($panelUser, [
        'data_limit'   => $dataLimit,
        'used_traffic' => $used,
        'status'       => (string) ($options['panel_status'] ?? 'active'),
    ]);

    // کلیدِ این پنل به همین ادمین وصل است — عین واقعیت. بدون این، `GET /api/admin`
    // «اولین ادمین» را برمی‌گرداند و شارژِ پنل دوم روی پنل اول اعمال می‌شود.
    $fixtureApiKey = array_key_exists('api_key', $options)
        ? (string) $options['api_key']
        : 'pg_key_' . $panelUser;

    if ($fixtureApiKey !== '') {
        $panel->bindApiKey($fixtureApiKey, $panelUser);
    }

    // ⚠️ `api_key` پیش‌فرض دارد چون ربات فقط با کلید کار می‌کند: پنلی که
    // کلید ندارد «وصل نیست» شمرده می‌شود و آمار/قطع دسترسی/شارژ رویش
    // کار نمی‌کند. برای تستِ «پنلِ وصل‌نشده» کافی است
    // `'api_key' => null` بدهید.
    $panelId = $panels->create($uid, $panelUser, (string) ($options['password'] ?? 'pass'), [
        'panel_status'     => PanelRepository::STATUS_ACTIVE,
        'data_limit'       => $dataLimit,
        'used_traffic'     => $used,
        'access_expire_at' => $options['expire_at'] ?? null,
        'login_url'        => 'https://panel.test',
        'api_key'          => $fixtureApiKey,
    ]);

    return [$users->findById($uid), $panels->find($panelId)];
}

/**
 * شناسهٔ پنل یک کاربر (تازه‌ترین).
 */
function primaryPanelId(PanelRepository $panels, array $user): int
{
    $panels = $panels->listByUser((int) $user['id']);

    return $panels === [] ? 0 : (int) $panels[0]['id'];
}

/**
 * ساخت سفارش پرداخت‌شده (برای تست‌هایی که مستقیم سراغ Provisioner می‌روند).
 *
 * @param array<string, mixed> $package
 * @param array<string, mixed> $extra
 */
function makePaidOrder(
    \Pasargad\Store\OrderRepository $orders,
    int $userId,
    array $package,
    array $extra = []
): array {
    $order = $orders->create($userId, array_merge([
        'package_id'    => (int) ($package['id'] ?? 0),
        'package_title' => (string) $package['title'],
        'kind'          => (string) $package['kind'],
        'volume_gb'     => (float) $package['volume_gb'],
        'bonus_gb'      => (float) ($package['bonus_gb'] ?? 0),
        'duration_days' => (int) $package['duration_days'],
        'price_toman'   => (int) $package['price_toman'],
    ], $extra));

    $orders->markPaid((int) $order['id'], 'card2card', 'test-ref');

    return $orders->find((int) $order['id']);
}