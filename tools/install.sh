#!/usr/bin/env bash
# نصب خودکار ربات نمایندگان پاسارگاد
#
# استفاده: bash tools/install.sh

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

ok()   { echo -e "${GREEN}✅ $1${NC}"; }
fail() { echo -e "${RED}❌ $1${NC}"; exit 1; }
warn() { echo -e "${YELLOW}⚠️  $1${NC}"; }
info() { echo -e "${BLUE}ℹ️  $1${NC}"; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo -e "${BLUE}"
echo "═══════════════════════════════════════════"
echo "  ربات نمایندگان پاسارگاد — نصب"
echo "═══════════════════════════════════════════"
echo -e "${NC}"

# ------------------------------------------------------------------
info "بررسی پیش‌نیازها..."
# ------------------------------------------------------------------

command -v php >/dev/null 2>&1 || fail "PHP نصب نیست. (لینوکس: apt install php-cli یا yum install php-cli)"

PHP_VERSION=$(php -r 'echo PHP_VERSION;')
PHP_MAJOR=$(echo "$PHP_VERSION" | cut -d. -f1)
PHP_MINOR=$(echo "$PHP_VERSION" | cut -d. -f2)

if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]; }; then
    fail "PHP 8.1 یا بالاتر لازم است (نسخهٔ فعلی: $PHP_VERSION)"
fi
ok "PHP $PHP_VERSION"

for ext in pdo_sqlite curl json mbstring openssl; do
    php -m | grep -qi "^$ext$" || fail "افزونهٔ PHP '$ext' نصب نیست."
done
ok "همهٔ افزونه‌های لازم نصب هستند"

# ------------------------------------------------------------------
info "آماده‌سازی تنظیمات..."
# ------------------------------------------------------------------

if [ -f config.php ]; then
    warn "config.php از قبل وجود دارد؛ دست‌نخورده می‌ماند."
else
    cp config.example.php config.php
    ok "config.php از روی قالب ساخته شد."
fi

# تولید مقادیر تصادفی
CRYPTO_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
WEBHOOK_SECRET=$(php -r 'echo bin2hex(random_bytes(24));')

# جایگزینی مقادیر در فایل تنظیمات (در صورت وجود الگوی پیش‌فرض)
php -r '
$file = "config.php";
$content = file_get_contents($file);
$content = preg_replace(
    "/(CHANGE-THIS-TO-A-LONG-RANDOM-STRING-32\+CHARS)/",
    $argv[1],
    $content
);
$content = preg_replace(
    "/(CHANGE-THIS-RANDOM-SECRET)/",
    $argv[2],
    $content
);
file_put_contents($file, $content);
' "$CRYPTO_KEY" "$WEBHOOK_SECRET"

# اگر کاربر قبلاً کلیدی تنظیم کرده بود، این‌بار هم دست نمی‌زنیم
if grep -q 'CHANGE-THIS-TO-A-LONG-RANDOM-STRING' config.php; then
    warn "کلید رمزنگاری خودکار جایگزین نشد — مقدار crypto_key را دستی تنظیم کنید."
fi

# ------------------------------------------------------------------
info "پرسیدن مشخصات..."
# ------------------------------------------------------------------

echo ""
echo "برای ادامه، این مقادیر را در config.php ویرایش کنید:"
echo ""
echo "  • bot_token      — توکن از @BotFather"
echo "  • super_admins   — آیدی عددی تلگرام شما"
echo "  • base_url       — آدرس دامنه پروژه"
echo "  • panel.base_url — آدرس پنل (پیش‌فرض: https://us.api-system.top)"
echo "  • store.card_*   — اطلاعات کارت برای پرداخت دستی"
echo ""

read -r -p "آیا مقادیر را ویرایش کرده‌اید؟ (y/n) " -n 1 -r
echo ""
if [[ ! "$REPLY" =~ ^[Yy]$ ]]; then
    warn "نصب متوقف شد. پس از ویرایش config.php دوباره اجرا کنید."
    exit 0
fi

# ------------------------------------------------------------------
info "ساخت دیتابیس..."
# ------------------------------------------------------------------

mkdir -p data/logs
chmod -R 775 data 2>/dev/null || true

php tools/cli.php migrate || fail "مایگریشن دیتابیس ناموفق بود."
ok "دیتابیس آماده شد."

# ------------------------------------------------------------------
info "ساخت بسته‌های پیش‌فرض..."
# ------------------------------------------------------------------

php tools/seed.php
ok "بسته‌های پیش‌فرض ساخته شدند (از پنل مدیریت قابل تغییرند)."

# ------------------------------------------------------------------
info "بررسی تنظیمات..."
# ------------------------------------------------------------------

php -r '
require "bootstrap.php";
$checks = [
    ["bot_token", Pasargad\Support\Config::str("bot_token"), "توکن ربات"],
    ["super_admins", count(Pasargad\Support\Config::arr("super_admins")), "سوپرادمین‌ها"],
    ["base_url", Pasargad\Support\Config::str("base_url"), "آدرس پروژه"],
];
$bad = 0;
foreach ($checks as [$key, $value, $label]) {
    $empty = ($value === "" || $value === "PUT_BOT_TOKEN_HERE" || $value === 0);
    echo ($empty ? "  ⚠️  " : "  ✅ ") . $label . ": " . (is_scalar($value) ? $value : "?") . PHP_EOL;
    if ($empty) { $bad++; }
}
exit($bad === 0 ? 0 : 1);
' || warn "برخی تنظیمات کامل نیستند — ربات تا تنظیم آن‌ها کار نمی‌کند."

# ------------------------------------------------------------------
info "تنظیم وبهوک..."
# ------------------------------------------------------------------

read -r -p "وبهوک را تنظیم کنم؟ (y/n) " -n 1 -r
echo ""
if [[ "$REPLY" =~ ^[Yy]$ ]]; then
    php tools/cli.php set-webhook || warn "تنظیم وبهوک ناموفق بود — base_url و SSL را بررسی کنید."
fi

# ------------------------------------------------------------------
echo -e "${GREEN}"
echo "═══════════════════════════════════════════"
echo "  ✅ نصب کامل شد"
echo "═══════════════════════════════════════════"
echo -e "${NC}"
echo ""
echo "گام‌های بعدی:"
echo ""
echo "  ۱) کرون را تنظیم کنید (هر ۵ دقیقه):"
echo "     */5 * * * * php $ROOT/cron/worker.php"
echo ""
echo "  ۲) ربات را در تلگرام /start کنید"
echo ""
echo "  ۳) برای تست:"
echo "     php tools/cli.php selftest"
echo ""
echo "  ۴) لاگ‌ها:"
echo "     $ROOT/data/logs/"
echo ""