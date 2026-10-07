<?php

declare(strict_types=1);

/**
 * ابزار توسعه: پیش‌نمایش مینی‌اپ در مرورگر معمولی.
 *
 * ⚠️ فقط برای توسعهٔ محلی. خروجی یک فایل HTML در ریشهٔ پروژه می‌سازد که
 * `initData` واقعاً معتبر دارد. اگر این فایل روی سرور آپلود شود، یعنی یک
 * راه دور زدن احراز هویت ساخته‌اید (هر کسی خود را جای هر کاربری جا بزند).
 * به همین دلیل نامش `_preview.html` است، در `.gitignore` می‌رود و در
 * `.htaccess` هم بسته شده.
 *
 * نحوهٔ استفاده:
 *   1) سرور محلی را بالا بیاور:  php -S 127.0.0.1:8081
 *   2) این اسکریپت را اجرا کن:   php tools/preview.php 123456 علی
 *   3) در مرورگر باز کن:         http://127.0.0.1:8081/_preview.html
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);

require_once $root . '/bootstrap.php';

use Pasargad\Support\Config;

/** امضای HMAC یک initData معتبر، دقیقاً همان چیزی که InitData انتظار دارد. */
function preview_sign_init_data(int $userId, string $firstName, string $token): string
{
    $pairs = [
        'auth_date' => (string) time(),
        'query_id'  => 'PREVIEW',
        'user'      => json_encode(
            ['id' => $userId, 'first_name' => $firstName, 'username' => 'preview'],
            JSON_UNESCAPED_UNICODE
        ),
    ];

    ksort($pairs);

    $check = [];
    foreach ($pairs as $k => $v) {
        $check[] = $k . '=' . $v;
    }

    $secret = hash_hmac('sha256', 'WebAppData', $token, true);
    $pairs['hash'] = hash_hmac('sha256', implode("\n", $check), $secret);

    $query = [];
    foreach ($pairs as $k => $v) {
        $query[] = urlencode($k) . '=' . urlencode($v);
    }

    return implode('&', $query);
}

$userId   = (int) ($argv[1] ?? 0);
$userName = (string) ($argv[2] ?? 'پیش‌نمایش');

$token = Config::str('bot_token', '');

if ($token === '' || $token === 'PUT_BOT_TOKEN_HERE') {
    fwrite(STDERR, "bot_token در config.php تنظیم نشده است.\n");
    exit(1);
}

if ($userId <= 0) {
    fwrite(STDERR, "آیدی عددی کاربر را بدهید: php tools/preview.php 123456 علی\n");
    exit(1);
}

$index = (string) file_get_contents($root . '/assets/webapp/index.html');

$boot = json_encode([
    // آدرس مطلق: فایل پیش‌نمایش در ریشهٔ سرور محلی باز می‌شود و
    // `webapp.php` کنارش است، ولی مسیر مطلق ابهام را از بین می‌برد.
    'api'        => '/webapp.php',
    'bot'        => ltrim(Config::str('bot_username', ''), '@'),
    'support'    => Config::str('notifications.support_link', ''),
    'store_name' => Config::str('store.name', 'ربات نمایندگان پنل'),
    'version'    => 'preview',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$initData = preview_sign_init_data($userId, $userName, $token);

/*
 * شبیه‌سازی `Telegram.WebApp` — فقط همان APIهایی که اپ استفاده می‌کند.
 * `initData` واقعی است پس API مثل محیط واقعی پاسخ می‌دهد؛ بقیهٔ رفتار
 * (تعامل‌هایی که فقط در کلاینت تلگرام کار می‌کنند) بی‌اثر است.
 */
$stub = <<<'JS'
<script>
(function () {
    var params = new URLSearchParams(window.__INIT_DATA__);

    window.Telegram = { WebApp: {
        initData: window.__INIT_DATA__,
        initDataUnsafe: {
            user: JSON.parse(params.get('user')),
            start_param: params.get('start_param') || ''
        },
        colorScheme: 'dark',
        themeParams: {
            bg_color: '#0b1020', secondary_bg_color: '#121933',
            text_color: '#f2f5ff', hint_color: '#8b96b8',
            link_color: '#62aeff', button_color: '#62aeff',
            button_text_color: '#ffffff',
            section_separator_color: 'rgba(255,255,255,.08)'
        },
        platform: 'web',
        isVersionAtLeast: function () { return true; },
        ready: function () {}, expand: function () {},
        close: function () { alert('نسخهٔ پیش‌نمایش است؛ بستن به تلگرام نیاز دارد.'); },
        expandTo: function () {}, disableVerticalSwipes: function () {},
        setHeaderColor: function () {}, setBackgroundColor: function () {},
        onEvent: function () {},
        openLink: function (u) { window.open(u, '_blank', 'noopener'); },
        BackButton: { show: function () {}, hide: function () {}, onClick: function () {}, offClick: function () {} },
        MainButton: {
            show: function () {}, hide: function () {}, onClick: function () {},
            offClick: function () {}, setText: function () {},
            showProgress: function () {}, hideProgress: function () {}
        },
        HapticFeedback: {
            impactOccurred: function () {},
            notificationOccurred: function () {},
            selectionChanged: function () {}
        }
    } };
})();
</script>
JS;

$html = str_replace(
    '<script src="https://telegram.org/js/telegram-web-app.js"></script>',
    '<script>window.__INIT_DATA__ = ' . json_encode($initData) . ';</script>' . $stub,
    $index
);

$html = str_replace('__WEBAPP_BOOT__', $boot, $html);

// استاتیک‌ها یک هفته کش می‌شوند (تا در تلگرام دوباره دانلود نشوند)، پس در
// حالت توسعه باید کش را دور بزنیم وگرنه هر تغییر CSS/JS اصلاً دیده نمی‌شود.
$stamp = '?asset=app.js&v=' . time();

$html = str_replace(['?asset=app.css', '?asset=app.js'], ['?asset=app.css&v=' . time(), $stamp], $html);

// در پیش‌نمایش، درخواست‌ها به همان سرور محلی می‌روند و CSPِ `webapp.php`
// روی صفحهٔ HTML اعمال نمی‌شود (فقط روی هدرِ پاسخِ خودِ PHP).
$target = $root . '/_preview.html';

file_put_contents($target, $html);

echo "\n────────────────────────────────────────────\n";
echo "پیش‌نمایش ساخته شد: {$target}\n";
echo "کاربر: {$userId} / {$userName}\n\n";
echo "حالا سرور محلی را بالا بیاور و باز کن:\n";
echo "  php -S 127.0.0.1:8081\n";
echo "  http://127.0.0.1:8081/_preview.html\n";
echo "────────────────────────────────────────────\n\n";
