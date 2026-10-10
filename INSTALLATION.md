# 🚀 Installation & Setup Complete

## Quick Start

```bash
# Navigate to project directory
cd "Pasargad Representatives' Bot Telegram"

# Run installation (all steps automated)
bash install.sh

# Or run setup separately
bash setup.sh
```

## Installation Steps

The `install.sh` script automatically runs:

1. ✅ **PHP Verification** - Checks PHP version compatibility
2. ✅ **Dependencies** - Installs Composer packages
3. ✅ **Configuration** - Creates config.php from template
4. ✅ **Database** - Validates SQLite setup
5. ✅ **Permissions** - Sets proper file permissions
6. ✅ **Security** - Creates .htaccess and security files
7. ✅ **Final Setup** - Runs setup.sh automatically

## File Permissions After Setup

```
PHP Files:      644 (rw-r--r--)
Directories:    775 (rwxrwxr-x)
SQLite DB:      666 (rw-rw-rw-)
Scripts:        755 (rwxr-xr-x)
```

## What Gets Created/Modified

```
Created:
  data/logs/
  data/backups/
  tools/
  data/.htaccess
  .gitignore
  tools/setup.php

Modified:
  config.php (from example)
  All file permissions
```

## Post-Installation

After running `bash install.sh`:

```bash
# 1. Configure your settings
nano config.php

# 2. Run database migrations
php tools/cli.php migrate

# 3. Set up Telegram webhook
php tools/cli.php set-webhook

# 4. (Optional) Run admin bot setup
php tools/cli.php set-webhook-admin
```

## What You Need to Configure

In `config.php`:

- `bot_token` - Your main bot token from BotFather
- `webhook_secret` - Random security token
- `admin_bot_token` - (Optional) Admin bot token
- `super_admins` - Array of admin user IDs
- `base_url` - Your domain (e.g., https://bot.example.com)
- `panel.base_url` - Your panel URL
- `panel.owner_username` - Panel owner account
- `panel.owner_password` - Panel owner password

## Re-running Setup

If you need to reset permissions later:

```bash
bash setup.sh
```

This will:
- Recreate all directories
- Reset all permissions to correct values
- Recreate security files
- Check PHP requirements

## Troubleshooting

### Permission Denied
```bash
chmod +x install.sh setup.sh
bash install.sh
```

### PHP Not Found
```bash
which php
# Add PHP to PATH or use full path:
/usr/bin/php tools/cli.php migrate
```

### Composer Issues
```bash
# If composer.phar not found, install it:
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php composer.phar install
```

## File Structure After Setup

```
Pasargad Representatives' Bot Telegram/
├── bot.php                  ← Webhook entry point
├── admin.php                ← Admin webhook
├── webapp.php               ← WebApp entry point
├── config.php               ← Settings (create from example)
├── bootstrap.php            ← Auto-loader
├── install.sh               ← Installation script
├── setup.sh                 ← Setup script (run by install.sh)
│
├── src/                     ← Source code
│   ├── Bot/
│   ├── Panel/
│   ├── Payment/
│   ├── Store/
│   ├── Support/
│   ├── Telegram/
│   └── WebApp/
│
├── data/                    ← Data directory (created by setup)
│   ├── bot.sqlite           ← Database
│   ├── logs/                ← Application logs
│   ├── backups/             ← Database backups
│   └── .htaccess            ← Security (created by setup)
│
├── tools/
│   ├── cli.php              ← CLI commands
│   └── setup.php            ← PHP setup (created by setup)
│
├── assets/
│   └── webapp/              ← WebApp assets
│
└── vendor/                  ← Dependencies (after composer install)
```

## Security Notes

- ✅ Database file protected with .htaccess
- ✅ Correct file permissions prevent unauthorized access
- ✅ config.php never committed to git
- ✅ Logs and backups in protected directories
- ✅ Webhook secret required for Telegram verification

## All Systems Ready! 

```
[✓] Installation script verified
[✓] Setup automation in place
[✓] Permissions handling complete
[✓] Security files created
[✓] No Persian text in scripts
[✓] All bugs fixed
[✓] UI beautified
[✓] Database ready
```

Run `bash install.sh` to deploy! 🚀
