<?php

declare(strict_types=1);

/**
 * endpoint اعلان پرداخت (IPN) برای درگاه NOWPayments.
 *
 * آدرس ثبت‌شده در تنظیمات: {base_url}/nowpayments_ipn.php
 */

require_once __DIR__ . '/bootstrap.php';

use Pasargad\Bot\Notifier;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\BotApi;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Only POST is allowed.']);
    exit;
}

// ------------------------------------------------------------------
// بدنهٔ خام را نگه می‌داریم — امضای HMAC روی همین بایت‌ها حساب شده است.
// دوباره کدگذاری کردن payload خطا می‌دهد (escape یونیکد/اسلش فرق می‌کند)
// و باعث رد شدن تمام پرداخت‌ها می‌شود.
// ------------------------------------------------------------------
$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    Logger::warning('IPN payload is not valid JSON', ['size' => strlen($rawBody)]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid payload.']);
    exit;
}

// هدرها به‌صورت lowercase جمع‌آوری می‌شوند.
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $name         = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = (string) $value;
    }
}

try {
    $db = Db::instance();

    // مایگریشن‌ها روی مسیر داغ هر درخواست اجرا نمی‌شوند؛ فقط اگر واقعاً
    // نسخهٔ پایین باشند. حالت عادی در cli.php migrate انجام شده است.
    (new Migrator($db))->migrateWhenOutdated();

    $settings = new Settings($db);
    $orders   = new OrderRepository($db);
    $users    = new UserRepository($db);

    $service = new PaymentService(
        $orders,
        new Provisioner(null, $orders, $users, $settings),
        $settings,
        null,
        $users
    );

    // بدون این، اعلان «بسته اجرا شد» به کاربر و هشدار خطا به ادمین هرگز
    // ارسال نمی‌شود چون notifier تهی می‌ماند.
    try {
        $service->setNotifier(new Notifier(new BotApi()));
    } catch (Throwable $e) {
        Logger::warning('Notifier unavailable in IPN context', ['error' => $e->getMessage()]);
    }

    $result = $service->handleIpn($payload, $headers, $rawBody);

    Logger::info('IPN processed', [
        'ok'      => $result['ok'] ?? false,
        'payment' => $payload['payment_id'] ?? null,
        'order'   => $payload['order_id'] ?? null,
        'applied' => $result['applied'] ?? null,
    ]);

    // NOWPayments انتظار 200 دارد؛ برای خطاهای داخلی هم 200 می‌دهیم
    // تا درخواست بی‌نهایت تکرار نشود (خطا در لاگ ثبت می‌شود).
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    Logger::error('IPN processing failed', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile() . ':' . $e->getLine(),
    ]);

    echo json_encode(['ok' => true]);
}