#!/usr/bin/env bash
#
# Quick installer for the panel representatives bot — just 3 questions, the rest is automatic.
#
#   bash tools/install.sh
#
# Questions:
#   1) Bot token from @BotFather
#   2) Your numeric Telegram ID (super admin)
#   3) Website URL (no trailing slash)
#
# Everything else is automatic: config.php creation, random security keys,
# backup, migration, default packages, token validation, webhook registration
# and a health report.
#
# Non-interactive (useful for CI or re-installs):
#   BOT_TOKEN=123:ABC ADMIN_ID=123456 BASE_URL=https://bot.example.com \
#     bash tools/install.sh
#   # or
#   bash tools/install.sh --token=123:ABC --admin-id=123456 --base-url=https://bot.example.com
#
# Options:
#   --skip-webhook   Skip webhook registration (run later: php tools/cli.php set-webhook)
#   --help           Show this help
#
# Note: `read -n 1` is deliberately NOT used. That mode does not consume the
# Enter key, so the next question silently returns with an empty value; in the
# previous version this made webhook registration never run while the script
# still printed "Install complete".

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

ok()   { echo -e "${GREEN}✅ $1${NC}"; }
warn() { echo -e "${YELLOW}⚠️  $1${NC}"; }
info() { echo -e "${BLUE}ℹ️  $1${NC}"; }
fail() { echo -e "${RED}❌ $1${NC}" >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# ------------------------------------------------------------------
# Arguments
# ------------------------------------------------------------------

ARG_TOKEN=""
ARG_ADMIN_ID=""
ARG_BASE_URL=""
SKIP_WEBHOOK=0

for arg in "$@"; do
    case "$arg" in
        --token=*)      ARG_TOKEN="${arg#--token=}" ;;
        --admin-id=*)   ARG_ADMIN_ID="${arg#--admin-id=}" ;;
        --base-url=*)   ARG_BASE_URL="${arg#--base-url=}" ;;
        --skip-webhook) SKIP_WEBHOOK=1 ;;
        --help|-h)
            # The whole comment block from the shebang until the first non-comment line
            awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "${BASH_SOURCE[0]}"
            exit 0 ;;
        *) fail "Unknown option: $arg (see --help)" ;;
    esac
done

echo -e "${BLUE}"
echo "═══════════════════════════════════════════"
echo "  Quick install — representatives bot ✨"
echo "═══════════════════════════════════════════"
echo -e "${NC}"

# ------------------------------------------------------------------
# Prerequisites
# ------------------------------------------------------------------

info "Checking prerequisites..."

detect_php() {
    local candidate
    for candidate in "${PHP_BIN:-}" php php.exe; do
        [[ -n "$candidate" ]] || continue
        if command -v "$candidate" >/dev/null 2>&1; then
            PHP_BIN="$candidate"
            return 0
        fi
    done

    # Laragon / XAMPP when PHP is not on PATH (Windows)
    local glob
    for glob in /c/laragon/bin/php/*/php.exe /c/php*/php.exe /c/xampp/php/php.exe; do
        if [[ -x "$glob" ]]; then
            PHP_BIN="$glob"
            return 0
        fi
    done

    return 1
}

PHP_BIN=""
detect_php || fail "PHP not found. Install it (Linux: apt install php-cli) or pass the path with PHP_BIN=/path/to/php"

command -v "$PHP_BIN" >/dev/null 2>&1 || true

PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
PHP_MAJOR="${PHP_VERSION%%.*}"
PHP_MINOR="$(echo "$PHP_VERSION" | cut -d. -f2)"

if (( PHP_MAJOR < 8 )) || { (( PHP_MAJOR == 8 )) && (( PHP_MINOR < 1 )); }; then
    fail "PHP 8.1 or newer is required (current version: $PHP_VERSION)"
fi
ok "PHP $PHP_VERSION  ($PHP_BIN)"

for ext in pdo_sqlite curl json mbstring openssl; do
    "$PHP_BIN" -m | grep -qi "^${ext}$" || fail "Required PHP extension «$ext» is missing."
