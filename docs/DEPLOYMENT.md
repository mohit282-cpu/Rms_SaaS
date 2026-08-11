# RMS SaaS — Production Hosting & Deployment Guide

This guide provides instructions for deploying the RMS SaaS application to commercial hosting environments (Apache, cPanel, Nginx, or VPS).

## System Requirements

- **PHP**: 8.1 or higher (Extensions: `mysqli`, `pdoconfig`, `mbstring`, `json`, `gd`, `openssl`, `curl`)
- **Database**: MySQL 8.0+ or MariaDB 10.5+ (Strict mode recommended)
- **Web Server**: Apache 2.4+ (with `mod_rewrite`, `mod_headers`, `mod_deflate`) or Nginx
- **HTTPS**: Valid SSL/TLS Certificate (required for secure cookies & payment Webhooks)

---

## 1. Directory Structure & Permissions

Ensure proper directory permissions are configured on your web server:

```bash
chmod 755 /home/user/public_html
chmod -R 755 /home/user/public_html/storage
chmod -R 755 /home/user/public_html/uploads
```

- `storage/`: Must be writable by web server user (PHP-FPM / `www-data`). Direct web access is blocked by `storage/.htaccess`.
- `uploads/`: Must be writable for menu items, customer logos, and avatars. Direct script execution is blocked by `uploads/.htaccess`.

---

## 2. Environment Configuration (.env)

Copy `.env.example` to `.env` in the document root and populate with production values:

```ini
APP_NAME="RMS SaaS Restaurant Operating System"
APP_ENV=production
APP_DEBUG=false
APP_URL="https://yourdomain.com"

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=yourdb_rms
DB_USERNAME=yourdb_user
DB_PASSWORD=SecureProductionPassword123!

JWT_SECRET=Random64CharHexStringGenerateWithBin2HexRandomBytes
SESSION_LIFETIME=7200
LOG_CHANNEL=daily
```

> [!IMPORTANT]
> Never set `APP_DEBUG=true` in a live production environment. Technical error messages and stack traces are suppressed and written to `storage/logs/php_errors.log`.

---

## 3. Database Migrations

Run database migrations via CLI to provision tables and constraints:

```bash
php database/migrate.php
```

---

## 4. Scheduled Background Tasks (Cron Jobs)

Set up the following cron job to run every minute for background maintenance (point sweeps, shift sweeps, subscription checks):

```cron
* * * * * cd /home/user/public_html && php database/cron.php >> /dev/null 2>&1
```

---

## 5. Web Server Configuration (.htaccess / Nginx)

Apache `.htaccess` rules are pre-configured in the document root for:
- Automatic blocking of `.env`, `.app_secret`, `.sql`, and `.log` files.
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`).
- Disabling directory index listing.
- PHP production error display suppression (`display_errors Off`).
