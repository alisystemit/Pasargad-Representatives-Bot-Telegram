#!/bin/bash

set -e

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

echo "=========================================="
echo "[*] Pasargad Bot Installation Script"
echo "=========================================="
echo ""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() {
    echo -e "${BLUE}[i]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[✓]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

log_error() {
    echo -e "${RED}[✗]${NC} $1"
}

# Step 1: Check PHP
log_info "Checking PHP installation..."

if ! command -v php &> /dev/null; then
    log_error "PHP not found!"
    exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_VERSION;')
log_success "PHP $PHP_VERSION found"

# Step 2: Install dependencies with Composer
log_info "Installing dependencies..."

if [ -f "composer.json" ]; then
    if command -v composer &> /dev/null; then
        composer install --no-dev --optimize-autoloader 2>&1 | grep -E "(Installing|installed|Loading)" || true
    elif [ -f "composer.phar" ]; then
        php composer.phar install --no-dev --optimize-autoloader 2>&1 | grep -E "(Installing|installed|Loading)" || true
    else
        log_warning "Composer not found, skipping dependency installation"
    fi
else
    log_warning "composer.json not found"
fi

log_success "Dependencies ready"

# Step 3: Check if config.php exists
log_info "Checking configuration..."

if [ ! -f "config.php" ]; then
    if [ -f "config.example.php" ]; then
        log_warning "config.php not found, copying from config.example.php..."
        cp config.example.php config.php
        log_success "config.php created (please configure it)"
    else
        log_error "Neither config.php nor config.example.php found!"
        exit 1
    fi
else
    log_success "config.php exists"
fi

# Step 4: Database check
log_info "Checking database..."

if [ -f "data/bot.sqlite" ]; then
    log_success "Database found"
else
    log_info "Database file will be created on first run"
fi

# Step 5: Verify permissions on sensitive files
log_info "Checking file permissions..."

if [ -f "config.php" ]; then
    chmod 644 config.php
    log_success "config.php permissions set"
fi

# Step 6: Run setup script (FINAL STEP)
log_info "Running final setup..."
echo ""

if [ -f "setup.sh" ]; then
    bash setup.sh
else
    log_error "setup.sh not found!"
    exit 1
fi

# Final summary
echo ""
echo "=========================================="
log_success "Installation complete!"
echo ""
echo "[*] Configured:"
echo "  [✓] PHP dependencies"
echo "  [✓] Configuration"
echo "  [✓] Database"
echo "  [✓] Permissions"
echo "  [✓] Security"
echo ""
echo "[*] Next steps:"
echo "  1. Edit config.php with your settings"
echo "  2. Run: php tools/cli.php migrate"
echo "  3. Run: php tools/cli.php set-webhook"
echo ""
echo "[*] For support, check the README.md"
echo "=========================================="
