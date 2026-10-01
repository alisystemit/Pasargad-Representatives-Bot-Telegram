<?php

declare(strict_types=1);

namespace Pasargad\Telegram;

use Pasargad\Support\Str;

/**
 * ساخت کیبوردهای اینلاین به‌صورت فشرده و امن.
 *
 * ساختار استاندارد کیبورد: «ردیف»هایی که هر ردیف آرایه‌ای از «دکمه»هاست:
 *   [ [ ['text'=>'a','data'=>'x'], ['text'=>'b','data'=>'y'] ],  [ ['text'=>'back','data'=>'menu'] ]  ]
 *
 * برای راحتی، Keyboard::rows() ورودی‌های نامنظم را هم می‌پذیرد و نرمال می‌کند،
 * بنابراین Keyboard::back() را می‌توان هم مستقیم به buildMarkup() داد و هم داخل rows().
 */
final class Keyboard
{
    /**
     * نرمال‌سازی ورودی به ساختار «ردیف‌های کیبورد».
     *
     * ورودی می‌تواند یکی از این شکل‌ها باشد و همه یک خروجی می‌دهند:
     *   ۱) ['text' => 'a', 'data' => 'b']                → یک دکمه (خودش یک ردیف)
     *   ۲) [['text'=>'a','data'=>'b'], ['text'=>'c', …]] → دو دکمه در یک ردیف
     *   ۳) [[[ … ]], [[ … ]]]                            → چند ردیف
     *
     * @param  array<int, mixed> $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function rows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // یک دکمهٔ تکی است → آن را در یک ردیف می‌پیچیم.
            if (self::isButton($row)) {
                $result[] = [$row];
                continue;
            }

            // آرایه‌ای از دکمه‌ها → همان یک ردیف است.
            // (اگر خروجی back()/line() باشد که خودش ردیف‌های آماده است،
            //  هر ردیف درونی جداگانه به نتیجه اضافه می‌شود.)
            $buttons = [];
            foreach ($row as $button) {
                if (!is_array($button)) {
                    continue;
                }

                if (self::isButton($button)) {
                    $buttons[] = $button;
                } elseif (self::isReadyRow($button)) {
                    // این عنصر خودش یک ردیف کامل است (مثلاً back()).
                    if ($buttons !== []) {
                        $result[] = $buttons;
                        $buttons = [];
                    }
                    $result[] = $button;
                }
            }

            if ($buttons !== []) {
                $result[] = $buttons;
            }
        }

        return $result;
    }

    /**
     * آیا این آرایه یک «ردیف آماده» است؟
     *
     * یعنی هر عنصرش یک دکمه است — نه اینکه خودش دکمه باشد و نه اینکه
     * عناصرش دوباره لیست دکمه باشند.
     *
     * @param  array<int, mixed> $candidate
     */
    private static function isReadyRow(array $candidate): bool
    {
        if ($candidate === []) {
            return false;
        }

        foreach ($candidate as $item) {
            if (!is_array($item) || !self::isButton($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * آیا این آرایه یک دکمه است؟ (کلیدهای مشخصهٔ دکمه را دارد)
     *
     * @param array<mixed> $candidate
     */
    public static function isButton(array $candidate): bool
    {
        return isset($candidate['text']) || isset($candidate['url']);
    }

    /**
     * ساخت چند ردیف از روی فهرست دکمه‌ها با تعداد دلخواه در هر ردیف.
     *
     * @param  array<int, array<string, mixed>> $buttons
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function line(array $buttons, int $perRow = 2): array
    {
        $rows = [];
        foreach (array_chunk($buttons, max(1, $perRow)) as $chunk) {
            $rows[] = $chunk;
        }

        return $rows;
    }

    /**
     * دکمهٔ بازگشت (به‌تنهایی در یک ردیف).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function back(string $data = 'menu', string $text = '🔙 بازگشت'): array
    {
        return [[['text' => $text, 'data' => $data]]];
    }

    /**
     * کیبورد «بله/خیر».
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function confirmYesNo(string $yesData = 'conf:yes', string $noData = 'conf:no'): array
    {
        return [[
            ['text' => '✅ بله', 'data' => $yesData],
            ['text' => '❌ خیر', 'data' => $noData],
        ]];
    }

    /**
     * دکمهٔ بستن.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function close(): array
    {
        return [[['text' => '✖️ بستن', 'data' => 'close']]];
    }

    /**
     * کیبورد صفحه‌بندی با دکمه‌های قبلی/بعدی.
     *
     * @param  array<int, array<string, mixed>> $items
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function paginate(string $namespace, array $items, int $page, int $perPage = 6): array
    {
        $rows   = [];
        $chunks = array_chunk($items, max(1, $perPage));
        $buttons = [];

        foreach ($chunks[$page] ?? [] as $item) {
            $buttons[] = [
                'text' => Str::truncate((string) ($item['title'] ?? ''), 30),
                'data' => $namespace . ':' . ($item['id'] ?? ''),
            ];
        }

        if ($buttons !== []) {
            $rows = self::line($buttons, 2);
        }

        $totalPages = max(1, (int) ceil(count($items) / max(1, $perPage)));
        $nav        = [];

        if ($page > 0) {
            $nav[] = ['text' => '◀️ قبلی', 'data' => $namespace . ':page:' . ($page - 1)];
        }
        if ($page < $totalPages - 1) {
            $nav[] = ['text' => 'بعدی ▶️', 'data' => $namespace . ':page:' . ($page + 1)];
        }
        if ($nav !== []) {
            $rows[] = $nav;
        }

        return $rows;
    }

    /**
     * اضافه کردن یک ردیف دکمه به انتهای کیبورد.
     *
     * @param  array<int, array<int, array<string, mixed>>> $keyboard
     * @param  array<int, array<string, mixed>>             $row
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function append(array $keyboard, array $row): array
    {
        // ورودی می‌تواند یک دکمهٔ تکی یا آرایه‌ای از دکمه‌ها باشد.
        $buttons = self::isButton($row) ? [$row] : array_values(array_filter($row, 'is_array'));

        if ($buttons !== []) {
            $keyboard[] = $buttons;
        }

        return $keyboard;
    }

    /**
     * دکمهٔ لینک بیرونی.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function link(string $text, string $url): array
    {
        return [[[ 'text' => $text, 'url' => $url, 'style' => 'url' ]]];
    }
}