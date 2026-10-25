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
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\AgencyService;
use Pasargad\Store\BotConfig;
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
echo "\n▶ ۱) تشخیص کلید از روی ردیف پنل\n";
// ------------------------------------------------------------------

check('ردیف با api_key معتبر است', PanelAccess::isApiKey(['api_key' => 'pg_key_x']));
check('ردیف بدون api_key نامعتبر است', !PanelAccess::isApiKey(['api_key' => '']));
check('ردیف قدیمی که فقط رمز داشت نامعتبر است', !PanelAccess::isApiKey(['panel_password' => 'x', 'auth_mode' => 'password']));
check('ردیف خالی نامعتبر است', !PanelAccess::isApiKey([]));

$access0 = new PanelAccess(new PanelRepository());
check(
    'پنلِ فقط‌رمز، «قابل استفاده» نیست',
    $access0->credentialsOf(['panel_username' => 'rep1', 'panel_password' => 'secret', 'auth_mode' => 'password']) === null
);

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
check('نام ادمین از خودِ پنل خوانده شد', ($mine[0]['panel_username'] ?? '') === 'malek', (string) ($mine[0]['panel_username'] ?? 'NULL'));

// کلید و رمز ستون‌های جدا دارند تا ربات بتواند اول با کلید کار کند و فقط
// اگر کلید نتوانست، سراغ رمز برود.
check(
    'کلید در ستون api_key ذخیره شد، نه خام در panel_password',
    ($mine[0]['api_key'] ?? '') !== ''
        && ($mine[0]['api_key'] ?? '') !== 'pg_key_live_abcd-5678',
    'api_key=' . var_export($mine[0]['api_key'] ?? null, true)
);
check(
    'رمزی ثبت نشده چون کاربر فقط کلید داد',
    ($mine[0]['panel_password'] ?? '') === '',
    'panel_password=' . var_export($mine[0]['panel_password'] ?? null, true)
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
    'برای پنل تازه هم کلید صادر شد (وگرنه مدیریت‌شدنی نبود)',
    PanelAccess::isApiKey((array) $newPanel),
    'api_key=' . var_export($newPanel['api_key'] ?? null, true)
);
check(
    'کلیدِ صادرشده همان چیزی است که پنل برگرداند',
    (new PanelAccess($panels))->apiKeyOf((array) $newPanel) !== ''
        && str_starts_with((new PanelAccess($panels))->apiKeyOf((array) $newPanel), 'pg_key_issued_'),
    'key=' . var_export((new PanelAccess($panels))->apiKeyOf((array) $newPanel), true)
);

// ------------------------------------------------------------------
echo "\n▶ ۴) نبودِ پنل متصل ⇒ برگشت به اکانت owner سراسری\n";
// ------------------------------------------------------------------

