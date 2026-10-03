<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\Settings;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;
use Pasargad\Telegram\BotApi;

/**
 * دروازهٔ عضویت اجباری کانال.
 *
 * منطق: تا وقتی کاربر عضو کانال نشده، هیچ قابلیتی (خرید، دیدن پنل، تست
 * کانفیگ) در اختیارش قرار نمی‌گیرد — فقط پیام «عضو شو» و دکمهٔ بررسی مجدد.
 *
 * چرا کش؟
 * `getChatMember` یک درخواست شبکه به تلگرام است. بدون کش، هر پیام کاربر یک
 * درخواست اضافه می‌ساخت و در ساعات شلوغ ربات به سقف نرخ تلگرام می‌خورد و کل
 * پیام‌ها صف می‌شد. نتیجهٔ کش چند دقیقه‌ای: کاربری که عضو شده حداکثر چند
 * دقیقه بعد از ربات اجازه می‌گیرد که کاملاً قابل قبول است.
 *
 * چرا سوپرادمین‌ها exempt هستند؟
 * اگر ادمین هم گیر بیفتد، عملاً ربات برای همیشه قفل می‌شود و هیچ‌کس نمی‌تواند
 * آن را باز کند. این بدترین حالت ممکن است، پس استثنا واجب است.
 */
final class ChannelGuard
{
    private BotApi $bot;
    private Settings $settings;

    public function __construct(BotApi $bot, Settings $settings)
    {
        $this->bot      = $bot;
        $this->settings = $settings;
    }

    /**
     * آیا عضویت اجباری روشن است و کانالی تنظیم شده؟
     */
    public function isRequired(): bool
    {
        return $this->settings->bool(Settings::CHANNEL_ENFORCED, false)
            && $this->channel() !== '';
    }

    /**
     * شناسهٔ کانال. ورودی می‌تواند «@name»، «name» یا «-100…» باشد.
     */
    public function channel(): string
    {
        $channel = trim((string) $this->settings->get(Settings::CHANNEL, ''));

        // اگر فقط اسم بدون @ بود، به شکل قابل استفاده برای getChatMember درمی‌آید
        return ltrim($channel, '@');
    }

    /**
     * لینک دعوت کانال برای دکمهٔ «عضویت».
     */
    public function inviteLink(): string
    {
        $channel = trim((string) $this->settings->get(Settings::CHANNEL, ''));

        if ($channel === '') {
            return '';
        }

        if (str_starts_with($channel, 'http')) {
            return $channel;
        }

        return 'https://t.me/' . ltrim($channel, '@');
    }

    /**
     * عنوان نمایشی کانال.
     */
    public function channelTitle(): string
    {
        $channel = trim((string) $this->settings->get(Settings::CHANNEL, ''));

        return $channel === '' ? 'کانال رسمی' : ltrim($channel, '@');
    }

    /**
     * بررسی عضویت یک کاربر (با کش).
     *
     * @return array{ok:bool, member:bool, cached:bool, error?:string}
     */
    public function check(int $telegramId): array
    {
        if (!$this->isRequired()) {
            return ['ok' => true, 'member' => true, 'cached' => false];
        }

        $cacheKey = $this->cacheKey($telegramId);
        $cached   = $this->settings->get($cacheKey);

        if ($cached === '1') {
            return ['ok' => true, 'member' => true, 'cached' => true];
        }

        if ($cached === '0') {
            // نتیجهٔ منفی هم کش می‌شود ولی کوتاه‌تر (نصف عمر)، تا اگر کاربر
            // همین حالا عضو شده باشد زودتر راه بیفتد.
            $ttl = (int) ($this->settings->get($cacheKey . ':t') ?? 0);

            if ($ttl > time()) {
                return ['ok' => true, 'member' => false, 'cached' => true];
            }
        }

        $status = $this->fetchStatus($telegramId);

        if ($status['error'] !== null) {
            // خطای شبکه نباید کاربر را قفل کند. «باز» فرض می‌کنیم و فقط لاگ
            // می‌زنیم — قفل کردن ربات به‌خاطر قطعی اینترنت، بدترین حالت است.
            Logger::warning('Channel membership check failed', [
                'user_id' => $telegramId,
                'error'   => $status['error'],
            ]);

            return ['ok' => false, 'member' => true, 'cached' => false, 'error' => $status['error']];
        }

        if ($status['member']) {
            $this->settings->set($cacheKey, '1');
        } else {
            $this->settings->set($cacheKey, '0');
            $this->settings->set($cacheKey . ':t', (string) (time() + 300));
        }

        return ['ok' => true, 'member' => $status['member'], 'cached' => false];
    }

    /**
     * فراخوانی واقعی getChatMember.
     *
     * @return array{member:bool, error:?string}
     */
    private function fetchStatus(int $telegramId): array
    {
        $result = $this->bot->call('getChatMember', [
            'chat_id' => $this->channel(),
            'user_id' => $telegramId,
        ]);

        if (!($result['ok'] ?? false)) {
            return ['member' => false, 'error' => (string) ($result['description'] ?? 'unknown')];
        }

        $status = strtolower((string) ($result['result']['status'] ?? ''));

        // «restricted» هم یعنی عضو است (فقط حق سکوت/ارسال محدود شده).
        $member = in_array($status, [
            'creator',
            'administrator',
            'member',
            'restricted',
        ], true);

        return ['member' => $member, 'error' => null];
    }

    /**
     * پاک کردن کش یک کاربر (دکمهٔ «بررسی مجدد» این را صدا می‌زند).
     */
    public function forget(int $telegramId): void
    {
        $this->settings->set($this->cacheKey($telegramId), '');
    }

    /**
     * متن و کیبوردِ «باید عضو شوید».
     *
     * @return array{0:string, 1:array<int, array<int, array<string, mixed>>>}
     */
    public function gateScreen(): array
    {
        $title  = $this->channelTitle();
        $invite = $this->inviteLink();

        $lines = [
            '📢✨ <b>عضویت در کانال الزامی است! 🔒</b>',
            '',
            'برای استفاده از ربات نمایندگان، باید در کانال رسمی <b>' . Str::escape($title) . '</b> عضو باشید! 👇✅',
            '',
            '۱️⃣ روی دکمهٔ «📢 عضویت در کانال» بزنید! 👆',
            '۲️⃣ بعد از عضو شدن، «✅ بررسی مجدد» را بزنید! 🔄',
        ];

        $rows = [];

        if ($invite !== '') {
            $rows[] = [['text' => '📢 عضویت در کانال', 'url' => $invite, 'style' => 'url']];
        }

        $rows[] = [['text' => '✅ بررسی مجدد', 'data' => BotApi::encodeData('channel.recheck')]];
        $rows[] = [['text' => '🏠 منوی اصلی', 'data' => BotApi::encodeData('menu')]];

        return [implode("\n", $lines), $rows];
    }

    private function cacheKey(int $telegramId): string
    {
        return 'cm:' . $telegramId;
    }
}