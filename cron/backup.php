<?php
/**
 * WA Manager — Production Database & Media Assets Backup Utility
 * File: cron/backup.php
 */
require_once(__DIR__ . '/../config/db.php');

$backupDir = __DIR__ . '/../backups';
$dbBackupDir = $backupDir . '/db';
$mediaBackupDir = $backupDir . '/media';

if (!is_dir($dbBackupDir)) {
    @mkdir($dbBackupDir, 0777, true);
}

$timestamp = date('Ymd_His');
$sqlFile = $dbBackupDir . "/backup_$timestamp.sql";

echo "Starting Database backup...\n";

$tables = [];
$res = $conn->query("SHOW TABLES");
while ($row = $res->fetch_row()) {
    $tables[] = $row[0];
}

$sqlContent = "-- WA SaaS Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tables as $table) {
    // Fetch schema creation
    $createRes = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
    $sqlContent .= "DROP TABLE IF EXISTS `$table`;\n";
    $sqlContent .= $createRes[1] . ";\n\n";
    
    // Fetch records
    $dataRes = $conn->query("SELECT * FROM `$table`");
    while ($row = $dataRes->fetch_assoc()) {
        $keys = array_keys($row);
        $escapedValues = array_map(function($val) use ($conn) {
            if ($val === null) return 'NULL';
            return "'" . $conn->real_escape_string($val) . "'";
        }, array_values($row));
        
        $sqlContent .= "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $escapedValues) . ");\n";
    }
    $sqlContent .= "\n\n";
}

file_put_contents($sqlFile, $sqlContent);
echo "✓ Database schema & tables data exported to: " . basename($sqlFile) . "\n";

// Backup uploads/ media
echo "Backing up media uploads...\n";
$uploadDir = __DIR__ . '/../uploads';

$zipFile = $backupDir . "/backup_$timestamp.zip";
$zip = new \ZipArchive();

if ($zip->open($zipFile, \ZipArchive::CREATE) === TRUE) {
    // Add SQL Dump file
    $zip->addFile($sqlFile, 'db/' . basename($sqlFile));
    
    // Add uploads/ contents
    if (is_dir($uploadDir)) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploadDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = 'uploads/' . substr($filePath, strlen($uploadDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }
    
    $zip->close();
    echo "✓ All database tables and uploaded media assets packed in: " . basename($zipFile) . "\n";
    
    // Delete raw sql dump
    @unlink($sqlFile);
} else {
    echo "✗ Failed to create compressed backup ZIP archive.\n";
}
