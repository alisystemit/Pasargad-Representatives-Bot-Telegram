<?php

declare(strict_types=1);

namespace Pasargad\Panel;

use Pasargad\Store\PanelRepository;
use Pasargad\Support\Logger;

/**
 * لایهٔ «آیا اصلاً باید همگام کنیم؟» روی همگام‌سازیِ پنل.
 *
 * ## چرا این لایه اصلاً لازم است
 *
 * `PanelSyncer` کارِ «خواندن از پنل و نوشتن در دیتابیس» را انجام می‌دهد و
 * **درست** است. این کلاس جای آن را نمی‌گیرد و کارِ تکراری نمی‌کند.
 *
 * کاری که اینجا انجام می‌شود یک چیزِ ساده ولی پرهزینه است: **پنلی که همین
 * الان همگام شده، دوباره همگام نشود.**
 *
 * چرا مهم است: تماس با پنل کند و محدود است. کرون هر چند دقیقه یک‌بار روی
 * *همهٔ* پنل‌ها می‌زند؛ اگر برای پنلی که ده ثانیه پیش خوانده شده دوباره
 * درخواست بفرستیم، بودجهٔ زمانیِ کرون را می‌خوریم و پنل‌های واقعیِ دیگر
 * بی‌وقت می‌مانند.
 *
 * ## چه چیزی از نسخهٔ قبلی حذف شد و چرا
 *
 * نسخهٔ قبلی یک موتورِ همگام‌سازیِ موازی بود (`performSync`،
 * `recordSyncSuccess`، `getLastSyncTime`) که هیچ‌کدام پیاده نشده بودند و
 * `getLastSyncTime()` همیشه `time()` برمی‌گرداند — یعنی ادعا می‌کرد پنل هرگز
 * نیاز به همگام ندارد.
 *
 * همچنین `mergeData()` با `array_merge($local, $remote)` **سمتِ پنل را همیشه
 * برنده** می‌کرد. برای `used_traffic` درست است، ولی برای ستون‌هایی که ربات
 * مدیریت می‌کند (`granted_volume`، `user_limit`) فاجعه است: کاربری که خریده،
 * موجودی‌اش را از دست می‌داد. این «حل تعارض» حذف شد، چون `PanelSyncer`
 * خودش دقیقاً می‌داند کدام ستون از کجا بیاید.
 */
final class PanelSyncManager
{
    /**
     * کمترین فاصلهٔ دو همگام‌سازیِ یک پنل.
     *
     * ⚠️ عدد عمدی است: نه خیلی کم (که عملاً بی‌فایده شود) و نه خیلی زیاد (که
     * آمارِ کاربران کهنه بماند و حجمِ مصرفی دیر نشان داده شود).
     */
    public const MIN_SYNC_INTERVAL = 300;   // ۵ دقیقه

    private static ?PanelRepository $panels = null;

    public static function usePanels(?PanelRepository $panels): void
    {
        self::$panels = $panels;
    }

    private static function panels(): PanelRepository
    {
        return self::$panels ?? new PanelRepository();
    }

    /**
     * آیا این پنل الان نیاز به همگام‌سازی دارد؟
     *
     * پنلی که هرگز همگام نشده **نیاز دارد** — وگرنه پنلی که تازه وصل شده تا
     * وقتی کرون دستی اجرا نشود، هرگز آمار نمی‌گیرد.
     *
     * @param array<string, mixed>|null $panel ردیفِ پنل، اگر از قبل خوانده شده
     */
    public static function needsSync(int $panelId, ?array $panel = null): bool
    {
        if ($panelId <= 0) {
            return false;
        }

        $syncedAt = (int) (($panel ?? self::panels()->find($panelId))['synced_at'] ?? 0);

        if ($syncedAt <= 0) {
            return true;
        }

        return (time() - $syncedAt) > self::MIN_SYNC_INTERVAL;
    }

