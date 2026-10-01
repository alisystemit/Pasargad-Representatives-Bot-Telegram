<?php

declare(strict_types=1);

/**
 * endpoint اعلان پرداخت (IPN) برای درگاه NOWPayments.
 *
 * آدرس ثبت‌شده در تنظیمات: {base_url}/nowpayments_ipn.php
 */

require_once __DIR__ . '/bootstrap.php';

use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Only POST is allowed.']);
    exit;
}

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
    if (str_starts_with($key, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$name] = (string) $value;
    }
}

try {
    $db = Db::instance();
    (new Migrator($db))->migrate();

    $service = new PaymentService(
        new OrderRepository($db),
        new Provisioner(null, new OrderRepository($db), new UserRepository($db), new \Pasargad\Store\Settings($db)),
        new \Pasargad\Store\Settings($db)
    );

    $result = $service->handleIpn($payload, $headers);

    Logger::info('IPN processed', [
        'ok'      => $result['ok'] ?? false,
        'payment' => $payload['payment_id'] ?? null,
        'order'   => $payload['order_id'] ?? null,
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