# 🐞 گزارش باگ‌های پروژه Pasargad Representatives Bot

> **تاریخ بررسی:** ۱۹ اکتبر ۲۰۲۶  
> **کامیت مبنا:** `b1d1324` — «Detailed internal-error reporting on the bot and Mini App»  
> **روش:** بازبینی مستقیم کد از نقطهٔ صفر (entrypointها، Kernel، Mini App API، لایهٔ Store، پرداخت، پنل و worker)  
> **وضعیت تست‌ها:** ۱۹۷۸ تست موفق، ۰ تست ناموفق (تست‌های live پنل به‌دلیل نبود اینترنت اجرا نشدند)

---

## خلاصهٔ اجرایی

در این بازبینی **۲۳ باگ** تأیید شد که ۱ مورد بحرانی، ۸ مورد بالا، ۱۲ مورد متوسط و ۲ مورد کم‌اهمیت هستند.

| شدت | تعداد | شناسه‌ها |
|---|---:|---|
| 🔴 بحرانی | ۱ | B-01 |
| 🟠 بالا | ۸ | B-02 … B-09 |
| 🟡 متوسط | ۱۲ | B-10 … B-21 |
| 🔵 کم | ۲ | B-22، B-23 |

**مهم‌ترین یافته:** «رفع باگ Race Condition حذف خودکار کانفیگ تست» که در `CHANGES_SUMMARY.md` و `BUGFIX_ROADMAP.md` انجام‌شده اعلام شده، در واقع خودش یک باگ بحرانی جدید ایجاد کرده است: فراخوانی `Db` بدون `use` صحیح و استفاده از متد ناموجود `fetchOne()` باعث **Fatal Error** می‌شود و چون `catch (\Exception)` است، `Error` گرفته نمی‌شود. جزئیات در B-01.

> نکته: مواردی که در مستندات قبلی پروژه (`BUGFIX_ROADMAP.md`، `COMPREHENSIVE_BUGFIX_PLAN.md`، `CHANGES_SUMMARY.md`) به‌عنوان رفع‌شده ثبت شده‌اند، دوباره گزارش نشده‌اند؛ مگر اینکه خودِ اصلاح، باگ جدیدی ایجاد کرده باشد.

---

## 🔴 بحرانی

### B-01 — حذف خودکار کانفیگ تست با Fatal Error از کار افتاده است

- **شدت:** بحرانی
- **محل:** `src/Bot/Kernel.php:1203` (و تا خط ۱۲۲۸)
- **مربوط:** `CHANGES_SUMMARY.md:13` — این مورد قبلاً «رفع‌شده» اعلام شده است.

**توضیح:**  
داخل namespace `Pasargad\Bot` و بدون import کردن `Pasargad\Support\Db`، این کد اجرا می‌شود:

```php
try {
    $db = Db::instance();
    $db->transaction(function () use ($db, $configId, $seconds) {
        $config = $db->fetchOne(
            'SELECT id, user_id, is_auto_delete FROM test_configs WHERE id = ?',
            [$configId]
        );
        ...
    });
} catch (\Exception $e) {
    ...
}
```

سه خطا هم‌زمان وجود دارد:

1. `Db::instance()` به کلاس `Pasargad\Bot\Db` resolve می‌شود که وجود ندارد → `Error: Class "Pasargad\Bot\Db" not found`.
2. حتی با import صحیح، متد `fetchOne()` در `Pasargad\Support\Db` وجود ندارد. متد صحیح `first()` است.
3. `catch (\Exception)` نمی‌تواند `\Error` را بگیرد؛ بنابراین صفحه/پردازش با Fatal Error متوقف می‌شود و پیام خطای فارسی هم به کاربر نمایش داده نمی‌شود.

**اثر:**  
تنظیم «حذف خودکار» برای کانفیگ تست عملاً غیرممکن است. هر بار کاربر این مسیر را طی کند، درخواست با خطای مرگبار متوقف می‌شود و ممکن است session کاربر هم پاک نشود.

**اصلاح پیشنهادی:**

```php
use Pasargad\Support\Db;

// ...

try {
    $db = Db::instance();
    $db->transaction(function () use ($db, $configId, $seconds): void {
        $config = $db->first(
            'SELECT id, user_id, is_auto_delete FROM test_configs WHERE id = ?',
            [$configId]
        );

        if ($config === null) {
            throw new \RuntimeException('کانفیگ تست یافت نشد.');
        }

        if ((int) ($config['is_auto_delete'] ?? 0) === 1) {
            throw new \RuntimeException('این کانفیگ قبلاً برای حذف خودکار تنظیم شده است.');
        }

        $this->testRepo->update($configId, [
            'is_auto_delete' => 1,
            'auto_delete_at' => time() + $seconds,
        ]);
    });
} catch (\Throwable $e) {
    $this->bot->sendMessage($chatId, '⚠️ خطا: ' . $e->getMessage());
    return true;
}
```

در صورت امکان، به‌جای SQL خام از `TestConfigRepository` برای خواندن هم استفاده شود و مالکیت کانفیگ نسبت به کاربر بررسی گردد.

---

## 🟠 باگ‌های با اهمیت بالا

### B-02 — محدودکنندهٔ ضداسپم هرگز فعال نمی‌شود

- **شدت:** بالا
- **محل:** `src/Bot/FloodGuard.php:249-271`، فراخوانی در `src/Bot/Kernel.php:334`