done
ok "Required extensions present (pdo_sqlite, curl, json, mbstring, openssl)"

# ------------------------------------------------------------------
# Questions 1..3
# ------------------------------------------------------------------

ANSWER=""

# ask <question> <validation regex> <error hint> <default value>
#
# The answer goes to ANSWER (not stdout) so the call does not need a subshell;
# inside a subshell `exit` would only close that subshell and the install would
# silently continue.
ask() {
    local prompt="$1" pattern="$2" hint="$3" default="${4:-}" value=""

    while true; do
        if [[ -n "$default" ]]; then
            printf '%s [%s]: ' "$prompt" "$default" >&2
        else
            printf '%s: ' "$prompt" >&2
        fi

        if ! IFS= read -r value; then
            echo "" >&2
            fail "Input closed. You can run without prompts:
  BOT_TOKEN=... ADMIN_ID=... BASE_URL=... bash tools/install.sh"
        fi

        [[ -z "$value" && -n "$default" ]] && value="$default"

        if [[ "$value" =~ $pattern ]]; then
            ANSWER="$value"
            return 0
        fi

        echo -e "${YELLOW}⚠️  $hint${NC}" >&2
    done
}

# Value from an argument or an environment variable; only asked when missing.
TOKEN="${ARG_TOKEN:-${BOT_TOKEN:-}}"
ADMIN_ID="${ARG_ADMIN_ID:-${ADMIN_ID:-}}"
BASE_URL="${ARG_BASE_URL:-${BASE_URL:-}}"

if [[ -z "$TOKEN" ]]; then
    echo "" >&2
    ask "1) Bot token from @BotFather (like 123456789:AAH...)" \
        '^[0-9]{4,}:[A-Za-z0-9_-]{8,}$' \
        'Token format is not valid. Copy it from @BotFather and keep the whole `:`.'
    TOKEN="$ANSWER"
fi

if [[ -z "$ADMIN_ID" ]]; then
    echo "" >&2
    ask "2) Your numeric Telegram ID (super admin)" \
        '^[0-9]{4,20}(,[0-9]{4,20})*$' \
        'The ID must be numeric — it is not a @username or a profile link. Get it from @userinfobot.'
    ADMIN_ID="$ANSWER"
fi

if [[ -z "$BASE_URL" ]]; then
    echo "" >&2
    ask "3) Website URL (no trailing slash, like https://bot.example.com)" \
        '^https?://[A-Za-z0-9.-]+(:[0-9]+)?(/[^[:space:]]*)?$' \
        'The URL must start with http:// or https://.'
    BASE_URL="$ANSWER"
fi

BASE_URL="${BASE_URL%/}"

case "$BASE_URL" in
    https://*) ;;
    *) warn "Telegram only accepts webhooks over HTTPS. The URL must be https for the bot to work." ;;
esac

echo ""
info "Token:     ${TOKEN%%:*}:********"
info "Super admin: $ADMIN_ID"
info "URL:       $BASE_URL"
echo ""

# ------------------------------------------------------------------
# Configuration
# ------------------------------------------------------------------

info "Writing settings to config.php..."
"$PHP_BIN" tools/configure.php \
    --bot-token="$TOKEN" \
    --admin-id="$ADMIN_ID" \
    --base-url="$BASE_URL" \
    || fail "Writing config.php failed — install stopped."

# ------------------------------------------------------------------
# Database
# ------------------------------------------------------------------

info "Preparing the database..."

mkdir -p data/logs data/backups
chmod -R 775 data 2>/dev/null || true

# Back up before any schema change: a half-done migration means restoring the
# database, and restoring means losing every order and every panel sold.
if [[ -f data/bot.sqlite ]]; then
    "$PHP_BIN" tools/cli.php backup >/dev/null || warn "Backup failed; continuing."
else
    info "No database to back up (fresh install) — continuing..."
fi

"$PHP_BIN" tools/cli.php migrate || fail "Database migration failed."
ok "Database ready."

info "Creating default packages..."
"$PHP_BIN" tools/seed.php || fail "Creating default packages failed."
ok "Packages created."

