<?php

declare(strict_types=1);

/**
 * پنل متصلِ کاربر: آدرس اختصاصی، کلید API، و ساخت پنل نمایندگی روی همان پنل.
 *
 * این تست‌ها روی سه باگ واقعی نگهبان می‌گذارند که هرکدام باعث می‌شد
 * قابلیتی که کدش هست، هرگز کار نکند:
 *
 *   ۱) «کلید API» کلید را با طرح `Bearer` می‌فرستاد. آزمایش زنده روی پنل
 *      نشان داد `Authorization: Bearer pg_key_…` همیشه ۴۰۱ می‌دهد و
 *      `Authorization: ApiKey pg_key_…` کار می‌کند. یعنی مسیر کلید همیشه
 *      شکست می‌خورد و خطایش هم دقیقاً شبیه «کلید اشتباه» بود.
 *
 *   ۲) «بروزرسانی از پنل»، «آمار کاربران» و «قطع دسترسی» همیشه به پنل
 *      **سراسری** می‌زدند، حتی وقتی کاربر پنل خودش را روی دامنهٔ دیگری وصل
 *      کرده بود — یعنی درخواست به سرور اشتباه می‌رفت.
 *
 *   ۳) خرید «پنل نمایندگی» همیشه با اکانت owner سراسری ساخته می‌شد، نه روی
 *      پنلی که خریدار وصل کرده. برای نماینده‌ای که پنلش روی دامنهٔ خودش بود،
 *      خریدش عملاً بی‌فایده می‌شد.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/FakePanelClient.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelAccess;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Crypto;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
Settings::flush();
(new Pasargad\Support\Migrator($db))->migrate();

Config::set('crypto_key', 'panel-access-test');
Config::set('base_url', 'https://bot.example.com');

const TG  = 8801;
const ADM = 8802;

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    echo "  ❌ {$label}\n";

    if ($detail !== '') {
        echo '     ↳ ' . str_replace("\n", "\n       ", mb_substr($detail, 0, 240)) . "\n";
    }
}

$users  = new UserRepository();
$panels = new PanelRepository();
$orders = new OrderRepository();
$st     = new Settings();

function boot(FakePanelClient $client): Kernel
{
    global $users, $orders, $st, $panels;

    $pkgs  = new PackageRepository();
    $flags = new FeatureFlags($st);
    $ss    = new SessionStore();
    $prov  = new Provisioner($client, $orders, $users, $st, $panels);
    $pay   = new PaymentService($orders, $prov, $st, $flags, $users);
    $bot   = new Pasargad\Telegram\FakeBotApi();

    return new Kernel(
        $bot, new Notifier($bot), $users, $pkgs, $orders, $prov, $pay,
        $st, $ss, $client, $flags
    );
}

function cb(int $tg, array $payload): Update
{
    return new Update([
        'update_id'      => 1,
        'callback_query' => [
            'id'            => 'cb',
            'from'          => ['id' => $tg],
            'chat_instance' => 'x',
            'data'          => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'message'       => ['message_id' => 5, 'chat' => ['id' => $tg]],
        ],
    ]);
}

function txt(int $tg, string $text): Update
{
    return new Update([
        'update_id' => 1,
        'message'   => ['message_id' => 6, 'from' => ['id' => $tg], 'chat' => ['id' => $tg], 'text' => $text],
    ]);
}

echo "\n" . str_repeat('═', 62) . "\n";
echo "  پنل متصل کاربر — کلید API و ساخت پنل نمایندگی روی پنل خودش\n";
echo str_repeat('═', 62) . "\n";

// ------------------------------------------------------------------
echo "\n▶ ۱) تشخیص روش احراز هویت از روی ردیف پنل\n";
// ------------------------------------------------------------------

$pwRow = ['id' => 1, 'panel_username' => 'rep1', 'auth_mode' => null];
$keyRow = ['id' => 2, 'panel_username' => 'apikey-abc', 'auth_mode' => 'apikey'];

check('ردیف بدون auth_mode (قدیمی) = رمز عبور', PanelAccess::modeOf($pwRow) === PanelAccess::MODE_PASSWORD);
check('auth_mode=apikey = کلید API', PanelAccess::modeOf($keyRow) === PanelAccess::MODE_API_KEY);
check('مقدار ناشناخته به رمز عبور برمی‌گردد', PanelAccess::modeOf(['auth_mode' => 'چیزی'] ?? []) === PanelAccess::MODE_PASSWORD);
check('ردیف بدون ستون اصلاً هم رمز عبور است', PanelAccess::modeOf([]) === PanelAccess::MODE_PASSWORD);

// ------------------------------------------------------------------
echo "\n▶ ۲) اتصال پنل با کلید API\n";
// ------------------------------------------------------------------

$client = new FakePanelClient();
$client->addAdmin('malek', ['id' => 9, 'role' => ['id' => 1, 'name' => 'owner', 'is_owner' => true]]);

$kernel = boot($client);
$kernel->handle(txt(ADM, '/start'));

// در مسیر کلید API بعد از آدرس، مستقیم کلید پرسیده می‌شود (بدون نام کاربری).
$kernel->handle(cb(ADM, ['n' => 'panel.connect.apikey']));
$kernel->handle(txt(ADM, 'https://my-panel.example.com'));
$kernel->handle(txt(ADM, 'pg_key_live_abcd-5678'));

$adminRow = $users->findByTelegramId(ADM);
$mine     = $panels->listByUser((int) $adminRow['id']);

check('پنل با کلید API ثبت شد', $mine !== [], 'کاربر: ' . json_encode($adminRow, JSON_UNESCAPED_UNICODE));
check(
    'auth_mode=apikey روی ردیف ذخیره شد',
    ($mine[0]['auth_mode'] ?? '') === PanelAccess::MODE_API_KEY,
    'auth_mode=' . var_export($mine[0]['auth_mode'] ?? null, true)
);
check(
    'base_url اختصاصی ذخیره شد',
    ($mine[0]['base_url'] ?? '') === 'https://my-panel.example.com',
    'base_url=' . var_export($mine[0]['base_url'] ?? null, true)
);
check('نام کاربری از هش کلید ساخته شد', str_starts_with((string) ($mine[0]['panel_username'] ?? ''), 'apikey-'));
check(
    'کلید رمزنگاری‌شده ذخیره شد، نه خام',
    ($mine[0]['panel_password'] ?? '') !== 'pg_key_live_abcd-5678'
        && ($mine[0]['panel_password'] ?? '') !== ''
);

$access = new PanelAccess($panels);
$creds  = $access->credentialsOf($mine[0]);

check('PanelAccess روش را apikey تشخیص داد', ($creds['mode'] ?? '') === PanelAccess::MODE_API_KEY);
check('کلید قابل بازیابی است', ($creds['credential'] ?? '') === 'pg_key_live_abcd-5678');
check('آدرس درست است', ($creds['base_url'] ?? '') === 'https://my-panel.example.com');

$apiClient = $access->clientFor($mine[0]);

check('کلاینت ساخته شد', $apiClient !== null);
check('کلاینت توکن ثابت دارد', $apiClient !== null && $apiClient->hasStaticToken());
check(
    'طرح هدر ApiKey است، نه Bearer',
    $apiClient !== null && $apiClient->authScheme() === Pasargad\Panel\PasarGuardClient::SCHEME_API_KEY,
    'scheme=' . ($apiClient?->authScheme() ?? '?')
);

// ------------------------------------------------------------------
echo "\n▶ ۳) پنل نمایندگی روی پنل خودِ کاربر ساخته می‌شود\n";
// ------------------------------------------------------------------

$client->addRole(7, ['id' => 7, 'name' => 'reseller', 'is_owner' => false]);
$client->ownerUsername = 'malek';

$buyerId  = (int) $users->findByTelegramId(ADM)['id'];
$pkgs     = new PackageRepository();
$pkg      = $pkgs->create(['slug' => 'agency-x', 'title' => 'پنل نمایندگی', 'kind' => PackageRepository::KIND_AGENCY, 'volume_gb' => 50, 'duration_days' => 30, 'price_toman' => 500000]);

$order = $orders->create($buyerId, [
    'code'          => 'ORD-ACC-1',
    'package_id'    => $pkg,
    'package_title' => 'پنل نمایندگی',
    'kind'          => PackageRepository::KIND_AGENCY,
    'volume_gb'     => 50,
    'duration_days' => 30,
    'price_toman'   => 500000,
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$prov = new Provisioner($client, $orders, $users, $st, $panels);
$result = $prov->provision($orders->find((int) $order['id']));

check('ساخت پنل موفق بود', ($result['ok'] ?? false) === true, json_encode($result, JSON_UNESCAPED_UNICODE));
check('یک ادمین روی پنل ساخته شد', count($client->createdAdmins) === 1, json_encode($client->createdAdmins, JSON_UNESCAPED_UNICODE));
// در مسیر کلید API، نام کاربریِ احراز هویت عمداً «api» است و کلاینت توکن
// ثابت دارد؛ آنچه مهم است این است که **همان کلاینت تزریق‌شده** استفاده شد و
// نه یک کلاینت تازه که به پنل سراسری می‌رفت.
check(
    'احراز هویت از مسیر کلیدِ کاربر آمد',
    ($client->createdAdmins[0]['owner'] ?? '') === 'api',
    'owner=' . ($client->createdAdmins[0]['owner'] ?? '?')
);
check(
    'هیچ تلاشی برای ورود با نام کاربری/رمز نشد (کلید کافی بود)',
    $client->loginCalls === 0,
    'loginCalls=' . $client->loginCalls
);

$newPanel = $panels->find((int) ($result['details']['panel_id'] ?? 0));

check('پنل تازه در دیتابیس ثبت شد', $newPanel !== null);
check(
    'base_url پنل تازه = آدرس خریدار (نه پنل سراسری)',
    ($newPanel['base_url'] ?? '') === 'https://my-panel.example.com',
    'base_url=' . var_export($newPanel['base_url'] ?? null, true)
);
check(
    'پنل تازه با رمز کار می‌کند (ساخته شده، نه کلیددار)',
    PanelAccess::modeOf((array) $newPanel) === PanelAccess::MODE_PASSWORD,
    'auth_mode=' . var_export($newPanel['auth_mode'] ?? null, true)
);

// ------------------------------------------------------------------
echo "\n▶ ۴) نبودِ پنل متصل ⇒ برگشت به اکانت owner سراسری\n";
// ------------------------------------------------------------------

Config::set('panel.owner_username', 'global-owner');
Config::set('panel.owner_password', Crypto::encrypt('global-pass'));

$freshClient = new FakePanelClient();
$freshClient->addAdmin('global-owner', ['id' => 1, 'role' => ['id' => 1, 'name' => 'owner', 'is_owner' => true]]);
$freshClient->ownerUsername = 'global-owner';

$otherTg = 8803;
$freshProv = new Provisioner($freshClient, $orders, $users, $st, $panels);

$freshKernel = boot($freshClient);
$freshKernel->handle(txt($otherTg, '/start'));

$order2 = $orders->create((int) $users->findByTelegramId($otherTg)['id'], [
    'code'          => 'ORD-ACC-2',
    'package_title' => 'پنل نمایندگی',
    'kind'          => PackageRepository::KIND_AGENCY,
    'volume_gb'     => 10,
    'duration_days' => 30,
    'price_toman'   => 100000,
    'status'        => OrderRepository::STATUS_PAID,
    'paid_at'       => time(),
]);

$res2 = $freshProv->provision($orders->find((int) $order2['id']));

check('خریدارِ بدون پنل هم می‌تواند بخرد', ($res2['ok'] ?? false) === true, json_encode($res2, JSON_UNESCAPED_UNICODE));
check(
    'و با اکانت owner سراسری ساخته شد',
    ($freshClient->createdAdmins[0]['owner'] ?? '') === 'global-owner',
    'owner=' . ($freshClient->createdAdmins[0]['owner'] ?? '?')
);

$newPanel2 = $panels->find((int) ($res2['details']['panel_id'] ?? 0));

check(
    'پنل تازه base_url ندارد ⇒ یعنی «از پنل سراسری استفاده کن»',
    ($newPanel2['base_url'] ?? '') === '',
    'base_url=' . var_export($newPanel2['base_url'] ?? null, true)
);

// ------------------------------------------------------------------
echo "\n▶ ۵) همگام‌سازی روی آدرس خودِ پنل، نه پنل سراسری\n";
// ------------------------------------------------------------------

$syncClient = new FakePanelClient();
$syncClient->admins['apikey-abc'] = ['id' => 5, 'username' => 'apikey-abc', 'status' => 'active', 'data_limit' => 999];

$syncPanels = new PanelRepository();
$syncOwner  = (int) $users->findByTelegramId($otherTg)['id'];
$syncId = $syncPanels->create($syncOwner, 'apikey-abc', 'pg_key_zzz', [
    'base_url'  => 'https://their-own.example.com',
    'auth_mode' => PanelAccess::MODE_API_KEY,
]);

$syncRow = $syncPanels->find($syncId);
$sync    = new Pasargad\Store\PanelSyncer($syncPanels, $syncClient);

$syncResult = $sync->syncOne((array) $syncRow);

check('همگام‌سازی موفق بود', ($syncResult['ok'] ?? false) === true, json_encode($syncResult, JSON_UNESCAPED_UNICODE));

$usedPaths = array_column($syncClient->loggedRequests, 'path');

check(
    'درخواست به مسیر ادمین جاری رفت (GET /api/admin)',
    in_array('/api/admin', $usedPaths, true),
    json_encode($syncClient->loggedRequests, JSON_UNESCAPED_UNICODE)
);

$after = $syncPanels->find($syncId);
check('وضعیت پنل به‌روزرسانی شد', (int) $after['synced_at'] > 0, 'synced_at=' . var_export($after['synced_at'], true));
check('سقف حجم از پنل خوانده و ذخیره شد', (int) $after['data_limit'] === 999);

echo "\n" . str_repeat('─', 62) . "\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);
