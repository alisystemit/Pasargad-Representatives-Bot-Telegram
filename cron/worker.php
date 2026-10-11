<?php

declare(strict_types=1);

/**
 * worker کرون — اجرای صف بسته‌های خریداری‌شده.
 *
 * زمان‌بندی پیشنهادی (هر ۵ دقیقه):
 *   * * * * * php /path/cron/worker.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("این اسکریپت فقط از خط فرمان قابل اجراست.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Pasargad\Bot\Notifier;
use Pasargad\Bot\SessionStore;
use Pasargad\Store\AlertService;
use Pasargad\Store\FeatureFlags;
use Pasargad\Store\OrderRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\PanelSyncer;
use Pasargad\Store\Provisioner;
use Pasargad\Store\TestConfigRepository;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Db;
use Pasargad\Support\Logger;
use Pasargad\Support\Migrator;

Logger::channel('worker');

/**
 * مسیر فایل قفل در پوشهٔ داده (نه لاگ، چون پوشهٔ لاگ ممکن است پاک شود).
 */
function lock_file(): string
{
    $dataDir = dirname(\Pasargad\Support\Config::str('db.path', __DIR__ . '/../data/bot.sqlite'));

    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }

    return rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'worker.lock';
}

$lockFile = lock_file();
$lock     = @fopen($lockFile, 'c');

/**
 * اعلان‌ساز، اگر ساخته شود.
 *
 * چرا می‌تواند null باشد؟ روی نصب تازه‌ای که هنوز `bot_token` در config.php
 * نشده، `new Notifier()` استثنا می‌دهد. اگر آن استثنا بیرون بزند، کل worker
 * می‌میرد و **کارهای دیتابیس هم انجام نمی‌شود** — یعنی هشدارها نمی‌روند،
 * قطع دسترسی انجام نمی‌شود و پاداش معرفی پرداخت نمی‌شود، فقط چون توکن
 * تنظیم نیست. پس ساخت اعلان‌ساز همیشه fail-safe است.
 */
function make_notifier(): ?Notifier
{
    try {
        return new Notifier();
    } catch (Throwable $e) {
        Logger::warning('Notifier unavailable — bot_token not configured', [
            'error' => $e->getMessage(),
        ]);

        return null;
    }
}

if ($lock === false) {
    // ناتوانی در ساخت فایل قفل نباید باعث شود کرون بی‌صدا از کار بیفتد.
    fwrite(STDERR, "هشدار: فایل قفل ساخته نشد (" . $lockFile . ") — بدون قفل ادامه می‌دهیم.\n");
    $lock = null;
} elseif (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "worker دیگری در حال اجراست؛ خروج.\n";
    exit(0);
}

