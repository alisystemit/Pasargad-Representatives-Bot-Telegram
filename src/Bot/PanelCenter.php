<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\AccessCutoff;
use Pasargad\Store\AgencyService;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\PanelSyncer;
use Pasargad\Store\PanelUserStats;
use Pasargad\Store\Settings;
use Pasargad\Store\TestConfigRepository;
use Pasargad\Store\TestConfigService;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * صفحه‌های مربوط به پنل‌های نمایندگی.
 *
 * سه جریان دارد:
 *   ۱) فهرست پنل‌های من + جزئیات کامل هر پنل (آدرس ورود، یوزر، پسورد،
 *      حجم، زمان، مصرف و…). این همان چیزی است که نماینده برای کار روزمرهٔ
 *      خود لازم دارد.
 *   ۲) دریافت/غیرفعال کردن کانفیگ تست.
 *   ۳) ثبت پنل موجود («من پنل دارم») برای ادمینی که نمایندهٔ ربات نیست.
 */
final class PanelCenter
{
    private BotApi $bot;
    private PanelRepository $panels;
    private TestConfigService $testConfigs;
    private TestConfigRepository $testRepo;
    private PanelSyncer $syncer;
    private Settings $settings;
    private SessionStore $sessions;
    private ?\Pasargad\Panel\PasarGuardClient $panel = null;
    private ?PanelUserStats $userStats = null;

    public function __construct(
        BotApi $bot,
        ?PanelRepository $panels = null,
        ?TestConfigService $testConfigs = null,
        ?TestConfigRepository $testRepo = null,
        ?Settings $settings = null,
        ?SessionStore $sessions = null,
        ?\Pasargad\Panel\PasarGuardClient $panel = null,
        ?PanelUserStats $userStats = null
    ) {
        $this->bot         = $bot;
        $this->panels      = $panels ?? new PanelRepository();
        $this->testConfigs = $testConfigs ?? new TestConfigService($this->panels);
        $this->testRepo    = $testRepo ?? new TestConfigRepository();
        $this->settings    = $settings ?? new Settings();
        $this->sessions    = $sessions ?? new SessionStore();
        $this->panel       = $panel;
        $this->syncer      = new PanelSyncer($this->panels, $panel);
        $this->userStats   = $userStats ?? new PanelUserStats($this->panels, $this->settings, $panel);
    }

    /**
     * سرویس آمار کاربران پنل.
     */
    public function userStats(): PanelUserStats
    {
        if ($this->userStats === null) {
            $this->userStats = new PanelUserStats($this->panels, $this->settings, $this->panel);
        }

        return $this->userStats;
    }

    /**
     * مهلت ارفاقی فعال (روز).
     */
    private function graceDays(): int
    {
        return max(0, $this->settings->int(\Pasargad\Store\Settings::EXPIRE_GRACE_DAYS, 3));
    }

    /**
     * کلاینت پنل تزریق‌شده.
     *
     * نباید اینجا `new PasarGuardClient()` زده شود: در تست‌ها و هر حالتی که
     * کلاینت جعلی تزریق شده، ساختن کلاینت واقعی یعنی یک درخواست شبکهٔ
     * ناخواسته که هم تست را کند می‌کند هم نتیجه را غیرقابل پیش‌بینی.
     */
    private function panelClient(): \Pasargad\Panel\PasarGuardClient
    {
        if ($this->panel === null) {
            $this->panel = new \Pasargad\Panel\PasarGuardClient();
        }

        return $this->panel;
    }

    // ------------------------------------------------------------------
    // فهرست و جزئیات
    // ------------------------------------------------------------------

