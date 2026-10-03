#!/usr/bin/env bash
# Installer for the Panel Representatives Telegram bot.
#
# Usage: bash tools/install.sh

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
echo "  Panel Representatives Bot — Install ✨"
echo "═══════════════════════════════════════════"
echo -e "${NC}"

# ------------------------------------------------------------------
info "Checking prerequisites..."
# ------------------------------------------------------------------

command -v php >/dev/null 2>&1 || fail "PHP is not installed. (Linux: apt install php-cli or yum install php-cli)"

PHP_VERSION=$(php -r 'echo PHP_VERSION;')
PHP_MAJOR=$(echo "$PHP_VERSION" | cut -d. -f1)
PHP_MINOR=$(echo "$PHP_VERSION" | cut -d. -f2)

if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]; }; then
    fail "PHP 8.1 or newer is required (current: $PHP_VERSION)"
fi
ok "PHP $PHP_VERSION"

for ext in pdo_sqlite curl json mbstring openssl; do
    php -m | grep -qi "^$ext$" || fail "PHP extension '$ext' is not installed."
done
ok "All required extensions are present"

# ------------------------------------------------------------------
info "Preparing configuration..."
# ------------------------------------------------------------------

if [ -f config.php ]; then
    warn "config.php already exists; leaving it untouched."
else
    cp config.example.php config.php
    ok "config.php created from the template."
fi

# Generate random secrets
CRYPTO_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
WEBHOOK_SECRET=$(php -r 'echo bin2hex(random_bytes(24));')

# Substitute values in the config file (only where the placeholder is present)
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

# If the operator had already set a key, we deliberately leave it alone
if grep -q 'CHANGE-THIS-TO-A-LONG-RANDOM-STRING' config.php; then
    warn "Encryption key was not auto-replaced — set crypto_key manually."
fi

# ------------------------------------------------------------------
info "Configuration checklist..."
# ------------------------------------------------------------------

echo ""
echo "Edit these values in config.php before continuing:"
echo ""
echo "  • bot_token      — token from @BotFather"
echo "  • super_admins   — your numeric Telegram ID"
echo "  • base_url       — public URL of this installation"
echo "  • panel.base_url — panel URL (default: https://us.api-system.top)"
echo "  • store.card_*   — card details for manual payments"
echo ""
echo "  For selling agency panels you also need:"
echo "  • panel.owner_username / panel.owner_password — the panel owner account"
echo "    that creates each representative's panel."
echo ""

read -r -p "Have you edited these values? (y/n) " -n 1 -r
echo ""
if [[ ! "$REPLY" =~ ^[Yy]$ ]]; then
    warn "Install stopped. Run this again after editing config.php."
    exit 0
fi

# ------------------------------------------------------------------
info "Setting up the database..."
# ------------------------------------------------------------------

mkdir -p data/logs data/backups
chmod -R 775 data 2>/dev/null || true

# ------------------------------------------------------------------
# Backup before any schema change.
#
# Without this, a half-applied migration means restoring the database, and
# restoring means losing every order and every purchased panel — none of
# which can be reconstructed.
# ------------------------------------------------------------------
if [[ -f data/bot.sqlite ]]; then
    php tools/cli.php backup && ok "Backup taken before the schema change."
else
    warn "No database to back up yet (fresh install) — continuing."
fi

php tools/cli.php migrate || fail "Database migration failed."
ok "Database is ready."

# ------------------------------------------------------------------
info "Creating starter packages..."
# ------------------------------------------------------------------

php tools/seed.php
ok "Starter packages created (editable later in the admin panel)."

# ------------------------------------------------------------------
info "Verifying configuration..."
# ------------------------------------------------------------------

php -r '
require "bootstrap.php";
$checks = [
    ["bot_token", Pasargad\Support\Config::str("bot_token"), "bot_token"],
    ["super_admins", count(Pasargad\Support\Config::arr("super_admins")), "super_admins count"],
    ["base_url", Pasargad\Support\Config::str("base_url"), "base_url"],
];
$bad = 0;
foreach ($checks as [$key, $value, $label]) {
    $empty = ($value === "" || $value === "PUT_BOT_TOKEN_HERE" || $value === 0);
    echo ($empty ? "  ⚠️  " : "  ✅ ") . $label . ": " . (is_scalar($value) ? $value : "?") . PHP_EOL;
    if ($empty) { $bad++; }
}
exit($bad === 0 ? 0 : 1);
' || warn "Some settings are incomplete — the bot will not work until they are set."

# ------------------------------------------------------------------
info "Registering the webhook..."
# ------------------------------------------------------------------

read -r -p "Register the webhook now? (y/n) " -n 1 -r
echo ""
if [[ "$REPLY" =~ ^[Yy]$ ]]; then
    php tools/cli.php set-webhook || warn "Webhook registration failed — check base_url and SSL."
fi

# ------------------------------------------------------------------
# Separate admin bot webhook (optional)
#
# Only offered when admin_bot_token is present in the config; otherwise we
# would ask a pointless question the operator answers "no" to without even
# knowing the feature exists.
# ------------------------------------------------------------------
ADMIN_TOKEN="$(php -r 'require "bootstrap.php"; echo Pasargad\Support\Config::str("admin_bot_token", "");' 2>/dev/null)"

if [[ -n "$ADMIN_TOKEN" ]]; then
    read -r -p "Register the separate admin bot webhook too? (y/n) " -n 1 -r
    echo ""
    if [[ "$REPLY" =~ ^[Yy]$ ]]; then
        php tools/cli.php set-webhook-admin \
            || warn "Admin webhook registration failed — check admin_webhook_secret."
    fi
else
    warn "admin_bot_token is empty ⇒ the separate admin bot stays disabled (this is fine)."
fi

# ------------------------------------------------------------------
echo -e "${GREEN}"
echo "═══════════════════════════════════════════"
echo "  ✅ Install complete"
echo "═══════════════════════════════════════════"
echo -e "${NC}"
echo ""
echo "Next steps:"
echo ""
echo "  1) Schedule cron (every 5 minutes):"
echo "     */5 * * * * php $ROOT/cron/worker.php"
echo ""
echo "  2) Send /start to the bot in Telegram"
echo ""
echo "  3) Set the panel owner account in config.php (panel.owner_username"
echo "     and panel.owner_password) — without it, selling agency panels is disabled."
echo ""
echo "  4) To verify:"
echo "     php tools/cli.php selftest"
echo "     php tools/run_tests.php"
echo ""
echo "  5) Logs:"
echo "     $ROOT/data/logs/"
echo ""
echo "  6) Always back up before a schema change:"
echo "     php tools/cli.php backup"
echo ""