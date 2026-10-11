<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Panel\PasarGuardClient;
use Pasargad\Panel\PanelException;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\SessionStore;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\Bot;

/**
 * مدیریت ایجاد و اتصال پنل‌های جدید
 * - درخواست اطلاعات پنل
 * - تست اتصال
 * - ذخیره اتصال موفق
 * - مدیریت خطاها
 */
final class PanelConnectionManager
{
    private Bot $bot;
    private PanelRepository $panels;
    private SessionStore $sessions;

    public function __construct(
        Bot $bot,
        PanelRepository $panels,
        SessionStore $sessions
    ) {
        $this->bot = $bot;
        $this->panels = $panels;
        $this->sessions = $sessions;
    }

    /**
     * نمایش منوی ایجاد اتصال جدید
     */
    public function showCreateConnectionMenu(int $chatId, int $telegramId): void
    {
        $message = MessageFormatter::info('🔗 ایجاد اتصال پنل جدید', [
            '📍 آدرس پنل' => 'https://your-panel.com',
            '👤 نام کاربری' => 'نام کاربری مدیریتی',
            '🔑 رمز عبور یا API Key' => 'توضیح اتصال',
        ]);

        $message .= "\n\n<b>انتخاب نوع اتصال:</b>\n";

        $keyboard = [
            [['text' => '🔐 رمز عبور', 'callback_data' => 'panel:connect:password']],
            [['text' => '🔑 API Key', 'callback_data' => 'panel:connect:apikey']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'panel_menu']],
        ];