**توضیح:**  
`isSpamRateLimited()` تعداد ردیف‌ها را می‌شمارد:

```sql
SELECT COUNT(*) AS cnt
FROM flood_guard
WHERE key = ? AND updated_at >= ?
```

اما جدول `flood_guard` یک جدول key-value است و `key` کلید اصلی است (`src/Support/Migrator.php:389-392`). در ادامه هم درج با `ON CONFLICT(key) DO UPDATE` انجام می‌شود؛ یعنی همیشه **حداکثر یک ردیف** برای هر کاربر وجود دارد. بنابراین `$count` هیچ‌وقت از ۱ بالاتر نمی‌رود و شرط `$count >= 15` هرگز برقرار نمی‌شود.

**اثر:**  
`Kernel.php:334` با `isSpamRateLimited($userId, 15, 60)` انتظار دارد پس از ۱۵ درخواست در ۶۰ ثانیه کاربر را محدود کند، اما این محافظت کاملاً بی‌اثر است. اسپم/فلود کاربران می‌تواند منابع ربات و پنل را مصرف کند.

**اصلاح پیشنهادی:**  
یک شمارندهٔ واقعی ذخیره شود، مثلاً دو ستون `hits` و `window_start` به `flood_guard` اضافه شود (یا جدول `flood_events`):

```sql
INSERT INTO flood_guard (key, hits, window_start)
VALUES (:k, 1, :now)
ON CONFLICT(key) DO UPDATE SET
    hits = CASE
        WHEN flood_guard.window_start <= :cutoff THEN 1
        ELSE flood_guard.hits + 1
    END,
    window_start = CASE
        WHEN flood_guard.window_start <= :cutoff THEN :now
        ELSE flood_guard.window_start
    END
RETURNING hits;
```

سپس `hits >= $maxCount` بررسی شود. (در SQLite نسخهٔ ۳.۳۵+ `RETURNING` پشتیبانی می‌شود؛ در غیر این صورت داخل تراکنش `SELECT` و `UPDATE` انجام شود.)

---

### B-03 — شارژ حجم، پنل نامحدود را به پنل محدود تبدیل می‌کند

- **شدت:** بالا
- **محل:** `src/Store/Provisioner.php:472-499`

**توضیح:**  
در PasarGuard مقدار `data_limit = 0` به معنی **نامحدود** است. اما کد فعلی:

```php
$currentLimit = ... ? (int) $admin['data_limit'] : 0;
$baseLimit = max($currentLimit, $usedTraffic);
$newLimit  = $baseLimit + $bytes;
```

برای پنل نامحدود، `currentLimit` صفر است و در نتیجه `newLimit = used_traffic + حجم خریداری‌شده`. سپس همین مقدار به‌عنوان `data_limit` به پنل ارسال می‌شود.

**اثر:**  
کاربری که پنلش نامحدود بوده، با خرید یک بستهٔ شارژ، پنلش به یک سقف محدود (مصرف‌شده + حجم جدید) تبدیل می‌شود و عملاً بخشی از حجم قبلی را از دست می‌دهد. این تغییر برگشت‌ناپذیر است.

**اصلاح پیشنهادی:**

```php
$isUnlimited = $currentLimit === 0;
$newLimit = $isUnlimited
    ? 0
    : max($currentLimit, $usedTraffic) + $bytes;
```

در حالت نامحدود، `modifyAdmin` برای `data_limit` ارسال نشود (یا صفر ارسال شود) و در `target_limit` هم مقدار صفر با یک فلگ جدا (مثلاً `target_limit_set`) از «ذخیره‌نشده» تفکیک شود؛ چون `reserveTargetLimit(0)` با `target_limit = 0` در تلاش مجدد با «مقدار ذخیره‌نشده» قابل تشخیص نیست.

---

### B-04 — تمدید زودهنگام، روزهای باقی‌مانده را می‌سوزاند

- **شدت:** بالا
- **محل:** `src/Store/Provisioner.php:442-443` و `src/Store/PanelRepository.php:499-505`

**توضیح:**  
در شارژ پنل:

```php
$expireAt = $days > 0 ? time() + $days * 86400 : null;
```

و در `addGranted()`:

```sql
access_expire_at = CASE
    WHEN access_expire_at IS NULL OR access_expire_at < :e THEN :e
    ELSE access_expire_at
END
```

یعنی تاریخ انقضای جدید با `MAX(تاریخ فعلی، now + days)` گرفته می‌شود، نه `تاریخ فعلی + days`. اگر کاربری ۲۰ روز اعتبار باقی‌مانده داشته باشد و یک بستهٔ ۳۰ روزه بخرد، اعتبارش ۳۰ روز از **الان** می‌شود (تقریباً ۱۰ روز از دست می‌رود). دو خرید ۳۰ روزه در یک روز هم حدود ۳۰ روز می‌شود، نه ۶۰ روز.

**اثر:**  
از دست رفتن اعتبار پرداخت‌شدهٔ کاربر و شکایت/نارضایتی. کامنت‌های کد ادعای افزایشی بودن را دارند اما پیاده‌سازی این‌طور نیست.

**اصلاح پیشنهادی:**

```php
$currentExpire = (int) ($panel['access_expire_at'] ?? 0);
$baseExpire    = max(time(), $currentExpire);
$expireAt      = $days > 0 ? $baseExpire + $days * 86400 : null;
```