# ------------------------------------------------------------------
# Token validation + bot name (automatic)
# ------------------------------------------------------------------
#
# `get-me` exit codes:
#   0 = token is valid (bot username on stdout)
#   1 = token is invalid → the install must stop
#   2 = no network access to Telegram → continue, but warn
# ------------------------------------------------------------------

info "Validating the bot token with Telegram..."

GET_ME_OUTPUT=""
set +e
GET_ME_OUTPUT="$("$PHP_BIN" tools/cli.php get-me 2>/dev/null)"
GET_ME_RC=$?
set -e

if (( GET_ME_RC == 0 )); then
    BOT_USERNAME="$GET_ME_OUTPUT"
    ok "Token valid — bot @$BOT_USERNAME"
    if [[ -n "$BOT_USERNAME" ]]; then
        "$PHP_BIN" tools/configure.php --bot-username="$BOT_USERNAME" >/dev/null || true
    fi
elif (( GET_ME_RC == 1 )); then
    fail "Invalid bot token. Get a new one from @BotFather and run the installer again."
else
    warn "Cannot reach api.telegram.org; the token was not verified. Run this once the network works:"
    warn "  php tools/cli.php get-me"
fi

# ------------------------------------------------------------------
# Webhook
# ------------------------------------------------------------------

WEBHOOK_FAILED=0

if (( SKIP_WEBHOOK == 1 )); then
    warn "--skip-webhook: webhook not registered. Run later: php tools/cli.php set-webhook"
else
    info "Registering the Telegram webhook..."
    if "$PHP_BIN" tools/cli.php set-webhook; then
        ok "Webhook registered: $BASE_URL/bot.php"
    else
        WEBHOOK_FAILED=1
        warn "Webhook registration failed. After fixing the problem run: php tools/cli.php set-webhook"
    fi

    # The admin bot webhook only makes sense when its token is configured.
    ADMIN_TOKEN="$("$PHP_BIN" -r 'require "bootstrap.php"; echo trim(Pasargad\Support\Config::str("admin_bot_token", ""));' 2>/dev/null || true)"
    if [[ -n "$ADMIN_TOKEN" ]]; then
        info "Registering the admin bot webhook..."
        if ! "$PHP_BIN" tools/cli.php set-webhook-admin; then
            WEBHOOK_FAILED=1
            warn "Admin webhook registration failed: php tools/cli.php set-webhook-admin"
        fi
    fi
fi

# ------------------------------------------------------------------
# Health report
# ------------------------------------------------------------------

echo ""
info "Health report..."

set +e
"$PHP_BIN" tools/healthcheck.php
HEALTH_RC=$?
set -e

if (( HEALTH_RC == 0 )); then
    ok "All checks green."
else
    warn "healthcheck reported errors/warnings (see above)."
fi

# ------------------------------------------------------------------
# Summary
# ------------------------------------------------------------------

echo ""
if (( WEBHOOK_FAILED == 0 )); then
    echo -e "${GREEN}"
    echo "═══════════════════════════════════════════"
    echo "  ✅ Install complete"
    echo "═══════════════════════════════════════════"
    echo -e "${NC}"
else
    echo -e "${YELLOW}"
    echo "═══════════════════════════════════════════"
    echo "  ⚠️  Installed, but the webhook was not registered"
    echo "═══════════════════════════════════════════"
    echo -e "${NC}"
fi

cat <<EOF

Next steps:

  1) Cron (every 5 minutes — the most important step after installing):
       */5 * * * * php $ROOT/cron/worker.php

  2) Send /start to the bot.

  3) To sell agency panels, configure the panel-creating account
     (config.php → panel.owner_username / panel.owner_password).

  4) For manual payments, enter your card details
     (config.php → store.card_number / card_owner).

  5) Verify again:
       php tools/cli.php get-me
       php tools/cli.php webhook-info
       php tools/healthcheck.php
       php tools/run_tests.php

  6) Logs:
       $ROOT/data/logs/

  7) Before any risky change:
       php tools/cli.php backup

EOF

if (( WEBHOOK_FAILED != 0 )); then
    exit 1
fi
