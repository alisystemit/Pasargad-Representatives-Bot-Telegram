<?php

declare(strict_types=1);

/**
 * تست جریان «اتصال پنل جدید» — فقط با کلید API.
 *
 * ربات دیگر نام کاربری و رمز نمی‌گیرد، پس جریان فقط دو مرحله دارد:
 * آدرس ← کلید.
 *
 * سه چیز باید اثبات شود:
 *   ۱) منو فقط راهِ کلید API را پیشنهاد می‌دهد و دسترسی‌های لازم را می‌گوید.
 *   ۲) آدرس نامعتبر مرحله را جلو نمی‌برد؛ آدرس درست مستقیم می‌رود سراغ کلید.
 *   ۳) کلید اشتباه چیزی ذخیره نمی‌کند و کلید درست **رمزنگاری‌شده** ذخیره
 *      می‌شود (نه خام) و رمز عبور اصلاً ذخیره نمی‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/Fixture.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Bot\PanelConnectionManager;
use Pasargad\Bot\SessionStore;
use Pasargad\Store\PanelAccess;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Telegram\FakeBotApi;

$db = TestDb::boot();
(new Migrator($db))->migrate();

Config::set('panel.api_key', 'pg_key_test_global');

$users    = new UserRepository($db);
$panels   = new PanelRepository($db);
$sessions = new SessionStore($db);
$bot      = new FakeBotApi();

// کلاینت قلابی تا مسیر موفق بدون شبکه تست شود.
$panelClient = new Pasargad\Panel\FakePanelClient();
$panelClient->validApiKeys = ['pg_key_live_abcdef'];
$panelClient->addAdmin('operator1', [
    'id'         => 501,
    'role'       => ['id' => 1, 'name' => 'owner', 'is_owner' => true],
    'data_limit' => 10737418240,
]);

$manager = new PanelConnectionManager($bot, $panels, $sessions, $panelClient);

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
check('گزینهٔ کلید API هست', $bot->hasButton('کلید API'));
check('گزینهٔ رمز عبور دیگر نیست', !$bot->hasButton('رمز عبور'), $bot->allText());
check('دسترسی‌های لازم ذکر شده', str_contains($bot->allText(), 'admins.update'), $bot->allText());

// آدرس نامعتبر: نباید مرحله را جلو ببرد.
$state = ['step' => PanelConnectionManager::STEP_URL];

$bot->reset();
$ok = $manager->handleBaseUrl(100, 100, 'ftp://bad-host', $state);

check('آدرس نامعتبر رد شد', $ok === false);
check('پیام خطای آدرس داده شد', str_contains($bot->allText(), 'آدرس پنل معتبر نیست'), $bot->allText());

// آدرس بدون scheme: خودمان https اضافه می‌کنیم.
$bot->reset();
$manager->handleBaseUrl(100, 100, 'panel.example.com', $state);

$state = $sessions->get(100) ?? [];
check('scheme به URL اضافه شد', ($state['panel_base_url'] ?? '') === 'https://panel.example.com', json_encode($state, JSON_UNESCAPED_UNICODE));
check('مرحله به «کلید» رفت', ($state['step'] ?? '') === PanelConnectionManager::STEP_CREDENTIAL, json_encode($state, JSON_UNESCAPED_UNICODE));
check('از کاربر کلید خواسته شد', str_contains($bot->allText(), 'کلید API'), $bot->allText());
check('نام کاربری دیگر پرسیده نمی‌شود', !str_contains($bot->allText(), 'نام کاربری'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۲: کلید اشتباه نباید چیزی ذخیره کند\n";
// ------------------------------------------------------------------

$before = $panels->countAll();
$bot->reset();
$manager->handleCredential(100, 100, 'wrong-key', $sessions->get(100) ?? [], $user);

$after = $panels->countAll();

check('پنلی ذخیره نشد', $before === $after, "before={$before} after={$after}");
check('خطای اتصال به کاربر گفته شد', str_contains($bot->allText(), 'اتصال برقرار نشد'), $bot->allText());

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۳: ذخیرهٔ موفق (آدرس اختصاصی + کلید رمزنگاری‌شده)\n";
// ------------------------------------------------------------------

$sessions->set(100, ['step' => PanelConnectionManager::STEP_CREDENTIAL, 'panel_base_url' => 'https://panel.example.com']);

$bot->reset();
$ok = $manager->handleCredential(100, 100, 'pg_key_live_abcdef', $sessions->get(100) ?? [], $user);

check('اتصال موفق گزارش شد', $ok === true, $bot->allText());

$stored = $panels->listByUser((int) $user['id'])[0] ?? null;
$access = new PanelAccess($panels);

check('پنل ساخته شد', $stored !== null);
check(
    'نام ادمین از خودِ پنل خوانده شد',
    ($stored['panel_username'] ?? '') === 'operator1',
    (string) ($stored['panel_username'] ?? 'NULL')
);
check('آدرس اختصاصی پنل ذخیره شد', ($stored['base_url'] ?? '') === 'https://panel.example.com', (string) ($stored['base_url'] ?? 'NULL'));
check('کلید خام ذخیره نشد', ($stored['api_key'] ?? '') !== 'pg_key_live_abcdef', (string) ($stored['api_key'] ?? ''));
check('کلید رمزنگاری شد', str_starts_with((string) ($stored['api_key'] ?? ''), 'v1:'), (string) ($stored['api_key'] ?? ''));
check('کلید قابل بازیابی است', $access->apiKeyOf((array) $stored) === 'pg_key_live_abcdef');
check('هیچ رمز عبوری ذخیره نشد', ($stored['panel_password'] ?? '') === '', (string) ($stored['panel_password'] ?? 'NULL'));
check('نقش از API خوانده شد', ($stored['panel_role'] ?? '') === 'owner');
check('حجم از API خوانده شد', (int) ($stored['data_limit'] ?? 0) === 10737418240);

// به‌روزرسانی همان ردیف، نه ساخت ردیف تکراری
$again = $panels->upsertFromPanel(
    (int) $user['id'],
    'operator1',
    '',
    ['id' => 501, 'role' => ['name' => 'owner', 'is_owner' => true]],
    PanelRepository::SOURCE_BOT,
    ['base_url' => 'https://panel.example.com', 'api_key' => 'pg_key_rotated']
);

check('به‌روزرسانی ردیف تکراری نساخت', $again === (int) $stored['id'] && $panels->countAll() === 1);
check(
    'کلید جدید جایگزین شد',
    $access->apiKeyOf((array) $panels->find($again)) === 'pg_key_rotated'
);

// ------------------------------------------------------------------
echo "\n▶ مرحلهٔ ۵: پنل دوم روی دامنهٔ دیگر\n";
// ------------------------------------------------------------------

$secondId = $panels->upsertFromPanel(
    (int) $user['id'],
    'operator2',
    '',
    ['id' => 502, 'role' => ['name' => 'admin']],
    PanelRepository::SOURCE_SELF,
    ['base_url' => 'https://panel2.example.com', 'api_key' => 'pg_key_two']
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
        '',
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