همچنین برای idempotency، مقدار `target_expire_at` در سفارش ذخیره شود تا تلاش مجدد، دوباره روز اضافه نکند. `addGranted()` می‌تواند مستقیماً همان مقدار محاسبه‌شده را بنویسد (یا با `MAX` بماند، چون مقدار قبلاً افزایشی است).

---

### B-05 — لینک اشتراک پنل‌های با دامنهٔ اختصاصی اشتباه ساخته می‌شود

- **شدت:** بالا
- **محل:** `src/Store/PanelRepository.php:791-810` (فراخوانی‌ها: خطوط ۴۱۱ و ۴۷۱)

**توضیح:**  
`extractSubUrl()` وقتی پاسخ پنل فقط توکن می‌دهد، آدرس را با `panel.base_url` **global** می‌سازد:

```php
return rtrim(\Pasargad\Support\Config::str('panel.base_url', ''), '/')
    . str_replace('{token}', rawurlencode($value), $pattern);
```

اما هر ردیف `panels` می‌تواند `base_url` اختصاصی داشته باشد و `PanelAccess::baseUrlOf()` هم دقیقاً همین را در نظر می‌گیرد. تابع `extractSubUrl()` به‌صورت static فقط `$adminDetails` را می‌گیرد و به ردیف پنل دسترسی ندارد.

**اثر:**  
برای پنل‌هایی که روی دامنهٔ اختصاصی هستند، لینک سابسکریپشن ساخته‌شده به دامنهٔ پیش‌فرض/اشتباه اشاره می‌کند و برای کاربر باز نمی‌شود.

**اصلاح پیشنهادی:**

```php
public static function extractSubUrl(array $details, ?string $baseUrl = null): ?string
{
    // ...
    $baseUrl = $baseUrl !== null && trim($baseUrl) !== ''
        ? rtrim(trim($baseUrl), '/')
        : rtrim(\Pasargad\Support\Config::str('panel.base_url', ''), '/');

    return $baseUrl . str_replace('{token}', rawurlencode($value), $pattern);
}
```

و در `upsertFromPanel()` / `syncFromPanel()` آدرس پایهٔ همان ردیف (یا `PanelAccess::baseUrlOf($panelRow)`) پاس داده شود. بهتر است در پاسخ API اگر `sub_url` کامل وجود دارد، همان اولویت داشته باشد (که الان دارد).

---

### B-06 — هیچ مسیری برای لغو/منقضی‌کردن سفارش پرداخت‌نشده وجود ندارد

- **شدت:** بالا
- **محل:** `src/Store/OrderRepository.php:21-40` و `cron/worker.php` (بدون جست‌وجوی سفارش‌های منقضی)

**توضیح:**  
`STATUS_CANCELLED = 'cancelled'` تعریف شده، در `TERMINAL_STATUSES` هست و در View/متن‌ها برچسب دارد، اما در کل پروژه **هیچ‌جا نوشته نمی‌شود**. جست‌وجوی `STATUS_CANCELLED` فقط موارد خواندنی/برچسب را نشان می‌دهد. همچنین worker هیچ کوئری‌ای برای سفارش‌های `created` یا `awaiting_payment` قدیمی و هیچ auto-expire ندارد.

**اثر:**  
سفارش‌های رها‌شده تا ابد در وضعیت انتظار می‌مانند. این موضوع مستقیماً با B-07، B-08 و B-10 ترکیب می‌شود و باعث می‌شود امتیاز وفاداری، کد تخفیف و تخفیف معرفی کاربر مصرف/سوخته شود بدون پرداخت. گزارش‌ها و آمار سفارش هم آلوده می‌شوند.

**اصلاح پیشنهادی:**  
یک متد در `OrderRepository` برای لغو اتمیک اضافه شود:

```php
public function cancel(int $orderId, string $reason): bool
{
    return $this->db->run(
        "UPDATE orders
         SET status = 'cancelled', terminal_reason = :r, updated_at = :t
         WHERE id = :id AND status IN ('created', 'awaiting_payment')",
        ['r' => $reason, 't' => time(), 'id' => $orderId]
    )->rowCount() > 0;
}
```

سپس در `cron/worker.php` سفارش‌های قدیمی‌تر از مثلاً ۳۰ دقیقه لغو شوند و در همان مسیر، امتیاز/کد تخفیف آزاد شود. یک دکمهٔ «لغو سفارش» هم برای کاربر/ادمین اضافه شود.

---

### B-07 — امتیاز وفاداری هنگام ساخت سفارش کسر می‌شود و در صورت عدم پرداخت برنمی‌گردد

- **شدت:** بالا
- **محل:** `src/Bot/Kernel.php:3387-3389` و `src/WebApp/Api.php:1562-1564`

**توضیح:**  
بعد از ساخت سفارش (حتی قبل از پرداخت):

```php
if ($loyaltyExtra > 0) {
    $this->users->deductLoyaltyPoints((int) $user['id'], $redeemPoints);
}
```

هیچ مسیری برای بازگرداندن این امتیازها در صورت لغو، انقضا یا عدم پرداخت وجود ندارد (و مسیر لغو هم اصلاً وجود ندارد — B-06).

**اثر:**  
کاربری که سفارش را نیمه‌کاره رها کند، امتیازش را از دست می‌دهد؛ در حالی که تخفیف وفاداری هم عملاً استفاده نشده است. این یک از دست رفتن دارایی کاربر است.

