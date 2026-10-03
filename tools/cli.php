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

        case 'set-webhook-admin':
            // وبهوک دوم برای ربات مدیریتی (اختیاری)
            $adminToken = trim(Config::str('admin_bot_token', ''));

            if ($adminToken === '') {
                out('admin_bot_token در config.php تنظیم نشده است — وبهوک مدیریتی غیرفعال است.', 'warn');
                out('برای فعال‌سازی: یک ربات دوم از @BotFather بسازید و توکنش را بگذارید.', 'info');
                break;
            }

            $adminSecret = trim(Config::str('admin_webhook_secret', ''));

            if ($adminSecret === '' || $adminSecret === 'CHANGE-THIS-RANDOM-SECRET') {
                out('ابتدا admin_webhook_secret را در config.php مقداردهی کنید (یک رشتهٔ تصادفی).', 'warn');
                out('پیشنهاد: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"', 'info');
                break;
            }

            $adminUrl  = rtrim(Config::str('base_url'), '/') . '/admin.php';
            $adminBot  = new \Pasargad\Telegram\BotApi($adminToken);
            $result    = $adminBot->call('setWebhook', [
                'url'                 => $adminUrl,
                'secret_token'        => $adminSecret,
                'allowed_updates'     => json_encode(['message', 'callback_query']),
                'drop_pending_updates' => 'true',
                'max_connections'     => '10',
            ]);

            if ($result['ok'] ?? false) {
                out('وبهوک مدیریتی تنظیم شد: ' . $adminUrl, 'ok');
                out('فقط پیام‌های سوپرادمین‌ها به این وبهوک می‌رسند.', 'info');
            } else {
                out('خطا: ' . json_encode($result, JSON_UNESCAPED_UNICODE), 'err');
            }
            break;

        case 'webhook-info':
            $bot = new \Pasargad\Telegram\BotApi(Config::str('bot_token'));
            out(json_encode($bot->call('getWebhookInfo'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'info');
            break;

        case 'webhook-info-admin':
            $adminToken = trim(Config::str('admin_bot_token', ''));

            if ($adminToken === '') {
                out('admin_bot_token تنظیم نشده است.', 'warn');
                break;
            }

            $adminBot = new \Pasargad\Telegram\BotApi($adminToken);
            out(json_encode($adminBot->call('getWebhookInfo'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'info');
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

        case 'backup':
            // قبل از هر تغییر پرریسک (مایگریشن جدید، تغییر کد) این را اجرا کنید:
            //   php tools/cli.php backup
            if (isset($options['keep'])) {
                $keep = max(2, min(200, (int) $options['keep']));
                (new \Pasargad\Store\Settings())->set(\Pasargad\Store\Settings::BACKUP_KEEP, (string) $keep);
                out('سقف نگهداری بکاپ روی ' . $keep . ' نسخه تنظیم شد.', 'ok');
            }

            if (isset($options['prune'])) {
                $pruned = \Pasargad\Support\Backup::pruneOld();
                out('پاک‌سازی: ' . $pruned['removed'] . ' فایل حذف شد، ' . $pruned['kept'] . ' فایل باقی است.', 'ok');
                break;
            }

            $backup = \Pasargad\Support\Backup::run();
            if ($backup['ok'] ?? false) {
                out('بکاپ ساخته شد: ' . ($backup['path'] ?? '') . ' (' . ($backup['size'] ?? 0) . ' بایت)', 'ok');
                out('سقف نگهداری: ' . \Pasargad\Support\Backup::keepCount() . ' نسخه', 'info');
            } else {
                out('خطا: ' . ($backup['message'] ?? ''), 'err');
                exit(1);
            }
            break;

        case 'help':
        default:
            out("دستورهای موجود:\n"
                . "  migrate\n"
                . "  set-webhook\n"
                . "  set-webhook-admin      وبهوک ربات مدیریتی (اختیاری)\n"
                . "  webhook-info\n"
                . "  webhook-info-admin\n"
                . "  health\n"
                . "  selftest\n"
                . "  backup [--keep=N] [--prune]");
            break;
    }
} catch (Throwable $e) {
    Logger::error('CLI command failed', ['cmd' => $command, 'error' => $e->getMessage()]);
    out($e->getMessage(), 'err');
    exit(1);
}