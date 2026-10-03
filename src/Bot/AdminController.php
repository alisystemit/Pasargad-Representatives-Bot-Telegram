<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Payment\PaymentService;
use Pasargad\Store\AccessCutoff;
use Pasargad\Store\AgencyService;
use Pasargad\Store\CouponRepository;
use Pasargad\Store\DiscountService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\PanelSyncer;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TestConfigRepository;
use Pasargad\Store\TicketRepository;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;
use Pasargad\Telegram\Update;

/**
 * پنل مدیریت سوپرادمین.
 *
 * قابلیت‌ها:
 *   • مدیریت بسته‌های فروشگاه (افزودن، ویرایش، فعال/غیرفعال، حذف)
 *   • مشاهده و مدیریت سفارش‌ها
 *   • تأیید/رد رسیدهای کارت‌به‌کارت و اجرای دستی بسته
 *   • **مدیریت پنل‌های نمایندگی**: فهرست، جزئیات، بروزرسانی و **قطع دسترسی
 *     همهٔ کاربران یک پنل پس از اتمام اعتبار**
 *   • مدیریت کاربران ربات (مسدودسازی، جستجو)
 *   • تنظیمات فروشگاه و کانال اجباری
 *   • آمار و گزارش
 */
final class AdminController
{
    private BotApi $bot;
    private Notifier $notifier;
    private UserRepository $users;
    private PackageRepository $packages;
    private OrderRepository $orders;
    private Provisioner $provisioner;
    private PaymentService $payments;
    private Settings $settings;
    private SessionStore $sessions;
    private FeatureFlags $flags;
    private PanelRepository $panels;
    private ?\Pasargad\Panel\PasarGuardClient $panelClient = null;

    public function __construct(
        BotApi $bot,
        Notifier $notifier,
        UserRepository $users,
        PackageRepository $packages,
        OrderRepository $orders,
        Provisioner $provisioner,
        PaymentService $payments,
        Settings $settings,
        ?SessionStore $sessions = null,
        ?FeatureFlags $flags = null,
        ?PanelRepository $panels = null,
        ?\Pasargad\Panel\PasarGuardClient $panelClient = null
    ) {
        $this->bot         = $bot;
        $this->notifier    = $notifier;
        $this->users       = $users;
        $this->packages    = $packages;
        $this->orders      = $orders;
        $this->provisioner = $provisioner;
        $this->payments    = $payments;
        $this->settings    = $settings;
        $this->sessions    = $sessions ?? new SessionStore();
        $this->flags       = $flags ?? new FeatureFlags($settings);
        $this->panels      = $panels ?? new PanelRepository();
        $this->panelClient = $panelClient;
    }

    /**
     * سوییچ‌های فعال/غیرفعال.
     */
    public function flags(): FeatureFlags
    {
        return $this->flags;
    }

