<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Payment\NowPaymentsGateway;
use Pasargad\Payment\PaymentService;
use Pasargad\Store\AgencyService;
use Pasargad\Store\DiscountService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\PanelSyncer;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\TicketRepository;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Invoice;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;
use Pasargad\Telegram\Update;

/**
 * کنترلر اصلی ربات: مسیریابی آپدیت‌ها، مدیریت نشست کاربر و ارائهٔ منوها.
 *
 * جریان کلی هر آپدیت:
 *   آپدیت → ثبت کاربر → فیلتر مسدودی → فیلتر خاموشی → فیلتر عضویت کانال
 *          → مسیریاب (callback یا پیام) → هندلر
 *
 * نکتهٔ معماری: خرید «پنل نمایندگی» **نیازی به اتصال قبلی ندارد**. کاربر تازه
 * می‌تواند مستقیم پنل بخرد؛ پنل به‌صورت خودکار ساخته و اطلاعاتش به او داده
 * می‌شود. جریان «من پنل دارم» فقط برای کسی است که از قبل پنل دارد.
 */
final class Kernel
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
    private ?\Pasargad\Panel\PasarGuardClient $panel = null;
    private ?PanelCenter $panelCenter = null;
    private ?ChannelGuard $channelGuard = null;
    private ?AdminController $adminController = null;
    private ?SupportCenter $supportCenter = null;
    private ?DiscountService $discounts = null;
    private FloodGuard $flood;

    public function __construct(
        ?BotApi $bot = null,
        ?Notifier $notifier = null,
        ?UserRepository $users = null,
        ?PackageRepository $packages = null,
        ?OrderRepository $orders = null,
        ?Provisioner $provisioner = null,
        ?PaymentService $payments = null,
        ?Settings $settings = null,
        ?SessionStore $sessions = null,
        ?\Pasargad\Panel\PasarGuardClient $panel = null,
        ?FeatureFlags $flags = null,
        ?PanelRepository $panels = null,
        ?FloodGuard $floodGuard = null
    ) {
        $this->bot         = $bot ?? new BotApi();
        $this->notifier    = $notifier ?? new Notifier($this->bot);
        $this->users       = $users ?? new UserRepository();
        $this->packages    = $packages ?? new PackageRepository();
        $this->orders      = $orders ?? new OrderRepository();
        $this->settings    = $settings ?? new Settings();
        $this->sessions    = $sessions ?? new SessionStore();
        $this->flags       = $flags ?? new FeatureFlags($this->settings);
        $this->panel       = $panel;
        $this->panels      = $panels ?? new PanelRepository();
        $this->provisioner = $provisioner ?? new Provisioner($panel, $this->orders, $this->users, $this->settings, $this->panels);
        $this->payments    = $payments ?? new PaymentService($this->orders, $this->provisioner, $this->settings, $this->flags);
        $this->flood       = $floodGuard ?? new FloodGuard();

        $this->payments->setNotifier($this->notifier);
    }

    public function flags(): FeatureFlags
    {
        return $this->flags;
    }

    public function notifier(): Notifier
    {
        return $this->notifier;
    }

    public function botApi(): BotApi
    {
        return $this->bot;
    }

    public function panels(): PanelRepository
    {
        return $this->panels;
    }

    // ------------------------------------------------------------------
    // نقاط دسترسیِ تنبل (lazy)
    // ------------------------------------------------------------------

    private function panelCenter(): PanelCenter
    {
        if ($this->panelCenter === null) {
            $this->panelCenter = new PanelCenter(
                $this->bot,
                $this->panels,
                new \Pasargad\Store\TestConfigService($this->panels, null, $this->settings, $this->panelClient()),
                null,
                $this->settings,
                $this->sessions,
                $this->panelClient(),
                new \Pasargad\Store\PanelUserStats($this->panels, $this->settings, $this->panelClient())
            );
        }

        return $this->panelCenter;
    }

    private function channelGuard(): ChannelGuard
    {
        if ($this->channelGuard === null) {
            $this->channelGuard = new ChannelGuard($this->bot, $this->settings);
        }

        return $this->channelGuard;
    }

    private function adminController(): AdminController
    {
        if ($this->adminController === null) {
            $this->adminController = new AdminController(
                $this->bot,
                $this->notifier,
                $this->users,
                $this->packages,
                $this->orders,
                $this->provisioner,
                $this->payments,
                $this->settings,
                $this->sessions,
                $this->flags,
                $this->panels,
                $this->panelClient()
            );
        }

        return $this->adminController;
    }

    private function panelClient(): \Pasargad\Panel\PasarGuardClient
    {
        if ($this->panel === null) {
            $this->panel = new \Pasargad\Panel\PasarGuardClient();

            // بودجهٔ زمانی از قبل تعیین شده (مثلاً توسط وبهوک) ⇒ به کلاینت تازه
            // هم منتقل می‌شود، وگرنه فراخوانی اول در هر مسیر بدون سقف اجرا
            // می‌شد و همان ۱۶۰ ثانیهٔ کشنده برمی‌گشت.
            if ($this->panelBudget > 0.0) {
                $this->panel->beginRequest($this->panelBudget);
            }
        }

        return $this->panel;
    }

    /**
     * سقف زمانی کل این پردازش برای تماس با پنل.
     *
     * @var float ثانیه، یا 0 برای «بدون سقف»
     */
    private float $panelBudget = 0.0;

    /**
     * تعیین سقف زمانی تماس با پنل برای این درخواست.
     *
     * چرا؟ تلگرام بعد از ۶۰ ثانیه بی‌پاسخ ماندن، update را دوباره می‌فرستد؛ و
     * پردازش دوباره یعنی بارگذاری دوبارهٔ پنل، پیام تکراری برای کاربر و در
     * نهایت قفل شدن دکمه‌ها توسط FloodGuard. مقدار پیش‌فرض ۴۵ ثانیه است تا
     * جا برای ارسال پیام خطا به کاربر هم بماند.
     *
     * این فقط یک **سقف** است، نه تضمین: اگر پردازش زودتر تمام شود، مشکلی
     * نیست؛ اگر پنل کند باشد، درخواست‌های بعدی با timeout کوتاه‌تر و در
     * نهایت با یک خطای سریع و قابل‌فهم متوقف می‌شوند.
     */
    public function beginPanelBudget(?float $seconds = null): void
    {
        $this->panelBudget = $seconds ?? (float) Config::int('panel.webhook_budget_seconds', 45);

        // کل پروسه، نه فقط کلاینت این Kernel.
        //
        // چرا لازم است: در یک درخواست، چند سرویس کلاینت پنل خودشان می‌سازند
        // (`PanelSyncer`, `AccessCutoff`, `PanelUserStats`, `TestConfigService`,
        // `AdminController`…). اگر بودجه فقط روی کلاینت Kernel می‌نشست، بقیه
        // بدون سقف کار می‌کردند و همان ۱۶۰ ثانیهٔ کشندهٔ هر فراخوانی برمی‌گشت.
        \Pasargad\Panel\PasarGuardClient::setProcessBudget($this->panelBudget);

        if ($this->panel !== null) {
            $this->panel->beginRequest($this->panelBudget);
        }
    }

    private function supportCenter(): SupportCenter
    {
        if ($this->supportCenter === null) {
            $this->supportCenter = new SupportCenter(
                $this->bot,
                $this->notifier,
                new TicketRepository(),
                $this->settings,
                $this->flags,
                $this->sessions
            );
        }

        return $this->supportCenter;
    }

    private function discounts(): DiscountService
    {
        if ($this->discounts === null) {
            $this->discounts = new DiscountService(settings: $this->settings);
        }

        return $this->discounts;
    }

    public function tickets(): TicketRepository
    {
        return new TicketRepository();
    }

    // ------------------------------------------------------------------
    // پردازش یک آپدیت
    // ------------------------------------------------------------------

    public function handle(Update $update): void
    {
        $userId = $update->userId();
        $chatId = $update->chatId();

        if ($userId === null || $chatId === null) {
            return;
        }

        $user = $this->users->upsertByTelegram($userId, [
            'username'      => $update->username(),
            'first_name'    => $update->firstName(),
            'language_code' => $update->languageCode(),
        ]);

        if (!empty($user['is_blocked'])) {
            if ($update->isCallbackQuery()) {
                $this->bot->answerCallback((string) $update->raw()['callback_query']['id'], Text::blocked((string) $user['blocked_reason']));
            } elseif (($update->text()) !== '' && $update->text() !== '/start') {
                $this->bot->sendMessage($chatId, Text::blocked((string) $user['blocked_reason']));
            }

            return;
        }

        $isAdmin = $this->notifier->isAdmin($userId);

        // ------------------------------------------------------------------
        // 🛡️ ضدتکرار و محافظ بار سرور (fail-open: با خطا، دستور اجرا می‌شود)
        // ------------------------------------------------------------------
        if ($this->isDuplicateOrFlood($update, $userId, $chatId)) {
            return;
        }

        // کل ربات خاموش است: فقط سوپرادمین‌ها راه دسترسی دارند تا بتوانند
        // دوباره روشنش کنند (وگرنه ربات برای همیشه خاموش می‌ماند).
        if (!$this->flags->isBotEnabled() && !$isAdmin) {
            $this->handleDisabledBot($update, $chatId);
            return;
        }

        // ------------------------------------------------------------------
        // دروازهٔ عضویت اجباری کانال
        //
        // سوپرادمین‌ها معاف‌اند: اگر ادمین هم گیر بیفتد، ربات برای همیشه قفل
        // می‌شود و هیچ‌کس نمی‌تواند بازش کند.
        // ------------------------------------------------------------------
        if (!$isAdmin && $this->channelGuard()->isRequired() && $this->isChannelGateOpen($update)) {
            $this->handleChannelGate($update, $chatId);
            return;
        }

        try {
            if ($update->isCallbackQuery()) {
                $this->handleCallback($update, $user, $isAdmin);
            } else {
                $this->handleMessage($update, $user, $isAdmin);
            }
        } catch (\Pasargad\Panel\PanelException $e) {
            // خطای پنل **قبلاً** به فارسی قابل‌فهم ترجمه شده (کلاینت پنل این کار
            // را می‌کند)، پس همان را نشان می‌دهیم. «خطایی رخ داد» چیزی به کاربر
            // نمی‌گفت و باعث می‌شد فکر کند خریدش ثبت نشده و دوباره بزند — یعنی
            // خرید تکراری.
            Logger::warning('Panel error while handling update', [
                'user_id' => $userId,
                'update'  => $update->updateId(),
                'status'  => $e->httpStatus(),
                'error'   => $e->getMessage(),
            ]);

            $this->safeReply($chatId, '⚠️🔗 ارتباط با پنل برقرار نشد: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('Update handling failed', [
                'user_id' => $userId,
                'update'  => $update->updateId(),
                'error'   => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);

            $this->safeReply($chatId, '⚠️🔧 خطایی رخ داد! 😔 لطفاً دوباره تلاش کنید. 🙏');
        }
    }

    /**
     * دکمهٔ «بررسی مجدد» باید همیشه کار کند، حتی وقتی هنوز عضو نیست.
     */
    private function isChannelGateOpen(Update $update): bool
    {
        if (!$update->isCallbackQuery()) {
            return true;
        }

        $ns = (string) ($update->callbackPayload()['n'] ?? '');

        return !in_array($ns, ['channel.recheck', 'menu', 'help', 'close', 'noop'], true);
    }

    /**
     * 🛡️ بررسی تکراری بودن آپدیت یا طغیان دستورات.
     *
     * @return bool true یعنی آپدیت مصرف شد و نباید ادامه یابد
     */
    private function isDuplicateOrFlood(Update $update, int $userId, int $chatId): bool
    {
        // اثر انگشت محتوا: برای کال‌بک دادهٔ کال‌بک، برای پیام متن/رسانه.
        $fingerprint = $update->isCallbackQuery()
            ? 'cb:' . sha1((string) ($update->callbackData() ?? ''))
            : ($update->hasPhoto() || $update->hasDocument()
                ? 'media'
                : 'msg:' . sha1($update->command() . '|' . $update->text()));

        if ($this->flood->isDuplicateUpdate($update->updateId(), $fingerprint)) {
            if ($update->isCallbackQuery()) {
                $this->bot->answerCallback(
                    (string) ($update->raw()['callback_query']['id'] ?? ''),
                    '⏳ در حال پردازش... ⌛️'
                );
            }

            return true;
        }

        if ($update->isCallbackQuery()) {
            $payload = $update->callbackPayload();
            $ns      = (string) ($payload['n'] ?? '');

            if ($ns === '') {
                $raw = trim((string) ($update->callbackData() ?? ''));

                if ($raw !== '' && $raw[0] !== '{') {
                    $ns = $raw;
                }
            }

            $fingerprint = 'cb:' . sha1((string) ($update->callbackData() ?? ''));
            $window      = FloodGuard::callbackWindow($ns);

            if ($this->flood->isThrottled($userId, $fingerprint, $window)) {
                $this->bot->answerCallback(
                    (string) ($update->raw()['callback_query']['id'] ?? ''),
                    '⏳ لطفاً کمی صبر کنید... ⏳'
                );

                return true;
            }

            return false;
        }

        // پیام متنی: فقط تکرار عیناً یکسان در پنجرهٔ کوتاه رد می‌شود.
        $text = $update->text();

        if ($update->hasPhoto() || $update->hasDocument()) {
            return $this->flood->isThrottled($userId, 'media', FloodGuard::RECEIPT_WINDOW);
        }

        if ($text === '') {
            return false;
        }

        // 📝 کاربر وسط یک فرم است (ثبت پنل، ویرایش متن/بسته، شارژ کیف پول و...)
        // → ورودی تایپ‌شده‌اش هرگز طغیان نیست. تلاش مجدد با همان متن (مثلاً
        // ارسال دوبارهٔ نام کاربری بعد از خطا) کاملاً قانونی است و نباید
        // بی‌صدا بلعیده شود — وگرنه کاربر فکر می‌کند ربات هنگ کرده است.
        $state = $this->sessions->get($userId);

        if (is_array($state) && in_array((string) ($state['step'] ?? ''), self::TEXT_INPUT_STEPS, true)) {
            return false;
        }

        return $this->flood->isThrottled(
            $userId,
            'msg:' . sha1($update->command() . '|' . $text),
            FloodGuard::MESSAGE_WINDOW
        );
    }

    /**
     * مراحل نشستی که ورودی متنی آزاد مصرف می‌کنند.
     *
     * @var array<int, string>
     */
    private const TEXT_INPUT_STEPS = [
        'panel:username',
        'panel:password',
        'pkg:edit',
        'admin_notice',
        'admin_channel',
        'admin_rules',
        'admin_broadcast',
        'admin_text',
        'admin_wallet',
        'admin_testconfig',
    ];

    private function handleChannelGate(Update $update, int $chatId): void
    {
        $guard  = $this->channelGuard();
        $result = $guard->check((int) $update->userId());

        if ($result['member']) {
            // عضو شده است → اجازهٔ عبور و نمایش منو
            if ($update->isCallbackQuery()) {
                $this->bot->answerCallback((string) ($update->raw()['callback_query']['id'] ?? ''), '✅ عضویت شما تأیید شد');
            }

            $user = $this->users->findByTelegramId((int) $update->userId()) ?? [];

            $this->bot->sendMessage($chatId, '✅ عضویت شما در کانال تأیید شد. خوش آمدید! 👋', [
                'reply_markup' => $this->bot->buildMarkup([[
                    ['text' => '🏠 منوی اصلی', 'data' => BotApi::encodeData('menu')],
                ]]),
            ]);

            $this->showMainMenu($chatId, $user, $this->notifier->isAdmin((int) $update->userId()));

            return;
        }

        [$text, $keyboard] = $guard->gateScreen();

        if ($update->isCallbackQuery()) {
            $this->bot->answerCallback((string) ($update->raw()['callback_query']['id'] ?? ''), 'هنوز عضو نشده‌اید');

            return;
        }

        $this->bot->sendMessage($chatId, $text, [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function handleDisabledBot(Update $update, int $chatId): void
    {
        $notice = $this->flags->disabledNotice();

        if ($update->isCallbackQuery()) {
            $this->bot->answerCallback(
                (string) ($update->raw()['callback_query']['id'] ?? ''),
                Str::truncate(strip_tags($notice), 180),
                true
            );

            return;
        }

        // فقط به پیام‌های معنادار پاسخ می‌دهیم تا در صورت انبوه پیام، اسپم نشود.
        $text = $update->text();
        if ($text === '' || $text === '/start') {
            $this->bot->sendMessage($chatId, $notice, [
                'reply_markup' => $this->bot->buildMarkup(
                    Keyboard::link('📞 پشتیبانی', $this->supportLink())
                ),
            ]);

            return;
        }

        $this->bot->sendMessage($chatId, $notice);
    }

    private function supportLink(): string
    {
        return Config::str('notifications.support_link', 'https://t.me/');
    }

    // ------------------------------------------------------------------
    // پیام‌های متنی
    // ------------------------------------------------------------------

    private function handleMessage(Update $update, array $user, bool $isAdmin): void
    {
        $chatId     = (int) $update->chatId();
        $text       = $update->text();
        $telegramId = (int) $update->userId();
        $state      = $this->sessions->get($telegramId);

        // مسیرهای ویرایش متن سوپرادمین (فقط سوپرادمین)
        if ($isAdmin && ($state['step'] ?? '') === 'admin_notice') {
            $this->sessions->clear($telegramId);

            if ($text === '' || $update->command() === 'cancel') {
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
            } else {
                $this->adminController()->saveNotice($chatId, $text);
            }

            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_channel') {
            $this->sessions->clear($telegramId);

            if ($text === '' || $update->command() === 'cancel') {
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
            } else {
                $this->adminController()->saveChannel($chatId, $text);
            }

            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_rules') {
            $this->sessions->clear($telegramId);

            if ($text === '' || $update->command() === 'cancel') {
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
            } else {
                $this->adminController()->saveRules($chatId, $text);
            }

            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_broadcast') {
            if ($update->command() === 'cancel' || $update->command() === 'start') {
                $this->sessions->clear($telegramId);
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
                return;
            }

            if ($text === '') {
                $this->bot->sendMessage($chatId, '⚠️ متن خالی است. دوباره بفرستید یا /cancel را بزنید.');
                return;
            }

            $this->adminController()->saveBroadcastDraft($chatId, $telegramId, $update->text());
            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_text') {
            $key = (string) ($state['key'] ?? '');

            if ($update->command() === 'cancel' || $update->command() === 'start') {
                $this->sessions->clear($telegramId);
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
                return;
            }

            if ($text === '') {
                $this->bot->sendMessage($chatId, '⚠️ متن خالی است.');
                return;
            }

            $this->sessions->clear($telegramId);
            $this->adminController()->saveText($chatId, $key, $update->text());
            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_coupon') {
            if ($update->command() === 'cancel' || $update->command() === 'start') {
                $this->sessions->clear($telegramId);
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
                return;
            }

            $this->sessions->clear($telegramId);

            if ($text === '') {
                $this->bot->sendMessage($chatId, '⚠️ متن خالی است.');
                return;
            }

            $this->adminController()->saveCoupon($chatId, $text);
            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_ticket_reply') {
            $ticketId = (int) ($state['ticket_id'] ?? 0);

            if ($update->command() === 'cancel' || $update->command() === 'start') {
                $this->sessions->clear($telegramId);
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
                return;
            }

            $this->sessions->clear($telegramId);

            if ($text === '') {
                $this->bot->sendMessage($chatId, '⚠️ متن پاسخ خالی است.');
                return;
            }

            $this->adminController()->saveTicketReply($chatId, $ticketId, $text);
            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_number') {
            $field = (string) ($state['field'] ?? '');

            if ($update->command() === 'cancel' || $update->command() === 'start') {
                $this->sessions->clear($telegramId);
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
                return;
            }

            $this->sessions->clear($telegramId);

            if ($text === '') {
                $this->bot->sendMessage($chatId, '⚠️ عددی نفرستادید. دوباره تلاش کنید.');
                return;
            }

            $this->adminController()->saveNumber($chatId, $field, $text);
            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_wallet') {
            $userDbId = (int) ($state['user_id'] ?? 0);

            if ($update->command() === 'cancel' || $update->command() === 'start') {
                $this->sessions->clear($telegramId);
                $this->bot->sendMessage($chatId, '❌ لغو شد.');
                return;
            }

            if ($text === '') {
                $this->bot->sendMessage($chatId, '⚠️ مبلغ را بفرستید یا /cancel را بزنید.');
                return;
            }

            $this->sessions->clear($telegramId);
            $this->adminController()->saveWallet($chatId, $telegramId, $userDbId, $update->text());
            return;
        }

        if ($isAdmin && ($state['step'] ?? '') === 'admin_testconfig') {
            $field = (string) ($state['field'] ?? '');

            if ($update->command() === 'cancel' || $update->command() === 'start') {
                $this->sessions->clear($telegramId);
                $this->bot->sendMessage($chatId, '❌ لغو شد. ❌');
                return;
            }

            if ($text === '') {
                $this->bot->sendMessage($chatId, '⚠️📝 مقداری نفرستادید! دوباره بفرستید یا /cancel را بزنید. ❌');
                return;
            }

            $this->sessions->clear($telegramId);
            $this->adminController()->saveTestConfig($chatId, $field, $update->text());
            return;
        }

        // مسیرهای نشستی: ثبت پنل («من پنل دارم») و ویرایش بسته
        if ($state !== null && $this->handleSessionState($update, $user, $state)) {
            return;
        }

        // مدیریت رسید کارت‌به‌کارت
        if ($update->hasPhoto() || $update->hasDocument()) {
            $this->handleReceipt($update, $user);
            return;
        }

        if ($text === '') {
            return;
        }

        if ($update->isCommand()) {
            $this->handleCommand($update, $user, $isAdmin);
            return;
        }

        // مدیریت بسته‌ها با پیام متنی (فقط سوپرادمین)
        if ($isAdmin && str_contains($text, '|')) {
            $editor = new PackageEditor($this->packages, $this->bot);

            // اگر ادمین قبلاً روی «✏️ ویرایش» زده باشد، این پیام باید بستهٔ
            // موجود را به‌روزرسانی کند نه اینکه بستهٔ تکراری بسازد.
            $editingId = $this->adminController()->editingPackageId($telegramId);

            if ($editor->tryHandle($chatId, $text, $telegramId, $editingId)) {
                if ($editingId !== null) {
                    $this->sessions->clear($telegramId);
                }

                return;
            }
        }

        $this->handleMenuText($update, $user, $isAdmin, $text);
    }

    private function handleCommand(Update $update, array $user, bool $isAdmin): void
    {
        $chatId     = (int) $update->chatId();
        $telegramId = (int) $update->userId();

        switch ($update->command()) {
            case 'start':
                // لینک دعوت: /start R123 یا /start ref_R123
                //
                // پارامتر فقط وقتی پذیرفته می‌شود که شکل کد معرفی داشته باشد،
                // وگرنه هر متنی بعد از /start (مثلاً /start foo) یک «معرفی
                // نامعتبر» نشان می‌داد و کاربر گیج می‌شد.
                $refParam = trim((string) $update->argument());

                if ($refParam !== '') {
                    $refParam = preg_replace('/^ref_/i', '', $refParam) ?? '';

                    $bind = $this->bindReferral($user, $refParam);

                    if (($bind['ok'] ?? false) && ($bind['message'] ?? '') !== '') {
                        $this->bot->sendMessage($chatId, Str::escape((string) $bind['message']));
                    }
                }

                // عمداً fall-through به منوی اصلی
                // fallthrough
            case 'menu':
                $this->sessions->clear($telegramId);
                $name = (string) ($user['first_name'] ?? $user['username'] ?? 'دوست عزیز');
                // کیبوردِ ثابتِ شیشه‌ای کنار دکمه‌های اینلاین:
                // این پیام دکمه‌های پایینی را می‌سازد، پیام منو دکمه‌های اینلاین را.
                $this->bot->sendMessage($chatId, Text::welcome($name, $this->hasPanels($user)), [
                    'reply_markup' => [
                        'keyboard' => [
                            [['text' => '🏠 منوی اصلی'], ['text' => '🛒 فروشگاه']],
                            [['text' => '👤 حساب من'], ['text' => '🎫 پشتیبانی']],
                        ],
                        'resize_keyboard' => true,
                        'is_persistent'  => true,
                    ],
                ]);
                $this->showMainMenu($chatId, $user, $isAdmin);
                break;

            case 'shop':
                $this->showShop($chatId, $user, PackageRepository::KIND_AGENCY);
                break;

            case 'coupon':
                $this->askCouponCode($chatId, $telegramId);
                break;

            case 'referral':
            case 'invite':
                $this->showReferral($chatId, $user);
                break;

            case 'support':
            case 'ticket':
                $this->supportCenter()->start($chatId, $user);
                break;

            case 'panels':
            case 'panel':
                $this->panelCenter()->showList($chatId, $user);
                break;

            case 'account':
            case 'profile':
                $this->showAccount($chatId, $user);
                break;

            case 'wallet':
                $this->showWallet($chatId, $user);
                break;

            case 'payments':
            case 'payhistory':
                $this->showPayments($chatId, $user);
                break;

            case 'orders':
                $this->showOrders($chatId, $user, 0);
                break;

            case 'rules':
            case 'terms':
                $this->showRules($chatId);
                break;

            case 'test':
                $this->panelCenter()->showTestList($chatId, $user);
                break;

            case 'login':
                // نگه‌داشته شده برای سازگاری با دکمه‌های قدیمی؛ اکنون همان
                // جریان «من پنل دارم» است.
                $this->panelCenter()->startSelfRegister($chatId, $telegramId);
                break;

            case 'logout':
                $this->bot->sendMessage($chatId,
                    "ℹ️✨ پنل‌های شما به ربات متصل هستند و نیازی به قطع اتصال ندارند! ✅\n\n"
                    . "اگر می‌خواهید پنل دیگری اضافه کنید، از دکمهٔ «🔗 من پنل دارم» استفاده کنید. 🔌👇");
                $this->showMainMenu($chatId, $user, $isAdmin);
                break;

            case 'help':
                $this->bot->sendMessage($chatId, Text::help(), [
                    'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                        [['text' => '📜 قوانین', 'data' => BotApi::encodeData('rules')]],
                        Keyboard::back('menu'),
                    ])),
                ]);
                break;

            case 'buy':
                $this->handleBuyCommand($update, $user);
                break;

            default:
                $this->bot->sendMessage($chatId, '❓ دستور ناشناخته. /help را ببینید.');
        }
    }

    private function handleMenuText(Update $update, array $user, bool $isAdmin, string $text): void
    {
        // دکمه‌های ثابت (ردیفِ پایینِ صفحه) به همان مسیرهای ربات مپ می‌شوند
        switch ($text) {
            case '🏠 منوی اصلی':
                $this->sessions->clear((int) $update->userId());
                $this->showMainMenu((int) $update->chatId(), $user, $isAdmin);

                return;
            case '🛒 فروشگاه':
                $this->showShop((int) $update->chatId(), $user, PackageRepository::KIND_AGENCY);

                return;
            case '👤 حساب من':
                $this->showAccount((int) $update->chatId(), $user);

                return;
            case '🎫 پشتیبانی':
                $this->supportCenter()->start((int) $update->chatId(), $user);

                return;
        }

        $this->bot->sendMessage((int) $update->chatId(), 'برای مشاهدهٔ گزینه‌ها روی دکمه‌های زیر بزنید 👇', [
            'reply_markup' => $this->bot->buildMarkup($this->mainMenuKeyboard($user, $isAdmin)),
        ]);
    }

    // ------------------------------------------------------------------
    // نشست‌ها
    // ------------------------------------------------------------------

    /**
     * پردازش پیام بر اساس وضعیت نشست جاری.
     *
     * @param  array<string, mixed> $user
     * @param  array<string, mixed> $state
     * @return bool true یعنی پیام در این مسیر مصرف شد
     */
    private function handleSessionState(Update $update, array $user, array $state): bool
    {
        $chatId     = (int) $update->chatId();
        $telegramId = (int) $update->userId();
        $text       = $update->text();
        $step       = (string) ($state['step'] ?? '');

        switch ($step) {
            case 'coupon:apply':
                if ($update->command() === 'cancel' || $update->command() === 'start') {
                    $this->sessions->clear($telegramId);
                    $this->bot->sendMessage($chatId, '❌ لغو شد.');
                    return true;
                }

                if ($text === '') {
                    $this->bot->sendMessage($chatId, '⚠️ متن خالی است. کد تخفیف را بفرستید یا /cancel را بزنید.');
                    return true;
                }

                $this->handleCouponInput($chatId, $telegramId, $user, $text);
                return true;

            case 'ticket:category':
                $category = Str::toEnglishDigits(trim($text));

                if ($update->command() === 'cancel' || $update->command() === 'start') {
                    $this->sessions->clear($telegramId);
                    $this->bot->sendMessage($chatId, '❌ لغو شد.');
                    return true;
                }

                $this->sessions->clear($telegramId);
                $this->supportCenter()->askBody($chatId, $telegramId, 0, $category);
                return true;

            case 'ticket:body':
                $ticketId = (int) ($state['ticket_id'] ?? 0);
                $category = (string) ($state['category'] ?? 'other');

                if ($update->command() === 'cancel' || $update->command() === 'start') {
                    $this->sessions->clear($telegramId);
                    $this->bot->sendMessage($chatId, '❌ لغو شد.');
                    return true;
                }

                $this->sessions->clear($telegramId);

                if ($text === '') {
                    $this->bot->sendMessage($chatId, '⚠️ متن خالی است. لطفاً پیام خود را بنویسید.');
                    return true;
                }

                $this->supportCenter()->submit($chatId, $user, $category, $text, $ticketId);
                return true;

            case 'panel:username':
                if ($update->command() === 'cancel' || $update->command() === 'start') {
                    $this->sessions->clear($telegramId);
                    $this->bot->sendMessage($chatId, '❌ لغو شد.');
                    return true;
                }

                $username = Str::toEnglishDigits(trim($text));

                if (!Str::isValidPanelUsername($username)) {
                    $this->bot->sendMessage($chatId, '⚠️ نام کاربری نامعتبر است. فقط حروف انگلیسی، عدد و _ مجاز است.');
                    return true;
                }

                $this->panelCenter()->askPassword($chatId, $telegramId, $username);
                return true;

            case 'panel:password':
                $password = $text;
                $username = (string) ($state['username'] ?? '');

                $this->sessions->clear($telegramId);

                if ($password === '' || $username === '') {
                    $this->bot->sendMessage($chatId, '❌ ثبت پنل ناموفق بود. دوباره تلاش کنید.');
                    return true;
                }

                // نکتهٔ حیاتی: نتیجه باید بررسی شود. finishSelfRegister روی
                // خطا فقط آرایه برمی‌گرداند و پیامی نمی‌فرستد — پس اگر اینجا
                // نتیجه نادیده گرفته شود، کاربر رمز اشتباه می‌فرستد و هیچ
                // پاسخی نمی‌گیرد و فکر می‌کند ربات هنگ کرده است.
                $result = $this->panelCenter()->finishSelfRegister($chatId, $user, $username, $password);

                if (!($result['ok'] ?? false)) {
                    // نتیجه ممکن است HTML آماده (مثل Text::loginFailed) یا متن
                    // ساده باشد؛ BotApi خودش در صورت خطای parse به متن ساده
                    // برمی‌گردد، پس اینجا escape نمی‌کنیم تا تگ‌ها خراب نشوند.
                    $this->bot->sendMessage($chatId, (string) $result['message'], [
                        'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                            [['text' => '🔁 تلاش مجدد', 'data' => BotApi::encodeData('panel.self')]],
                            Keyboard::back('menu'),
                        ])),
                    ]);
                }

                return true;

            default:
                // نکتهٔ حیاتی: اینجا نباید نشست پاک شود.
                //
                // این متد فقط مراحل شناخته‌شده را می‌شناسد، ولی Kernel برای هر
                // نشست غیرتهی آن را صدا می‌زند. اگر اینجا clear می‌شد:
                //   • نشست pkg:edit می‌مرد → ویرایش بسته به ساخت بستهٔ تکراری
                //     تبدیل می‌شد (فروش با قیمت اشتباه).
                // پس فقط مسیرهای شناخته‌شده پیام را مصرف می‌کنند.
                return false;
        }
    }

    // ------------------------------------------------------------------
    // رسید کارت‌به‌کارت
    // ------------------------------------------------------------------

    private function handleReceipt(Update $update, array $user): void
    {
        $chatId = (int) $update->chatId();

        // آخرین سفارش‌های در انتظار پرداخت این کاربر
        $orders = $this->orders->listByUser((int) $user['id'], 5, 0, OrderRepository::STATUS_AWAITING_PAYMENT);

        if ($orders === []) {
            $this->bot->sendMessage($chatId, '❗️🧾 سفارش در انتظار پرداختی ندارید! 😔 ابتدا یک بسته انتخاب کنید. 🛒👇');
            return;
        }

        // رسید دستی فقط برای کارت‌به‌کارت دستی است؛ سفارش خودکار رسید نمی‌خواهد.
        $order = null;

        foreach ($orders as $candidate) {
            $method = (string) ($candidate['payment_method'] ?? '');

            if ($method === '' || $method === \Pasargad\Payment\CardToCardGateway::NAME) {
                $order = $candidate;
                break;
            }
        }

        if ($order === null) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '⚡️💳 سفارش خودکار دارید و نیازی به ارسال رسید نیست! 📝❌',
                '',
                'بعد از واریز دقیق مبلغ یکتا، دکمهٔ «🔄 بررسی وضعیت» را بزنید تا خودکار تأیید شود. ✅🤖',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [[
                        'text' => '🔄 بررسی وضعیت',
                        'data' => BotApi::encodeData('order.check', ['id' => (int) $orders[0]['id']]),
                    ]],
                    Keyboard::back('orders'),
                ])),
            ]);

            return;
        }

        $photo  = $update->largestPhoto();
        $fileId = $photo['file_id'] ?? ($update->document()['file_id'] ?? null);

        if ($fileId === null) {
            $this->bot->sendMessage($chatId, '⚠️📸 لطفاً تصویر رسید را به‌صورت عکس بفرستید! 🙏');
            return;
        }

        $result = $this->payments->submitReceipt($order, (string) $fileId);

        $this->bot->sendMessage($chatId, $result['message'], [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [[
                    'text' => '🔄 بررسی وضعیت',
                    'data' => BotApi::encodeData('order.check', ['id' => (int) $order['id']]),
                ]],
                Keyboard::back('orders'),
            ])),
        ]);
    }

    // ------------------------------------------------------------------
    // callback ها
    // ------------------------------------------------------------------

    private function handleCallback(Update $update, array $user, bool $isAdmin): void
    {
        $chatId     = (int) $update->chatId();
        $data       = $update->callbackPayload();
        $ns         = (string) ($data['n'] ?? '');
        // سازگاری با دکمه‌های قدیمی که data خام دارند (مثل 'menu' به‌جای JSON)
        if ($ns === '') {
            $raw = trim((string) ($update->callbackData() ?? ''));
            if ($raw !== '' && $raw[0] !== '{') {
                $ns = $raw;
                // نگاشت نام‌های قدیمی به namespace فعلی
                $ns = match ($ns) {
                    'orders' => 'order.list',
                    'menu' => 'menu',
                    'close' => 'close',
                    'noop' => 'noop',
                    default => $ns,
                };
            }
        }
        $callbackId = (string) ($update->raw()['callback_query']['id'] ?? '');
        $messageId  = (int) ($update->messageId() ?? 0);

        if ($ns === 'close') {
            $this->bot->answerCallback($callbackId);
            $this->bot->deleteMessage($chatId, $messageId);
            return;
        }

        if ($ns === 'noop') {
            $this->bot->answerCallback($callbackId, 'این قابلیت در حال حاضر غیرفعال است.');
            return;
        }

        if ($ns === 'menu') {
            $this->bot->answerCallback($callbackId);
            $this->showMainMenu($chatId, $user, $isAdmin);
            return;
        }

        if ($ns === 'help') {
            $this->bot->answerCallback($callbackId);
            $this->bot->edit($chatId, $messageId, Text::help(), Keyboard::rows([
                [['text' => '📜 قوانین', 'data' => BotApi::encodeData('rules')]],
                Keyboard::back('menu'),
            ]));
            return;
        }

        if ($ns === 'rules') {
            $this->bot->answerCallback($callbackId);
            $this->showRules($chatId, $messageId);
            return;
        }

        if ($ns === 'channel.recheck') {
            $this->channelGuard()->forget((int) $update->userId());
            $this->handleChannelGate($update, $chatId);
            return;
        }

        // ---------------- پنل‌ها ----------------
        if ($ns === 'panel.list') {
            $this->bot->answerCallback($callbackId);
            $this->panelCenter()->showList($chatId, $user);
            return;
        }

        if ($ns === 'panel.view') {
            $this->bot->answerCallback($callbackId);
            $this->panelCenter()->showDetails($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'panel.sync') {
            $this->bot->answerCallback($callbackId, 'در حال بروزرسانی...');
            $this->panelCenter()->sync($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'panel.refresh') {
            $this->bot->answerCallback($callbackId, 'در حال بروزرسانی...');
            $this->refreshAllPanels($chatId, $user);
            return;
        }

        if ($ns === 'panel.self') {
            $this->bot->answerCallback($callbackId);
            $this->panelCenter()->startSelfRegister($chatId, (int) $update->userId());
            return;
        }

        // ---------------- تست کانفیگ ----------------
        if ($ns === 'panel.test') {
            $this->bot->answerCallback($callbackId, 'در حال ساخت کانفیگ تست...');
            $this->panelCenter()->issueTest($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'panel.test.list') {
            $this->bot->answerCallback($callbackId);
            $this->panelCenter()->showTestList($chatId, $user);
            return;
        }

        // ---------------- آمار کاربران پنل ----------------
        if ($ns === 'panel.stats') {
            $this->bot->answerCallback($callbackId, 'در حال شمردن کاربران پنل...');
            $this->panelCenter()->showStats($chatId, $user, (int) ($data['id'] ?? 0), !empty($data['f']));
            return;
        }

        // ---------------- تخفیف و معرفی ----------------
        if ($ns === 'coupon.apply') {
            $this->bot->answerCallback($callbackId);
            $this->askCouponCode($chatId, (int) $update->userId());
            return;
        }

        if ($ns === 'coupon.clear') {
            $this->bot->answerCallback($callbackId);
            $this->clearCoupon($chatId, $user);
            return;
        }

        if ($ns === 'coupon.my') {
            $this->bot->answerCallback($callbackId);
            $this->showMyCoupon($chatId, $user);
            return;
        }

        if ($ns === 'referral.my') {
            $this->bot->answerCallback($callbackId);
            $this->showReferral($chatId, $user);
            return;
        }

        // ---------------- پشتیبانی ----------------
        if ($ns === 'ticket.new') {
            $this->bot->answerCallback($callbackId);
            $this->supportCenter()->askCategory($chatId, (int) $update->userId());
            return;
        }

        if ($ns === 'ticket.topic') {
            $this->bot->answerCallback($callbackId);
            $this->supportCenter()->askBody(
                $chatId,
                (int) $update->userId(),
                0,
                (string) ($data['c'] ?? 'other')
            );
            return;
        }

        if ($ns === 'ticket.list') {
            $this->bot->answerCallback($callbackId);
            $this->supportCenter()->showMyTickets($chatId, $user);
            return;
        }

        if ($ns === 'ticket.view') {
            $this->bot->answerCallback($callbackId);
            $this->supportCenter()->showTicket($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'ticket.reply') {
            $this->bot->answerCallback($callbackId);
            $this->supportCenter()->askBody($chatId, (int) $update->userId(), (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'ticket.close') {
            $this->bot->answerCallback($callbackId);
            $this->supportCenter()->closeTicket($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'panel.test.off') {
            $this->bot->answerCallback($callbackId, 'در حال غیرفعال کردن...');
            $this->panelCenter()->disableTest($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        // ---------------- حساب / کیف پول / پرداخت‌ها ----------------
        if ($ns === 'user.account') {
            $this->bot->answerCallback($callbackId);
            $this->showAccount($chatId, $user);
            return;
        }

        if ($ns === 'user.wallet') {
            $this->bot->answerCallback($callbackId);
            $this->showWallet($chatId, $user);
            return;
        }

        if ($ns === 'user.payments') {
            $this->bot->answerCallback($callbackId);
            $this->showPayments($chatId, $user);
            return;
        }

        if ($ns === 'user.refresh') {
            $this->bot->answerCallback($callbackId, 'در حال بروزرسانی...');
            $this->refreshAllPanels($chatId, $user);
            return;
        }

        // ---------------- فروشگاه ----------------
        if ($ns === 'shop') {
            $this->bot->answerCallback($callbackId);
            $this->showShop($chatId, $user, (string) ($data['kind'] ?? PackageRepository::KIND_AGENCY), (int) ($data['p'] ?? 0));
            return;
        }

        if ($ns === 'pkg') {
            $this->bot->answerCallback($callbackId);
            $this->showPackage($chatId, $user, (int) ($data['id'] ?? 0), (int) ($data['p'] ?? 0));
            return;
        }

        if ($ns === 'pkg.buy') {
            $this->bot->answerCallback($callbackId);
            $this->createOrder($chatId, $user, (int) ($data['id'] ?? 0), (int) ($data['p'] ?? 0));
            return;
        }

        if ($ns === 'pay') {
            $this->bot->answerCallback($callbackId);
            $this->startPayment($chatId, $user, (int) ($data['id'] ?? 0), (string) ($data['m'] ?? ''));
            return;
        }

        // ---------------- سفارش‌ها ----------------
        if ($ns === 'order.list' || $ns === 'orders') {
            $this->bot->answerCallback($callbackId);
            $this->showOrders($chatId, $user, (int) ($data['page'] ?? 0));
            return;
        }

        if ($ns === 'order.view') {
            $this->bot->answerCallback($callbackId);
            $this->showOrderDetails($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'order.check') {
            $this->bot->answerCallback($callbackId, 'در حال بررسی...');
            $this->checkOrder($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'order.invoice') {
            $this->bot->answerCallback($callbackId);
            $this->showInvoice($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        // مسیرهای مخصوص سوپرادمین
        if ($isAdmin && str_starts_with($ns, 'admin.')) {
            $this->bot->answerCallback($callbackId);
            $this->adminController()->route($update, $user, $data, $ns);

            return;
        }

        $this->bot->answerCallback($callbackId, 'این گزینه در دسترس نیست.');
    }

    // ------------------------------------------------------------------
    // نمایش‌ها
    // ------------------------------------------------------------------

    private function showMainMenu(int $chatId, array $user, bool $isAdmin, bool $force = false): void
    {
        $hasPanels = $this->hasPanels($user);

        $this->bot->sendMessage(
            $chatId,
            Text::mainMenu($isAdmin, $hasPanels, $this->settings->bool(Settings::SHOP_OPENED, true)),
            ['reply_markup' => $this->bot->buildMarkup($this->mainMenuKeyboard($user, $isAdmin))]
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function mainMenuKeyboard(array $user, bool $isAdmin): array
    {
        $hasPanels = $this->hasPanels($user);
        $rows      = [];

        if ($hasPanels) {
            $rows[] = [
                ['text' => '🖥️✨ پنل‌های من', 'data' => BotApi::encodeData('panel.list')],
                ['text' => '👤✨ حساب من', 'data' => BotApi::encodeData('user.account')],
            ];
            $rows[] = [
                ['text' => '🛒💎 خرید پنل نمایندگی', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_AGENCY])],
                ['text' => '⚡️🔋 شارژ پنل', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_TOPUP])],
            ];
            $rows[] = [
                ['text' => '🧾📦 سفارش‌ها', 'data' => BotApi::encodeData('order.list')],
                ['text' => '🧪🎁 تست کانفیگ', 'data' => BotApi::encodeData('panel.test.list')],
            ];
            $rows[] = [
                ['text' => '💰👛 کیف پول', 'data' => BotApi::encodeData('user.wallet')],
                ['text' => '💳📊 تاریخچه پرداخت‌ها', 'data' => BotApi::encodeData('user.payments')],
            ];
        } else {
            $rows[] = [[
                'text' => '🛒💎 خرید پنل نمایندگی',
                'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_AGENCY]),
            ]];
            $rows[] = [[
                'text' => '🔗 من پنل دارم',
                'data' => BotApi::encodeData('panel.self'),
            ]];
            $rows[] = [['text' => '🧾📦 سفارش‌ها', 'data' => BotApi::encodeData('order.list')]];
            $rows[] = [
                ['text' => '💰👛 کیف پول', 'data' => BotApi::encodeData('user.wallet')],
                ['text' => '💳📊 تاریخچه پرداخت‌ها', 'data' => BotApi::encodeData('user.payments')],
            ];
        }

        $rows[] = [
            ['text' => '🎟️ کد تخفیف', 'data' => BotApi::encodeData('coupon.apply')],
            ['text' => '🎁 دعوت دوست', 'data' => BotApi::encodeData('referral.my')],
        ];

        $rows[] = [
            ['text' => '📜⚖️ قوانین', 'data' => BotApi::encodeData('rules')],
            ['text' => '❓📖 راهنما', 'data' => BotApi::encodeData('help')],
        ];

        if ($this->flags->isTicketsEnabled()) {
            $rows[] = [
                ['text' => '🎫 پشتیبانی', 'data' => BotApi::encodeData('ticket.new')],
                ['text' => '📋 تیکت‌های من', 'data' => BotApi::encodeData('ticket.list')],
            ];
        }

        if ($isAdmin) {
            $rows[] = [['text' => '🛠✨ پنل مدیریت', 'data' => BotApi::encodeData('admin.home')]];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function hasPanels(array $user): bool
    {
        return $this->panels->countByUser((int) ($user['id'] ?? 0)) > 0;
    }

    private function showRules(int $chatId, int $messageId = 0): void
    {
        $text = $this->rulesText();
        $rows = Keyboard::rows([
            [['text' => '🖥 پنل‌های من', 'data' => BotApi::encodeData('panel.list')]],
            Keyboard::back('menu'),
        ]);

        if ($messageId > 0) {
            $this->bot->edit($chatId, $messageId, $text, $rows);
            return;
        }

        $this->bot->sendMessage($chatId, $text, ['reply_markup' => $this->bot->buildMarkup($rows)]);
    }

    /**
     * متن قوانین؛ اگر ادمین متنی تنظیم کرده باشد همان استفاده می‌شود.
     */
    public function rulesText(): string
    {
        $custom = trim((string) $this->settings->get(Settings::RULES_TEXT, ''));

        return $custom !== '' ? $custom : Settings::DEFAULT_RULES;
    }

    private function showAccount(int $chatId, array $user): void
    {
        $fresh   = $this->users->findById((int) $user['id']) ?? $user;
        $panels  = $this->panels->listByUser((int) $fresh['id']);

        $this->bot->sendMessage($chatId, Text::account($fresh, $panels), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '🖥️✨ پنل‌های من', 'data' => BotApi::encodeData('panel.list')]],
                [
                    ['text' => '💰👛 کیف پول', 'data' => BotApi::encodeData('user.wallet')],
                    ['text' => '💳📊 تاریخچه پرداخت‌ها', 'data' => BotApi::encodeData('user.payments')],
                ],
                [['text' => '🔄 بروزرسانی از پنل', 'data' => BotApi::encodeData('user.refresh')]],
                Keyboard::back('menu', '🔙 بازگشت به منوی اصلی 🏠'),
            ])),
        ]);
    }

    /**
     * 💰 کیف پول کاربر + تاریخچه تراکنش‌ها.
     *
     * @param array<string, mixed> $user
     */
    private function showWallet(int $chatId, array $user): void
    {
        $fresh = $this->users->findById((int) $user['id']) ?? $user;
        $txns = [];

        try {
            $txns = $this->users->walletHistory((int) $fresh['id'], 10);
        } catch (\Throwable $e) {
            $txns = [];
        }

        $this->bot->sendMessage($chatId, Text::wallet($fresh, $txns), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [
                    ['text' => '🧾📦 سفارش‌ها', 'data' => BotApi::encodeData('order.list')],
                    ['text' => '💳📊 تاریخچه پرداخت‌ها', 'data' => BotApi::encodeData('user.payments')],
                ],
                Keyboard::back('menu', '🔙 بازگشت به منوی اصلی 🏠'),
            ])),
        ]);
    }

    /**
     * 💳 تاریخچه پرداخت‌های کاربر.
     *
     * @param array<string, mixed> $user
     */
    private function showPayments(int $chatId, array $user): void
    {
        $payments = [];

        try {
            $payments = $this->orders->paymentsForUser((int) $user['id'], 20);
        } catch (\Throwable $e) {
            $payments = [];
        }

        $this->bot->sendMessage($chatId, Text::paymentHistory($payments), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [
                    ['text' => '💰👛 کیف پول', 'data' => BotApi::encodeData('user.wallet')],
                    ['text' => '🧾📦 سفارش‌ها', 'data' => BotApi::encodeData('order.list')],
                ],
                Keyboard::back('menu', '🔙 بازگشت به منوی اصلی 🏠'),
            ])),
        ]);
    }

    /**
     * بروزرسانی همهٔ پنل‌های کاربر از پنل.
     *
     * @param array<string, mixed> $user
     */
    private function refreshAllPanels(int $chatId, array $user): void
    {
        $result = $this->provisioner->syncUserPanels($user);

        $icon = $result['ok'] ? '✅' : '⚠️';

        $this->bot->sendMessage($chatId, $icon . ' ' . Str::escape((string) $result['message']), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '🖥 مشاهدهٔ پنل‌ها', 'data' => BotApi::encodeData('panel.list')]],
                Keyboard::back('menu'),
            ])),
        ]);
    }

    // ------------------------------------------------------------------
    // فروشگاه
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     */
    private function showShop(int $chatId, array $user, string $kind = PackageRepository::KIND_AGENCY, int $panelId = 0): void
    {
        if (!$this->shopIsOpen()) {
            $this->bot->sendMessage($chatId, Text::shopClosed());
            return;
        }

        if (!$this->flags->isBotEnabled()) {
            $this->bot->sendMessage($chatId, $this->flags->disabledNotice());
            return;
        }

        // «شارژ پنل» بدون داشتن پنل معنا ندارد؛ کاربر را به خرید پنل هدایت می‌کنیم.
        if ($kind === PackageRepository::KIND_TOPUP && !$this->hasPanels($user)) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '⚠️🖥️ برای شارژ، اول باید یک پنل نمایندگی داشته باشید! 😔',
                '',
                'اگر از قبل پنل دارید، آن را با دکمهٔ «🔗 من پنل دارم» اضافه کنید! 🔌✨',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [[
                        'text' => '🛒 خرید پنل نمایندگی',
                        'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_AGENCY]),
                    ]],
                    [['text' => '🔗 من پنل دارم', 'data' => BotApi::encodeData('panel.self')]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return;
        }

        $packages = $this->packages->activePackagesByKind($kind);

        if ($packages === []) {
            $this->bot->sendMessage($chatId, '📦 در حال حاضر بسته‌ای در این بخش موجود نیست.', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('menu')])),
            ]);

            return;
        }

        // خرید پنل نمایندگی وقتی ممکن نیست که اکانت سازندهٔ پنل تنظیم نشده باشد.
        if ($kind === PackageRepository::KIND_AGENCY) {
            $agency = new AgencyService();

            if (!$agency->canCreatePanels()['ok']) {
                $this->bot->sendMessage($chatId, implode("\n", [
                    '⛔️🔧 <b>خرید پنل نمایندگی موقتاً در دسترس نیست! 😔</b>',
                    '',
                    Str::escape($agency->canCreatePanels()['message']),
                ]), [
                    'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                        Keyboard::back('menu', '🏠 بازگشت به منو'),
                    ])),
                ]);

                return;
            }
        }

        $keyboard = [];

        foreach ($packages as $package) {
            $keyboard[] = [[
                'text' => Str::truncate((string) $package['title'], 30),
                'data' => BotApi::encodeData('pkg', ['id' => (int) $package['id'], 'p' => $panelId]),
            ]];
        }

        $otherKind = $kind === PackageRepository::KIND_AGENCY
            ? PackageRepository::KIND_TOPUP
            : PackageRepository::KIND_AGENCY;

        $keyboard[] = [[
            'text' => '🔄 بخش دیگر',
            'data' => BotApi::encodeData('shop', ['kind' => $otherKind]),
        ]];
        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, Text::shopList($kind), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * صفحهٔ یک بسته + دکمهٔ خرید.
     *
     * برای بستهٔ شارژ، اگر کاربر بیش از یک پنل داشته باشد، اول باید پنل هدف را
     * انتخاب کند — وگرنه شارژ به‌طور مبهم روی «تازه‌ترین» پنل اعمال می‌شد.
     *
     * @param array<string, mixed> $user
     */
    private function showPackage(int $chatId, array $user, int $packageId, int $panelId = 0): void
    {
        // کلید فروشگاه هم اینجا بررسی می‌شود، نه فقط در صفحهٔ فروشگاه.
        // دلیل: پیام تأیید خرید قبلاً در چت کاربر مانده و دکمهٔ «خرید» روی آن
        // هنوز کلیک‌پذیر است؛ اگر اینجا کلید بررسی نشود، بستن فروشگاه عملاً
        // هیچ اثری روی این کاربران ندارد.
        if (!$this->shopIsOpen()) {
            $this->bot->sendMessage($chatId, Text::shopClosed());
            return;
        }

        $package = $this->packages->find($packageId);

        if ($package === null || (int) $package['is_active'] !== 1) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $kind    = (string) $package['kind'];
        $panels  = $this->panels->listByUser((int) $user['id']);

        // ---- شارژ: انتخاب پنل هدف ----
        if ($kind === PackageRepository::KIND_TOPUP) {
            if ($panelId > 0) {
                $target = $this->panels->findForUser($panelId, (int) $user['id']);

                if ($target === null) {
                    $this->bot->sendMessage($chatId, Text::notFound());
                    return;
                }
            } elseif (count($panels) === 1) {
                // فقط یک پنل دارد → انتخابی در کار نیست، مستقیم همان.
                // (وگرنه کاربر برای هر خرید یک کلیک اضافه می‌زد بی‌دلیل.)
                $panelId = (int) $panels[0]['id'];
                $target  = $panels[0];
            } else {
                $lines = [
                    '🖥 <b>کدام پنل را شارژ می‌کنید؟</b>',
                    '',
                    '📦 بسته: <b>' . Str::escape((string) $package['title']) . '</b>',
                    '💾 حجم: <b>' . Str::faNumber((float) $package['volume_gb'], 1) . ' گیگابایت</b>',
                    '👥 سقف کاربران: <b>' . PackageRepository::userLimitLabel($package['max_users'] ?? 0) . '</b> 🎯',
                    '💰 مبلغ: <b>' . Str::formatToman((int) $package['price_toman']) . '</b>',
                ];

                $keyboard = [];

                foreach ($panels as $panel) {
                    $keyboard[] = [[
                        'text' => Str::truncate((string) $panel['panel_username'], 26),
                        'data' => BotApi::encodeData('pkg', ['id' => $packageId, 'p' => (int) $panel['id']]),
                    ]];
                }

                $keyboard[] = Keyboard::back(
                    BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_TOPUP]),
                    '⬅️ بازگشت'
                );

                $this->bot->sendMessage($chatId, implode("\n", $lines), [
                    'reply_markup' => $this->bot->buildMarkup($keyboard),
                ]);

                return;
            }
        }

        $keyboard = [[[
            'text' => '🛒 خرید این بسته',
            'data' => BotApi::encodeData('pkg.buy', ['id' => $packageId, 'p' => $panelId]),
        ]]];

        if ($kind === PackageRepository::KIND_TOPUP && $panelId > 0) {
            $target  = $this->panels->find($panelId);
            $keyboard[] = [[
                'text' => '🔄 تغییر پنل',
                'data' => BotApi::encodeData('pkg', ['id' => $packageId, 'p' => 0]),
            ]];

            $this->bot->sendMessage($chatId, Text::confirmPurchase($package, (string) ($target['panel_username'] ?? ''))
                . "\n\n" . Text::packageDetails($package), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows($keyboard)),
            ]);

            return;
        }

        $keyboard[] = [[
            'text' => '⬅️ بازگشت به فروشگاه',
            'data' => BotApi::encodeData('shop', ['kind' => $kind]),
        ]];

        $this->bot->sendMessage($chatId, Text::confirmPurchase($package) . "\n\n" . Text::packageDetails($package), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function shopIsOpen(): bool
    {
        return $this->settings->bool(Settings::SHOP_OPENED, true);
    }

    // ------------------------------------------------------------------
    // کد تخفیف
    // ------------------------------------------------------------------

    /**
     * درخواست وارد کردن کد تخفیف.
     */
    private function askCouponCode(int $chatId, int $telegramId): void
    {
        if (!$this->flags->isCouponsEnabled()) {
            $this->bot->sendMessage($chatId, '🎟️😴 کد تخفیف موقتاً غیرفعال است!');
            return;
        }

        $this->sessions->set($telegramId, ['step' => 'coupon:apply']);

        $this->bot->sendMessage($chatId, implode("\n", [
            '🎟️✨ <b>کد تخفیف خود را بفرستید</b>',
            '',
            'کد را تنها بفرستید تا روی خرید بعدی اعمال شود، یا همراه قیمت کل بنویسید '
                . 'تا ببینید چقدر کم می‌شود (مثلاً: <code>SUMMER 500000</code>).',
            '',
            'برای انصراف /cancel را بزنید. 🙃',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back('menu', '🔙 بازگشت به منو'),
            ])),
        ]);
    }

    /**
     * پردازش کد تخفیف ارسالی کاربر.
     *
     * @param array<string, mixed> $user
     */
    private function handleCouponInput(int $chatId, int $telegramId, array $user, string $text): void
    {
        $parts = preg_split('/[\s,،]+/u', trim($text)) ?: [];

        $code  = strtoupper((string) ($parts[0] ?? ''));
        $price = isset($parts[1]) ? (int) preg_replace('/\D/', '', $parts[1]) : 0;

        $quote = $this->discounts()->quote($code, (int) $user['id'], $price);

        if (!($quote['ok'] ?? false)) {
            $this->sessions->clear($telegramId);

            $this->bot->sendMessage($chatId, '❌ ' . Str::escape((string) $quote['message']), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🎟️ کد دیگری امتحان کنم', 'data' => BotApi::encodeData('coupon.apply')]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return;
        }

        $couponId = (int) $quote['coupon']['id'];

        // کد روی رکورد کاربر ذخیره می‌شود (نه نشست) تا اگر کاربر امروز کد
        // وارد کرد و فردا خرید کرد، تخفیف هنوز برایش باشد.
        $this->users->setCouponCode((int) $user['id'], $code);
        $this->sessions->clear($telegramId);

        $lines = [
            '✅ ' . Str::escape((string) $quote['message']),
            '',
            '🎟️ کد: <code>' . Str::escape($code) . '</code>',
        ];

        if ($price > 0) {
            $final = max(0, $price - (int) $quote['discount']);

            $lines[] = '💰 قیمت: ' . Str::formatToman($price);
            $lines[] = '🎉 تخفیف: <b>-' . Str::formatToman((int) $quote['discount']) . '</b>';
            $lines[] = '💵 پرداختی: <b>' . Str::formatToman($final) . '</b>';
        } else {
            $lines[] = '';
            $lines[] = 'این کد روی خرید بعدی شما اعمال می‌شود. 🛒';
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [
                    ['text' => '🛒 فروشگاه', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_AGENCY])],
                    ['text' => '🎟️ کد تخفیف من', 'data' => BotApi::encodeData('coupon.my')],
                ],
                [['text' => '🗑️ حذف کد', 'data' => BotApi::encodeData('coupon.clear')]],
                Keyboard::back('menu'),
            ])),
        ]);
    }

    /**
     * حذف کد تخفیف فعال کاربر.
     *
     * @param array<string, mixed> $user
     */
    private function clearCoupon(int $chatId, array $user): void
    {
        $cleared = $this->users->clearCouponCode((int) $user['id']);

        $this->bot->sendMessage($chatId, $cleared
            ? '🗑️ کد تخفیف حذف شد.'
            : 'ℹ️ کد تخفیف فعالی نداشتید.');
    }

    /**
     * کد تخفیف فعال کاربر.
     *
     * @param array<string, mixed> $user
     */
    private function showMyCoupon(int $chatId, array $user): void
    {
        $code = $this->users->couponCode((int) $user['id']);

        if ($code === '') {
            $this->bot->sendMessage($chatId, '🎟️ کد تخفیف فعالی ندارید.', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🎟️ وارد کردن کد', 'data' => BotApi::encodeData('coupon.apply')]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return;
        }

        $this->bot->sendMessage($chatId, '🎟️ کد تخفیف فعال شما: <code>' . Str::escape($code) . "</code>\n\n"
            . 'این کد روی خرید بعدی اعمال می‌شود. 🛒', [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '🗑️ حذف کد', 'data' => BotApi::encodeData('coupon.clear')]],
                Keyboard::back('menu'),
            ])),
        ]);
    }

    // ------------------------------------------------------------------
    // معرفی دوستان
    // ------------------------------------------------------------------

    /**
     * صفحهٔ «🎁 دعوت دوست» + کد معرفی و آمار.
     *
     * @param array<string, mixed> $user
     */
    private function showReferral(int $chatId, array $user): void
    {
        if (!$this->flags->isReferralEnabled()) {
            $this->bot->sendMessage($chatId, '🎁 سیستم معرفی موقتاً غیرفعال است. 😴');
            return;
        }

        $summary = $this->discounts()->referralSummary((int) $user['id']);
        $percent = max(0, min(100, (int) $this->settings->int(Settings::REFERRAL_DISCOUNT, 10)));
        $botName = Config::str('bot_username', '');

        $lines = [
            '🎁✨ <b>دعوت دوست به ربات</b>',
            '',
            '🔗 کد شما: <code>' . Str::escape($summary['code']) . '</code>',
        ];

        if ($botName !== '') {
            $lines[] = '';
            $lines[] = '📣 لینک دعوت:';
            $lines[] = '<code>' . Str::escape('https://t.me/' . $botName . '?start=' . $summary['code']) . '</code>';
        }

        $lines[] = '';
        $lines[] = '👥 دعوت‌شده‌ها: <b>' . Str::faNumber($summary['invited']) . '</b>';
        $lines[] = '✅ پاداش داده‌شده: <b>' . Str::faNumber($summary['rewarded']) . '</b>';
        $lines[] = '';
        $lines[] = '🎉 دوست شما با این کد <b>' . Str::faNumber($percent) . '٪</b> تخفیف می‌گیرد.';
        $lines[] = '💰 شما به ازای هر خرید موفق او <b>' . Str::formatToman($summary['bonus_each'])
            . '</b> پاداش می‌گیرید (به کیف پولتان اضافه می‌شود).';

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '👛 کیف پول من', 'data' => BotApi::encodeData('user.wallet')]],
                Keyboard::back('menu'),
            ])),
        ]);
    }

    /**
     * ثبت کد معرفی از `/start ref_CODE` یا لینک دعوت.
     *
     * @param  array<string, mixed> $user
     * @return array{ok:bool, referrer:?array<string, mixed>, message:string}
     */
    public function bindReferral(array $user, string $code): array
    {
        $none = ['ok' => false, 'referrer' => null, 'message' => ''];

        if (!$this->flags->isReferralEnabled() || trim($code) === '') {
            return $none;
        }

        $referrer = $this->discounts()->referrals()->findReferrerByCode($code);

        if ($referrer === null) {
            return ['ok' => false, 'referrer' => null, 'message' => 'کد معرفی نامعتبر است. 🔍'];
        }

        $bonus = max(0, (int) $this->settings->int(Settings::REFERRAL_BONUS, 50000));

        $result = $this->discounts()->referrals()->bind(
            (int) $referrer['id'],
            (int) $user['id'],
            $bonus
        );

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'referrer' => null, 'message' => (string) $result['message']];
        }

        $percent = max(0, min(100, (int) $this->settings->int(Settings::REFERRAL_DISCOUNT, 10)));

        return [
            'ok'       => true,
            'referrer' => $referrer,
            'message'  => '🎉 کد معرفی ثبت شد! اولین خرید شما <b>' . Str::faNumber($percent)
                . '٪</b> ارزان‌تر است. 🛒',
        ];
    }

    /**
     * ساخت سفارش.
     *
     * @param  array<string, mixed> $user
     */
    private function createOrder(int $chatId, array $user, int $packageId, int $panelId = 0): void
    {
        if (!$this->shopIsOpen()) {
            $this->bot->sendMessage($chatId, Text::shopClosed());
            return;
        }

        $package = $this->packages->find($packageId);

        if ($package === null || (int) $package['is_active'] !== 1) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $minOrder = Config::int('store.min_order_toman', 50000);
        if ((int) $package['price_toman'] < $minOrder) {
            $this->bot->sendMessage($chatId, '⚠️💰 قیمت این بسته کمتر از حداقل مجاز است! 😔');
            return;
        }

        $kind = (string) $package['kind'];

        // ------------------------------------------------------------------
        // شارژ بدون پنل معتبر انجام نمی‌شود.
        //
        // اگر کاربر چند پنل دارد، panelId از دکمهٔ انتخاب پنل آمده است. اگر
        // نیامده (یعنی پنلِ دیگری را دستکاری کرده) باید متعلق به خودش باشد،
        // وگرنه سفارش پایانی می‌شود و پولش گم می‌شود.
        // ------------------------------------------------------------------
        $resolvedPanelId = 0;

        if ($kind === PackageRepository::KIND_TOPUP) {
            if ($panelId > 0) {
                if ($this->panels->findForUser($panelId, (int) $user['id']) === null) {
                    $this->bot->sendMessage($chatId, '🚫🔒 این پنل به حساب شما تعلق ندارد! 😔');
                    return;
                }

                $resolvedPanelId = $panelId;
            } else {
                $primary = $this->panels->primaryForUser($user);

                if ($primary === null) {
                    $this->bot->sendMessage($chatId, '⚠️🖥️ ابتدا باید یک پنل نمایندگی داشته باشید! 😔', [
                        'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                            [[
                                'text' => '🛒 خرید پنل نمایندگی',
                                'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_AGENCY]),
                            ]],
                            Keyboard::back('menu'),
                        ])),
                    ]);

                    return;
                }

                $resolvedPanelId = (int) $primary['id'];
            }
        }

        $order = null;

        // ------------------------------------------------------------------
        // محاسبهٔ تخفیف (کد تخفیف و/یا پاداش معرفی).
        //
        // این بیرون از تراکنش انجام می‌شود چون فقط *محاسبه* است؛ مصرف واقعی
        // کد داخل همان تراکنشِ ساخت سفارش و فقط در صورت ساخت موفق انجام می‌شود
        // (وگرنه کدی که نتوانسته استفاده شود، سوخته می‌خورد).
        // ------------------------------------------------------------------
        $listPrice  = (int) $package['price_toman'];
        $discount   = $this->resolveDiscount($user, $listPrice);
        $finalPrice = max(0, $listPrice - $discount['discount']);

        // حداقل مبلغ خرید **بعد** از تخفیف سنجیده می‌شود. اگر قبل از تخفیف
        // سنجیده شود، یک بستهٔ ۵۰ هزار تومانی با کد ۱۰۰٪ عملاً رایگان می‌شد و
        // ربات بی‌دلیل بار پنل می‌داد.
        if ($finalPrice < $minOrder) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '⚠️💰 با این میزان تخفیف، مبلغ سفارش از حداقل مجاز کمتر می‌شود! 😔',
                '',
                'حداقل مبلغ خرید: <b>' . Str::formatToman($minOrder) . '</b>',
                'تخفیف قابل اعمال: <b>' . Str::formatToman($discount['discount']) . '</b>',
                '',
                'لطفاً کد تخفیف را حذف کنید یا بستهٔ بزرگ‌تری انتخاب کنید.',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🗑️ حذف کد تخفیف', 'data' => BotApi::encodeData('coupon.clear')]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return;
        }

        // ------------------------------------------------------------------
        // بررسی سقف خرید و ساخت سفارش، داخل یک تراکنش.
        //
        // بدون تراکنش، دو کلیک سریع روی دکمهٔ خرید (که تلگرام به‌صورت دو
        // callback_query جدا می‌فرستد و دو پروسهٔ جدا پردازش می‌کنند) هر دو
        // مقدار یکسانی می‌خوانند، هر دو از سقف عبور می‌کنند و کاربر دو برابر
        // حجم می‌گیرد — در حالی که max_per_user = 1 است.
        // ------------------------------------------------------------------
        try {
            $this->orders->transaction(function () use ($user, $package, $resolvedPanelId, $discount, $listPrice, $finalPrice, &$order): void {
                $maxPerUser = (int) $package['max_per_user'];

                if ($maxPerUser > 0) {
                    $purchased = $this->packages->purchasedCount((int) $user['id'], (string) $package['kind']);

                    if ($purchased >= $maxPerUser) {
                        throw new ShopException(
                            '⚠️🛑 شما حداکثر <b>' . Str::faNumber($maxPerUser) . '</b> بسته از این نوع خریده‌اید! 😔'
                        );
                    }
                }

                $order = $this->orders->create((int) $user['id'], [
                    'package_id'    => (int) $package['id'],
                    'package_title' => (string) $package['title'],
                    'kind'          => (string) $package['kind'],
                    'volume_gb'     => (float) $package['volume_gb'],
                    'bonus_gb'      => (float) ($package['bonus_gb'] ?? 0),
                    'duration_days' => (int) $package['duration_days'],
                    'max_users'     => max(0, (int) ($package['max_users'] ?? 0)),
                    // price_toman مبلغ *قابل پرداخت* است (بعد از تخفیف) چون
                    // درگاه پرداخت، بررسی IPN و اجرای بسته همه همین را می‌خوانند.
                    'price_toman'   => $finalPrice,
                    'original_price_toman' => $listPrice,
                    'discount_toman' => (int) $discount['discount'],
                    'coupon_code'   => $discount['code'] !== '' ? (string) $discount['code'] : null,
                    'referred_by'   => $discount['referral'] !== '' ? (string) $discount['referral'] : null,
                    'panel_id'      => $resolvedPanelId > 0 ? $resolvedPanelId : null,
                    'status'        => OrderRepository::STATUS_CREATED,
                ]);

                // مصرف کد فقط حالا که سفارش واقعاً ساخته شد.
                if ((int) $discount['discount'] > 0 && $discount['coupon_id'] > 0) {
                    $this->discounts()->consumeCoupon(
                        (int) $discount['coupon_id'],
                        (int) $user['id'],
                        (int) $order['id'],
                        (int) $discount['discount']
                    );

                    // کد تخفیف یک‌بارمصرف است (مگر per_user_limit بیشتر باشد)، پس
                    // بعد از مصرف از کاربر برداشته می‌شود تا روی خرید بعدی دوباره
                    // اعمال نشود.
                    $this->users->clearCouponCode((int) $user['id']);
                }
            });
        } catch (ShopException $e) {
            // خطای قابل انتظار (سقف خرید) — فقط پیام را نشان می‌دهیم.
            $this->bot->sendMessage($chatId, $e->getMessage());
            return;
        }

        if ($order === null) {
            return;
        }

        $this->users->refreshOrderStats((int) $user['id']);
        $this->showPaymentMethods($chatId, $order, $discount);
    }

    /**
     * بهترین تخفیف قابل اعمال برای این کاربر.
     *
     * قاعده: اگر کد تخفیف فعال دارد، همان برنده است. پاداش معرفی فقط وقتی
     * اعمال می‌شود که کد تخفیفی در کار نباشد — وگرنه دو تخفیف روی هم جمع
     * می‌شد و یک بستهٔ گران عملاً رایگان می‌شد.
     *
     * @param  array<string, mixed> $user
     * @return array{discount:int, code:string, coupon_id:int, referral:string}
     */
    private function resolveDiscount(array $user, int $listPrice): array
    {
        $none = ['discount' => 0, 'code' => '', 'coupon_id' => 0, 'referral' => ''];

        $userId = (int) $user['id'];
        $code   = $this->users->couponCode($userId);

        if ($code !== '' && $this->flags->isCouponsEnabled()) {
            $quote = $this->discounts()->quote($code, $userId, $listPrice);

            if ($quote['ok'] ?? false) {
                $bind = $this->discounts()->referrals()->findByReferee($userId);

                return [
                    'discount'  => (int) $quote['discount'],
                    'code'      => $code,
                    'coupon_id' => (int) $quote['coupon']['id'],
                    'referral'  => $bind !== null ? (string) $bind['code'] : '',
                ];
            }

            // کد بی‌اعتبار شده (منقضی/ظرفیت تمام) → بی‌صدا دور می‌رود تا
            // خرید کاربر به خاطر کد خراب قفل نشود.
            $this->users->clearCouponCode($userId);
        }

        $referral = $this->discounts()->referralDiscount($userId, $listPrice);

        if ($referral['ok'] ?? false) {
            $bind = $this->discounts()->referrals()->findByReferee($userId);

            return [
                'discount'  => (int) $referral['discount'],
                'code'      => '',
                'coupon_id' => 0,
                'referral'  => $bind !== null ? (string) $bind['code'] : '',
            ];
        }

        return $none;
    }

    /**
     * @param array<string, mixed> $order
     * @param array{discount:int, code:string, coupon_id:int, referral:string} $discount
     *        خروجی `resolveDiscount()`؛ عمداً **بدون مقدار پیش‌فرض** چون
     *        شکلش دقیقاً همین است و `[]` باعث می‌شد هر خواندنی از کلید
     *        `discount` روی آرایهٔ خالی undefined بدهد. اگر روزی فراخوان بدون
     *        تخفیف اضافه شد، باید صریح `$this->resolveDiscount($user, …)` بگیرد.
     */
    private function showPaymentMethods(int $chatId, array $order, array $discount): void
    {
        $gateways = $this->payments->activeGateways();

        if ($gateways === []) {
            $this->bot->sendMessage($chatId, '⚠️💳 هیچ روش پرداختی فعال نیست! 😔 با پشتیبانی تماس بگیرید. 📞🙏');
            return;
        }

        $keyboard = [];

        foreach ($gateways as $gateway) {
            $keyboard[] = [[
                'text' => $gateway->title(),
                'data' => BotApi::encodeData('pay', ['id' => (int) $order['id'], 'm' => $gateway->name()]),
            ]];
        }

        $keyboard[] = Keyboard::back('menu');

        $lines = [Text::paymentMethods(), '', '💳 سفارش: <code>' . $order['code'] . '</code>'];

        if ((int) ($order['discount_toman'] ?? 0) > 0) {
            $original = (int) ($order['original_price_toman'] ?? (int) $order['price_toman']);

            $lines[] = '💰 قیمت پایه: ' . Str::formatToman($original);
            $lines[] = '🎉 تخفیف: <b>-' . Str::formatToman((int) $order['discount_toman']) . '</b>';

            if (!empty($order['coupon_code'])) {
                $lines[] = '🎟️ کد: <code>' . Str::escape((string) $order['coupon_code']) . '</code>';
            } elseif (!empty($order['referred_by'])) {
                $lines[] = '🎁 پاداش معرفی اعمال شد';
            }
        }

        $lines[] = '';
        $lines[] = '💵 <b>مبلغ قابل پرداخت: ' . Str::formatToman((int) $order['price_toman']) . '</b>';

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function startPayment(int $chatId, array $user, int $orderId, string $method): void
    {
        $order = $this->orders->findForUser($orderId, (int) $user['id']);

        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        // سفارش‌هایی که قبلاً ساخته شده‌اند باید قابل پرداخت بمانند، حتی اگر
        // فروشگاه بسته شود — کاربر پولش را در راه است. فقط سفارش «جدید» متوقف
        // می‌شود که در createOrder کنترل شده است.
        $result = $this->payments->startPayment($order, $method, $chatId);

        if (!($result['ok'] ?? false)) {
            $this->bot->sendMessage($chatId, '❌ ' . Str::escape((string) $result['message']));
            return;
        }

        $keyboard = [];

        if (!empty($result['pay_url'])) {
            $keyboard[] = Keyboard::link('💳 پرداخت در درگاه', (string) $result['pay_url']);
        }

        // اگر درگاه لینک نداد، کاربر هیچ راهی برای پرداخت ندارد. صریح بگوییم
        // به‌جای اینکه پیام موفق نشان داده شود و دکمه بی‌صدا حذف شود.
        if ($method === NowPaymentsGateway::NAME && $keyboard === []) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '⚠️🔧 <b>درگاه ارز دیجیتال لینک پرداخت برنگرداند! 😔</b>',
                '',
                'لطفاً روش پرداخت دیگری را انتخاب کنید یا با پشتیبانی تماس بگیرید. 📞🙏',
                'اگر پولی واریز کرده‌اید، رسید را نگه دارید. 🧾',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🧾 جزئیات سفارش', 'data' => BotApi::encodeData('order.view', ['id' => $orderId])]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return;
        }

        $keyboard[] = [['text' => '🔄 بررسی وضعیت', 'data' => BotApi::encodeData('order.check', ['id' => $orderId])]];
        $keyboard[] = [['text' => '🧾 جزئیات سفارش', 'data' => BotApi::encodeData('order.view', ['id' => $orderId])]];
        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, (string) $result['message'], [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showOrders(int $chatId, array $user, int $page): void
    {
        $all   = $this->orders->listByUser((int) $user['id'], 50);
        $items = array_map(static fn (array $o): array => ['id' => (int) $o['id'], 'title' => (string) $o['package_title'] . ' • ' . $o['code']], $all);

        $keyboard = [];

        foreach (array_chunk($items, 5) as $index => $chunk) {
            if ($index !== $page) {
                continue;
            }

            foreach ($chunk as $item) {
                $keyboard[] = [[
                    'text' => Str::truncate((string) $item['title'], 34),
                    'data' => BotApi::encodeData('order.view', ['id' => (int) $item['id']]),
                ]];
            }
        }

        $totalPages = max(1, (int) ceil(count($items) / 5));
        $nav        = [];

        if ($page > 0) {
            $nav[] = ['text' => '◀️ قبلی', 'data' => BotApi::encodeData('order.list', ['page' => $page - 1])];
        }

        if ($page < $totalPages - 1) {
            $nav[] = ['text' => 'بعدی ▶️', 'data' => BotApi::encodeData('order.list', ['page' => $page + 1])];
        }

        if ($nav !== []) {
            $keyboard[] = $nav;
        }

        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, Text::orderList(array_slice($all, $page * 5, 5)), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showOrderDetails(int $chatId, array $user, int $orderId): void
    {
        $order = $this->orders->findForUser($orderId, (int) $user['id']);

        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $keyboard = [];

        if ($order['status'] === OrderRepository::STATUS_AWAITING_PAYMENT) {
            $keyboard[] = [['text' => '🔄 بررسی وضعیت', 'data' => BotApi::encodeData('order.check', ['id' => $orderId])]];
        }

        // اگر سفارش ساخت پنل بوده، مستقیم به همان پنل می‌رود.
        if (!empty($order['panel_id'])) {
            $keyboard[] = [['text' => '🖥 مشاهدهٔ پنل', 'data' => BotApi::encodeData('panel.view', ['id' => (int) $order['panel_id']])]];
        }

        // 🧾 فاکتور — فقط برای سفارش پرداخت‌شده. سفارش پرداخت‌نشده فاکتور
        // ندارد چون هنوز پولی جابه‌جا نشده و ممکن است لغو شود.
        if (Invoice::isPayable($order)) {
            $keyboard[] = [['text' => '🧾 فاکتور خرید', 'data' => BotApi::encodeData('order.invoice', ['id' => $orderId])]];
        }

        $keyboard[] = Keyboard::back('orders');

        $this->bot->sendMessage($chatId, Text::orderDetails($order), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * 🧾 فاکتور خرید (+ لینک قابل چاپ).
     */
    private function showInvoice(int $chatId, array $user, int $orderId): void
    {
        $order = $this->orders->findForUser($orderId, (int) $user['id']);

        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        if (!Invoice::isPayable($order)) {
            $this->bot->sendMessage($chatId, '🧾 فاکتور فقط برای سفارش‌های پرداخت‌شده صادر می‌شود.');
            return;
        }

        $order = Invoice::ensureToken($this->orders, $order);
        $url   = Invoice::url($order);

        $rows = [];

        // دکمهٔ لینک فقط وقتی ساخته می‌شود که لینک **واقعاً** قابل ساخت باشد.
        // اگر base_url تنظیم نشده باشد، دکمهٔ url نسبی کل کیبورد را
        // بی‌سروصدا نابود می‌کند و کاربر پیام «از دکمهٔ زیر» را با
        // کیبوردی بدون دکمه می‌بیند. بهتر است صریح بگوییم چه شده.
        if ($url !== '') {
            $rows[] = [['text' => '🖨️ باز کردن فاکتور قابل چاپ', 'url' => $url]];
        } else {
            $this->bot->sendMessage($chatId, '⚠️🖨️ لینک فاکتور قابل چاپ در دسترس نیست '
                . '(<code>base_url</code> در config.php تنظیم نشده است). متن فاکتور پایین آمده ✅');
        }

        $rows[] = [['text' => '🧾 جزئیات سفارش', 'data' => BotApi::encodeData('order.view', ['id' => $orderId])]];
        $rows[] = Keyboard::back('orders');

        $this->bot->sendMessage(
            $chatId,
            Invoice::telegramText($order, (string) ($user['first_name'] ?? '')),
            ['reply_markup' => $this->bot->buildMarkup(Keyboard::rows($rows))]
        );
    }

    private function checkOrder(int $chatId, array $user, int $orderId): void
    {
        $order = $this->orders->findForUser($orderId, (int) $user['id']);

        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $result = $this->payments->checkAndMaybeApply($order);
        $fresh  = $this->orders->find($orderId) ?? $order;

        $icon = ($result['paid'] ?? false) ? '✅' : '⏳';

        $this->bot->sendMessage(
            $chatId,
            $icon . ' ' . Str::escape((string) $result['message']) . "\n\n" . Text::orderDetails($fresh),
            ['reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('orders')]))]
        );
    }

    /**
     * دستور /buy با کد سفارش — نمایش جزئیات سفارش برای خود کاربر.
     *
     * @param array<string, mixed> $user
     */
    private function handleBuyCommand(Update $update, array $user): void
    {
        $chatId = (int) $update->chatId();
        $args   = $update->args();
        $code   = $args[0] ?? '';

        if ($code === '') {
            $this->bot->sendMessage($chatId, "ℹ️📝 قالب دستور: <code>/buy ORD-XXXXXX</code>\n\n🧾 سفارش‌های من: /orders");
            return;
        }

        $order = $this->orders->findForUser(
            (int) ($this->orders->findByCode(strtoupper($code))['id'] ?? 0),
            (int) $user['id']
        );

        if ($order === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->showOrderDetails($chatId, $user, (int) $order['id']);
    }

    private function safeReply(int $chatId, string $text): void
    {
        try {
            $this->bot->sendMessage($chatId, $text);
        } catch (\Throwable $e) {
            Logger::error('Failed to send error message', ['error' => $e->getMessage()]);
        }
    }
}