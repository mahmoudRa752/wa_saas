# Backup and Recovery Guide

This document describes how to back up and recover database records and media files for the WA SaaS application.

## 1. Automated Backups

A unified backup script is provided in [backup.php](file:///c:/xampp/htdocs/wa_saas/cron/backup.php). It:
1. Loops through all MySQL tables, serializes schemas, and dumps inserts to a SQL script.
2. Packages the SQL dump along with all uploaded files (`uploads/`) into a compressed `.zip` archive inside the `backups/` directory.

### Schedule Backup Cron
To schedule daily backups (e.g. at 2 AM every day), add this to your system crontab:
```cron
0 2 * * * php /var/www/html/cron/backup.php >> /var/log/backup.log 2>&1
```

---

## 2. Recovery Steps

In the event of database failure or migration:

### Steps:
1. Locate the backup ZIP file in `backups/`.
2. Extract the archive:
   ```bash
   unzip backups/backup_[timestamp].zip -d /tmp/backup_extracted
   ```
3. Import the SQL file back to MySQL:
   ```bash
   mysql -u root -p wa_saas < /tmp/backup_extracted/db/backup_[timestamp].sql
   ```
4. Copy the `uploads/` contents back to your web application uploads path:
   ```bash
   cp -r /tmp/backup_extracted/uploads/* /var/www/html/wa_saas/uploads/
   ```
5. Adjust file permissions to ensure the web server can write to the directory:
   ```bash
   chown -R www-data:www-data /var/www/html/wa_saas/uploads
   ```
