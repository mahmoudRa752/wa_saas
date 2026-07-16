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
require_once(__DIR__ . '/../core/Conversation/ConversationRepository.php');
require_once(__DIR__ . '/../core/Conversation/ConversationService.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageRepository.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageService.php');

use Core\Conversation\ConversationRepository;
use Core\Conversation\ConversationService;
use Core\ChatMessage\ChatMessageRepository;
use Core\ChatMessage\ChatMessageService;

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
$convService = new ConversationService(new ConversationRepository($conn));
$conv = $convService->getForCompany($convId, $companyId);

if (!$conv) {
    jsonExit(['error' => 'Forbidden'], 403);
}

// جلب الرسائل الجديدة فقط
$chatMsgService = new ChatMessageService(new ChatMessageRepository($conn));
$rawMessages = $chatMsgService->getMessagesSince($convId, $afterId);

$messages = [];
foreach ($rawMessages as $row) {
    $messages[] = [
        'id'          => (int) $row['id'],
        'direction'   => $row['direction'],
        'body'        => $row['body'],
        'sent_at'     => $row['sent_at'],
        'sender_name' => $row['sender_name'] ?? 'You',
    ];
}

jsonExit(['messages' => $messages]);