        $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
    }

    /**
     * شروع اتصال با رمز عبور
     */
    public function startPasswordConnection(int $chatId, int $telegramId): void
    {
        $this->sessions->set($telegramId, [
            'mode' => 'panel_connect',
            'auth_type' => 'password',
            'step' => 'awaiting_url',
        ]);

        $message = MessageFormatter::inputRequest(
            'آدرس پنل پاسارگاد',
            'آدرس کامل پنل شامل پروتکل https:// یا http://',
            'https://panel.example.com'
        );

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * شروع اتصال با API Key
     */
    public function startApiKeyConnection(int $chatId, int $telegramId): void
    {
        $this->sessions->set($telegramId, [
            'mode' => 'panel_connect',
            'auth_type' => 'apikey',
            'step' => 'awaiting_url',
        ]);

        $message = MessageFormatter::inputRequest(
            'آدرس پنل پاسارگاد',
            'آدرس کامل پنل شامل پروتکل https:// یا http://',
            'https://panel.example.com'
        );

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * پردازش ورودی آدرس پنل
     */
    public function handlePanelUrl(int $chatId, int $telegramId, string $url): void
    {
        $url = trim($url);

        // بررسی URL
        $error = ErrorHandler::validateInput($url, 'url');
        if ($error !== null) {
            $this->bot->sendMessage($chatId, MessageFormatter::validationError('آدرس پنل', $error));
            return;
        }

        // ذخیره URL
        $state = $this->sessions->get($telegramId) ?? [];
        $state['panel_url'] = $url;
        $state['step'] = 'awaiting_username';
        $this->sessions->set($telegramId, $state);

        // درخواست نام کاربری
        $message = MessageFormatter::inputRequest(
            'نام کاربری مدیریتی',
            'نام کاربری حساب مدیریتی در پنل',
            'admin'
        );

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * پردازش ورودی نام کاربری
     */
    public function handleUsername(int $chatId, int $telegramId, string $username): void
    {
        $username = trim($username);

        // بررسی نام کاربری
        $error = ErrorHandler::validateInput($username, 'username', ['min_length' => 3]);
        if ($error !== null) {
            $this->bot->sendMessage($chatId, MessageFormatter::validationError('نام کاربری', $error));
            return;
        }

        // ذخیره نام کاربری
        $state = $this->sessions->get($telegramId) ?? [];
        $state['username'] = $username;
        $state['step'] = 'awaiting_credential';
        $this->sessions->set($telegramId, $state);

        // درخواست رمز عبور یا API Key
        $authType = $state['auth_type'] ?? 'password';
        if ($authType === 'password') {
            $message = MessageFormatter::inputRequest(
                'رمز عبور',
                'رمز عبور حساب مدیریتی در پنل',
                '••••••••'
            );
        } else {
            $message = MessageFormatter::inputRequest(
                'API Key',
                'کلید API برای احراز هویت',
                'sk_live_...'
            );
        }

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * پردازش ورودی رمز عبور/API Key و تست اتصال
     */
    public function handleCredentialAndTest(int $chatId, int $telegramId, string $credential): void
    {
        $credential = trim($credential);

        if (empty($credential)) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::validationError(
                    'اعتبارنامه',
                    '⚠️ رمز عبور یا API Key نمی‌تواند خالی باشد.'
                )
            );
            return;
        }

        $state = $this->sessions->get($telegramId) ?? [];
        $panelUrl = $state['panel_url'] ?? null;
        $username = $state['username'] ?? null;
        $authType = $state['auth_type'] ?? 'password';

        if (!$panelUrl || !$username) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::error('خطا در جلسه کار', 'لطفاً دوباره شروع کنید.')
            );
            $this->sessions->clear($telegramId);
            return;
        }

        // نمایش پیام "درحال تست"
        $testingMsg = $this->bot->sendMessage($chatId, MessageFormatter::loading('تست اتصال با پنل'));

        try {
            // تست اتصال
            $client = new PasarGuardClient();
            $testResult = $this->testPanelConnection(
                $panelUrl,
                $username,
                $credential,
                $authType
            );

            if (!$testResult['ok']) {
                $this->bot->editMessageText(
                    $chatId,
                    $testingMsg['result']['message_id'] ?? 0,
                    MessageFormatter::error(
                        'اتصال ناموفق',
                        $testResult['message'] ?? 'خطای نامشخص'
                    )
                );
                return;
            }

            // اتصال موفق - ذخیره
            $adminInfo = $testResult['admin'] ?? [];
            $state['step'] = 'awaiting_name';
            $state['credential'] = $credential;
            $this->sessions->set($telegramId, $state);

            $confirmMsg = "✅ <b>اتصال موفق!</b>\n\n";
            $confirmMsg .= "📊 <b>اطلاعات پنل:</b>\n";
            $confirmMsg .= "  • آدرس: <code>" . Str::escape($panelUrl) . "</code>\n";
            $confirmMsg .= "  • نام کاربری: <code>" . Str::escape($username) . "</code>\n";
            $confirmMsg .= "  • مدیر: " . Str::escape($adminInfo['name'] ?? 'نامشخص') . "\n\n";
            $confirmMsg .= "📝 <b>نام این پنل را وارد کنید</b> (برای نمایش در ربات):\n";
            $confirmMsg .= "(مثال: \"پنل اصلی\" یا \"پنل کاربران\")\n\n";
            $confirmMsg .= "❌ برای لغو: /cancel";

            $this->bot->editMessageText($chatId, $testingMsg['result']['message_id'] ?? 0, $confirmMsg);
        } catch (\Throwable $e) {
            Logger::error('Panel connection test failed', [
                'error' => $e->getMessage(),
                'panel_url' => $panelUrl,
            ]);

            $this->bot->editMessageText(
                $chatId,
                $testingMsg['result']['message_id'] ?? 0,
                MessageFormatter::error(
                    'خطا در تست اتصال',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * تست اتصال با پنل
     */
    private function testPanelConnection(
        string $url,
        string $username,
        string $credential,
        string $authType
    ): array {
        try {
            // بررسی صحت URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return [
                    'ok' => false,
                    'message' => 'آدرس پنل معتبر نیست',
                ];
            }

            // تمیزکردن URL
            $url = rtrim($url, '/');

            // سعی برای اتصال
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url . '/api/check');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return [
                    'ok' => false,
                    'message' => 'پنل در آدرس مشخص‌شده یافت نشد (HTTP ' . $httpCode . ')',
                ];
            }

            // بررسی ساده - اگر پاسخ OK باشد
            if (strpos($response, 'ok') === false && $response !== 'ok') {
                // سعی برای login با credentials
                $loginResult = $this->attemptPanelLogin($url, $username, $credential, $authType);
                if (!$loginResult['ok']) {
                    return $loginResult;
                }
            }

            // اتصال موفق
            return [
                'ok' => true,
                'message' => 'اتصال موفق',
                'admin' => [
                    'name' => $username,
                ],
            ];
        } catch (\Throwable $e) {
            Logger::error('Panel connection test error', ['error' => $e->getMessage()]);
            return [
                'ok' => false,
                'message' => 'خطا: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * سعی برای ورود به پنل
     */
    private function attemptPanelLogin(
        string $url,
        string $username,
        string $credential,
        string $authType
    ): array {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url . '/api/login');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POST, true);

            $postData = [
                'username' => $username,
            ];

            if ($authType === 'password') {
                $postData['password'] = $credential;
            } else {
                $postData['api_key'] = $credential;
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                return [
                    'ok' => false,
                    'message' => 'احراز هویت ناموفق - نام کاربری یا رمز عبور اشتباه است',
                ];
            }

            $result = json_decode($response, true);
            if (($result['ok'] ?? false) === false) {
                return [
                    'ok' => false,
                    'message' => $result['message'] ?? 'احراز هویت ناموفق',
                ];
            }

            return [
                'ok' => true,
                'message' => 'اتصال موفق',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'خطا در بررسی اعتبارات: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * پردازش نام پنل و ذخیره اتصال
     */
    public function handlePanelName(int $chatId, int $telegramId, string $panelName, int $userId): void
    {
        $panelName = trim($panelName);

        if (empty($panelName)) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::validationError('نام پنل', '⚠️ نام پنل نمی‌تواند خالی باشد.')
            );
            return;
        }

        if (mb_strlen($panelName) > 100) {
            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::validationError('نام پنل', '⚠️ نام پنل نمی‌تواند بیش از ۱۰۰ کاراکتر باشد.')
            );
            return;
        }

        $state = $this->sessions->get($telegramId) ?? [];
        $panelUrl = $state['panel_url'] ?? null;
        $username = $state['username'] ?? null;
        $credential = $state['credential'] ?? null;

        if (!$panelUrl || !$username || !$credential) {
            $this->bot->sendMessage($chatId, MessageFormatter::error('خطا', 'اطلاعات ناقص است.'));
            $this->sessions->clear($telegramId);
            return;
        }

        try {
            // ذخیره پنل در دیتابیس
            $panelId = $this->panels->create([
                'user_id' => $userId,
                'panel_name' => $panelName,
                'panel_url' => $panelUrl,
                'panel_username' => $username,
                'panel_password' => $credential, // باید رمزگذاری شود!
                'status' => 'active',
                'created_at' => time(),
            ]);

            $this->sessions->clear($telegramId);

            $successMsg = MessageFormatter::success(
                'پنل با موفقیت اضافه شد! ✨',
                [
                    'نام' => $panelName,
                    'آدرس' => Str::escape($panelUrl),
                    'وضعیت' => 'فعال ✅',
                ]
            );

            $this->bot->sendMessage($chatId, $successMsg);

            // نمایش منوی پنل
            $this->showPanelMenu($chatId, $telegramId);
        } catch (\Throwable $e) {
            Logger::error('Failed to save panel', ['error' => $e->getMessage()]);

            $this->bot->sendMessage(
                $chatId,
                MessageFormatter::error('خطا در ذخیره پنل', $e->getMessage())
            );
        }
    }

    /**
     * نمایش منوی پنل
     */
    public function showPanelMenu(int $chatId, int $telegramId): void
    {
        $message = "🖥️ <b>مدیریت پنل‌ها</b>\n\n";

        $keyboard = [
            [['text' => '➕ اتصال پنل جدید', 'callback_data' => 'panel:connect']],
            [['text' => '📋 لیست پنل‌های من', 'callback_data' => 'panel:list']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'main_menu']],
        ];

        $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
    }

    /**
     * نمایش لیست پنل‌های کاربر
     */
    public function listUserPanels(int $chatId, int $userId): void
    {
        // TODO: دریافت پنل‌های کاربر از دیتابیس
        $panels = []; // $this->panels->findByUserId($userId)

        if (empty($panels)) {
            $message = "📭 <b>پنلی موجود نیست</b>\n\n";
            $message .= "برای شروع، یک پنل جدید اتصال دهید:\n";

            $keyboard = [
                [['text' => '➕ اتصال پنل جدید', 'callback_data' => 'panel:connect']],
                [['text' => '⬅️ بازگشت', 'callback_data' => 'main_menu']],
            ];

            $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
            return;
        }

        $message = "🖥️ <b>پنل‌های شما</b>\n\n";

        foreach ($panels as $panel) {
            $status = $panel['status'] === 'active' ? '✅' : '⛔️';
            $message .= "$status <b>" . Str::escape((string) $panel['panel_name']) . "</b>\n";
            $message .= "   📍 " . Str::escape((string) $panel['panel_url']) . "\n\n";
        }

        $keyboard = [
            [['text' => '➕ اتصال جدید', 'callback_data' => 'panel:connect']],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'main_menu']],
        ];

        $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
    }
}
