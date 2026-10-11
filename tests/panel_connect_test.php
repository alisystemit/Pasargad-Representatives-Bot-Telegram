<?php

declare(strict_types=1);

/**
 * تست جریان «اتصال پنل جدید» و «تست اتصال».
 *
 * سه چیز باید اثبات شود:
 *   ۱) دکمهٔ اتصال در صفحهٔ پنل‌ها (حتی وقتی هیچ پنلی نیست) وجود دارد.
 *   ۲) جریان «آدرس → نام کاربری → رمز» نشست را درست پیش می‌برد و آدرس نامعتبر
 *      را رد می‌کند بدون آنکه مرحله را جلو ببرد.
 *   ۳) رمز اشتباه هیچ چیزی ذخیره نمی‌کند؛ رمز درست پنل را می‌سازد و **رمزنگاری‌شده**
 *      ذخیره می‌شود (نه خام).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/Fixture.php';

use Pasargad\Bot\PanelConnectionManager;
use Pasargad\Bot\SessionStore;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;

$db = TestDb::boot();
(new Migrator($db))->migrate();

Config::set('panel.owner_username', 'root');
Config::set('panel.owner_password', 'rootpass');

$users    = new UserRepository($db);
$panels   = new PanelRepository($db);
$sessions = new SessionStore($db);
$bot      = new FakeBotApi();
$manager  = new PanelConnectionManager($bot, $panels, $sessions);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        echo "  ❌ {$label}\n";

        if ($detail !== '') {
            echo "     ↳ " . str_replace("\n", "\n       ", mb_substr($detail, 0, 400)) . "\n";
        }
    }
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function update(array $payload): array
{
    return [
        'message_id' => 1,
        'date'       => time(),
        'chat'       => ['id' => 100, 'type' => 'private'],
        'from'       => ['id' => 100, 'first_name' => 'تستر', 'language_code' => 'fa'],
        'text'       => 'سلام',
        ...$payload,
    ];
}

$user = $users->upsertByTelegram(100, ['first_name' => 'تستر', 'language_code' => 'fa']);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۱: منوی اتصال و اعتبار آدرس\n";
// ------------------------------------------------------------------

$bot->reset();
$manager->showMenu(100);

check('منوی اتصال باز شد', str_contains($bot->allText(), 'اتصال پنل'), $bot->allText());
check('گزینهٔ رمز عبور هست', $bot->hasButton('رمز عبور'));
check('گزینهٔ کلید API هست', $bot->hasButton('کلید API'));

// آدرس نامعتبر: نباید مرحله را جلو ببرد.
$state = ['auth_mode' => 'password', 'step' => PanelConnectionManager::STEP_URL];

$bot->reset();
$ok = $manager->handleBaseUrl(100, 100, 'ftp://bad-host', $state);

check('آدرس نامعتبر رد شد', $ok === false);
check('پیام خطای آدرس داده شد', str_contains($bot->allText(), 'آدرس پنل معتبر نیست'), $bot->allText());

// آدرس بدون scheme: خودمان https اضافه می‌کنیم.
$bot->reset();
$manager->handleBaseUrl(100, 100, 'panel.example.com', $state);

$state = $sessions->get(100) ?? [];
check('scheme به URL اضافه شد', ($state['panel_base_url'] ?? '') === 'https://panel.example.com', json_encode($state, JSON_UNESCAPED_UNICODE));
check('مرحله به «نام کاربری» رفت', ($state['step'] ?? '') === PanelConnectionManager::STEP_USERNAME);
check('از کاربر نام کاربری خواسته شد', str_contains($bot->allText(), 'نام کاربری'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۲: نام کاربری\n";
// ------------------------------------------------------------------

$bot->reset();
$manager->handleUsername(100, 100, 'نام نامعتبر!', $sessions->get(100) ?? []);

check('نام کاربری نامعتبر رد شد', str_contains($bot->allText(), 'نام کاربری نامعتبر است'), $bot->allText());

$bot->reset();
$manager->handleUsername(100, 100, 'operator1', $sessions->get(100) ?? []);

$state = $sessions->get(100) ?? [];
check('نام کاربری ذخیره شد', ($state['panel_username'] ?? '') === 'operator1');
check('مرحله به «رمز» رفت', ($state['step'] ?? '') === PanelConnectionManager::STEP_CREDENTIAL);
check('از کاربر رمز خواسته شد', str_contains($bot->allText(), 'رمز عبور'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۳: رمز اشتباه نباید چیزی ذخیره کند\n";
// ------------------------------------------------------------------

// پنل قلابی: خطای ورود را شبیه‌سازی می‌کنیم چون کلاینت واقعی شبکه می‌خواهد.
Config::set('panel.base_url', 'http://127.0.0.1:1');   // پورت بسته ⇒ اتصال شکست می‌خورد

$before = $panels->countAll();
$bot->reset();
$manager->handleCredential(100, 100, 'wrong-pass', $sessions->get(100) ?? [], $user);

$after = $panels->countAll();

check('پنلی ذخیره نشد', $before === $after, "before={$before} after={$after}");
check('خطای اتصال به کاربر گفته شد', str_contains($bot->allText(), 'اتصال برقرار نشد'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۴: ذخیرهٔ موفق (آدرس اختصاصی + رمزنگاری)\n";
// ------------------------------------------------------------------

// مسیر موفق به شبکهٔ واقعی نیاز دارد، پس این‌جا ساختار رکورد را با همان مسیری
// می‌سنجیم که `handleCredential()` صدا می‌زند: `upsertFromPanel`.
$panelId = $panels->upsertFromPanel(
    (int) $user['id'],
    'operator1',
    'secret-pass',
    ['id' => 501, 'role' => ['name' => 'owner', 'is_owner' => true], 'data_limit' => 10737418240, 'used_traffic' => 1073741824],
    PanelRepository::SOURCE_SELF,
    ['base_url' => 'https://panel.example.com']
);

$stored = $panels->find($panelId);

check('پنل ساخته شد', $stored !== null);
check('نام کاربری درست ذخیره شد', ($stored['panel_username'] ?? '') === 'operator1');
check('رمز خام ذخیره نشد', ($stored['panel_password'] ?? '') !== 'secret-pass', (string) ($stored['panel_password'] ?? ''));
check('رمز رمزنگاری شد', str_starts_with((string) ($stored['panel_password'] ?? ''), 'v1:'), (string) ($stored['panel_password'] ?? ''));
check('آدرس اختصاصی پنل ذخیره شد', ($stored['base_url'] ?? '') === 'https://panel.example.com', (string) ($stored['base_url'] ?? 'NULL'));
check('نقش owner از API خوانده شد', ($stored['panel_role'] ?? '') === 'owner');
check('حجم از API خوانده شد', (int) ($stored['data_limit'] ?? 0) === 10737418240);
check('رمز قابل بازیابی است', $panels->plainPassword($stored) === 'secret-pass');

$again = $panels->upsertFromPanel(
    (int) $user['id'],
    'operator1',
    'new-pass',
    ['id' => 501, 'role' => ['name' => 'owner', 'is_owner' => true]],
    PanelRepository::SOURCE_BOT,
    ['base_url' => 'https://panel.example.com']
);

check('به‌روزرسانی ردیف تکراری نساخت', $again === $panelId && $panels->countAll() === 1);
check('رمز جدید جایگزین شد', $panels->plainPassword($panels->find($panelId)) === 'new-pass');

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۵: پنل دوم روی دامنهٔ دیگر\n";
// ------------------------------------------------------------------

$secondId = $panels->upsertFromPanel(
    (int) $user['id'],
    'operator2',
    'second-pass',
    ['id' => 502, 'role' => ['name' => 'admin']],
    PanelRepository::SOURCE_SELF,
    ['base_url' => 'https://panel2.example.com']
);

check('کاربر می‌تواند دو پنل داشته باشد', $panels->countByUser((int) $user['id']) === 2);
check('دو پنل شناسهٔ متفاوت دارند', $panelId !== $secondId);
check('پنل دوم آدرس خودش را دارد', ($panels->find($secondId)['base_url'] ?? '') === 'https://panel2.example.com');

// ------------------------------------------------------------------
echo "\n▶ ستون ناموجود در \$extra\n";
// ------------------------------------------------------------------
//
// پارامتر `$extra` پنل‌ها فقط نام ستون می‌گیرد، پس یک typo ساده نباید کل
// ذخیرهٔ پنل را بترکاند. قبلاً چنین چیزی وجود نداشت و نوشتن ستون ناموجود
// «no such column» می‌داد.

$threw = false;
$badId = 0;

try {
    $badId = $panels->upsertFromPanel(
        (int) $user['id'],
        'typo_panel',
        'p',
        ['id' => 999],
        PanelRepository::SOURCE_SELF,
        ['definitely_not_a_column' => 'x']
    );
} catch (\Throwable $e) {
    $threw = true;
}

check('ستون ناموجود exception نمی‌دهد', $threw === false);
check('ستون ناموجود نادیده گرفته شد ولی ردیف ساخته شد', $badId > 0 && $panels->find($badId) !== null);

// ------------------------------------------------------------------
echo "\n▶ نتیجه\n";
// ------------------------------------------------------------------

echo "══════════════════════════════════════════════════════════════\n";
echo "  اتصال پنل — {$passed} موفق، {$failed} ناموفق\n";
echo "══════════════════════════════════════════════════════════════\n";

exit($failed === 0 ? 0 : 1);