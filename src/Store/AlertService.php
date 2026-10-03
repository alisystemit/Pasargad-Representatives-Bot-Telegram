<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;
use Pasargad\Telegram\BotApi;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * هشدارهای خودکار پنل‌ها — به خریدار **و** مدیر.
 *
 * هر پنل (نه هر کاربر) مبنای هشدار است، چون یک نماینده می‌تواند چند پنل با
 * وضعیت کاملاً متفاوت داشته باشد.
 *
 * چهار بررسی انجام می‌شود:
 *   ۱) حجم رو به اتمام      → خریدار + مدیر
 *   ۲) نزدیک شدن به انقضا   → خریدار + مدیر
 *   ۳) ورود به مهلت ارفاقی  → خریدار + مدیر (اضطراری)
 *   ۴) انقضا + پایان مهلت   → خریدار + مدیر (با دکمهٔ اقدام برای مدیر)
 *
 * به‌علاوه کاربران تست منقضی فقط تمیزکاری وضعیت می‌شوند.
 *
 * هشدارها در جدول settings علامت‌گذاری می‌شوند تا هر پنل برای هر آستانه فقط
 * یک‌بار مطلع شود (بدون نیاز به جدول جداگانه).
 */
final class AlertService
{
    private PanelRepository $panels;
    private Settings $settings;
    private Db $db;
    private ?BotApi $bot = null;

    /**
     * اعلان به سوپرادمین‌ها (معمولاً Bot\Notifier).
     *
     * عمداً `object` و نه کلاس Bot\Notifier: AlertService در لایهٔ Store است
     * و کرون هم از آن استفاده می‌کند؛ وابستگی به لایهٔ Bot باعث حلقه و
     * سختی تست می‌شد. فقط متد notifyAdmins() لازم است.
     */
    private ?object $notifier = null;

    public function __construct(
        ?PanelRepository $panels = null,
        ?Settings $settings = null,
        ?BotApi $bot = null
    ) {
        $this->panels   = $panels ?? new PanelRepository();
        $this->settings = $settings ?? new Settings();
        $this->bot      = $bot;
        $this->db       = $this->panels->db();
    }

    /**
     * اتصال اعلان به سوپرادمین‌ها.
     *
     * از یک شیء duck-typed استفاده می‌شود تا AlertService وابسته به کلاس‌های
     * لایهٔ Bot نشود و در کرون هم قابل استفاده بماند.
     */
    public function setNotifier(object $notifier): void
    {
        $this->notifier = $notifier;
    }