    /**
     * فهرست پنل‌های کاربر.
     *
     * @param array<string, mixed> $user
     */
    public function showList(int $chatId, array $user): void
    {
        $panels = $this->panels->listByUser((int) $user['id']);

        if ($panels === []) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '🖥️✨ <b>پنل‌های من 🌐</b>',
                '',
                '😔 شما هنوز هیچ پنل نمایندگی ندارید! 🈳',
                '',
                '🛒💎 برای شروع، یک بستهٔ «🖥️ پنل نمایندگی 🌟» بخرید تا حساب اپراتور شما ساخته شود! 🎁🚀',
                '',
                'اگر از قبل پنل دارید و فقط می‌خواهید آن را به ربات وصل کنید، '
                    . 'از دکمهٔ «🔗 من پنل دارم» استفاده کنید! 🔌👇',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [
                        ['text' => '🛒 خرید پنل نمایندگی', 'data' => BotApi::encodeData('shop', ['kind' => 'agency'])],
                    ],
                    [['text' => '🔗 من پنل دارم', 'data' => BotApi::encodeData('panel.self')]],
                    Keyboard::back('menu'),
                ])),
            ]);

            return;
        }

        $lines = [
            '🖥️✨ <b>پنل‌های من 🌐</b> (' . Str::faNumber(count($panels)) . ')',
            '',
        ];
        $keyboard = [];

        foreach ($panels as $panel) {
            $limit = (int) $panel['data_limit'];
            $used  = (int) $panel['used_traffic'];
            $left  = PanelRepository::daysLeft($panel);
            $grace = $this->graceDays();

            $lines[] = PanelRepository::statusLabel($panel) . ' <b>'
                . Str::escape((string) $panel['panel_username']) . '</b>';

            $lines[] = '   💾 ' . Str::formatBytes($limit > 0 ? $limit : null)
                . ' • 📥 ' . Str::formatBytes($used);

            if ((int) ($panel['stats_at'] ?? 0) > 0) {
                $lines[] = '   👥 فعال: ' . Str::faNumber((int) $panel['users_active'])
                    . ' از ' . Str::faNumber((int) $panel['users_total']);
            }

            if ($left === null) {
                $lines[] = '   ⏳ بدون انقضا';
            } elseif (PanelRepository::isInGrace($panel, $grace)) {
                $lines[] = '   🚨 مهلت ارفاقی: '
                    . Str::faNumber(max(0, (int) PanelRepository::graceDaysLeft($panel, $grace)))
                    . ' روز';
            } else {
                $lines[] = '   📅 ' . ($left < 0
                    ? '⛔️ منقضی‌شده'
                    : '⏳ ' . Str::faNumber($left) . ' روز اعتبار');
            }

            $lines[] = '';

            $keyboard[] = [[
                'text' => PanelRepository::isExpired($panel) ? '⛔️ ' : '🖥 '
                    . Str::truncate((string) $panel['panel_username'], 22),
                'data' => BotApi::encodeData('panel.view', ['id' => (int) $panel['id']]),
            ]];
        }

        $keyboard[] = [[
            'text' => '🔄 بروزرسانی از پنل',
            'data' => BotApi::encodeData('panel.refresh'),
        ]];
        $keyboard[] = [[
            'text' => '🔗 من پنل دارم',
            'data' => BotApi::encodeData('panel.self'),
        ]];
        $keyboard[] = Keyboard::back('menu');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * جزئیات کامل یک پنل — چیزی که نماینده برای کار روزمره لازم دارد.
     *
     * عمداً همهٔ اطلاعات ورود (آدرس، یوزر، پسورد) اینجا نشان داده می‌شود:
     * نماینده به آن‌ها برای ورود به پنل خودش نیاز دارد و پسورد از قبل روی
     * پنلش هست؛ ربات هم آن را رمزنگاری نگه داشته. تنها راه عملی این است که
     * نماینده رمزش را از مدیریت نخواهد.
     *
     * @param array<string, mixed> $user
     */
    public function showDetails(int $chatId, array $user, int $panelId): void
    {
        $panel = $this->panels->findForUser($panelId, (int) $user['id']);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $this->bot->sendMessage($chatId, $this->detailsText($panel), [
            'reply_markup' => $this->bot->buildMarkup($this->detailsKeyboard($panel)),
        ]);
    }

    /**
     * متن کامل جزئیات پنل (برای پیام و همچنین تست‌ها).
     *
     * @param array<string, mixed> $panel
     */
    public function detailsText(array $panel): string
    {
        $username  = (string) $panel['panel_username'];
        $password  = $this->panels->plainPassword($panel);
        $loginUrl  = PanelRepository::loginUrl(
            $panel['login_url'] === null ? null : (string) $panel['login_url']
        );
        $limit     = (int) $panel['data_limit'];
        $used      = (int) $panel['used_traffic'];
        $daysLeft  = PanelRepository::daysLeft($panel);
        $expired   = PanelRepository::isExpired($panel);
        $grace     = $this->graceDays();
        $inGrace   = PanelRepository::isInGrace($panel, $grace);
        $graceLeft = PanelRepository::graceDaysLeft($panel, $grace);
        $stats     = $this->userStats->cached($panel);

        $lines = [
            '🖥️✨ <b>پنل نمایندگی: ' . Str::escape($username) . ' 🌟</b>',
            '',
            '🔑✨ <b>اطلاعات ورود 📝</b>',
            '🌐 آدرس پنل: ' . ($loginUrl === '' ? '<i>➖ ثبت نشده</i>' : Str::escape($loginUrl)),
            '👤 نام کاربری: <code>' . Str::escape($username) . '</code>',
            '🔒 رمز عبور: <code>' . ($password === '' ? '<i>➖ ثبت نشده</i>' : Str::escape($password)) . '</code>',
        ];

        if (!empty($panel['panel_role'])) {
            $lines[] = '🎭 نقش در پنل: ' . Str::escape((string) $panel['panel_role']);
        }

        $lines[] = '';
        $lines[] = '📊✨ <b>وضعیت سرویس 📶</b>';
        $lines[] = '📶 وضعیت: ' . PanelRepository::statusLabel($panel);

        if ($limit > 0) {
            $percent = $used > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0;
            $lines[] = '💾 حجم کل: <b>' . Str::formatBytes($limit) . '</b> 📦';
            $lines[] = '📥 مصرف: <b>' . Str::formatBytes($used) . '</b> (' . Str::faNumber($percent) . '٪) 📊';
            $lines[] = Text::progressBar($percent);
        } else {
            $lines[] = '💾 حجم کل: <b>نامحدود ♾️</b>';
            $lines[] = '📥 مصرف: ' . Str::formatBytes($used) . ' 📊';
        }

        // 👥 سقف کاربران خریداری‌شده (جدا از حجم و مدت) در برابر کاربران ساخته‌شده.
        $userLimit  = max(0, (int) ($panel['user_limit'] ?? 0));
        $usersTotal = max(0, (int) ($panel['users_total'] ?? 0));

        if ($userLimit > 0) {
            $lines[] = '👥 کاربران: <b>' . Str::faNumber($usersTotal) . ' / ' . Str::faNumber($userLimit) . '</b> 🎯';

            if ($usersTotal >= $userLimit) {
                $lines[] = '⚠️⛔️ سقف کاربران پر شده است! برای ساخت کاربر جدید پنل را شارژ کنید. 🔋';
            }
        } else {
            $lines[] = '👥 کاربران: <b>' . Str::faNumber($usersTotal) . '</b> (نامحدود ♾️) ✨';
        }

        $lines[] = '';
        $lines[] = '🗓✨ <b>اعتبار ⏳</b>';

        if ($daysLeft === null) {
            $lines[] = '📅 انقضا: بدون محدودیت زمانی ♾️';
        } elseif ($inGrace) {
            $lines[] = '📅 انقضا: <b>' . Str::date((int) $panel['access_expire_at']) . '</b> (گذشته 😔)';
            $lines[] = '⏳🚨 <b>مهلت ارفاقی: ' . Str::faNumber(max(0, (int) $graceLeft)) . ' روز</b> باقی مانده!';
            $lines[] = '✂️ پس از تمام شدن مهلت، دسترسی مشتریان این پنل قطع می‌شود.';
        } elseif ($expired) {
            $lines[] = '📅 انقضا: <b>' . Str::date((int) $panel['access_expire_at']) . '</b> (گذشته 😔)';
            $lines[] = '⚠️🔒 دسترسی مشتریان این پنل قطع شده یا در حال قطع شدن است! ✂️';
        } else {
            $lines[] = '📅 انقضا: <b>' . Str::date((int) $panel['access_expire_at']) . '</b> 🗓';
            $lines[] = '⏳ باقی‌مانده: <b>' . Str::faNumber($daysLeft) . ' روز</b> 🎉';
        }

        // آمار کاربران پنل (اگر قبلاً محاسبه شده باشد)
        if ($stats['at'] > 0) {
            $lines[] = '';
            $lines[] = '👥✨ <b>کاربران پنل 👥</b>';
            $lines[] = '🟢 فعال: <b>' . Str::faNumber($stats['active']) . '</b>'
                . ' • ⛔️ غیرفعال: <b>' . Str::faNumber($stats['disabled']) . '</b>';
            $lines[] = '🕒 آخرین بروزرسانی آمار: ' . Str::date($stats['at']) . ' 🔄';
        }

        if (!empty($panel['sub_url'])) {
            $lines[] = '';
            $lines[] = '🔗 لینک اشتراک: <code>' . Str::escape((string) $panel['sub_url']) . '</code> 📎';
        }

        $lines[] = '';
        $lines[] = '🧾 سفارش ایجاد: ' . ($panel['order_id'] === null
            ? '📝 ثبت دستی'
            : Str::escape((string) $panel['order_id']));
        $lines[] = '🕒 آخرین بروزرسانی: ' . Str::date((int) ($panel['synced_at'] ?? 0)) . ' 🔄';

        if ($expired) {
            $lines[] = '';
            $lines[] = '🛒💳 برای فعال‌سازی دوباره، پنل را تمدید کنید! 🔋👇';
        }

        return implode("\n", $lines);
    }

    /**
     * 👥 آمار کاربران یک پنل (فعال/غیرفعال).
     *
     * نماینده برای این آمار مجبور بود وارد پنل شود و لیست را دستی بشمارد.
     * اینجا با یک کلیک و با رعایت TTL انجام می‌شود.
     *
     * @param array<string, mixed> $user
     */
    public function showStats(int $chatId, array $user, int $panelId, bool $force = false): void
    {
        $panel = $this->panels->findForUser($panelId, (int) $user['id']);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $result = $this->userStats->stats($panel, $force);

        if (!($result['ok'] ?? false)) {
            $this->bot->sendMessage($chatId, '⚠️ ' . Str::escape((string) $result['message']), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    [['text' => '🔄 تلاش مجدد', 'data' => BotApi::encodeData('panel.stats', ['id' => $panelId, 'f' => 1])]],
                    Keyboard::back(BotApi::encodeData('panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل'),
                ])),
            ]);

            return;
        }

        $total    = (int) $result['total'];
        $active   = (int) $result['active'];
        $disabled = (int) $result['disabled'];

        $percent = $total > 0 ? (int) round(($active / $total) * 100) : 0;

        $lines = [
            '👥✨ <b>آمار کاربران پنل</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '',
            '🧑 کل کاربران: <b>' . Str::faNumber($total) . '</b>',
            '🟢 فعال: <b>' . Str::faNumber($active) . '</b>',
            '⛔️ غیرفعال/منقضی: <b>' . Str::faNumber($disabled) . '</b>',
            '',
            '📊 نسبت فعال: <b>' . Str::faNumber($percent) . '٪</b>',
            Text::progressBar($percent),
            '',
            '🕒 آخرین بروزرسانی: ' . Str::date((int) $result['at'])
                . (($result['cached'] ?? false) ? ' (از حافظهٔ موقت)' : ''),
        ];

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '🔄 بروزرسانی فوری', 'data' => BotApi::encodeData('panel.stats', ['id' => $panelId, 'f' => 1])]],
                Keyboard::back(BotApi::encodeData('panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل'),
            ])),
        ]);
    }

    /**
     * @param array<string, mixed> $panel
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function detailsKeyboard(array $panel): array
    {
        $panelId = (int) $panel['id'];
        $rows    = [];

        if ($this->testConfigs->isEnabled()) {
            $rows[] = [[
                'text' => '🧪 دریافت تست کانفیگ',
                'data' => BotApi::encodeData('panel.test', ['id' => $panelId]),
            ]];

            $rows[] = [['text' => '🧪 کانفیگ‌های تست من', 'data' => BotApi::encodeData('panel.test.list')]];
        }

        $rows[] = [
            ['text' => '👥 آمار کاربران', 'data' => BotApi::encodeData('panel.stats', ['id' => $panelId])],
            ['text' => '🔄 بروزرسانی', 'data' => BotApi::encodeData('panel.sync', ['id' => $panelId])],
        ];

        $rows[] = [[
            'text' => '🛒 شارژ/تمدید',
            'data' => BotApi::encodeData('shop', ['kind' => 'topup', 'p' => $panelId]),
        ]];

        $rows[] = Keyboard::back(BotApi::encodeData('panel.list'), '🖥 بازگشت به پنل‌ها');

        return Keyboard::rows($rows);
    }

    /**
     * بروزرسانی یک پنل از پنل.
     *
     * @param array<string, mixed> $user
     */
    public function sync(int $chatId, array $user, int $panelId): void
    {
        $panel = $this->panels->findForUser($panelId, (int) $user['id']);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $result = $this->syncer->syncOne($panel);

        $fresh = $this->panels->find($panelId) ?? $panel;

        if (!$result['ok']) {
            $this->bot->sendMessage($chatId, '⚠️ ' . Str::escape((string) $result['message']), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل'),
                ])),
            ]);

            return;
        }

        $this->bot->sendMessage($chatId, '✅ ' . Str::escape((string) $result['message']) . "\n\n"
            . $this->detailsText($fresh), [
            'reply_markup' => $this->bot->buildMarkup($this->detailsKeyboard($fresh)),
        ]);
    }

    // ------------------------------------------------------------------
    // تست کانفیگ
    // ------------------------------------------------------------------

    /**
     * ساخت کانفیگ تست روی پنل انتخاب‌شده.
     *
     * @param array<string, mixed> $user
     */
    public function issueTest(int $chatId, array $user, int $panelId): void
    {
        $panel = $this->panels->findForUser($panelId, (int) $user['id']);

        if ($panel === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        // 👑 ادمین‌ها نیازی به کانفیگ تست ندارند — فقط اطلاعات پنل داده می‌شود.
        // ساخت یوزر تست برای ادمین یعنی اشغال بیهودهٔ پنل؛ به‌جای آن همان
        // جزئیات پنل (به ترتیب خرید) نمایش داده می‌شود.
        if ($this->isSuperAdmin((int) ($user['telegram_id'] ?? 0))) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '👑✨ <b>شما ادمین هستید!</b> 🌟',
                '',
                '💎 نیازی به کانفیگ تست ندارید — اطلاعات پنل شما: 👇',
                '',
            ]) . $this->detailsText($panel), [
                'reply_markup' => $this->bot->buildMarkup($this->detailsKeyboard($panel)),
            ]);

            return;
        }

        if (!$this->testConfigs->isEnabled()) {
            $this->bot->sendMessage($chatId, '⚙️😴 دریافت تست کانفیگ موقتاً غیرفعال است! 🙏');
            return;
        }

        $requesterId = (int) $user['id'];
        $effective   = $this->testConfigs->effectivePanel($panel);
        $guard       = $this->testConfigs->canIssue($panel, $requesterId);

        if (!$guard['ok']) {
            $this->bot->sendMessage($chatId, Str::escape((string) $guard['message']), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل'),
                ])),
            ]);

            return;
        }

        $result = $this->testConfigs->issue($panel, $requesterId);

        if (!$result['ok']) {
            $this->bot->sendMessage($chatId, '❌ ' . Str::escape((string) $result['message']), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل'),
                ])),
            ]);

            return;
        }

        $details = (array) ($result['details'] ?? []);
        $id      = (int) ($details['config_id'] ?? 0);

        $lines = [
            '🧪🎉 <b>کانفیگ تست ساخته شد! ✅</b>',
            '',
            '🖥️ روی پنل: <code>' . Str::escape((string) $effective['panel_username']) . '</code> 🌐',
            '👤 نام کاربری: <code>' . Str::escape((string) ($details['username'] ?? '')) . '</code> 🔑',
            '💾 حجم: <b>' . Str::formatBytes((int) ($details['data_limit'] ?? 0)) . '</b> 📦',
            '📅 اعتبار: <b>' . Str::date((int) ($details['expire_at'] ?? 0)) . '</b> ⏳',
        ];

        if (!empty($details['sub_url'])) {
            $lines[] = '';
            $lines[] = '🔗 لینک کانفیگ: <code>' . Str::escape((string) $details['sub_url']) . '</code> 📎';
        }

        $lines[] = '';
        $lines[] = 'ℹ️💡 این یوزر موقتی است و پس از پایان اعتبار یا با دکمهٔ زیر غیرفعال می‌شود! ⏰';

        $keyboard = [];

        if (!empty($details['sub_url'])) {
            $keyboard[] = Keyboard::link('📂 دریافت کانفیگ', (string) $details['sub_url']);
        }

        $keyboard[] = [['text' => '⛔️ غیرفعال کردن تست', 'data' => BotApi::encodeData('panel.test.off', ['id' => $id])]];
        $keyboard[] = Keyboard::back(BotApi::encodeData('panel.view', ['id' => $panelId]), '🖥 بازگشت به پنل');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * فهرست کانفیگ‌های تست کاربر.
     *
     * @param array<string, mixed> $user
     */
    public function showTestList(int $chatId, array $user): void
    {
        $configs = $this->testRepo->listByUser((int) $user['id'], 10);

        if ($configs === []) {
            $this->bot->sendMessage($chatId, '🧪📭 تا این لحظه کانفیگ تستی درخواست نکرده‌اید! 😔', [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back('panel.list', '🖥 بازگشت به پنل‌ها'),
                ])),
            ]);

            return;
        }

        $lines = ['🧪✨ <b>کانفیگ‌های تست من 🎁</b>', ''];
        $keyboard = [];

        foreach ($configs as $config) {
            $status = (string) $config['status'];
            $icon   = $status === TestConfigRepository::STATUS_ACTIVE ? '🟢' : '⚪️';

            $lines[] = $icon . ' <code>' . Str::escape((string) $config['panel_username']) . '</code>'
                . ($status === TestConfigRepository::STATUS_ACTIVE ? '' : ' (' . Str::escape($status) . ')');
            $lines[] = '   💾 ' . Str::formatBytes((int) $config['data_limit'])
                . ' • 📥 ' . Str::formatBytes((int) $config['used_traffic']);
            $lines[] = '   📅 ' . Str::date((int) $config['expire_at']);
            $lines[] = '';

            if ($status === TestConfigRepository::STATUS_ACTIVE) {
                $keyboard[] = [[
                    'text' => '⛔️ غیرفعال کردن ' . Str::truncate((string) $config['panel_username'], 18),
                    'data' => BotApi::encodeData('panel.test.off', ['id' => (int) $config['id']]),
                ]];
            }
        }

        $keyboard[] = Keyboard::back('panel.list', '🖥 بازگشت به پنل‌ها');

        $this->bot->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->bot->buildMarkup($keyboard),
        ]);
    }

    /**
     * غیرفعال کردن یک کانفیگ تست.
     *
     * @param array<string, mixed> $user
     */
    public function disableTest(int $chatId, array $user, int $configId): void
    {
        $config = $this->testRepo->findForUser($configId, (int) $user['id']);

        if ($config === null) {
            $this->bot->sendMessage($chatId, Text::notFound());
            return;
        }

        $result = $this->testConfigs->disable($config);

        $icon = $result['ok'] ? '✅' : '⚠️';

        $this->bot->sendMessage($chatId, $icon . ' ' . Str::escape((string) $result['message']), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back(BotApi::encodeData('panel.test.list'), '🧪 بازگشت به کانفیگ‌های تست'),
            ])),
        ]);
    }

    /**
     * آیا این کاربر سوپرادمین ربات است؟
     */
    private function isSuperAdmin(int $telegramId): bool
    {
        if ($telegramId <= 0) {
            return false;
        }

        $admins = \Pasargad\Support\Config::arr('super_admins');

        foreach ($admins as $admin) {
            if ((int) $admin === $telegramId) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // ثبت پنل موجود («من پنل دارم»)
    // ------------------------------------------------------------------

    /**
     * شروع جریان ثبت پنل موجود.
     *
     * این جریان برای ادمینی است که پنل دارد ولی در ربات نماینده
     * نیست: اطلاعاتش را می‌دهد، ربات صحتش را می‌سنجد و او را به ربات اضافه
     * می‌کند.
     */
    public function startSelfRegister(int $chatId, int $telegramId): void
    {
        $this->bot->sendMessage($chatId, implode("\n", [
            '🔗✨ <b>من پنل دارم 🔌</b>',
            '',
            'اگر در پنل حساب ادمین (اپراتور) 👑 دارید و می‌خواهید آن را به ربات وصل کنید، اطلاعات زیر را بفرستید. 📝👇',
            '',
            'ℹ️🔒 این اطلاعات فقط برای مدیریت پنل شما در ربات استفاده می‌شود. 🛡️',
            'پس از ثبت، می‌توانید پنل را شارژ کنید و تست کانفیگ بگیرید. 🔋🧪🎉',
            '',
            'برای لغو /cancel را بزنید. ❌',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back('menu', '❌ انصراف'),
            ])),
        ]);

        $this->sessions->set($telegramId, ['step' => 'panel:username']);
    }

    /**
     * مرحلهٔ نام کاربری در جریان «من پنل دارم».
     */
    public function askPassword(int $chatId, int $telegramId, string $username): void
    {
        $this->bot->sendMessage($chatId, implode("\n", [
            '🔑✨ <b>رمز عبور پنل 🔒</b>',
            '',
            'برای حساب <code>' . Str::escape($username) . '</code> رمز عبور را بفرستید. 📝👇',
            '',
            '🔒🛡️ رمز شما رمزنگاری و فقط برای مدیریت همین پنل استفاده می‌شود. ✅',
            'بعد از ارسال، این پیام را از حافظهٔ چت خود پاک کنید. 🗑️',
        ]), [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                Keyboard::back('menu', '❌ انصراف'),
            ])),
        ]);

        $this->sessions->set($telegramId, [
            'step'     => 'panel:password',
            'username' => $username,
        ]);
    }

    /**
     * اعتبارسنجی و ثبت پنل.
     *
     * @param array<string, mixed> $user
     * @return array{ok:bool, message:string}
     */
    public function finishSelfRegister(int $chatId, array $user, string $username, string $password): array
    {
        $existing = $this->panels->findByPanelUsername($username);

        if ($existing !== null && (int) $existing['user_id'] !== (int) $user['id']) {
            return [
                'ok'      => false,
                'message' => '⚠️🔒 این پنل قبلاً به حساب دیگری در ربات وصل شده است! 😔 '
                    . 'اگر فکر می‌کنید اشتباه است، با پشتیبانی تماس بگیرید. 📞🙏',
            ];
        }

        try {
            $admin = $this->panelClient()->getAdmin($username, $username, $password);
        } catch (\Pasargad\Panel\PanelException $e) {
            return ['ok' => false, 'message' => Text::loginFailed($e->getMessage())];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => Text::loginFailed('خطای غیرمنتظره هنگام بررسی پنل.'),
            ];
        }

        $wasKnown = $existing !== null;

        $panelId = $this->panels->upsertFromPanel(
            (int) $user['id'],
            $username,
            $password,
            $admin,
            $wasKnown ? PanelRepository::SOURCE_BOT : PanelRepository::SOURCE_SELF
        );

        $panel = $this->panels->find($panelId);

        if ($panel === null) {
            return ['ok' => false, 'message' => '⚠️😔 ثبت پنل ناموفق بود! دوباره تلاش کنید. 🔄'];
        }

        $message = $wasKnown
            ? '✅ اطلاعات پنل شما به‌روزرسانی شد! 🎉'
            : '✅🎉 پنل شما با موفقیت به ربات اضافه شد! 🚀';

        $this->bot->sendMessage($chatId, $message . "\n\n" . $this->detailsText($panel), [
            'reply_markup' => $this->bot->buildMarkup($this->detailsKeyboard($panel)),
        ]);

        return ['ok' => true, 'message' => $message];
    }
}