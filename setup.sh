#!/bin/bash

set -e

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

echo "[*] Setting up project directories and permissions..."
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

# Step 1: Create required directories
log_info "Creating directories..."

mkdir -p data/logs
mkdir -p data/backups
mkdir -p tools

log_success "Directories created"

# Step 2: Set permissions
log_info "Setting permissions..."

chmod 775 data 2>/dev/null || true
chmod 775 data/logs 2>/dev/null || true
chmod 775 data/backups 2>/dev/null || true

if [ -f "data/bot.sqlite" ]; then
    chmod 666 data/bot.sqlite
    log_success "Database permissions: 666"
fi

find data/logs -type f -name "*.log" 2>/dev/null -exec chmod 666 {} \; || true

find . -type f -name "*.php" -exec chmod 644 {} \;
find . -type f -name "*.js" -exec chmod 644 {} \;
find . -type f -name "*.css" -exec chmod 644 {} \;
find . -type f -name "*.json" -exec chmod 644 {} \;
find . -type f -name "*.md" -exec chmod 644 {} \;

chmod 755 tools/*.php 2>/dev/null || true
chmod 755 *.php 2>/dev/null || true
chmod 755 bootstrap.php 2>/dev/null || true

log_success "Permissions set"

# Step 3: Create .htaccess for security
log_info "Creating security files..."

cat > data/.htaccess << 'EOF'
<FilesMatch "\.sqlite">
    Order Deny,Allow
    Deny from all
</FilesMatch>

Options -Indexes
EOF

log_success ".htaccess created"

# Step 4: Create .gitignore if not exists
if [ ! -f ".gitignore" ]; then
    log_info "Creating .gitignore..."
    cat > .gitignore << 'EOF'
data/bot.sqlite
data/logs/*.log
data/backups/*.backup

config.php

.env
.env.local
.env.*.local

.vscode/
.idea/
*.swp
*.swo

.DS_Store
Thumbs.db

vendor/
composer.lock
EOF
    log_success ".gitignore created"
fi

# Step 5: Create tools/setup.php
log_info "Creating PHP setup script..."

cat > tools/setup.php << 'PHPEOF'
<?php
declare(strict_types=1);

echo "[*] Running PHP setup checks...\n\n";

date_default_timezone_set('Asia/Tehran');

echo "[*] Checking requirements:\n\n";

$checks = [
    'PHP >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'SQLite3' => extension_loaded('sqlite3'),
    'PDO' => extension_loaded('pdo'),
    'Curl' => extension_loaded('curl'),
    'JSON' => extension_loaded('json'),
    'OpenSSL' => extension_loaded('openssl'),
];

$allOk = true;
foreach ($checks as $name => $result) {
    $status = $result ? '[✓]' : '[✗]';
    echo "$status $name\n";
    if (!$result) $allOk = false;
}

if (!$allOk) {
    echo "\n[✗] Some requirements are missing!\n";
    exit(1);
}

echo "\n[✓] All requirements OK!\n\n";

echo "[*] Setting up directories:\n\n";

$dirs = [
    'data' => 0o775,
    'data/logs' => 0o775,
    'data/backups' => 0o775,
    'tools' => 0o755,
];

foreach ($dirs as $dir => $perm) {
    if (!is_dir($dir)) {
        if (@mkdir($dir, $perm, true)) {
            echo "[✓] Created: $dir\n";
        } else {
            echo "[!] Failed to create: $dir\n";
        }
    } else {
        if (@chmod($dir, $perm)) {
            echo "[✓] Permissions set: $dir (" . decoct($perm) . ")\n";
        }
    }
}

echo "\n[✓] Setup complete!\n\n";
echo "[*] Next steps:\n";
echo "1. Copy config.example.php to config.php\n";
echo "2. Configure your tokens and credentials\n";
echo "3. Run: php tools/cli.php migrate\n";
echo "4. Run: php tools/cli.php set-webhook\n\n";
PHPEOF

chmod 755 tools/setup.php

log_success "PHP setup script created"

# Step 6: Set final permissions
log_info "Setting final permissions..."

chmod 755 install.sh setup.sh 2>/dev/null || true

log_success "All permissions set"

# Summary
echo ""
echo "=========================================="
echo ""
log_success "Project setup complete!"
echo ""
echo "[*] Summary:"
echo "  [✓] Directories created/configured"
echo "  [✓] Permissions set (644/755/775)"
echo "  [✓] Security files created"
echo "  [✓] Setup scripts ready"
echo ""
echo "[*] To run setup again:"
echo "  bash setup.sh"
echo ""
echo "=========================================="