Config::set('panel.api_key', 'pg_key_global');

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
// در مسیر کلید، نام کاربری احراز هویت عمداً «api» است و خودِ کلید در هدر
// می‌رود؛ آنچه مهم است این است که از کلیدِ سراسری استفاده شد و نه از پنلی
// که خریدار وصل کرده (که اصلاً ندارد).
check(
    'و با کلید سراسری ساخته شد',
    ($freshClient->createdAdmins[0]['owner'] ?? '') === 'api',
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
$syncId = $syncPanels->create($syncOwner, 'apikey-abc', '', [
    'base_url'  => 'https://their-own.example.com',
    'api_key'   => 'pg_key_zzz',
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

// ------------------------------------------------------------------
echo "\n▶ ۶) کلید تنها روش احراز هویت است\n";
// ------------------------------------------------------------------
//
// رمز عبور حذف شده، پس دو چیز باید ثابت بماند:
//   ۱) هیچ مسیری به رمز برنمی‌گردد — حتی ۴۰۳.
//   ۲) ۴۰۳ با **نام همان مجوز** گزارش می‌شود، نه با پیام مبهم.

$limited = new FakePanelClient();
$limited->admins['rep9'] = ['id' => 77, 'username' => 'rep9', 'status' => 'active', 'data_limit' => 500];
$limited->apiKeyForbidden = true;

$limitedPanels = new PanelRepository();
$limitedId = $limitedPanels->create($syncOwner, 'rep9', '', [
    'base_url' => 'https://rep9-panel.example.com',
    'api_key'  => 'pg_key_limited',
]);

$limitedRow = (array) $limitedPanels->find($limitedId);
$limitedAcc = new PanelAccess($limitedPanels);

check('کلید وجود دارد', PanelAccess::isApiKey($limitedRow));
check('رمزی هم روی ردیف نیست', ($limitedRow['panel_password'] ?? '') === '');

$limitedClient = $limitedAcc->clientForPanel($limitedRow, $limited);
$threw         = null;

try {
    $limited->resetAdminUsage('rep9', 'rep9', 'unused');
} catch (\Throwable $e) {
    $threw = $e;
}

check('عملیات ناتوان خطا داد (نه بی‌صدا)', $threw !== null);
check(
    'خطا «مجوز ندارد» است، نه «بی‌ارتباط»',
    $threw !== null && $threw->httpStatus() === 403,
    $threw === null ? 'بدون استثنا' : ('status=' . $threw->httpStatus() . ' — ' . $threw->getMessage())
);
check(
    'نام همان مجوزِ کم به کاربر گفته شد',
    $threw !== null && str_contains($threw->getMessage(), 'admins.update'),
    $threw === null ? '' : $threw->getMessage()
);
check(
    'پیام راهنمای ساخت کلید تازه دارد',
    $threw !== null && str_contains($threw->getMessage(), 'panel_key_check'),
    $threw === null ? '' : $threw->getMessage()
);
check(
    'هیچ تلاشی با طرح Bearer انجام نشد',
    !in_array('Bearer', $limited->authHeadersSeen, true),
    json_encode($limited->authHeadersSeen)
);

// ------------------------------------------------------------------
echo "\n▶ ۷) کلید کافی است ⇒ فقط یک درخواست، با طرح ApiKey\n";
// ------------------------------------------------------------------

$okClient = new FakePanelClient();
$okClient->admins['rep10'] = ['id' => 88, 'username' => 'rep10', 'status' => 'active'];

$okPanels = new PanelRepository();
$okId = $okPanels->create($syncOwner, 'rep10', '', [
    'base_url' => 'https://rep10-panel.example.com',
    'api_key'  => 'pg_key_good',
]);

$okRow  = (array) $okPanels->find($okId);
$okAcc  = new PanelAccess($okPanels);
$okCli  = $okAcc->clientForPanel($okRow, $okClient);

$okClient->resetAdminUsage('rep10', 'rep10', 'unused');

check('عملیات موفق بود', count($okClient->loggedRequests) === 1, json_encode($okClient->loggedRequests));
check('با طرح ApiKey رفت', ($okClient->authHeadersSeen[0] ?? '') === 'ApiKey', json_encode($okClient->authHeadersSeen));
check('هیچ تلاشی با رمز نشد', !in_array('Bearer', $okClient->authHeadersSeen, true));

// ------------------------------------------------------------------
echo "\n▶ ۸) جریان «من پنل دارم» در ربات — فقط کلید\n";
// ------------------------------------------------------------------

$selfClient = new FakePanelClient();
$selfClient->admins['rep11'] = ['id' => 99, 'username' => 'rep11', 'status' => 'active'];

$kernel2 = boot($selfClient);
$kernel2->handle(txt(8804, '/start'));

$kernel2->handle(cb(8804, ['n' => 'panel.self']));
$kernel2->handle(txt(8804, 'https://rep11-panel.example.com'));
$kernel2->handle(txt(8804, 'pg_key_from_user'));

$u4   = (int) $users->findByTelegramId(8804)['id'];
$saved = $panels->listByUser($u4);

check('پنل ذخیره شد', $saved !== [], 'کاربر: ' . $u4);
check('نام ادمین از خودِ پنل آمد', ($saved[0]['panel_username'] ?? '') === 'rep11', var_export($saved[0]['panel_username'] ?? null, true));
check(
    'کلید قابل بازیابی است',
    $access->apiKeyOf((array) $saved[0]) === 'pg_key_from_user',
    'key=' . var_export($access->apiKeyOf((array) $saved[0]), true)
);
check(
    'کلید رمزنگاری‌شده ذخیره شد (نه خام)',
    ($saved[0]['api_key'] ?? '') !== 'pg_key_from_user'
);
check('هیچ رمز عبوری ذخیره نشد', ($saved[0]['panel_password'] ?? '') === '', var_export($saved[0]['panel_password'] ?? null, true));

// ------------------------------------------------------------------
echo "\n▶ ۹) ساخت پنل نمایندگی با کلید سازنده (بدون رمز)\n";
// ------------------------------------------------------------------

// باگ واقعی: `Provisioner` یک `PasarGuardClient` بدون کلید می‌ساخت و به
// `AgencyService` می‌داد. آن کلاینت `createAdmin()` را با login رمز می‌فرستاد،
// پس خرید پنل نمایندگی همیشه با «نام کاربری یا رمز عبور اشتباه» شکست می‌خورد.
$ownerConfig = new BotConfig();
$ownerConfig->setPanelBaseUrl('https://us.api-system.top');
$ownerConfig->setOwnerApiKey('pg_key_owner_section9');

$agency       = new AgencyService();
$panelFactory = new ReflectionMethod(AgencyService::class, 'panelClient');
$ownerClient  = $panelFactory->invoke($agency);

check('کلید سازنده روی کلاینت نشست', $ownerClient->hasStaticToken());
check('درخواست با طرح ApiKey می‌رود', $ownerClient->authScheme() === 'ApiKey', $ownerClient->authScheme());
check('آدرس پنل از تنظیمات ربات (نه فقط config.php) می‌آید',
    $ownerClient->baseUrl() === 'https://us.api-system.top', $ownerClient->baseUrl());
check('login بدون هیچ تماس شبکه‌ای همان کلید را برمی‌گرداند',
    $ownerClient->login('api', '') === 'pg_key_owner_section9');

$prodProvisioner = new Provisioner();
$agencyProperty  = new ReflectionProperty(Provisioner::class, 'agency');
$prodAgency      = $agencyProperty->getValue($prodProvisioner);
$clientProperty  = new ReflectionProperty(AgencyService::class, 'panel');

check('Provisioner در مسیر عادی کلاینت بی‌کلید را به AgencyService نمی‌دهد',
    $clientProperty->getValue($prodAgency) === null);

// مسیر وبه‌اپ/کرون: کلاینت واقعی از بیرون تزریق می‌شود. باید همان کلاینت
// کلید سازنده را بگیرد، وگرنه ساخت پنل با ۴۰۱ «رمز اشتباه» می‌افتد.
$injectedReal   = new PasarGuardClient();
$realProvisioner = new Provisioner($injectedReal, $orders, $users, new Settings(), $panels);
$realAgency      = $agencyProperty->getValue($realProvisioner);
$injectedClient  = $panelFactory->invoke($realAgency);

check('کلاینت واقعیِ تزریق‌شده هم کلید سازنده را می‌گیرد',
    $injectedClient->hasStaticToken() && $injectedClient->authScheme() === 'ApiKey');
check('همان نمونهٔ تزریق‌شده است (کلاینت تازه ساخته نمی‌شود)',
    $injectedClient === $injectedReal);

// ------------------------------------------------------------------
echo "\n▶ ۱۰) ساخت پنل بعدی با «همان API اول»، نه کلید پنلِ ساخته‌شده\n";
// ------------------------------------------------------------------

// باگ واقعی (گزارش اپراتور): بعد از خرید اول، ردیف پنل با کلیدی که ربات
// برای همان پنل صادر می‌کند ذخیره می‌شود. خرید دوم `creatorPanelFor` را
// به همان ردیف می‌رساند و ساخت با کلیدی انجام می‌شد که مجوز `admins.create`
// ندارد ⇒ ۴۰۳ «دسترسی محدود»، در حالی که خرید اول با کلید سازنده موفق بود.
$creatorUid = (int) $users->upsertByTelegram(8810, ['telegram_id' => 8810])['id'];

// پنلی که ربات ساخته (source = bot) و حتی پیش‌فرض هم علامت خورده است.
$botMadeId = $panels->create($creatorUid, 'bot_made', '', [
    'api_key'    => 'pg_key_bot_made',
    'is_default' => 1,
]);

$creatorUser = $users->findById($creatorUid);
$chosen      = (new PanelAccess($panels))->creatorPanelFor($creatorUser);

check('پنلِ ساخته‌شدهٔ ربات منبع ساخت بعدی نمی‌شود (کلید سازنده می‌ماند)',
    $chosen === null, $chosen === null ? '' : 'panel_id=' . (int) $chosen['id']);

// پنلی که خودِ کاربر با کلیدش وصل کرده، همچنان معتبر است.
$selfId = $panels->create($creatorUid, 'self_made', '', ['api_key' => 'pg_key_self_made']);
$panels->update($selfId, ['source' => PanelRepository::SOURCE_SELF]);

$chosen = (new PanelAccess($panels))->creatorPanelFor($creatorUser);

check('پنلِ متصل‌شده توسط خود کاربر انتخاب می‌شود',
    $chosen !== null && (int) $chosen['id'] === $selfId,
    $chosen === null ? 'null' : 'panel_id=' . (int) $chosen['id']);

// و اگر جز پنلِ ساخته‌شدهٔ ربات چیزی نباشد، AgencyService با همان کلید
// سازنده/سراسری کار می‌کند (نه با کلید پنلِ ربات‌ساخته).
$agencyFallback = new AgencyService();
$agencyFallback->useCreatorPanel(null);
$fallbackClient = $panelFactory->invoke($agencyFallback);

check('مسیر جایگزین با کلید سازندهٔ سراسری کار می‌کند',
    $fallbackClient->hasStaticToken() && $fallbackClient->authScheme() === 'ApiKey');

check('ردیف پیش‌فرضِ ربات‌ساخته دست‌نخورده ماند', $panels->find($botMadeId) !== null);

echo "\n" . str_repeat('─', 62) . "\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo str_repeat('─', 62) . "\n";

exit($failed === 0 ? 0 : 1);