    /**
     * بررسی همهٔ پنل‌ها و ارسال هشدارهای لازم.
     *
     * این متد **همگام‌سازی نمی‌کند** — وظیفهٔ فراخوان است (کرون اول پنل‌ها را
     * sync می‌کند و بعد هشدارها را می‌سازد). دلیل: اگر هشدار بر اساس دادهٔ کهنه
     * ساخته شود، «مصرف» همیشه صفر است و هشدار حجم هرگز نمی‌رود.
     *
     * @return array{checked:int, low_volume:int, expiring:int, grace:int, expired:int, cutoff_requested:int}
     */
    public function runAll(): array
    {
        $grace = $this->graceDays();

        $result = [
            'checked'           => 0,
            'low_volume'        => 0,
            'expiring'          => 0,
            'grace'             => 0,
            'expired'           => 0,
            'cutoff_requested'  => 0,
            'user_limit'        => 0,
        ];

        // پنجرهٔ دیده‌شدن باید هشدار انقضا + مهلت ارفاقی را پوشش دهد، وگرنه
        // پنلی که ۱۰ روز پیش منقضی شده و ۵ روز دیگر مهلتش تمام می‌شود،
        // هیچ‌وقت بررسی نمی‌شود و قطع دسترسی فراموش می‌ماند.
        $lookback = max(30, (int) $this->settings->int(Settings::EXPIRE_WARN_DAYS, 3) + $grace + 30);

        foreach ($this->panels->listWatchable(200, $lookback) as $panel) {
            $result['checked']++;

            // حالت اضطراری: انقضا گذشته ولی هنوز مهلت ارفاقی داریم.
            if (PanelRepository::isInGrace($panel, $grace)) {
                if ($this->checkGrace($panel, $grace)) {
                    $result['grace']++;
                }

                continue;
            }

            // انقضا + پایان مهلت ارفاقی: اینجاست که باید قطع دسترسی پیشنهاد شود.
            if (PanelRepository::isGraceOver($panel, $grace)) {
                if ($this->checkExpired($panel)) {
                    $result['expired']++;
                }

                if ($this->requestCutoff($panel)) {
                    $result['cutoff_requested']++;
                }

                continue;
            }

            if ($this->checkExpiring($panel)) {
                $result['expiring']++;
            }

            if ($this->checkLowVolume($panel)) {
                $result['low_volume']++;
            }

            // 👥 سقف کاربران — مستقل از حجم و انقضا؛ حتی برای پنل منقضی‌نشده
            // هم باید دیده شود چون نماینده ممکن است زودتر از اتمام حجم/زمان
            // به سقف کاربر برسد.
            if ($this->checkUserLimit($panel)) {
                $result['user_limit']++;
            }
        }

        // کانفیگ‌های تست منقضی فقط تمیزکاری وضعیت‌اند (پیام نمی‌دهند).
        (new TestConfigRepository())->markExpired();

        if ($result['low_volume'] + $result['expiring'] + $result['grace'] + $result['expired'] + $result['user_limit'] > 0) {
            Logger::info('Panel alerts dispatched', $result);
        }

        return $result;
    }

    /**
     * مهلت ارفاقی فعال (روز).
     */
    private function graceDays(): int
    {
        return max(0, $this->settings->int(Settings::EXPIRE_GRACE_DAYS, 3));
    }

