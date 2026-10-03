<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Config;
use Pasargad\Support\Logger;

/**
 * همگام‌سازی وضعیت پنل‌ها از API پنل.
 *
 * دو جا استفاده می‌شود:
 *   • دکمهٔ «🔄 بروزرسانی» کاربر (یک پنل مشخص)
 *   • کرون (همهٔ پنل‌ها، برای اینکه هشدار حجم به‌موقع باشد)
 *
 * چرا کرون لازم است؟ مصرف ترافیک فقط وقتی دیده می‌شود که کسی بپرسد. اگر
 * هشدار «حجم رو به اتمام» فقط هنگام بازدید کاربر ساخته شود، نماینده ممکن است
 * چند روز بعد از اتمام حجم خبردار شود — یعنی مشتریانش قطع شده‌اند و خودش خبردار
 * نشده. کرون این حفره را می‌بندد.
 */
final class PanelSyncer
{
    private PanelRepository $panels;
    private ?PasarGuardClient $panel;

    public function __construct(?PanelRepository $panels = null, ?PasarGuardClient $panel = null)
    {
        $this->panels = $panels ?? new PanelRepository();
        $this->panel  = $panel;
    }

    private function panelClient(): PasarGuardClient
    {
        if ($this->panel === null) {
            $this->panel = new PasarGuardClient();
        }

        return $this->panel;
    }

    /**
     * همگام‌سازی یک پنل مشخص.
     *
     * @param  array<string, mixed> $panelRow
     * @return array{ok:bool, message:string, panel?:array<string, mixed>}
     */
    public function syncOne(array $panelRow): array
    {
        $password = $this->panels->plainPassword($panelRow);
        $username = trim((string) $panelRow['panel_username']);

        if ($username === '' || $password === '') {
            return ['ok' => false, 'message' => 'اطلاعات ورود این پنل در ربات ذخیره نشده است.'];
        }

        try {
            $admin = $this->panelClient()->getAdmin($username, $username, $password);
        } catch (PanelException $e) {
            if ($e->isAuthError()) {
                // رمز عوض شده یا حساب حذف شده؛ بازخوانی لازم است.
                $this->panels->update((int) $panelRow['id'], ['panel_status' => PanelRepository::STATUS_REVOKED]);

                return [
                    'ok'      => false,
                    'message' => 'اطلاعات ورود این پنل دیگر معتبر نیست. با دکمهٔ «🔄 اتصال مجدد» دوباره ثبتش کنید.',
                ];
            }

            return ['ok' => false, 'message' => 'ارتباط با پنل ممکن نشد: ' . $e->getMessage()];
        }

        $this->panels->syncFromPanel((int) $panelRow['id'], $admin);

        return [
            'ok'      => true,
            'message' => 'اطلاعات پنل بروزرسانی شد.',
            'panel'   => $this->panels->find((int) $panelRow['id']) ?? $panelRow,
        ];
    }

    /**
     * همگام‌سازی گروهی برای کرون.
     *
     * عمداً خطای یک پنل، بقیه را متوقف نمی‌کند: اگر پنل یک نمایندهٔ ۵۰۰
     * کاربر بی‌پاسخ بدهد، نباید بررسی هزار نمایندهٔ دیگر هم متوقف شود.
     *
     * ولی یک نگهبان دیگر هم لازم است: **سقف زمانی کل اجرا**.
     *
     * چرا؟ کرون با `listWatchable(200)` تا ۲۰۰ پنل را بررسی می‌کند و هر پنل
     * احراز هویت جداگانه می‌خواهد (نام کاربری هر نماینده فرق دارد، پس کش
     * توکن به کار نمی‌آید). اگر پنل کند یا بی‌پاسخ باشد، هر پنل تا
     * `login + getAdmin` یعنی حدود ۸۰ ثانیه معطلی می‌گیرد ⇒ ۲۰۰ × ۸۰ =
     * بیش از ۴ ساعت. کرون بعدی روی همان زمان اجرا می‌شود و انبوه پروسهٔ
     * نیمه‌تمام روی هم انباشته می‌شوند — یعنی «هنگ» در مقیاس کل ربات.
     *
     * با سقف زمانی، پنل‌های باقی‌مانده به اجرای بعدی کرون می‌مانند و تعدادشان
     * صریح گزارش می‌شود تا کسی فکر نکند همه بررسی شدند.
     *
     * @param  array<int, array<string, mixed>> $panels
     * @param  float|null $budgetSeconds سقف این اجرا؛ پیش‌فرض از تنظیمات
     * @return array{checked:int, synced:int, failed:int, skipped:int, budget_used:bool}
     */
    public function syncMany(array $panels, ?float $budgetSeconds = null): array
    {
        $result = ['checked' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0, 'budget_used' => false];

        $budget = $budgetSeconds ?? (float) Config::int('panel.cron_budget_seconds', 600);

        // ⚠️ فقط «صفر یا کمتر» یعنی بدون سقف. مقدار کوچک ولی مثبت (مثل ۵
        // ثانیه) یک سقف **واقعی** است و باید اعمال شود.
        //
        // چرا این تفکیک مهم است؟ اگر «کمتر از ۱۰» را بی‌سقف می‌گرفتیم، هر کسی
        // که پشتیبانی خواست را با یک عدد کوچک امتحان می‌کرد، بی‌سقفیِ کامل و
        // بی‌خبر می‌گرفت — یعنی برعکسِ انتظارش. کمکی که به نظر می‌رسد، نبودِ
        // کمک است.
        $deadline = $budget > 0.0 ? microtime(true) + $budget : INF;

        foreach ($panels as $panel) {
            if (microtime(true) >= $deadline) {
                $result['skipped']    += 1;
                $result['budget_used']  = true;
                continue;
            }

            $result['checked']++;

            try {
                $outcome = $this->syncOne($panel);
            } catch (\Throwable $e) {
                // استثنای پیش‌بینی‌نشده هم نباید کرون را kill کند.
                $result['failed']++;
                Logger::error('Panel sync crashed', [
                    'panel_id' => $panel['id'] ?? null,
                    'error'    => $e->getMessage(),
                ]);

                continue;
            }

            if ($outcome['ok']) {
                $result['synced']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }
}