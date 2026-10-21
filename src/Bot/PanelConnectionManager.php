<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Store\PanelAccess;
use Pasargad\Store\PanelRepository;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * ساخت اتصال تازه به پنل پاسارگاد از داخل ربات.
 *
 * چرا این مسیر جدا از «من پنل دارم» است؟
 *
 * `PanelCenter` فرض می‌گیرد پنل از قبل روی همان `panel.base_url` نصب است و فقط
 * نام کاربری/رمز می‌خواهد. اما نماینده ممکن است پنلش روی دامنهٔ خودش نصب باشد،
 * یا بخواهد با **کلید API** وصل شود نه رمز عبور. این کلاس جریان کامل را می‌دهد:
 *
 *     آدرس پنل → نام کاربری → رمز/API Key → تست اتصال → ثبت
 *
 * سه نکتهٔ مهم که این کلاس رعایت می‌کند:
 *
 * ۱) **تست قبل از ذخیره.** تا وقتی ورود واقعاً تأیید نشده، چیزی در دیتابیس
 *    نوشته نمی‌شود؛ وگرنه پنل‌های خراب در فهرست نماینده جمع می‌شدند.
 * ۲) **رمز رمزنگاری می‌شود.** `PanelRepository::upsertFromPanel()` خودش
 *    `Crypto::encrypt` را انجام می‌دهد، پس `panel_password` هیچ‌وقت خام نیست.
 * ۳) **کلاینت مشترک، نه curl خام.** `PasarGuardClient` هم SSL را درست بررسی
 *    می‌کند (نسخهٔ قبلی `CURLOPT_SSL_VERIFYPEER` را خاموش کرده بود) و هم
 *    سقف زمانی پروسه را رعایت می‌کند؛ بدون آن یک پنلِ کند می‌تواند وبهوک را از
 *    ۶۰ ثانیهٔ تلگرام رد کند و همان update دوباره فرستاده شود.
 */
final class PanelConnectionManager
{
    /**
     * کلید حالت در `SessionStore`.
     *
     * `Kernel::handleSessionState()` با `switch ($mode)` تصمیم می‌گیرد پیامِ متنیِ
     * بعدی به کدام جریان تعلق دارد. بدون این کلید، پیام‌های این جریان می‌افتند
     * به `default` و `false` برمی‌گردانند — یعنی بی‌صدا هیچ اتفاقی نمی‌افتد.
     */
    public const MODE = 'panel_connect';

    /**
     * مرحله‌های جریان.
     *
     * ⚠️ `Kernel::handleSessionState()` مسیریابیِ پیام‌های نشست را با
     * `switch ($step)` انجام می‌دهد، پس این مقادیر باید **دقیقاً** همان
     * رشته‌هایی باشند که آنجا در `case` آمده‌اند.
     */
    public const STEP_URL        = 'panel_connect_url';
    public const STEP_USERNAME   = 'panel_connect_username';
    public const STEP_CREDENTIAL = 'panel_connect_credential';

    /** بعد از کلید، رمز پشتیبان پرسیده می‌شود (مسیر «کلید + رمز»). */
    public const STEP_PASSWORD = 'panel_connect_password';

    /** حداکثر طول آدرس پنل؛ جلوگیری از ورودی‌های غیرمنطقی. */
    private const MAX_URL_LENGTH = 300;

    private BotApi $bot;
    private PanelRepository $panels;
    private SessionStore $sessions;

    /**
     * کلاینت تستیِ تزریق‌شده (فقط تست‌ها).
     *
     * در محیط واقعی `null` است و کلاینتِ مخصوصِ همان آدرس پنل ساخته می‌شود،
     * چون آدرس هر اتصال می‌تواند فرق کند. تست‌ها یک کلاینت قلابی می‌دهند تا
     * بدون شبکه بتوانند کل جریان را بسنجند.
     */
    private ?PasarGuardClient $injectedClient;