**اصلاح پیشنهادی:**  
امتیاز در لحظهٔ **پرداخت موفق** کسر شود (داخل همان تراکنش `markPaid` یا بلافاصله بعد از آن با بررسی اتمیک)، یا مقدار مصرف‌شده در سفارش ذخیره شود و در مسیر لغو/انقضا برگردانده شود. برای جلوگیری از استفادهٔ هم‌زمان، کسر باید conditional باشد (B-14).

---

### B-08 — کد تخفیف هنگام ساخت سفارش مصرف می‌شود و آزاد نمی‌شود

- **شدت:** بالا
- **محل:** `src/Bot/Kernel.php:3363-3375`، `src/WebApp/Api.php:1546-1555`، `src/Store/CouponRepository.php` (بدون متد release)

**توضیح:**  
`consumeCoupon()` بلافاصله بعد از ساخت سفارش صدا زده می‌شود، نه بعد از پرداخت. جدول `coupon_uses` هیچ متد حذف/آزادسازی ندارد و `used_count` هم در صورت لغو کم نمی‌شود.

**اثر:**  
اگر کاربر پرداخت را انجام ندهد یا سفارش لغو/منقضی شود، کد تخفیف سوخته است؛ ظرفیت `max_uses` کم شده و کاربر دیگری هم نمی‌تواند از آن استفاده کند. برای کدهای یک‌بارمصرف، کاربر عملاً کدش را از دست می‌دهد.

**اصلاح پیشنهادی:**  
مصرف کد در لحظهٔ پرداخت موفق انجام شود، یا یک جفت `reserve`/`release` پیاده شود. حداقل کار: در مسیر لغو/انقضا (B-06) ردیف `coupon_uses` مربوط به سفارش حذف و `used_count` کاهش یابد. برای جلوگیری از استفادهٔ هم‌زمان، رزرو باید در همان تراکنش ساخت سفارش باشد.

---

### B-09 — سقف `per_user_limit` کد تخفیف در `consume()` بررسی نمی‌شود و نتیجهٔ مصرف نادیده گرفته می‌شود

- **شدت:** بالا
- **محل:** `src/Store/CouponRepository.php:154-194`، `src/Bot/Kernel.php:3364`، `src/WebApp/Api.php:1547`

**توضیح:**  
`quote()` در `DiscountService` سقف هر کاربر را بررسی می‌کند، اما `consume()` فقط `max_uses` را در `UPDATE` دارد:

```sql
UPDATE coupons
SET used_count = used_count + 1
WHERE id = :id
  AND is_active = 1
  AND (max_uses = 0 OR used_count < max_uses)
```

`per_user_limit` در این کوئری نیست. بدتر اینکه خروجی `consumeCoupon()` در هر دو مسیر ساخت سفارش نادیده گرفته می‌شود:

```php
$this->discounts()->consumeCoupon(...); // return value ignored
```

بنابراین حتی اگر `max_uses` پر شده باشد، سفارش با تخفیف ثبت می‌شود و فقط مصرف کد ناموفق می‌ماند.

**اثر:**  
- کاربر می‌تواند بیش از `per_user_limit` از یک کد استفاده کند (با درخواست‌های هم‌زمان یا ترتیبی، چون quote قبلاً انجام شده).
- `max_uses` می‌تواند دور زده شود: دو درخواست هم‌زمان هر دو quote می‌گیرند، یکی `UPDATE` موفق می‌شود، دیگری شکست می‌خورد ولی سفارش با تخفیف ثبت می‌شود.
- تعداد `coupon_uses` ممکن است از `used_count` بیشتر شود.

**اصلاح پیشنهادی:**  
- در `consume()` داخل همان تراکنش، تعداد استفادهٔ کاربر را هم بررسی کنید:

```sql
AND (:per_user_limit = 0 OR (
    SELECT COUNT(*) FROM coupon_uses
    WHERE coupon_id = :id AND user_id = :uid
) < :per_user_limit)
```

- خروجی `consumeCoupon()` را بررسی کنید و در صورت `false`، تراکنش سفارش را rollback کنید (کل ساخت سفارش داخل `orders->transaction` است، پس `throw` کافی است).
- `clearCouponCode` فقط بعد از موفقیت مصرف انجام شود.

---

## 🟡 باگ‌های متوسط

### B-10 — تخفیف «اولین خرید معرفی» با سفارش پرداخت‌نشده می‌سوزد

- **شدت:** متوسط
- **محل:** `src/Store/DiscountService.php:184-193`

**توضیح:**  
شرط استفاده‌شدن تخفیف معرفی، هر سفارشی با `referred_by IS NOT NULL` و وضعیت غیر از `cancelled/rejected/refunded` است. سفارش‌های `created` و `awaiting_payment` هم شامل می‌شوند.

**اثر:**  
کاربر اولین سفارش را می‌سازد ولی پرداخت نمی‌کند؛ تخفیف معرفی «استفاده‌شده» حساب می‌شود و برای خرید واقعی بعدی دیگر اعمال نمی‌شود.

**اصلاح پیشنهادی:**  
فقط سفارش‌های پرداخت‌شده/اعمال‌شده را معیار بگیرید:

```sql
AND status IN ('paid', 'applying', 'applied')
```

یا یک فیلد `referral_discount_used_at` فقط در لحظهٔ پرداخت موفق ست شود.

