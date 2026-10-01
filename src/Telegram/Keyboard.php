<?php

declare(strict_types=1);

namespace Pasargad\Telegram;

use Pasargad\Support\Str;

/**
 * ساخت کیبوردهای اینلاین به‌صورت فشرده.
 *
 * نمونه:
 *   $kb = Keyboard::rows([
 *       [['text' => 'بله', 'data' => 'yn']],
 *       [['text' => 'خیر', 'data' => 'no']],
 *   ]);
 */
final class Keyboard
{
    /**
     * @param  array<int, array<int, array<string, mixed>>> $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function rows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = array_values($row);
        }

        return $result;
    }

    /**
     * ساخت یک ردیف از دکمه‌ها با طول مشخص.
     *
     * @param  array<int, array{text:string, data:string}> $buttons
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
     * دکمهٔ بازگشت.
     */
    public static function back(string $data = 'menu', string $text = '🔙 بازگشت'): array
    {
        return [['text' => $text, 'data' => $data]];
    }

    /**
     * کیبورد «بله/خیر».
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
     */
    public static function close(): array
    {
        return [['text' => '✖️ بستن', 'data' => 'close']];
    }

    /**
     * کیبورد صفحه‌بندی با دکمه‌های قبلی/بعدی.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function paginate(string $namespace, array $items, int $page, int $perPage = 6): array
    {
        $rows    = [];
        $chunks  = array_chunk($items, $perPage);
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

        $totalPages = max(1, (int) ceil(count($items) / $perPage));
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
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     * @param array<int, array<string, mixed>> $row
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function append(array $keyboard, array $row): array
    {
        if ($row !== []) {
            $keyboard[] = $row;
        }

        return $keyboard;
    }

    /**
     * دکمهٔ لینک بیرونی.
     */
    public static function link(string $text, string $url): array
    {
        return [['text' => $text, 'url' => $url, 'style' => 'url']];
    }
}