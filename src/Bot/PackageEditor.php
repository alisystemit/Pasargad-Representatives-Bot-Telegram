<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\PackageRepository;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;
use Pasargad\Telegram\Keyboard;

/**
 * جریان ساخت/ویرایش بسته از طریق پیام متنی سوپرADMین.
 *
 * فرمت ورودی:
 *   عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان
 *
 * نوع: panel_quota (حجم پنل) یا user_credit (اعتبار کاربر)
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
                '<code>عنوان | نوع | حجم_گیگ | مدت_روز | قیمت_تومان</code>',
                '',
                'مثال:',
                '<code>بسته ۱۰۰ گیگ | panel_quota | 100 | 30 | 500000</code>',
                '',
                'نوع بسته:',
                '• <code>panel_quota</code> — حجم مستقیم پنل',
                '• <code>user_credit</code> — اعتبار ساخت کاربر',
            ]), [
                'reply_markup' => $this->bot->buildMarkup(Keyboard::rows([Keyboard::back('admin.packages', '🗑 انصراف')])),
            ]);

            return true;
        }

        if ($editPackageId !== null) {
            $this->packages->update($editPackageId, $parsed);
            $this->bot->sendMessage($chatId, '✅ بسته با موفقیت به‌روزرسانی شد.');
        } else {
            $parsed['is_active'] = true;
            $parsed['sort_order'] = (int) ($this->packages->allPackages(false) ? count($this->packages->allPackages(false)) : 0);
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
        $kind  = strtolower($parts[1]);
        $vol   = (float) Str::toEnglishDigits($parts[2]);
        $days  = (int) Str::toEnglishDigits($parts[3]);
        $price = (int) Str::toEnglishDigits($parts[4]);

        // اعتبارسنجی
        if ($title === '' || mb_strlen($title) > 100) {
            return null;
        }

        $kind = match ($kind) {
            'panel_quota', 'panel', 'quota', 'پنل' => PackageRepository::KIND_PANEL_QUOTA,
            'user_credit', 'credit', 'user', 'کاربر' => PackageRepository::KIND_USER_CREDIT,
            default => null,
        };

        if ($kind === null || $vol <= 0 || $days < 0 || $price < 0) {
            return null;
        }

        if ($vol > 100000) {
            return null;   // سقف منطقی برای جلوگیری از اشتباه
        }

        return [
            'title'         => $title,
            'kind'          => $kind,
            'volume_gb'     => $vol,
            'duration_days' => $days,
            'price_toman'   => $price,
        ];
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