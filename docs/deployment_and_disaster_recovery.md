# RMS SaaS - Production Deployment & Disaster Recovery Guide

## 1. System Requirements
- **PHP Version**: 8.1 or higher (with `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `openssl` extensions enabled)
- **Database Engine**: MySQL 8.0+ or MariaDB 10.5+ (InnoDB storage engine default)
- **Web Server**: Apache 2.4+ with `mod_rewrite`, `mod_headers`, and `mod_expires` enabled
- **OS**: Linux (Ubuntu 22.04 LTS / Debian 12 / RHEL 9) or Windows Server

---

## 2. Production Environment Setup

### A. Environment Configuration (`.env`)
1. Create `.env` from `.env.example`:
   ```bash
   cp .env.example .env
   ```
2. Configure production parameters in `.env`:
   ```ini
   APP_NAME="RMS SaaS - Restaurant Management System"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL="https://your-domain.com"

   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=rms_saas_production
   DB_USERNAME=rms_db_user
   DB_PASSWORD="<COMPLEX_RANDOM_PASSWORD>"

   JWT_SECRET="<CRYPTOGRAPHICALLY_RANDOM_HEX_64>"
   SUPER_ADMIN_EMAIL="admin@your-domain.com"
   SUPER_ADMIN_PASSWORD="<SECURE_SUPERADMIN_PASSWORD>"
   ```

> [!CAUTION]
> Ensure `APP_DEBUG=false` in production to prevent stack trace or path disclosure.

---

### B. Directory Permissions
Ensure web server user (`www-data` / `apache`) has appropriate permissions:
```bash
chmod -R 755 /var/www/html/Rms_SaaS
chmod -R 775 /var/www/html/Rms_SaaS/storage
chmod -R 775 /var/www/html/Rms_SaaS/uploads
```

---

### C. Database Migration Execution
Execute CLI migrations deterministically before serving traffic:
```bash
php database/migrate.php
```

---

### D. Recommended PHP OPcache Configuration (`php.ini`)
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.validate_timestamps=1
opcache.save_comments=1
```

---

## 3. Disaster Recovery & Backup Strategy

### A. Automated Backup Command
Execute tenant or full database snapshots via CLI cron job:
```bash
# Daily full backup snapshot at midnight
0 0 * * * /usr/bin/php /var/www/html/Rms_SaaS/database/backup.php >> /var/www/html/Rms_SaaS/storage/logs/cron.log 2>&1
```

### B. Target Recovery Objectives
- **Recovery Point Objective (RPO)**: < 24 hours (with daily snapshot) or < 1 hour (if binary log replication enabled)
- **Recovery Time Objective (RTO)**: < 15 minutes (restore snapshot via CLI restore runner)

### C. Manual Restore Verification
To verify backup file integrity:
```bash
php -r "$b = json_decode(file_get_contents('storage/backups/backup_full_saas_YYYYMMDD_HHMMSS.json'), true); echo 'Tables: ' . count($b['tables']) . PHP_EOL;"
```

---

## 4. Operational Monitoring & Health Check
Test server health endpoint:
```bash
curl -i https://your-domain.com/api/health.php
```

Expected HTTP 200 response:
```json
{
  "success": true,
  "status": "healthy",
  "database": "connected"
}
```