    /**
     * مسیریابی callback های مدیریتی.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     */
    public function route(Update $update, array $user, array $data, string $ns): void
    {
        $chatId = (int) $update->chatId();

        switch ($ns) {
            // ---------------- صفحهٔ اصلی ----------------
            case 'admin.home':
                $this->showHome($chatId);
                break;

            case 'admin.stats':
                $this->showStats($chatId);
                break;

            // ---------------- پنل‌های نمایندگی ----------------
            case 'admin.panels':
                $this->showPanels($chatId, (int) ($data['page'] ?? 0), (string) ($data['q'] ?? ''));
                break;

            case 'admin.panel.view':
                $this->showPanel($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.panel.sync':
                $this->syncPanel($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.panel.cutoff':
                $this->previewCutoff($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.panel.cutoff.run':
                $this->runCutoff($chatId, (int) ($data['id'] ?? 0));
                break;

            // ---------------- بسته‌ها ----------------
            case 'admin.packages':
                $this->showPackages($chatId, (int) ($data['page'] ?? 0), false);
                break;

            case 'admin.packages.active':
                $this->showPackages($chatId, (int) ($data['page'] ?? 0), true);
                break;

            case 'admin.pkg.view':
                $this->showPackage($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.pkg.toggle':
                $this->togglePackage($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.pkg.delete':
                $this->deletePackage($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.pkg.new':
                $this->startCreatePackage($chatId);
                break;

            case 'admin.pkg.edit':
                $this->startEditPackage($chatId, (int) ($data['id'] ?? 0), (int) $update->userId());
                break;

            // ---------------- سفارش‌ها ----------------
            case 'admin.orders':
                $this->showOrders($chatId, (int) ($data['page'] ?? 0), (string) ($data['status'] ?? ''));
                break;

            case 'admin.order.view':
                $this->showOrder($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.review':
                $this->reviewOrder($update, $data, (string) ($data['act'] ?? 'approve'));
                break;

            case 'admin.retry':
                $this->retryOrder($chatId, (int) ($data['id'] ?? 0));
                break;

            // ---------------- کاربران ----------------
            case 'admin.users':
                $this->showUsers($chatId, (int) ($data['page'] ?? 0), (string) ($data['q'] ?? ''));
                break;

            case 'admin.user.view':
                $this->showUser($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.user.block':
                $this->toggleBlock($chatId, (int) ($data['id'] ?? 0));
                break;

            // ---------------- تنظیمات ----------------
            case 'admin.settings':
                $this->showSettings($chatId);
                break;

            case 'admin.gateways':
                $this->showGateways($chatId);
                break;

            case 'admin.gateway.toggle':
                $this->toggleGateway($chatId, (string) ($data['name'] ?? ''));
                break;

            case 'admin.flag.toggle':
                $this->toggleFlag($chatId, (string) ($data['key'] ?? ''));
                break;

            // ---------------- تست کانفیگ ----------------
            case 'admin.testconfig':
                $this->showTestConfig($chatId);
                break;

            case 'admin.testconfig.field':
                $this->startEditTestConfig($chatId, (int) $update->userId(), (string) ($data['f'] ?? ''));
                break;

            case 'admin.testconfig.panels':
                $this->showTestConfigPanels($chatId);
                break;

            case 'admin.testconfig.panel.set':
                $this->setTestConfigPanel($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.channel':
                $this->startEditChannel($chatId, (int) $update->userId());
                break;

            // ---------------- کدهای تخفیف ----------------
            case 'admin.coupons':
                $this->showCoupons($chatId);
                break;

            case 'admin.coupon.new':
                $this->startCreateCoupon($chatId, (int) $update->userId());
                break;

            case 'admin.coupon.toggle':
                $this->toggleCoupon($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.coupon.delete':
                $this->deleteCoupon($chatId, (int) ($data['id'] ?? 0));
                break;

            // ---------------- تیکت پشتیبانی ----------------
            case 'admin.tickets':
                $this->showTickets($chatId);
                break;

            case 'admin.ticket.view':
                $this->showTicket($chatId, (int) ($data['id'] ?? 0));
                break;

            case 'admin.ticket.reply':
                $this->startTicketReply($chatId, (int) $update->userId(), (int) ($data['id'] ?? 0));
                break;

            case 'admin.ticket.close':
                $this->closeTicket($chatId, (int) ($data['id'] ?? 0));
                break;

            // ---------------- مهلت ارفاقی و معرفی ----------------
            case 'admin.grow':
                $this->showGrowth($chatId);
                break;

            case 'admin.grow.field':
                $this->startEditNumber($chatId, (int) $update->userId(), (string) ($data['f'] ?? ''));
                break;

            case 'admin.grow.save':
                $this->saveNumber($chatId, (string) ($data['f'] ?? ''), (string) ($data['v'] ?? ''));
                break;

            case 'admin.backup.keep':
                $this->setBackupKeep($chatId, (int) ($data['n'] ?? 14));
                break;

            case 'admin.rules.edit':
                $this->startEditRules($chatId, (int) $update->userId());
                break;

            case 'admin.notice.edit':
                $this->startEditNotice($chatId, (int) $update->userId());
                break;

            case 'admin.notice.reset':
                $this->flags->resetDisabledNotice();
                $this->bot->sendMessage($chatId, '♻️ متن پیش‌فرض بازگردانی شد.');
                $this->showSettings($chatId);
                break;

            case 'admin.broadcast':
                $this->startBroadcast($chatId, (int) $update->userId());
                break;

            case 'admin.broadcast.send':
                $this->doBroadcast($chatId);
                break;

            // ---------------- متن‌های ثابت ----------------
            case 'admin.texts':
                $this->showTexts($chatId);
                break;

            case 'admin.text.edit':
                $this->startEditText($chatId, (int) $update->userId(), (string) ($data['key'] ?? ''));
                break;

            // ---------------- بکاپ ----------------
            case 'admin.backup':
                $this->showBackup($chatId);
                break;

            case 'admin.backup.now':
                $this->doBackupNow($chatId);
                break;

            case 'admin.backup.schedule':
                $this->setBackupSchedule($chatId, (string) ($data['mode'] ?? 'off'));
                break;

            // ---------------- کیف پول ----------------
            case 'admin.wallet.add':
                $this->startWalletAdd($chatId, (int) $update->userId(), (int) ($data['id'] ?? 0));
                break;

            default:
                $this->bot->sendMessage($chatId, '❓ این گزینه در پنل مدیریت تعریف نشده است.');
        }
    }

    // ------------------------------------------------------------------
    // صفحهٔ اصلی
    // ------------------------------------------------------------------

    private function showHome(int $chatId): void
    {
        $stats      = $this->orders->stats();
        $awaiting   = $this->orders->countAwaitingReview();
        $userCount  = $this->users->countAll();
        $panelCount = $this->panels->countAll();
        $atRisk     = $this->users->listAtRiskRepresentatives(50);

        $lines = [
            '🛠✨ <b>پنل مدیریت 👑</b>',
            '',
            '📊✨ <b>وضعیت کلی 📈</b>',
            '👥 کاربران ربات: <b>' . Str::faNumber($userCount) . '</b> 🙋',
            '🖥️ پنل‌های نمایندگی: <b>' . Str::faNumber($panelCount) . '</b> 🌐',
            '🧾 کل سفارش‌ها: <b>' . Str::faNumber($stats['total']) . '</b> 📦',
            '💰 درآمد کل: <b>' . Str::formatToman($stats['revenue']) . '</b> 💵',
            '✅ اجراشده: <b>' . Str::faNumber($stats['applied']) . '</b> 🎉',
        ];

        if ($awaiting > 0) {
            $lines[] = '⏳ در انتظار تأیید رسید: <b>' . Str::faNumber($awaiting) . '</b> 🧾';
        }

        if ($stats['failed'] > 0) {
            $lines[] = '❌ ناموفق: <b>' . Str::faNumber($stats['failed']) . '</b> 😔';
        }

        // ------------------------------------------------------------------
        // نمایندگان در معرض خطر — مهم‌ترین بخش این صفحه.
        //
        // اگر پنلی منقضی شده ولی کاربرانش هنوز فعال‌اند، عملاً سرویس رایگان
        // در حال ارائه است. این باید بلافاصله دیده شود، نه بعد از کلیک
        // چند صفحه‌ای.
        // ------------------------------------------------------------------
        $expiredPanels = [];
        $graceDays    = max(0, $this->settings->int(Settings::EXPIRE_GRACE_DAYS, 3));

        foreach ($this->panels->listAll(200) as $panel) {
            // فقط پنل‌هایی که مهلت ارفاقی‌شان هم تمام شده — یک پنل در مهلت
            // ارفاقی هنوز فرصت تمدید دارد و نباید «در معرض قطع» شمرده شود.
            if (PanelRepository::isGraceOver($panel, $graceDays)) {
                $expiredPanels[] = $panel;
            }
        }

        $pendingCutoff = array_filter(
            $expiredPanels,
            static fn (array $p): bool => $p['cutoff_done_at'] === null
        );

        if ($pendingCutoff !== []) {
            $lines[] = '';
            $lines[] = '✂️ <b>' . Str::faNumber(count($pendingCutoff))
                . ' پنل منقضی با کاربران فعال</b>';

            foreach (array_slice($pendingCutoff, 0, 5) as $panel) {
                $lines[] = '• <code>' . Str::escape((string) $panel['panel_username']) . '</code>';
            }

            $lines[] = '';
        }

        if ($atRisk !== []) {
            $lines[] = '⏳ <b>' . Str::faNumber(count($atRisk)) . ' نماینده نیازمند تمدید</b>';
        }

        $openTickets = (new TicketRepository($this->orders->db()))->countOpen();

        if ($openTickets > 0) {
            $lines[] = '';
            $lines[] = '🎫 <b>' . Str::faNumber($openTickets) . ' تیکت در انتظار پاسخ</b>';
        }

        $lines[] = '';
        $lines[] = '👇✨ یکی از بخش‌ها را انتخاب کنید! 👇🎯';

        $keyboard = Keyboard::rows([
            [
                ['text' => '🖥 پنل‌ها', 'data' => BotApi::encodeData('admin.panels')],
                ['text' => '📦 بسته‌ها', 'data' => BotApi::encodeData('admin.packages')],
            ],
            [
                ['text' => '🧾 سفارش‌ها', 'data' => BotApi::encodeData('admin.orders')],
                ['text' => '👥 کاربران', 'data' => BotApi::encodeData('admin.users')],
            ],
            [
                ['text' => '⚙️ تنظیمات', 'data' => BotApi::encodeData('admin.settings')],
                ['text' => '📊 آمار', 'data' => BotApi::encodeData('admin.stats')],
            ],
            [
                ['text' => '🎫 پشتیبانی', 'data' => BotApi::encodeData('admin.tickets')],
                ['text' => '🎟️ کد تخفیف', 'data' => BotApi::encodeData('admin.coupons')],
            ],
            [
                ['text' => '📣✨ پیام همگانی', 'data' => BotApi::encodeData('admin.broadcast')],
                ['text' => '💾 بکاپ', 'data' => BotApi::encodeData('admin.backup')],
            ],
            [
                ['text' => '🚀 رشد و نگهداشت', 'data' => BotApi::encodeData('admin.grow')],
                ['text' => '✏️ متن‌ها', 'data' => BotApi::encodeData('admin.texts')],
            ],
            [['text' => '🔙 بازگشت به منوی اصلی 🏠', 'data' => BotApi::encodeData('menu')]],
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showStats(int $chatId): void
    {
        $stats    = $this->orders->stats();
        $sold     = $this->orders->soldVolume();
        $packages = $this->packages->allPackages(true);

        $lines = [
            '📊✨ <b>گزارش آماری 📈</b>',
            '',
            '💰✨ <b>فروش 🛒</b>',
            '• 🧾 کل سفارش‌ها: ' . Str::faNumber($stats['total']),
            '• 💵 پرداخت‌شده: ' . Str::faNumber($stats['paid']),
            '• ✅ اجراشده: ' . Str::faNumber($stats['applied']),
            '• ❌ ناموفق: ' . Str::faNumber($stats['failed']),
            '• 💰 درآمد کل: <b>' . Str::formatToman($stats['revenue']) . '</b> 🎉',
            '',
            '💾✨ <b>حجم فروش‌رفته 📦</b>',
            '• 🖥️ پنل نمایندگی: ' . Str::faNumber($sold[PackageRepository::KIND_AGENCY] ?? 0, 1) . ' گیگابایت',
            '• ⚡️ شارژ پنل: ' . Str::faNumber($sold[PackageRepository::KIND_TOPUP] ?? 0, 1) . ' گیگابایت',
            '',
            '🖥️✨ <b>پنل‌ها 🌐</b>',
            '• 🔢 کل پنل‌های ساخته‌شده: ' . Str::faNumber($this->panels->countAll()),
            '• 🟢 نمایندگان فعال: ' . Str::faNumber(count($this->users->listLinkedAdmins())),
            '• 🧪 کانفیگ‌های تست داده‌شده: ' . Str::faNumber(count((new TestConfigRepository())->listByPanel(0))),
            '',
            '📦✨ <b>بسته‌های فعال: ' . Str::faNumber(count($packages)) . ' 🎁</b>',
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back('admin.home', '🛠 پنل مدیریت'),
            ])),
        ]);
    }

    // ------------------------------------------------------------------
    // پنل‌های نمایندگی
    // ------------------------------------------------------------------

    private function showPanels(int $chatId, int $page, string $query): void
    {
        $perPage    = 8;
        $total      = $this->panels->countAll($query);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = max(0, min($page, $totalPages - 1));

        $panels = $this->panels->listAll($perPage, $page * $perPage, $query);

        if ($panels === []) {
            $this->bot->sendMessage($chatId, '🖥️📭 پنلی یافت نشد! 😔', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت'),
                ])),
            ]);

            return;
        }

        $lines = ['🖥️✨ <b>پنل‌های نمایندگی 🌐</b> (' . Str::faNumber($total) . ')', ''];
        $keyboard = [];

        foreach ($panels as $panel) {
            $expired    = PanelRepository::isExpired($panel);
            $needsCut   = $expired && $panel['cutoff_done_at'] === null;
            $icon       = $needsCut ? '✂️' : ($expired ? '⌛️' : '🟢');
            $daysLeft   = PanelRepository::daysLeft($panel);

            $lines[] = $icon . ' <code>' . Str::escape((string) $panel['panel_username']) . '</code>'
                . ' • 👤 ' . (int) ($panel['telegram_id'] ?? 0);
            $lines[] = '   💾 ' . Str::formatBytes((int) $panel['data_limit'])
                . ' • 📥 ' . Str::formatBytes((int) $panel['used_traffic']);

            if ($daysLeft === null) {
                $lines[] = '   ⏳ بدون انقضا';
            } elseif ($expired) {
                $lines[] = '   ⌛️ منقضی‌شده'
                    . ($needsCut ? ' — منتظر قطع دسترسی' : ' — قطع شده');
            } else {
                $lines[] = '   📅 ' . Str::faNumber($daysLeft) . ' روز اعتبار';
            }

            $lines[] = '';

            $keyboard[] = [[
                'text' => $icon . ' ' . Str::truncate((string) $panel['panel_username'], 22),
                'data' => BotApi::encodeData('admin.panel.view', ['id' => (int) $panel['id']]),
            ]];
        }

        $nav = [];

        if ($page > 0) {
            $nav[] = ['text' => '◀️', 'data' => BotApi::encodeData('admin.panels', ['page' => $page - 1, 'q' => $query])];
        }

        if ($page < $totalPages - 1) {
            $nav[] = ['text' => '▶️', 'data' => BotApi::encodeData('admin.panels', ['page' => $page + 1, 'q' => $query])];
        }

        if ($nav !== []) {
            $keyboard[] = $nav;
        }

        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showPanel(int $chatId, int $panelId): void
    {
        $panel = $this->panels->find($panelId);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $user  = $this->users->findById((int) $panel['user_id']);
        $expired = PanelRepository::isExpired($panel);
        $daysLeft = PanelRepository::daysLeft($panel);

        $lines = [
            '🖥️✨ <b>' . Str::escape((string) $panel['panel_username']) . ' 🌟</b>',
            '',
            '🆔 شناسه: ' . (int) $panel['id'] . ' 🔢',
            '👤🙋 نماینده: ' . ($user === null
                ? '➖'
                : Str::escape((string) ($user['first_name'] ?? '')) . ' • <code>' . (int) $user['telegram_id'] . '</code>'),
            '🌐 آدرس: ' . Str::escape(PanelRepository::loginUrl(
                $panel['login_url'] === null ? null : (string) $panel['login_url']
            )) . ' 🔗',
            '🔒 رمز: <code>' . Str::escape($this->panels->plainPassword($panel)) . '</code> 🔑',
            '🎭 نقش پنل: ' . Str::escape((string) ($panel['panel_role'] ?? '—')) . ' 👑',
            '',
            '📶 وضعیت: ' . PanelRepository::statusLabel($panel) . ' 📊',
            '💾 سقف: ' . Str::formatBytes((int) $panel['data_limit']) . ' 📦',
            '📥 مصرف: ' . Str::formatBytes((int) $panel['used_traffic']) . ' 📊',
            '📦 خریداری‌شده: ' . Str::formatBytes((int) $panel['granted_volume']) . ' 🎁',
            '📅 انقضا: ' . ($panel['access_expire_at'] === null
                ? '♾️ بدون محدودیت'
                : Str::date((int) $panel['access_expire_at']) . ' 🗓'),
        ];

        if ($daysLeft !== null && !$expired) {
            $lines[] = '⏳ باقی‌مانده: ' . Str::faNumber($daysLeft) . ' روز';
        }

        $lines[] = '🏷️ منبع: ' . match ((string) $panel['source']) {
            PanelRepository::SOURCE_SELF   => 'ثبت دستی («من پنل دارم»)',
            PanelRepository::SOURCE_LEGACY => 'مهاجرت از نسخهٔ قبل',
            default                        => 'خرید از ربات',
        };

        if (!empty($panel['sub_url'])) {
            $lines[] = '🔗 لینک اشتراک: <code>' . Str::escape((string) $panel['sub_url']) . '</code>';
        }

        if ($panel['cutoff_requested_at'] !== null && $panel['cutoff_done_at'] === null) {
            $lines[] = '';
            $lines[] = '✂️ درخواست قطع دسترسی داده شده ولی هنوز اجرا نشده است.';
        }

        if ($panel['cutoff_done_at'] !== null) {
            $lines[] = '✂️ دسترسی کاربران قطع شد: ' . Str::date((int) $panel['cutoff_done_at'])
                . ' (' . Str::faNumber((int) $panel['cutoff_count']) . ' کاربر)';
        }

        $testConfigs = (new TestConfigRepository())->listByPanel($panelId);

        if ($testConfigs !== []) {
            $lines[] = '';
            $lines[] = '🧪 کانفیگ‌های تست: ' . Str::faNumber(count($testConfigs));
        }

        $lines[] = '';
        $lines[] = '🕒 آخرین بروزرسانی: ' . Str::date((int) ($panel['synced_at'] ?? 0));

        $keyboard = [[
            ['text' => '🔄 بروزرسانی', 'data' => BotApi::encodeData('admin.panel.sync', ['id' => $panelId])],
        ]];

        if ($expired) {
            $keyboard[] = [[
                'text' => $panel['cutoff_done_at'] === null
                    ? '✂️ قطع دسترسی همهٔ کاربران'
                    : '♻️ قطع دسترسی دوباره',
                'data' => BotApi::encodeData('admin.panel.cutoff', ['id' => $panelId]),
            ]];
        }

        if ($user !== null) {
            $keyboard[] = [[
                'text' => '👤 پروندهٔ نماینده',
                'data' => BotApi::encodeData('admin.user.view', ['id' => (int) $user['id']]),
            ]];
        }

        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.panels'), '🖥 بازگشت به پنل‌ها');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    private function syncPanel(int $chatId, int $panelId): void
    {
        $panel = $this->panels->find($panelId);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $syncer = new PanelSyncer($this->panels, $this->panel());
        $result = $syncer->syncOne($panel);

        $this->bot->sendMessage($chatId, ($result['ok'] ? '✅ ' : '⚠️ ')
            . Str::escape((string) $result['message']));

        $this->showPanel($chatId, $panelId);
    }

    /**
     * پیش‌نمایش قطع دسترسی: نشان دادن کاربرانی که قرار است غیرفعال شوند.
     *
     * عمداً قبل از اجرا یک صفحهٔ تأیید نشان داده می‌شود: عملیات برگشت‌پذیر
     * نیست و ممکن است ده‌ها کاربر واقعی را درگیر کند.
     */
    private function previewCutoff(int $chatId, int $panelId): void
    {
        $panel = $this->panels->find($panelId);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $cutoff = new AccessCutoff($this->panels, $this->panel());
        $result = $cutoff->listPanelUsers($panel, 20);

        if (!$result['ok']) {
            $this->bot->sendMessage($chatId, '⚠️ ' . Str::escape((string) $result['message']), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل'),
                ])),
            ]);

            return;
        }

        $users = (array) $result['users'];

        $active   = array_filter($users, static fn (array $u): bool
            => !in_array((string) ($u['status'] ?? ''), ['disabled', 'expired', 'on_hold'], true));
        $inactive = array_values($users);

        $lines = [
            '✂️ <b>قطع دسترسی کاربران پنل</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '👤 نماینده: <code>' . (int) ($panel['telegram_id'] ?? 0) . '</code>',
            '',
            '⚠️ با اجرای این عملیات، دسترسی تمام کاربران فعال این پنل قطع می‌شود.',
            'اطلاعات حساب‌ها و آمار مصرفشان حذف نمی‌شود و با تمدید دوباره قابل فعال‌سازی است.',
            '',
            '👥 کل کاربران: <b>' . Str::faNumber(count($inactive)) . '</b>',
            '⛔️ فعال (قطع می‌شوند): <b>' . Str::faNumber(count($active)) . '</b>',
        ];

        if ($active !== []) {
            $lines[] = '';
            $lines[] = '— کاربران فعال —';

            foreach (array_slice($active, 0, 15) as $user) {
                $lines[] = '• <code>' . Str::escape((string) ($user['username'] ?? '—')) . '</code>'
                    . ' • ' . Str::formatBytes((int) ($user['used_traffic'] ?? 0));
            }

            if (count($active) > 15) {
                $lines[] = '• … و ' . Str::faNumber(count($active) - 15) . ' کاربر دیگر';
            }
        }

        if ($panel['access_expire_at'] !== null && !PanelRepository::isExpired($panel)) {
            $lines[] = '';
            $lines[] = 'ℹ️ توجه: این پنل هنوز منقضی نشده است (انقضا: '
                . Str::date((int) $panel['access_expire_at']) . ').';
        }

        $keyboard = [];

        if ($active !== []) {
            $keyboard[] = [[
                'text' => '✅ تأیید و قطع دسترسی ' . Str::faNumber(count($active)) . ' کاربر',
                'data' => BotApi::encodeData('admin.panel.cutoff.run', ['id' => $panelId]),
            ]];
        }

        $keyboard[] = Keyboard::back(
            BotApi::encodeData('admin.panel.view', ['id' => $panelId]),
            '❌ انصراف'
        );

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    /**
     * اجرای واقعی قطع دسترسی.
     */
    private function runCutoff(int $chatId, int $panelId): void
    {
        $panel = $this->panels->find($panelId);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->bot->sendMessage($chatId, '⏳ در حال قطع دسترسی کاربران…');

        $cutoff = new AccessCutoff($this->panels, $this->panel());
        $result = $cutoff->cutoff($panel);

        $icon = ($result['ok'] ?? false) ? '✅' : '⚠️';

        $this->bot->sendMessage($chatId, $icon . ' ' . Str::escape((string) $result['message']), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل'),
            ])),
        ]);

        // خریدار هم باید مطلع شود که دسترسی مشتریانش قطع شد.
        if (($result['details']['disabled'] ?? 0) > 0) {
            $user = $this->users->findById((int) $panel['user_id']);

            if ($user !== null) {
                $this->notifier->notifyUser((int) $user['telegram_id'], implode("\n", [
                    '✂️ <b>دسترسی کاربران پنل شما قطع شد</b>',
                    '',
                    '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
                    '👥 تعداد کاربران غیرفعال‌شده: ' . Str::faNumber((int) $result['details']['disabled']),
                    '',
                    'برای فعال‌سازی دوباره، پنل را تمدید کنید.',
                ]));
            }
        }

        $this->showPanel($chatId, $panelId);
    }

    private function panel(): \Pasargad\Panel\PasarGuardClient
    {
        if ($this->panelClient === null) {
            $this->panelClient = new \Pasargad\Panel\PasarGuardClient();
        }

        return $this->panelClient;
    }

    // ------------------------------------------------------------------
    // مدیریت بسته‌ها
    // ------------------------------------------------------------------

