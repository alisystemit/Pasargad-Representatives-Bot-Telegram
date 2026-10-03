<?php

declare(strict_types=1);

/**
 * تست تیکت پشتیبانی و آمار کاربران پنل.
 *
 * این دو قابلیت هر دو «رابطهٔ نماینده با پنلش» را بهتر می‌کنند و هر دو یک
 * پیامد عملی دارند: تیکت مسیر گفت‌وگو را از پیام خصوصی جدا می‌کند، و آمار
 * کاربران به مدیر اجازه می‌دهد بفهمد کدام نماینده واقعاً فعال است.
 *
 * مرزهای امنیتی که این تست نگهبانی می‌کند:
 *   • کاربر فقط تیکت خودش را می‌بیند.
 *   • پنل مدیریت فقط برای سوپرادمین است.
 *   • پاسخ ادمین حتماً به کاربر می‌رسد (وگرنه تیکت بی‌پاسخ می‌ماند).
 *   • آمار از پنلِ کاربر دیگر خوانده نمی‌شود.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakePanelClient.php';
require_once __DIR__ . '/FakeBotApi.php';
require_once __DIR__ . '/Fixture.php';

use Pasargad\Bot\Kernel;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\PanelUserStats;
use Pasargad\Store\Settings;
use Pasargad\Store\TestDb;
use Pasargad\Store\TicketRepository;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Migrator;
use Pasargad\Panel\FakePanelClient;
use Pasargad\Telegram\FakeBotApi;
use Pasargad\Telegram\Update;

$db = TestDb::boot();
(new Migrator($db))->migrate();

Config::set('super_admins', [999]);
Config::set('store.card_number', '6037999999999999');
Config::set('nowpayments.api_key', 'test-key');
Config::set('panel.base_url', 'https://panel.test');

$settings = new Settings($db);
$flags    = new \Pasargad\Store\FeatureFlags($settings);
$users    = new UserRepository($db);
$orders   = new OrderRepository($db);
$packages = new PackageRepository($db);
$panels   = new PanelRepository($db);
$tickets  = new TicketRepository($db);

$panel = new FakePanelClient();
$bot   = new FakeBotApi();

$provisioner = new \Pasargad\Store\Provisioner($panel, $orders, $users, $settings, $panels);
$payments    = new PaymentService($orders, $provisioner, $settings, $flags, $users);

$kernel = new Kernel(
    $bot, new Notifier($bot), $users, $packages, $orders,
    $provisioner, $payments, $settings, new SessionStore($db), $panel, $flags
);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
        return;
    }

    $failed++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function cb(int $userId, array $payload, int $nonce = 0): Update
{
    $payload = $payload + ['n2' => $nonce];

    return new Update([
        'update_id'      => random_int(1, 999999),
        'callback_query' => [
            'id'            => 'cb' . random_int(1000, 99999),
            'chat_instance' => 'ci',
            'data'          => json_encode($payload),
            'from'          => ['id' => $userId],
            'message'       => ['message_id' => random_int(1, 999), 'chat' => ['id' => $userId]],
        ],
    ]);
}

function txt(int $userId, string $text, int $msgId): Update
{
    return new Update([
        'update_id' => random_int(1, 999999),
        'message'   => [
            'message_id' => $msgId,
            'chat'       => ['id' => $userId],
            'from'       => ['id' => $userId],
            'text'       => $text,
        ],
    ]);
}

$ADMIN = 999;
$USER  = 8100;
$OTHER = 8101;

$users->upsertByTelegram($ADMIN, ['telegram_id' => $ADMIN, 'first_name' => 'سوپر']);

[$user, $userPanel]   = makeRep('tick_panel', $panel, $users, $panels, ['telegram_id' => $USER]);
[$other, $otherPanel] = makeRep('tick_other', $panel, $users, $panels, ['telegram_id' => $OTHER]);

$userDbId   = (int) $user['id'];
$userPanelId = (int) $userPanel['id'];
$otherPanelId = (int) $otherPanel['id'];

// =====================================================================
echo "\n▶ ساخت تیکت از مسیر کاربر\n";
// =====================================================================

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.new'], 1));
check('انتخاب موضوع نمایش داده شد', str_contains($bot->allText(), 'موضوع تیکت'), $bot->allText());
check('گزینه‌های موضوع دیده می‌شوند', $bot->hasButton('مشکل پرداخت') && $bot->hasButton('مشکل پنل'));

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.topic', 'c' => 'payment'], 2));
check('درخواست متن تیکت آمد',
    str_contains($bot->allText(), 'پیام خود را بنویسید'), $bot->allText());

$bot->reset();
$kernel->handle(txt($USER, 'سلام، پرداخت کردم ولی پنلم شارژ نشد.', 3));

$myTicket = $tickets->listByUser($userDbId, 1);
$ticketId = (int) ($myTicket[0]['id'] ?? 0);

check('تیکت ساخته شد', $ticketId > 0);
check('موضوع درست ذخیره شد', (string) ($myTicket[0]['category'] ?? '') === 'payment');
check('پیام اول کاربر ثبت شد', count($tickets->messages($ticketId)) === 1);
check('وضعیت «در انتظار پاسخ» است', (string) $myTicket[0]['status'] === TicketRepository::STATUS_OPEN);
check('کاربر پیام تأیید گرفت', str_contains($bot->allText(), 'ثبت شد'), $bot->allText());
check('و شمارهٔ تیکت را دید', str_contains($bot->allText(), '#'), $bot->allText());

// =====================================================================
echo "\n▶ اعلان به مدیر\n";
// =====================================================================

$adminText = $bot->lastTextFor($ADMIN);
check('ادمین مطلع شد', str_contains($adminText, 'تیکت جدید'), $adminText);
check('موضوع تیکت در اعلان آمد', str_contains($adminText, 'پرداخت'), $adminText);
check('دکمهٔ باز کردن تیکت برای مدیر هست', $bot->hasButton('باز کردن تیکت'));

// =====================================================================
echo "\n▶ کاربر نمی‌تواند تیکت دیگری را ببیند\n";
// =====================================================================

[$stranger] = makeRep('tick_stranger', $panel, $users, $panels, ['telegram_id' => 8102]);
$strangerId = (int) $stranger['id'];

$bot->reset();
$kernel->handle(cb(8102, ['n' => 'ticket.view', 'id' => $ticketId], 4));
check('تیکت کاربر دیگر باز نشد',
    !str_contains($bot->allText(), 'پرداخت کردم'), $bot->allText());
check('پیام «پیدا نشد» گرفت', str_contains($bot->allText(), 'پیدا نشد'), $bot->allText());

$bot->reset();
$kernel->handle(cb(8102, ['n' => 'ticket.reply', 'id' => $ticketId], 5));
check('پاسخ دادن به تیکت دیگری شروع نشد',
    !str_contains($bot->allText(), 'پاسخ دادن'), $bot->allText());

// =====================================================================
echo "\n▶ صف پشتیبانی و پاسخ مدیر\n";
// =====================================================================

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.tickets'], 6));
check('صف پشتیبانی باز شد', str_contains($bot->allText(), 'صف پشتیبانی'), $bot->allText());
check('تیکت در صف هست', str_contains($bot->allText(), '#' . \Pasargad\Support\Str::faNumber($ticketId)), $bot->allText());
check('نام دسته نمایش داده شد', str_contains($bot->allText(), 'پرداخت'), $bot->allText());

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.ticket.view', 'id' => $ticketId], 7));
$adminView = $bot->allText();
check('متن تیکت برای مدیر باز شد', str_contains($adminView, 'پرداخت کردم'), $adminView);
check('اطلاعات مالک نمایش داده شد', str_contains($adminView, 'کاربر:'), $adminView);
check('دکمهٔ پاسخ هست', $bot->hasButton('پاسخ دادن'));

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.ticket.reply', 'id' => $ticketId], 8));
check('درخواست پاسخ آمد', str_contains($bot->allText(), 'پاسخ خود را'), $bot->allText());

$bot->reset();
$kernel->handle(txt($ADMIN, 'سلام، پرداخت شما تایید شد. ۱۰ دقیقه صبر کنید.', 9));

check('پاسخ مدیر ثبت شد', count($tickets->messages($ticketId)) === 2);

$ticketAfter = $tickets->find($ticketId);
check('وضعیت به «پاسخ داده شد» تغییر کرد',
    (string) $ticketAfter['status'] === TicketRepository::STATUS_ANSWERED);
check('زمان پاسخ مدیر ثبت شد', $ticketAfter['admin_reply_at'] !== null);

$replyToUser = $bot->lastTextFor($USER);
check('پاسخ به کاربر هم رسید', str_contains($replyToUser, 'پاسخ پشتیبانی'), $replyToUser);
check('متن پاسخ برای کاربر ارسال شد', str_contains($replyToUser, 'تایید شد'), $replyToUser);
check('نشست ادمین پاک شد', $kernel === null ? false : (new SessionStore($db))->get($ADMIN) === null);

// =====================================================================
echo "\n▶ پیگیری تیکت از سمت کاربر\n";
// =====================================================================

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.list'], 10));
check('فهرست تیکت‌ها باز شد', str_contains($bot->allText(), 'تیکت‌های من'), $bot->allText());
check('تیکت در فهرست هست',
    str_contains($bot->allText(), '#' . \Pasargad\Support\Str::faNumber($ticketId)), $bot->allText());
check('وضعیت «پاسخ داده شده» نشان داده شد',
    str_contains($bot->allText(), 'پاسخ داده شده'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.view', 'id' => $ticketId], 11));
$userView = $bot->allText();
check('گفت‌وگو برای کاربر باز شد', str_contains($userView, 'گفت‌وگو'), $userView);
check('پیام مدیر در نمای کاربر هست', str_contains($userView, 'تایید شد'), $userView);
check('پیام خود کاربر هم هست', str_contains($userView, 'پرداخت کردم'), $userView);
check('دکمهٔ پاسخ دادن هست', $bot->hasButton('پاسخ دادن'));

// =====================================================================
echo "\n▶ پاسخ دوبارهٔ کاربر و چرخهٔ بستن\n";
// =====================================================================

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.reply', 'id' => $ticketId], 12));
check('کاربر وارد حالت پاسخ شد', str_contains($bot->allText(), 'پاسخ خود را'), $bot->allText());

$bot->reset();
$kernel->handle(txt($USER, 'ممنون، منتظر می‌مانم.', 13));
check('پاسخ کاربر ثبت شد', count($tickets->messages($ticketId)) === 3);

$adminNotified = $bot->lastTextFor($ADMIN);
check('مدیر از پاسخ کاربر خبردار شد', str_contains($adminNotified, 'پاسخ جدید کاربر'), $adminNotified);

$bot->reset();
$kernel->handle(cb($ADMIN, ['n' => 'admin.ticket.close', 'id' => $ticketId], 14));
$closed = $tickets->find($ticketId);
check('تیکت بسته شد', (string) $closed['status'] === TicketRepository::STATUS_CLOSED);
check('زمان بستن ثبت شد', $closed['closed_at'] !== null);

// تیکت بسته با پاسخ دوباره باز می‌شود.
//
// کاربر باید اول «💬 پاسخ دادن» را بزند — متن آزاد وقتی نشستی باز نیست به
// تیکت هیچ ربطی ندارد و به منوی عمومی می‌افتد. این عمدی است وگرنه هر پیام
// تصادفی کاربر یک تیکت جدید می‌ساخت.
$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.reply', 'id' => $ticketId], 15));
$kernel->handle(txt($USER, 'یک سوال دیگه دارم', 16));
check('پیام به تیکت بسته ثبت شد', count($tickets->messages($ticketId)) === 4);
check('و تیکت دوباره باز شد',
    (string) $tickets->find($ticketId)['status'] === TicketRepository::STATUS_OPEN,
    (string) $tickets->find($ticketId)['status']);

// =====================================================================
echo "\n▶ لغو و خالی بودن\n";
// =====================================================================

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.new'], 17));
$sessions = new SessionStore($db);
check('نشست انتخاب موضوع باز شد', ($sessions->get($USER)['step'] ?? '') === 'ticket:category');

$kernel->handle(txt($USER, '/cancel', 18));
check('/cancel نشست را بست', $sessions->get($USER) === null);

$countBefore = $tickets->countAll();
$bot->reset();
$kernel->handle(cb($OTHER, ['n' => 'ticket.new'], 19));
$kernel->handle(txt($OTHER, '   ', 20));
check('متن خالی تیکت نمی‌سازد', $tickets->countAll() === $countBefore,
    "before={$countBefore} after=" . $tickets->countAll());

$bot->reset();
$kernel->handle(cb($OTHER, ['n' => 'ticket.topic', 'c' => 'panel'], 21));
$kernel->handle(txt($OTHER, 'وقتی وارد پنل می‌شوم ارور می‌دهد', 22));

$otherTickets = $tickets->listByUser((int) $other['id'], 5);
check('تیکت دوم برای کاربر دیگر ساخته شد', count($otherTickets) === 1);
check('موضوعش پنل است', (string) $otherTickets[0]['category'] === 'panel');

// =====================================================================
echo "\n▶ سوییچ تیکت\n";
// =====================================================================

$flags->setTicketsEnabled(false);
$bot->reset();
$kernel->handle(cb($USER, ['n' => 'ticket.new'], 23));
check('با خاموش بودن، تیکت ساخته نمی‌شود',
    str_contains($bot->allText(), 'غیرفعال'), $bot->allText());

$flags->setTicketsEnabled(true);

// =====================================================================
echo "\n▶ دسترسی به پنل مدیریت\n";
// =====================================================================

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'admin.tickets'], 24));
check('کاربر عادی به صف پشتیبانی دسترسی ندارد',
    !str_contains($bot->allText(), 'صف پشتیبانی'), $bot->allText());

// =====================================================================
echo "\n▶ آمار کاربران پنل\n";
// =====================================================================

// ۵ کاربر روی پنل کاربر: ۳ فعال، ۱ غیرفعال، ۱ منقضی
$panel->clearUsers();
$panel->addUser('enduser_a', ['status' => 'active']);
$panel->addUser('enduser_b', ['status' => 'active']);
$panel->addUser('enduser_c', ['status' => 'active']);
$panel->addUser('enduser_d', ['status' => 'disabled']);
$panel->addUser('enduser_e', ['status' => 'expired']);

$settings->set(Settings::PANEL_STATS_TTL, '30');

$statsService = new PanelUserStats($panels, $settings, $panel);
$freshPanel   = $panels->find($userPanelId);

$result = $statsService->refresh($freshPanel);

check('شمارش کاربران موفق بود', $result['ok'], $result['message']);
check('۵ کاربر شمرده شد', $result['total'] === 5, (string) $result['total']);
check('۳ کاربر فعال', $result['active'] === 3, (string) $result['active']);
check('۲ کاربر غیرفعال/منقضی', $result['disabled'] === 2, (string) $result['disabled']);

$stored = $panels->find($userPanelId);
check('آمار در دیتابیس ذخیره شد', (int) $stored['users_total'] === 5);
check('زمان آمار ثبت شد', (int) $stored['stats_at'] > 0);

// کش
$cached = $statsService->stats($panels->find($userPanelId));
check('دومین درخواست از کش خوانده شد', $cached['cached'] === true, json_encode($cached));

$forced = $statsService->stats($panels->find($userPanelId), true);
check('با اجبار، دوباره خوانده شد', $forced['cached'] === false);
check('و نتیجه یکسان است', $forced['total'] === 5);

// TTL صفر → همیشه تازه
$settings->set(Settings::PANEL_STATS_TTL, '1');
$settings->set(Settings::PANEL_STATS_TTL, '30');

// خطای ورود
$panel->loginError = 'unauthorized';
$broken = $statsService->refresh($panels->find($userPanelId));
check('خطای ورود با پیام روشن برگشت', !$broken['ok'] && $broken['message'] !== '');
$panel->loginError = '';

// =====================================================================
echo "\n▶ صفحهٔ آمار در ربات\n";
// =====================================================================

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'panel.stats', 'id' => $userPanelId], 30));
$statsText = $bot->allText();
check('صفحهٔ آمار باز شد', str_contains($statsText, 'آمار کاربران پنل'), $statsText);
check('تعداد کل نمایش داده شد', str_contains($statsText, 'کل کاربران'), $statsText);
check('تعداد فعال نمایش داده شد', str_contains($statsText, 'فعال'), $statsText);

$bot->reset();
$kernel->handle(cb($OTHER, ['n' => 'panel.stats', 'id' => $userPanelId], 31));
check('کاربر دیگر نمی‌تواند آمار پنل کس دیگر را ببیند',
    !str_contains($bot->allText(), 'کل کاربران'), $bot->allText());

// آمار در صفحهٔ جزئیات پنل هم دیده می‌شود
$bot->reset();
$kernel->handle(cb($USER, ['n' => 'panel.view', 'id' => $userPanelId], 32));
check('آمار در صفحهٔ جزئیات پنل هم هست',
    str_contains($bot->allText(), 'کاربران پنل'), $bot->allText());

$bot->reset();
$kernel->handle(cb($USER, ['n' => 'panel.list'], 33));
check('و در فهرست پنل‌ها هم هست',
    str_contains($bot->allText(), 'فعال: ۳'), $bot->allText());

// دکمهٔ آمار در صفحهٔ جزئیات
$bot->reset();
$kernel->handle(cb($USER, ['n' => 'panel.view', 'id' => $userPanelId], 34));
check('دکمهٔ آمار کاربران هست', $bot->hasButton('آمار کاربران'));

echo "\n───────────────\n";
echo "نتیجه: {$passed} موفق، {$failed} ناموفق\n";
echo "───────────────\n";

exit($failed === 0 ? 0 : 1);