---

### B-11 — تخفیف معرفی روی سفارش‌های کد تخفیف‌دار هم «مصرف‌شده» علامت می‌خورد

- **شدت:** متوسط
- **محل:** `src/Bot/Kernel.php:3416-3422`، `src/WebApp/Api.php:1395-1402` و `1539`

**توضیح:**  
در `resolveDiscount` وقتی کد تخفیف برنده می‌شود، تابع همچنان کد معرف را از `findByReferee()` برمی‌گرداند:

```php
return [
    'discount' => ...,
    'code'     => $code,
    'referral' => $bind !== null ? (string) $bind['code'] : '',
];
```

سپس در ساخت سفارش، `referred_by` همان مقدار را می‌گیرد؛ در حالی که تخفیف معرفی **اعمال نشده** است (چون کد تخفیف برنده شده).

**اثر:**  
اولین خرید با کد تخفیف، تخفیف معرفی را برای همیشه غیرفعال می‌کند. همچنین گزارش‌ها نشان می‌دهند سفارش از طریق معرف بوده، در حالی که تخفیف معرفی نداشته است.

**اصلاح پیشنهادی:**  
وقتی کد تخفیف برنده است، `referral` را خالی برگردانید، یا یک فیلد جدا مثل `referral_applied` اضافه کنید و `referred_by` را فقط وقتی پر کنید که تخفیف معرفی واقعاً اعمال شده است.

---

### B-12 — اتصال کد معرف بعد از خریدهای قبلی مجاز است

- **شدت:** متوسط
- **محل:** `src/Store/ReferralRepository.php:76-100` و `src/WebApp/Api.php:2232-2259`

**توضیح:**  
`bind()` فقط بررسی می‌کند که referee قبلاً به کسی متصل نشده باشد. هیچ بررسی‌ای برای سابقهٔ خرید referee وجود ندارد.

**اثر:**  
کاربر می‌تواند بعد از چند خرید، کد معرف یک نفر را وارد کند و برای خرید بعدی تخفیف «اولین خرید» بگیرد؛ معرف هم پاداش می‌گیرد. این با منطق «دعوت دوستان قبل از اولین خرید» در تضاد است.

**اصلاح پیشنهادی:**  
در `bind()` بررسی شود که referee هیچ سفارش پرداخت‌شده‌ای ندارد (مثلاً `orders` با `status IN ('paid','applying','applied')`). در صورت تمایل، پنجرهٔ زمانی هم محدود شود (مثلاً تا ۲۴ ساعت بعد از عضویت).

---

### B-13 — کاهش دستی/منفی کیف پول غیراتمیک است و می‌تواند موجودی را منفی کند

- **شدت:** متوسط
- **محل:** `src/Store/UserRepository.php:328-349`

**توضیح:**  
`adjustWallet()` موجودی را می‌خواند، در PHP جمع می‌زند و سپس یک `UPDATE` نسبی می‌زند:

```php
$current = $this->walletBalance($userId);
$balance = $current + $amount;
if ($balance < 0) {
    $amount = -$current;
    $balance = 0;
}

$this->db->run(
    'UPDATE users SET wallet_balance = wallet_balance + :a ...',
    ['a' => $amount, ...]
);
```

اگر دو کاهش منفی هم‌زمان اجرا شوند (مثلاً دو refund/admin adjustment)، هر دو موجودی قدیمی را می‌خوانند و هر دو همان مقدار منفی را اعمال می‌کنند؛ نتیجه می‌تواند زیر صفر برود.

**اثر:**  
موجودی منفی، اختلاف در حساب‌ها و امکان سوءاستفاده در سناریوهای هم‌زمان.

**اصلاح پیشنهادی:**  
به‌جای محاسبه در PHP، شرط را در `UPDATE` بگذارید:

```sql
UPDATE users
SET wallet_balance = wallet_balance + :a, updated_at = :t
WHERE id = :id AND wallet_balance + :a >= 0
```

و `rowCount()` را بررسی کنید. برای SQLite، تراکنش را با `BEGIN IMMEDIATE` شروع کنید تا نوشتن‌های هم‌زمان سریال شوند.

---

### B-14 — کسر امتیاز وفاداری conditional نیست؛ دو سفارش هم‌زمان هر دو تخفیف می‌گیرند

- **شدت:** متوسط
- **محل:** `src/Store/UserRepository.php:121-127`، `src/Bot/Kernel.php:3387`، `src/WebApp/Api.php:1562`

**توضیح:**  
`deductLoyaltyPoints()` این‌طور است:

```sql
UPDATE users SET loyalty_points = MAX(0, loyalty_points - ?) WHERE id = ?
```

اگر کاربر ۱۰۰ امتیاز داشته باشد و دو سفارش هم‌زمان ساخته شود، هر دو در `resolveDiscount` شرط `points >= 100` را می‌بینند و هر دو ۵٪ تخفیف می‌گیرند. سپس اولی ۱۰۰ کم می‌کند و دومی به‌خاطر `MAX(0, ...)` فقط صفر می‌شود؛ یعنی تخفیف دوم رایگان داده شده است.

**اثر:**  
از دست رفتن درآمد و تخفیف تکراری.

**اصلاح پیشنهادی:**  
کسر را شرطی کنید و نتیجه را بررسی کنید:

```sql
UPDATE users
SET loyalty_points = loyalty_points - :p, updated_at = :t
WHERE id = :id AND loyalty_points >= :p
```

