<?php

declare(strict_types=1);

namespace Pasargad\WebApp;

use Pasargad\Bot\ChannelGuard;
use Pasargad\Bot\Notifier;
use Pasargad\Bot\ShopException;
use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Payment\CardToCardGateway;
use Pasargad\Payment\PaymentGateway;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\AccessCutoff;
use Pasargad\Store\AgencyService;
use Pasargad\Store\AuditLogger;
use Pasargad\Store\CouponRepository;
use Pasargad\Store\DiscountService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\PanelSyncer;
use Pasargad\Store\PanelUserStats;
use Pasargad\Store\Provisioner;
use Pasargad\Store\ReferralRepository;
use Pasargad\Store\Security;
use Pasargad\Store\Settings;
use Pasargad\Store\TestConfigRepository;
use Pasargad\Store\TestConfigService;
use Pasargad\Store\TicketRepository;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Invoice;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;

/**
 * API مینی‌اپ تلگرام.
 *
 * پوشش کامل جریان‌های ربات، اما به‌جای پیام و کیبورد inline، با JSON و صفحهٔ وب:
 *   • حساب: پروفایل، کیف پول، تاریخچهٔ پرداخت، امتیاز وفاداری
 *   • پنل‌ها: فهرست، جزئیات کامل (اطلاعات ورود)، سینک، آمار کاربران،
 *     اتصال پنل موجود، اشتراک
 *   • کانفیگ تست: ساخت، فهرست، غیرفعال‌سازی، حذف خودکار
 *   • فروشگاه: بسته‌ها، پیش‌فاکتور با تخفیف، ثبت سفارش، پرداخت، بررسی،
 *     رسید کارت‌به‌کارت، فاکتور
 *   • تخفیف و معرفی: اعمال/حذف کد، آمار معرفی، پاداش سطح دوم
 *   • پشتیبانی: تیکت‌ها، گفت‌وگو، بستن تیکت
 *   • پنل مدیریت (فقط سوپرادمین): آمار، سفارش‌ها و تأیید/رد پرداخت، کاربران،
 *     بلاک/رفع بلاک، شارژ کیف پول، بسته‌ها و فعال/غیرفعال، کد تخفیف، تیکت‌ها
 *     و پاسخ مدیر، سوییچ‌ها، پنل‌ها، لاگ حسابرسی
 *
 * نکته‌های معماری که عمداً رعایت شده‌اند:
 *   ۱) **هیچ منطق کسب‌وکاری اینجا دوباره نوشته نشده.** تخفیف با
 *      `DiscountService`، ساخت سفارش با همان قواعد `Kernel`، اجرا با
 *      `Provisioner`. این کلاس فقط ترجمهٔ HTTP↔سرویس است.
 *   ۲) هر endpoint مالکیت را خودش بررسی می‌کند (`findForUser` و مشابه)؛
 *      هیچ‌جا «شناسه را از کلاینت بگیر و مستقیم بخوان» وجود ندارد.
 *   ۳) نمایش و نوشتن جدا شده‌اند: `GET` فقط می‌خواند، `POST` فقط تغییر
 *      وضعیت. این جلوی «دکمهٔ GET که سفارش می‌سازد» را می‌گیرد.
 */
final class Api
{
    private UserRepository $users;
    private PanelRepository $panels;
    private OrderRepository $orders;
    private PackageRepository $packages;
    private TicketRepository $tickets;
    private TestConfigRepository $testConfigs;
    private Settings $settings;
    private FeatureFlags $flags;
    private DiscountService $discounts;
    private Security $security;
    private AuditLogger $audit;
    private PanelSyncer $syncer;
    private PanelUserStats $userStats;
    private TestConfigService $testService;
    private Provisioner $provisioner;
    private PaymentService $payments;
    // ⚠️ هر سه `= null` لازم دارند: یک typed property بدون مقدار پیش‌فرض
    // «قبل از مقداردهی‌شدن» است و حتی خواندنش خطای fatal می‌دهد. کلاس
    // در تست‌ها با کلاینت تزریق‌شده ساخته می‌شود و ممکن است هیچ‌کدام از این
    // سه در سازنده مقدار نگیرند.
    private ?BotApi $bot = null;
    private ?ChannelGuard $channel = null;
    private ?PasarGuardClient $panelClient = null;

    /**
     * رکورد کاربر جاری (از `users`).
     *
     * @var array<string, mixed>
     */
    private array $user = [];

    /** آیدی عددی تلگرام کاربر جاری. */
    private int $telegramId = 0;

    /** آیا کاربر جاری سوپرادمین است؟ */
    private bool $isAdmin = false;

    /** پارامتر شروع (کد معرفی) از initData. */
    private string $startParam = '';

    public function __construct(?BotApi $bot = null, ?PasarGuardClient $panel = null, ?\Pasargad\Support\Db $db = null)
    {
        $db = $db ?? \Pasargad\Support\Db::instance();

        $this->users       = new UserRepository($db);
        $this->panels      = new PanelRepository($db);
        $this->orders      = new OrderRepository($db);
        $this->packages    = new PackageRepository($db);
        $this->tickets     = new TicketRepository($db);
        $this->testConfigs = new TestConfigRepository($db);
        $this->settings    = new Settings($db);
        $this->flags       = new FeatureFlags($this->settings);
        $this->discounts   = new DiscountService(
            new CouponRepository($db),
            new ReferralRepository($db),
            $this->settings,
            $db
        );
        $this->security = new Security($db);
        $this->audit    = new AuditLogger($db);

        $this->bot = $bot;
        $this->panelClient = $panel;

        $this->syncer      = new PanelSyncer($this->panels, $this->panelClient);
        $this->userStats   = new PanelUserStats($this->panels, $this->settings, $this->panelClient);
        $this->testService = new TestConfigService($this->panels, $this->testConfigs, $this->settings, $this->panelClient);

        $this->provisioner = new Provisioner(
            $this->panelClient,
            $this->orders,
            $this->users,
            $this->settings,
            $this->panels
        );

        $this->payments = new PaymentService(
            $this->orders,
            $this->provisioner,
            $this->settings,
            $this->flags,
            $this->users
        );
        $this->payments->setNotifier(new Notifier($this->requireBot()));
    }

    private function requireBot(): BotApi
    {
        if ($this->bot === null) {
            $this->bot = new BotApi();
        }

        return $this->bot;
    }

    private function requirePanelClient(): PasarGuardClient
    {
        if ($this->panelClient === null) {
            $this->panelClient = new PasarGuardClient();
        }

        return $this->panelClient;
    }

    private function channelGuard(): ChannelGuard
    {
        if ($this->channel === null) {
            $this->channel = new ChannelGuard($this->requireBot(), $this->settings);
        }

        return $this->channel;
    }

    // ------------------------------------------------------------------
    // ورود
    // ------------------------------------------------------------------