    // ------------------------------------------------------------------
    // ۱) حجم رو به اتمام
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $panel
     */
    public function checkLowVolume(array $panel): bool
    {
        $limit = (int) ($panel['data_limit'] ?? 0);
        $used  = (int) ($panel['used_traffic'] ?? 0);

        if ($limit <= 0 || $used <= 0) {
            return false;   // نامحدود یا بدون مصرف
        }

        $remainingPercent = (($limit - $used) / $limit) * 100;

        if ($remainingPercent > 0) {
            $threshold = max(1, $this->settings->int(Settings::LOW_VOLUME_ALERT, 5));
            if ($remainingPercent > $threshold) {
                return false;
            }
        }

        // کلید شامل سقف فعلی است تا با هر شارژ دوباره هشدار برود.
        if ($this->alreadySent($this->warnKey((int) $panel['id'], 'low'), (string) $limit)) {
            return false;
        }

        $sent = $this->sendToBuyer($panel, implode("\n", [
            '⚠️ <b>هشدار حجم پنل شما</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '💾 سقف حجم: <b>' . Str::formatBytes($limit) . '</b>',
            '📥 مصرف: <b>' . Str::formatBytes($used) . '</b>',
            '📊 باقی‌مانده: <b>' . Str::faNumber(max(0, $remainingPercent), 1) . '٪</b>',
            '',
            'برای ادامهٔ سرویس مشتریان، پنل را شارژ کنید. 🛒',
        ]), [[
            ['text' => '🛒 شارژ پنل', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_TOPUP])],
        ]]);

        if (!$sent) {
            return false;
        }

        $this->panels->markLowVolumeWarned((int) $panel['id'], $limit);

        $this->notifyAdmins(implode("\n", [
            '⚠️ <b>هشدار حجم — نماینده</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '🆔 تلگرام: <code>' . (int) ($panel['telegram_id'] ?? 0) . '</code>',
            '💾 سقف: ' . Str::formatBytes($limit) . ' • مصرف: ' . Str::formatBytes($used),
            '📊 باقی‌مانده: ' . Str::faNumber(max(0, $remainingPercent), 1) . '٪',
        ]), [
            'text' => '🖥 پنل',
            'data' => BotApi::encodeData('admin.panel.view', ['id' => (int) $panel['id']]),
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // ۱-ب) رسیدن به سقف کاربران 👥
    // ------------------------------------------------------------------

    /**
     * سقف تعداد کاربران پنل پر شده است.
     *
     * معیار «کاربران ساخته‌شده» (users_total) است نه فعال‌ها — چون لیمیت روی
     * «ساخت» اعمال می‌شود. کلید ضدتکرار شامل خود سقف است تا با هر شارژ
     * (سقف جدید) هشدار تازه برود.
     *
     * @param array<string, mixed> $panel
     */
    public function checkUserLimit(array $panel): bool
    {
        $limit = max(0, (int) ($panel['user_limit'] ?? 0));

        if ($limit <= 0) {
            return false;   // نامحدود ♾️
        }

        $total = max(0, (int) ($panel['users_total'] ?? 0));

        if ($total < $limit) {
            return false;
        }

        // کلید شامل سقف فعلی است تا با هر شارژ دوباره هشدار برود.
        if ($this->alreadySent($this->warnKey((int) $panel['id'], 'users'), (string) $limit)) {
            return false;
        }

        $sent = $this->sendToBuyer($panel, implode("\n", [
            '👥⚠️ <b>سقف کاربران پنل شما پر شد! 🎯</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '👥 کاربران ساخته‌شده: <b>' . Str::faNumber($total) . '</b>',
            '🎯 سقف خریداری‌شده: <b>' . Str::faNumber($limit) . '</b>',
            '',
            'برای ساخت کاربر جدید، سقف پنل را با شارژ بالا ببرید! 🔋🛒',
        ]), [[
            ['text' => '🛒 شارژ پنل', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_TOPUP])],
        ]]);

        if (!$sent) {
            return false;
        }

        $this->panels->markUserLimitWarned((int) $panel['id'], $limit);

        $this->notifyAdmins(implode("\n", [
            '👥⚠️ <b>سقف کاربران — نماینده به سقف رسید 🎯</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '🆔 تلگرام: <code>' . (int) ($panel['telegram_id'] ?? 0) . '</code>',
            '👥 ساخته‌شده: ' . Str::faNumber($total) . ' • سقف: ' . Str::faNumber($limit),
        ]), [
            'text' => '🖥 پنل',
            'data' => BotApi::encodeData('admin.panel.view', ['id' => (int) $panel['id']]),
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // ۲) نزدیک شدن به انقضا
    // ------------------------------------------------------------------

    /**
 * @param array<string, mixed> $panel
 */
    public function checkExpiring(array $panel): bool
    {
        $expireAt = $panel['access_expire_at'] ?? null;

        if ($expireAt === null) {
            return false;   // بدون انقضا
        }

        // ------------------------------------------------------------------
        // پنلی که تاریخش گذشته، مسیر «هشدار تمام شد» را دارد نه این یکی.
        //
        // بررسی با daysLeft کافی نیست چون ceil یک مقدار منفی کوچک (مثلاً
        // «۱ ساعت پیش») صفر می‌دهد و صفر در بازهٔ «امروز» قرار می‌گیرد —
        // یعنی کاربر دو پیام متفاوت و متناقض می‌گرفت.
        // ------------------------------------------------------------------
        if (PanelRepository::isExpired($panel)) {
            return false;
        }

        $daysLeft = (int) ceil(((int) $expireAt - time()) / 86400);

        $warnDays = max(0, $this->settings->int(Settings::EXPIRE_WARN_DAYS, 3));

        if ($daysLeft > $warnDays) {
            return false;
        }

        // کلید شامل تاریخ انقضا است تا با هر تمدید، هشدار دوباره برود.
        if ($this->alreadySent($this->warnKey((int) $panel['id'], 'exp'), (string) $expireAt)) {
            return false;
        }

        $sent = $this->sendToBuyer($panel, implode("\n", [
            '⏳ <b>یادآوری اعتبار پنل</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            $daysLeft > 0
                ? '⏳ اعتبار این پنل <b>' . Str::faNumber($daysLeft) . ' روز</b> دیگر تمام می‌شود.'
                : '⌛️ اعتبار این پنل <b>امروز</b> به پایان می‌رسد.',
            '📅 تاریخ انقضا: ' . Str::date((int) $expireAt),
            '',
            'برای اینکه سرویس مشتریانتان قطع نشود، پنل را تمدید کنید. 🛒',
        ]), [[
            ['text' => '🛒 تمدید پنل', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_TOPUP])],
        ]]);

        if (!$sent) {
            return false;
        }

        $this->panels->markExpiryWarned((int) $panel['id'], (int) $expireAt);

        $this->notifyAdmins(implode("\n", [
            '⏳ <b>انقضای نزدیک پنل — نماینده</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '🆔 تلگرام: <code>' . (int) ($panel['telegram_id'] ?? 0) . '</code>',
            '📅 انقضا: ' . Str::date((int) $expireAt),
        ]), [
            'text' => '🖥 پنل',
            'data' => BotApi::encodeData('admin.panel.view', ['id' => (int) $panel['id']]),
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // ۳) انقضا شد → اطلاع + درخواست قطع دسترسی
    // ------------------------------------------------------------------

    /**
     * اعتبار پنل تمام شده است.
     *
     * @param array<string, mixed> $panel
     */
    public function checkExpired(array $panel, ?int $graceDays = null): bool
    {
        $grace = $graceDays ?? $this->graceDays();

        // اگر کاربری قبلاً پنل تازه خریده و این پنل تمدید شده، انقضایش
        // ملغی شده و نباید «تمام شد» فرستاده شود.
        if (!PanelRepository::isGraceOver($panel, $grace)) {
            return false;
        }

        if ((int) ($panel['expiry_notified'] ?? 0) === 1) {
            return false;
        }

        $panelId = (int) $panel['id'];

        $sent = $this->sendToBuyer($panel, implode("\n", [
            '⌛️ <b>مهلت ارفاقی پنل شما تمام شد</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '📅 تاریخ انقضا: ' . Str::date((int) $panel['access_expire_at']),
            '',
            '⚠️ دسترسی کاربران این پنل قطع می‌شود. برای ادامهٔ سرویس باید پنل را تمدید کنید.',
        ]), [[
            ['text' => '🛒 تمدید فوری', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_TOPUP])],
        ]]);

        if (!$sent) {
            return false;
        }

        $this->panels->markExpiryNotified($panelId);

        $this->notifyAdmins(implode("\n", [
            '⌛️ <b>مهلت ارفاقی تمام شد — آمادهٔ قطع دسترسی</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '🆔 تلگرام: <code>' . (int) ($panel['telegram_id'] ?? 0) . '</code>',
            '📅 انقضا: ' . Str::date((int) $panel['access_expire_at']),
            '',
            'در صورت تمدید نشدن، دسترسی همهٔ کاربران این پنل باید قطع شود.',
        ]), [
            'text' => '✂️ قطع دسترسی کاربران',
            'data' => BotApi::encodeData('admin.panel.cutoff', ['id' => $panelId]),
        ]);

        return true;
    }

    /**
     * انقضا گذشته ولی هنوز مهلت ارفاقی هست — هشدار اضطراری.
     *
     * این پیام از «انقضای نزدیک» مهم‌تر است: سرویس مشتریان عملاً تمام شده و
     * فقط چند روز فرصت مانده.
     *
     * @param array<string, mixed> $panel
     */
    public function checkGrace(array $panel, ?int $graceDays = null): bool
    {
        $grace = $graceDays ?? $this->graceDays();

        if (!PanelRepository::isInGrace($panel, $grace)) {
            return false;
        }

        $panelId  = (int) $panel['id'];
        $deadline = (int) PanelRepository::graceDeadline($panel, $grace);
        $left     = (int) PanelRepository::graceDaysLeft($panel, $grace);

        // کلید شامل پایان مهلت است تا با هر تمدید، هشدار تازه برود.
        if ($this->alreadySent($this->warnKey($panelId, 'grace'), (string) $deadline)) {
            return false;
        }

        $sent = $this->sendToBuyer($panel, implode("\n", [
            '🚨 <b>اعتبار پنل شما تمام شده است!</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '📅 تاریخ انقضا: ' . Str::date((int) $panel['access_expire_at']),
            '',
            '⏳ به شما <b>' . Str::faNumber(max(0, $left)) . ' روز مهلت ارفاقی</b> داده شده است.',
            '✂️ پس از پایان این مهلت، دسترسی همهٔ کاربران این پنل قطع می‌شود!',
        ]), [[
            ['text' => '🛒 تمدید فوری', 'data' => BotApi::encodeData('shop', ['kind' => PackageRepository::KIND_TOPUP])],
        ]]);

        if (!$sent) {
            return false;
        }

        $this->panels->markGraceNotified($panelId, $deadline);

        $this->notifyAdmins(implode("\n", [
            '🚨 <b>پنل در مهلت ارفاقی — نیازمند تمدید</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '🆔 تلگرام: <code>' . (int) ($panel['telegram_id'] ?? 0) . '</code>',
            '📅 انقضا: ' . Str::date((int) $panel['access_expire_at']),
            '⏳ پایان مهلت ارفاقی: ' . Str::date($deadline),
        ]), [
            'text' => '🖥 پنل',
            'data' => BotApi::encodeData('admin.panel.view', ['id' => $panelId]),
        ]);

        return true;
    }

    /**
     * درخواست قطع دسترسی کاربران پنل از سوی مدیر.
     *
     * فقط یک‌بار درخواست داده می‌شود تا هر اجرای کرون پیام تکراری نفرستد.
     *
     * @param array<string, mixed> $panel
     */
    public function requestCutoff(array $panel, ?int $graceDays = null): bool
    {
        if (!$this->settings->bool(Settings::CUTOFF_ON_EXPIRE, true)) {
            return false;
        }

        // نگهبان مهلت ارفاقی داخل خودِ این متد، نه فقط در runAll.
        //
        // دلیل: این متد عمومی است و ممکن است از مسیر دیگری (کرون، پنل ادمین،
        // یا تست) صدا زده شود. اگر نگهبان فقط در فراخوان باشد، یک فراخوان
        // مستقیم یعنی قطع دسترسی کاربران یک نماینده که هنوز ۲ روز مهلت
        // ارفاقی دارد — یعنی دقیقاً همان آسیبی که مهلت ارفاقی ساخته شد تا
        // جلویش را بگیرد.
        if (!PanelRepository::isGraceOver($panel, $graceDays ?? $this->graceDays())) {
            return false;
        }

        $panelId = (int) $panel['id'];

        if ($panel['cutoff_done_at'] !== null) {
            return false;   // قبلاً انجام شده
        }

        if ($panel['cutoff_requested_at'] !== null) {
            return false;   // قبلاً درخواست شده
        }

        $this->panels->markCutoffRequested($panelId);

        $this->notifyAdmins(implode("\n", [
            '✂️ <b>درخواست قطع دسترسی کاربران</b>',
            '',
            '🖥 پنل: <code>' . Str::escape((string) $panel['panel_username']) . '</code>',
            '🆔 نماینده: <code>' . (int) ($panel['telegram_id'] ?? 0) . '</code>',
            '📅 انقضا: ' . Str::date((int) $panel['access_expire_at']),
            '',
            'این پنل منقضی شده است. با دکمهٔ زیر همهٔ کاربران آن غیرفعال می‌شوند.',
        ]), [
            'text' => '✂️ قطع دسترسی همه',
            'data' => BotApi::encodeData('admin.panel.cutoff', ['id' => $panelId]),
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // ارسال
    // ------------------------------------------------------------------

    /**
     * ارسال هشدار به خریدار پنل.
     *
     * اگر ارسال شکست بخورد نباید علامت «ارسال شد» ثبت شود، وگرنه یک خطای
     * موقت تلگرام (۴۲۹ یا تایم‌اوت) باعث می‌شود کاربر تا ابد بی‌خبر بماند.
     *
     * @param array<string, mixed> $panel
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     */
    private function sendToBuyer(array $panel, string $text, array $keyboard = []): bool
    {
        $telegramId = $this->telegramIdOf($panel);

        if ($telegramId <= 0) {
            Logger::warning('Cannot send panel alert — telegram id unknown', [
                'panel_id' => $panel['id'] ?? null,
            ]);

            return false;
        }

        $bot = $this->botApi();

        $result = $bot->sendMessage($telegramId, $text, [
            'reply_markup' => $bot->buildMarkup($keyboard),
        ]);

        if (!($result['ok'] ?? false)) {
            Logger::warning('Panel alert delivery failed', [
                'telegram_id' => $telegramId,
                'panel_id'    => $panel['id'] ?? null,
                'error'       => $result['description'] ?? 'unknown',
            ]);

            return false;
        }

        return true;
    }

    /**
     * آیدی تلگرام صاحب پنل.
     *
     * اگر رکورد پنل از یک کوئری join‌شده آمده، `telegram_id` از قبل هست؛
     * وگرنه از جدول کاربران خوانده می‌شود.
     *
     * چرا این مهم است: هشدار نباید فقط وقتی کار کند که فراخواننده حواسش
     * باشد join بگذارد. اگر `telegram_id` گم باشد، هشدار بی‌سروصدا حذف
     * می‌شد و نماینده هرگز خبردار نمی‌شد — بدترین نوع باگ، چون بی‌صداست.
     *
     * @param array<string, mixed> $panel
     */
    private function telegramIdOf(array $panel): int
    {
        if (isset($panel['telegram_id']) && (int) $panel['telegram_id'] > 0) {
            return (int) $panel['telegram_id'];
        }

        $panelId = (int) ($panel['id'] ?? 0);

        if ($panelId <= 0) {
            return 0;
        }

        $userId = (int) $this->db->value('SELECT user_id FROM panels WHERE id = ?', [$panelId]);

        if ($userId <= 0) {
            return 0;
        }

        return (int) ($this->db->value('SELECT telegram_id FROM users WHERE id = ?', [$userId]) ?? 0);
    }

    /**
     * @param array<string, mixed>|null $button
     */
    private function notifyAdmins(string $text, ?array $button = null): void
    {
        if ($this->notifier === null) {
            return;
        }

        $this->notifier->notifyAdmins($text, $button);
    }

    private function botApi(): BotApi
    {
        if ($this->bot === null) {
            try {
                $this->bot = new BotApi();
            } catch (\Throwable $e) {
                Logger::warning('Bot API not available for alerts', ['error' => $e->getMessage()]);
            }
        }

        if ($this->bot === null) {
            throw new \RuntimeException('Bot API در دسترس نیست.');
        }

        return $this->bot;
    }

    // ------------------------------------------------------------------
    // کلید هشدار
    // ------------------------------------------------------------------

    private function warnKey(int $panelId, string $topic): string
    {
        return 'panelwarn:' . $panelId . ':' . $topic;
    }

    private function alreadySent(string $key, string $value): bool
    {
        return $this->settings->get($key) === $value;
    }
}