اگر `rowCount() !== 1` بود، سفارش/تخفیف باید لغو یا بدون تخفیف بازمحاسبه شود. این کار باید داخل همان تراکنش ساخت سفارش انجام شود.

---

### B-15 — اعتبارسنجی مبلغ IPN با نبود فیلدهای کلیدی دور زده می‌شود

- **شدت:** متوسط
- **محل:** `src/Payment/PaymentService.php:626-688`

**توضیح:**  
دو مسیر عبور بدون بررسی مبلغ وجود دارد:

1. اگر `price_amount` وجود نداشته باشد یا عددی نباشد، تابع `true` برمی‌گرداند و فقط به امضای IPN تکیه می‌کند:

```php
if ($invoiceAmount === null || !is_numeric($invoiceAmount)) {
    Logger::warning('IPN has no price_amount field, relying on signature only', ...);
    return true;
}
```

2. اگر `paymentRow['amount_usd']` صفر یا خالی باشد، `$rate = $paid` قرار می‌گیرد:

```php
$rate = (float) ($paymentRow['amount_usd'] ?? 0) > 0.0
    ? (float) $paymentRow['amount_usd']
    : $paid;

$paidToman = $rate > 0.0
    ? (int) round($expectedToman * ($paid / $rate))
    : 0;
```

در این حالت `$paid / $rate = 1` و هر مبلغی که در `price_amount` باشد، با انتظار برابر می‌شود.

**اثر:**  
اگر مهاجم بتواند یک IPN معتبر امضاشده با مبلغ کم/دلخواه تولید کند (یا یک IPN ناقص از سمت درگاه برسد)، سفارش بدون پرداخت کامل «پرداخت‌شده» علامت می‌خورد. حتی بدون مهاجم، IPN ناقص می‌تواند باعث تطبیق اشتباه شود.

**اصلاح پیشنهادی:**  
- نبود `price_amount` یا `price_currency` را رد کنید (`return false`) و پرداخت را برای بررسی دستی علامت بزنید.
- برای ارزهای غیرتومانی، وجود `amount_usd > 0` را الزامی کنید؛ اگر نبود `false`.
- هرگز `rate = paid` قرار ندهید.
- در صورت امکان، مبلغ را از `pay_amount` با نرخ ذخیره‌شده محاسبه و با تلورانس محدود بررسی کنید.

---

### B-16 — دورهٔ نامعتبر در Mini App بی‌صدا پذیرفته می‌شود و با ربات ناسازگار است

- **شدت:** متوسط
- **محل:** `src/WebApp/Api.php:1305-1315` و `1457-1477`؛ مقایسه با `src/Bot/Kernel.php:3168-3177`

**توضیح:**  
در ربات، اگر `d` با هیچ دورهٔ مجازی مطابقت نداشته باشد، خطا داده می‌شود:

```php
if (!$matched) { ... return; }
```

اما در Mini App فقط یک حلقهٔ `foreach` وجود دارد و در صورت عدم تطابق، هیچ خطایی داده نمی‌شود؛ `$package` با مقدار پیش‌فرض خودش (قیمت/مدت پایه) ادامه می‌دهد.

**اثر:**  
کاربر دورهٔ دلخواه (مثلاً ۹۰ روز) را انتخاب می‌کند، اما Mini App بی‌صدا دورهٔ پایه (مثلاً ۳۰ روز) را ثبت می‌کند؛ یا برعکس، قیمت پرداختی با دورهٔ نمایش‌داده‌شده هم‌خوان نیست. این می‌تواند منجر به خرید اشتباه و شکایت شود.

**اصلاح پیشنهادی:**  
دقیقاً مثل ربات، یک `$matched` نگه دارید و در `quoteFor()` و `createOrder()` در صورت `$days > 0 && !$matched` خطای `ShopException` بدهید. همچنین `days <= 0` را به «دورهٔ پیش‌فرض» صریح تبدیل کنید، نه هر مقداری که کلاینت فرستاده است.

---

### B-17 — پرداخت با کیف پول، مسیر `applyAfterPayment()` را دور می‌زند

- **شدت:** متوسط
- **محل:** `src/WebApp/Api.php:1784-1815` و `src/Bot/Kernel.php:3608-3620`

**توضیح:**  
هر دو مسیر بعد از `markPaid()` مستقیماً `provisioner->provision()` را صدا می‌زنند. در حالی که مسیر IPN/درگاه از `PaymentService::applyAfterPayment()` استفاده می‌کند که علاوه بر provision، `notifyUserApplied()` را هم صدا می‌زند. این متد اعلان «بسته اعمال شد» و **۱۰ امتیاز وفاداری** را اضافه می‌کند (`PaymentService.php:815-855`).

**اثر:**  
- کاربرانی که با کیف پول پرداخت می‌کنند، پیام «اعمال شد» و امتیاز وفاداری دریافت نمی‌کنند.
- در صورت خطای provision، اعلان خرابی به ادمین ارسال نمی‌شود.
- تنظیم `autoApply` هم نادیده گرفته می‌شود؛ حتی وقتی اعمال خودکار خاموش است، بستهٔ کیف پول بلافاصله اعمال می‌شود.
- اگر `markPaid()` استثنا پرتاب کند (نه false)، کیف پول قبلاً کم شده و مسیر refund اجرا نمی‌شود؛ در Api این استثنا به لایهٔ بالاتر می‌رود و کاربر پول را از دست می‌دهد.

