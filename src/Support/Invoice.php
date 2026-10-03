<?php

declare(strict_types=1);

namespace Pasargad\Support;

use Pasargad\Store\OrderRepository;
use Pasargad\Store\PackageRepository;

/**
 * فاکتور خرید — متن تلگرام + صفحهٔ HTML قابل چاپ.
 *
 * چرا HTML و نه PDF واقعی؟ ساخت PDF بدون کتابخانه (composer نداریم) یعنی
 * نوشتن دستی یک تولیدکنندهٔ PDF که هم با فارسی/RTL و هم با فونت مشکل دارد.
 * راه عملی: یک صفحهٔ HTML تمیز و چاپ‌پذیر که نماینده در مرورگر باز می‌کند و
 * با «Print → Save as PDF» آن را به PDF تبدیل می‌کند. نتیجه همان چیزی است که
 * مشتری نهایی انتظار دارد، با صفر وابستگی.
 *
 * دسترسی با **توکن** محافظت می‌شود، نه با شناسهٔ سفارش: لینک فاکتور در کانال
 * مشتری نماینده دست‌به‌دست می‌شود و نباید حدس‌زدنی باشد.
 */
final class Invoice
{
    /** حداکثر طول متن مجاز در یک ردیف جدول فاکتور */
    private const MAX_FIELD_LEN = 300;

