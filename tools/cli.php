<?php

declare(strict_types=1);

/**
 * ابزار خط فرمان: نصب/مایگریشن، ساخت وبهوک، بررسی سلامت و تست خشک.
 *
 * استفاده:
 *   php tools/cli.php migrate
 *   php tools/cli.php set-webhook
 *   php tools/cli.php health
 *   php tools/cli.php selftest
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این ابزار فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Support\Config;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;

$command = $argv[1] ?? 'help';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--')) {
        $pair = explode('=', substr($arg, 2), 2);
        $options[$pair[0]] = $pair[1] ?? true;
    }
}

function out(string $message, string $type = 'info'): void
{
    $icons = ['info' => 'ℹ️ ', 'ok' => '✅ ', 'warn' => '⚠️  ', 'err' => '❌ '];
    echo ($icons[$type] ?? '') . $message . PHP_EOL;
}

try {
    switch ($command) {
        case 'migrate':
            $db = Db::instance();
            $ran = (new Migrator($db))->migrate();
            out($ran === [] ? 'دیتابیس به‌روز است (مایگریشن جدیدی نبود).' : 'مایگریشن‌های اجراشده: ' . implode(', ', $ran), 'ok');
            break;

        case 'set-webhook':
            if (!class_exists(\Pasargad\Telegram\BotApi::class)) {
                out('کلاس BotApi هنوز ساخته نشده است.', 'warn');
                break;
            }
            $url = rtrim(Config::str('base_url'), '/') . '/bot.php';
            $bot = new \Pasargad\Telegram\BotApi(Config::str('bot_token'));
            $secret = $options['secret'] ?? Config::str('webhook_secret', '');
            if ($secret === '' || $secret === 'CHANGE-THIS-RANDOM-SECRET') {
                out('ابتدا webhook_secret را در config.php مقداردهی کنید.', 'warn');
                break;
            }
            $result = $bot->call('setWebhook', [
                'url'             => $url,
                'secret_token'    => $secret,
                'allowed_updates' => json_encode(['message', 'callback_query', 'edited_message']),
                'drop_pending_updates' => 'true',
                'max_connections' => '40',
            ]);
            if ($result['ok'] ?? false) {
                out('وبهوک تنظیم شد: ' . $url, 'ok');
            } else {
                out('خطا در تنظیم وبهوک: ' . json_encode($result, JSON_UNESCAPED_UNICODE), 'err');
            }
            break;

        case 'webhook-info':
            $bot = new \Pasargad\Telegram\BotApi(Config::str('bot_token'));
            out(json_encode($bot->call('getWebhookInfo'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'info');
            break;

        case 'health':
            $db = Db::instance();
            out('دیتابیس: ' . $db->pdo()->query('PRAGMA journal_mode')->fetchColumn(), 'ok');
            out('مایگریشن‌ها: ' . implode(', ', (new Migrator($db))->appliedMigrations()), 'info');
            out('کاربران ربات: ' . $db->count('SELECT COUNT(*) FROM users'), 'info');
            out('بسته‌ها: ' . $db->count('SELECT COUNT(*) FROM packages'), 'info');
            out('سفارش‌ها: ' . $db->count('SELECT COUNT(*) FROM orders'), 'info');
            break;

        case 'selftest':
            require __DIR__ . '/selftest.php';
            break;

        case 'help':
        default:
            out("دستورهای موجود:\n  migrate\n  set-webhook\n  webhook-info\n  health\n  selftest");
            break;
    }
} catch (Throwable $e) {
    Logger::error('CLI command failed', ['cmd' => $command, 'error' => $e->getMessage()]);
    out($e->getMessage(), 'err');
    exit(1);
}