<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Payment\PaymentService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
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
 *   • مدیریت کاربران ربات (مسدودسازی، جستجو)
 *   • تنظیمات فروشگاه (باز/بسته کردن، اجرای خودکار)
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
        ?FeatureFlags $flags = null
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
                $this->startEditPackage($chatId, (int) ($data['id'] ?? 0));
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

            case 'admin.notice.edit':
                $this->startEditNotice($chatId, (int) $update->userId());
                break;

            case 'admin.notice.reset':
                $this->flags->resetDisabledNotice();
                $this->bot->sendMessage($chatId, '♻️ متن پیش‌فرض بازگردانی شد.');
                $this->showSettings($chatId);
                break;

            case 'admin.setting.toggle':
                $this->toggleSetting($chatId, (string) ($data['key'] ?? ''));
                break;

            case 'admin.broadcast':
                $this->broadcast($chatId, (string) ($data['text'] ?? ''));
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
        $stats     = $this->orders->stats();
        $awaiting  = $this->orders->countAwaitingReview();
        $userCount = $this->users->countAll();

        $lines = [
            '🛠 <b>پنل مدیریت</b>',
            '',
            '📊 <b>وضعیت کلی</b>',
            '👥 کاربران ربات: <b>' . Str::faNumber($userCount) . '</b>',
            '🧾 کل سفارش‌ها: <b>' . Str::faNumber($stats['total']) . '</b>',
            '💰 درآمد کل: <b>' . Str::formatToman($stats['revenue']) . '</b>',
            '✅ اجراشده: <b>' . Str::faNumber($stats['applied']) . '</b>',
        ];

        if ($awaiting > 0) {
            $lines[] = '⏳ در انتظار تأیید رسید: <b>' . Str::faNumber($awaiting) . '</b>';
        }

        if ($stats['failed'] > 0) {
            $lines[] = '❌ ناموفق: <b>' . Str::faNumber($stats['failed']) . '</b>';
        }

        $lines[] = '';
        $lines[] = 'یکی از بخش‌ها را انتخاب کنید 👇';

        $keyboard = Keyboard::rows([
            [
                ['text' => '📦 بسته‌ها', 'data' => BotApi::encodeData('admin.packages')],
                ['text' => '🧾 سفارش‌ها', 'data' => BotApi::encodeData('admin.orders')],
            ],
            [
                ['text' => '👥 کاربران', 'data' => BotApi::encodeData('admin.users')],
                ['text' => '⚙️ تنظیمات', 'data' => BotApi::encodeData('admin.settings')],
            ],
            [
                ['text' => '📊 آمار', 'data' => BotApi::encodeData('admin.stats')],
                ['text' => '🛠 منوی اصلی ربات', 'data' => BotApi::encodeData('menu')],
            ],
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showStats(int $chatId): void
    {
        $stats   = $this->orders->stats();
        $sold    = $this->orders->soldVolume();
        $packages = $this->packages->allPackages(true);

        $lines = [
            '📊 <b>گزارش آماری</b>',
            '',
            '💰 <b>فروش</b>',
            '• کل سفارش‌ها: ' . Str::faNumber($stats['total']),
            '• پرداخت‌شده: ' . Str::faNumber($stats['paid']),
            '• اجراشده: ' . Str::faNumber($stats['applied']),
            '• ناموفق: ' . Str::faNumber($stats['failed']),
            '• درآمد کل: <b>' . Str::formatToman($stats['revenue']) . '</b>',
            '',
            '💾 <b>حجم فروش‌رفته</b>',
        ];

        $lines[] = '• بسته‌های پنل: ' . Str::faNumber($sold[PackageRepository::KIND_PANEL_QUOTA] ?? 0, 1) . ' گیگابایت';
        $lines[] = '• اعتبار کاربر: ' . Str::faNumber($sold[PackageRepository::KIND_USER_CREDIT] ?? 0, 1) . ' گیگابایت';
        $lines[] = '';
        $lines[] = '📦 <b>بسته‌های فعال: ' . Str::faNumber(count($packages)) . '</b>';

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('admin.home', '🛠 پنل مدیریت')])),
        ]);
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
            $this->bot->sendMessage($chatId, '📦 هیچ بسته‌ای تعریف نشده است.', [
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

        $lines = ['📦 <b>مدیریت بسته‌ها</b>', ''];
        $keyboard = [];

        foreach ($slice as $package) {
            $icon = (int) $package['is_active'] === 1 ? '🟢' : '🔴';
            $kind = $package['kind'] === PackageRepository::KIND_USER_CREDIT ? 'اعتبار کاربر' : 'حجم پنل';
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
            'نوع: ' . ($package['kind'] === PackageRepository::KIND_USER_CREDIT ? 'اعتبار کاربر' : 'حجم پنل'),
            'حجم: ' . Str::faNumber((float) $package['volume_gb'], 1) . ' گیگابایت',
            'هدیه: ' . Str::faNumber((float) $package['bonus_gb'], 1) . ' گیگابایت',
            'مدت: ' . Str::faNumber((int) $package['duration_days']) . ' روز',
            'قیمت: <b>' . Str::formatToman((int) $package['price_toman']) . '</b>',
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
            '➕ <b>ساخت بستهٔ جدید</b>',
            '',
            'لطفاً اطلاعات را به این ترتیب و در یک پیام بفرستید:',
            '',
            '<code>عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان</code>',
            '',
            'مثال:',
            '<code>بسته ۱۰۰ گیگ | panel_quota | 100 | 30 | 500000</code>',
            '',
            'نوع بسته:',
            '• <code>panel_quota</code> — افزایش مستقیم حجم پنل',
            '• <code>user_credit</code> — اعتبار ساخت کاربر',
        ]));
    }

    private function startEditPackage(int $chatId, int $packageId): void
    {
        $package = $this->packages->find($packageId);
        if ($package === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->bot->sendMessage($chatId, implode("\n", [
            '✏️ <b>ویرایش بسته</b>',
            '',
            'بسته: <b>' . Str::escape((string) $package['title']) . '</b>',
            '',
            'فرمت: <code>عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان</code>',
            '',
            'مقادیر فعلی:',
            '<code>' . implode(' | ', [
                (string) $package['title'],
                (string) $package['kind'],
                (string) $package['volume_gb'],
                (string) $package['duration_days'],
                (string) $package['price_toman'],
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
            $this->bot->sendMessage($chatId, '🧾 سفارشی با این فیلتر یافت نشد.', [
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
            'cancelled'        => '🚫',
        ];

        $lines = ['🧾 <b>مدیریت سفارش‌ها</b> (' . Str::faNumber($total) . ')', ''];
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

        if (in_array((string) $order['status'], [OrderRepository::STATUS_PAID, OrderRepository::STATUS_FAILED], true)) {
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

        $this->bot->sendMessage($chatId, ($approved ? '✅' : '❌') . ' ' . Str::escape((string) $result['message']));

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

        $this->bot->sendMessage($chatId, '⏳ در حال اجرای بسته روی پنل...');

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
            $this->bot->sendMessage($chatId, '👥 کاربری یافت نشد.', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('admin.home', '🛠 پنل مدیریت')])),
            ]);
            return;
        }

        $lines = ['👥 <b>کاربران ربات</b> (' . Str::faNumber($total) . ')', ''];
        $keyboard = [];

        foreach ($users as $user) {
            $blocked = (int) $user['is_blocked'] === 1;
            $linked  = ($user['panel_username'] ?? null) !== null;
            $icon    = $blocked ? '🚫' : ($linked ? '🟢' : '⚪️');

            $lines[] = $icon . ' ' . Str::escape((string) ($user['panel_username'] ?? $user['username'] ?? 'کاربر'))
                . ' <code>' . (int) $user['telegram_id'] . '</code>';
            $lines[] = '   حجم: ' . Str::formatBytes((int) $user['panel_data_limit'])
                . ' • خرید: ' . Str::formatToman((int) $user['total_paid']);
            $lines[] = '';

            $keyboard[] = [[
                'text' => $icon . ' ' . Str::truncate((string) ($user['panel_username'] ?? $user['telegram_id']), 24),
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

        $lines = [
            '👤 <b>جزئیات کاربر</b>',
            '',
            '🆔 تلگرام: <code>' . (int) $user['telegram_id'] . '</code>',
            '👤 نام: ' . Str::escape((string) ($user['first_name'] ?? '—')),
            '🆔 یوزرنیم تیگرام: @' . Str::escape((string) ($user['username'] ?? '—')),
            '',
            '🖥 حساب پنل: ' . Str::escape((string) ($user['panel_username'] ?? '—')),
            '📊 وضعیت: ' . Str::escape((string) $user['panel_status']),
            '💾 حجم پنل: ' . Str::formatBytes((int) $user['panel_data_limit']),
            '📥 مصرف: ' . Str::formatBytes((int) $user['panel_used']),
            '🎁 حجم هدیه‌شده: ' . Str::formatBytes((int) $user['granted_volume']),
            '🎫 اعتبار کاربر: ' . Str::formatBytes((int) $user['user_credit']),
            '',
            '🧾 سفارش‌ها: ' . Str::faNumber((int) $user['orders_count']),
            '💰 مجموع خرید: ' . Str::formatToman((int) $user['total_paid']),
            '📅 عضویت: ' . Str::date((int) $user['created_at']),
            '🕒 آخرین بازدید: ' . Str::date((int) ($user['last_seen_at'] ?? 0)),
        ];

        if ($blocked) {
            $lines[] = '';
            $lines[] = '🚫 مسدود: ' . Str::escape((string) ($user['blocked_reason'] ?? ''));
        }

        $keyboard = Keyboard::rows([
            [[
                'text' => $blocked ? '🟢 رفع مسدودی' : '🚫 مسدود کردن',
                'data' => BotApi::encodeData('admin.user.block', ['id' => $userId]),
            ]],
            Keyboard::back('admin.users', '👥 بازگشت به کاربران'),
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
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
            $this->bot->sendMessage($chatId, '🟢 مسدودی کاربر برداشته شد.');

            $this->notifier->notifyUser((int) $user['telegram_id'], '✅ <b>دسترسی شما دوباره فعال شد.</b>');
        } else {
            $this->users->setBlocked($userId, true, 'مسدود توسط سوپرادمین');
            $this->bot->sendMessage($chatId, '🚫 کاربر مسدود شد.');

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

        $lines = [
            '⚙️ <b>تنظیمات ربات</b>',
            '',
            ($botOn ? '🟢' : '🔴') . ' کل ربات: <b>' . ($botOn ? 'فعال' : 'غیرفعال') . '</b>',
            ($shopOpen ? '🟢' : '🔴') . ' فروشگاه: <b>' . ($shopOpen ? 'باز' : 'بسته') . '</b>',
            ($autoApply ? '🟢' : '🔴') . ' اجرای خودکار بسته: <b>' . ($autoApply ? 'فعال' : 'غیرفعال') . '</b>',
        ];

        // درگاه‌ها
        $lines[] = '';
        $lines[] = '💳 <b>درگاه‌های پرداخت</b>';

        foreach ($flags->gatewayStatuses() as $name => $status) {
            $label = $name === 'card2card' ? 'کارت‌به‌کارت' : 'ارز دیجیتال';
            $icon  = $status['enabled'] ? '🟢' : '🔴';
            $note  = '';

            if (!$status['configured']) {
                $note = '  (پیکربندی نشده در config.php)';
            }

            $lines[] = $icon . ' ' . $label . ': <b>' . ($status['enabled'] ? 'فعال' : 'غیرفعال') . '</b>' . $note;
        }

        // قابلیت‌ها
        $lines[] = '';
        $lines[] = '🔧 <b>قابلیت‌ها</b>';
        $lines[] = ($flags->isRenewalEnabled() ? '🟢' : '🔴') . ' تمدید: <b>'
            . ($flags->isRenewalEnabled() ? 'فعال' : 'غیرفعال') . '</b>';
        $lines[] = ($flags->isUserToolsEnabled() ? '🟢' : '🔴') . ' ابزار ساخت/تمدید کاربر: <b>'
            . ($flags->isUserToolsEnabled() ? 'فعال' : 'غیرفعال') . '</b>';

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
                ['text' => $autoApply ? '⛔️ خاموش کردن اجرای خودکار' : '✅ روشن کردن اجرای خودکار',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::AUTO_APPLY])],
            ],
            [
                ['text' => ($flags->isRenewalEnabled() ? '🔴' : '🟢') . ' تمدید',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::RENEWAL_ENABLED])],
                ['text' => ($flags->isUserToolsEnabled() ? '🔴' : '🟢') . ' ابزار کاربر',
                 'data' => BotApi::encodeData('admin.flag.toggle', ['key' => Settings::USER_TOOLS])],
            ],
            [['text' => '💳 مدیریت درگاه‌های پرداخت', 'data' => BotApi::encodeData('admin.gateways')]],
            [
                ['text' => '📊 آمار', 'data' => BotApi::encodeData('admin.stats')],
                ['text' => '🛠 پنل مدیریت', 'data' => BotApi::encodeData('admin.home')],
            ],
        ]);

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
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

            $lines[] = ($isOn ? '🟢' : '🔴') . ' <b>' . Str::escape($gateway->title()) . '</b>';

            if ($name === 'card2card') {
                $card = Config::str('store.card_number');
                $lines[] = '   شماره کارت: ' . ($card !== '' ? '<code>' . Str::escape($card) . '</code>' : '<i>تنظیم نشده</i>');
            } else {
                $lines[] = '   کلید API: ' . ($state['configured'] ? '<code>••••••</code>' : '<i>تنظیم نشده</i>');
            }

            $lines[] = '   وضعیت: ' . ($enabled ? 'فعال' : 'غیرفعال')
                . ($state['configured'] ? '' : ' — تا وقتی در config.php پیکربندی نشود کار نمی‌کند');
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
            '✏️ <b>متن غیرفعالی ربات</b>',
            '',
            'این متن به کاربرانی نمایش داده می‌شود که وقتی ربات خاموش است پیام می‌دهند.',
            '',
            '📌 <b>متن فعلی:</b>',
            $current,
            '',
            '────────────────────',
            'متن جدید را بفرستید. برای بازگردانی متن پیش‌فرض «پیش‌فرض» را بنویسید.',
            'برای لغو /cancel را بزنید.',
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

    private function toggleSetting(int $chatId, string $key): void
    {
        if (!in_array($key, [Settings::SHOP_OPENED, Settings::AUTO_APPLY], true)) {
            $this->bot->sendMessage($chatId, '❌ کلید تنظیم نامعتبر است.');
            return;
        }

        $current = $this->settings->bool($key, true);
        $this->settings->set($key, $current ? '0' : '1');

        $this->bot->sendMessage($chatId, '✅ تنظیم به‌روزرسانی شد.');
        $this->showSettings($chatId);
    }

    private function broadcast(int $chatId, string $text): void
    {
        // در این نسخه پیام همگانی از طریق کرون/کلید انجام می‌شود.
        $this->bot->sendMessage($chatId, implode("\n", [
            '📣 <b>پیام همگانی</b>',
            '',
            'برای ارسال پیام به همه کاربران، پیام را اینجا بفرستید و به دکمهٔ تأیید بزنید.',
            '',
            '⚠️ فعلاً فقط پیش‌نمایش است.',
        ]));
    }
}