    public function __construct(
        BotApi $bot,
        PanelRepository $panels,
        SessionStore $sessions,
        ?PasarGuardClient $injectedClient = null
    ) {
        $this->bot            = $bot;
        $this->panels         = $panels;
        $this->sessions       = $sessions;
        $this->injectedClient = $injectedClient;
    }

    // ------------------------------------------------------------------
    // منوها
    // ------------------------------------------------------------------

    /**
     * منوی شروع: فقط با کلید API.
     */
    public function showMenu(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            "🔌 <b>اتصال پنل پاسارگاد</b>\n\n"
            . "برای اینکه ربات بتواند پنل شما را مدیریت کند (ساخت نماینده، شارژ "
            . "حجم، کانفیگ و…) فقط به **کلید API** نیاز دارد.\n\n"
            . "🔑 <b>کلید API</b>\n\n"
            . "ساختنش: داخل پنل → بخش تنظیمات / API Keys → ساخت کلید جدید.\n"
            . "دسترسی‌های لازم:\n"
            . "• <code>admins.create</code> — ساخت پنل نمایندگی\n"
            . "• <code>admins.read</code> — دیدن پنل و وضعیت آن\n"
            . "• <code>admins.update</code> — شارژ و تمدید حجم\n"
            . "• <code>users.read</code> — آمار کاربران\n\n"
            . "💡 اگر مطمئن نیستید کلیدتان کافی است، بعد از اتصال این را بزنید:\n"
            . "<code>php tools/panel_key_check.php &lt;کلید&gt;</code>\n\n"
            . "🔒 کلید شما **رمزنگاری‌شده** ذخیره می‌شود و هیچ‌وقت به‌صورت خام "
            . "نمایش داده نمی‌شود. هر وقت خواستید می‌توانید آن را از داخل پنل باطل کنید.\n\n"
            . "👇 ادامه",
            [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🔑 اتصال با کلید API', 'data' => BotApi::encodeData('panel.connect.apikey')]],
                    [['text' => '🖥️ پنل‌های من', 'data' => BotApi::encodeData('panel.mine')]],
                    Keyboard::back(BotApi::encodeData('panel.list'), '🔙 بازگشت'),
                ])),
            ]
        );
    }

    /**
     * فهرست پنل‌های کاربر.
     *
     * ⚠️ `$userId` باید شناسهٔ داخلی جدول `users` باشد، نه آیدی تلگرام —
     * وگرنه هیچ ردیفی پیدا نمی‌شود و کاربر همیشه «پنلی ندارد» می‌بیند.
     */
    public function listUserPanels(int $chatId, int $userId): void
    {
        $panels = $this->panels->listByUser($userId);

        if ($panels === []) {
            $this->bot->sendMessage(
                $chatId,
                "📭 <b>هنوز پنلی وصل نکرده‌اید</b>\n\n"
                . "برای شروع، یک پنل جدید وصل کنید:",
                [
                    'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                        [['text' => '➕ اتصال پنل جدید', 'data' => BotApi::encodeData('panel.connect')]],
                        Keyboard::back(BotApi::encodeData('panel.list'), '🔙 بازگشت'),
                    ])),
                ]
            );

            return;
        }

        $lines = ['🖥️ <b>پنل‌های شما</b> (' . Str::faNumber(count($panels)) . ')', ''];

        foreach ($panels as $panel) {
            $label = trim((string) ($panel['label'] ?? ''));

            $lines[] = PanelRepository::statusLabel($panel) . ' <b>'
                . Str::escape($label !== '' ? $label : (string) $panel['panel_username']) . '</b>';

            if ($label !== '') {
                $lines[] = '   🆔 ' . Str::escape((string) $panel['panel_username']);
            }

            $lines[] = '   💾 ' . Str::formatBytes((int) $panel['data_limit'])
                . ' • 📥 ' . Str::formatBytes((int) $panel['used_traffic']);

            $daysLeft = PanelRepository::daysLeft($panel);

            $lines[] = $daysLeft === null
                ? '   ⏳ بدون انقضا'
                : '   📅 ' . Str::faNumber($daysLeft) . ' روز اعتبار';

            $lines[] = '';
        }

        $kb = [[['text' => '➕ اتصال پنل جدید', 'data' => BotApi::encodeData('panel.connect')]]];
        $kb[] = Keyboard::back(BotApi::encodeData('panel.list'), '🔙 بازگشت');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($kb),
        ]);
    }

    // ------------------------------------------------------------------
    // شروع جریان (از دکمه‌ها)
    // ------------------------------------------------------------------

    public function startApiKeyConnection(int $chatId, int $telegramId): void
    {
        // ⚠️ کل نشست پاک می‌شود، نه فقط کلیدهای این جریان. اگر نشستِ نیمه‌کارهٔ
        // دیگری (مثلاً «ویرایش بسته») باقی بماند، پیام بعدیِ کاربر به‌جای این
        // جریان، آن یکی را ادامه می‌دهد و چیز اشتباهی ذخیره می‌شود.
        $this->sessions->clear($telegramId);

        $this->sessions->set($telegramId, ['step' => self::STEP_URL]);

        $this->askBaseUrl($chatId);
    }

    // ------------------------------------------------------------------
    // پیام‌های هر مرحله
    // ------------------------------------------------------------------

    private function askBaseUrl(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            "🌐 <b>آدرس پنل پاسارگاد را بفرستید</b>\n\n"
            . "آدرسی که با آن پنل را باز می‌کنید، همراه <code>https://</code>:\n"
            . "<code>https://panel.example.com</code>\n\n"
            . "💡 اگر پنل روی زیرشاخه نصب است همان را کامل بنویسید.\n"
            . "❌ برای لغو: /cancel"
        );
    }

    private function askCredential(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            "🔑 <b>کلید API را بفرستید</b>\n\n"
            . "کلیدی که پنل در بخش تنظیمات / API Keys به شما داده است.\n\n"
            . "⚠️ بعد از فرستادن، این پیام را از حافظهٔ چت خود پاک کنید،\n"
            . "چون کلید در گفتگو دیده می‌شود.\n\n"
            . "🔒 این مقدار رمزنگاری می‌شود و هیچ‌وقت به‌صورت خام ذخیره یا نمایش داده نمی‌شود.\n"
            . "❌ برای لغو: /cancel"
        );
    }

    // ------------------------------------------------------------------
    // پردازش ورودی‌ها
    // ------------------------------------------------------------------

    /**
     * مرحلهٔ ۱ → ۲ (یا مستقیم به ۳، در مسیر کلید API که نام کاربری نمی‌خواهد).
     *
     * @param array<string, mixed> $state
     * @return bool true یعنی مرحله مصرف شد و نشست جلو رفت
     */
    public function handleBaseUrl(int $chatId, int $telegramId, string $text, array $state): bool
    {
        $url = $this->normaliseBaseUrl($text);

        if ($url === null) {
            $this->bot->sendMessage(
                $chatId,
                "⚠️ <b>آدرس پنل معتبر نیست.</b>\n\n"
                . "آدرس باید با <code>http://</code> یا <code>https://</code> شروع شود و یک دامنه داشته باشد.\n"
                . "نمونهٔ درست: <code>https://panel.example.com</code>"
            );

            return false;   // نشست را نگه می‌داریم تا کاربر دوباره تلاش کند.
        }

        $state['panel_base_url'] = $url;
        // بعد از آدرس، مستقیم کلید پرسیده می‌شود. نام کاربری دیگر لازم نیست
        // چون با کلید، پنل خودش ادمینِ متناظر را برمی‌گرداند.
        $state['step'] = self::STEP_CREDENTIAL;

        $this->sessions->set($telegramId, $state);

        $this->askCredential($chatId);

        return true;
    }

    /**
     * مرحلهٔ ۳: تست اتصال و — اگر موفق بود — ثبت پنل.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $user رکورد کاربر جاری
     */
    public function handleCredential(int $chatId, int $telegramId, string $text, array $state, array $user): bool
    {
        $credential = trim($text);

        if ($credential === '') {
            $this->bot->sendMessage($chatId, '⚠️ مقدار خالی است. دوباره بفرستید یا /cancel بزنید.');

            return false;
        }

        $baseUrl = (string) ($state['panel_base_url'] ?? '');
        $userId  = (int) ($user['id'] ?? 0);

        if ($baseUrl === '' || $userId <= 0) {
            $this->sessions->clear($telegramId);

            $this->bot->sendMessage($chatId, '⚠️ جلسهٔ اتصال نامعتبر است. دوباره از منوی «اتصال پنل جدید» شروع کنید.');

            return false;
        }

        // پیام «در حال تست» جداگانه فرستاده و بعد **حذف** می‌شود (نه ویرایش):
        // `editMessageText` روی پیامی که همین حالا فرستاده شده با خطای
        // «message is not modified» برمی‌گردد.
        $pending = $this->bot->sendMessage($chatId, '⏳ در حال تست اتصال به پنل…');

        try {
            $client = $this->injectedClient ?? new PasarGuardClient($baseUrl);
            $probe  = $this->probePanel($client, $state, $credential);

            if (!$probe['ok']) {
                $this->clearPending($chatId, $pending);

                $this->bot->sendMessage(
                    $chatId,
                    "❌ <b>اتصال برقرار نشد</b>\n\n" . $probe['message'] . "\n\n"
                    . "💡 آدرس پنل و کلید را بررسی کنید. اگر پنل گواهی SSL معتبر ندارد، "
                    . "موقتاً <code>panel.verify_ssl</code> را در تنظیمات خاموش کنید."
                );

                return false;
            }

            // نام واقعی ادمین را خودِ پنل می‌گوید (`GET /api/admin`) — خیلی بهتر
            // از یک شناسهٔ ساختگی مثل «apikey-a1b2…» است، چون در فهرست پنل‌ها
            // به کاربر قابل‌شناسایی نشان داده می‌شود.
            $username = trim((string) ($probe['admin']['username'] ?? ''));

            if ($username === '') {
                $username = 'apikey-' . substr(hash('sha256', $credential), 0, 12);
            }

            $existing = $this->panels->findByPanelUsername($username, $baseUrl);

            // ⚠️ مالکیت را نمی‌رباییم: اگر پنل به حساب دیگری وصل است، رد می‌شود.
            if ($existing !== null && (int) $existing['user_id'] !== $userId) {
                $this->clearPending($chatId, $pending);

                $this->bot->sendMessage(
                    $chatId,
                    "🔒 <b>این پنل به حساب دیگری وصل است.</b>\n\n"
                    . "پنل <code>" . Str::escape($username) . "</code> قبلاً توسط کاربر دیگری ثبت شده است."
                );

                return false;
            }

            // ⚠️ آدرس اختصاصی این اتصال در `base_url` ذخیره می‌شود (NULL یعنی
            // «از `panel.base_url` سراسری استفاده کن»).
            //
            // کلید **خام** از اینجا رد می‌شود و رمزنگاری‌اش را
            // `PanelRepository` انجام می‌دهد — وگرنه یک روز کلید خام در
            // دیتابیس می‌نشست. رمز عبور هم دیگر گرفته نمی‌شود، پس خالی
            // ثبت می‌شود.
            $panelId = $this->panels->upsertFromPanel(
                $userId,
                $username,
                '',
                $probe['admin'],
                $existing !== null ? PanelRepository::SOURCE_BOT : PanelRepository::SOURCE_SELF,
                [
                    'base_url' => $baseUrl,
                    'api_key'  => $credential,
                ],
                $baseUrl
            );

            $this->clearPending($chatId, $pending);

            // نشست فقط **بعد** از ثبت موفق پاک می‌شود.
            $this->sessions->clear($telegramId);

            $panel = $this->panels->find($panelId);

            $this->bot->sendMessage(
                $chatId,
                ($existing !== null
                    ? '✅ <b>اتصال پنل به‌روزرسانی شد.</b>'
                    : '🎉 <b>پنل با موفقیت وصل شد!</b>')
                . "\n\n"
                . ($panel !== null ? $this->panelSummary($panel) : ''),
                [
                    'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                        [['text' => '📋 فهرست پنل‌ها', 'data' => BotApi::encodeData('panel.list')]],
                        Keyboard::back(BotApi::encodeData('panel.list'), '🔙 بازگشت'),
                    ])),
                ]
            );

            return true;
        } catch (PanelException $e) {
            $this->clearPending($chatId, $pending);
            Logger::warning('Panel connection failed', ['error' => $e->getMessage(), 'url' => $baseUrl]);

            $this->bot->sendMessage(
                $chatId,
                '❌ <b>خطا در اتصال به پنل</b>' . "\n\n" . Str::escape($e->getMessage())
            );

            return false;
        } catch (\Throwable $e) {
            $this->clearPending($chatId, $pending);
            Logger::error('Panel connection crashed', ['error' => $e->getMessage(), 'url' => $baseUrl]);

            $this->bot->sendMessage(
                $chatId,
                "⚠️ <b>خطای غیرمنتظره</b>\n\nاتصال انجام نشد. جزئیات در لاگ سرور ثبت شد."
            );

            return false;
        }
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /**
     * تست واقعی اتصال.
     *
     * برای مسیر رمز از همان `login()`/`getAdmin()`ای استفاده می‌شود که خود ربات
     * برای همهٔ عملیات پنل به کار می‌برد، پس رفتارش یکی است. برای کلید API یک
     * درخواست سبک با هدر `Authorization` زده می‌شود.
     *
     * @param array<string, mixed> $state
     * @return array{ok:bool, message:string, admin:array<string, mixed>}
     */
    private function probePanel(
        PasarGuardClient $client,
        array $state,
        string $credential
    ): array {
        return $this->probeWithApiKey($client, $credential);
    }

    /**
     * @return array{ok:bool, message:string, admin:array<string, mixed>}
     */
    private function probeWithApiKey(PasarGuardClient $client, string $apiKey): array
    {
        try {
            // ⚠️ قبلاً اینجا `request('GET', ..., 'api', $apiKey)` صدا زده
            // می‌شد، یعنی نام کاربری «api» با کلید به‌عنوان رمز عبور. آن
            // مسیر یک `POST /api/admin/token` با grant_type=password می‌فرستد
            // که کلید API آن را نمی‌پذیرد — پس این شاخه **همیشه** خطا می‌داد
            // و کاربر هرگز نمی‌توانست با کلید وصل شود.
            //
            // حالا کلید مستقیم در هدر Authorization می‌رود.
            $data = $client->getAdminWithApiKey($apiKey);

            return [
                'ok'      => true,
                'message' => 'اتصال با کلید API برقرار شد.',
                'admin'   => $data,
            ];
        } catch (PanelException $e) {
            return [
                'ok'      => false,
                'message' => $e->isAuthError()
                    ? 'کلید API معتبر نیست یا منقضی شده است.'
                    : Str::escape($e->getMessage()),
                'admin'   => [],
            ];
        }
    }

    /**
     * پاک‌سازی آدرس ورودی.
     *
     * چرا این‌قدر سخت‌گیرانه؟ ورودی کاربر مستقیم به `Http` می‌رود، پس باید قبلش
     * هم معتبر و هم بی‌خطر باشد: فقط http/https، فقط دامنه، و بدون `user:pass`.
     */
    private function normaliseBaseUrl(string $text): ?string
    {
        $url = rtrim(trim($text), "/ \t\n\r\0\x0B");

        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return null;
        }

        // اگر کاربر scheme را ننوشت، خودمان اضافه می‌کنیم.
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false
            || empty($parts['host'])
            || !empty($parts['user'])
            || !empty($parts['pass'])
        ) {
            return null;   // URL با `user:pass` یا بدون میزبان مجاز نیست.
        }

        if (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;   // `file://`، `ftp://` و… کنار گذاشته می‌شوند.
        }

        return $this->isUsableHost((string) $parts['host']) ? $url : null;
    }

    /**
     * آیا این میزبان واقعاً یک میزبان قابل استفاده است؟
     *
     * چرا لازم است؟ چون بالا `https://` را به ورودی اضافه می‌کنیم، پس هر متن
     * دلخواهی («نامعتبر»، «سلام») از `parse_url` رد می‌شود و به‌عنوان میزبان جا
     * می‌افتد؛ بعد `PasarGuardClient` یک درخواست HTTP به همان متن می‌زند.
     *
     * پس فقط دو چیز قبول است: یک IP معتبر، یا یک نام دامنهٔ واقعی. دامنهٔ
     * غیر‌ASCII (مثل «پنل.ایر») هم رد می‌شود چون باید به Punycode تبدیل شود و
     * `Http` این کار را نمی‌کند.
     */
    private function isUsableHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // نام دامنه: برچسب‌های الفبا/عدد/خط‌تیره که با خط‌تیره شروع یا تمام
        // نمی‌شوند، و حداقل یک نقطه (یعنی واقعاً دامنه است، نه یک کلمه).
        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            $host
        ) === 1;
    }

    /**
     * @param array<string, mixed> $panel
     */
    private function panelSummary(array $panel): string
    {
        $lines = [
            '📋 <b>مشخصات اتصال</b>',
            '───────────────',
            '👤 نام کاربری: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '📊 وضعیت: ' . PanelRepository::statusLabel($panel),
            '🔑 کلید API: ذخیره‌شده به‌صورت رمزنگاری‌شده 🔒',
        ];

        // آدرس اختصاصی این اتصال؛ NULL یعنی از پیش‌فرض سراسری می‌خواند.
        $baseUrl = trim((string) ($panel['base_url'] ?? ''));

        $lines[] = '📍 آدرس پنل: ' . ($baseUrl !== ''
            ? '<code>' . Str::escape($baseUrl) . '</code>'
            : '<i>پیش‌فرض سراسری (panel.base_url)</i>');

        if (!empty($panel['label'])) {
            $lines[] = '🏷️ برچسب: ' . Str::escape((string) $panel['label']);
        }

        if ((int) ($panel['data_limit'] ?? 0) > 0) {
            $lines[] = '💾 سقف حجم: ' . Str::formatBytes((int) $panel['data_limit']);
        }

        if ((int) ($panel['used_traffic'] ?? 0) > 0) {
            $lines[] = '📥 مصرف: ' . Str::formatBytes((int) $panel['used_traffic']);
        }

        if (!empty($panel['sub_url'])) {
            $lines[] = '🔗 آدرس کاربر: <code>' . Str::escape((string) $panel['sub_url']) . '</code>';
        }

        return implode("\n", $lines);
    }

    /**
     * پاک‌کردن پیام «در حال تست».
     *
     * @param array<string, mixed> $pending خروجی `sendMessage`
     */
    private function clearPending(int $chatId, array $pending): void
    {
        $messageId = (int) ($pending['result']['message_id'] ?? 0);

        if ($messageId <= 0) {
            return;
        }

        try {
            $this->bot->deleteMessage($chatId, $messageId);
        } catch (\Throwable $e) {
            Logger::debug('Could not delete pending message', ['error' => $e->getMessage()]);
        }
    }
}