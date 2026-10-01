<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use RuntimeException;

/**
 * خطای قابل انتظار در جریان خرید که باید به کاربر نمایش داده شود.
 *
 * مثال: سقف خرید هر کاربر پر شده، یا فروشگاه بسته است.
 *
 * تفاوت با خطاهای فنی: این خطاها نشانهٔ نقص نیستند، پس Kernel آن‌ها را
 * می‌گیرد و پیام مناسب را نشان می‌دهد، نه اینکه کل webhook را fail کند و
 * پیام اصلی کاربر هم از دست برود.
 */
final class ShopException extends RuntimeException
{
}