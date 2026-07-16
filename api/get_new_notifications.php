<?php
/**
 * WA Manager — Fetch Realtime Routing Notifications
 * File: api/get_new_notifications.php
 */
session_start();
require_once("../config/db.php");

header("Content-Type: application/json");

if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$companyId = (int)$_SESSION['company_id'];

// Query unread notifications
$stmt = $conn->prepare("
    SELECT id, type, message, created_at 
    FROM notifications 
    WHERE company_id = ? AND user_id = ? AND is_read = 0 
    ORDER BY created_at ASC
");
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => "SQL Prepare Failed"]);
    exit;
}
$stmt->bind_param("ii", $companyId, $userId);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Mark as read
if (!empty($notifications)) {
    $ids = array_column($notifications, 'id');
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    $updateStmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ($inClause)");
    if ($updateStmt) {
        $types = str_repeat('i', count($ids));
        $updateStmt->bind_param($types, ...$ids);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

echo json_encode([
    "status" => "success",
    "notifications" => $notifications
]);
exit;