    /**
     * اطمینان از وجود توکن فاکتور برای یک سفارش.
     *
     * نوشتن **شرطی** است (`WHERE invoice_token IS NULL`) تا دو درخواست
     * همزمان دو توکن متفاوت نسازند. اگر ساده بود، درخواست دوم جایگزین
     * درخواست اول می‌شد و لینکی که نماینده **قبلاً برای مشتری فرستاده بود**
     * ۴۰۴ می‌داد — یعنی فاکتورِ تحویل‌شده خراب می‌شد.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed> همان سفارش، با invoice_token پر
     */
    public static function ensureToken(OrderRepository $orders, array $order): array
    {
        $token = trim((string) ($order['invoice_token'] ?? ''));

        if ($token !== '') {
            return $order;
        }

        $orderId = (int) $order['id'];

        $orders->db()->run(
            'UPDATE orders
             SET invoice_token = :t, updated_at = :now
             WHERE id = :id AND (invoice_token IS NULL OR invoice_token = \'\')',
            ['t' => self::generateToken(), 'now' => time(), 'id' => $orderId]
        );

        $fresh = $orders->find($orderId);

        // اگر پروسهٔ دیگری زودتر نوشته باشد، توکن او معتبر است و مال ما نیست.
        $order['invoice_token'] = (string) ($fresh['invoice_token'] ?? $token);

        return $order;
    }

    /**
     * پیدا کردن سفارش با توکن فاکتور.
     *
     * @return array<string, mixed>|null
     */
    public static function findByToken(OrderRepository $orders, string $token): ?array
    {
        $token = trim($token);

        // توکن‌ها با hex ساخته می‌شوند؛ هر چیز دیگری از پیش رد می‌شود تا
        // کوئری بی‌جهت و قابل حدس نباشد.
        if ($token === '' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        return $orders->db()->first('SELECT * FROM orders WHERE invoice_token = ?', [$token]);
    }

    /**
     * لینک قابل چاپ فاکتور — یا رشتهٔ خالی اگر قابل ساخت نباشد.
     *
     * ⚠️ **چرا رشتهٔ خالی برمی‌گرداند و نه لینک نسبی؟**
     * دکمهٔ `url` تلگرام فقط آدرس مطلق (`http://` یا `tg://`) قبول می‌کند و
     * اگر آدرس نسبی باشد، `BotApi::buildMarkup()` کل کیبورد را `null` می‌کند
     * و همهٔ دکمه‌ها — حتی «جزئیات سفارش» و «بازگشت» — بی‌سروصدا حذف
     * می‌شوند. کاربر پیام «از دکمهٔ زیر استفاده کنید» را می‌بیند ولی هیچ
     * دکمه‌ای وجود ندارد. پس بهتر است صادقانه بگوییم لینک در دسترس نیست.
     *
     * @param array<string, mixed> $order
     */
    public static function url(array $order): string
    {
        $token = trim((string) ($order['invoice_token'] ?? ''));

        if ($token === '') {
            return '';
        }

        $base = rtrim(trim(Config::str('base_url', '')), '/');

        if ($base === '') {
            return '';
        }

        return $base . '/invoice.php?t=' . $token;
    }

    /**
     * آیا چاپ‌پذیری فاکتور امکان‌پذیر است؟ (لینک ساخته می‌شود؟)
     *
     * @param array<string, mixed> $order
     */
    public static function hasPrintableLink(array $order): bool
    {
        return self::url($order) !== '';
    }

    // ------------------------------------------------------------------
    // نرمال‌سازی داده‌های فاکتور
    // ------------------------------------------------------------------

    /**
     * سه عدد مالی فاکتور، سازگار با هم.
     *
     * مشکل: اگر `original_price_toman` با `price + discount` نخواند، فاکتور
     * خودتناقض می‌شود: «قیمت پایه ۳۵۰٬۰۰۰، تخفیف ۱۰۰٬۰۰۰، پرداختی ۳۰۰٬۰۰۰».
     * مشتری حساب می‌کند و می‌گوید ۲۵۰ هزار شد، نه ۳۰۰ هزار — و حق دارد.
     *
     * راه‌حل: **مبلغ پرداخت‌شده مرجع است** (چون همان واقعاً از حساب کم شده)،
     * و قیمت پایه از روی همان + تخفیف بازسازی می‌شود. پس جمع همیشه درست است.
     *
     * @param  array<string, mixed> $order
     * @return array{price:int, discount:int, original:int}
     */
    public static function amounts(array $order): array
    {
        $price    = max(0, (int) ($order['price_toman'] ?? 0));
        $discount = max(0, (int) ($order['discount_toman'] ?? 0));

        $original = (int) ($order['original_price_toman'] ?? 0);

        // اگر قیمت پایه نامعتبر یا ناسازگار است، خودمان می‌سازیمش.
        if ($original <= 0 || $original < $price + $discount) {
            $original = $price + $discount;
        }

        return ['price' => $price, 'discount' => $discount, 'original' => $original];
    }

    /**
     * دلیل تخفیف، اگر وجود داشته باشد.
     *
     * @param  array<string, mixed> $order
     * @return array{label:string, value:string}|null
     */
    public static function discountReason(array $order): ?array
    {
        $code = trim((string) ($order['coupon_code'] ?? ''));

        if ($code !== '') {
            return ['label' => 'کد تخفیف', 'value' => $code];
        }

        if (trim((string) ($order['referred_by'] ?? '')) !== '') {
            return ['label' => 'پاداش معرفی', 'value' => (string) $order['referred_by']];
        }

        return null;
    }

    /**
     * شمارهٔ پرداخت/ارجاع، اگر ثبت شده باشد.
     *
     * @param array<string, mixed> $order
     */
    public static function paymentReference(array $order): string
    {
        $ref = trim((string) ($order['payment_ref'] ?? ''));

        return $ref === '' ? '' : $ref;
    }

    /**
     * نام روش پرداخت، اگر ثبت شده باشد.
     *
     * @param array<string, mixed> $order
     */
    public static function paymentMethod(array $order): string
    {
        $method = trim((string) ($order['payment_method'] ?? ''));

        if ($method === '') {
            return '';
        }

        return match ($method) {
            'card2card'   => 'کارت‌به‌کارت دستی',
            'autocard'    => 'کارت‌به‌کارت خودکار',
            'nowpayments' => 'ارز دیجیتال',
            'wallet'      => 'کیف پول',
            default       => $method,
        };
    }

    // ------------------------------------------------------------------
    // متن
    // ------------------------------------------------------------------

    /**
     * متن فاکتور برای پیام تلگرام.
     *
     * @param array<string, mixed> $order
     */
    public static function telegramText(array $order, ?string $customerName = null): string
    {
        $amounts = self::amounts($order);

        $lines = [
            '🧾✨ <b>فاکتور خرید</b>',
            '',
            '🔢 شمارهٔ فاکتور: <code>' . Str::escape((string) $order['code']) . '</code>',
            '📅 تاریخ: ' . Str::date((int) ($order['created_at'] ?? 0)),
        ];

        if ($customerName !== null && trim($customerName) !== '') {
            $lines[] = '👤 مشتری: ' . Str::escape($customerName);
        }

        $lines[] = '';
        $lines[] = '📦✨ <b>شرح</b>';
        $lines[] = '🧱 ' . Str::escape((string) $order['package_title']);
        $lines[] = '📂 نوع: ' . Str::escape(PackageRepository::kindLabel((string) $order['kind']));

        $volumeGb = (float) ($order['volume_gb'] ?? 0) + (float) ($order['bonus_gb'] ?? 0);

        if ($volumeGb > 0) {
            $lines[] = '💾 حجم: <b>' . Str::faNumber($volumeGb, 1) . ' گیگابایت</b>';
        }

        $days = (int) ($order['duration_days'] ?? 0);

        if ($days > 0) {
            $lines[] = '⏳ اعتبار: <b>' . Str::faNumber($days) . ' روز</b>';
        }

        if ($amounts['discount'] > 0) {
            $lines[] = '';
            $lines[] = '💰 قیمت پایه: ' . Str::formatToman($amounts['original']);

            $reason = self::discountReason($order);

            if ($reason !== null) {
                $lines[] = ($reason['label'] === 'کد تخفیف' ? '🎟️ کد تخفیف' : '🎁')
                    . ' ' . $reason['label'] . ': <code>'
                    . Str::escape(Str::truncate($reason['value'], 32)) . '</code>';
            } else {
                // تخفیف بدون دلیل یعنی مشتری نمی‌تواند بپرسد «چرا؟» — بدتر
                // از ننوشتنش نیست ولی باید صریح باشد.
                $lines[] = '🎁 تخفیف اعمال‌شده';
            }

            $lines[] = '🎉 تخفیف: <b>−' . Str::formatToman($amounts['discount']) . '</b>';
        }

        $lines[] = '';
        $lines[] = '💵💰 <b>مبلغ قابل پرداخت: ' . Str::formatToman($amounts['price']) . '</b>';

        $method = self::paymentMethod($order);
        $ref    = self::paymentReference($order);

        if ($method !== '') {
            $lines[] = '💳 روش پرداخت: ' . Str::escape($method);
        }

        if ($ref !== '') {
            $lines[] = '🧾 کد پیگیری: <code>' . Str::escape(Str::truncate($ref, 40)) . '</code>';
        }

        $paidAt = $order['paid_at'] ?? null;

        if ($paidAt !== null) {
            $lines[] = '✅ پرداخت‌شده در ' . Str::date((int) $paidAt);
        } else {
            $lines[] = '⏳ وضعیت: ' . Str::escape((string) $order['status']);
        }

        if (self::hasPrintableLink($order)) {
            $lines[] = '';
            $lines[] = '🖨️ برای نسخهٔ قابل چاپ (PDF) از دکمهٔ زیر استفاده کنید.';
        }

        return implode("\n", $lines);
    }

    /**
     * آیا سفارش پرداخت شده است؟ (فقط سفارش پرداخت‌شده فاکتور معتبر دارد)
     *
     * @param array<string, mixed> $order
     */
    public static function isPayable(array $order): bool
    {
        return ($order['paid_at'] ?? null) !== null
            && in_array((string) $order['status'], ['paid', 'applied'], true);
    }

    // ------------------------------------------------------------------
    // HTML
    // ------------------------------------------------------------------

    /**
     * صفحهٔ HTML فاکتور (چاپ‌پذیر).
     *
     * @param array<string, mixed>      $order
     * @param OrderRepository|null $orders برای خواندن نام خریدار؛ اختیاری است
     */
    public static function html(array $order, ?OrderRepository $orders = null): string
    {
        $amounts   = self::amounts($order);
        $title     = Str::truncate((string) $order['package_title'], self::MAX_FIELD_LEN);
        $kind      = PackageRepository::kindLabel((string) $order['kind']);
        $volumeGb  = (float) ($order['volume_gb'] ?? 0) + (float) ($order['bonus_gb'] ?? 0);
        $days      = (int) ($order['duration_days'] ?? 0);
        $code      = Str::escape((string) $order['code']);
        $storeName = self::esc(Config::str('store.name', 'ربات نمایندگان پنل'));
        $contact   = trim(Config::str('notifications.support_link', ''));
        $reason    = self::discountReason($order);
        $method    = self::paymentMethod($order);
        $ref       = self::paymentReference($order);

        $rows = '<tr><th>شرح</th><td>' . self::esc($title) . '</td></tr>'
            . '<tr><th>نوع بسته</th><td>' . self::esc($kind) . '</td></tr>';

        if ($volumeGb > 0) {
            $rows .= '<tr><th>حجم</th><td>' . Str::faNumber($volumeGb, 1) . ' گیگابایت</td></tr>';
        }

        if ($days > 0) {
            $rows .= '<tr><th>مدت اعتبار</th><td>' . Str::faNumber($days) . ' روز</td></tr>';
        }

        // نام خریدار: در فاکتوری که نماینده به مشتری‌اش می‌دهد، هویت خریدار
        // (و فروشنده) بخشی از خودِ سند است.
        $customer = self::customerName($order, $orders);

        if ($customer !== '') {
            $rows .= '<tr><th>خریدار</th><td>' . self::esc($customer) . '</td></tr>';
        }

        $rows .= '<tr><th>تاریخ سفارش</th><td>' . Str::date((int) ($order['created_at'] ?? 0)) . '</td></tr>';

        if ($amounts['discount'] > 0) {
            $rows .= '<tr><th>قیمت پایه</th><td>' . Str::formatToman($amounts['original']) . '</td></tr>';

            if ($reason !== null) {
                $rows .= '<tr><th>' . self::esc($reason['label']) . '</th>'
                    . '<td dir="ltr">' . self::esc(Str::truncate($reason['value'], 32)) . '</td></tr>';
            } else {
                $rows .= '<tr><th>تخفیف</th><td>اعمال‌شده</td></tr>';
            }

            $rows .= '<tr class="minus"><th>تخفیف</th><td>−' . Str::formatToman($amounts['discount']) . '</td></tr>';
        }

        if ($method !== '') {
            $rows .= '<tr><th>روش پرداخت</th><td>' . self::esc($method) . '</td></tr>';
        }

        if ($ref !== '') {
            $rows .= '<tr><th>کد پیگیری</th><td dir="ltr">' . self::esc(Str::truncate($ref, 40)) . '</td></tr>';
        }

        $rows .= '<tr class="total"><th>مبلغ قابل پرداخت</th><td>'
            . Str::formatToman($amounts['price']) . '</td></tr>';

        $paidAt = $order['paid_at'] ?? null;
        $status = $paidAt !== null
            ? '✅ پرداخت‌شده در ' . Str::date((int) $paidAt)
            : 'در انتظار پرداخت';

        $contactHtml = $contact !== ''
            ? '<p class="contact">پشتیبانی: <a href="' . self::esc($contact) . '" rel="noopener noreferrer">'
                . self::esc($contact) . '</a></p>'
            : '';

        $printButton = '<button onclick="window.print()">🖨️ چاپ / ذخیره PDF</button>';

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>فاکتور {$code}</title>
<style>
  /* فونت وزیرمتن — خودمیزبان (assets/fonts) تا فاکتور آفلاین هم درست چاپ شود */
  @font-face {
    font-family: 'Vazirmatn';
    src: url('assets/fonts/Vazirmatn-Regular.woff2') format('woff2');
    font-weight: 400; font-style: normal; font-display: swap;
  }
  @font-face {
    font-family: 'Vazirmatn';
    src: url('assets/fonts/Vazirmatn-Bold.woff2') format('woff2');
    font-weight: 700; font-style: normal; font-display: swap;
  }
  :root { color-scheme: light; }
  body { font-family: 'Vazirmatn', Tahoma, sans-serif; background: #f4f6f8; margin: 0; padding: 24px; color: #1b2733; }
  .sheet { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 14px;
           padding: 32px; box-shadow: 0 6px 24px rgba(0,0,0,.07); }
  header { display: flex; justify-content: space-between; align-items: flex-start;
           border-bottom: 2px solid #e6eaee; padding-bottom: 16px; gap: 16px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .muted { color: #7a8894; font-size: 13px; }
  .badge { font-size: 12px; padding: 4px 10px; border-radius: 20px; background: #eaf7ef; color: #197a3f; white-space: nowrap; }
  table { width: 100%; border-collapse: collapse; margin-top: 20px; }
  th, td { padding: 10px 12px; border-bottom: 1px solid #eef1f4; text-align: right; font-size: 14px; }
  th { color: #5a6874; font-weight: 600; width: 38%; }
  tr.total th, tr.total td { font-size: 17px; font-weight: 700; color: #0f2d1c; border-top: 2px solid #1f9d55; }
  tr.minus td { color: #b3261e; }
  .contact { margin-top: 20px; font-size: 13px; color: #5a6874; }
  button { margin-top: 22px; background: #1f9d55; color: #fff; border: 0; border-radius: 10px;
           padding: 12px 20px; font-size: 15px; font-family: inherit; cursor: pointer; width: 100%; }
  footer { margin-top: 18px; font-size: 11px; color: #9aa6b1; text-align: center; }
  @media print {
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; border-radius: 0; max-width: none; }
    button { display: none; }
  }
</style>
</head>
<body>
<div class="sheet">
  <header>
    <div>
      <h1>فاکتور خرید</h1>
      <div class="muted">{$storeName}</div>
      <div class="muted" dir="ltr">{$code}</div>
    </div>
    <div class="badge">{$status}</div>
  </header>

  <table>{$rows}</table>

  {$contactHtml}

  {$printButton}

  <footer>این فاکتور به‌صورت خودکار توسط ربات تولید شده است.</footer>
</div>
</body>
</html>
HTML;
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * نام خریدار از روی `user_id` سفارش.
     *
     * @param array<string, mixed> $order
     */
    private static function customerName(array $order, ?OrderRepository $orders): string
    {
        if ($orders === null) {
            return '';
        }

        $userId = (int) ($order['user_id'] ?? 0);

        if ($userId <= 0) {
            return '';
        }

        try {
            $row = $orders->db()->first(
                'SELECT first_name, username FROM users WHERE id = ?',
                [$userId]
            );
        } catch (\Throwable $e) {
            return '';
        }

        if ($row === null) {
            return '';
        }

        $name = trim((string) ($row['first_name'] ?? ''));

        if ($name === '') {
            $name = trim((string) ($row['username'] ?? ''));
        }

        return Str::truncate($name, 64);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}