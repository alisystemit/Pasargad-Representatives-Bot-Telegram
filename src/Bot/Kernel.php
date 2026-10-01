<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Payment\PaymentService;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;
use Pasargad\Store\Provisioner;
use Pasargad\Store\Settings;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;
use Pasargad\Telegram\Update;

/**
 * کنترلرر اصلی ربات: مسیریابی آپدیت‌ها، مدیریت نشست کاربر و ارائهٔ منوها.
 *
 * جریان کلی:
 *   آپدیت → AuthService (احراز هویت کاربر) → Router (callback یا پیام) → Handler
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
    private ?\Pasargad\Panel\PasarGuardClient $panel = null;

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
        ?\Pasargad\Panel\PasarGuardClient $panel = null
    ) {
        $this->bot        = $bot ?? new BotApi();
        $this->notifier   = $notifier ?? new Notifier($this->bot);
        $this->users      = $users ?? new UserRepository();
        $this->packages   = $packages ?? new PackageRepository();
        $this->orders     = $orders ?? new OrderRepository();
        $this->settings   = $settings ?? new Settings();
        $this->sessions   = $sessions ?? new SessionStore();
        $this->panel      = $panel;
        $this->provisioner = $provisioner ?? new Provisioner($panel, $this->orders, $this->users, $this->settings);
        $this->payments   = $payments ?? new PaymentService($this->orders, $this->provisioner, $this->settings);

        $this->payments->setNotifier($this->notifier);
    }

    public function notifier(): Notifier
    {
        return $this->notifier;
    }

    public function botApi(): BotApi
    {
        return $this->bot;
    }

    /**
     * پردازش یک آپدیت تلگرام.
     */
    public function handle(Update $update): void
    {
        $userId = $update->userId();
        $chatId = $update->chatId();

        if ($userId === null || $chatId === null) {
            return;
        }

        // ثبت/به‌روزرسانی کاربر
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

        try {
            if ($update->isCallbackQuery()) {
                $this->handleCallback($update, $user, $isAdmin);
            } else {
                $this->handleMessage($update, $user, $isAdmin);
            }
        } catch (\Throwable $e) {
            Logger::error('Update handling failed', [
                'user_id' => $userId,
                'update'  => $update->updateId(),
                'error'   => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);

            $this->safeReply($chatId, '⚠️ خطایی رخ داد. لطفاً دوباره تلاش کنید.');
        }
    }

    // ------------------------------------------------------------------
    // پیام‌های متنی
    // ------------------------------------------------------------------

    private function handleMessage(Update $update, array $user, bool $isAdmin): void
    {
        $chatId = (int) $update->chatId();
        $text   = $update->text();
        $state  = $this->sessions->get($update->userId());

        // مسیر ورود (state machine ساده)
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

        // دستورها
        if ($update->isCommand()) {
            $this->handleCommand($update, $user, $isAdmin);
            return;
        }

        // مدیریت بسته‌ها با پیام متنی (فقط سوپرادمین)
        if ($isAdmin && str_contains($text, '|')) {
            $editor = new PackageEditor($this->packages, $this->bot);
            if ($editor->tryHandle($chatId, $text, (int) $update->userId())) {
                return;
            }
        }

        // پاسخ به دکمه‌های متنی منو
        $this->handleMenuText($update, $user, $isAdmin, $text);
    }

    private function handleCommand(Update $update, array $user, bool $isAdmin): void
    {
        $chatId = (int) $update->chatId();

        switch ($update->command()) {
            case 'start':
            case 'menu':
                $this->sessions->clear((int) $update->userId());
                $name = (string) ($user['first_name'] ?? $user['username'] ?? 'دوست عزیز');
                $this->bot->sendMessage($chatId, Text::welcome($name, $this->isLinked($user)));
                $this->showMainMenu($chatId, $user, $isAdmin);
                break;

            case 'shop':
                $this->showShop($chatId, $user);
                break;

            case 'account':
            case 'profile':
                $this->showAccount($chatId, $user);
                break;

            case 'orders':
                $this->showOrders($chatId, $user, 0);
                break;

            case 'login':
                $this->sessions->set((int) $update->userId(), ['step' => 'await_username']);
                $this->bot->sendMessage($chatId, Text::loginAskUsername(), [
                    'reply_markup' => $this->bot->buildMarkup(Keyboard::back('menu', '❌ انصراف')),
                ]);
                break;

            case 'logout':
                $this->users->unlinkPanel((int) $user['id']);
                $this->bot->sendMessage($chatId, "🔌 <b>اتصال پنل قطع شد.</b>\n\nبرای استفاده دوباره /login را بزنید.");
                $this->showMainMenu($chatId, $user, $isAdmin, false);
                break;

            case 'help':
                $this->bot->sendMessage($chatId, Text::help(), [
                    'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('menu')])),
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
        $chatId = (int) $update->chatId();
        $this->bot->sendMessage($chatId, 'برای مشاهدهٔ گزینه‌ها روی دکمه‌های زیر بزنید 👇', [
            'reply_markup' => $this->bot->buildMarkup($this->mainMenuKeyboard($user, $isAdmin)),
        ]);
    }

    // ------------------------------------------------------------------
    // نشست ورود
    // ------------------------------------------------------------------

    /**
     * پردازش پیام بر اساس وضعیت نشست جاری (مثلاً مراحل ورود).
     *
     * @param  array<string, mixed> $user
     * @param  array<string, mixed> $state وضعیت خوانده‌شده از SessionStore
     * @return bool true یعنی پیام در این مسیر مصرف شد
     */
    private function handleSessionState(Update $update, array $user, array $state): bool
    {
        $chatId  = (int) $update->chatId();
        $telegramId = (int) $update->userId();
        $text     = $update->text();
        $step     = (string) ($state['step'] ?? '');

        switch ($step) {
            case 'await_username':
                // کاربر می‌تواند با /cancel یا دکمهٔ «❌ انصراف» (پیام خالی) عملیات را لغو کند.
                if ($update->command() === 'cancel' || $update->command() === 'start') {
                    $this->sessions->clear($telegramId);
                    $this->bot->sendMessage($chatId, '❌ لغو شد.');
                    return true;
                }

                $username = \Pasargad\Support\Str::toEnglishDigits(trim($text));
                if (!\Pasargad\Support\Str::isValidPanelUsername($username)) {
                    $this->bot->sendMessage($chatId, '⚠️ نام کاربری نامعتبر است. فقط حروف انگلیسی، عدد و _ مجاز است.');
                    return true;
                }

                $this->sessions->set($telegramId, ['step' => 'await_password', 'username' => $username]);
                $this->bot->sendMessage($chatId, Text::loginAskPassword(), [
                    'reply_markup' => $this->bot->buildMarkup(Keyboard::back('menu', '❌ انصراف')),
                ]);
                return true;

            case 'await_password':
                $password = $text;
                $session  = $this->sessions->pull($telegramId);
                $username = (string) ($session['username'] ?? '');

                if ($password === '' || $username === '') {
                    $this->bot->sendMessage($chatId, '❌ ورود ناموفق بود. دوباره تلاش کنید.');
                    return true;
                }

                $this->attemptLogin($chatId, $user, $username, $password);
                return true;

            default:
                $this->sessions->clear($telegramId);
                return false;
        }
    }

    private function attemptLogin(int $chatId, array $user, string $username, string $password): void
    {
        try {
            $panel   = $this->panelClient();
            $admin   = $panel->getAdmin($username, $username, $password);

            $this->users->linkPanel((int) $user['id'], $username, $password, $admin);

            $fresh = $this->users->findById((int) $user['id']) ?? $user;
            $this->bot->sendMessage($chatId, Text::loginSuccess($admin));
            $this->showMainMenu($chatId, $fresh, $this->notifier->isAdmin((int) $user['telegram_id']));
        } catch (\Pasargad\Panel\PanelException $e) {
            $this->bot->sendMessage($chatId, Text::loginFailed($e->getMessage()), [
                'reply_markup' => $this->bot->buildMarkup([[
                    ['text' => '🔁 تلاش مجدد', 'data' => \Pasargad\Telegram\BotApi::encodeData('user.login')],
                ], Keyboard::back('menu')]),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Login attempt failed', ['error' => $e->getMessage()]);
            $this->bot->sendMessage($chatId, Text::loginFailed('خطای غیرمنتظره هنگام اتصال به پنل.'));
        }
    }

    // ------------------------------------------------------------------
    // رسید کارت‌به‌کارت
    // ------------------------------------------------------------------

    private function handleReceipt(Update $update, array $user): void
    {
        $chatId = (int) $update->chatId();

        // آخرین سفارش در انتظار پرداخت این کاربر
        $orders = $this->orders->listByUser((int) $user['id'], 1, 0, OrderRepository::STATUS_AWAITING_PAYMENT);
        if ($orders === []) {
            $this->bot->sendMessage($chatId, '❗️ سفارش در انتظار پرداختی ندارید. ابتدا یک بسته انتخاب کنید.');
            return;
        }

        $order = $orders[0];
        $photo = $update->largestPhoto();
        $fileId = $photo['file_id'] ?? ($update->document()['file_id'] ?? null);

        if ($fileId === null) {
            $this->bot->sendMessage($chatId, '⚠️ لطفاً تصویر رسید را به‌صورت عکس بفرستید.');
            return;
        }

        $result = $this->payments->submitReceipt($order, (string) $fileId);

        $this->bot->sendMessage($chatId, $result['message'], [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [[
                    'text' => '🔄 بررسی وضعیت',
                    'data' => \Pasargad\Telegram\BotApi::encodeData('order.check', ['id' => (int) $order['id']]),
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
        $chatId = (int) $update->chatId();
        $data   = $update->callbackPayload();
        $ns     = (string) ($data['n'] ?? '');
        $callbackId = (string) ($update->raw()['callback_query']['id'] ?? '');

        // بستن پیام
        if ($ns === 'close') {
            $this->bot->answerCallback($callbackId);
            $this->bot->deleteMessage($chatId, (int) $update->messageId());
            return;
        }

        if ($ns === 'menu') {
            $this->bot->answerCallback($callbackId);
            $this->showMainMenu($chatId, $user, $isAdmin);
            return;
        }

        if ($ns === 'help') {
            $this->bot->answerCallback($callbackId);
            $this->bot->edit($chatId, (int) $update->messageId(), Text::help(), Keyboard::rows([Keyboard::back('menu')]));
            return;
        }

        if ($ns === 'user.login') {
            $this->bot->answerCallback($callbackId);
            $this->sessions->set((int) $update->userId(), ['step' => 'await_username']);
            $this->bot->sendMessage($chatId, Text::loginAskUsername(), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::back('menu', '❌ انصراف')),
            ]);
            return;
        }

        if ($ns === 'user.account') {
            $this->bot->answerCallback($callbackId);
            $this->showAccount($chatId, $user);
            return;
        }

        if ($ns === 'user.refresh') {
            $this->bot->answerCallback($callbackId);
            $result = $this->provisioner->syncUser($user);
            $fresh  = $this->users->findById((int) $user['id']) ?? $user;
            $this->bot->edit($chatId, (int) $update->messageId(), Text::account($fresh) . "\n\nℹ️ " . $result['message']);
            return;
        }

        if ($ns === 'shop') {
            $this->bot->answerCallback($callbackId);
            $kind = (string) ($data['kind'] ?? PackageRepository::KIND_PANEL_QUOTA);
            $this->showShop($chatId, $user, $kind);
            return;
        }

        if ($ns === 'pkg') {
            $this->bot->answerCallback($callbackId);
            $this->showPackage($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'pkg.buy') {
            $this->bot->answerCallback($callbackId);
            $this->createOrder($chatId, $user, (int) ($data['id'] ?? 0));
            return;
        }

        if ($ns === 'pay') {
            $this->bot->answerCallback($callbackId);
            $this->startPayment($chatId, $user, (int) ($data['id'] ?? 0), (string) ($data['m'] ?? ''));
            return;
        }

        if ($ns === 'order.list') {
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

        if ($ns === 'user.credit') {
            $this->bot->answerCallback($callbackId);
            $this->showCreditInfo($chatId, $user);
            return;
        }

        // مسیرهای مخصوص سوپرادمین
        if ($isAdmin && str_starts_with($ns, 'admin.')) {
            $this->bot->answerCallback($callbackId);
            $this->adminRouter($update, $user, $data, $ns, $isAdmin);
            return;
        }

        $this->bot->answerCallback($callbackId, 'این گزینه در دسترس نیست.');
    }

    // ------------------------------------------------------------------
    // نمایش‌ها
    // ------------------------------------------------------------------

    private function showMainMenu(int $chatId, array $user, bool $isAdmin, bool $force = false): void
    {
        $linked = $this->isLinked($user);

        $this->bot->sendMessage($chatId, Text::mainMenu($isAdmin, $linked, $this->settings->bool(Settings::SHOP_OPENED, true)), [
            'reply_markup' => $this->bot->buildMarkup($this->mainMenuKeyboard($user, $isAdmin)),
        ]);
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function mainMenuKeyboard(array $user, bool $isAdmin): array
    {
        $linked = $this->isLinked($user);

        $rows = [];

        if ($linked) {
            $rows[] = [
                ['text' => '🛒 خرید بسته', 'data' => \Pasargad\Telegram\BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_PANEL_QUOTA])],
                ['text' => '👤 حساب من', 'data' => \Pasargad\Telegram\BotApi::encodeData('user.account')],
            ];
            $rows[] = [
                ['text' => '🧾 سفارش‌ها', 'data' => \Pasargad\Telegram\BotApi::encodeData('order.list')],
                ['text' => '🎁 اعتبار کاربر', 'data' => \Pasargad\Telegram\BotApi::encodeData('user.credit')],
            ];
        } else {
            $rows[] = [['text' => '🔐 اتصال به پنل', 'data' => \Pasargad\Telegram\BotApi::encodeData('user.login')]];
        }

        $rows[] = [['text' => '❓ راهنما', 'data' => \Pasargad\Telegram\BotApi::encodeData('help')]];

        if ($isAdmin) {
            $rows[] = [['text' => '🛠 پنل مدیریت', 'data' => \Pasargad\Telegram\BotApi::encodeData('admin.home')]];
        }

        return $rows;
    }

    private function showAccount(int $chatId, array $user): void
    {
        $linked = ($user['panel_username'] ?? null) !== null;

        if (!$linked) {
            $this->bot->sendMessage($chatId, '⚠️ ابتدا باید به پنل وصل شوید.', [
                'reply_markup' => $this->bot->buildMarkup([[
                    ['text' => '🔐 اتصال به پنل', 'data' => \Pasargad\Telegram\BotApi::encodeData('user.login')],
                ], Keyboard::back('menu')]),
            ]);
            return;
        }

        $keyboard = Keyboard::rows([
            [['text' => '🔄 بروزرسانی از پنل', 'data' => \Pasargad\Telegram\BotApi::encodeData('user.refresh')]],
            Keyboard::back('menu'),
        ]);

        $this->bot->sendMessage($chatId, Text::account($user), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showShop(int $chatId, array $user, string $kind = PackageRepository::KIND_PANEL_QUOTA): void
    {
        if (!$this->settings->bool(Settings::SHOP_OPENED, true)) {
            $this->bot->sendMessage($chatId, '🛒 فروشگاه موقتاً بسته است.');
            return;
        }

        if (($user['panel_username'] ?? null) === null) {
            $this->bot->sendMessage($chatId, '⚠️ برای خرید ابتدا به پنل وصل شوید.', [
                'reply_markup' => $this->bot->buildMarkup([[
                    ['text' => '🔐 اتصال به پنل', 'data' => \Pasargad\Telegram\BotApi::encodeData('user.login')],
                ]]),
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

        $keyboard = [];
        foreach ($packages as $package) {
            $keyboard[] = [[
                'text' => Str::truncate((string) $package['title'], 30),
                'data' => \Pasargad\Telegram\BotApi::encodeData('pkg', ['id' => (int) $package['id']]),
            ]];
        }

        $otherKind = $kind === PackageRepository::KIND_PANEL_QUOTA
            ? PackageRepository::KIND_USER_CREDIT
            : PackageRepository::KIND_PANEL_QUOTA;

        $keyboard[] = [['text' => '🔄 بخش دیگر', 'data' => \Pasargad\Telegram\BotApi::encodeData('shop', ['kind' => $otherKind])]];
        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, Text::shopList($kind), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function showPackage(int $chatId, array $user, int $packageId): void
    {
        $package = $this->packages->find($packageId);
        if ($package === null || (int) $package['is_active'] !== 1) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $keyboard = Keyboard::rows([
            [[
                'text' => '🛒 خرید این بسته',
                'data' => \Pasargad\Telegram\BotApi::encodeData('pkg.buy', ['id' => $packageId]),
            ]],
            [['text' => '⬅️ بازگشت به فروشگاه', 'data' => \Pasargad\Telegram\BotApi::encodeData('shop', ['kind' => (string) $package['kind']])]],
        ]);

        $this->bot->sendMessage($chatId, Text::confirmPurchase($package) . "\n\n" . Text::packageDetails($package), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    private function createOrder(int $chatId, array $user, int $packageId): void
    {
        $package = $this->packages->find($packageId);
        if ($package === null || (int) $package['is_active'] !== 1) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $minOrder = Config::int('store.min_order_toman', 50000);
        if ((int) $package['price_toman'] < $minOrder) {
            $this->bot->sendMessage($chatId, '⚠️ قیمت این بسته کمتر از حداقل مجاز است.');
            return;
        }

        // بررسی سقف خرید هر کاربر
        $maxPerUser = (int) $package['max_per_user'];
        if ($maxPerUser > 0) {
            $purchased = $this->packages->purchasedCount((int) $user['id'], (string) $package['kind']);
            if ($purchased >= $maxPerUser) {
                $this->bot->sendMessage(
                    $chatId,
                    '⚠️ شما حداکثر <b>' . Str::faNumber($maxPerUser) . '</b> بسته از این نوع خریده‌اید.'
                );
                return;
            }
        }

        $totalGb = (float) $package['volume_gb'] + (float) ($package['bonus_gb'] ?? 0);

        $order = $this->orders->create((int) $user['id'], [
            'package_id'    => (int) $package['id'],
            'package_title' => (string) $package['title'],
            'kind'          => (string) $package['kind'],
            'volume_gb'     => (float) $package['volume_gb'],
            'bonus_gb'      => (float) ($package['bonus_gb'] ?? 0),
            'duration_days' => (int) $package['duration_days'],
            'price_toman'   => (int) $package['price_toman'],
            'status'        => OrderRepository::STATUS_CREATED,
        ]);

        $this->users->refreshOrderStats((int) $user['id']);
        $this->showPaymentMethods($chatId, $order);
    }

    private function showPaymentMethods(int $chatId, array $order): void
    {
        $gateways = $this->payments->activeGateways();

        if ($gateways === []) {
            $this->bot->sendMessage($chatId, '⚠️ هیچ روش پرداختی فعال نیست. با پشتیبانی تماس بگیرید.');
            return;
        }

        $keyboard = [];
        foreach ($gateways as $gateway) {
            $keyboard[] = [[
                'text' => $gateway->title(),
                'data' => \Pasargad\Telegram\BotApi::encodeData('pay', ['id' => (int) $order['id'], 'm' => $gateway->name()]),
            ]];
        }

        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, Text::paymentMethods() . "\n\n💳 سفارش: <code>" . $order['code'] . '</code>', [
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

        $result = $this->payments->startPayment($order, $method, $chatId);

        if (!($result['ok'] ?? false)) {
            $this->bot->sendMessage($chatId, '❌ ' . Str::escape((string) $result['message']));
            return;
        }

        $keyboard = [];

        if (!empty($result['pay_url'])) {
            $keyboard[] = Keyboard::link('💳 پرداخت در درگاه', (string) $result['pay_url']);
        }

        $keyboard[] = [['text' => '🔄 بررسی وضعیت', 'data' => \Pasargad\Telegram\BotApi::encodeData('order.check', ['id' => $orderId])]];
        $keyboard[] = [['text' => '🧾 جزئیات سفارش', 'data' => \Pasargad\Telegram\BotApi::encodeData('order.view', ['id' => $orderId])]];
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
                    'data' => \Pasargad\Telegram\BotApi::encodeData('order.view', ['id' => (int) $item['id']]),
                ]];
            }
        }

        $totalPages = max(1, (int) ceil(count($items) / 5));
        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '◀️ قبلی', 'data' => \Pasargad\Telegram\BotApi::encodeData('order.list', ['page' => $page - 1])];
        }
        if ($page < $totalPages - 1) {
            $nav[] = ['text' => 'بعدی ▶️', 'data' => \Pasargad\Telegram\BotApi::encodeData('order.list', ['page' => $page + 1])];
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
            $keyboard[] = [['text' => '🔄 بررسی وضعیت', 'data' => \Pasargad\Telegram\BotApi::encodeData('order.check', ['id' => $orderId])]];
        }

        $keyboard[] = Keyboard::back('orders');

        $this->bot->sendMessage($chatId, Text::orderDetails($order), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
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

    private function showCreditInfo(int $chatId, array $user): void
    {
        $credit = (int) ($user['user_credit'] ?? 0);
        $expire = $user['user_credit_expire'] ?? null;

        $lines = [
            '🎁 <b>اعتبار ساخت کاربر</b>',
            '',
            '💾 اعتبار فعلی: <b>' . Str::formatBytes($credit) . '</b>',
        ];

        if ($expire !== null) {
            $lines[] = '📅 انقضا: ' . Str::date((int) $expire);
        }

        if ($credit <= 0) {
            $lines[] = '';
            $lines[] = 'ℹ️ برای افزایش اعتبار، بستهٔ «اعتبار کاربر» را از فروشگاه بخرید.';
        }

        $lines[] = '';
        $lines[] = 'ℹ️ با این اعتبار می‌توانید برای مشتریان خود کاربر جدید بسازید.';

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '🛒 خرید اعتبار', 'data' => \Pasargad\Telegram\BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_USER_CREDIT])]],
                Keyboard::back('menu'),
            ])),
        ]);
    }

    /**
     * کلاینت پنل (در صورت تزریق‌نشدن، از تنظیمات ساخته می‌شود).
     */
    private function panelClient(): \Pasargad\Panel\PasarGuardClient
    {
        if ($this->panel === null) {
            $this->panel = new \Pasargad\Panel\PasarGuardClient();
        }

        return $this->panel;
    }

    /**
     * آیا کاربر به پنل متصل و فعال است؟
     *
     * @param  array<string, mixed> $user
     */
    private function isLinked(array $user): bool
    {
        return $this->isLinkedUser($user);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isLinkedUser(array $user): bool
    {
        return ($user['panel_username'] ?? null) !== null
            && in_array((string) $user['panel_status'], ['active', 'limited'], true);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function handleBuyCommand(Update $update, array $user): void
    {
        $chatId = (int) $update->chatId();
        $args   = $update->args();
        $code   = $args[0] ?? '';

        if ($code === '') {
            $this->bot->sendMessage($chatId, "ℹ️ قالب دستور: <code>/buy ORD-XXXXXX</code>\n\nسفارش‌های من: /orders");
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

    /**
     * مسیریابی callback های مدیریتی (در AdminController پیاده‌سازی می‌شود).
     */
    private function adminRouter(Update $update, array $user, array $data, string $ns, bool $isAdmin): void
    {
        $admin = new AdminController(
            $this->bot,
            $this->notifier,
            $this->users,
            $this->packages,
            $this->orders,
            $this->provisioner,
            $this->payments,
            $this->settings
        );

        $admin->route($update, $user, $data, $ns);
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