<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\PackageRepository;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * جریان ساخت/ویرایش بسته از طریق پیام متنی سوپرادمین.
 *
 * فرمت ورودی:
 *   عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان
 *
 * نوع: `agency` (پنل نمایندگی) یا `topup` (شارژ پنل). نام‌های فارسی و
 * نام‌های قدیمی هم پذیرفته می‌شوند (نگاه کنید به normalizeKind).
 */
final class PackageEditor
{
    private PackageRepository $packages;
    private BotApi $bot;

    public function __construct(PackageRepository $packages, BotApi $bot)
    {
        $this->packages = $packages;
        $this->bot      = $bot;
    }

    /**
     * تلاش برای پردازش پیام مدیریتی؛ اگر پیام مربوط نبود false برمی‌گرداند.
     *
     * @return bool true یعنی پیام مصرف شد
     */
    public function tryHandle(int $chatId, string $text, int $adminId, ?int $editPackageId = null): bool
    {
        $text = trim($text);

        if ($text === '' || !str_contains($text, '|')) {
            return false;
        }

        $parsed = $this->parse($text);
        if ($parsed === null) {
            $this->bot->sendMessage($chatId, implode("\n", [
                '⚠️ <b>فرمت ورودی نامعتبر است</b>',
                '',
                'فرمت صحیح:',
                '<code>عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان | سقف_کاربران | دوره‌ها</code>',
                '',
                'مثال:',
                '<code>پنل ۱۰۰ گیگ ۳۰ روزه | agency | 100 | 30 | 500000 | 50 | 30:500000,90:1350000,365:4500000</code>',
                '',
                '💡 بخش آخر (سقف تعداد کاربران پنل) اختیاری است؛ اگر ننویسید نامحدود ♾️ می‌شود.',
                '💡 بخش دوره‌ها اختیاری است و برای قیمت‌گذاری ماهانه/سه‌ماهه/سالانه استفاده می‌شود.',
                '',
                'نوع بسته:',
                '• <code>agency</code> (یا «پنل نمایندگی») — ساخت پنل تازه',
                '• <code>topup</code> (یا «شارژ») — شارژ پنل موجود',
                '',
                'برای اینکه کاربر بتواند چند پنل بخرد، سقف هر کاربر را ۰ بگذارید (نامحدود).',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                    Keyboard::back(BotApi::encodeData('admin.packages'), '🗑 انصراف'),
                ])),
            ]);

            return true;
        }

        if ($editPackageId !== null) {
            $this->packages->update($editPackageId, $parsed);
            $this->bot->sendMessage($chatId, '✅ بسته با موفقیت به‌روزرسانی شد.');
        } else {
            $parsed['is_active'] = true;
            $parsed['sort_order'] = count($this->packages->allPackages(false));
            $newId = $this->packages->create($parsed);
            $this->bot->sendMessage($chatId, '✅ بستهٔ جدید ساخته شد (شناسه: ' . $newId . ').');
        }

        $this->showPackagesMenu($chatId);
        return true;
    }

    /**
     * تجزیهٔ رشتهٔ ورودی به دادهٔ بسته.
     *
     * @return array<string, mixed>|null
     */
    private function parse(string $text): ?array
    {
        $parts = array_map('trim', explode('|', $text));

        if (count($parts) < 5) {
            return null;
        }

        $title = $parts[0];
        $kind  = PackageRepository::normalizeKind($parts[1]);
        $vol   = (float) Str::toEnglishDigits($parts[2]);
        $days  = (int) Str::toEnglishDigits($parts[3]);
        $price = (int) Str::toEnglishDigits($parts[4]);

        // سقف کاربران اختیاری است (بخش ششم)؛ نبودش یعنی نامحدود ♾️.
        $maxUsers = isset($parts[5]) && trim($parts[5]) !== ''
            ? (int) Str::toEnglishDigits(trim($parts[5]))
            : 0;

        // گزینه‌های چنددوره‌ای اختیاری (بخش هفتم)؛ مانند «30:500000,90:1400000,365:4800000»
        $prices = null;
        if (isset($parts[6]) && trim($parts[6]) !== '') {
            $raw = trim($parts[6]);
            $ok = true;
            foreach (explode(',', $raw) as $seg) {
                $p = explode(':', trim($seg), 2);
                if (count($p) !== 2 || (int) trim($p[0]) <= 0 || (int) trim($p[1]) < 0) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                return null;
            }
            $prices = $raw;
        }

        // اعتبارسنجی
        if ($title === '' || mb_strlen($title) > 100) {
            return null;
        }

        // فقط دو نوعِ قابل فروش پذیرفته می‌شود. نام‌های ناشناخته رد می‌شوند
        // تا بستهٔ اشتباه (مثلاً یک نوع منسوخ) در فروشگاه ظاهر نشود.
        if (!in_array($kind, PackageRepository::SHOP_KINDS, true)) {
            return null;
        }

        if ($vol <= 0 || $days < 0 || $price < 0 || $maxUsers < 0) {
            return null;
        }

        if ($vol > 100000) {
            return null;   // سقف منطقی برای جلوگیری از اشتباه
        }

        if ($maxUsers > 1000000) {
            return null;   // سقف منطقی تعداد کاربر
        }

        $out = [
            'title'         => $title,
            'kind'          => $kind,
            'volume_gb'     => $vol,
            'duration_days' => $days,
            'price_toman'   => $price,
            'max_users'     => $maxUsers,
        ];

        // فقط وقتی دوره‌ها وارد شده باشند کلید prices اضافه شود؛ در غیر این صورت
        // در حالت ویرایش، یک بستهٔ چنددوره‌ای با null پاک نمی‌شود.
        if ($prices !== null) {
            $out['prices'] = $prices;
        }

        return $out;
    }

    private function showPackagesMenu(int $chatId): void
    {
        $this->bot->sendMessage($chatId, '📦 <b>لیست بسته‌ها</b>', [
            'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([
                [['text' => '➕ بستهٔ جدید', 'data' => BotApi::encodeData('admin.pkg.new')]],
                [['text' => '🛠 پنل مدیریت', 'data' => BotApi::encodeData('admin.home')]],
            ])),
        ]);
    }
}