    /**
     * پالودنِ فهرستِ پنل‌ها از آن‌هایی که نیاز به همگام ندارند.
     *
     * @param  array<int, array<string, mixed>> $panels
     * @return array<int, array<string, mixed>>
     */
    public static function dueForSync(array $panels, int $limit = 0): array
    {
        $due = [];

        foreach ($panels as $panel) {
            if (!self::needsSync((int) ($panel['id'] ?? 0), $panel)) {
                continue;
            }

            $due[] = $panel;

            if ($limit > 0 && count($due) >= $limit) {
                break;
            }
        }

        return $due;
    }

    /**
     * گزارشِ وضعیتِ همگام‌سازی — **اعدادِ واقعی**.
     *
     * نسخهٔ قبلی همه‌چیز را صفر برمی‌گرداند. برای یک داشبوردِ مدیریتی، صفرِ
     * ساختگی از نبودِ داشبورد بدتر است: مدیر فکر می‌کند هیچ پنلی همگام نشده
     * و دنبال مشکلی می‌گردد که وجود ندارد.
     *
     * @param  array<int, array<string, mixed>>|null $panels اگر داده شود، فقط
     *                                                       همین‌ها شمرده می‌شوند
     * @return array<string, int>
     */
    public static function getSyncStatusReport(?array $panels = null): array
    {
        $panels ??= self::panels()->listWatchable(1000);

        $total = count($panels);
        $fresh = 0;
        $due  = 0;

        foreach ($panels as $panel) {
            if (self::needsSync((int) ($panel['id'] ?? 0), $panel)) {
                $due++;
            } else {
                $fresh++;
            }
        }

        return [
            'total_panels'    => $total,
            'synced_recently' => $fresh,
            'pending_sync'    => $due,
            // شکستِ همگام به معنی «پنل خراب» نیست؛ ۴۰۱/۴۰۳ یعنی کاربر باید
            // کلیدش را درست کند. شمارشِ این‌ها جدا و با عنوانِ درست لازم است،
            // وگرنه با خرابیِ واقعی قاطی می‌شود.
            'unlinked'        => self::countUnlinked($panels),
            // «پنل مرده» وضعیت نیست؛ یک ستونِ جدا (`is_dead`) است. برای همین
            // جدا شمرده می‌شود و داخل وضعیت‌ها قاطی نمی‌شود.
            'dead'            => self::countDead($panels),
            'revoked'         => self::countStatus($panels, PanelRepository::STATUS_REVOKED),
            'interval'        => self::MIN_SYNC_INTERVAL,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     */
    private static function countDead(array $panels): int
    {
        $count = 0;

        foreach ($panels as $panel) {
            if ((int) ($panel['is_dead'] ?? 0) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $panels
     */
    private static function countStatus(array $panels, string $status): int
    {
        $count = 0;

        foreach ($panels as $panel) {
            if ((string) ($panel['panel_status'] ?? '') === $status) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * پنل‌هایی که کلید ندارند و برای همین همگام نمی‌شوند.
     *
     * این شمارنده بعد از حذف رمز اهمیت پیدا کرد: این پنل‌ها بی‌سروصدا از کار
     * افتاده‌اند و تنها راه دیدنشان همین عدد است.
     *
     * @param array<int, array<string, mixed>> $panels
     */
    private static function countUnlinked(array $panels): int
    {
        $count = 0;

        foreach ($panels as $panel) {
            if (trim((string) ($panel['api_key'] ?? '')) === '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * ثبتِ نتیجهٔ همگام‌سازی برای گزارش.
     *
     * `synced_at` را خودِ `PanelSyncer` می‌نویسد؛ این متد فقط لاگ می‌کند تا
     * بتوان بعداً سراغِ کندیِ کرون رفت. نوشتنِ دوبارهٔ همان ستون از اینجا
     * یعنی دو نویسنده برای یک حقیقت — و هرکدام می‌تواند دیگری را بی‌صدا خراب
     * کند.
     */
    public static function recordResult(int $panelId, bool $ok, string $message = '', int $elapsedMs = 0): void
    {
        if ($ok) {
            Logger::info('Panel sync finished', [
                'panel_id'  => $panelId,
                'elapsed_ms' => $elapsedMs,
            ]);

            return;
        }

        Logger::warning('Panel sync failed', [
            'panel_id'   => $panelId,
            'elapsed_ms' => $elapsedMs,
            'message'    => $message,
        ]);
    }
}