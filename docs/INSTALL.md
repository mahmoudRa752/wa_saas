# Installation Guide

Follow these steps to deploy and run the WA SaaS Manager portal.

## 1. Quick Start via Docker (Recommended)

### Requirements:
- Install Docker and Docker Compose.

### Steps:
1. Copy `.env.example` to `.env` and set environment variables.
2. Build and launch:
   ```bash
   docker-compose up -d --build
   ```
3. Access the dashboard at `http://localhost/wa_saas/` or `http://localhost:port/`.

---

## 2. Manual Installation

### Steps:
1. **Web Server Setup**: Configure Apache or Nginx DocumentRoot to point to `/var/www/html/wa_saas/` with `mod_rewrite` enabled.
2. **Database Import**: Create a database in MySQL and import the schema script:
   ```bash
   mysql -u root -p wa_saas < chat_schema.sql
   ```
3. **Database Migration**: Run the migrations using command line to apply performance indexes:
   ```bash
   php cron/add_indexes.php
   ```
4. **Composer Installation**: Install dependencies using Composer:
   ```bash
   composer install --no-dev
   ```
5. **Scheduler Setup**: Run the campaign processor cron job once every minute:
   ```cron
   * * * * * php /var/www/html/cron/process_broadcasts.php >> /var/log/cron_broadcasts.log 2>&1
   ```
