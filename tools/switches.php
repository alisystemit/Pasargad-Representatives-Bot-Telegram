<?php

declare(strict_types=1);

/**
 * مدیریت سوییچ‌های ربات از خط فرمان (جایگزین سریع پنل تلگرام).
 *
 * استفاده:
 *   php tools/switches.php list
 *   php tools/switches.php bot on|off
 *   php tools/switches.php gateway card2card|nowpayments on|off
 *   php tools/switches.php renewal on|off
 *   php tools/switches.php usertools on|off
 *   php tools/switches.php notice "متن دلخواه"
 *   php tools/switches.php notice --reset
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این ابزار فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Payment\CardToCardGateway;
use Pasargad\Payment\NowPaymentsGateway;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\Settings;
use Pasargad\Support\Config;
use Pasargad\Support\Db;

$flags = new FeatureFlags(new Settings(Db::instance()));

$command = $argv[1] ?? 'list';
$arg     = $argv[2] ?? null;

function out(string $message, string $type = 'info'): void
{
    $icons = ['info' => 'ℹ️ ', 'ok' => '✅ ', 'warn' => '⚠️  ', 'err' => '❌ '];
    echo ($icons[$type] ?? '') . $message . PHP_EOL;
}

function icon(bool $value): string
{
    return $value ? '🟢 فعال' : '🔴 غیرفعال';
}

switch ($command) {
    case 'list':
        out('وضعیت فعلی سوییچ‌ها:', 'info');
        out('');
        out('ربات:            ' . icon($flags->isBotEnabled()));
        out('کارت‌به‌کارت:    ' . icon($flags->isGatewayEnabled(CardToCardGateway::NAME)));
        out('ارز دیجیتال:     ' . icon($flags->isGatewayEnabled(NowPaymentsGateway::NAME)));
        out('تمدید:           ' . icon($flags->isRenewalEnabled()));
        out('ابزار کاربر:     ' . icon($flags->isUserToolsEnabled()));
        out('فروشگاه:         ' . icon($flags->settings()->bool(\Pasargad\Store\Settings::SHOP_OPENED, true)));
        out('اجرای خودکار:    ' . icon($flags->settings()->bool(\Pasargad\Store\Settings::AUTO_APPLY, true)));
        out('');
        out('متن غیرفعالی فعلی (' . mb_strlen($flags->disabledNotice()) . ' کاراکتر):');
        out($flags->disabledNotice());
        break;

    case 'bot':
        if (!in_array($arg, ['on', 'off'], true)) {
            out('قالب: php tools/switches.php bot on|off', 'err');
            exit(1);
        }
        $flags->setBotEnabled($arg === 'on');
        out('کل ربات ' . ($arg === 'on' ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        if ($arg === 'off') {
            out('کاربران عادی پیام تعیین‌شده را می‌بینند؛ سوپرادمین‌ها دسترسی دارند.', 'warn');
        }
        break;

    case 'gateway':
        $name = $arg;
        $mode = $argv[3] ?? null;

        if (!in_array($name, [CardToCardGateway::NAME, NowPaymentsGateway::NAME], true)) {
            out('قالب: php tools/switches.php gateway card2card|nowpayments on|off', 'err');
            exit(1);
        }

        if (!in_array($mode, ['on', 'off'], true)) {
            out('قالب: php tools/switches.php gateway ' . $name . ' on|off', 'err');
            exit(1);
        }

        $flags->setGatewayEnabled($name, $mode === 'on');
        out('درگاه «' . $name . '» ' . ($mode === 'on' ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');

        if ($mode === 'off') {
            $stillOn = false;
            foreach ([CardToCardGateway::NAME, NowPaymentsGateway::NAME] as $other) {
                if ($other !== $name && $flags->isGatewayEnabled($other)) {
                    $stillOn = true;
                }
            }
            if (!$stillOn) {
                out('هشدار: دیگر هیچ روش پرداختی فعال نیست.', 'warn');
            }
        }
        break;

    case 'renewal':
        if (!in_array($arg, ['on', 'off'], true)) {
            out('قالب: php tools/switches.php renewal on|off', 'err');
            exit(1);
        }
        $flags->setRenewalEnabled($arg === 'on');
        out('قابلیت تمدید ' . ($arg === 'on' ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'usertools':
        if (!in_array($arg, ['on', 'off'], true)) {
            out('قالب: php tools/switches.php usertools on|off', 'err');
            exit(1);
        }
        $flags->setUserToolsEnabled($arg === 'on');
        out('ابزار ساخت/تمدید کاربر ' . ($arg === 'on' ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'notice':
        if ($arg === '--reset') {
            $flags->resetDisabledNotice();
            out('متن غیرفعالی به حالت پیش‌فرض بازگردانی شد.', 'ok');
            break;
        }

        if ($arg === null) {
            out('متن فعلی:', 'info');
            out($flags->disabledNotice());
            out('');
            out('برای تغییر: php tools/switches.php notice "متن جدید"', 'info');
            break;
        }

        // بقیهٔ آرگومان‌ها به هم می‌چسبند تا متن چندخطی هم ممکن باشد
        $text = trim(implode(' ', array_slice($argv, 2)));

        if (mb_strlen($text) > 4000) {
            out('متن بیش از حد طولانی است (حداکثر ۴۰۰۰ کاراکتر).', 'err');
            exit(1);
        }

        $flags->setDisabledNotice($text);
        out('متن غیرفعالی ذخیره شد (' . mb_strlen($text) . ' کاراکتر).', 'ok');
        break;

    default:
        out("دستورهای موجود:\n"
            . "  list                                          نمایش وضعیت\n"
            . "  bot on|off                                    فعال/غیرفعال کردن کل ربات\n"
            . "  gateway <card2card|nowpayments> on|off        مدیریت درگاه پرداخت\n"
            . "  renewal on|off                                فعال/غیرفعال تمدید\n"
            . "  usertools on|off                              فعال/غیرفعال ابزار کاربر\n"
            . "  notice \"متن\" | notice --reset              متن غیرفعالی ربات");
        exit($command === 'help' ? 0 : 1);
}