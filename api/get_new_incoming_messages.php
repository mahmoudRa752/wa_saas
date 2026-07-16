<?php
/**
 * WA Manager — API: Get New Incoming Messages (Notifications)
 * File: api/get_new_incoming_messages.php
 * Type: JSON API — Standalone
 */

ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

session_start();
require_once(__DIR__ . '/../config/db.php');

ob_clean();

header('Content-Type: application/json; charset=utf-8');

function jsonExit(array $data, int $code = 200): void {
    ob_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['company_id'])) {
    jsonExit(['error' => 'Unauthorized'], 401);
}

$companyId = (int) $_SESSION['company_id'];
$role      = $_SESSION['role'] ?? '';
$userId    = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$isAdmin   = ($role === 'admin');

$lastCheckTime = $_GET['last_check_time'] ?? '';

if (empty($lastCheckTime)) {
    // If no last_check_time is provided, default to 5 seconds ago
    $lastCheckTime = date('Y-m-d H:i:s', time() - 5);
}

// Ensure the timestamp is safe
$lastCheckTime = date('Y-m-d H:i:s', strtotime($lastCheckTime));
$serverTime = date('Y-m-d H:i:s');

// Build SQL with isolation guard
if ($isAdmin) {
    $stmt = $conn->prepare("
        SELECT cm.id, cm.conversation_id, cm.body, cm.sent_at, c.contact_number
        FROM chat_messages cm
        JOIN conversations c ON cm.conversation_id = c.id
        WHERE c.company_id = ?
          AND cm.direction = 'in'
          AND cm.sent_at > ?
        ORDER BY cm.id ASC
    ");
    $stmt->bind_param("is", $companyId, $lastCheckTime);
} else {
    $stmt = $conn->prepare("
        SELECT cm.id, cm.conversation_id, cm.body, cm.sent_at, c.contact_number
        FROM chat_messages cm
        JOIN conversations c ON cm.conversation_id = c.id
        WHERE c.company_id = ?
          AND cm.direction = 'in'
          AND cm.sent_at > ?
          AND (c.created_by = ? OR c.assigned_to = ? OR c.id IN (
              SELECT DISTINCT cm2.conversation_id FROM chat_messages cm2 WHERE cm2.user_id = ?
          ))
        ORDER BY cm.id ASC
    ");
    $stmt->bind_param("isiii", $companyId, $lastCheckTime, $userId, $userId, $userId);
}

$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$messages = [];
foreach ($result as $row) {
    $messages[] = [
        'id'              => (int) $row['id'],
        'conversation_id' => (int) $row['conversation_id'],
        'contact_number'  => $row['contact_number'],
        'body'            => $row['body'],
        'sent_at'         => $row['sent_at'],
    ];
}

jsonExit([
    'messages' => $messages,
    'server_time' => $serverTime
]);