try {
    $db = Db::instance();
    (new Migrator($db))->migrate();

    // اطمینان از وجود پوشهٔ لاگ (اگر نبود، لاگ‌ها بی‌صدا از دست می‌رفتند).
    $logDir = \Pasargad\Support\Config::str('log.path', __DIR__ . '/../data/logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    // ۱) اجرای خودکار بسته‌های پرداخت‌شده
    $orders    = new OrderRepository($db);
    $users     = new UserRepository($db);
    $panels    = new PanelRepository($db);
    $provisioner = new Provisioner(null, $orders, $users, null, $panels);

    $result = $provisioner->processQueue(10);

    echo 'پردازش صف: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

    // ۱-الف) ♻️ پخش دوبارهٔ آپدیت‌هایی که پردازششان نیمه‌کاره مانده بود.
    //
    // چرا اینجا و نه داخل درخواستِ وبهوک: تلگرام هر آپدیت را فقط یک‌بار
    // می‌فرستد. اگر پردازشش خطا بدهد، بدون این مرحله برای همیشه از دست می‌رود
    // (مشتری رسید می‌فرستد، سفارش باز می‌ماند، کسی خبردار نمی‌شود).
    //
    // و چرا اینجا `sleep` نمی‌کنیم: خوابیدن درون وب‌سرور باعث تایم‌اوت تلگرام و
    // ارسال دوبارهٔ همان آپدیت می‌شد — دو نسخهٔ همزمان از یک پردازشِ پول‌محور.
    try {
        $replay = \Pasargad\Bot\WebhookReliability::replay(
            static function (array $payload): void {
                (new \Pasargad\Bot\Kernel())->handle(new \Pasargad\Telegram\Update($payload));
            },
            20
        );

        if (($replay['attempted'] ?? 0) > 0) {
            echo 'پخش دوبارهٔ آپدیت‌ها: ' . json_encode($replay, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Throwable $e) {
        echo 'خطای پخش دوبارهٔ آپدیت‌ها: ' . $e->getMessage() . "\n";
    }

    // ۱-ب) ⚡️ بررسی خودکار فاکتورهای کارت‌به‌کارت خودکار
    //
    // سفارش‌هایی که با مبلغ یکتا پرداخت شده‌اند، اینجا از روی استعلام کارت
    // تأیید و اجرا می‌شوند — بدون نیاز به رسید دستی. 📝❌
    try {
        $autoFlags = new FeatureFlags();
        $autoCard  = new \Pasargad\Payment\AutoCardGateway();

        if ($autoFlags->isGatewayEnabled(\Pasargad\Payment\AutoCardGateway::NAME) && $autoCard->isEnabled()) {
            $autoPayments = new \Pasargad\Payment\PaymentService($orders, $provisioner, new \Pasargad\Store\Settings($db), $autoFlags);
            $autoNotifier = make_notifier();

            if ($autoNotifier !== null) {
                $autoPayments->setNotifier($autoNotifier);
            }

            $checked = 0;
            $confirmed = 0;

            foreach ($orders->awaitingAutoCard(20) as $autoOrder) {
                $checked++;

                try {
                    $checkResult = $autoPayments->checkAndMaybeApply($autoOrder);

                    if ($checkResult['paid'] ?? false) {
                        $confirmed++;
                    }
                } catch (Throwable $e) {
                    echo 'خطای استعلام خودکار سفارش ' . ($autoOrder['id'] ?? '?') . ': ' . $e->getMessage() . "\n";
                }
            }

            if ($checked > 0) {
                echo "کارت خودکار: {$checked} بررسی، {$confirmed} تأیید ✅\n";
            }
        }
    } catch (Throwable $e) {
        echo 'خطای کارت خودکار: ' . $e->getMessage() . "\n";
    }

    // ------------------------------------------------------------------
    // ۲) هشدارهای پنل (حجم کم، انقضای نزدیک، انقضای قطع‌شده)
    //
    // نکتهٔ مهم: AlertService خودش ابتدا پنل‌ها را همگام می‌کند، پس لازم
    // نیست اینجا جداگانه sync کنیم — وگرنه دو بار به پنل درخواست می‌رفت.
    //
    // ولی اگر سوییچ «همگام‌سازی خودکار» خاموش باشد، همگام‌سازی جدا انجام
    // می‌شود ولی هشدارها فقط بر اساس داده‌های ذخیره‌شده محاسبه می‌شوند.
    // ------------------------------------------------------------------
    $flags  = new FeatureFlags();
    $syncer = new PanelSyncer($panels);

    if ($flags->isPanelSyncEnabled()) {
        // ⚠️ فقط پنل‌هایی که واقعاً نیاز دارند.
        //
        // کرون ممکن است هر چند دقیقه یک‌بار اجرا شود، ولی پنلی که ده ثانیه پیش
        // خوانده شده چیزِ تازه‌ای برای گفتن ندارد. فرستادن درخواستِ بیهوده
        // بودجهٔ زمانی را می‌خورد و پنل‌های واقعیِ دیگر را بی‌وقت رها می‌کند.
        $due = \Pasargad\Panel\PanelSyncManager::dueForSync($panels->listWatchable(200));

        if ($due === []) {
            // ⚠️ پیام باید بگوید چند پنل اصلاً بود — وگرنه «همه به‌تازگی همگام
            // بودند» برای فروشگاهی که هیچ پنلی ندارد، گمراه‌کننده است و یعنی
            // کسی فکر می‌کند بررسی انجام شده.
            printf(
                "همگام‌سازی پنل‌ها: %d پنل دیده شد، نیازی به بررسی نبود.\n",
                count($panels->listWatchable(200))
            );
        } else {
            $synced = $syncer->syncMany($due);

            if ($synced['synced'] > 0 || $synced['failed'] > 0) {
                echo sprintf("همگام‌سازی پنل‌ها: %d بررسی، %d موفق، %d ناموفق\n",
                    $synced['checked'], $synced['synced'], $synced['failed']);
            }

            // سقف زمانی اجرا را خوردیم ⇒ بقیه در اجرای بعدی بررسی می‌شوند.
            // بدون این پیام، کسی فکر می‌کند همهٔ پنل‌ها همگام شدند.
            if ($synced['skipped'] > 0) {
                echo sprintf(
                    "⚠️ سقف زمانی همگام‌سازی رسید: %d پنل برای اجرای بعدی ماند.\n",
                    $synced['skipped']
                );
            }
        }

        // پنل‌های بدون کلید بی‌سروصدا از کار می‌افتند؛ این عدد تنها راهِ
        // دیدنشان است تا برایشان کلید ساخته شود.
        $report = \Pasargad\Panel\PanelSyncManager::getSyncStatusReport();

        if ($report['unlinked'] > 0) {
            echo sprintf(
                "🔑 %d پنل کلید API ندارد و همگام نمی‌شود — نماینده باید کلید بسازد.\n",
                $report['unlinked']
            );
        }
    }

    // اعلان‌ها باید به سوپرادمین‌ها هم برسند (هشدار برای خریدار و مدیر).
    $alerts  = new AlertService($panels);
    $notifier = make_notifier();

    if ($notifier !== null) {
        $alerts->setNotifier($notifier);
    }

    $alertResult = $alerts->runAll();

    $alertTotal = $alertResult['low_volume'] + $alertResult['expiring']
        + $alertResult['grace'] + $alertResult['expired'];

    if ($alertTotal > 0) {
        echo sprintf(
            "هشدارها: %d حجم کم، %d انقضای نزدیک، %d مهلت ارفاقی، %d انقضای قطع‌شده (%d درخواست قطع دسترسی)\n",
            $alertResult['low_volume'],
            $alertResult['expiring'],
            $alertResult['grace'],
            $alertResult['expired'],
            $alertResult['cutoff_requested']
        );
    }

    // ------------------------------------------------------------------
    // ۲-ب) 🎁 پاداش معرفی
    //
    // فقط وقتی داده می‌شود که معرفی‌شده یک خرید **پرداخت‌شده** داشته باشد.
    // دادن پاداش در لحظهٔ ثبت سفارش یعنی یک نفر می‌تواند چند سفارش باز ثبت
    // کند، پاداش بگیرد و هرگز پرداخت نکند.
    // ------------------------------------------------------------------
    try {
        $discounts = new \Pasargad\Store\DiscountService(settings: new \Pasargad\Store\Settings($db));
        $rewarded  = $discounts->rewardReferrers($notifier);

        if ($rewarded['rewarded'] > 0) {
            echo sprintf(
                "پاداش معرفی: %d نفر، %d تومان به کیف پول اضافه شد 🎁\n",
                $rewarded['rewarded'],
                $rewarded['toman']
            );
        }
    } catch (Throwable $e) {
        echo 'خطای پاداش معرفی: ' . $e->getMessage() . "\n";
    }

    // ------------------------------------------------------------------
    // ۲-ب) کمیسیونِ درصدیِ خریدهای بعدی (مدل دوم)
    //
    // جدا از پاداشِ اولیه اجرا می‌شود، چون منطقشان فرق دارد: پاداشِ اولیه
    // «یک بار برای هر معرفی» است، کمیسیون «به ازای هر سفارشِ بعدی، تا سقف».
    // اگر در یک حلقه قاطی شوند، سقفِ کمیسیون می‌توانست پاداشِ اولیه را هم
    // بخورد و یک معرف، هم پاداش بگیرد هم بیشتر از سقف کمیسیون.
    //
    // متد خودش در حالتِ خاموش فوری برمی‌گردد، پس وقتی کمیسیون غیرفعال است
    // این خط عملاً هزینه‌ای ندارد.
    // ------------------------------------------------------------------
    try {
        $discounts2  = new \Pasargad\Store\DiscountService(settings: new \Pasargad\Store\Settings($db));
        $commission  = $discounts2->payCommissions($notifier);

        if ($commission['orders'] > 0) {
            echo sprintf(
                "کمیسیون معرفی: %d سفارش از %d معرف، %d تومان 💸\n",
                $commission['orders'],
                $commission['referrers'],
                $commission['toman']
            );
        }

        // ⚠️ ردیف‌هایی که در دفتر ثبت شدند ولی پولشان نرسید. عمداً پاک نمی‌شوند
        // (پاک کردن = کرونِ بعدی دوباره می‌ریزد و پولِ مضاعف می‌دهد)، پس
        // تنها کاری که می‌کنیم این است که **بلند** گزارش کنیم تا دیده شود.
        if ($commission['skipped'] > 0) {
            echo sprintf(
                "⚠️ کمیسیون: %d مورد رد/ناموفق — بررسی کنید (کرون: 'Referral commission payout failed')\n",
                $commission['skipped']
            );
        }
    } catch (Throwable $e) {
        echo 'خطای کمیسیون معرفی: ' . $e->getMessage() . "\n";
    }

    // ۳) پاک‌سازی کانفیگ‌های تست قدیمی و نشست‌های منقضی
    $prunedConfigs = (new TestConfigRepository($db))->prune(90);
    if ($prunedConfigs > 0) {
        echo "کانفیگ‌های تست قدیمی پاک شدند: {$prunedConfigs}\n";
    }

    // ۳-ب) بکاپ خودکار دیتابیس (روزانه ۱ بار یا ۲ بار در روز)
    try {
        $cronSettings = new \Pasargad\Store\Settings($db);

        if (\Pasargad\Support\Backup::isDue()) {
            $backup = \Pasargad\Support\Backup::run($cronSettings);

            if ($backup['ok'] ?? false) {
                echo 'بکاپ ساخته شد: ' . ($backup['path'] ?? '') . "\n";

                // ارسال به سوپرادمین‌ها
                if ($notifier !== null) {
                    try {
                        foreach ($notifier->adminIds() as $adminId) {
                            \Pasargad\Support\Backup::sendTo((int) $adminId, (string) $backup['path']);
                        }
                    } catch (Throwable $e) {
                        echo 'ارسال بکاپ ناموفق بود: ' . $e->getMessage() . "\n";
                    }
                }
            } else {
                echo 'بکاپ ناموفق بود: ' . ($backup['message'] ?? '') . "\n";
            }
        }
    } catch (Throwable $e) {
        echo 'خطای بکاپ: ' . $e->getMessage() . "\n";
    }

    $pruned = (new SessionStore($db))->prune();
    if ($pruned > 0) {
        echo "نشست‌های منقضی پاک شدند: {$pruned}\n";
    }

    // 🛡️ پاک‌سازی جدول‌های ضدتکرار
    $floodPruned = (new \Pasargad\Bot\FloodGuard($db))->prune();
    if (($floodPruned['flood'] ?? 0) > 0 || ($floodPruned['updates'] ?? 0) > 0) {
        echo "ضدتکرار پاک‌سازی شد: {$floodPruned['flood']} ردیف طغیان، {$floodPruned['updates']} آپدیت 🧹\n";
    }

    // ۴) گزارش کوتاه وضعیت
    $stats = $orders->stats();
    echo sprintf(
        "وضعیت: %d سفارش، %d اجراشده، %d ناموفق، %d در انتظار رسید، %d پنل فعال\n",
        $stats['total'],
        $stats['applied'],
        $stats['failed'],
        $stats['awaiting'],
        count($panels->listWatchable(500))
    );

    // ۴-ب) گزارش دوره‌ای به ادمین
    try {
        $rep = new \Pasargad\Store\Settings($db);
        $mkReport = function (int $days) use ($db, $alertResult, $panels): string {
            $since = time() - $days * 86400;
            $count = (int) $db->value(
                "SELECT COUNT(*) FROM orders WHERE status IN ('paid','applied') AND paid_at IS NOT NULL AND paid_at >= ?",
                [$since]
            );
            $sum = (int) $db->value(
                "SELECT COALESCE(SUM(price_toman),0) FROM orders WHERE status IN ('paid','applied') AND paid_at IS NOT NULL AND paid_at >= ?",
                [$since]
            );
            $low  = (int) ($alertResult['low_volume'] ?? 0);
            $exp  = (int) (($alertResult['expiring'] ?? 0) + ($alertResult['expired'] ?? 0));
            $watch = count($panels->listWatchable(500));

            return "📊✨ <b>گزارش"
                . ($days >= 7 ? ' هفتگی' : ' روزانه')
                . "</b>\n\n"
                . '🛒 فروش‌ها: <b>' . $count . "</b>\n"
                . '💰 مبلغ: <b>' . \Pasargad\Support\Str::formatToman($sum) . "</b>\n"
                . '⚠️ پنل کم‌حجم: <b>' . $low . "</b>\n"
                . '⌛️ اعتبار تمام‌شده/نزدیک: <b>' . $exp . "</b>\n"
                . '🟢 پنل‌های فعال: <b>' . $watch . "</b>";
        };

        if ($rep->bool(\Pasargad\Store\Settings::REPORT_DAILY)) {
            $last = (int) ($rep->get(\Pasargad\Store\Settings::REPORT_DAILY_LAST) ?? 0);
            if ($last + 86400 <= time() && $notifier !== null) {
                foreach ($notifier->adminIds() as $adminId) {
                    $notifier->notifyUser((int) $adminId, $mkReport(1));
                }
                $rep->set(\Pasargad\Store\Settings::REPORT_DAILY_LAST, (string) time());
                echo "گزارش روزانه ارسال شد.\n";
            }
        }

        if ($rep->bool(\Pasargad\Store\Settings::REPORT_WEEKLY)) {
            $last = (int) ($rep->get(\Pasargad\Store\Settings::REPORT_WEEKLY_LAST) ?? 0);
            if ($last + 604800 <= time() && $notifier !== null) {
                foreach ($notifier->adminIds() as $adminId) {
                    $notifier->notifyUser((int) $adminId, $mkReport(7));
                }
                $rep->set(\Pasargad\Store\Settings::REPORT_WEEKLY_LAST, (string) time());
                echo "گزارش هفتگی ارسال شد.\n";
            }
        }
    } catch (Throwable $e) {
        echo 'خطای گزارش دوره‌ای: ' . $e->getMessage() . "\n";
    }

    Logger::info('Worker finished', $result);
    exit(0);
} catch (Throwable $e) {
    Logger::error('Worker crashed', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile() . ':' . $e->getLine(),
    ]);

    fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}