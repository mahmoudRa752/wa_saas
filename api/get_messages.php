<?php
/**
 * WA Manager — API: Get Chat Messages
 * File: api/get_messages.php
 * Type: JSON API — Standalone (no layouts)
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
$convId    = (int) ($_GET['conversation_id'] ?? 0);
$afterId   = (int) ($_GET['after_id']        ?? 0);

if ($convId <= 0) {
    jsonExit(['error' => 'conversation_id required'], 400);
}

// التحقق من الملكية
$check = $conn->prepare("SELECT id FROM conversations WHERE id = ? AND company_id = ? LIMIT 1");
$check->bind_param('ii', $convId, $companyId);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    jsonExit(['error' => 'Forbidden'], 403);
}
$check->close();

// جلب الرسائل الجديدة فقط
$stmt = $conn->prepare("
    SELECT
        cm.id,
        cm.direction,
        cm.body,
        cm.sent_at,
        u.name AS sender_name
    FROM chat_messages cm
    LEFT JOIN users u ON cm.user_id = u.id
    WHERE cm.conversation_id = ?
      AND cm.id > ?
    ORDER BY cm.sent_at ASC
    LIMIT 50
");
$stmt->bind_param('ii', $convId, $afterId);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id'          => (int) $row['id'],
        'direction'   => $row['direction'],
        'body'        => $row['body'],
        'sent_at'     => $row['sent_at'],
        'sender_name' => $row['sender_name'] ?? 'You',
    ];
}
$stmt->close();

jsonExit(['messages' => $messages]);