**اصلاح پیشنهادی:**  
بعد از `markPaid()` موفق، `applyAfterPayment($orderId)` را صدا بزنید (نه `provision()` مستقیم). کل بلوک کسر کیف پول، `markPaid` و provision را در `try/catch` بگذارید و در صورت استثنا، کیف پول را برگردانید. برای جلوگیری از provision دوباره، وضعیت سفارش بررسی شود (که `applyAfterPayment` خودش انجام می‌دهد).

---

### B-18 — شمارش قبل/بعد در قطع دسترسی bulk با فیلتر اشتباه انجام می‌شود

- **شدت:** متوسط
- **محل:** `src/Store/AccessCutoff.php:262-265` (و `160-170` برای `$authUser`)

**توضیح:**  
`countStatuses()` این‌طور فراخوانی می‌شود:

```php
$active = $client->countUsers($username, $password, [...], $username);
$off    = $client->countUsers($username, $password, [...], $username);
```

آرگومان آخر `admin` است. در حالت API-key (که `credentialsOf()` فعلاً فقط همین را پشتیبانی می‌کند)، `$authUser = 'api'` است، اما اینجا `$username` (نام پنل) به‌عنوان `admin` پاس می‌شود. نتیجه این است که کوئری با `?admin=<panel_username>` اجرا می‌شود و احتمالاً صفر کاربر برمی‌گرداند.

**اثر:**  
`before` و `after` هر دو صفر (یا هر دو اشتباه) می‌شوند؛ `disabled = max(0, before.active - after.active)` برابر صفر می‌شود و ربات گزارش می‌دهد «هیچ کاربری غیرفعال نشد»، در حالی که bulk disable انجام شده است. `markCutoffDone` هم با صفر ثبت می‌شود.

**اصلاح پیشنهادی:**  
آرگومان `admin` را بر اساس حالت احراز هویت تعیین کنید:

```php
$adminFilter = $authUser === 'api' ? null : $authUser;
$active = $client->countUsers($username, $password, [...], $adminFilter);
$off    = $client->countUsers($username, $password, [...], $adminFilter);
```

(اگر API-key همهٔ کاربران پنل را می‌بیند، `null` درست است.)

---

### B-19 — نام کاربری پنل به‌صورت global بررسی می‌شود و پنل‌های سرورهای مختلف را اشتباه مسدود می‌کند

- **شدت:** متوسط
- **محل:** `src/Store/PanelRepository.php:129-135` و `355-357`؛ گاردها در `src/WebApp/Api.php:991-995` و `src/Bot/PanelConnectionManager.php:325-329`

**توضیح:**  
`findByPanelUsername()` فقط با `panel_username` جست‌وجو می‌کند و `base_url`/سرور را در نظر نمی‌گیرد:

```sql
SELECT * FROM panels
WHERE panel_username = ? COLLATE NOCASE
ORDER BY id ASC LIMIT 1
```

اگر دو کاربر روی دو پنل/server مختلف نام کاربری ادمین یکسان داشته باشند (مثلاً هر دو `admin`)، کاربر دوم با پیام «این پنل قبلاً متصل است» مسدود می‌شود. همچنین `upsertFromPanel()` اگر گارد نباشد، `user_id` ردیف اول را بازنویسی می‌کند. `UserRepository::linkPanel()` بدون گارد `upsertFromPanel()` را صدا می‌زند (البته فعلاً استفاده نمی‌شود).

**اثر:**  
کاربران قانونی نمی‌توانند پنل خود را متصل کنند؛ در صورت استفاده از `linkPanel` خطر انتقال مالکیت ردیف پنل وجود دارد.

**اصلاح پیشنهادی:**  
کلید یکتایی را به `(base_url, panel_username)` تغییر دهید:

```php
public function findByPanelUsername(string $panelUsername, ?string $baseUrl = null): ?array
```

و در همهٔ فراخوانی‌ها `baseUrl` پنل را پاس دهید. ایندکس یکتا روی `(base_url, panel_username COLLATE NOCASE)` بسازید و `upsertFromPanel` را هم به همین کلید محدود کنید.

---

### B-20 — فلگ `expiry_notified` پس از تمدید ریست نمی‌شود؛ اعلان انقضا فقط یک‌بار ارسال می‌شود

- **شدت:** متوسط
- **محل:** `src/Store/PanelRepository.php:492-520` و `564-567`؛ مصرف در `src/Store/AlertService.php:374`

**توضیح:**  
`markExpiryNotified()` مقدار `expiry_notified = 1` را ست می‌کند، اما `addGranted()` (که در تمدید/شارژ صدا زده می‌شود) این فلگ را ریست نمی‌کند. `AlertService::checkExpired()` هم اگر فلگ ۱ باشد فوراً `false` برمی‌گرداند.

**اثر:**  
اگر پنلی یک‌بار منقضی شود و اعلان ارسال شود، پس از تمدید و انقضای بعدی هیچ اعلانی برای کاربر ارسال نمی‌شود. `grace_notified` هم همین مشکل را دارد.

**اصلاح پیشنهادی:**  
در `addGranted()` هنگام تمدید:

```sql
expiry_notified = 0,
grace_notified = 0,
cutoff_requested_at = NULL
```

