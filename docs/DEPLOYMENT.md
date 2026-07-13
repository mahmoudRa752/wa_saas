# Production Deployment Guide

This document describes how to deploy the WA SaaS application to a production environment.

## 1. System Requirements

Ensure the server meets the following specifications:
- **PHP**: Version 8.1 or 8.2.
- **Web Server**: Apache (with `mod_rewrite` enabled) or Nginx.
- **Database**: MySQL 5.7+ or MariaDB 10.3+.
- **Required PHP Extensions**:
  - `mysqli` (database driver)
  - `gd` (required for image processing and PhpSpreadsheet)
  - `zip` (required for Excel file processing)
  - `openssl` (required for secure cURL requests to Meta API)
  - `opcache` (strongly recommended for production optimization)

---

## 2. Docker Deployment (Recommended)

The easiest way to deploy is using the provided Docker configuration.

### Steps:
1. Clone the repository to the production server.
2. Edit `.env` to configure your credentials.
3. Build and launch the containers:
   ```bash
   docker-compose up -d --build
   ```
4. Verify that Apache is running on port `80` and MySQL on port `3307` (or custom mapped ports).

---

## 3. Manual Web Server Deployment

If you are deploying manually (e.g. on Ubuntu LTS or cPanel VPS):

### Apache Setup
Ensure `.htaccess` file is processed by setting `AllowOverride All` in your Apache VirtualHost configuration:
```apache
<VirtualHost *:80>
    ServerName wa-saas.yourdomain.com
    DocumentRoot /var/www/html/wa_saas

    <Directory /var/www/html/wa_saas>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Database Initialization
Import the schema script into your MySQL database:
```bash
mysql -u root -p wa_saas < chat_schema.sql
```

---

## 4. Background Cron Setup

The application features a background broadcast campaign worker. You must register a system cron job to run this processor once every minute:

1. Open your crontab editor:
   ```bash
   crontab -e
   ```
2. Append the following job line (adjust paths to match your environment):
   ```cron
   * * * * * php /var/www/html/cron/process_broadcasts.php >> /var/log/cron_broadcasts.log 2>&1
   ```

---

## 5. Security Checklist
- [ ] Rename `.env.example` to `.env` and set secure passwords.
- [ ] Mute PHP errors display in production (`display_errors = Off` in `php.ini`).
- [ ] Force HTTPS using custom redirect rules in `.htaccess`.
