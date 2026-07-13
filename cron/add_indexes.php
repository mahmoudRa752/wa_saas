<?php
/**
 * WA Manager — Performance Index Migration Utility
 * File: cron/add_indexes.php
 */
require_once(__DIR__ . '/../config/db.php');

echo "Starting Performance Database Index migrations...\n";

/**
 * Add index safely and idempotently
 */
function addIndex($conn, $table, $indexName, $columns) {
    $check = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($check && $check->num_rows === 0) {
        if ($conn->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)")) {
            echo "✓ Index '$indexName' added to table '$table'.\n";
        } else {
            echo "✗ Failed to add index '$indexName': " . $conn->error . "\n";
        }
    } else {
        echo "• Index '$indexName' already exists on table '$table'.\n";
    }
}

addIndex($conn, 'conversations', 'idx_conv_company_status', 'company_id, status');
addIndex($conn, 'conversations', 'idx_conv_assignee', 'assigned_to');
addIndex($conn, 'conversations', 'idx_conv_last_msg', 'last_message_at');
addIndex($conn, 'chat_messages', 'idx_msg_conv_direction', 'conversation_id, direction');
addIndex($conn, 'chat_messages', 'idx_msg_sent_at', 'sent_at');
addIndex($conn, 'conversation_tags', 'idx_conv_tags_composite', 'conversation_id, tag_id');
addIndex($conn, 'company_audit_logs', 'idx_audit_company', 'company_id');
addIndex($conn, 'internal_messages', 'idx_internal_msg', 'sender_id, receiver_id');

echo "Index migrations completed successfully!\n";
