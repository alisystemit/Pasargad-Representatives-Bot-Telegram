<?php

declare(strict_types=1);

/**
 * مدیریت سوییچ‌های ربات از خط فرمان (جایگزین سریع پنل تلگرام).
 *
 * استفاده:
 *   php tools/switches.php list
 *   php tools/switches.php bot on|off
 *   php tools/switches.php gateway card2card|autocard|nowpayments on|off
 *   php tools/switches.php testconfig on|off
 *   php tools/switches.php sync on|off
 *   php tools/switches.php cutoff on|off
 *   php tools/switches.php channel on|off
 *   php tools/switches.php channel-name @my_channel
 *   php tools/switches.php warn-days 5
 *   php tools/switches.php grace-days 3
 *   php tools/switches.php coupons on|off
 *   php tools/switches.php referral on|off
 *   php tools/switches.php tickets on|off
 *   php tools/switches.php referral-percent 10
 *   php tools/switches.php referral-bonus 50000
 *   php tools/switches.php test-volume 2
 *   php tools/switches.php notice "متن دلخواه"
 *   php tools/switches.php notice --reset
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این ابزار فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Payment\CardToCardGateway;
use Pasargad\Payment\AutoCardGateway;
use Pasargad\Payment\NowPaymentsGateway;
use Pasargad\Store\AgencyService;
use Pasargad\Store\CouponRepository;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\Settings;
use Pasargad\Store\TicketRepository;
use Pasargad\Support\Backup;
use Pasargad\Support\Config;
use Pasargad\Support\Db;

$settings = new Settings(Db::instance());
$flags    = new FeatureFlags($settings);

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

/**
 * خواندن on|off با بررسی ورودی.
 */
function onOff(?string $arg): ?bool
{
    if ($arg === 'on') {
        return true;
    }

    if ($arg === 'off') {
        return false;
    }

    return null;
}

