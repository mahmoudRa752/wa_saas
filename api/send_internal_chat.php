<?php
/**
 * WA Manager — API: Send Internal Chat Message
 * File: api/send_internal_chat.php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonExit(['error' => 'POST method required'], 405);
}

$companyId = (int) $_SESSION['company_id'];
$myUserId  = (int) $_SESSION['user_id'];
$colleagueId = (int) ($_POST['colleague_id'] ?? 0);
$message   = trim($_POST['message'] ?? '');

if ($colleagueId <= 0 || $message === '') {
    jsonExit(['error' => 'colleague_id and message are required'], 400);
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

// Insert message
$stmt = $conn->prepare("
    INSERT INTO internal_messages (company_id, sender_id, receiver_id, message)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param("iiis", $companyId, $myUserId, $colleagueId, $message);
$stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($newId > 0) {
    jsonExit([
        'id'          => $newId,
        'sender_id'   => $myUserId,
        'receiver_id' => $colleagueId,
        'message'     => $message,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
} else {
    jsonExit(['error' => 'Failed to insert message'], 500);
}