    /**
     * احراز هویت با initData تلگرام و ساخت/به‌روزرسانی رکورد کاربر.
     *
     * @param array<string, mixed> $init پارامترهای initData
     * @return array{ok:bool, message:string}
     */
    public function authenticate(array $init): array
    {
        $user = (array) ($init['user'] ?? []);

        $telegramId = (int) ($user['id'] ?? 0);

        if ($telegramId <= 0) {
            return ['ok' => false, 'message' => 'شناسهٔ کاربر نامعتبر است.'];
        }

        $this->telegramId  = $telegramId;
        $this->startParam  = (string) ($init['start_param'] ?? '');

        // همان منبع حقیقتِ ربات: `Notifier::isAdmin()` هم دقیقاً همین را
        // می‌خواند. اگر اینجا جدا حساب می‌شد، یک نفر می‌توانست در مینی‌اپ
        // مدیر باشد ولی در ربات کاربر عادی (یا برعکس) — یعنی دور زدن کنترل
        // دسترسی با یک باگ به‌ظاهر بی‌اهمیت.
        $this->isAdmin = (new Notifier($this->requireBot()))->isAdmin($telegramId);

        try {
            $this->user = $this->users->upsertByTelegram($telegramId, [
                'username'      => isset($user['username']) ? (string) $user['username'] : null,
                'first_name'    => isset($user['first_name']) ? (string) $user['first_name'] : null,
                'language_code' => isset($user['language_code']) ? (string) $user['language_code'] : null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('WebApp user upsert failed', [
                'telegram_id' => $telegramId,
                'error'       => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'اتصال به سرویس ممکن نشد. لطفاً دوباره تلاش کنید.'];
        }

        if (!empty($this->user['is_blocked'])) {
            return [
                'ok'      => false,
                'message' => '⛔️ حساب شما مسدود شده است.' . (!empty($this->user['blocked_reason'])
                    ? ' دلیل: ' . Str::truncate((string) $this->user['blocked_reason'], 160)
                    : ''),
            ];
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * شناسهٔ داخلی کاربر جاری در جدول `users`.
     *
     * عمومی است چون تست‌ها و ابزارهای CLI به آن نیاز دارند؛ در خودِ اپ
     * هیچ‌جا از بیرون قابل دور زدن نیست چون هنوز باید `authenticate()` را
     * رد کرده باشی.
     */
    public function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    /**
     * آیدی عددی تلگرام کاربر جاری.
     */
    public function telegramId(): int
    {
        return $this->telegramId;
    }

    /**
     * آیا کاربر جاری سوپرادمین است؟
     */
    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    private function graceDays(): int
    {
        return $this->flags->graceDays();
    }

    // ------------------------------------------------------------------
    // مسیریابی
    // ------------------------------------------------------------------

    /**
     * اجرای یک عملیات و بسته‌بندی نتیجه.
     *
     * @param  array<string, mixed> $input ورودی کلاینت
     * @return array<string, mixed>
     */
    public function handle(string $action, array $input): array
    {
        try {
            $result = $this->route($action, $input);
        } catch (ShopException $e) {
            // خطای قابل انتظار کسب‌وکاری (سقف خرید، فروشگاه بسته، …):
            // پیامش برای کاربر نوشته شده، پس همان برمی‌گردد.
            return ['ok' => false, 'message' => $this->stripEmoji($e->getMessage())];
        } catch (PanelException $e) {
            Logger::warning('WebApp panel error', [
                'user_id' => $this->telegramId,
                'action'  => $action,
                'error'   => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'message' => '⚠️ ارتباط با پنل برقرار نشد: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Logger::error('WebApp action failed', [
                'user_id' => $this->telegramId,
                'action'  => $action,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);

            return ['ok' => false, 'message' => 'خطای داخلی رخ داد. لطفاً دوباره تلاش کنید.'];
        }

        return is_array($result) ? $result + ['ok' => true] : ['ok' => true];
    }

    /**
     * حذف ایموجی از پیام‌های متنی ربات.
     *
     * پیام‌های `ShopException` و `PanelException` برای تلگرام نوشته شده‌اند و
     * ایموجی و تگ HTML دارند. در Mini App همان متن با ایموجی‌های تکراری و
     * تگ‌های نشان‌داده‌شده بد به‌نظر می‌رسد، پس تمیز می‌شود.
     */
    private function stripEmoji(string $text): string
    {
        $text = str_replace(['<b>', '</b>', '<i>', '</i>', '<code>', '</code>'], '', $text);
        $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{2B00}-\x{2BFF}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function route(string $action, array $input): array
    {
        return match ($action) {
            'bootstrap'      => $this->bootstrap(),

            'panels.list'    => $this->panelsList(),
            'panel.detail'   => $this->panelDetail($this->intInput($input, 'id')),
            'panel.sync'     => $this->panelSync($this->intInput($input, 'id')),
            'panel.stats'    => $this->panelStats($this->intInput($input, 'id'), $this->boolInput($input, 'force')),
            'panel.link'     => $this->panelLink((string) ($input['username'] ?? ''), (string) ($input['password'] ?? '')),
            'panel.subscribe' => $this->panelSubscribe($this->intInput($input, 'id')),
            'panel.users'    => $this->panelUsersPreview($this->intInput($input, 'id'), $this->intInput($input, 'limit')),

            'tests.list'     => $this->testsList(),
            'test.issue'     => $this->testIssue($this->intInput($input, 'panel_id')),
            'test.disable'   => $this->testDisable($this->intInput($input, 'id')),
            'test.autodelete' => $this->testAutoDelete(
                $this->intInput($input, 'id'),
                (string) ($input['op'] ?? 'toggle'),
                $this->intInput($input, 'seconds')
            ),

            'shop.list'      => $this->shopList((string) ($input['kind'] ?? PackageRepository::KIND_AGENCY)),
            'shop.package'   => $this->shopPackage($this->intInput($input, 'id'), $this->intInput($input, 'days')),
            'shop.quote'     => $this->shopQuote(
                $this->intInput($input, 'package_id'),
                $this->intInput($input, 'days'),
                $this->intInput($input, 'panel_id')
            ),
            'order.create'   => $this->createOrder(
                $this->intInput($input, 'package_id'),
                $this->intInput($input, 'days'),
                $this->intInput($input, 'panel_id')
            ),

            'orders.list'    => $this->ordersList((string) ($input['status'] ?? '')),
            'order.detail'   => $this->orderDetail($this->intInput($input, 'id')),
            'order.pay'      => $this->startPayment($this->intInput($input, 'id'), (string) ($input['method'] ?? '')),
            'order.check'    => $this->checkOrder($this->intInput($input, 'id')),
            'order.receipt'  => $this->submitReceipt(
                $this->intInput($input, 'id'),
                (string) ($input['file_id'] ?? ''),
                (string) ($input['data'] ?? '')
            ),
            'order.invoice'  => $this->orderInvoice($this->intInput($input, 'id')),

            'wallet'         => $this->wallet(),
            'payments'       => $this->paymentsHistory(),
            'coupon.apply'   => $this->couponApply((string) ($input['code'] ?? ''), $this->intInput($input, 'price')),
            'coupon.clear'   => $this->couponClear(),

            'referral'       => $this->referral(),
            'referral.bind'  => $this->referralBind((string) ($input['code'] ?? '')),

            'tickets.list'   => $this->ticketsList(),
            'ticket.create'  => $this->ticketCreate((string) ($input['category'] ?? 'other'), (string) ($input['body'] ?? '')),
            'ticket.detail'  => $this->ticketDetail($this->intInput($input, 'id')),
            'ticket.reply'   => $this->ticketReply($this->intInput($input, 'id'), (string) ($input['body'] ?? '')),
            'ticket.close'   => $this->ticketClose($this->intInput($input, 'id')),

            'rules'          => ['text' => $this->rulesText()],
            'support'        => $this->supportInfo(),

            // ---- پنل مدیریت ----
            'admin.overview'    => $this->adminOverview(),
            'admin.orders'      => $this->adminOrders((string) ($input['status'] ?? ''), $this->intInput($input, 'page')),
            'admin.order'       => $this->adminOrder($this->intInput($input, 'id')),
            'admin.order.review' => $this->adminReviewOrder(
                $this->intInput($input, 'id'),
                $this->boolInput($input, 'approved'),
                (string) ($input['note'] ?? '')
            ),
            'admin.order.retry' => $this->adminRetryOrder($this->intInput($input, 'id')),
            'admin.users'       => $this->adminUsers((string) ($input['q'] ?? ''), $this->intInput($input, 'page')),
            'admin.user'        => $this->adminUser($this->intInput($input, 'id')),
            'admin.user.block'  => $this->adminBlockUser(
                $this->intInput($input, 'id'),
                $this->boolInput($input, 'blocked'),
                (string) ($input['reason'] ?? '')
            ),
            'admin.user.wallet' => $this->adminAdjustWallet(
                $this->intInput($input, 'id'),
                $this->intInput($input, 'amount'),
                (string) ($input['note'] ?? '')
            ),
            'admin.panels'      => $this->adminPanels((string) ($input['q'] ?? ''), $this->intInput($input, 'page')),
            'admin.panel.sync'  => $this->adminPanelSync($this->intInput($input, 'id')),
            'admin.panel.cutoff' => $this->adminCutoff($this->intInput($input, 'id'), $this->boolInput($input, 'confirm')),
            'admin.packages'    => $this->adminPackages(),
            'admin.package.save' => $this->adminSavePackage($input),
            'admin.package.toggle' => $this->adminTogglePackage($this->intInput($input, 'id')),
            'admin.package.delete' => $this->adminDeletePackage($this->intInput($input, 'id')),
            'admin.coupons'     => $this->adminCoupons(),
            'admin.coupon.save' => $this->adminSaveCoupon($input),
            'admin.coupon.delete' => $this->adminDeleteCoupon($this->intInput($input, 'id')),
            'admin.tickets'     => $this->adminTickets(),
            'admin.ticket'      => $this->adminTicket($this->intInput($input, 'id')),
            'admin.ticket.reply' => $this->adminReplyTicket($this->intInput($input, 'id'), (string) ($input['body'] ?? '')),
            'admin.flags'       => $this->adminFlags(),
            'admin.flag.toggle' => $this->adminToggleFlag((string) ($input['key'] ?? '')),
            'admin.risk'        => $this->adminRisk(),
            'admin.audit'       => $this->adminAudit(),
            'admin.settings'    => $this->adminSettings(),
            'admin.setting.save' => $this->adminSaveSetting((string) ($input['key'] ?? ''), (string) ($input['value'] ?? '')),

            default => throw new ShopException('این بخش وجود ندارد.'),
        };
    }

    // ------------------------------------------------------------------
    // ورودی
    // ------------------------------------------------------------------

    /**
     * خواندن یک عدد صحیح از ورودی کلاینت.
     *
     * دو نکته که هر دو بار امنیتی‌اند:
     *
     * ۱) **علامت منفی حفظ می‌شود.** کاربر کیف پول مدیریتی دارد و «کسر وجه»
     *    یعنی عدد منفی. اگر فقط ارقام را نگه می‌داشتیم، `-1000000` به
     *    `1000000` تبدیل می‌شد و «کسر» به «شارژ» تبدیل می‌شد — یعنی هر
     *    مدیر با یک اشتباه، پول کاربر را زیاد می‌کرد.
     *
     * ۲) ارقام فارسی/عربی هم پذیرفته می‌شوند (کاربر ممکن است عدد را از متن
     *    ربات کپی کند) و هر جداکننده‌ای مثل `٬` و فاصله دور ریخته می‌شود.
     *
     * @param array<string, mixed> $input
     */
    private function intInput(array $input, string $key): int
    {
        $value = $input[$key] ?? 0;

        if (is_bool($value) || !is_scalar($value)) {
            return 0;
        }

        $text = Str::toEnglishDigits(trim((string) $value));

        // علامت منفی پیش از حذف نویسه‌های اضافه خوانده می‌شود. علامت « منهای
        // یونیکد» (U+2212) هم پذیرفته می‌شود چون کیبورد موبایل فارسی
        // گاهی همان را می‌فرستد.
        $negative = str_contains($text, '-') || str_contains($text, "\u{2212}");

        $digits = preg_replace('/\D/', '', $text);

        if ($digits === null || $digits === '') {
            return 0;
        }

        $number = (int) $digits;

        return $negative ? -$number : $number;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function boolInput(array $input, string $key): bool
    {
        $value = $input[$key] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * نگهبان داخلی برای عملیات مدیریتی.
     *
     * @return array<string, mixed>
     */
    private function requireAdmin(): array
    {
        if (!$this->isAdmin) {
            return ['ok' => false, 'forbidden' => true, 'message' => '⛔️ این بخش مخصوص مدیریت است.'];
        }

        return ['ok' => true, 'message' => ''];
    }

    // ------------------------------------------------------------------
    // bootstrap
    // ------------------------------------------------------------------

    /**
     * همهٔ چیزی که کلاینت برای شروع لازم دارد، در یک درخواست.
     *
     * چرا یک endpoint بزرگ؟ چون مینی‌اپ باید *فوری* رندر شود. اگر ۶ درخواست
     * جدا لازم بود، کاربر نوار اسکلتون را چند ثانیه می‌دید و حس «کند بودن»
     * می‌گرفت — با این حال که دیتابیس محلی است، کل این پاسخ زیر ۵۰ms آماده
     * می‌شود.
     *
     * @return array<string, mixed>
     */
    private function bootstrap(): array
    {
        $panels = $this->panels->listByUser($this->userId());
        $cards  = View::panelCards($panels, $this->graceDays());

        $tickets = $this->tickets->listByUser($this->userId(), 5);
        $orders  = $this->orders->listByUser($this->userId(), 5);

        $walletBalance = $this->users->walletBalance($this->userId());

        $totalLimit = 0;
        $totalUsed  = 0;
        $expiring    = 0;

        foreach ($cards as $card) {
            $totalLimit += (int) $card['traffic']['limit'];
            $totalUsed  += (int) $card['traffic']['used'];

            if (!empty($card['expired'])) {
                $expiring++;
            } elseif (($card['days_left'] ?? null) !== null && (int) $card['days_left'] <= 7) {
                $expiring++;
            }
        }

        return [
            'user' => [
                'id'            => $this->telegramId,
                'first_name'    => (string) ($this->user['first_name'] ?? ''),
                'username'      => (string) ($this->user['username'] ?? ''),
                'is_admin'      => $this->isAdmin,
                'blocked'       => false,
                'wallet'        => $walletBalance,
                'wallet_text'   => Str::formatToman($walletBalance),
                'loyalty'       => (int) ($this->user['loyalty_points'] ?? 0),
                'orders_count'  => (int) ($this->user['orders_count'] ?? 0),
                'total_paid'    => (int) ($this->user['total_paid'] ?? 0),
                'total_paid_text' => Str::formatToman((int) ($this->user['total_paid'] ?? 0)),
                'coupon'        => $this->users->couponCode($this->userId()),
                'is_representative' => $panels !== [],
            ],
            'panels' => [
                'count'       => count($cards),
                'items'       => $cards,
                'total_limit' => $totalLimit,
                'total_used'  => $totalUsed,
                'used_percent' => $totalLimit > 0 ? min(100, (int) round($totalUsed / $totalLimit * 100)) : 0,
                'alerts'      => $expiring,
            ],
            'recent_orders' => array_map([View::class, 'order'], $orders),
            'tickets'       => [
                'open'   => $this->countOpenTickets($tickets),
                'recent' => array_map([View::class, 'ticket'], $tickets),
            ],
            'referral' => $this->discounts->referralSummary($this->userId()),
            'app' => [
                'bot_username'  => Config::str('bot_username', ''),
                'support_link'  => Config::str('notifications.support_link', ''),
                'shop_open'     => $this->settings->bool(Settings::SHOP_OPENED, true),
                'shop_closed_message' => '🛒 فروشگاه موقتاً بسته است. کمی بعد تلاش کنید.',
                'bot_enabled'   => $this->flags->isBotEnabled(),
                'disabled_notice' => $this->flags->disabledNotice(),
                'flags'         => $this->flags->summary(),
                'coupons'       => $this->flags->isCouponsEnabled(),
                'referral_enabled' => $this->flags->isReferralEnabled(),
                'tickets'       => $this->flags->isTicketsEnabled(),
                'test_config'   => $this->testService->isEnabled(),
                'renewal'       => $this->flags->isRenewalEnabled(),
                'min_order'     => Config::int('store.min_order_toman', 50000),
                'loyalty_percent' => max(0, min(100, $this->settings->int(Settings::LOYALTY_DISCOUNT, 5))),
                'loyalty_redeem' => max(1, $this->settings->int(Settings::LOYALTY_REDEEM, 100)),
                'test_config_volume' => Str::formatBytes($this->testService->defaultBytes()),
                'test_config_days'   => $this->testService->defaultDays(),
            ],
            'channel' => $this->channelInfo(),
            'can_create_panels' => (new AgencyService($this->panelClient))->canCreatePanels(),
            'needs_referral' => $this->needsReferralBind(),
            // کد معرفی از پارامتر شروع، برای ثبت خودکار در کلاینت. جدا از
            // `needs_referral` برگردانده می‌شود چون کلاینت باید بداند *چه*
            // کدی را ثبت کند.
            'ref_code' => $this->startParam,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>> $tickets
     */
    private function countOpenTickets(array $tickets): int
    {
        $open = 0;

        foreach ($tickets as $ticket) {
            if ((string) ($ticket['status'] ?? '') !== TicketRepository::STATUS_CLOSED) {
                $open++;
            }
        }

        return $open;
    }

    /**
     * اطلاعات دروازهٔ کانال برای نمایش در کلاینت.
     *
     * بررسی واقعی عضویت اینجا انجام نمی‌شود (یک درخواست شبکه به تلگرام است)؛
     * کلاینت وضعیت را می‌پرسد و ما کش‌شده جواب می‌دهیم.
     *
     * @return array<string, mixed>
     */
    private function channelInfo(): array
    {
        $required = $this->channelGuard()->isRequired();

        $member = true;

        if ($required && !$this->isAdmin) {
            $check = $this->channelGuard()->check($this->telegramId);
            $member = (bool) ($check['member'] ?? true);
        }

        return [
            'required'    => $required,
            'member'      => $member,
            'title'       => $this->channelGuard()->channelTitle(),
            'invite_link' => $this->channelGuard()->inviteLink(),
        ];
    }

    /**
     * آیا پارامتر شروع یک کد معرفی معتبر دارد که هنوز ثبت نشده؟
     *
     * این همان چیزی است که `/start ref_CODE` در ربات انجام می‌دهد: لینک دعوت
     * کاربر را مستقیم به مینی‌اپ می‌آورد (`?startapp=R123`). بدون این، کد معرفی
     * در مینی‌اپ بی‌اثر می‌شد و فقط داخل چت کار می‌کرد.
     */
    private function needsReferralBind(): bool
    {
        $code = ltrim(trim($this->startParam), 'R');

        if ($code === '' || preg_match('/^\d{1,10}$/', $code) !== 1) {
            return false;
        }

        return 'R' . $code !== ReferralRepository::codeFor($this->userId())
            && $this->discounts->referrals()->findByReferee($this->userId()) === null;
    }

    private function rulesText(): string
    {
        $custom = trim((string) $this->settings->get(Settings::RULES_TEXT, ''));

        return $custom !== '' ? $custom : Settings::DEFAULT_RULES;
    }

    /**
     * @return array<string, mixed>
     */
    private function supportInfo(): array
    {
        $link = trim(Config::str('notifications.support_link', ''));

        return [
            'text'      => trim((string) $this->settings->get(Settings::SUPPORT_TEXT, '')),
            'link'      => $link !== '' ? $link : null,
            'enabled'   => $this->flags->isTicketsEnabled(),
            'categories' => array_map(
                static fn (string $key): array => [
                    'key'   => $key,
                    'label' => TicketRepository::categoryLabel($key),
                ],
                TicketRepository::CATEGORY_KEYS
            ),
        ];
    }

    // ------------------------------------------------------------------
    // پنل‌ها
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function panelsList(): array
    {
        $panels = $this->panels->listByUser($this->userId());

        return [
            'items' => View::panelCards($panels, $this->graceDays()),
            'count' => count($panels),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function panelDetail(int $panelId): array
    {
        $panel = $this->ownedPanel($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'این پنل در حساب شما نیست.'];
        }

        return ['panel' => View::panelDetail($panel, $this->panels->plainPassword($panel), $this->graceDays())];
    }

    /**
     * @return array<string, mixed>
     */
    private function panelSync(int $panelId): array
    {
        $panel = $this->ownedPanel($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'این پنل در حساب شما نیست.'];
        }

        $result = $this->syncer->syncOne($panel);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) $result['message']];
        }

        $fresh = $this->panels->find($panelId) ?? $panel;

        return [
            'message' => (string) $result['message'],
            'panel'   => View::panelDetail($fresh, $this->panels->plainPassword($fresh), $this->graceDays()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function panelStats(int $panelId, bool $force): array
    {
        $panel = $this->ownedPanel($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'این پنل در حساب شما نیست.'];
        }

        $result = $this->userStats->stats($panel, $force);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) $result['message']];
        }

        $total  = (int) $result['total'];
        $active = (int) $result['active'];

        return [
            'stats' => [
                'total'        => $total,
                'active'       => $active,
                'disabled'     => (int) $result['disabled'],
                'percent'      => $total > 0 ? (int) round($active / $total * 100) : 0,
                'at'           => (int) $result['at'],
                'cached'       => (bool) ($result['cached'] ?? false),
                'total_text'   => Str::faNumber($total),
                'active_text'  => Str::faNumber($active),
                'disabled_text' => Str::faNumber((int) $result['disabled']),
            ],
            'panel' => View::panelDetail(
                $this->panels->find($panelId) ?? $panel,
                $this->panels->plainPassword($panel),
                $this->graceDays()
            ),
        ];
    }

    /**
     * پیش‌نمایش چند کاربر پنل برای بررسی «واقعی» بودن وضعیت.
     *
     * این داده معمولاً در دسترس نماینده نیست مگر با وارد شدن به پنل؛ برای
     * همین ارزشمند است. تعداد محدود و بدون پیمایش کامل است تا فشار روی پنل
     * وارد نشود.
     *
     * @return array<string, mixed>
     */
    private function panelUsersPreview(int $panelId, int $limit): array
    {
        $panel = $this->ownedPanel($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'این پنل در حساب شما نیست.'];
        }

        $limit = max(1, min($limit > 0 ? $limit : 10, 20));

        $result = (new AccessCutoff($this->panels, $this->requirePanelClient()))->listPanelUsers($panel, $limit);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) $result['message']];
        }

        $users = [];

        foreach ((array) ($result['users'] ?? []) as $item) {
            $status = (string) ($item['status'] ?? '');

            $users[] = [
                'username' => (string) ($item['username'] ?? ''),
                'status'   => $status,
                'off'      => PanelUserStats::isDisabledStatus($status),
                'data_limit' => (int) ($item['data_limit'] ?? 0),
                'used'     => (int) ($item['used_traffic'] ?? 0),
                'limit_text' => Str::formatBytes((int) ($item['data_limit'] ?? 0)),
                'used_text'  => Str::formatBytes((int) ($item['used_traffic'] ?? 0)),
                'expire_text' => Str::date(isset($item['expire']) && is_numeric($item['expire'])
                    ? (int) $item['expire']
                    : null),
            ];
        }

        return ['users' => $users];
    }

    /**
     * ثبت پنل موجود («من پنل دارم»).
     *
     * همان دو مرحلهٔ ربات، اما در یک فرم: نام کاربری + رمز. اعتبارسنجی رمز با
     * همان کلاینت پنل انجام می‌شود و قفل ضد brute-force هم اعمال می‌شود —
     * بدون آن، هر کسی می‌توانست رمزها را روی پنل اصلی حدس بزند.
     *
     * @return array<string, mixed>
     */
    private function panelLink(string $username, string $password): array
    {
        $username = trim($username);
        $password = (string) $password;

        if (!Str::isValidPanelUsername($username)) {
            return ['ok' => false, 'message' => 'نام کاربری پنل معتبر نیست.'];
        }

        if ($password === '' || mb_strlen($password) > 128) {
            return ['ok' => false, 'message' => 'رمز عبور را وارد کنید.'];
        }

        $security = $this->security;
        $kind     = 'webapp_panel_login';

        if ($security->isLocked($this->telegramId, $kind)) {
            $minutes = max(1, $this->settings->int(Settings::LOGIN_LOCK_MINUTES, 15));

            return ['ok' => false, 'message' => 'تلاش‌های ناموفق زیاد بود. ' . Str::faNumber($minutes) . ' دقیقه دیگر تلاش کنید.'];
        }

        $existing = $this->panels->findByPanelUsername($username);

        if ($existing !== null && (int) $existing['user_id'] !== $this->userId()) {
            return ['ok' => false, 'message' => 'این پنل قبلاً به حساب دیگری وصل شده است. اگر اشتباه است با پشتیبانی تماس بگیرید.'];
        }

        try {
            $admin = $this->requirePanelClient()->getAdmin($username, $username, $password);
        } catch (PanelException $e) {
            $max = max(1, $this->settings->int(Settings::LOGIN_MAX_ATTEMPTS, 5));
            $lock = max(60, $this->settings->int(Settings::LOGIN_LOCK_MINUTES, 15) * 60);

            $failure = $security->recordFailure($this->telegramId, $kind, $max, $lock);

            if ($failure['locked']) {
                return ['ok' => false, 'message' => 'تلاش‌های ناموفق زیاد بود؛ ورود موقتاً قفل شد.'];
            }

            return ['ok' => false, 'message' => 'ورود ناموفق بود: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'خطای غیرمنتظره هنگام بررسی پنل.'];
        }

        $security->clear($this->telegramId, $kind);

        $wasKnown = $existing !== null;

        $panelId = $this->panels->upsertFromPanel(
            $this->userId(),
            $username,
            $password,
            $admin,
            $wasKnown ? PanelRepository::SOURCE_BOT : PanelRepository::SOURCE_SELF
        );

        $panel = $this->panels->find($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'ثبت پنل ناموفق بود. دوباره تلاش کنید.'];
        }

        return [
            'message' => $wasKnown
                ? 'اطلاعات پنل شما به‌روزرسانی شد.'
                : 'پنل شما با موفقیت به ربات اضافه شد.',
            'panel'   => View::panelDetail($panel, $this->panels->plainPassword($panel), $this->graceDays()),
        ];
    }

    /**
     * فعال‌سازی اشتراک Premium روی یک پنل.
     *
     * ⚠️ این متد **پرداخت نمی‌گیرد**؛ فقط دارایی `is_subscribed` را فعال
     * می‌کند، دقیقاً مثل ربات. چون درگاه پرداختِ اشتراک هنوز پیاده نشده، در
     * کلاینت با برچسب «فعال‌سازی فوری» و توضیح صریح نمایش داده می‌شود تا
     * کاربر فکر نکند پولی کسر شده است.
     *
     * @return array<string, mixed>
     */
    private function panelSubscribe(int $panelId): array
    {
        $panel = $this->ownedPanel($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'این پنل در حساب شما نیست.'];
        }

        $expireAt = time() + 30 * 86400;

        $this->panels->update((int) $panel['id'], [
            'is_subscribed'          => 1,
            'subscription_expire_at' => $expireAt,
        ]);

        return [
            'message'    => 'اشتراک Premium پنل شما تا ' . Str::date($expireAt) . ' فعال شد.',
            'expire_text' => Str::date($expireAt),
            'panel'      => View::panelDetail(
                $this->panels->find((int) $panel['id']) ?? $panel,
                $this->panels->plainPassword($panel),
                $this->graceDays()
            ),
        ];
    }

    /**
     * پنل با بررسی مالکیت.
     *
     * @return array<string, mixed>|null
     */
    private function ownedPanel(int $panelId): ?array
    {
        if ($panelId <= 0) {
            return null;
        }

        return $this->panels->findForUser($panelId, $this->userId());
    }

    // ------------------------------------------------------------------
    // کانفیگ تست
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function testsList(): array
    {
        if (!$this->testService->isEnabled()) {
            return ['items' => [], 'enabled' => false];
        }

        $configs = $this->testConfigs->listByUser($this->userId(), 20);

        return [
            'enabled' => true,
            'items'   => array_map([View::class, 'testConfig'], $configs),
            'volume_text' => Str::formatBytes($this->testService->defaultBytes()),
            'days'    => $this->testService->defaultDays(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testIssue(int $panelId): array
    {
        if (!$this->testService->isEnabled()) {
            return ['ok' => false, 'message' => 'دریافت تست کانفیگ موقتاً غیرفعال است.'];
        }

        $panel = $this->ownedPanel($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => 'این پنل در حساب شما نیست.'];
        }

        $guard = $this->testService->canIssue($panel, $this->userId());

        if (!($guard['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $guard['message'])];
        }

        $result = $this->testService->issue($panel, $this->userId());

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $result['message'])];
        }

        $details = (array) ($result['details'] ?? []);
        $config  = $this->testConfigs->find((int) ($details['config_id'] ?? 0));

        return [
            'message' => 'کانفیگ تست ساخته شد.',
            'config'  => $config !== null ? View::testConfig($config) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testDisable(int $configId): array
    {
        $config = $configId > 0 ? $this->testConfigs->findForUser($configId, $this->userId()) : null;

        if ($config === null) {
            return ['ok' => false, 'message' => 'این کانفیگ در حساب شما نیست.'];
        }

        $result = $this->testService->disable($config);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) $result['message']];
        }

        $fresh = $this->testConfigs->find($configId);

        return [
            'message' => (string) $result['message'],
            'config'  => $fresh !== null ? View::testConfig($fresh) : null,
        ];
    }

    /**
     * تنظیم حذف خودکار کانفیگ تست.
     *
     * سقف یک سال: یک عدد بزرگ از تایپ اشتباه یا حمله، یک رکورد را برای همیشه
     * نگه می‌داشت و عملاً پاک‌سازی خودکار را از کار می‌انداخت.
     *
     * @return array<string, mixed>
     */
    private function testAutoDelete(int $configId, string $op, int $seconds): array
    {
        $config = $configId > 0 ? $this->testConfigs->findForUser($configId, $this->userId()) : null;

        if ($config === null) {
            return ['ok' => false, 'message' => 'این کانفیگ در حساب شما نیست.'];
        }

        $patch = [];

        if ($op === 'on') {
            $patch = ['is_auto_delete' => 1, 'auto_delete_at' => max(0, (int) ($config['auto_delete_at'] ?? 0))];
        } elseif ($op === 'off') {
            $patch = ['is_auto_delete' => 0, 'auto_delete_at' => 0];
        } elseif ($op === 'clear') {
            $patch = ['is_auto_delete' => 0, 'auto_delete_at' => 0];
        } elseif ($op === 'set') {
            $seconds = max(60, min($seconds, 365 * 86400));

            $patch = [
                'is_auto_delete' => 1,
                'auto_delete_at' => time() + $seconds,
            ];
        } else {
            return ['ok' => false, 'message' => 'عملیات نامعتبر است.'];
        }

        $this->testConfigs->update($configId, $patch);

        $fresh = $this->testConfigs->find($configId);

        return [
            'message' => $this->stripEmoji(match ($op) {
                'on'    => 'حذف خودکار فعال شد.',
                'off'   => 'حذف خودکار غیرفعال شد.',
                'clear' => 'زمان حذف خودکار پاک شد.',
                default => 'زمان حذف خودکار تنظیم شد.',
            }),
            'config'  => $fresh !== null ? View::testConfig($fresh) : null,
        ];
    }

    // ------------------------------------------------------------------
    // فروشگاه و سفارش
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function shopList(string $kind): array
    {
        if (!$this->shopIsOpen()) {
            return ['ok' => false, 'items' => [], 'message' => '🛒 فروشگاه موقتاً بسته است.'];
        }

        if (!in_array($kind, PackageRepository::SHOP_KINDS, true)) {
            $kind = PackageRepository::KIND_AGENCY;
        }

        $items = [];

        foreach ($this->packages->activePackagesByKind($kind) as $package) {
            $items[] = View::package(
                $package,
                $this->packages->purchasedCount($this->userId(), (string) $package['kind'])
            );
        }

        return [
            'kind'        => $kind,
            'kind_label'  => PackageRepository::kindLabel($kind),
            'items'       => $items,
            'can_create'  => (new AgencyService($this->panelClient))->canCreatePanels(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shopPackage(int $packageId, int $days): array
    {
        if (!$this->shopIsOpen()) {
            return ['ok' => false, 'message' => '🛒 فروشگاه موقتاً بسته است.'];
        }

        $package = $this->activePackage($packageId);

        if ($package === null) {
            return ['ok' => false, 'message' => 'این بسته در دسترس نیست.'];
        }

        return $this->quoteFor($package, $days);
    }

    /**
     * پیش‌فاکتور بدون ساخت سفارش.
     *
     * دلیل جدا بودنش از `order.create`: کاربر باید **قبل** از ساخت سفارش،
     * تخفیف و مبلغ نهایی را ببیند. ساخت سفارش کد تخفیف را مصرف می‌کند، پس اگر
     * هر دو یکی بودند، هر بار که کسی قیمت را «بررسی» می‌کرد یک سفارش و یک
     * مصرف کد ساخته می‌شد.
     *
     * @return array<string, mixed>
     */
    private function shopQuote(int $packageId, int $days, int $panelId): array
    {
        if (!$this->shopIsOpen()) {
            return ['ok' => false, 'message' => '🛒 فروشگاه موقتاً بسته است.'];
        }

        $package = $this->activePackage($packageId);

        if ($package === null) {
            return ['ok' => false, 'message' => 'این بسته در دسترس نیست.'];
        }

        // انتخاب پنل در پیش‌فاکتور فقط برای بررسی مالکیت است (مثلاً کاربر ممکن
        // است پنلِ خودِ دیگری را دستکاری کند)؛ سفارش نهایی خودش دوباره
        // بررسی می‌کند.
        if ((string) $package['kind'] === PackageRepository::KIND_TOPUP && $panelId > 0 && $this->ownedPanel($panelId) === null) {
            return ['ok' => false, 'message' => 'این پنل در حساب شما نیست.'];
        }

        return $this->quoteFor($package, $days, $panelId);
    }

    /**
     * محاسبهٔ قیمت نهایی با تمام تخفیف‌ها.
     *
     * ⚠️ منطق این دقیقاً همان `Kernel::resolveDiscount()` + تخفیف وفاداری است.
     * اگر اینجا جدا نوشته شود، «قیمتی که کاربر می‌بیند» با «مبلغی که
     * درگاه می‌گیرد» یکی نمی‌شود و کاربر اضافه پرداخت می‌کند.
     *
     * @param  array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function quoteFor(array $package, int $days, int $panelId = 0): array
    {
        if ($days > 0) {
            foreach (PackageRepository::periodOptions($package) as $option) {
                if ((int) $option['duration_days'] === $days) {
                    $package['duration_days'] = (int) $option['duration_days'];
                    $package['price_toman']   = (int) $option['price_toman'];
                    break;
                }
            }
        }

        $listPrice  = (int) $package['price_toman'];
        $discount   = $this->resolveDiscount($listPrice);
        $finalPrice = max(0, $listPrice - $discount['discount']);

        $minOrder = Config::int('store.min_order_toman', 50000);

        $target = null;

        if ((string) $package['kind'] === PackageRepository::KIND_TOPUP) {
            if ($panelId > 0) {
                $target = $this->ownedPanel($panelId);
            } else {
                $target = $this->panels->primaryForUser($this->user);
            }

            if ($target === null) {
                return ['ok' => false, 'message' => 'برای شارژ باید یک پنل نمایندگی داشته باشید.'];
            }
        }

        $view = View::package($package, $this->packages->purchasedCount($this->userId(), (string) $package['kind']));

        return [
            'package' => $view,
            'panel'   => $target !== null ? View::panelCard($target, $this->graceDays()) : null,
            'quote'   => [
                'list'          => $listPrice,
                'discount'      => $discount['discount'],
                'final'         => $finalPrice,
                'list_text'     => Str::formatToman($listPrice),
                'discount_text' => Str::formatToman($discount['discount']),
                'final_text'    => Str::formatToman($finalPrice),
                'code'          => $discount['code'],
                'referral'      => $discount['referral'],
                'loyalty'       => $discount['loyalty'],
                'loyalty_points' => $discount['loyalty_points'],
                'below_minimum' => $finalPrice < $minOrder,
                'minimum'       => $minOrder,
                'minimum_text'  => Str::formatToman($minOrder),
            ],
            'coupon' => $this->users->couponCode($this->userId()),
        ];
    }

    /**
     * بهترین تخفیف قابل اعمال — همان قواعد Kernel.
     *
     * قاعده: کد تخفیف برنده است؛ پاداش معرفی فقط وقتی کدی در کار نباشد
     * (وگرنه دو تخفیف روی هم جمع می‌شد و بستهٔ گران عملاً رایگان می‌شد).
     * بعد از آن، تخفیف وفاداری روی مبلغ نهایی اعمال می‌شود.
     *
     * @return array{discount:int, code:string, coupon_id:int, referral:string, loyalty:int, loyalty_points:int}
     */
    private function resolveDiscount(int $listPrice): array
    {
        $none = [
            'discount'       => 0,
            'code'           => '',
            'coupon_id'      => 0,
            'referral'       => '',
            'loyalty'        => 0,
            'loyalty_points' => 0,
        ];

        $userId = $this->userId();
        $code   = $this->users->couponCode($userId);

        $resolved = null;

        if ($code !== '' && $this->flags->isCouponsEnabled()) {
            $quote = $this->discounts->quote($code, $userId, $listPrice);

            if ($quote['ok'] ?? false) {
                $bind  = $this->discounts->referrals()->findByReferee($userId);

                $resolved = [
                    'discount'  => (int) $quote['discount'],
                    'code'      => $code,
                    'coupon_id' => (int) $quote['coupon']['id'],
                    'referral'  => $bind !== null ? (string) $bind['code'] : '',
                ];
            } else {
                // کد بی‌اعتبار (منقضی/ظرفیت تمام) بی‌صدا دور می‌رود تا خرید
                // کاربر به‌خاطر کد خراب قفل نشود.
                $this->users->clearCouponCode($userId);
            }
        }

        if ($resolved === null) {
            $referral = $this->discounts->referralDiscount($userId, $listPrice);

            if ($referral['ok'] ?? false) {
                $bind = $this->discounts->referrals()->findByReferee($userId);

                $resolved = [
                    'discount'  => (int) $referral['discount'],
                    'code'      => '',
                    'coupon_id' => 0,
                    'referral'  => $bind !== null ? (string) $bind['code'] : '',
                ];
            }
        }

        $resolved = $resolved ?? $none;

        $percent = max(0, min(100, $this->settings->int(Settings::LOYALTY_DISCOUNT, 5)));
        $redeem  = max(1, $this->settings->int(Settings::LOYALTY_REDEEM, 100));
        $points  = (int) ($this->user['loyalty_points'] ?? 0);

        $final = max(0, $listPrice - $resolved['discount']);

        if ($percent > 0 && $points >= $redeem) {
            $loyalty = (int) floor($final * $percent / 100);

            if ($loyalty > 0) {
                $final                -= $loyalty;
                $resolved['discount'] += $loyalty;
                $resolved['loyalty']   = $loyalty;
                $resolved['loyalty_points'] = $redeem;
            }
        }

        $resolved['discount'] = min($resolved['discount'], $listPrice);

        return $resolved;
    }

    /**
     * ثبت سفارش.
     *
     * ترتیب کار دقیقاً مثل `Kernel::createOrder()`:
     *   ۱) بررسی فروشگاه/بسته
     *   ۲) مالکیت پنل هدف (برای شارژ) — قبل از هر محاسبه، چون سفارش با پنل
     *      غریبه هرگز نباید ساخته شود حتی اگر بعداً به خطا بخورد
     *   ۳) محاسبهٔ تخفیف (بیرون از تراکنش؛ فقط محاسبه است)
     *   ۴) حداقل مبلغ **بعد** از تخفیف
     *   ۵) تراکنش: سقف خرید + ساخت سفارش + مصرف کد
     *
     * @return array<string, mixed>
     */
    private function createOrder(int $packageId, int $days, int $panelId): array
    {
        if (!$this->shopIsOpen()) {
            throw new ShopException('🛒 فروشگاه موقتاً بسته است.');
        }

        $package = $this->activePackage($packageId);

        if ($package === null) {
            throw new ShopException('این بسته در دسترس نیست.');
        }

        if ($days > 0) {
            foreach (PackageRepository::periodOptions($package) as $option) {
                if ((int) $option['duration_days'] === $days) {
                    $package['duration_days'] = (int) $option['duration_days'];
                    $package['price_toman']   = (int) $option['price_toman'];
                    break;
                }
            }
        }

        $kind      = (string) $package['kind'];
        $minOrder  = Config::int('store.min_order_toman', 50000);
        $listPrice = (int) $package['price_toman'];

        $resolvedPanelId = 0;

        if ($kind === PackageRepository::KIND_TOPUP) {
            if ($panelId > 0) {
                if ($this->ownedPanel($panelId) === null) {
                    throw new ShopException('🚫 این پنل به حساب شما تعلق ندارد.');
                }

                $resolvedPanelId = $panelId;
            } else {
                $primary = $this->panels->primaryForUser($this->user);

                if ($primary === null) {
                    throw new ShopException('برای شارژ، اول باید یک پنل نمایندگی داشته باشید.');
                }

                $resolvedPanelId = (int) $primary['id'];
            }
        }

        $discount   = $this->resolveDiscount($listPrice);
        $finalPrice = max(0, $listPrice - $discount['discount']);

        if ($finalPrice < $minOrder) {
            throw new ShopException(
                'با این میزان تخفیف مبلغ سفارش از حداقل مجاز (' . Str::formatToman($minOrder) . ') کمتر می‌شود. '
                . 'کد تخفیف را حذف کنید یا بستهٔ بزرگ‌تری انتخاب کنید.'
            );
        }

        $order = null;

        // تراکنش لازم است: دو کلیک سریع روی «خرید» (که سرور دو درخواست جدا
        // می‌بیند) نباید هر دو از سقف خرید عبور کنند.
        $this->orders->transaction(function () use ($package, $resolvedPanelId, $discount, $listPrice, $finalPrice, $kind, &$order): void {
            $maxPerUser = (int) $package['max_per_user'];

            if ($maxPerUser > 0
                && $this->packages->purchasedCount($this->userId(), $kind) >= $maxPerUser) {
                throw new ShopException(
                    'شما حداکثر ' . Str::faNumber($maxPerUser) . ' بسته از این نوع خریده‌اید.'
                );
            }

            $order = $this->orders->create($this->userId(), [
                'package_id'           => (int) $package['id'],
                'package_title'        => (string) $package['title'],
                'kind'                 => $kind,
                'volume_gb'            => (float) $package['volume_gb'],
                'bonus_gb'             => (float) ($package['bonus_gb'] ?? 0),
                'duration_days'        => (int) $package['duration_days'],
                'max_users'            => max(0, (int) ($package['max_users'] ?? 0)),
                'price_toman'          => $finalPrice,
                'original_price_toman' => $listPrice,
                'discount_toman'       => (int) $discount['discount'],
                'coupon_code'          => $discount['code'] !== '' ? $discount['code'] : null,
                'referred_by'          => $discount['referral'] !== '' ? $discount['referral'] : null,
                'panel_id'             => $resolvedPanelId > 0 ? $resolvedPanelId : null,
                'status'               => OrderRepository::STATUS_CREATED,
            ]);

            // مصرف کد فقط حالا که سفارش واقعاً ساخته شد؛ وگرنه کدی که نتوانسته
            // استفاده شود، سوخته می‌خورد.
            if ((int) $discount['discount'] > 0 && $discount['coupon_id'] > 0) {
                $this->discounts->consumeCoupon(
                    (int) $discount['coupon_id'],
                    $this->userId(),
                    (int) $order['id'],
                    (int) $discount['discount']
                );

                $this->users->clearCouponCode($this->userId());
            }
        });

        if ($order === null) {
            return ['ok' => false, 'message' => 'ثبت سفارش ناموفق بود.'];
        }

        if ($discount['loyalty'] > 0) {
            $this->users->deductLoyaltyPoints($this->userId(), (int) $discount['loyalty_points']);
        }

        $this->users->refreshOrderStats($this->userId());

        $fresh = $this->users->findById($this->userId()) ?? $this->user;
        $this->user = $fresh;

        return [
            'message'  => 'سفارش ساخته شد.',
            'order'    => View::orderDetail($order, ['gateways' => $this->gateways()]),
            'wallet'   => $this->users->walletBalance($this->userId()),
            'loyalty'  => (int) ($fresh['loyalty_points'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gateways(): array
    {
        $out = [];

        foreach ($this->payments->activeGateways() as $name => $gateway) {
            if ($gateway instanceof PaymentGateway) {
                $out[] = View::gateway($name, $gateway->title(), $gateway->requiresReview());
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function ordersList(string $status): array
    {
        $status = $status !== '' ? $status : null;

        if ($status !== null && !in_array($status, [
            OrderRepository::STATUS_CREATED,
            OrderRepository::STATUS_AWAITING_PAYMENT,
            OrderRepository::STATUS_PAID,
            OrderRepository::STATUS_APPLYING,
            OrderRepository::STATUS_APPLIED,
            OrderRepository::STATUS_FAILED,
            OrderRepository::STATUS_CANCELLED,
            OrderRepository::STATUS_REFUNDED,
            OrderRepository::STATUS_REJECTED,
        ], true)) {
            $status = null;
        }

        $orders = $this->orders->listByUser($this->userId(), 30, 0, $status);

        $counts = [];
        foreach ([
            OrderRepository::STATUS_CREATED,
            OrderRepository::STATUS_AWAITING_PAYMENT,
            OrderRepository::STATUS_APPLIED,
            OrderRepository::STATUS_FAILED,
        ] as $key) {
            $counts[$key] = $this->orders->countByUser($this->userId(), $key);
        }

        return [
            'items'  => array_map([View::class, 'order'], $orders),
            'counts' => $counts,
            'total'  => $this->orders->countByUser($this->userId()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderDetail(int $orderId): array
    {
        $order = $orderId > 0 ? $this->orders->findForUser($orderId, $this->userId()) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'این سفارش در حساب شما نیست.'];
        }

        $invoiceUrl = null;

        if (Invoice::isPayable($order)) {
            $order    = Invoice::ensureToken($this->orders, $order);
            $invoiceUrl = Invoice::url($order);
        }

        $panel = null;

        if (!empty($order['panel_id'])) {
            $panelRow = $this->ownedPanel((int) $order['panel_id']);

            if ($panelRow !== null) {
                $panel = View::panelCard($panelRow, $this->graceDays());
            }
        }

        return [
            'order' => View::orderDetail($order, ['invoice_url' => $invoiceUrl]),
            'panel' => $panel,
            'gateways' => $this->gateways(),
            'provision_logs' => array_map(
                static fn (array $log): array => [
                    'id'      => (int) $log['id'],
                    'status'  => (string) $log['status'],
                    'message' => Str::truncate((string) ($log['message'] ?? ''), 240),
                    'text'    => Str::date((int) $log['created_at']),
                ],
                $this->orders->provisionLogs((int) $order['id'], 5)
            ),
        ];
    }

    /**
     * شروع پرداخت یک سفارش.
     *
     * @return array<string, mixed>
     */
    private function startPayment(int $orderId, string $method): array
    {
        $order = $orderId > 0 ? $this->orders->findForUser($orderId, $this->userId()) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'این سفارش در حساب شما نیست.'];
        }

        $result = $this->payments->startPayment($order, $method, $this->telegramId);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $result['message'])];
        }

        $fresh = $this->orders->find((int) $order['id']) ?? $order;

        return [
            'message'      => $this->stripEmoji((string) ($result['message'] ?? '')),
            'instructions' => isset($result['instructions'])
                ? $this->stripEmoji((string) $result['instructions'])
                : null,
            'pay_url'      => View::safeUrl($result['pay_url'] ?? null),
            'requires_review' => (bool) ($result['requires_review'] ?? false),
            'amount'       => (int) ($result['amount_toman'] ?? (int) $fresh['price_toman']),
            'amount_text'  => Str::formatToman((int) ($result['amount_toman'] ?? (int) $fresh['price_toman'])),
            'order'        => View::orderDetail($fresh),
        ];
    }

    /**
     * بررسی وضعیت پرداخت (و اجرای بسته اگر تأیید شد).
     *
     * @return array<string, mixed>
     */
    private function checkOrder(int $orderId): array
    {
        $order = $orderId > 0 ? $this->orders->findForUser($orderId, $this->userId()) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'این سفارش در حساب شما نیست.'];
        }

        $result = $this->payments->checkAndMaybeApply($order);
        $fresh  = $this->orders->find($orderId) ?? $order;

        $this->user = $this->users->findById($this->userId()) ?? $this->user;

        return [
            'paid'    => (bool) ($result['paid'] ?? false),
            'message' => $this->stripEmoji((string) ($result['message'] ?? '')),
            'order'   => View::orderDetail($fresh),
        ];
    }

    /**
     * ثبت رسید کارت‌به‌کارت.
     *
     * دو مسیر ورودی پذیرفته می‌شود:
     *   • `file_id` — اگر کلاینت somehow یک file_id معتبر دارد
     *   • `data` — تصویر base64 که از `input[type=file]` خوانده شده
     *
     * چرا مسیر دوم لازم است؟ Bot API تلگرام **فایل را از راه دور نمی‌گیرد**
     * (فقط file_id یا URL). برای اینکه مینی‌اپ بتواند عکس رسید را بفرستد،
     * سرور باید اول آن را جایی برای تلگرام داشته باشد. راه‌حل استاندارد و
     * بدون سرور واسط: فایل روی **چت خودِ کاربر** آپلود می‌شود، file_id از
     * پاسخ گرفته و بعد پیام پاک می‌شود تا چت کاربر شلوغ نشود.
     *
     * @return array<string, mixed>
     */
    private function submitReceipt(int $orderId, string $fileId, string $data): array
    {
        $order = $orderId > 0 ? $this->orders->findForUser($orderId, $this->userId()) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'این سفارش در حساب شما نیست.'];
        }

        $fileId = trim($fileId);

        if ($fileId === '') {
            $fileId = $this->uploadReceipt($data);
        }

        if ($fileId === '') {
            return ['ok' => false, 'message' => 'تصویر رسید ارسال نشد.'];
        }

        $result = $this->payments->submitReceipt($order, $fileId);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $result['message'])];
        }

        $fresh = $this->orders->find($orderId) ?? $order;

        return [
            'message' => $this->stripEmoji((string) $result['message']),
            'order'   => View::orderDetail($fresh),
        ];
    }

    /**
     * لینک فاکتور قابل چاپ.
     *
     * @return array<string, mixed>
     */
    private function orderInvoice(int $orderId): array
    {
        $order = $orderId > 0 ? $this->orders->findForUser($orderId, $this->userId()) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'این سفارش در حساب شما نیست.'];
        }

