<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Support\Str;

/**
 * Standardized message formatting for better UX
 * - Consistent error messages
 * - Loading states
 * - Success confirmations
 * - Progress indicators
 */
final class MessageFormatter
{
    /**
     * Format error message with action suggestions
     */
    public static function error(string $message, string $suggestion = ''): string
    {
        $text = "❌ <b>خطا</b>\n";
        $text .= "─────────────────────\n";
        $text .= $message . "\n";

        if ($suggestion !== '') {
            $text .= "\n💡 <i>$suggestion</i>";
        }

        return $text;
    }

    /**
     * Format success message
     */
    public static function success(string $message, array $details = []): string
    {
        $text = "✅ <b>موفق</b>\n";
        $text .= "─────────────────────\n";
        $text .= $message . "\n";

        if (!empty($details)) {
            $text .= "\n📋 جزئیات:\n";
            foreach ($details as $label => $value) {
                $text .= "  • <b>$label:</b> <code>$value</code>\n";
            }
        }

        return $text;
    }

    /**
     * Format warning message
     */
    public static function warning(string $message, string $action = ''): string
    {
        $text = "⚠️ <b>هشدار</b>\n";
        $text .= "─────────────────────\n";
        $text .= $message . "\n";

        if ($action !== '') {
            $text .= "\n👉 $action";
        }

        return $text;
    }

    /**
     * Format loading message
     */
    public static function loading(string $action): string
    {
        $spinner = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $frame = $spinner[(int) (time() * 10) % count($spinner)];

        return "$frame <i>$action...</i>";
    }

    /**
     * Format progress bar
     */
    public static function progress(int $current, int $total, int $width = 20): string
    {
        $percent = (int) round(($current / max($total, 1)) * 100);
        $filled = (int) round(($current / max($total, 1)) * $width);

        $bar = str_repeat('▰', $filled) . str_repeat('▱', max(0, $width - $filled));

        return "$bar <b>" . Str::faNumber($percent) . "%</b>";
    }

    /**
     * Format transaction confirmation
     */
    public static function transactionConfirmation(string $title, array $items): string
    {
        $text = "📋 <b>$title</b>\n";
        $text .= "═════════════════════════\n\n";

        foreach ($items as $label => $value) {
            $text .= "  <b>$label</b>\n";
            $text .= "  └─ <code>$value</code>\n\n";
        }

        return $text;
    }

    /**
     * Format input request with format hint
     */
    public static function inputRequest(string $field, string $hint = '', string $example = ''): string
    {
        $text = "📝 <b>$field را وارد کنید</b>\n";
        $text .= "─────────────────────\n";

        if ($hint !== '') {
            $text .= "\n💡 $hint\n";
        }

        if ($example !== '') {
            $text .= "\n📌 مثال: <code>$example</code>";
        }

        $text .= "\n\n❌ برای لغو: /cancel";

        return $text;
    }

    /**
     * Format validation error with hint
     */
    public static function validationError(string $field, string $error, string $hint = ''): string
    {
        $text = "⚠️ <b>$field نامعتبر است</b>\n";
        $text .= "─────────────────────\n";
        $text .= "$error\n";

        if ($hint !== '') {
            $text .= "\n💡 $hint";
        }

        return $text;
    }

    /**
     * Format list with proper formatting
     */
    public static function list(string $title, array $items, string $emptyMessage = '📭 موردی موجود نیست'): string
    {
        if (empty($items)) {
            return $emptyMessage;
        }

        $text = "<b>$title</b>\n";
        $text .= "═════════════════════════\n\n";

        foreach ($items as $index => $item) {
            $number = Str::faNumber($index + 1);
            $text .= "$number️⃣ $item\n";
        }

        return $text;
    }

    /**
     * Format action menu
     */
    public static function actionMenu(string $title, array $options): string
    {
        $text = "👇 <b>$title</b>\n";
        $text .= "═════════════════════════\n\n";

        foreach ($options as $emoji => $label) {
            $text .= "$emoji <b>$label</b>\n";
        }

        return $text;
    }

    /**
     * Format info box
     */
    public static function info(string $title, array $fields): string
    {
        $text = "ℹ️ <b>$title</b>\n";
        $text .= "═════════════════════════\n\n";

        foreach ($fields as $label => $value) {
            if (is_array($value)) {
                $text .= "<b>$label:</b>\n";
                foreach ($value as $item) {
                    $text .= "  • $item\n";
                }
                $text .= "\n";
            } else {
                $text .= "<b>$label:</b> <code>$value</code>\n";
            }
        }

        return $text;
    }

    /**
     * Format retry message with countdown
     */
    public static function retry(string $message, int $secondsLeft = 0): string
    {
        $text = "🔄 <b>دوباره تلاش کنید</b>\n";
        $text .= "─────────────────────\n";
        $text .= "$message\n";

        if ($secondsLeft > 0) {
            $text .= "\n⏱️ لطفاً " . Str::faNumber($secondsLeft) . " ثانیه صبر کنید...";
        }

        return $text;
    }

    /**
     * Format confirmation request
     */
    public static function confirm(string $message, string $details = ''): string
    {
        $text = "❓ <b>تایید</b>\n";
        $text .= "─────────────────────\n";
        $text .= "$message\n";

        if ($details !== '') {
            $text .= "\n📋 <i>$details</i>";
        }

        return $text;
    }

    /**
     * Format divider
     */
    public static function divider(): string
    {
        return "\n═════════════════════════\n";
    }

    /**
     * Format section header
     */
    public static function section(string $title, string $icon = '📌'): string
    {
        return "\n$icon <b>$title</b>\n" . str_repeat("─", 20) . "\n";
    }
}