    private function showPackages(int $chatId, int $page, bool $onlyActive): void
    {
        $all = $this->packages->allPackages(false);

        if ($onlyActive) {
            $all = array_values(array_filter($all, static fn (array $p): bool => (int) $p['is_active'] === 1));
        }

        if ($all === []) {
            $this->bot->sendMessage($chatId, '📦📭 هیچ بسته‌ای تعریف نشده است! 😔', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '➕ بستهٔ جدید', 'data' => BotApi::encodeData('admin.pkg.new')]],
                    Keyboard::back('admin.home', '🛠 پنل مدیریت'),
                ])),
            ]);
            return;
        }

        $perPage  = 8;
        $totalPages = max(1, (int) ceil(count($all) / $perPage));
        $page = max(0, min($page, $totalPages - 1));
        $slice = array_slice($all, $page * $perPage, $perPage);

        $lines = ['📦✨ <b>مدیریت بسته‌ها 🎁</b>', ''];
        $keyboard = [];

        foreach ($slice as $package) {
            $icon = (int) $package['is_active'] === 1 ? '🟢' : '🔴';
            $kind = PackageRepository::kindLabel((string) $package['kind']);
            $lines[] = $icon . ' <b>' . Str::escape((string) $package['title']) . '</b>';
            $lines[] = '   ' . Str::faNumber((float) $package['volume_gb'], 1) . ' گیگ • '
                . Str::faNumber((int) $package['duration_days']) . ' روز • '
                . Str::formatToman((int) $package['price_toman']);
            $lines[] = '   نوع: ' . $kind;
            $lines[] = '';

            $keyboard[] = [[
                'text' => '✏️ ' . Str::truncate((string) $package['title'], 20),
                'data' => BotApi::encodeData('admin.pkg.view', ['id' => (int) $package['id']]),
            ]];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '◀️', 'data' => BotApi::encodeData('admin.packages', ['page' => $page - 1])];
        }
        if ($page < $totalPages - 1) {
            $nav[] = ['text' => '▶️', 'data' => BotApi::encodeData('admin.packages', ['page' => $page + 1])];
        }
        if ($nav !== []) {
            $keyboard[] = $nav;
        }

        $keyboard[] = [['text' => '➕ بستهٔ جدید', 'data' => BotApi::encodeData('admin.pkg.new')]];
        $keyboard[] = Keyboard::back('admin.home', '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showPackage(int $chatId, int $packageId): void
    {
        $package = $this->packages->find($packageId);
        if ($package === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $status = (int) $package['is_active'] === 1 ? '🟢 فعال' : '🔴 غیرفعال';

        $lines = [
            '📦 <b>' . Str::escape((string) $package['title']) . '</b>',
            '',
            'وضعیت: ' . $status,
            'نوع: ' . PackageRepository::kindLabel((string) $package['kind']),
            'حجم: ' . Str::faNumber((float) $package['volume_gb'], 1) . ' گیگابایت',
            'هدیه: ' . Str::faNumber((float) $package['bonus_gb'], 1) . ' گیگابایت',
            'مدت: ' . Str::faNumber((int) $package['duration_days']) . ' روز',
            'قیمت: <b>' . Str::formatToman((int) $package['price_toman']) . '</b>',
            '👥 سقف کاربران پنل: <b>' . PackageRepository::userLimitLabel($package['max_users'] ?? 0) . '</b> 🎯',
            'سقف هر کاربر: ' . ((int) $package['max_per_user'] > 0 ? Str::faNumber((int) $package['max_per_user']) : 'نامحدود'),
        ];

        if (($package['description'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = '📝 ' . Str::escape((string) $package['description']);
        }

        $keyboard = Keyboard::rows([
            [
                ['text' => '✏️ ویرایش', 'data' => BotApi::encodeData('admin.pkg.edit', ['id' => $packageId])],
                ['text' => ((int) $package['is_active'] === 1 ? '🔴 غیرفعال' : '🟢 فعال'), 'data' => BotApi::encodeData('admin.pkg.toggle', ['id' => $packageId])],
            ],
            [['text' => '🗑 حذف بسته', 'data' => BotApi::encodeData('admin.pkg.delete', ['id' => $packageId])]],
            [['text' => '⬅️ بازگشت', 'data' => BotApi::encodeData('admin.packages')]],
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function togglePackage(int $chatId, int $packageId): void
    {
        if ($this->packages->toggle($packageId)) {
            $package = $this->packages->find($packageId);
            $state   = (int) ($package['is_active'] ?? 0) === 1 ? 'فعال شد ✅' : 'غیرفعال شد ⛔️';
            $this->bot->sendMessage($chatId, 'بستهٔ «' . Str::escape((string) ($package['title'] ?? '')) . '» ' . $state);
            $this->showPackage($chatId, $packageId);
        } else {
            $this->bot->sendMessage($chatId, Text::notFound());
        }
    }

    private function deletePackage(int $chatId, int $packageId): void
    {
        $package = $this->packages->find($packageId);
        if ($package === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->packages->delete($packageId);
        $this->bot->sendMessage($chatId, '🗑 بستهٔ «' . Str::escape((string) $package['title']) . '» حذف شد.');
        $this->showPackages($chatId, 0, false);
    }

    private function startCreatePackage(int $chatId): void
    {
        $this->bot->sendMessage($chatId, implode("\n", [
            '➕✨ <b>ساخت بستهٔ جدید 📦</b>',
            '',
            '📝 لطفاً اطلاعات را به این ترتیب و در یک پیام بفرستید: 👇',
            '',
            '<code>عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان | سقف_کاربران</code> 📋',
            '',
            'مثال: 💡',
            '<code>پنل ۱۰۰ گیگ ۳۰ روزه | agency | 100 | 30 | 500000 | 50</code>',
            '',
            '💡 بخش آخر (سقف تعداد کاربران پنل) اختیاری است؛ خالی یعنی نامحدود ♾️.',
            '',
            'نوع بسته: 🏷️',
            '• <code>agency</code> — 🖥️ خرید پنل نمایندگی تازه (ساخت حساب اپراتور) 👑',
            '• <code>topup</code> — ⚡️ شارژ/تمدید یکی از پنل‌های موجود 🔋',
            '',
            'ℹ️💡 برای اینکه کاربر بتواند چند پنل بخرد، سقف هر کاربر را ۰ بگذارید (نامحدود ♾️).',
        ]));
    }

    /**
     * شناسهٔ بسته‌ای که ادمین در حال ویرایش آن است (یا null).
     *
     * Kernel پیش از ساخت بستهٔ جدید این را چک می‌کند تا پیام ویرایش به
     * update منجر شود نه ایجاد رکورد تکراری.
     */
    public function editingPackageId(int $telegramId): ?int
    {
        $state = $this->sessions->get($telegramId);
        $id    = (int) ($state['package_id'] ?? 0);

        if (($state['step'] ?? '') !== 'pkg:edit' || $id <= 0) {
            return null;
        }

        return $id;
    }

    private function startEditPackage(int $chatId, int $packageId, int $telegramId): void
    {
        $package = $this->packages->find($packageId);
        if ($package === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        // بدون این ثبت، Kernel شناسه را نمی‌داند و به‌جای ویرایش، بستهٔ
        // تکراری می‌سازد — یعنی فروش با قیمت اشتباه.
        $this->sessions->set($telegramId, [
            'step'      => 'pkg:edit',
            'package_id' => $packageId,
        ]);

        $this->bot->sendMessage($chatId, implode("\n", [
            '✏️✨ <b>ویرایش بسته 📦</b>',
            '',
            'بسته: <b>' . Str::escape((string) $package['title']) . '</b> 🎁',
            '',
            '📋 فرمت: <code>عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان | سقف_کاربران</code>',
            '',
            '📌 مقادیر فعلی: 👇',
            '<code>' . implode(' | ', [
                (string) $package['title'],
                (string) $package['kind'],
                (string) $package['volume_gb'],
                (string) $package['duration_days'],
                (string) $package['price_toman'],
                (string) ($package['max_users'] ?? 0),
            ]) . '</code>',
        ]));
    }

    // ------------------------------------------------------------------
    // مدیریت سفارش‌ها
    // ------------------------------------------------------------------

    private function showOrders(int $chatId, int $page, string $status): void
    {
        $status = $status === '' ? '' : $status;
        $all    = $this->orders->listAll(200, 0, $status !== '' ? $status : null);
        $total  = count($all);

        if ($all === []) {
            $this->bot->sendMessage($chatId, '🧾📭 سفارشی با این فیلتر یافت نشد! 😔🔍', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('admin.home', '🛠 پنل مدیریت')])),
            ]);
            return;
        }

        $perPage    = 8;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = max(0, min($page, $totalPages - 1));
        $slice      = array_slice($all, $page * $perPage, $perPage);

        $statusLabels = [
            'created'          => '🆕',
            'awaiting_payment' => '⏳',
            'paid'             => '💰',
            'applying'         => '⚙️',
            'applied'          => '✅',
            'failed'           => '❌',
            'rejected'         => '🚫',
            'cancelled'        => '🚫',
            'refunded'         => '↩️',
        ];

        $lines = ['🧾✨ <b>مدیریت سفارش‌ها 📦</b> (' . Str::faNumber($total) . ')', ''];
        $keyboard = [];

        foreach ($slice as $order) {
            $icon = $statusLabels[(string) $order['status']] ?? '•';
            $lines[] = $icon . ' <code>' . Str::escape((string) $order['code']) . '</code>';
            $lines[] = '   ' . Str::escape((string) $order['package_title']);
            $lines[] = '   ' . Str::formatToman((int) $order['price_toman'])
                . ' • ' . Str::escape((string) ($order['panel_username'] ?? '—'));
            $lines[] = '';

            $keyboard[] = [[
                'text' => $icon . ' ' . Str::truncate((string) $order['code'] . ' • ' . $order['package_title'], 26),
                'data' => BotApi::encodeData('admin.order.view', ['id' => (int) $order['id']]),
            ]];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '◀️', 'data' => BotApi::encodeData('admin.orders', ['page' => $page - 1, 'status' => $status])];
        }
        if ($page < $totalPages - 1) {
            $nav[] = ['text' => '▶️', 'data' => BotApi::encodeData('admin.orders', ['page' => $page + 1, 'status' => $status])];
        }
        if ($nav !== []) {
            $keyboard[] = $nav;
        }

        // فیلترهای سریع
        $keyboard[] = [
            ['text' => '⏳ رسیدها', 'data' => BotApi::encodeData('admin.orders', ['status' => 'awaiting_payment'])],
            ['text' => '❌ ناموفق', 'data' => BotApi::encodeData('admin.orders', ['status' => 'failed'])],
            ['text' => '🚫 رد شده', 'data' => BotApi::encodeData('admin.orders', ['status' => 'rejected'])],
        ];
        $keyboard[] = Keyboard::back('admin.home', '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showOrder(int $chatId, int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $lines = [Text::orderDetails($order)];

        $payment = $this->orders->lastPayment($orderId);
        if ($payment !== null) {
            $lines[] = '';
            $lines[] = '💳 روش پرداخت: ' . Str::escape((string) $payment['method']);
            if ($payment['external_id'] !== null) {
                $lines[] = '🔗 شناسه: <code>' . Str::escape((string) $payment['external_id']) . '</code>';
            }
        }

        $logs = $this->orders->provisionLogs($orderId, 3);
        if ($logs !== []) {
            $lines[] = '';
            $lines[] = '📜 <b>لاگ اخیر</b>';
            foreach ($logs as $log) {
                $lines[] = '• [' . Str::escape((string) $log['status']) . '] ' . Str::escape(Str::truncate((string) $log['message'], 80));
            }
        }

        $keyboard = [];

        if ($order['status'] === OrderRepository::STATUS_AWAITING_PAYMENT && $order['receipt_file_id'] !== null) {
            $keyboard[] = [[
                'text' => '✅ تأیید رسید',
                'data' => BotApi::encodeData('admin.review', ['id' => $orderId, 'act' => 'approve']),
            ]];
            $keyboard[] = [[
                'text' => '❌ رد پرداخت',
                'data' => BotApi::encodeData('admin.review', ['id' => $orderId, 'act' => 'reject']),
            ]];
        }

        // «اجرای دستی» فقط برای سفارش‌هایی که هنوز نهایی نشده‌اند؛
        // سفارش ردشده/لغوشده/بازگشت‌وجه هرگز نباید اجرا شود (حتی با دکمهٔ ادمین).
        if (
            in_array((string) $order['status'], [OrderRepository::STATUS_PAID, OrderRepository::STATUS_FAILED], true)
            && !$this->orders->isTerminal((string) $order['status'])
        ) {
            $keyboard[] = [[
                'text' => '⚙️ اجرای بسته روی پنل',
                'data' => BotApi::encodeData('admin.retry', ['id' => $orderId]),
            ]];
        }

        $keyboard[] = Keyboard::back('admin.orders', '🧾 بازگشت به سفارش‌ها');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function reviewOrder(Update $update, array $data, string $action): void
    {
        $chatId   = (int) $update->chatId();
        $orderId  = (int) ($data['id'] ?? 0);
        $adminId  = (int) ($update->userId() ?? 0);
        $order    = $this->orders->find($orderId);

        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $approved = $action === 'approve';
        $result   = $this->payments->reviewOrder($order, $approved, $adminId);

        // اطلاع به کاربر
        $user = $this->users->findById((int) $order['user_id']);
        if ($user !== null) {
            $this->notifier->notifyUser(
                (int) $user['telegram_id'],
                ($approved ? '✅ ' : '❌ ') . '<b>سفارش ' . Str::escape((string) $order['code']) . '</b>\n\n'
                . Str::escape((string) $result['message'])
            );
        }

        $this->bot->sendMessage($chatId, ($approved ? '✅🎉' : '❌') . ' ' . Str::escape((string) $result['message']));

        if ($approved) {
            $this->showOrder($chatId, $orderId);
        } else {
            $this->showOrders($chatId, 0, '');
        }
    }

    private function retryOrder(int $chatId, int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        // دکمه مخفی شده اما callback قدیمی در چت همچنان قابل کلیک است؛
        // سفارش نهایی (ردشده/لغوشده/بازگشت‌وجه) هرگز نباید اجرا شود.
        if ($this->orders->isTerminal((string) $order['status'])) {
            $this->bot->sendMessage($chatId, '🚫🔒 این سفارش نهایی شده و قابل اجرا نیست! 😔 وضعیت: '
                . Str::escape(Text::statusLabel((string) $order['status'])));

            $this->showOrder($chatId, $orderId);
            return;
        }

        $this->bot->sendMessage($chatId, '⏳⚙️ در حال اجرای بسته روی پنل... 🔄');

        $result = $this->provisioner->provision($order);

        $icon = $result['ok'] ? '✅' : '⚠️';
        $this->bot->sendMessage($chatId, $icon . ' ' . Str::escape((string) $result['message']));

        if (!$result['ok']) {
            $user = $this->users->findById((int) $order['user_id']);
            if ($user !== null) {
                $this->notifier->notifyUser(
                    (int) $user['telegram_id'],
                    "⚠️ اجرای بستهٔ شما ناموفق بود.\n\nسفارش: <code>" . Str::escape((string) $order['code']) . "</code>\n"
                    . 'دلیل: ' . Str::escape((string) $result['message'])
                );
            }
        }

        $this->showOrder($chatId, $orderId);
    }

    // ------------------------------------------------------------------
    // مدیریت کاربران
    // ------------------------------------------------------------------

    private function showUsers(int $chatId, int $page, string $query): void
    {
        $perPage    = 10;
        $total      = $this->users->countAll($query);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = max(0, min($page, $totalPages - 1));

        $users = $this->users->listAll($perPage, $page * $perPage, $query);

        if ($users === []) {
            $this->bot->sendMessage($chatId, '👥📭 کاربری یافت نشد! 😔🔍', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('admin.home', '🛠 پنل مدیریت')])),
            ]);
            return;
        }

        $lines = ['👥✨ <b>کاربران ربات 🙋</b> (' . Str::faNumber($total) . ')', ''];
        $keyboard = [];

        foreach ($users as $user) {
            $blocked = (int) $user['is_blocked'] === 1;
            $panels  = $this->panels->listByUser((int) $user['id']);
            $icon    = $blocked ? '🚫' : ($panels === [] ? '⚪️' : '🟢');

            $lines[] = $icon . ' ' . Str::escape((string) ($user['first_name'] ?? $user['username'] ?? 'کاربر'))
                . ' <code>' . (int) $user['telegram_id'] . '</code>';
            $lines[] = '   🖥 ' . Str::faNumber(count($panels)) . ' پنل • خرید: '
                . Str::formatToman((int) $user['total_paid']);
            $lines[] = '';

            $keyboard[] = [[
                'text' => $icon . ' ' . Str::truncate((string) ($user['first_name'] ?? $user['telegram_id']), 24),
                'data' => BotApi::encodeData('admin.user.view', ['id' => (int) $user['id']]),
            ]];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '◀️', 'data' => BotApi::encodeData('admin.users', ['page' => $page - 1, 'q' => $query])];
        }
        if ($page < $totalPages - 1) {
            $nav[] = ['text' => '▶️', 'data' => BotApi::encodeData('admin.users', ['page' => $page + 1, 'q' => $query])];
        }
        if ($nav !== []) {
            $keyboard[] = $nav;
        }
        $keyboard[] = Keyboard::back('admin.home', '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showUser(int $chatId, int $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $blocked = (int) $user['is_blocked'] === 1;
        $panels  = $this->panels->listByUser($userId);

        $lines = [
            '👤✨ <b>جزئیات نماینده 🎫</b>',
            '',
            '🆔📱 تلگرام: <code>' . (int) $user['telegram_id'] . '</code>',
            '👤📝 نام: ' . Str::escape((string) ($user['first_name'] ?? '—')),
            '🆔💬 یوزرنیم تلگرام: @' . Str::escape((string) ($user['username'] ?? '—')),
            '',
            '💰👛 کیف پول: <b>' . Str::formatToman((int) ($user['wallet_balance'] ?? 0)) . '</b> 🪙',
            '',
            '🖥️🌐 <b>پنل‌ها: ' . Str::faNumber(count($panels)) . ' 🎯</b>',
        ];

        if ($panels === []) {
            $lines[] = 'پنلی ثبت نشده است.';
        }

        foreach ($panels as $panel) {
            $daysLeft = PanelRepository::daysLeft($panel);

            $lines[] = '';
            $lines[] = PanelRepository::statusLabel($panel) . ' <code>'
                . Str::escape((string) $panel['panel_username']) . '</code>';
            $lines[] = '   💾 ' . Str::formatBytes((int) $panel['data_limit'])
                . ' • 📥 ' . Str::formatBytes((int) $panel['used_traffic']);
            $lines[] = '   📅 ' . ($daysLeft === null
                ? 'بدون انقضا'
                : ($daysLeft < 0 ? 'منقضی‌شده' : Str::faNumber($daysLeft) . ' روز اعتبار'));
        }

        $lines[] = '';
        $lines[] = '🧾 سفارش‌ها: ' . Str::faNumber((int) $user['orders_count']);
        $lines[] = '💰 مجموع خرید: ' . Str::formatToman((int) $user['total_paid']);
        $lines[] = '📅 عضویت: ' . Str::date((int) $user['created_at']);
        $lines[] = '🕒 آخرین بازدید: ' . Str::date((int) ($user['last_seen_at'] ?? 0));

        if ($blocked) {
            $lines[] = '';
            $lines[] = '🚫 مسدود: ' . Str::escape((string) ($user['blocked_reason'] ?? ''));
        }

        $keyboard = [];

        foreach (array_slice($panels, 0, 5) as $panel) {
            $keyboard[] = [[
                'text' => '🖥 ' . Str::truncate((string) $panel['panel_username'], 22),
                'data' => BotApi::encodeData('admin.panel.view', ['id' => (int) $panel['id']]),
            ]];
        }

        $keyboard[] = [[
            'text' => $blocked ? '🟢 رفع مسدودی' : '🚫 مسدود کردن',
            'data' => BotApi::encodeData('admin.user.block', ['id' => $userId]),
        ]];

        $keyboard[] = [[
            'text' => '💰👛 شارژ/کسر کیف پول',
            'data' => BotApi::encodeData('admin.wallet.add', ['id' => $userId]),
        ]];

        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.users'), '👥🔙 بازگشت به کاربران');
        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    private function toggleBlock(int $chatId, int $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $blocked = (int) $user['is_blocked'] === 1;

        if ($blocked) {
            $this->users->setBlocked($userId, false);
            $this->bot->sendMessage($chatId, '🟢✅ مسدودی کاربر برداشته شد! 🎉');

            $this->notifier->notifyUser((int) $user['telegram_id'], '✅🎉 <b>دسترسی شما دوباره فعال شد! 🟢</b>');
        } else {
            $this->users->setBlocked($userId, true, 'مسدود توسط سوپرادمین');
            $this->bot->sendMessage($chatId, '🚫🔒 کاربر مسدود شد! ⛔️');

            $this->notifier->notifyUser((int) $user['telegram_id'], Text::blocked('مسدود توسط سوپرادمین'));
        }

        $this->showUser($chatId, $userId);
    }

    // ------------------------------------------------------------------
    // تنظیمات
    // ------------------------------------------------------------------

    private function showSettings(int $chatId): void
    {
        $flags     = $this->flags();
        $shopOpen  = $this->settings->bool(Settings::SHOP_OPENED, true);
        $autoApply = $this->settings->bool(Settings::AUTO_APPLY, true);
        $botOn     = $flags->isBotEnabled();
        $channelOn = $this->settings->bool(Settings::CHANNEL_ENFORCED, false);
        $channel   = trim((string) $this->settings->get(Settings::CHANNEL, ''));

        $lines = [
            '⚙️✨ <b>تنظیمات ربات 🤖</b>',
            '',
            ($botOn ? '🟢' : '🔴') . ' کل ربات: <b>' . ($botOn ? 'فعال ✅' : 'غیرفعال 🔴') . '</b>',
            ($shopOpen ? '🟢' : '🔴') . ' فروشگاه: <b>' . ($shopOpen ? 'باز 🛒' : 'بسته 🔴') . '</b>',
            ($autoApply ? '🟢' : '🔴') . ' اجرای خودکار بسته: <b>' . ($autoApply ? 'فعال ✅' : 'غیرفعال 🔴') . '</b>',
        ];

        // درگاه‌ها
        $lines[] = '';
        $lines[] = '💳✨ <b>درگاه‌های پرداخت 💰</b>';

        foreach ($flags->gatewayStatuses() as $name => $status) {
            $label = match ($name) {
                'card2card' => '💳 کارت‌به‌کارت دستی 📝',
                'autocard'  => '⚡️💳 کارت‌به‌کارت خودکار 🤖',
                default     => '🪙 ارز دیجیتال 💱',
            };
            $icon  = $status['enabled'] ? '🟢' : '🔴';
            $note  = '';

            if (!$status['configured']) {
                $note = '  (⚠️ پیکربندی نشده در config.php)';
            }

            $lines[] = $icon . ' ' . $label . ': <b>' . ($status['enabled'] ? 'فعال ✅' : 'غیرفعال 🔴') . '</b>' . $note;
        }

        // ------------------------------------------------------------------
        // نمایندگان و پنل‌ها
        // ------------------------------------------------------------------
        $agencyReady = (new AgencyService())->canCreatePanels()['ok'];

        $lines[] = '';
        $lines[] = '🖥️✨ <b>نمایندگان 👥</b>';
        $lines[] = ($flags->isPanelSyncEnabled() ? '🟢' : '🔴') . ' همگام‌سازی خودکار پنل‌ها: <b>'
            . ($flags->isPanelSyncEnabled() ? 'فعال ✅' : 'غیرفعال 🔴') . '</b> 🔄';
        $lines[] = ($flags->isTestConfigEnabled() ? '🟢' : '🔴') . ' تست کانفیگ: <b>'
            . ($flags->isTestConfigEnabled() ? 'فعال ✅' : 'غیرفعال 🔴') . '</b> 🧪';
        $lines[] = ($flags->isCutoffOnExpireEnabled() ? '🟢' : '🔴') . ' قطع دسترسی پس از انقضا: <b>'
            . ($flags->isCutoffOnExpireEnabled() ? 'فعال ✅' : 'غیرفعال 🔴') . '</b> ✂️';
        $lines[] = '⏳ هشدار انقضا: <b>' . Str::faNumber(max(0, $this->settings->int(Settings::EXPIRE_WARN_DAYS, 3))) . ' روز</b>';
        $lines[] = '🛡 مهلت ارفاقی: <b>' . Str::faNumber(max(0, $this->settings->int(Settings::EXPIRE_GRACE_DAYS, 3)))
            . ' روز</b> پس از انقضا';
        $lines[] = '💧 هشدار حجم کم: <b>' . Str::faNumber(max(1, $this->settings->int(Settings::LOW_VOLUME_ALERT, 5))) . '٪</b>';

        $lines[] = ($agencyReady ? '🟢' : '🔴') . ' ساخت پنل نمایندگی: <b>'
            . ($agencyReady ? 'آماده' : 'نیازمند تنظیم اکانت سازنده در config.php') . '</b>';

        $testPanelId = $this->settings->int(Settings::TEST_CONFIG_PANEL_ID, 0);
        $testPanelLabel = '👤 خودکار';

        if ($testPanelId > 0) {
            $testPanel = $this->panels->find($testPanelId);
            $testPanelLabel = $testPanel !== null
                ? '🖥️ ' . Str::escape((string) $testPanel['panel_username'])
                : '⚠️ حذف‌شده';
        }

        $lines[] = '🧪 حجم تست: <b>' . Str::faNumber((float) $this->settings->get(Settings::TEST_CONFIG_VOLUME_GB, '1'), 1) . ' گیگ</b> 📦'
            . ' • مدت: <b>' . Str::faNumber($this->settings->int(Settings::TEST_CONFIG_DAYS, 1)) . ' روز</b> 📅'
            . ' • سقف همزمان: <b>' . Str::faNumber($this->settings->int(Settings::TEST_CONFIG_MAX, 2)) . '</b> 👥'
            . ' • فاصله: <b>' . Str::faNumber($this->settings->int(Settings::TEST_CONFIG_COOLDOWN, 30)) . ' دقیقه</b> ⏳';
        $lines[] = '🖥️ پنل تست: <b>' . $testPanelLabel . '</b> 🌐';

        // کانال و قوانین
        $lines[] = '';
        $lines[] = '📢✨ <b>عضویت و قوانین 📜</b>';
        $lines[] = ($channelOn ? '🟢' : '🔴') . ' عضویت اجباری کانال: <b>'
            . ($channelOn ? 'اجباری ✅' : 'غیرفعال 🔴') . '</b> 📡';
        $lines[] = '📡 کانال: <b>' . ($channel === '' ? '<i>➖ تعیین نشده</i>' : Str::escape($channel)) . '</b> 🔗';

        $keyboard = Keyboard::rows([
            [
                ['text' => $botOn ? '🔴 غیرفعال کردن ربات' : '🟢 فعال کردن ربات',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::BOT_ENABLED])],
                ['text' => '✏️ متن غیرفعالی',
                 'data' => BotApi::encodeData('admin.notice.edit')],
            ],
            [
                ['text' => $shopOpen ? '🔴 بستن فروشگاه' : '🟢 باز کردن فروشگاه',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::SHOP_OPENED])],
                ['text' => $autoApply ? '⛔️ اجرای خودکار' : '✅ اجرای خودکار',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::AUTO_APPLY])],
            ],
            [
                ['text' => ($flags->isPanelSyncEnabled() ? '🔴' : '🟢') . ' همگام‌سازی',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::PANEL_SYNC])],
                ['text' => ($flags->isTestConfigEnabled() ? '🔴' : '🟢') . ' تست کانفیگ',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::TEST_CONFIG_ENABLED])],
            ],
            [
                ['text' => '🧪⚙️ تنظیمات تست (حجم، زمان، پنل)', 'data' => BotApi::encodeData('admin.testconfig')],
            ],
            [
                ['text' => ($flags->isCutoffOnExpireEnabled() ? '🔴' : '🟢') . ' قطع پس از انقضا',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::CUTOFF_ON_EXPIRE])],
                ['text' => ($channelOn ? '🔴' : '🟢') . ' عضویت اجباری',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::CHANNEL_ENFORCED])],
            ],
            [
                ['text' => '📡 تنظیم کانال', 'data' => BotApi::encodeData('admin.channel')],
                ['text' => '📜 ویرایش قوانین', 'data' => BotApi::encodeData('admin.rules.edit')],
            ],
            [
                ['text' => '✏️✨ متن‌های ثابت', 'data' => BotApi::encodeData('admin.texts')],
                ['text' => '💾 بکاپ', 'data' => BotApi::encodeData('admin.backup')],
            ],
            [
                ['text' => '🚀 رشد و نگهداشت', 'data' => BotApi::encodeData('admin.grow')],
                ['text' => ($flags->isCouponsEnabled() ? '🔴' : '🟢') . ' کد تخفیف',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::COUPONS_ENABLED])],
            ],
            [
                ['text' => ($flags->isReferralEnabled() ? '🔴' : '🟢') . ' معرفی',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::REFERRAL_ENABLED])],
                ['text' => ($flags->isTicketsEnabled() ? '🔴' : '🟢') . ' تیکت پشتیبانی',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::TICKETS_ENABLED])],
            ],
            [['text' => '💳 مدیریت درگاه‌های پرداخت', 'data' => BotApi::encodeData('admin.gateways')]],
            [
                ['text' => '🖥 پنل‌ها', 'data' => BotApi::encodeData('admin.panels')],
                ['text' => '📊 آمار', 'data' => BotApi::encodeData('admin.stats')],
            ],
            [['text' => '🔙 بازگشت به پنل مدیریت 🛠', 'data' => BotApi::encodeData('admin.home')]],
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * شروع ویرایش کانال اجباری.
     */
    public function startEditChannel(int $chatId, int $adminId): void
    {
        $current = trim((string) $this->settings->get(Settings::CHANNEL, ''));

        $this->bot->sendMessage($chatId, implode("\n", [
            '📡✨ <b>کانال اجباری عضویت 📢</b>',
            '',
            'تا وقتی کاربر در این کانال عضو نشود، ربات به او دسترسی نمی‌دهد! 🔒',
            '',
            '📌 <b>مقدار فعلی:</b> ' . ($current === '' ? '<i>➖ تعیین نشده</i>' : Str::escape($current)),
            '',
            '────────────────────',
            '📝 نام کانال را بفرستید! هر سه شکل پذیرفته است: 👇',
            '<code>@my_channel</code> یا <code>my_channel</code> یا لینک کامل 🔗',
            '',
            'برای غیرفعال کردن اجبار، «پیش‌فرض» یا «حذف» را بفرستید. ♻️',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.settings'), '❌ انصراف'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_channel']);
    }

    /**
     * ذخیرهٔ کانالی که ادمین فرستاده است.
     */
    public function saveChannel(int $chatId, string $text): void
    {
        $text = trim($text);

        if ($text === '' || in_array($text, ['حذف', 'پیش‌فرض', 'none', 'off'], true)) {
            $this->settings->set(Settings::CHANNEL, '');
            $this->bot->sendMessage($chatId, '♻️ کانال پاک شد و عضویت اجباری غیرفعال گردید.');
            $this->showSettings($chatId);

            return;
        }

        // اگر لینک کامل فرستاده شد، فقط شناسهٔ کانال از آن بیرون کشیده می‌شود.
        if (preg_match('#t\.me/(?:s/)?([A-Za-z0-9_+\-/]+)#', $text, $m) === 1) {
            $text = '@' . $m[1];
        }

        if (!preg_match('/^@?[A-Za-z0-9_+\-]{4,64}$/', $text)) {
            $this->bot->sendMessage($chatId, '⚠️ نام کانال نامعتبر است. مثال: <code>@my_channel</code>');
            return;
        }

        $this->settings->set(Settings::CHANNEL, ltrim($text, '@'));
        $this->bot->sendMessage($chatId, '✅ کانال ذخیره شد. برای اعمال، سوییچ «عضویت اجباری» باید روشن باشد.');
        $this->showSettings($chatId);
    }

    /**
     * شروع ویرایش متن قوانین.
     */
    public function startEditRules(int $chatId, int $adminId): void
    {
        $current = trim((string) $this->settings->get(Settings::RULES_TEXT, ''));

        $this->bot->sendMessage($chatId, implode("\n", [
            '📜✨ <b>ویرایش قوانین ⚖️</b>',
            '',
            'این متن در صفحهٔ «📜 قوانین» به همهٔ کاربران نشان داده می‌شود! 👥',
            '',
            'متن از HTML پشتیبانی می‌کند (<b>bold</b>، <i>italic</i>، <code>code</code>). 💻',
            '',
            '📌 <b>متن فعلی:</b>',
            $current === '' ? '<i>➖ متن پیش‌فرض فعال است</i>' : $current,
            '',
            '────────────────────',
            '📝 متن جدید را بفرستید! برای بازگردانی پیش‌فرض «پیش‌فرض» را بنویسید. ♻️',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.settings'), '❌ انصراف'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_rules']);
    }

    /**
     * ذخیرهٔ متن قوانین.
     */
    public function saveRules(int $chatId, string $text): void
    {
        $text = trim($text);

        if (mb_strlen($text) > 3500) {
            $this->bot->sendMessage($chatId, '⚠️ متن بیش از حد طولانی است (حداکثر ۳۵۰۰ کاراکتر).');
            return;
        }

        if ($text === '' || $text === 'پیش‌فرض') {
            $this->settings->set(Settings::RULES_TEXT, '');
            $this->bot->sendMessage($chatId, '♻️ متن پیش‌فرض قوانین بازگردانی شد. ✅');
        } else {
            $this->settings->set(Settings::RULES_TEXT, $text);
            $this->bot->sendMessage($chatId, '✅ متن قوانین ذخیره شد. 🎉');
        }

        $this->showSettings($chatId);
    }

    // ------------------------------------------------------------------
    // 🧪 تنظیمات تست کانفیگ (حجم، زمان، سقف، فاصله، پنل مربوط)
    // ------------------------------------------------------------------

    /**
     * @return array<string, string> فیلد => برچسب فارسی
     */
    public static function testConfigFields(): array
    {
        return [
            'volume'   => '💾 حجم تست (گیگ)',
            'days'     => '📅 مدت تست (روز)',
            'max'      => '👥 سقف همزمان',
            'cooldown' => '⏳ فاصله (دقیقه)',
        ];
    }

    private function showTestConfig(int $chatId): void
    {
        $volume   = (float) $this->settings->get(Settings::TEST_CONFIG_VOLUME_GB, '1');
        $days     = $this->settings->int(Settings::TEST_CONFIG_DAYS, 1);
        $max      = $this->settings->int(Settings::TEST_CONFIG_MAX, 2);
        $cooldown = $this->settings->int(Settings::TEST_CONFIG_COOLDOWN, 30);
        $panelId  = $this->settings->int(Settings::TEST_CONFIG_PANEL_ID, 0);

        $panelLabel = '👤 پنل خود کاربر (پیش‌فرض) ✅';

        if ($panelId > 0) {
            $panel = $this->panels->find($panelId);
            $panelLabel = $panel !== null
                ? '🖥️ <code>' . Str::escape((string) $panel['panel_username']) . '</code> 🌐'
                : '⚠️ پنل حذف‌شده (به پیش‌فرض برمی‌گردد)';
        }

        $lines = [
            '🧪⚙️ <b>تنظیمات تست کانفیگ 🎁</b>',
            '',
            '💾 حجم هر تست: <b>' . Str::faNumber($volume, 1) . ' گیگ</b> 📦',
            '📅 مدت هر تست: <b>' . Str::faNumber($days) . ' روز</b> ⏳',
            '👥 سقف همزمان هر کاربر: <b>' . ($max > 0 ? Str::faNumber($max) : 'نامحدود ♾️') . '</b>',
            '⏰ فاصلهٔ بین دو تست: <b>' . Str::faNumber($cooldown) . ' دقیقه</b>',
            '🖥️ پنل تست: ' . $panelLabel,
            '',
            '💡 اگر پنل خاصی تعیین شود، همهٔ تست‌ها روی همان پنل ساخته می‌شوند؛ وگرنه روی پنل خود کاربر. 👇',
        ];

        $keyboard = Keyboard::rows([
            [
                ['text' => '💾 حجم', 'data' => BotApi::encodeData('admin.testconfig.field', ['f' => 'volume'])],
                ['text' => '📅 مدت', 'data' => BotApi::encodeData('admin.testconfig.field', ['f' => 'days'])],
            ],
            [
                ['text' => '👥 سقف', 'data' => BotApi::encodeData('admin.testconfig.field', ['f' => 'max'])],
                ['text' => '⏳ فاصله', 'data' => BotApi::encodeData('admin.testconfig.field', ['f' => 'cooldown'])],
            ],
            [['text' => '🖥️ انتخاب پنل تست', 'data' => BotApi::encodeData('admin.testconfig.panels')]],
            Keyboard::back(BotApi::encodeData('admin.settings'), '⚙️ بازگشت به تنظیمات 🔙'),
            Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    public function startEditTestConfig(int $chatId, int $adminId, string $field): void
    {
        $labels = self::testConfigFields();

        if (!isset($labels[$field])) {
            $this->bot->sendMessage($chatId, '❌ فیلد ناشناخته است! 😔');
            return;
        }

        $current = match ($field) {
            'volume'   => (string) $this->settings->get(Settings::TEST_CONFIG_VOLUME_GB, '1'),
            'days'     => (string) $this->settings->int(Settings::TEST_CONFIG_DAYS, 1),
            'max'      => (string) $this->settings->int(Settings::TEST_CONFIG_MAX, 2),
            default    => (string) $this->settings->int(Settings::TEST_CONFIG_COOLDOWN, 30),
        };

        $hint = match ($field) {
            'volume'   => 'عدد اعشاری بین ۰٫۱ تا ۱۰ (گیگابایت). 💾',
            'days'     => 'عدد صحیح بین ۱ تا ۳۰ (روز). 📅',
            'max'      => 'عدد صحیح بین ۰ تا ۱۰ (۰ = نامحدود ♾️). 👥',
            default    => 'عدد صحیح بین ۰ تا ۱۴۴۰ (دقیقه). ⏳',
        };

        $this->bot->sendMessage($chatId, implode("\n", [
            '✏️🧪 <b>' . $labels[$field] . '</b>',
            '',
            '📌 مقدار فعلی: <b>' . Str::escape($current) . '</b>',
            '💡 ' . $hint,
            '',
            'مقدار جدید را بفرستید! 📝👇',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.testconfig'), '🔙 بازگشت 🔙'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_testconfig', 'field' => $field]);
    }

    public function saveTestConfig(int $chatId, string $field, string $text): void
    {
        $labels = self::testConfigFields();

        if (!isset($labels[$field])) {
            $this->bot->sendMessage($chatId, '❌ فیلد ناشناخته است! 😔');
            return;
        }

        $normalized = str_replace([',', '٬', ' '], '', Str::toEnglishDigits(trim($text)));

        if (!is_numeric($normalized)) {
            $this->bot->sendMessage($chatId, '⚠️📝 فقط عدد بفرستید! (مثلاً <code>2</code>) 😔');
            return;
        }

        $valid = true;

        switch ($field) {
            case 'volume':
                $value = (float) $normalized;
                $valid = $value >= 0.1 && $value <= 10;

                if ($valid) {
                    $this->settings->set(Settings::TEST_CONFIG_VOLUME_GB, (string) $value);
                }
                break;

            case 'days':
                $value = (int) $normalized;
                $valid = $value >= 1 && $value <= 30;

                if ($valid) {
                    $this->settings->set(Settings::TEST_CONFIG_DAYS, (string) $value);
                }
                break;

            case 'max':
                $value = (int) $normalized;
                $valid = $value >= 0 && $value <= 10;

                if ($valid) {
                    $this->settings->set(Settings::TEST_CONFIG_MAX, (string) $value);
                }
                break;

            default:
                $value = (int) $normalized;
                $valid = $value >= 0 && $value <= 1440;

                if ($valid) {
                    $this->settings->set(Settings::TEST_CONFIG_COOLDOWN, (string) $value);
                }
                break;
        }

        if (!$valid) {
            $this->bot->sendMessage($chatId, '⚠️📏 مقدار خارج از بازهٔ مجاز است! 😔 دوباره تلاش کنید. 🔄');
            return;
        }

        $this->bot->sendMessage($chatId, '✅🎉 ذخیره شد!');
        $this->showTestConfig($chatId);
    }

    private function showTestConfigPanels(int $chatId): void
    {
        $panels  = $this->panels->listAll(8);
        $current = $this->settings->int(Settings::TEST_CONFIG_PANEL_ID, 0);

        $lines = [
            '🖥️✨ <b>پنل تست کانفیگ 🌐</b>',
            '',
            'تست همهٔ کاربران روی کدام پنل ساخته شود؟ 👇',
            '',
            'فعلاً: <b>' . ($current > 0 ? 'پنل #' . Str::faNumber($current) : 'پنل خود کاربر ✅') . '</b>',
        ];

        $keyboard = [];

        $keyboard[] = [[
            'text' => ($current === 0 ? '✅ ' : '') . '👤 پنل خود کاربر',
            'data' => BotApi::encodeData('admin.testconfig.panel.set', ['id' => 0]),
        ]];

        foreach ($panels as $panel) {
            $id = (int) $panel['id'];

            $keyboard[] = [[
                'text' => ($current === $id ? '✅ ' : '🖥️ ') . Str::truncate((string) $panel['panel_username'], 24),
                'data' => BotApi::encodeData('admin.testconfig.panel.set', ['id' => $id]),
            ]];
        }

        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.testconfig'), '🔙 بازگشت 🔙');
        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function setTestConfigPanel(int $chatId, int $panelId): void
    {
        if ($panelId > 0 && $this->panels->find($panelId) === null) {
            $this->bot->sendMessage($chatId, '❌😔 این پنل پیدا نشد! 🔍');
            return;
        }

        $this->settings->set(Settings::TEST_CONFIG_PANEL_ID, (string) max(0, $panelId));
        $this->bot->sendMessage($chatId, '✅🖥️ پنل تست ذخیره شد! 🎉');
        $this->showTestConfig($chatId);
    }

    /**
     * صفحهٔ مدیریت درگاه‌های پرداخت.
     */
    private function showGateways(int $chatId): void
    {
        $states = $this->payments->gatewayStates();
        $flags  = $this->flags();

        $lines = ['💳 <b>درگاه‌های پرداخت</b>', ''];
        $keyboard = [];

        foreach ($states as $name => $state) {
            $gateway = $state['gateway'];
            $enabled = $state['enabled'];
            $isOn    = $enabled && $state['configured'];

            $lines[] = ($isOn ? '🟢' : '🔴') . ' <b>' . Str::escape($gateway->title()) . '</b> ✨';

            if ($name === 'card2card') {
                $card = Config::str('store.card_number');
                $lines[] = '   🏦 شماره کارت: ' . ($card !== '' ? '<code>' . Str::escape($card) . '</code> ✅' : '<i>⚠️ تنظیم نشده</i>');
            } elseif ($name === 'autocard') {
                $card = \Pasargad\Payment\AutoCardGateway::cardNumber();
                $api  = trim(Config::str('autocard.api_url', ''));
                $lines[] = '   🏦 شماره کارت: ' . ($card !== '' ? '<code>' . Str::escape($card) . '</code> ✅' : '<i>⚠️ تنظیم نشده</i>');
                $lines[] = '   🔌 استعلام: ' . ($api !== '' ? '<code>' . Str::escape(Str::truncate($api, 40)) . '</code> ✅' : '<i>⚠️ api_url تنظیم نشده</i>');
                $lines[] = '   💡 مبالغ یکتا + تأیید خودکار، بدون رسید دستی 🤖';
            } else {
                $lines[] = '   🔑 کلید API: ' . ($state['configured'] ? '<code>••••••</code> ✅' : '<i>⚠️ تنظیم نشده</i>');
            }

            $lines[] = '   📊 وضعیت: ' . ($enabled ? 'فعال ✅' : 'غیرفعال 🔴')
                . ($state['configured'] ? '' : ' — ⚠️ تا وقتی در config.php پیکربندی نشود کار نمی‌کند! 📝');
            $lines[] = '';

            $keyboard[] = [[
                'text' => ($enabled ? '🔴 خاموش کردن' : '🟢 روشن کردن') . ' ' . $gateway->title(),
                'data' => BotApi::encodeData('admin.gateway.toggle', ['name' => $name]),
            ]];
        }

        $active = 0;
        foreach ($states as $state) {
            if ($state['enabled'] && $state['configured']) {
                $active++;
            }
        }

        $lines[] = $active > 0
            ? '✅ <b>' . Str::faNumber($active) . ' درگاه آمادهٔ پذیرش پرداخت است.</b>'
            : '⚠️ <b>هیچ درگاه فعالی وجود ندارد!</b> کاربران نمی‌توانند پرداخت کنند.';

        $keyboard[] = Keyboard::back('admin.settings', '⚙️ بازگشت به تنظیمات');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * روشن/خاموش کردن یک درگاه پرداخت.
     */
    private function toggleGateway(int $chatId, string $name): void
    {
        if (!$this->flags()->setGatewayEnabled($name, !$this->flags()->isGatewayEnabled($name))) {
            $this->bot->sendMessage($chatId, '❌ درگاه ناشناخته است.');
            return;
        }

        $state = $this->flags()->isGatewayEnabled($name);

        // اگر آخرین درگاه فعال خاموش شد، به کاربران هشدار می‌دهیم.
        if (!$state) {
            $stillAvailable = false;
            foreach ($this->payments->gatewayStates() as $key => $info) {
                if ($key !== $name && $info['enabled'] && $info['configured']) {
                    $stillAvailable = true;
                    break;
                }
            }

            if (!$stillAvailable) {
                $this->bot->sendMessage($chatId, implode("\n", [
                    '⚠️ <b>هشدار:</b> با خاموش کردن این درگاه، هیچ روش پرداختی فعال نماند.',
                    '',
                    'کاربرانی که سفارش ثبت کرده‌اند نمی‌توانند پرداخت کنند.',
                    'برای بازگرداندن، همین دکمه را دوباره بزنید.',
                ]));
            }
        }

        $this->showGateways($chatId);
    }

    /**
     * تغییر وضعیت یک کلید دوحالته.
     */
    private function toggleFlag(int $chatId, string $key): void
    {
        $new = $this->flags()->toggle($key);

        if ($new === null) {
            $this->bot->sendMessage($chatId, '❌ کلید تنظیم نامعتبر است.');
            return;
        }

        // خاموش کردن کل ربات: هشدار مهم چون همهٔ کاربران را از دسترس خارج می‌کند.
        if ($key === Settings::BOT_ENABLED && $new === false) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '🔴 <b>ربات غیرفعال شد.</b>',
                '',
                'کاربران عادی دیگر نمی‌توانند از ربات استفاده کنند و پیام تعیین‌شده را می‌بینند.',
                'سوپرADMین‌ها همچنان دسترسی دارند تا بتوانند ربات را دوباره روشن کنند.',
            ]));

            $this->showSettings($chatId);
            return;
        }

        $this->showSettings($chatId);
    }

    /**
     * شروع ویرایش متن غیرفعالی ربات.
     */
    public function startEditNotice(int $chatId, int $adminId): void
    {
        $current = $this->flags()->disabledNotice();

        $this->bot->sendMessage($chatId, implode("\n", [
            '✏️✨ <b>متن غیرفعالی ربات 🔴</b>',
            '',
            'این متن به کاربرانی نمایش داده می‌شود که وقتی ربات خاموش است پیام می‌دهند! 😴',
            '',
            '📌 <b>متن فعلی:</b>',
            $current,
            '',
            '────────────────────',
            '📝 متن جدید را بفرستید! برای بازگردانی متن پیش‌فرض «پیش‌فرض» را بنویسید. ♻️',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '♻️ بازگردانی متن پیش‌فرض', 'data' => BotApi::encodeData('admin.notice.reset')]],
                Keyboard::back('admin.settings', '❌ انصراف'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_notice']);
    }

    /**
     * ذخیرهٔ متن غیرفعالی که ادمین فرستاده است.
     */
    public function saveNotice(int $chatId, string $text): void
    {
        $text = trim($text);

        if (mb_strlen($text) > 4000) {
            $this->bot->sendMessage($chatId, '⚠️ متن بیش از حد طولانی است (حداکثر ۴۰۰۰ کاراکتر).');
            return;
        }

        if ($text === '' || $text === 'پیش‌فرض') {
            $this->flags()->resetDisabledNotice();
            $this->bot->sendMessage($chatId, '♻️ متن پیش‌فرض بازگردانی شد.');
        } else {
            $this->flags()->setDisabledNotice($text);
            $this->bot->sendMessage($chatId, '✅ متن غیرفعالی ذخیره شد.');
        }

        $this->showSettings($chatId);
    }

    // ------------------------------------------------------------------
    // 📣 پیام همگانی به تمام کاربران
    // ------------------------------------------------------------------

    public function startBroadcast(int $chatId, int $adminTelegramId): void
    {
        $count = $this->users->countAll();

        $this->bot->sendMessage($chatId, implode("\n", [
            '📣✨ <b>پیام همگانی به تمام کاربران 👥</b>',
            '',
            '👥 تعداد کاربران ربات: <b>' . Str::faNumber($count) . '</b> نفر',
            '',
            '📝 متن پیام را بفرستید (از HTML پشتیبانی می‌شود).',
            '⚠️ پیام به <b>همهٔ</b> کاربران (نه فقط ۵۰۰ نفر) ارسال می‌شود.',
            '',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.home'), '🔙 بازگشت 🛠'),
            ])),
        ]);

        if ($adminTelegramId > 0) {
            $this->sessions->set($adminTelegramId, ['step' => 'admin_broadcast']);
        }
    }

    /**
     * پیش‌نمایش پیام همگانی قبل از ارسال نهایی.
     */
    public function saveBroadcastDraft(int $chatId, int $adminTelegramId, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            $this->bot->sendMessage($chatId, '⚠️ متن خالی است.');
            return;
        }

        $this->sessions->set($adminTelegramId, [
            'step' => 'admin_broadcast_confirm',
            'text' => $text,
        ]);

        $count = $this->users->countAll();

        $this->bot->sendMessage($chatId, implode("\n", [
            '👀 <b>پیش‌نمایش پیام همگانی</b>',
            '',
            '👥 گیرندگان: <b>' . Str::faNumber($count) . '</b> کاربر',
            '',
            '────────────────────',
            $text,
            '────────────────────',
            '',
            'ارسال شود؟',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '✅📣 بله، ارسال به همه', 'data' => BotApi::encodeData('admin.broadcast.send')]],
                Keyboard::back(BotApi::encodeData('admin.home'), '❌ انصراف'),
            ])),
        ]);
    }

    public function doBroadcast(int $chatId): void
    {
        // متن از نشست خوانده می‌شود چون callback_data فقط ۶۴ بایت است
        $draft = '';
        try {
            // نشست ادمین جاری را پیدا می‌کنیم — chatId همان telegramId ادمین است
            $state = $this->sessions->get($chatId);
            $draft = (string) ($state['text'] ?? '');
        } catch (\Throwable $e) {
        }

        if (trim($draft) === '') {
            $this->bot->sendMessage($chatId, '⚠️ متنی برای ارسال نیست. دوباره تلاش کنید.', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.home'), '🔙 بازگشت 🛠'),
                ])),
            ]);
            return;
        }

        $this->sessions->clear($chatId);
        $this->doBroadcastToAll($chatId, $draft);
    }

    private function doBroadcastToAll(int $chatId, string $text): void
    {
        $this->bot->sendMessage($chatId, '⏳📣 در حال ارسال پیام همگانی...');

        $sent = 0;
        $failed = 0;
        $offset = 0;
        $perPage = 500;

        // صفحه‌به‌صفحه همهٔ کاربران — نه فقط ۵۰۰ نفر اول
        while (true) {
            $users = $this->users->listAll($perPage, $offset);

            if ($users === []) {
                break;
            }

            $ids = [];
            foreach ($users as $u) {
                $tid = (int) ($u['telegram_id'] ?? 0);
                if ($tid > 0 && (int) ($u['is_blocked'] ?? 0) !== 1) {
                    $ids[] = $tid;
                }
            }

            if ($ids !== []) {
                $result = $this->notifier->broadcast($ids, $text);
                $sent += (int) $result['sent'];
                $failed += (int) $result['failed'];
            }

            $offset += $perPage;

            if (count($users) < $perPage) {
                break;
            }
        }

        $this->bot->sendMessage($chatId, sprintf(
            "📣✨ <b>پیام همگانی ارسال شد! 🎉</b>\n\n✅ موفق: <b>%s</b> 📩\n❌ ناموفق: <b>%s</b> 📭",
            Str::faNumber($sent),
            Str::faNumber($failed)
        ), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.home'), '🔙 بازگشت به پنل مدیریت 🛠'),
            ])),
        ]);
    }

    // ------------------------------------------------------------------
    // ✏️ متن‌های ثابت (قوانین، خوش‌آمد، پشتیبانی، راهنما، ...)
    // ------------------------------------------------------------------

    /**
     * @return array<string, string> کلید تنظیمات => برچسب فارسی
     */
    public static function editableTexts(): array
    {
        return [
            Settings::RULES_TEXT   => '📜 قوانین و شرایط ⚖️',
            Settings::WELCOME_TEXT => '👋✨ پیام خوش‌آمد 🎉',
            Settings::SUPPORT_TEXT => '📞 پشتیبانی 💬',
            Settings::HELP_TEXT    => 'ℹ️ راهنما 📖',
            Settings::BOT_DISABLED_NOTICE => '⛔️ متن غیرفعالی ربات 🔴',
        ];
    }

    private function showTexts(int $chatId): void
    {
        $lines = ['✏️✨ <b>متن‌های ثابت ربات 📝</b>', '', 'کدام متن را ویرایش می‌کنید؟ 👇'];
        $keyboard = [];

        foreach (self::editableTexts() as $key => $label) {
            $current = $key === Settings::BOT_DISABLED_NOTICE
                ? $this->flags->disabledNotice()
                : trim((string) $this->settings->get($key, ''));

            $status = $current !== '' ? '✅' : '⚪️';
            $lines[] = '';
            $lines[] = $status . ' ' . $label . ': ' . ($current !== ''
                ? Str::escape(Str::truncate(strip_tags($current), 60))
                : '<i>پیش‌فرض</i>');

            $keyboard[] = [[
                'text' => '✏️ ' . $label,
                'data' => BotApi::encodeData('admin.text.edit', ['key' => $key]),
            ]];
        }

        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.settings'), '⚙️ بازگشت به تنظیمات 🔙');
        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    public function startEditText(int $chatId, int $adminId, string $key): void
    {
        $labels = self::editableTexts();

        if (!isset($labels[$key])) {
            $this->bot->sendMessage($chatId, '❌ متن ناشناخته است.');
            return;
        }

        $current = $key === Settings::BOT_DISABLED_NOTICE
            ? $this->flags->disabledNotice()
            : trim((string) $this->settings->get($key, ''));

        $this->bot->sendMessage($chatId, implode("\n", [
            '✏️ <b>' . $labels[$key] . '</b>',
            '',
            '📌 <b>متن فعلی:</b>',
            $current === '' ? '<i>متن پیش‌فرض فعال است</i>' : $current,
            '',
            '────────────────────',
            $key === Settings::WELCOME_TEXT
                ? '💡 می‌توانید از <code>{name}</code> برای نام کاربر استفاده کنید.'
                : '💡 متن از HTML پشتیبانی می‌کند.',
            'متن جدید را بفرستید. برای بازگردانی پیش‌فرض «پیش‌فرض» را بنویسید. ♻️',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.texts'), '🔙 بازگشت به متن‌ها'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_text', 'key' => $key]);
    }

    public function saveText(int $chatId, string $key, string $text): void
    {
        $labels = self::editableTexts();

        if (!isset($labels[$key])) {
            $this->bot->sendMessage($chatId, '❌ متن ناشناخته است.');
            return;
        }

        $text = trim($text);

        if (mb_strlen($text) > 4000) {
            $this->bot->sendMessage($chatId, '⚠️ متن بیش از حد طولانی است (حداکثر ۴۰۰۰ کاراکتر).');
            return;
        }

        if ($text === '' || $text === 'پیش‌فرض') {
            if ($key === Settings::BOT_DISABLED_NOTICE) {
                $this->flags->resetDisabledNotice();
            } else {
                $this->settings->set($key, '');
            }
            $this->bot->sendMessage($chatId, '♻️ متن پیش‌فرض بازگردانی شد. ✅');
        } else {
            if ($key === Settings::BOT_DISABLED_NOTICE) {
                $this->flags->setDisabledNotice($text);
            } else {
                $this->settings->set($key, $text);
            }
            $this->bot->sendMessage($chatId, '✅ متن ذخیره شد! 🎉');
        }

        $this->showTexts($chatId);
    }

    // ------------------------------------------------------------------
    // 💾 بکاپ‌گیری (همون لحظه / روزانه / دو بار در روز)
    // ------------------------------------------------------------------

    private function showBackup(int $chatId): void
    {
        $schedule = (string) $this->settings->get(Settings::BACKUP_SCHEDULE, 'off');
        $lastAt = (int) $this->settings->get(Settings::BACKUP_LAST_AT, '0');

        $scheduleLabel = match ($schedule) {
            'daily' => '📅 روزانه (۱ بار در روز) ✅',
            'twice' => '📅📅 دو بار در روز ✅',
            default => '🔴 خاموش',
        };

        $keep  = max(2, $this->settings->int(Settings::BACKUP_KEEP, \Pasargad\Support\Backup::KEEP_FILES));
        $files = \Pasargad\Support\Backup::list();

        $lines = [
            '💾✨ <b>سیستم بکاپ‌گیری 📦</b>',
            '',
            '⏰ زمان‌بندی خودکار: <b>' . $scheduleLabel . '</b>',
            '🕒 آخرین بکاپ: <b>' . ($lastAt > 0 ? Str::date($lastAt) : 'هرگز') . '</b>',
            '📚 نسخه‌های نگه‌داشته‌شده: <b>' . Str::faNumber(count($files)) . '</b> از سقف '
                . Str::faNumber($keep),
            '',
            '💡 بکاپ خودکار توسط کرون (worker) انجام می‌شود؛',
            'کافی است کرون هر چند دقیقه اجرا شود.',
            '',
            '⚠️ برای تغییرات پرریسک (مایگریشن جدید، تغییر کد)،',
            'از خط فرمان یک بکاپ بگیرید:',
            '<code>php tools/cli.php backup</code>',
            '',
            '👇 یکی را انتخاب کنید:',
        ];

        $keyboard = Keyboard::rows([
            [['text' => '⚡️📦 بکاپ همون لحظه', 'data' => BotApi::encodeData('admin.backup.now')]],
            [
                ['text' => ($schedule === 'daily' ? '✅ ' : '') . '📅 روزانه ۱ بار', 'data' => BotApi::encodeData('admin.backup.schedule', ['mode' => 'daily'])],
                ['text' => ($schedule === 'twice' ? '✅ ' : '') . '📅📅 روزانه ۲ بار', 'data' => BotApi::encodeData('admin.backup.schedule', ['mode' => 'twice'])],
            ],
            [['text' => ($schedule === 'off' ? '✅ ' : '') . '🔴 خاموش کردن خودکار', 'data' => BotApi::encodeData('admin.backup.schedule', ['mode' => 'off'])]],
            [
                ['text' => '🗑 نگه‌داشت: ۷', 'data' => BotApi::encodeData('admin.backup.keep', ['n' => 7])],
                ['text' => '🗑 نگه‌داشت: ۱۴', 'data' => BotApi::encodeData('admin.backup.keep', ['n' => 14])],
                ['text' => '🗑 نگه‌داشت: ۳۰', 'data' => BotApi::encodeData('admin.backup.keep', ['n' => 30])],
            ],
            Keyboard::back(BotApi::encodeData('admin.home'), '🔙 بازگشت به پنل مدیریت 🛠'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function doBackupNow(int $chatId): void
    {
        $this->bot->sendMessage($chatId, '⏳💾 در حال ساخت بکاپ...');

        $result = \Pasargad\Support\Backup::run();

        if (!($result['ok'] ?? false)) {
            $this->bot->sendMessage($chatId, '❌ ' . Str::escape((string) $result['message']), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.backup'), '🔙 بازگشت به بکاپ 💾'),
                ])),
            ]);
            return;
        }

        $path = (string) ($result['path'] ?? '');
        $size = (int) ($result['size'] ?? 0);

        $send = \Pasargad\Support\Backup::sendTo($chatId, $path, $this->bot);

        $this->bot->sendMessage($chatId, implode("\n", [
            '✅💾 <b>بکاپ آماده شد! 🎉</b>',
            '',
            '📁 فایل: <code>' . Str::escape(basename($path)) . '</code>',
            '📦 حجم: <b>' . Str::formatBytes($size) . '</b>',
            ($send['ok'] ?? false) ? '📩 فایل بکاپ در پیام بعدی ارسال شد. ✅' : '⚠️ ارسال فایل ناموفق بود: ' . Str::escape((string) $send['message']),
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.backup'), '🔙 بازگشت به بکاپ 💾'),
                Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت'),
            ])),
        ]);
    }

    private function setBackupSchedule(int $chatId, string $mode): void
    {
        if (!in_array($mode, ['off', 'daily', 'twice'], true)) {
            $mode = 'off';
        }

        $this->settings->set(Settings::BACKUP_SCHEDULE, $mode);

        $label = match ($mode) {
            'daily' => 'روزانه ۱ بار 📅',
            'twice' => 'روزانه ۲ بار 📅📅',
            default => 'خاموش 🔴',
        };

        $this->bot->sendMessage($chatId, '✅ زمان‌بندی بکاپ: <b>' . $label . '</b>');
        $this->showBackup($chatId);
    }

    // ------------------------------------------------------------------
    // 💰 کیف پول (شارژ توسط ادمین)
    // ------------------------------------------------------------------

    public function startWalletAdd(int $chatId, int $adminTelegramId, int $userDbId): void
    {
        $user = $this->users->findById($userDbId);

        if ($user === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $balance = 0;
        try {
            $balance = $this->users->walletBalance($userDbId);
        } catch (\Throwable $e) {
            $balance = (int) ($user['wallet_balance'] ?? 0);
        }

        $this->bot->sendMessage($chatId, implode("\n", [
            '💰✨ <b>کیف پول نماینده 👛</b>',
            '',
            '👤 ' . Str::escape((string) ($user['first_name'] ?? $user['username'] ?? '')) . ' • <code>' . (int) $user['telegram_id'] . '</code>',
            '💵 موجودی فعلی: <b>' . Str::formatToman($balance) . '</b>',
            '',
            '💡 مبلغ را به تومان بفرستید (مثلاً <code>100000</code>).',
            '➖ برای کسر، عدد منفی بفرستید (مثلاً <code>-50000</code>).',
            '',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.user.view', ['id' => $userDbId]), '🔙 بازگشت به نماینده 👤'),
            ])),
        ]);

        $this->sessions->set($adminTelegramId, ['step' => 'admin_wallet', 'user_id' => $userDbId]);
    }

    public function saveWallet(int $chatId, int $adminTelegramId, int $userDbId, string $text): void
    {
        $user = $this->users->findById($userDbId);

        if ($user === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $normalized = Str::toEnglishDigits(trim($text));
        $normalized = str_replace([',', '٬', ' '], '', $normalized);

        if (!preg_match('/^-?\d+$/', $normalized)) {
            $this->bot->sendMessage($chatId, '⚠️ مبلغ نامعتبر است. فقط عدد بفرستید (مثلاً 100000 یا -50000).');
            return;
        }

        $amount = (int) $normalized;

        if ($amount === 0 || abs($amount) > 1000000000) {
            $this->bot->sendMessage($chatId, '⚠️ مبلغ باید بین ۱ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.');
            return;
        }

        try {
            $result = $this->users->adjustWallet($userDbId, $amount, '', $adminTelegramId);
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, '❌ خطا: ' . Str::escape($e->getMessage()));
            return;
        }

        $this->bot->sendMessage($chatId, implode("\n", [
            '✅💰 <b>کیف پول به‌روزرسانی شد! 🎉</b>',
            '',
            '💵 مبلغ: <b>' . Str::formatToman($amount) . '</b>',
            '👛 موجودی جدید: <b>' . Str::formatToman((int) $result['balance']) . '</b>',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.user.view', ['id' => $userDbId]), '🔙 بازگشت به نماینده 👤'),
            ])),
        ]);

        // اطلاع به کاربر 💌
        $this->notifier->notifyUser((int) $user['telegram_id'], implode("\n", [
            '💰✨ <b>کیف پول شما به‌روزرسانی شد! 👛🎉</b>',
            '',
            ($amount > 0 ? '🟢➕ شارژ: ' : '🔴➖ کسر: ') . '<b>' . Str::formatToman($amount) . '</b>',
            '👛 موجودی جدید: <b>' . Str::formatToman((int) $result['balance']) . '</b> 🪙',
        ]));

        $this->showUser($chatId, $userDbId);
    }

    // ------------------------------------------------------------------
    // 🎟️ کدهای تخفیف
    // ------------------------------------------------------------------

    private function coupons(): CouponRepository
    {
        return new CouponRepository($this->orders->db());
    }

    /**
     * فهرست کدهای تخفیف.
     */
    private function showCoupons(int $chatId): void
    {
        $coupons = $this->coupons();
        $all     = $coupons->listAll(30);

        $lines = [
            '🎟️✨ <b>مدیریت کدهای تخفیف</b>',
            '',
            '📦 کل کدها: <b>' . Str::faNumber($coupons->countAll()) . '</b>'
                . ' • 🟢 فعال: <b>' . Str::faNumber($coupons->countActive()) . '</b>',
            '',
        ];

        if ($all === []) {
            $lines[] = '😔 هنوز کدی ساخته نشده است.';
        }

        $keyboard = [];

        foreach ($all as $coupon) {
            $active = (int) $coupon['is_active'] === 1;
            $expired = $coupon['expires_at'] !== null && (int) $coupon['expires_at'] <= time();

            $lines[] = ($active && !$expired ? '🟢' : '🔴') . ' <code>'
                . Str::escape((string) $coupon['code']) . '</code>';
            $lines[] = '   🎁 ' . self::couponValueLabel($coupon);
            $lines[] = '   👥 استفاده: ' . Str::faNumber((int) $coupon['used_count'])
                . ($coupon['max_uses'] !== null && (int) $coupon['max_uses'] > 0
                    ? ' از ' . Str::faNumber((int) $coupon['max_uses']) : '')
                . ($expired ? ' • ⌛️ منقضی' : '');

            $keyboard[] = [[
                'text' => ($active ? '🔴 خاموش ' : '🟢 روشن ') . Str::truncate((string) $coupon['code'], 16),
                'data' => BotApi::encodeData('admin.coupon.toggle', ['id' => (int) $coupon['id']]),
            ]];
        }

        $keyboard[] = [['text' => '➕ کد تخفیف جدید', 'data' => BotApi::encodeData('admin.coupon.new')]];
        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    /**
     * @param array<string, mixed> $coupon
     */
    private static function couponValueLabel(array $coupon): string
    {
        $value = (int) $coupon['value'];

        return (string) $coupon['kind'] === CouponRepository::KIND_FIXED
            ? Str::formatToman($value) . ' تخفیف نقدی'
            : Str::faNumber($value) . '٪ تخفیف';
    }

    /**
     * شروع ساخت کد تخفیف (ورودی متنی، مثل ساخت بسته).
     */
    public function startCreateCoupon(int $chatId, int $adminId): void
    {
        $this->bot->sendMessage($chatId, implode("\n", [
            '➕🎟️ <b>ساخت کد تخفیف جدید</b>',
            '',
            '📝 این فرمت را بفرستید:',
            '<code>کد | نوع | مقدار | حداکثر_استفاده | سقف_تخفیف | توضیح</code>',
            '',
            '🔸 <b>نوع</b>: <code>percent</code> یا <code>fixed</code>',
            '🔸 <b>مقدار</b>: درصد (۱ تا ۱۰۰) یا مبلغ تومان',
            '🔸 <b>حداکثر_استفاده</b>: عدد صحیح — ۰ یعنی نامحدود ♾️',
            '🔸 <b>سقف_تخفیف</b>: عدد — ۰ یعنی بدون سقف',
            '🔸 <b>توضیح</b>: اختیاری',
            '',
            'مثال:',
            '<code>SUMMER | percent | 20 | 100 | 200000 | کمپین تابستان</code>',
            '<code>OFF500 | fixed | 50000 | 0 | 0 | تخفیف ثابت</code>',
            '',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.coupons'), '🔙 بازگشت 🔙'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_coupon']);
    }

    /**
     * ثبت کد تخفیف از متن ادمین.
     */
    public function saveCoupon(int $chatId, string $text): void
    {
        $parts = array_map('trim', explode('|', $text));

        if (count($parts) < 3) {
            $this->bot->sendMessage($chatId, '⚠️ فرمت کامل نیست. حداقل سه بخش لازم است: کد | نوع | مقدار');
            return;
        }

        $code = $parts[0];
        $kind = strtolower($parts[1]) === 'fixed'
            ? CouponRepository::KIND_FIXED
            : CouponRepository::KIND_PERCENT;

        $digits = static fn (string $s): int => (int) preg_replace('/\D/', '', Str::toEnglishDigits($s));

        $value   = $digits($parts[2]);
        $maxUses = isset($parts[3]) ? $digits($parts[3]) : 0;
        $cap     = isset($parts[4]) ? $digits($parts[4]) : 0;
        $note    = trim($parts[5] ?? '');

        if (!preg_match('/^[A-Za-z0-9_\-]{3,32}$/', $code)) {
            $this->bot->sendMessage($chatId, '⚠️ کد نامعتبر است. فقط حروف انگلیسی، عدد، _ و - (۳ تا ۳۲ کاراکتر).');
            return;
        }

        if ($value <= 0) {
            $this->bot->sendMessage($chatId, '⚠️ مقدار باید بزرگ‌تر از صفر باشد.');
            return;
        }

        if ($kind === CouponRepository::KIND_PERCENT && $value > 100) {
            $this->bot->sendMessage($chatId, '⚠️ درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.');
            return;
        }

        $coupons = $this->coupons();

        if ($coupons->findByCode($code) !== null) {
            $this->bot->sendMessage($chatId, '⚠️ کد <code>' . Str::escape($code) . '</code> قبلاً وجود دارد.');
            return;
        }

        $id = $coupons->create([
            'code'       => $code,
            'kind'       => $kind,
            'value'      => $value,
            'max_uses'   => $maxUses,
            'per_user_limit' => 1,
            'max_discount_toman' => $cap,
            'note'       => $note !== '' ? $note : null,
            'is_active'  => 1,
        ]);

        $created = $coupons->find($id) ?? [];

        $this->bot->sendMessage($chatId, implode("\n", [
            '✅ کد تخفیف ساخته شد! 🎉',
            '',
            '🎟️ کد: <code>' . Str::escape((string) ($created['code'] ?? $code)) . '</code>',
            '🎁 ' . self::couponValueLabel($created),
            '👥 سقف استفاده: ' . ($maxUses > 0 ? Str::faNumber($maxUses) : 'نامحدود ♾️'),
        ]));

        $this->showCoupons($chatId);
    }

    private function toggleCoupon(int $chatId, int $couponId): void
    {
        $coupons = $this->coupons();
        $coupon  = $coupons->find($couponId);

        if ($coupon === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $new = (int) $coupon['is_active'] === 1 ? 0 : 1;
        $coupons->update($couponId, ['is_active' => $new]);

        $this->showCoupons($chatId);
    }

    private function deleteCoupon(int $chatId, int $couponId): void
    {
        $this->coupons()->delete($couponId);
        $this->bot->sendMessage($chatId, '🗑️ کد حذف شد.');
        $this->showCoupons($chatId);
    }

    // ------------------------------------------------------------------
    // 🎫 تیکت پشتیبانی
    // ------------------------------------------------------------------

    /**
     * صف تیکت‌های باز.
     */
    private function showTickets(int $chatId): void
    {
        $tickets = new TicketRepository($this->orders->db());
        $open    = $tickets->listOpen(20);

        $lines = [
            '🎫✨ <b>صف پشتیبانی</b>',
            '',
            '🕓 در انتظار پاسخ: <b>' . Str::faNumber($tickets->countOpen()) . '</b>',
            '',
        ];

        if ($open === []) {
            $lines[] = '🎉 تیکت بازی وجود ندارد!';
        }

        $keyboard = [];

        foreach ($open as $ticket) {
            $name = trim((string) ($ticket['first_name'] ?? '')) ?: ('کاربر ' . (int) $ticket['telegram_id']);

            $lines[] = TicketRepository::statusLabel($ticket) . ' #' . Str::faNumber((int) $ticket['id']);
            $lines[] = '   👤 ' . Str::escape($name)
                . ' • 📂 ' . TicketRepository::categoryLabel((string) $ticket['category']);
            $lines[] = '   💬 ' . Str::escape(Str::truncate((string) $ticket['subject'], 60));
            $lines[] = '   🕒 ' . Str::dateShort((int) $ticket['updated_at']);

            $keyboard[] = [[
                'text' => TicketRepository::statusLabel($ticket) . ' #' . Str::faNumber((int) $ticket['id']),
                'data' => BotApi::encodeData('admin.ticket.view', ['id' => (int) $ticket['id']]),
            ]];
        }

        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    /**
     * یک تیکت + دکمهٔ پاسخ.
     */
    private function showTicket(int $chatId, int $ticketId): void
    {
        $tickets = new TicketRepository($this->orders->db());
        $ticket  = $tickets->find($ticketId);

        if ($ticket === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $owner = $this->users->findById((int) $ticket['user_id']);

        $header = implode("\n", [
            '👤 کاربر: <code>' . (int) ($owner['telegram_id'] ?? 0) . '</code>'
                . (($owner['first_name'] ?? '') !== '' ? ' — ' . Str::escape((string) $owner['first_name']) : ''),
            '🖥 پنل‌های او: <b>' . Str::faNumber($this->panels->countByUser((int) $ticket['user_id'])) . '</b>',
        ]);

        $rows = [[
            ['text' => '💬 پاسخ دادن', 'data' => BotApi::encodeData('admin.ticket.reply', ['id' => $ticketId])],
        ]];

        if ((string) $ticket['status'] !== TicketRepository::STATUS_CLOSED) {
            $rows[] = [[
                'text' => '🔒 بستن تیکت',
                'data' => BotApi::encodeData('admin.ticket.close', ['id' => $ticketId]),
            ]];
        }

        $rows[] = Keyboard::back(BotApi::encodeData('admin.tickets'), '🎫 صف پشتیبانی');

        $this->bot->sendMessage($chatId, $header . "\n\n" . $this->supportThreadText($tickets, $ticket), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($rows)),
        ]);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function supportThreadText(TicketRepository $tickets, array $ticket): string
    {
        $lines = [
            '🎫 <b>تیکت #' . Str::faNumber((int) $ticket['id']) . '</b>',
            '📶 وضعیت: ' . TicketRepository::statusLabel($ticket),
            '📅 ثبت: ' . Str::date((int) $ticket['created_at']),
            '',
        ];

        foreach ($tickets->messages((int) $ticket['id'], 40) as $message) {
            $who = (string) $message['from_side'] === 'admin' ? '🛠 شما' : '👤 کاربر';

            $lines[] = $who . ' — ' . Str::dateShort((int) $message['created_at']);
            $lines[] = Str::escape(Str::truncate((string) $message['body'], 700));
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function startTicketReply(int $chatId, int $adminId, int $ticketId): void
    {
        $tickets = new TicketRepository($this->orders->db());

        if ($tickets->find($ticketId) === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->bot->sendMessage($chatId, '💬 پاسخ خود را برای تیکت #' . Str::faNumber($ticketId) . " بنویسید:\n"
            . 'برای لغو /cancel را بزنید. ❌', [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.ticket.view', ['id' => $ticketId]), '🔙 بازگشت 🔙'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_ticket_reply', 'ticket_id' => $ticketId]);
    }

    public function saveTicketReply(int $chatId, int $ticketId, string $text): void
    {
        $tickets = new TicketRepository($this->orders->db());
        $reply   = trim($text);

        if ($reply === '') {
            $this->bot->sendMessage($chatId, '⚠️ متن پاسخ خالی است.');
            return;
        }

        if ($ticketId <= 0 || $tickets->find($ticketId) === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $ticket = $tickets->find($ticketId);
        $tickets->replyAsAdmin($ticketId, $reply);

        // پاسخ باید به خود کاربر هم برسد، وگرنه تیکت بی‌پاسخ می‌ماند و
        // کاربر فکر می‌کند مدیر جواب نداده است.
        $owner = $this->users->findById((int) $ticket['user_id']);

        if ($owner !== null && (int) $owner['telegram_id'] > 0) {
            $this->notifier->notifyUser((int) $owner['telegram_id'], implode("\n", [
                '💌✨ <b>پاسخ پشتیبانی برای شما آمد!</b>',
                '',
                '🎫 تیکت: <code>#' . Str::faNumber($ticketId) . '</code>',
                '',
                '💬 ' . Str::escape(Str::truncate($reply, 900)),
            ]));
        }

        $this->showTicket($chatId, $ticketId);
    }

    private function closeTicket(int $chatId, int $ticketId): void
    {
        $tickets = new TicketRepository($this->orders->db());
        $tickets->close($ticketId);

        $this->bot->sendMessage($chatId, '🔒 تیکت بسته شد.');
        $this->showTicket($chatId, $ticketId);
    }

    // ------------------------------------------------------------------
    // ⏳ مهلت ارفاقی و پاداش معرفی
    // ------------------------------------------------------------------

    /**
     * فیلدهای عددی قابل تنظیم از پنل.
     *
     * @return array<string, array{label:string, key:string, min:int, max:int, hint:string}>
     */
    private static function growthFields(): array
    {
        return [
            'grace' => [
                'label' => '⏳ مهلت ارفاقی انقضا (روز)',
                'key'   => Settings::EXPIRE_GRACE_DAYS,
                'min'   => 0,
                'max'   => 60,
                'hint'  => 'چند روز بعد از انقضا فرصت تمدید داده شود. ۰ = بدون مهلت (رفتار قدیمی).',
            ],
            'ref_percent' => [
                'label' => '🎁 تخفیف معرفی (درصد)',
                'key'   => Settings::REFERRAL_DISCOUNT,
                'min'   => 0,
                'max'   => 100,
                'hint'  => 'تخفیفی که کاربرِ معرفی‌شده در اولین خریدش می‌گیرد.',
            ],
            'ref_bonus' => [
                'label' => '💰 پاداش معرف (تومان)',
                'key'   => Settings::REFERRAL_BONUS,
                'min'   => 0,
                'max'   => 100000000,
                'hint'  => 'مبلغی که به کیف پول معرف اضافه می‌شود.',
            ],
            'stats_ttl' => [
                'label' => '🕒 عمر حافظهٔ آمار کاربران (دقیقه)',
                'key'   => Settings::PANEL_STATS_TTL,
                'min'   => 1,
                'max'   => 1440,
                'hint'  => 'چقدر طول بکشد تا آمار کاربران پنل دوباره از API خوانده شود.',
            ],
        ];
    }

    /**
     * صفحهٔ «🚀 رشد و نگهداشت» (مهلت ارفاقی، معرفی، آمار پنل).
     */
    private function showGrowth(int $chatId): void
    {
        $graceDays = max(0, $this->settings->int(Settings::EXPIRE_GRACE_DAYS, 3));

        $lines = [
            '🚀✨ <b>رشد و نگهداشت</b>',
            '',
            '⏳ مهلت ارفاقی انقضا: <b>' . Str::faNumber($graceDays) . ' روز</b>',
            $graceDays > 0
                ? '   <i>یعنی پس از انقضا، ' . Str::faNumber($graceDays)
                    . ' روز فرصت تمدید هست و بعد درخواست قطع دسترسی می‌رود.</i>'
                : '   <i>⚠️ بدون مهلت — به‌محض انقضا درخواست قطع دسترسی می‌رود.</i>',
            '',
            '🎁 تخفیف معرفی: <b>' . Str::faNumber(max(0, $this->settings->int(Settings::REFERRAL_DISCOUNT, 10)))
                . '٪</b>',
            '💰 پاداش معرف: <b>' . Str::formatToman(max(0, $this->settings->int(Settings::REFERRAL_BONUS, 50000)))
                . '</b>',
            '🕒 عمر حافظهٔ آمار پنل: <b>'
                . Str::faNumber(max(1, $this->settings->int(Settings::PANEL_STATS_TTL, 30))) . ' دقیقه</b>',
            '',
            '🎟️ کدهای تخفیف: <b>' . Str::faNumber($this->coupons()->countActive()) . '</b> فعال',
        ];

        $keyboard = [];

        foreach (self::growthFields() as $field => $meta) {
            $keyboard[] = [['text' => $meta['label'], 'data' => BotApi::encodeData('admin.grow.field', ['f' => $field])]];
        }

        $keyboard[] = [['text' => '🎟️ مدیریت کدهای تخفیف', 'data' => BotApi::encodeData('admin.coupons')]];
        $keyboard[] = Keyboard::back(BotApi::encodeData('admin.home'), '🛠 پنل مدیریت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
        ]);
    }

    public function startEditNumber(int $chatId, int $adminId, string $field): void
    {
        $fields = self::growthFields();

        if (!isset($fields[$field])) {
            $this->bot->sendMessage($chatId, '❌ فیلد ناشناخته است!');
            return;
        }

        $meta    = $fields[$field];
        $current = (string) $this->settings->get($meta['key'], '0');

        $this->bot->sendMessage($chatId, implode("\n", [
            '✏️ <b>' . $meta['label'] . '</b>',
            '',
            '📌 مقدار فعلی: <b>' . Str::escape($current) . '</b>',
            '💡 ' . $meta['hint'],
            '🔢 بازهٔ مجاز: ' . Str::faNumber($meta['min']) . ' تا ' . Str::faNumber($meta['max']),
            '',
            'مقدار جدید را بفرستید! 📝',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('admin.grow'), '🔙 بازگشت 🔙'),
            ])),
        ]);

        $this->sessions->set($adminId, ['step' => 'admin_number', 'field' => $field]);
    }

    public function saveNumber(int $chatId, string $field, string $text): void
    {
        $fields = self::growthFields();

        if (!isset($fields[$field])) {
            $this->bot->sendMessage($chatId, '❌ فیلد ناشناخته است!');
            return;
        }

        $meta   = $fields[$field];
        $digits = (int) preg_replace('/\D/', '', Str::toEnglishDigits(trim($text)));

        if ($digits < $meta['min'] || $digits > $meta['max']) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '⚠️📏 مقدار خارج از بازهٔ مجاز است! 😔',
                'مقدار باید بین ' . Str::faNumber($meta['min']) . ' و ' . Str::faNumber($meta['max']) . ' باشد.',
            ]));

            return;
        }

        $this->settings->set($meta['key'], (string) $digits);

        $this->bot->sendMessage($chatId, '✅ ذخیره شد: <b>' . $meta['label'] . ' = '
            . Str::faNumber($digits) . '</b>');

        $this->showGrowth($chatId);
    }

    // ------------------------------------------------------------------
    // 💾 سیاست نگهداری بکاپ
    // ------------------------------------------------------------------

    public function setBackupKeep(int $chatId, int $keep): void
    {
        $keep = max(2, min($keep, 200));

        $this->settings->set(Settings::BACKUP_KEEP, (string) $keep);

        $this->bot->sendMessage($chatId, '💾 تعداد نسخه‌های نگه‌داشته‌شده: <b>' . Str::faNumber($keep) . '</b>');
        $this->showBackup($chatId);
    }
}