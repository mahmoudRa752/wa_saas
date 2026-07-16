<?php
/**
 * WA Manager — API: Get Internal Chat Messages
 * File: api/get_internal_messages.php
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

if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    jsonExit(['error' => 'Unauthorized'], 401);
}

$companyId = (int) $_SESSION['company_id'];
$myUserId  = (int) $_SESSION['user_id'];
$colleagueId = (int) ($_GET['colleague_id'] ?? 0);
$afterId   = (int) ($_GET['after_id'] ?? 0);

if ($colleagueId <= 0) {
    jsonExit(['error' => 'colleague_id required'], 400);
}

// Verify that the colleague belongs to the same company
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND company_id = ? LIMIT 1");
$stmt->bind_param("ii", $colleagueId, $companyId);
$stmt->execute();
$colleagueExists = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$colleagueExists) {
    jsonExit(['error' => 'Colleague not found in your company'], 404);
}

// Fetch messages
$stmt = $conn->prepare("
    SELECT im.id, im.sender_id, im.receiver_id, im.message, im.created_at, u.name AS sender_name
    FROM internal_messages im
    JOIN users u ON im.sender_id = u.id
    WHERE im.company_id = ?
      AND ((im.sender_id = ? AND im.receiver_id = ?) OR (im.sender_id = ? AND im.receiver_id = ?))
      AND im.id > ?
    ORDER BY im.id ASC
");
$stmt->bind_param("iiiiiii", $companyId, $myUserId, $colleagueId, $colleagueId, $myUserId, $afterId);
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Mark received messages as read
$stmt = $conn->prepare("
    UPDATE internal_messages 
    SET is_read = 1 
    WHERE company_id = ? AND sender_id = ? AND receiver_id = ? AND is_read = 0
");
$stmt->bind_param("iii", $companyId, $colleagueId, $myUserId);
$stmt->execute();
$stmt->close();

$messages = [];
foreach ($result as $row) {
    $messages[] = [
        'id'          => (int) $row['id'],
        'sender_id'   => (int) $row['sender_id'],
        'receiver_id' => (int) $row['receiver_id'],
        'message'     => $row['message'],
        'created_at'  => $row['created_at'],
        'sender_name' => $row['sender_name'],
    ];
}

jsonExit(['messages' => $messages]);