و کلیدهای `panelwarn:{id}:grace` در Settings پاک شوند. اگر تمدید قبل از انقضا انجام می‌شود، ریست فلگ‌ها منطقی است.

---

### B-21 — `upsertPayment()` در برابر ایندکس یکتا Race دارد

- **شدت:** متوسط
- **محل:** `src/Store/OrderRepository.php:485-499`؛ ایندکس در `src/Support/Migrator.php:200-201`

**توضیح:**  
ابتدا `SELECT` انجام می‌شود و اگر ردیف نبود `INSERT`. اما ایندکس یکتای `(order_id, method)` وجود دارد. دو فراخوانی هم‌زمان (مثلاً دو IPN یا دو کلیک) می‌توانند هر دو `SELECT` را خالی ببینند و سپس یکی از `INSERT`ها با `UNIQUE constraint failed` مواجه شود.

**اثر:**  
استثنا در مسیر پرداخت/IPN و احتمال از دست رفتن به‌روزرسانی وضعیت پرداخت.

**اصلاح پیشنهادی:**  
از `INSERT ... ON CONFLICT(order_id, method) DO UPDATE SET ...` استفاده کنید (SQLite) یا `INSERT OR IGNORE` و سپس `UPDATE`، و خطای یکتایی را مدیریت کنید.

---

## 🔵 باگ‌های کم‌اهمیت

### B-22 — عملیات bulk «ریست وضعیت» فقط پیام موفقیت نمایش می‌دهد و هیچ کاری نمی‌کند

- **شدت:** کم
- **محل:** `src/Bot/AdminController.php:3731-3754`

**توضیح:**  
در `executeBulkOp()` فقط `sync_all` پیاده‌سازی شده است. برای `reset_status` (و هر عملیات دیگر) صرفاً پیام «انجام شد» ارسال می‌شود:

```php
} else {
    $this->bot->sendMessage($chatId, '✅ عملیات «' . $label . '» انجام شد.');
}
```

**اثر:**  
ادمین فکر می‌کند وضعیت‌ها ریست شده‌اند، در حالی که هیچ تغییری اعمال نشده است. این می‌تواند به تصمیم‌های عملیاتی اشتباه منجر شود.

**اصلاح پیشنهادی:**  
یا پیاده‌سازی واقعی (ریست وضعیت‌های مشخص در دیتابیس/پنل) یا حذف دکمه/بازگرداندن خطای «این عملیات هنوز پیاده‌سازی نشده است».

---

### B-23 — فیلد `receipt_photo_id` با مقدار نامربوط پر می‌شود / عملاً بلااستفاده است

- **شدت:** کم
- **محل:** `src/Payment/PaymentService.php:332-335`

**توضیح:**  
در `submitReceipt()`:

```php
'receipt_file_id'  => $fileId,
'receipt_photo_id' => $reference,
```

اما `$reference` در هیچ‌کدام از فراخوانی‌ها پاس داده نمی‌شود (`Kernel.php:1563` و `Api.php:1879` فقط `$fileId` می‌فرستند). بنابراین `receipt_photo_id` همیشه `null` است و اگر بعداً استفاده شود، معنایش با نام ستون هم‌خوان نیست.

**اثر:**  
کد مرده/گمراه‌کننده؛ در صورت بازگرداندن رسید، ممکن است فیلد اشتباه خوانده شود.

**اصلاح پیشنهادی:**  
یا حذف این ستون/تخصیص، یا اگر منظور ذخیرهٔ `file_id` تصویر است، همان `$fileId` در `receipt_photo_id` ذخیره شود و `receipt_file_id` برای فایل/سند باقی بماند. تصمیم مستند شود.

---

## 🧭 اولویت پیشنهادی اصلاح

1. **B-01** — Fatal Error حذف خودکار (فوری).
2. **B-02** — ضداسپم بی‌اثر.
3. **B-03، B-04** — از دست رفتن حجم/اعتبار کاربر در شارژ.
4. **B-06، B-07، B-08** — چرخهٔ سفارش‌های پرداخت‌نشده و دارایی کاربر.
5. **B-09، B-10، B-11، B-14** — تخفیف‌ها و امتیازها.
6. **B-05، B-17، B-18** — لینک ساب، پرداخت کیف پول، گزارش قطع دسترسی.
7. بقیهٔ موارد متوسط/کم.

---

## 📌 نکات تکمیلی

- تست‌های خودکار پروژه همگی موفق‌اند، اما هیچ‌کدام از مسیرهای بالا (UI ربات، IPN واقعی، هم‌زمانی، شارژ پنل نامحدود) را پوشش نمی‌دهند؛ به همین دلیل این باگ‌ها در تست‌ها دیده نشده‌اند.
- پیشنهاد می‌شود برای موارد B-01، B-02، B-03، B-04، B-09 و B-15 تست رگرسیون نوشته شود.
- مورد B-01 نشان می‌دهد «رفع باگ» بدون تست مسیر واقعی می‌تواند خودش باگ بحرانی‌تری ایجاد کند؛ بهتر است مسیر `panel.test.autodelete.set` در تست‌های Bot Kernel شبیه‌سازی شود.
- این گزارش فقط باگ‌های کد است؛ مسائل عملیاتی (تنظیمات production، کلید رمزنگاری، بکاپ، محدودیت نرخ در سطح وب‌سرور) در گزارش‌های امنیتی جداگانه بررسی شوند.

---

_پایان گزارش_