switch ($command) {
    case 'list':
        out('وضعیت فعلی سوییچ‌ها:', 'info');
        out('');
        out('ربات:            ' . icon($flags->isBotEnabled()));
        out('کارت‌به‌کارت:    ' . icon($flags->isGatewayEnabled(CardToCardGateway::NAME)));
        out('کارت خودکار:    ' . icon($flags->isGatewayEnabled(AutoCardGateway::NAME)));
        out('ارز دیجیتال:     ' . icon($flags->isGatewayEnabled(NowPaymentsGateway::NAME)));
        out('فروشگاه:         ' . icon($settings->bool(Settings::SHOP_OPENED, true)));
        out('اجرای خودکار:    ' . icon($settings->bool(Settings::AUTO_APPLY, true)));
        out('');
        out('— نمایندگان —', 'info');
        out('همگام‌سازی پنل:  ' . icon($flags->isPanelSyncEnabled()));
        out('تست کانفیگ:      ' . icon($flags->isTestConfigEnabled()));
        out('قطع پس از انقضا: ' . icon($flags->isCutoffOnExpireEnabled()));
        out('ساخت پنل جدید:   ' . icon((new AgencyService())->canCreatePanels()['ok']));
        out('هشدار انقضا:     ' . $settings->int(Settings::EXPIRE_WARN_DAYS, 3) . ' روز قبل');
        out('مهلت ارفاقی:     ' . $flags->graceDays() . ' روز پس از انقضا');
        out('هشدار حجم:       ' . $settings->int(Settings::LOW_VOLUME_ALERT, 5) . '٪ باقی‌مانده');
        out('تست حجم:         ' . $settings->get(Settings::TEST_CONFIG_VOLUME_GB, '1') . ' گیگ / '
            . $settings->int(Settings::TEST_CONFIG_DAYS, 1) . ' روز');
        out('سقف تست همزمان:  ' . $settings->int(Settings::TEST_CONFIG_MAX, 2)
            . ' • فاصله: ' . $settings->int(Settings::TEST_CONFIG_COOLDOWN, 30) . ' دقیقه');
        out('عمر آمار پنل:    ' . $settings->int(Settings::PANEL_STATS_TTL, 30) . ' دقیقه');
        out('');
        out('— رشد و نگهداشت —', 'info');
        out('کد تخفیف:        ' . icon($flags->isCouponsEnabled())
            . ' (' . (new CouponRepository())->countActive() . ' کد فعال)');
        out('معرفی:           ' . icon($flags->isReferralEnabled())
            . ' (تخفیف ' . $settings->int(Settings::REFERRAL_DISCOUNT, 10)
            . '٪ • پاداش ' . number_format($settings->int(Settings::REFERRAL_BONUS, 50000)) . ' تومان)');
        out('تیکت پشتیبانی:   ' . icon($flags->isTicketsEnabled())
            . ' (' . (new TicketRepository())->countOpen() . ' تیکت باز)');
        out('نگهداری بکاپ:    ' . Backup::keepCount() . ' نسخه');
        out('ربات مدیریتی:    ' . icon(Config::str('admin_bot_token', '') !== '')
            . (Config::str('admin_bot_token', '') !== '' ? '' : ' (admin_bot_token تنظیم نشده)'));
        out('');
        out('— کانال و قوانین —', 'info');
        out('عضویت اجباری:    ' . icon($settings->bool(Settings::CHANNEL_ENFORCED, false)));
        out('کانال:           ' . ($settings->get(Settings::CHANNEL, '') ?: '—'));
        out('');
        out('متن غیرفعالی فعلی (' . mb_strlen($flags->disabledNotice()) . ' کاراکتر):');
        out($flags->disabledNotice());
        break;

    case 'bot':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php bot on|off', 'err');
            exit(1);
        }

        $flags->setBotEnabled($mode);
        out('کل ربات ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');

        if (!$mode) {
            out('کاربران عادی پیام تعیین‌شده را می‌بینند؛ سوپرادمین‌ها دسترسی دارند.', 'warn');
        }
        break;

    case 'gateway':
        $name = $arg;
        $mode = onOff($argv[3] ?? null);

        if (!in_array($name, [CardToCardGateway::NAME, AutoCardGateway::NAME, NowPaymentsGateway::NAME], true)) {
            out('قالب: php tools/switches.php gateway card2card|autocard|nowpayments on|off', 'err');
            exit(1);
        }

        if ($mode === null) {
            out('قالب: php tools/switches.php gateway ' . $name . ' on|off', 'err');
            exit(1);
        }

        $flags->setGatewayEnabled($name, $mode);

        out('درگاه «' . $name . '» ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');

        if (!$mode) {
            $stillOn = false;
            foreach ([CardToCardGateway::NAME, AutoCardGateway::NAME, NowPaymentsGateway::NAME] as $other) {
                if ($other !== $name && $flags->isGatewayEnabled($other)) {
                    $stillOn = true;
                }
            }

            if (!$stillOn) {
                out('هشدار: دیگر هیچ روش پرداختی فعال نیست.', 'warn');
            }
        }
        break;

    case 'testconfig':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php testconfig on|off', 'err');
            exit(1);
        }

        $flags->setTestConfigEnabled($mode);
        out('تست کانفیگ ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'sync':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php sync on|off', 'err');
            exit(1);
        }

        $settings->set(Settings::PANEL_SYNC, $mode ? '1' : '0');
        out('همگام‌سازی خودکار پنل‌ها ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');

        if (!$mode) {
            out('هشدار: بدون همگام‌سازی، هشدار حجم بر اساس دادهٔ کهنه ساخته می‌شود.', 'warn');
        }
        break;

    case 'cutoff':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php cutoff on|off', 'err');
            exit(1);
        }

        $settings->set(Settings::CUTOFF_ON_EXPIRE, $mode ? '1' : '0');
        out('درخواست قطع دسترسی پس از انقضا ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'channel':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php channel on|off', 'err');
            exit(1);
        }

        if ($mode && trim((string) $settings->get(Settings::CHANNEL, '')) === '') {
            out('ابتدا کانال را تعیین کنید: php tools/switches.php channel-name @my_channel', 'err');
            exit(1);
        }

        $settings->set(Settings::CHANNEL_ENFORCED, $mode ? '1' : '0');
        out('عضویت اجباری کانال ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'channel-name':
        if ($arg === null) {
            out('کانال فعلی: ' . ($settings->get(Settings::CHANNEL, '') ?: '—'), 'info');
            out('برای تغییر: php tools/switches.php channel-name @my_channel', 'info');
            out('برای پاک کردن: php tools/switches.php channel-name --clear', 'info');
            break;
        }

        if ($arg === '--clear') {
            $settings->set(Settings::CHANNEL, '');
            out('کانال پاک شد و عضویت اجباری غیرفعال گردید.', 'ok');
            break;
        }

        $value = ltrim($arg, '@');

        if (preg_match('/^[A-Za-z0-9_+\-]{4,64}$/', $value) !== 1) {
            out('نام کانال نامعتبر است. مثال: @my_channel', 'err');
            exit(1);
        }

        $settings->set(Settings::CHANNEL, $value);
        out('کانال روی @' . $value . ' تنظیم شد.', 'ok');
        break;

    case 'warn-days':
        $days = (int) $arg;
        if ($days < 0 || $days > 90) {
            out('قالب: php tools/switches.php warn-days 0..90', 'err');
            exit(1);
        }

        $settings->set(Settings::EXPIRE_WARN_DAYS, (string) $days);
        out('هشدار انقضا ' . $days . ' روز قبل تنظیم شد.', 'ok');
        break;

    case 'grace-days':
        $days = (int) $arg;

        if ($days < 0 || $days > 60) {
            out('قالب: php tools/switches.php grace-days 0..60', 'err');
            exit(1);
        }

        $settings->set(Settings::EXPIRE_GRACE_DAYS, (string) $days);

        out('مهلت ارفاقی روی ' . $days . ' روز تنظیم شد.', 'ok');

        if ($days === 0) {
            out('هشدار: با مهلت صفر، به‌محض انقضا درخواست قطع دسترسی می‌رود.', 'warn');
        } else {
            out('پنل‌های منقضی ' . $days . ' روز فرصت تمدید دارند و بعد درخواست قطع می‌رود.', 'info');
        }
        break;

    case 'coupons':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php coupons on|off', 'err');
            exit(1);
        }

        $flags->setCouponsEnabled($mode);
        out('کدهای تخفیف ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'referral':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php referral on|off', 'err');
            exit(1);
        }

        $flags->setReferralEnabled($mode);
        out('سیستم معرفی ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'tickets':
        $mode = onOff($arg);
        if ($mode === null) {
            out('قالب: php tools/switches.php tickets on|off', 'err');
            exit(1);
        }

        $flags->setTicketsEnabled($mode);
        out('تیکت پشتیبانی ' . ($mode ? 'فعال' : 'غیرفعال') . ' شد.', 'ok');
        break;

    case 'referral-percent':
        $percent = (int) $arg;
        if ($percent < 0 || $percent > 100) {
            out('قالب: php tools/switches.php referral-percent 0..100', 'err');
            exit(1);
        }

        $settings->set(Settings::REFERRAL_DISCOUNT, (string) $percent);
        out('تخفیف معرفی روی ' . $percent . '٪ تنظیم شد.', 'ok');
        break;

    case 'referral-bonus':
        $bonus = (int) $arg;
        if ($bonus < 0 || $bonus > 100000000) {
            out('قالب: php tools/switches.php referral-bonus 0..100000000', 'err');
            exit(1);
        }

        $settings->set(Settings::REFERRAL_BONUS, (string) $bonus);
        out('پاداش معرف روی ' . number_format($bonus) . ' تومان تنظیم شد.', 'ok');
        break;

    case 'low-volume':
        $percent = (int) $arg;
        if ($percent < 1 || $percent > 50) {
            out('قالب: php tools/switches.php low-volume 1..50', 'err');
            exit(1);
        }

        $settings->set(Settings::LOW_VOLUME_ALERT, (string) $percent);
        out('آستانهٔ هشدار حجم روی ' . $percent . '٪ باقی‌مانده تنظیم شد.', 'ok');
        break;

    case 'test-volume':
        $gb = (float) $arg;
        if ($gb < 0.1 || $gb > 10) {
            out('قالب: php tools/switches.php test-volume 0.1..10 (گیگابایت)', 'err');
            exit(1);
        }

        $settings->set(Settings::TEST_CONFIG_VOLUME_GB, (string) $gb);
        out('حجم کانفیگ تست روی ' . $gb . ' گیگابایت تنظیم شد.', 'ok');
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
            . "  gateway <card2card|autocard|nowpayments> on|off  مدیریت درگاه پرداخت\n"
            . "  testconfig on|off                             فعال/غیرفعال تست کانفیگ\n"
            . "  sync on|off                                   همگام‌سازی خودکار پنل‌ها\n"
            . "  cutoff on|off                                 قطع دسترسی پس از انقضا\n"
            . "  channel on|off                                عضویت اجباری کانال\n"
            . "  channel-name @name | --clear                  تعیین/پاک کردن کانال\n"
            . "  warn-days 0..90                               هشدار چند روز قبل انقضا\n"
            . "  low-volume 1..50                              آستانهٔ هشدار حجم (درصد)\n"
            . "  test-volume 0.1..10                           حجم کانفیگ تست (گیگ)\n"
            . "  notice \"متن\" | notice --reset               متن غیرفعالی ربات");

        exit($command === 'help' ? 0 : 1);
}