        if (!Invoice::isPayable($order)) {
            return ['ok' => false, 'message' => 'فاکتور فقط برای سفارش‌های پرداخت‌شده صادر می‌شود.'];
        }

        $order = Invoice::ensureToken($this->orders, $order);
        $url   = Invoice::url($order);

        if ($url === '') {
            return ['ok' => false, 'message' => 'لینک فاکتور در دسترس نیست (base_url تنظیم نشده است).'];
        }

        return [
            'url'  => $url,
            'text' => Invoice::telegramText($order),
        ];
    }

    /**
     * آپلود موقتی تصویر رسید روی چت کاربر و گرفتن `file_id`.
     *
     * نکته‌های مهم این مسیر:
     *   • حجم محدود می‌شود (۵MB) چون `post_max_size` و بدنهٔ PHP هر دو
     *     محدودند و فایل بزرگ‌تر یعنی خطای بی‌صدا.
     *   • فقط نوع‌های تصویری پذیرفته می‌شوند؛ فایل دلخواه یعنی می‌توانست
     *     هر بایتی را به‌عنوان «رسید» ثبت کرد.
     *   • پیام بعد از گرفتن file_id حذف می‌شود تا چت کاربر دست‌نخورده بماند.
     *
     * @return string file_id یا رشتهٔ خالی در صورت شکست
     */
    private function uploadReceipt(string $data): string
    {
        $data = trim($data);

        if ($data === '') {
            return '';
        }

        // کلاینت `data:image/jpeg;base64,…` می‌فرستد؛ فقط بخش base64 لازم است.
        if (str_starts_with($data, 'data:')) {
            $comma = strpos($data, ',');

            if ($comma === false) {
                return '';
            }

            $data = substr($data, $comma + 1);
        }

        $binary = base64_decode($data, true);

        if ($binary === false || $binary === '') {
            return '';
        }

        if (strlen($binary) > 5 * 1024 * 1024) {
            return '';
        }

        // تشخیص نوع واقعی فایل (نه پسوند فرستاده‌شده که کلاینت تعیین می‌کند).
        $info = @getimagesizefromstring($binary);

        if (!is_array($info) || empty($info['mime'])) {
            return '';
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $mime    = (string) $info['mime'];

        if (!in_array($mime, $allowed, true)) {
            return '';
        }

        $bot       = $this->requireBot();
        $extension = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');

        // فایل موقت داخل پوشهٔ data (که با .htaccess از دسترس وب خارج است)؛
        // نه /tmp که ممکن است روی سرور قابل خواندن باشد.
        $dir = PASARGAD_ROOT . '/data/tmp';

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return '';
        }

        $path = $dir . '/receipt_' . bin2hex(random_bytes(8)) . '.' . $extension;

        if (@file_put_contents($path, $binary) === false) {
            return '';
        }

        try {
            $result = $bot->sendPhoto($this->telegramId, $path, '📸 رسید سفارش');
        } finally {
            @unlink($path);
        }

        if (!($result['ok'] ?? false)) {
            Logger::warning('Receipt upload to telegram failed', [
                'user_id' => $this->telegramId,
                'error'   => (string) ($result['description'] ?? 'unknown'),
            ]);

            return '';
        }

        $message = (array) ($result['result'] ?? []);

        // بزرگ‌ترین اندازه = بهترین کیفیت برای مدیری که باید رقم رسید را بخواند.
        $sizes = (array) ($message['photo'] ?? []);
        $last  = end($sizes);

        $fileId = is_array($last) ? (string) ($last['file_id'] ?? '') : '';

        if ($fileId !== '') {
            // پیام موقتی را پاک کن: کاربر نباید رسید را در چت خودش ببیند،
            // چون برای مدیریت فرستاده شده است.
            $messageId = (int) ($message['message_id'] ?? 0);

            if ($messageId > 0) {
                $bot->deleteMessage($this->telegramId, $messageId);
            }
        }

        return $fileId;
    }

    // ------------------------------------------------------------------
    // کیف پول، پرداخت‌ها، تخفیف، معرفی
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function wallet(): array
    {
        $balance = $this->users->walletBalance($this->userId());
        $txns    = $this->users->walletHistory($this->userId(), 20);

        return [
            'balance'      => $balance,
            'balance_text' => Str::formatToman($balance),
            'items'        => array_map([View::class, 'walletTxn'], $txns),
            'support_link' => View::safeUrl(Config::str('notifications.support_link', '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentsHistory(): array
    {
        $payments = $this->orders->paymentsForUser($this->userId(), 30);

        return [
            'items'  => array_map([View::class, 'payment'], $payments),
            'total'  => count($payments),
            'paid'   => array_sum(array_map(
                static fn (array $p): int => (string) ($p['status'] ?? '') === 'confirmed'
                    ? (int) ($p['amount_toman'] ?? 0)
                    : 0,
                $payments
            )),
        ];
    }

    /**
     * اعمال کد تخفیف.
     *
     * اگر `price` داده شود، تخفیف همان‌جا محاسبه و نمایش داده می‌شود (بدون
     * ساخت سفارش) تا کاربر قبل از خرید بداند چقدر کم می‌شود.
     *
     * @return array<string, mixed>
     */
    private function couponApply(string $code, int $price): array
    {
        if (!$this->flags->isCouponsEnabled()) {
            return ['ok' => false, 'message' => 'کد تخفیف موقتاً غیرفعال است.'];
        }

        $code = strtoupper(trim($code));

        if ($code === '' || mb_strlen($code) > 32) {
            return ['ok' => false, 'message' => 'کد تخفیف را وارد کنید.'];
        }

        $quote = $this->discounts->quote($code, $this->userId(), max(0, $price));

        if (!($quote['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $quote['message'])];
        }

        $this->users->setCouponCode($this->userId(), $code);

        $discount = (int) $quote['discount'];

        return [
            'message'       => 'کد تخفیف اعمال شد.',
            'code'          => $code,
            'discount'      => $discount,
            'discount_text' => Str::formatToman($discount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function couponClear(): array
    {
        $cleared = $this->users->clearCouponCode($this->userId());

        return [
            'message' => $cleared ? 'کد تخفیف حذف شد.' : 'کد تخفیف فعالی نداشتید.',
            'code'    => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function referral(): array
    {
        $summary = $this->discounts->referralSummary($this->userId());

        $invited = [];

        foreach ($this->discounts->referrals()->listByReferrer($this->userId(), 25) as $row) {
            $invited[] = [
                'name'    => trim((string) ($row['first_name'] ?? '')) !== ''
                    ? Str::truncate((string) $row['first_name'], 40)
                    : 'کاربر',
                'telegram_id' => (int) ($row['telegram_id'] ?? 0),
                'bonus'   => (int) ($row['bonus_toman'] ?? 0),
                'bonus_text' => Str::formatToman((int) ($row['bonus_toman'] ?? 0)),
                'rewarded' => $row['rewarded_at'] !== null,
                'rewarded_text' => $row['rewarded_at'] === null ? 'در انتظار خرید' : Str::date((int) $row['rewarded_at']),
                'at'      => (int) $row['created_at'],
            ];
        }

        $link = $this->referralLink($summary['code']);

        return [
            'code'          => $summary['code'],
            'link'          => $link,
            'invited'       => (int) $summary['invited'],
            'rewarded'      => (int) $summary['rewarded'],
            'bonus_each'    => (int) $summary['bonus_each'],
            'bonus_each_text' => Str::formatToman((int) $summary['bonus_each']),
            'discount_percent' => max(0, min(100, $this->settings->int(Settings::REFERRAL_DISCOUNT, 10))),
            'level2_bonus'  => max(0, $this->settings->int(Settings::REFERRAL_BONUS_LEVEL2, 25000)),
            'items'         => $invited,
            'enabled'       => $this->flags->isReferralEnabled(),
        ];
    }

    /**
     * لینک دعوت؛ اگر نام‌کاربری ربات معلوم باشد، لینک مستقیم مینی‌اپ ساخته
     * می‌شود (`startapp=CODE`) تا کاربر با یک کلیک داخل اپ بیفتد.
     */
    private function referralLink(string $code): string
    {
        $bot = ltrim(Config::str('bot_username', ''), '@');

        if ($bot === '') {
            return '';
        }

        $direct = 'https://t.me/' . $bot . '/?startapp=' . rawurlencode($code);
        $botLink = 'https://t.me/' . $bot . '?start=' . rawurlencode($code);

        // لینک مستقیم فقط وقتی برگردانده می‌شود که دامنهٔ عمومی تنظیم شده باشد،
        // وگرنه تلگرام آن را باز نمی‌کند چون Mini App روی HTTPS باید باشد.
        $base = rtrim(trim(Config::str('base_url', '')), '/');

        if ($base === '' || !str_starts_with($base, 'https://')) {
            return $botLink;
        }

        return $direct;
    }

    /**
     * @return array<string, mixed>
     */
    private function referralBind(string $code): array
    {
        if (!$this->flags->isReferralEnabled()) {
            return ['ok' => false, 'message' => 'سیستم معرفی موقتاً غیرفعال است.'];
        }

        $code = strtoupper(trim($code));

        if ($code === '') {
            return ['ok' => false, 'message' => 'کد معرفی را وارد کنید.'];
        }

        // `/start ref_CODE` پیشوند ref_ را هم می‌پذیرد.
        $code = preg_replace('/^REF_/', '', $code) ?? $code;

        $referrer = $this->discounts->referrals()->findReferrerByCode($code);

        if ($referrer === null) {
            return ['ok' => false, 'message' => 'کد معرفی نامعتبر است.'];
        }

        $bonus = max(0, $this->settings->int(Settings::REFERRAL_BONUS, 50000));

        $result = $this->discounts->referrals()->bind(
            (int) $referrer['id'],
            $this->userId(),
            $bonus
        );

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $result['message'])];
        }

        $percent = max(0, min(100, $this->settings->int(Settings::REFERRAL_DISCOUNT, 10)));

        return [
            'message' => 'کد معرفی ثبت شد! اولین خرید شما '
                . Str::faNumber($percent) . '٪ ارزان‌تر است.',
            'percent' => $percent,
        ];
    }

    // ------------------------------------------------------------------
    // پشتیبانی
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function ticketsList(): array
    {
        if (!$this->flags->isTicketsEnabled()) {
            return ['ok' => false, 'items' => [], 'message' => 'پشتیبانی تیکتی موقتاً غیرفعال است.'];
        }

        return [
            'items' => array_map([View::class, 'ticket'], $this->tickets->listByUser($this->userId(), 30)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketCreate(string $category, string $body): array
    {
        if (!$this->flags->isTicketsEnabled()) {
            return ['ok' => false, 'message' => 'پشتیبانی تیکتی موقتاً غیرفعال است.'];
        }

        $body = trim($body);

        if (mb_strlen($body) < 3) {
            return ['ok' => false, 'message' => 'متن پیام خیلی کوتاه است.'];
        }

        if (mb_strlen($body) > 4000) {
            return ['ok' => false, 'message' => 'متن پیام بیش از حد بلند است.'];
        }

        if (!in_array($category, TicketRepository::CATEGORY_KEYS, true)) {
            $category = 'other';
        }

        $ticketId = $this->tickets->create($this->userId(), $category, $body);

        // نمایندهٔ پشتیبانی باید همان لحظه باخبر شود؛ وگرنه تیکه بدون
        // اطلاع در صف می‌ماند.
        try {
            (new Notifier($this->requireBot()))->notifyAdmins(
                '🎫 تیکت جدید از ' . Str::escape((string) ($this->user['first_name'] ?? 'کاربر')) . "\n"
                . 'دسته: ' . Str::escape(TicketRepository::categoryLabel($category)) . "\n"
                . Str::escape(Str::truncate($body, 220))
            );
        } catch (\Throwable $e) {
            Logger::warning('Could not notify admins about new ticket', ['error' => $e->getMessage()]);
        }

        $ticket = $this->tickets->find($ticketId);

        return [
            'message' => 'تیکت شما ثبت شد.',
            'ticket'  => $ticket !== null ? View::ticket($ticket) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketDetail(int $ticketId): array
    {
        $ticket = $ticketId > 0 ? $this->tickets->findForUser($ticketId, $this->userId()) : null;

        if ($ticket === null) {
            return ['ok' => false, 'message' => 'این تیکت در حساب شما نیست.'];
        }

        return [
            'ticket'   => View::ticket($ticket),
            'messages' => View::ticketMessages($this->tickets->messages($ticketId, 100)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketReply(int $ticketId, string $body): array
    {
        if (!$this->flags->isTicketsEnabled()) {
            return ['ok' => false, 'message' => 'پشتیبانی تیکتی موقتاً غیرفعال است.'];
        }

        $ticket = $ticketId > 0 ? $this->tickets->findForUser($ticketId, $this->userId()) : null;

        if ($ticket === null) {
            return ['ok' => false, 'message' => 'این تیکت در حساب شما نیست.'];
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 4000) {
            return ['ok' => false, 'message' => 'متن پیام نامعتبر است.'];
        }

        if (!$this->tickets->replyAsUser($ticketId, $body)) {
            return ['ok' => false, 'message' => 'ثبت پیام ناموفق بود.'];
        }

        $fresh = $this->tickets->find($ticketId);

        return [
            'message'  => 'پیام شما ثبت شد.',
            'ticket'   => $fresh !== null ? View::ticket($fresh) : null,
            'messages' => View::ticketMessages($this->tickets->messages($ticketId, 100)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketClose(int $ticketId): array
    {
        $ticket = $ticketId > 0 ? $this->tickets->findForUser($ticketId, $this->userId()) : null;

        if ($ticket === null) {
            return ['ok' => false, 'message' => 'این تیکت در حساب شما نیست.'];
        }

        $this->tickets->close($ticketId);

        $fresh = $this->tickets->find($ticketId);

        return [
            'message' => 'تیکت بسته شد.',
            'ticket'  => $fresh !== null ? View::ticket($fresh) : null,
        ];
    }

    // ==================================================================
    //  پنل مدیریت
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function adminOverview(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $orders  = $this->orders->stats();
        $tickets = count($this->tickets->listOpen(1));

        return [
            'users'   => $this->users->countAll(),
            'panels'  => $this->panels->countAll(),
            'orders'  => $orders,
            'tickets' => ['open' => $tickets],
            'coupons' => ['active' => $this->discounts->coupons()->countActive(), 'total' => $this->discounts->coupons()->countAll()],
            'gateway_statuses' => $this->flags->gatewayStatuses(),
            'risk'    => array_map(
                static fn (array $row): array => [
                    'id'        => (int) $row['id'],
                    'name'      => (string) ($row['first_name'] ?? 'کاربر'),
                    'telegram_id' => (int) ($row['telegram_id'] ?? 0),
                    'panels'    => (int) ($row['panel_count'] ?? 0),
                    'expired'   => (int) ($row['expired_count'] ?? 0),
                    'soonest'   => Str::date(isset($row['soonest_expire']) && $row['soonest_expire'] !== null
                        ? (int) $row['soonest_expire']
                        : null),
                ],
                $this->users->listAtRiskRepresentatives(10)
            ),
            'audit' => array_map(
                static fn (array $row): array => [
                    'id'      => (int) $row['id'],
                    'action'  => (string) $row['action'],
                    'target'  => (string) $row['target_type'] . '#' . (int) $row['target_id'],
                    'details' => Str::truncate((string) ($row['details'] ?? ''), 120),
                    'text'    => Str::date((int) $row['created_at']),
                ],
                $this->audit->latest(8)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminOrders(string $status, int $page): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $limit  = 20;
        $page   = max(0, $page);
        $status = $status !== '' ? $status : null;

        $rows = $this->orders->listAll($limit, $page * $limit, $status);
        $total = $this->orders->countAll($status);

        $items = [];

        foreach ($rows as $row) {
            $view = View::order($row);
            $view['customer'] = trim((string) ($row['first_name'] ?? '')) !== ''
                ? Str::truncate((string) $row['first_name'], 40)
                : 'کاربر';
            $view['telegram_id'] = (int) ($row['telegram_id'] ?? 0);
            $view['needs_review'] = (string) ($row['status'] ?? '') === OrderRepository::STATUS_AWAITING_PAYMENT
                && trim((string) ($row['receipt_file_id'] ?? '')) !== '';

            $items[] = $view;
        }

        return [
            'items'  => $items,
            'total'  => $total,
            'page'   => $page,
            'pages'  => max(1, (int) ceil($total / $limit)),
            'stats'  => $this->orders->stats(),
            'counts' => [
                OrderRepository::STATUS_AWAITING_PAYMENT => $this->orders->countAll(OrderRepository::STATUS_AWAITING_PAYMENT),
                OrderRepository::STATUS_PAID    => $this->orders->countAll(OrderRepository::STATUS_PAID),
                OrderRepository::STATUS_FAILED  => $this->orders->countAll(OrderRepository::STATUS_FAILED),
                OrderRepository::STATUS_APPLIED => $this->orders->countAll(OrderRepository::STATUS_APPLIED),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminOrder(int $orderId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $order = $orderId > 0 ? $this->orders->find($orderId) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }

        $user = $this->users->findById((int) $order['user_id']);

        $payments = [];

        foreach ($this->orders->paymentsForOrder((int) $order['id']) as $payment) {
            $payments[] = View::payment($payment);
        }

        return [
            'order' => View::orderDetail($order),
            'customer' => [
                'id'           => (int) ($user['id'] ?? 0),
                'telegram_id'  => (int) ($user['telegram_id'] ?? 0),
                'name'         => (string) ($user['first_name'] ?? ''),
                'username'     => (string) ($user['username'] ?? ''),
                'wallet'       => (int) ($user['wallet_balance'] ?? 0),
                'wallet_text'  => Str::formatToman((int) ($user['wallet_balance'] ?? 0)),
                'loyalty'      => (int) ($user['loyalty_points'] ?? 0),
                'blocked'      => (int) ($user['is_blocked'] ?? 0) === 1,
                'orders_count' => (int) ($user['orders_count'] ?? 0),
                'total_paid'   => (int) ($user['total_paid'] ?? 0),
            ],
            'payments' => $payments,
            'can_review' => (string) ($order['status'] ?? '') === OrderRepository::STATUS_AWAITING_PAYMENT,
            'can_retry'  => in_array((string) ($order['status'] ?? ''), [
                OrderRepository::STATUS_PAID,
                OrderRepository::STATUS_FAILED,
            ], true),
            'logs' => array_map(
                static fn (array $log): array => [
                    'id'      => (int) $log['id'],
                    'status'  => (string) $log['status'],
                    'message' => Str::truncate((string) ($log['message'] ?? ''), 240),
                    'text'    => Str::date((int) $log['created_at']),
                ],
                $this->orders->provisionLogs((int) $order['id'], 10)
            ),
        ];
    }

    /**
     * تأیید یا رد دستی پرداخت کارت‌به‌کارت.
     *
     * @return array<string, mixed>
     */
    private function adminReviewOrder(int $orderId, bool $approved, string $note): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $order = $orderId > 0 ? $this->orders->find($orderId) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }

        // 🔒 تأیید از مسیر «تاریخ پرداخت» محافظت می‌شود: سفارشِ بدون رسید یا
        // با روش غیرکارتی نباید با یک کلیک «تأیید» بسته بگیرد.
        if ($approved
            && trim((string) ($order['receipt_file_id'] ?? '')) === ''
            && (string) ($order['payment_method'] ?? '') !== CardToCardGateway::NAME) {
            return ['ok' => false, 'message' => 'این سفارش رسیدی برای بررسی ندارد.'];
        }

        $result = $this->payments->reviewOrder($order, $approved, $this->telegramId, trim($note));

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $result['message'])];
        }

        $fresh = $this->orders->find($orderId) ?? $order;

        return [
            'message' => $this->stripEmoji((string) $result['message']),
            'order'   => View::orderDetail($fresh),
        ];
    }

    /**
     * اجرای دستی سفارش پرداخت‌شده.
     *
     * @return array<string, mixed>
     */
    private function adminRetryOrder(int $orderId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $order = $orderId > 0 ? $this->orders->find($orderId) : null;

        if ($order === null) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }

        if ($order['paid_at'] === null) {
            return ['ok' => false, 'message' => 'این سفارش پرداخت‌شده نیست؛ اجرای آن یعنی بستهٔ رایگان.'];
        }

        $result = $this->provisioner->provision($order);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $result['message'])];
        }

        $this->audit->log($this->telegramId, 'provision_order', 'order', (int) $order['id'], 'اجرای دستی از مینی‌اپ');

        $fresh = $this->orders->find($orderId) ?? $order;

        return [
            'message' => $this->stripEmoji((string) $result['message']),
            'order'   => View::orderDetail($fresh),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminUsers(string $query, int $page): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $limit = 25;
        $page  = max(0, $page);
        $query = trim($query);

        $rows  = $this->users->listAll($limit, $page * $limit, $query);
        $total = $this->users->countAll($query);

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id'           => (int) $row['id'],
                'telegram_id'  => (int) $row['telegram_id'],
                'name'         => (string) ($row['first_name'] ?? ''),
                'username'     => (string) ($row['username'] ?? ''),
                'wallet'       => (int) $row['wallet_balance'],
                'wallet_text'  => Str::formatToman((int) $row['wallet_balance']),
                'loyalty'      => (int) $row['loyalty_points'],
                'orders'       => (int) $row['orders_count'],
                'total_paid'   => (int) $row['total_paid'],
                'panels'       => $this->panels->countByUser((int) $row['id']),
                'blocked'      => (int) $row['is_blocked'] === 1,
                'blocked_reason' => (string) ($row['blocked_reason'] ?? ''),
                'last_seen'    => Str::date(isset($row['last_seen_at']) ? (int) $row['last_seen_at'] : null),
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminUser(int $userId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $user = $userId > 0 ? $this->users->findById($userId) : null;

        if ($user === null) {
            return ['ok' => false, 'message' => 'کاربر یافت نشد.'];
        }

        return [
            'user' => [
                'id'          => (int) $user['id'],
                'telegram_id' => (int) $user['telegram_id'],
                'name'        => (string) ($user['first_name'] ?? ''),
                'username'    => (string) ($user['username'] ?? ''),
                'wallet'      => (int) $user['wallet_balance'],
                'wallet_text' => Str::formatToman((int) $user['wallet_balance']),
                'loyalty'     => (int) $user['loyalty_points'],
                'orders'      => (int) $user['orders_count'],
                'total_paid'  => (int) $user['total_paid'],
                'blocked'     => (int) $user['is_blocked'] === 1,
                'reason'      => (string) ($user['blocked_reason'] ?? ''),
                'coupon'      => $this->users->couponCode((int) $user['id']),
                'joined'      => Str::date((int) $user['created_at']),
                'last_seen'   => Str::date(isset($user['last_seen_at']) ? (int) $user['last_seen_at'] : null),
            ],
            'panels' => View::panelCards($this->panels->listByUser((int) $user['id']), $this->graceDays()),
            'wallet_txns' => array_map([View::class, 'walletTxn'], $this->users->walletHistory((int) $user['id'], 20)),
            'orders' => array_map([View::class, 'order'], $this->orders->listByUser((int) $user['id'], 10)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminBlockUser(int $userId, bool $blocked, string $reason): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $user = $userId > 0 ? $this->users->findById($userId) : null;

        if ($user === null) {
            return ['ok' => false, 'message' => 'کاربر یافت نشد.'];
        }

        // 🚫 بلاک کردن خودِ مدیر یعنی قفل کردن ربات برای همیشه: همهٔ
        // عملیات مدیریت از `super_admins` می‌آید و راه بازگشتی هم نیست.
        if ($blocked && (int) $user['telegram_id'] === $this->telegramId) {
            return ['ok' => false, 'message' => 'نمی‌توانید حساب خودتان را بلاک کنید.'];
        }

        $this->users->setBlocked((int) $user['id'], $blocked, $reason);

        $this->audit->log(
            $this->telegramId,
            $blocked ? 'block_user' : 'unblock_user',
            'user',
            (int) $user['id'],
            $reason
        );

        return ['message' => $blocked ? 'کاربر مسدود شد.' : 'مسدودی کاربر برداشته شد.'];
    }

    /**
     * شارژ/کسر کیف پول کاربر.
     *
     * سقف ۱ میلیارد تومان: بدون سقف، یک تایپ اشتباه (مثلاً ۱۰۰ میلیون به‌جای
     * ۱۰۰ هزار) یک فوری مالی واقعی است و بازگرداندنش فقط با دسترسی مستقیم به
     * دیتابیس ممکن است.
     *
     * @return array<string, mixed>
     */
    private function adminAdjustWallet(int $userId, int $amount, string $note): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        if ($userId <= 0 || $amount === 0) {
            return ['ok' => false, 'message' => 'مبلغ نامعتبر است.'];
        }

        if (abs($amount) > 1_000_000_000) {
            return ['ok' => false, 'message' => 'مبلغ خارج از محدودهٔ مجاز است.'];
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            return ['ok' => false, 'message' => 'کاربر یافت نشد.'];
        }

        $result = $this->users->adjustWallet($userId, $amount, $note, $this->telegramId, 'manual');

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) $result['message']];
        }

        $this->audit->log($this->telegramId, 'adjust_wallet', 'user', $userId, $amount . ' — ' . $note);

        try {
            (new Notifier($this->requireBot()))->notifyUser(
                (int) $user['telegram_id'],
                $amount > 0
                    ? '💰 کیف پول شما ' . Str::formatToman($amount) . ' شارژ شد.'
                    : '💸 ' . Str::formatToman(abs($amount)) . ' از کیف پول شما کسر شد.'
            );
        } catch (\Throwable $e) {
            Logger::warning('Could not notify user about wallet change', ['error' => $e->getMessage()]);
        }

        return [
            'message'      => (string) $result['message'],
            'balance'      => (int) $result['balance'],
            'balance_text' => Str::formatToman((int) $result['balance']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPanels(string $query, int $page): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $limit = 25;
        $page  = max(0, $page);
        $query = trim($query);

        $rows  = $this->panels->listAll($limit, $page * $limit, $query);
        $total = $this->panels->countAll($query);

        $items = [];

        foreach ($rows as $row) {
            $card = View::panelCard($row, $this->graceDays());
            $card['owner_id'] = (int) $row['user_id'];
            $card['owner_telegram_id'] = (int) ($row['telegram_id'] ?? 0);

            $items[] = $card;
        }

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPanelSync(int $panelId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $panel = $panelId > 0 ? $this->panels->find($panelId) : null;

        if ($panel === null) {
            return ['ok' => false, 'message' => 'پنل یافت نشد.'];
        }

        $result = $this->syncer->syncOne($panel);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => $this->stripEmoji((string) $result['message'])];
        }

        $fresh = $this->panels->find($panelId) ?? $panel;

        return [
            'message' => $this->stripEmoji((string) $result['message']),
            'panel'   => View::panelDetail($fresh, $this->panels->plainPassword($fresh), $this->graceDays()),
        ];
    }

    /**
     * قطع دسترسی کاربران یک پنل منقضی‌شده.
     *
     * عملیات برگشت‌پذیر نیست، پس عمداً **پیش‌نمایش** می‌خواهد: کلاینت اول
     * فهرست را نشان می‌دهد و اجرا فقط با تأیید صریح انجام می‌شود.
     *
     * @return array<string, mixed>
     */
    private function adminCutoff(int $panelId, bool $confirm): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $panel = $panelId > 0 ? $this->panels->find($panelId) : null;

        if ($panel === null) {
            return ['ok' => false, 'message' => 'پنل یافت نشد.'];
        }

        $cutoff = new AccessCutoff($this->panels, $this->requirePanelClient());

        // بدون `confirm` فقط پیش‌نمایش است — عملیات برگشت‌پذیر نیست.
        if (!$confirm) {
            $preview = $cutoff->listPanelUsers($panel, 20);

            if (!($preview['ok'] ?? false)) {
                return ['ok' => false, 'message' => $this->stripEmoji((string) $preview['message'])];
            }

            $users = array_map(
                static fn (array $u): array => [
                    'username' => (string) ($u['username'] ?? ''),
                    'status'   => (string) ($u['status'] ?? ''),
                ],
                array_slice((array) ($preview['users'] ?? []), 0, 20)
            );

            return [
                'preview' => true,
                'users'   => $users,
                'count'   => count($users),
                'panel'   => View::panelCard($panel, $this->graceDays()),
            ];
        }

        $result = $cutoff->cutoff($panel);

        $this->audit->log($this->telegramId, 'cutoff_panel', 'panel', $panelId, 'قطع دسترسی از مینی‌اپ');

        return [
            'message' => ($result['ok'] ?? false)
                ? 'قطع دسترسی انجام شد: ' . (string) ($result['message'] ?? '')
                : 'قطع دسترسی ناموفق بود.',
            'ok'      => (bool) ($result['ok'] ?? false),
            'details' => $result['details'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPackages(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $items = [];

        foreach ($this->packages->allPackages() as $package) {
            // شمارش خرید در نمای مدیریت معنا ندارد (کاربرِ مدیر خریده نیست)،
            // پس صفر داده می‌شود.
            $view = View::package($package, 0);

            $view['is_active']   = (int) $package['is_active'] === 1;
            $view['sort_order']  = (int) $package['sort_order'];
            $view['slug']        = (string) $package['slug'];

            $items[] = $view;
        }

        return ['items' => $items];
    }

    /**
     * ذخیرهٔ بسته (ایجاد یا ویرایش).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function adminSavePackage(array $input): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $id   = $this->intInput($input, 'id');
        $data = [
            'title'         => trim((string) ($input['title'] ?? '')),
            'description'   => trim((string) ($input['description'] ?? '')),
            'kind'          => PackageRepository::normalizeKind((string) ($input['kind'] ?? PackageRepository::KIND_AGENCY)),
            'volume_gb'     => $this->floatInput($input, 'volume_gb'),
            'bonus_gb'      => $this->floatInput($input, 'bonus_gb'),
            'duration_days' => max(1, $this->intInput($input, 'duration_days')),
            'price_toman'   => $this->intInput($input, 'price_toman'),
            'max_per_user'  => $this->intInput($input, 'max_per_user'),
            'max_users'     => $this->intInput($input, 'max_users'),
            'sort_order'    => $this->intInput($input, 'sort_order'),
            'is_active'     => $this->boolInput($input, 'is_active') ? 1 : 0,
            'prices'        => trim((string) ($input['prices'] ?? '')),
        ];

        if ($data['title'] === '') {
            return ['ok' => false, 'message' => 'عنوان بسته لازم است.'];
        }

        if ($data['price_toman'] <= 0) {
            return ['ok' => false, 'message' => 'قیمت باید بزرگ‌تر از صفر باشد.'];
        }

        if ($data['prices'] !== '' && PackageRepository::periodOptions([
            'duration_days' => $data['duration_days'],
            'price_toman'   => $data['price_toman'],
            'prices'        => $data['prices'],
        ]) === []) {
            return ['ok' => false, 'message' => 'قالب قیمت‌های چنددوره معتبر نیست.'];
        }

        if ($id > 0) {
            $this->packages->update($id, $data);
            $this->audit->log($this->telegramId, 'edit_package', 'package', $id, $data['title']);
        } else {
            $id = $this->packages->create($data);
            $this->audit->log($this->telegramId, 'create_package', 'package', $id, $data['title']);
        }

        $package = $this->packages->find($id);

        return [
            'message' => 'بسته ذخیره شد.',
            'package' => $package !== null ? View::package($package) : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function floatInput(array $input, string $key): float
    {
        $value = $input[$key] ?? 0;

        if (is_string($value)) {
            $value = Str::toEnglishDigits(trim($value));
            $value = str_replace(['٫', ','], ['.', ''], $value);
        }

        if (is_bool($value) || !is_scalar($value)) {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminTogglePackage(int $packageId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        if (!$this->packages->toggle($packageId)) {
            return ['ok' => false, 'message' => 'بسته یافت نشد.'];
        }

        $this->audit->log($this->telegramId, 'toggle_package', 'package', $packageId);

        $package = $this->packages->find($packageId);

        return [
            'message'   => 'وضعیت بسته تغییر کرد.',
            'is_active' => $package !== null && (int) $package['is_active'] === 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminDeletePackage(int $packageId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        if ($packageId <= 0 || $this->packages->find($packageId) === null) {
            return ['ok' => false, 'message' => 'بسته یافت نشد.'];
        }

        // سفارش‌های گذشته به این بسته اشاره دارند. حذف بسته `package_id` را
        // NULL می‌کند (ON DELETE SET NULL) و `package_title` روی سفارش می‌ماند،
        // پس سابقهٔ مالی حفظ می‌شود. ولی برای اطمینان، وجود سفارش پرداخت‌شده
        // مانع حذف است.
        $used = (int) $this->orders->db()->count(
            "SELECT COUNT(*) FROM orders WHERE package_id = ? AND status IN ('paid','applied')",
            [$packageId]
        );

        if ($used > 0) {
            return [
                'ok'      => false,
                'message' => 'این بسته ' . Str::faNumber($used) . ' سفارش پرداخت‌شده دارد و قابل حذف نیست. غیرفعالش کنید.',
            ];
        }

        $this->packages->delete($packageId);
        $this->audit->log($this->telegramId, 'delete_package', 'package', $packageId);

        return ['message' => 'بسته حذف شد.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminCoupons(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $items = [];

        foreach ($this->discounts->coupons()->listAll(100) as $coupon) {
            $items[] = [
                'id'            => (int) $coupon['id'],
                'code'          => (string) $coupon['code'],
                'kind'          => (string) $coupon['kind'],
                'kind_label'    => (string) $coupon['kind'] === CouponRepository::KIND_FIXED
                    ? 'مبلغ ثابت'
                    : 'درصدی',
                'value'         => (int) $coupon['value'],
                'max_uses'      => (int) $coupon['max_uses'],
                'used_count'    => (int) $coupon['used_count'],
                'per_user_limit' => (int) $coupon['per_user_limit'],
                'min_order'     => (int) $coupon['min_order_toman'],
                'max_discount'  => (int) $coupon['max_discount_toman'],
                'expires_at'    => $coupon['expires_at'] === null ? null : (int) $coupon['expires_at'],
                'expires_text'  => Str::date($coupon['expires_at'] === null ? null : (int) $coupon['expires_at']),
                'is_active'     => (int) $coupon['is_active'] === 1,
                'note'          => trim((string) ($coupon['note'] ?? '')) !== ''
                    ? (string) $coupon['note']
                    : null,
            ];
        }

        return [
            'items' => $items,
            'total' => count($items),
            'active' => $this->discounts->coupons()->countActive(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function adminSaveCoupon(array $input): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $id    = $this->intInput($input, 'id');
        $code  = strtoupper(trim((string) ($input['code'] ?? '')));
        $kind  = (string) ($input['kind'] ?? CouponRepository::KIND_PERCENT);
        $value = $this->intInput($input, 'value');

        if ($kind !== CouponRepository::KIND_FIXED) {
            $kind = CouponRepository::KIND_PERCENT;
        }

        if ($id === 0 && $code === '') {
            return ['ok' => false, 'message' => 'کد تخفیف لازم است.'];
        }

        if ($value <= 0) {
            return ['ok' => false, 'message' => 'مقدار تخفیف باید بزرگ‌تر از صفر باشد.'];
        }

        if ($kind === CouponRepository::KIND_PERCENT && $value > 100) {
            return ['ok' => false, 'message' => 'تخفیف درصدی نمی‌تواند بیش از ۱۰۰٪ باشد.'];
        }

        $data = [
            'code'               => $code,
            'kind'               => $kind,
            'value'              => $value,
            'max_uses'           => $this->intInput($input, 'max_uses'),
            'per_user_limit'     => $this->intInput($input, 'per_user_limit'),
            'min_order_toman'    => $this->intInput($input, 'min_order'),
            'max_discount_toman' => $this->intInput($input, 'max_discount'),
            'expires_at'         => $this->intInput($input, 'expires_at') > 0
                ? $this->intInput($input, 'expires_at')
                : null,
            'is_active'          => $this->boolInput($input, 'is_active') ? 1 : 0,
            'note'               => Str::truncate(trim((string) ($input['note'] ?? '')), 200),
        ];

        if ($id > 0) {
            $this->discounts->coupons()->update($id, $data);
            $this->audit->log($this->telegramId, 'edit_coupon', 'coupon', $id, $data['code']);
        } else {
            $id = $this->discounts->coupons()->create($data);
            $this->audit->log($this->telegramId, 'create_coupon', 'coupon', $id, $data['code']);
        }

        $coupon = $this->discounts->coupons()->find($id);

        return [
            'message' => 'کد تخفیف ذخیره شد.',
            'coupon'  => $coupon !== null ? [
                'id'        => (int) $coupon['id'],
                'code'      => (string) $coupon['code'],
                'kind'      => (string) $coupon['kind'],
                'value'     => (int) $coupon['value'],
                'is_active' => (int) $coupon['is_active'] === 1,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminDeleteCoupon(int $couponId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        if ($couponId <= 0 || $this->discounts->coupons()->find($couponId) === null) {
            return ['ok' => false, 'message' => 'کد یافت نشد.'];
        }

        $this->discounts->coupons()->delete($couponId);
        $this->audit->log($this->telegramId, 'delete_coupon', 'coupon', $couponId);

        return ['message' => 'کد تخفیف حذف شد.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminTickets(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $items = [];

        foreach ($this->tickets->listOpen(50) as $ticket) {
            $view = View::ticket($ticket);
            $view['telegram_id'] = (int) ($ticket['telegram_id'] ?? 0);
            $view['name']         = (string) ($ticket['first_name'] ?? '');

            $items[] = $view;
        }

        return [
            'items'   => $items,
            'open'    => $this->tickets->countOpen(),
            'total'   => $this->tickets->countAll(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminTicket(int $ticketId): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $ticket = $ticketId > 0 ? $this->tickets->find($ticketId) : null;

        if ($ticket === null) {
            return ['ok' => false, 'message' => 'تیکت یافت نشد.'];
        }

        $user = $this->users->findById((int) $ticket['user_id']);

        return [
            'ticket'   => View::ticket($ticket),
            'messages' => View::ticketMessages($this->tickets->messages($ticketId, 200)),
            'customer' => [
                'id'          => (int) ($user['id'] ?? 0),
                'telegram_id' => (int) ($user['telegram_id'] ?? 0),
                'name'        => (string) ($user['first_name'] ?? ''),
                'username'    => (string) ($user['username'] ?? ''),
                'blocked'     => (int) ($user['is_blocked'] ?? 0) === 1,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminReplyTicket(int $ticketId, string $body): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $ticket = $ticketId > 0 ? $this->tickets->find($ticketId) : null;

        if ($ticket === null) {
            return ['ok' => false, 'message' => 'تیکت یافت نشد.'];
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 4000) {
            return ['ok' => false, 'message' => 'متن پاسخ نامعتبر است.'];
        }

        if (!$this->tickets->replyAsAdmin($ticketId, $body)) {
            return ['ok' => false, 'message' => 'ثبت پاسخ ناموفق بود.'];
        }

        $user = $this->users->findById((int) $ticket['user_id']);

        if ($user !== null) {
            try {
                (new Notifier($this->requireBot()))->notifyUser(
                    (int) $user['telegram_id'],
                    "🎫 پاسخ جدید برای تیکت شما:\n\n" . Str::escape(Str::truncate($body, 600))
                );
            } catch (\Throwable $e) {
                Logger::warning('Could not notify user about ticket reply', ['error' => $e->getMessage()]);
            }
        }

        $fresh = $this->tickets->find($ticketId);

        return [
            'message'  => 'پاسخ ثبت شد.',
            'ticket'   => $fresh !== null ? View::ticket($fresh) : null,
            'messages' => View::ticketMessages($this->tickets->messages($ticketId, 200)),
        ];
    }

    /**
     * وضعیت همهٔ سوییچ‌ها و درگاه‌ها — فقط مدیر.
     *
     * این متد به‌جای آرایهٔ ساده در مسیریابی ساخته شد چون نسخهٔ فشرده
     * عملاً بدون کنترل دسترسی بود: هر کاربر عادی می‌توانست کلیدهای فعال/
     * غیرفعال ربات و وضعیت پیکربندی درگاه‌ها را بخواند، که اطلاعات عملیاتی
     * سیستم است و نباید عمومی باشد.
     *
     * @return array<string, mixed>
     */
    private function adminFlags(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        return [
            'flags'    => $this->flags->summary(),
            'gateways' => $this->flags->gatewayStatuses(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminToggleFlag(string $key): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $state = $this->flags->toggle(trim($key));

        if ($state === null) {
            return ['ok' => false, 'message' => 'این سوییچ قابل تغییر نیست.'];
        }

        // 🚫 خاموش کردن کل ربات، مدیر را در مینی‌اپ قفل می‌کند (پیام
        // «ربات خاموش است») و راه بازگشت فقط از چت است. سوپرادمین‌ها در
        // ربات از این مسیر معاف‌اند ولی در API نه، پس صریحاً جلویش گرفته
        // می‌شود.
        if ($key === Settings::BOT_ENABLED && !$state) {
            $this->flags->setBotEnabled(true);

            return ['ok' => false, 'message' => 'خاموش کردن کل ربات از این بخش ممکن نیست؛ از چت ربات انجام دهید.'];
        }

        $this->audit->log($this->telegramId, 'toggle_flag', 'setting', 0, $key . ' => ' . ($state ? '1' : '0'));

        return ['message' => 'سوییچ تغییر کرد.', 'value' => $state];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminRisk(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $rows = $this->users->listAtRiskRepresentatives(50);

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) ($row['first_name'] ?? 'کاربر'),
                'telegram_id' => (int) ($row['telegram_id'] ?? 0),
                'panels'    => (int) ($row['panel_count'] ?? 0),
                'expired'   => (int) ($row['expired_count'] ?? 0),
                'soonest'   => Str::date(isset($row['soonest_expire']) && $row['soonest_expire'] !== null
                    ? (int) $row['soonest_expire']
                    : null),
            ];
        }

        return ['items' => $items, 'count' => count($items)];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminAudit(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $items = [];

        foreach ($this->audit->latest(50) as $row) {
            $items[] = [
                'id'      => (int) $row['id'],
                'admin'   => (int) $row['admin_user_id'],
                'action'  => (string) $row['action'],
                'target'  => (string) $row['target_type'] . '#' . (int) $row['target_id'],
                'details' => Str::truncate((string) ($row['details'] ?? ''), 160),
                'text'    => Str::date((int) $row['created_at']),
            ];
        }

        return ['items' => $items];
    }

    /**
     * تنظیمات عددی که مدیر می‌تواند از مینی‌اپ عوض کند.
     *
     * فهرست بسته (allowlist) عمداً کوتاه است: هر کلید دلخواهی در `settings`
     * نوشتن یعنی امکان خراب‌کردن کلیدهای داخلی مثل `session:*` و
     * `panelwarn:*`.
     *
     * @return array<string, mixed>
     */
    private const NUMERIC_SETTINGS = [
        Settings::EXPIRE_WARN_DAYS,
        Settings::EXPIRE_GRACE_DAYS,
        Settings::LOW_VOLUME_ALERT,
        Settings::PANEL_STATS_TTL,
        Settings::TEST_CONFIG_VOLUME_GB,
        Settings::TEST_CONFIG_DAYS,
        Settings::TEST_CONFIG_MAX,
        Settings::TEST_CONFIG_COOLDOWN,
        Settings::TEST_CONFIG_PANEL_ID,
        Settings::REFERRAL_DISCOUNT,
        Settings::REFERRAL_BONUS,
        Settings::REFERRAL_BONUS_LEVEL2,
        Settings::LOYALTY_DISCOUNT,
        Settings::LOYALTY_REDEEM,
        Settings::LOGIN_MAX_ATTEMPTS,
        Settings::LOGIN_LOCK_MINUTES,
        Settings::BACKUP_KEEP,
        Settings::CHANNEL_TTL,
    ];

    /**
     * @return array<string, mixed>
     */
    private function adminSettings(): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $labels = [
            Settings::EXPIRE_WARN_DAYS      => 'چند روز قبل از انقضا هشدار',
            Settings::EXPIRE_GRACE_DAYS     => 'مهلت ارفاقی (روز)',
            Settings::LOW_VOLUME_ALERT      => 'آستانهٔ هشدار حجم (گیگابایت)',
            Settings::PANEL_STATS_TTL       => ' عمرت کش آمار کاربران (دقیقه)',
            Settings::TEST_CONFIG_VOLUME_GB => 'حجم کانفیگ تست (گیگابایت)',
            Settings::TEST_CONFIG_DAYS      => 'اعتبار کانفیگ تست (روز)',
            Settings::TEST_CONFIG_MAX       => 'سقف کانفیگ تست فعال',
            Settings::TEST_CONFIG_COOLDOWN  => 'فاصلهٔ دو کانفیگ تست (دقیقه)',
            Settings::TEST_CONFIG_PANEL_ID   => 'پنل ثابت تست (۰ = پنل خود کاربر)',
            Settings::REFERRAL_DISCOUNT     => 'درصد تخفیف معرفی',
            Settings::REFERRAL_BONUS        => 'پاداش معرفی (تومان)',
            Settings::REFERRAL_BONUS_LEVEL2 => 'پاداش سطح دوم (تومان)',
            Settings::LOYALTY_DISCOUNT      => 'درصد تخفیف وفاداری',
            Settings::LOYALTY_REDEEM        => 'امتیاز لازم برای تخفیف وفاداری',
            Settings::LOGIN_MAX_ATTEMPTS    => 'حداکثر تلاش ورود',
            Settings::LOGIN_LOCK_MINUTES    => 'مدت قفل ورود (دقیقه)',
            Settings::BACKUP_KEEP           => 'تعداد بکاپ نگه‌داشته‌شده',
            Settings::CHANNEL_TTL           => 'عمرت کش عضویت کانال (دقیقه)',
        ];

        $bounds = [
            Settings::EXPIRE_WARN_DAYS      => [0, 60],
            Settings::EXPIRE_GRACE_DAYS     => [0, 60],
            Settings::LOW_VOLUME_ALERT      => [0, 1000],
            Settings::PANEL_STATS_TTL       => [1, 1440],
            Settings::TEST_CONFIG_VOLUME_GB => [1, 10],
            Settings::TEST_CONFIG_DAYS      => [1, 365],
            Settings::TEST_CONFIG_MAX       => [0, 50],
            Settings::TEST_CONFIG_COOLDOWN  => [0, 10080],
            Settings::TEST_CONFIG_PANEL_ID   => [0, 1000000],
            Settings::REFERRAL_DISCOUNT     => [0, 100],
            Settings::REFERRAL_BONUS        => [0, 100000000],
            Settings::REFERRAL_BONUS_LEVEL2 => [0, 100000000],
            Settings::LOYALTY_DISCOUNT      => [0, 100],
            Settings::LOYALTY_REDEEM        => [1, 100000],
            Settings::LOGIN_MAX_ATTEMPTS    => [1, 50],
            Settings::LOGIN_LOCK_MINUTES    => [1, 1440],
            Settings::BACKUP_KEEP           => [2, 200],
            Settings::CHANNEL_TTL           => [1, 1440],
        ];

        $items = [];

        foreach (self::NUMERIC_SETTINGS as $key) {
            $range = $bounds[$key] ?? [0, 1000000];

            $items[] = [
                'key'   => $key,
                'label' => $labels[$key] ?? $key,
                'value' => $this->settings->int($key, (int) $range[0]),
                'min'   => $range[0],
                'max'   => $range[1],
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSaveSetting(string $key, string $value): array
    {
        $guard = $this->requireAdmin();

        if (!($guard['ok'] ?? false)) {
            return $guard;
        }

        $key = trim($key);

        if (!in_array($key, self::NUMERIC_SETTINGS, true)) {
            return ['ok' => false, 'message' => 'این تنظیم از این بخش قابل تغییر نیست.'];
        }

        $number = (int) preg_replace('/\D/', '', Str::toEnglishDigits($value));

        if ($number < 0) {
            return ['ok' => false, 'message' => 'مقدار نامعتبر است.'];
        }

        // سقف‌ها با allowlist هم‌خوان‌اند ولی در یک نقطه نگه‌داری می‌شوند تا
        // تغییر یکی، دیگری را جا نیندازد.
        $max = [
            Settings::EXPIRE_WARN_DAYS      => 60,
            Settings::EXPIRE_GRACE_DAYS     => 60,
            Settings::LOW_VOLUME_ALERT      => 1000,
            Settings::PANEL_STATS_TTL       => 1440,
            Settings::TEST_CONFIG_VOLUME_GB => 10,
            Settings::TEST_CONFIG_DAYS      => 365,
            Settings::TEST_CONFIG_MAX       => 50,
            Settings::TEST_CONFIG_COOLDOWN  => 10080,
            Settings::TEST_CONFIG_PANEL_ID   => 1000000,
            Settings::REFERRAL_DISCOUNT     => 100,
            Settings::REFERRAL_BONUS        => 100000000,
            Settings::REFERRAL_BONUS_LEVEL2 => 100000000,
            Settings::LOYALTY_DISCOUNT      => 100,
            Settings::LOYALTY_REDEEM        => 100000,
            Settings::LOGIN_MAX_ATTEMPTS    => 50,
            Settings::LOGIN_LOCK_MINUTES    => 1440,
            Settings::BACKUP_KEEP           => 200,
            Settings::CHANNEL_TTL           => 1440,
        ][$key] ?? 1000000;

        if ($number > $max) {
            return ['ok' => false, 'message' => 'مقدار بیشتر از حد مجاز (' . Str::faNumber($max) . ') است.'];
        }

        $this->settings->set($key, (string) $number);
        $this->audit->log($this->telegramId, 'set_setting', 'setting', 0, $key . '=' . $number);

        return ['message' => 'تنظیم ذخیره شد.', 'key' => $key, 'value' => $number];
    }

    // ------------------------------------------------------------------
    // کمکی
    // ------------------------------------------------------------------

    private function shopIsOpen(): bool
    {
        return $this->settings->bool(Settings::SHOP_OPENED, true)
            && $this->flags->isBotEnabled();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activePackage(int $packageId): ?array
    {
        if ($packageId <= 0) {
            return null;
        }

        $package = $this->packages->find($packageId);

        if ($package === null || (int) $package['is_active'] !== 1) {
            return null;
        }

        return $package;
    }
}
