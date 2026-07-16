<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Conversation/Conversation.php');
require_once(__DIR__ . '/../core/Conversation/ConversationRepository.php');
require_once(__DIR__ . '/../core/Conversation/ConversationService.php');
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/SavedReply/SavedReply.php');
require_once(__DIR__ . '/../core/SavedReply/SavedReplyRepository.php');
require_once(__DIR__ . '/../core/SavedReply/SavedReplyService.php');
require_once(__DIR__ . '/../core/ConversationNote/ConversationNote.php');
require_once(__DIR__ . '/../core/ConversationNote/ConversationNoteRepository.php');
require_once(__DIR__ . '/../core/ConversationNote/ConversationNoteService.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageRepository.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageService.php');
require_once(__DIR__ . '/../core/Services/WhatsAppService.php');

use Core\Conversation\ConversationRepository;
use Core\Conversation\ConversationService;
use Core\ConversationNote\ConversationNoteRepository;
use Core\ConversationNote\ConversationNoteService;
use Core\SavedReply\SavedReplyRepository;
use Core\SavedReply\SavedReplyService;
use Core\ChatMessage\ChatMessageRepository;
use Core\ChatMessage\ChatMessageService;
use Core\Services\WhatsAppService;
use Core\TenantContext;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
use Core\Auth\CsrfHelper;

function generateConversationNoteCsrfToken(): string
{
    return CsrfHelper::generateToken('conversation_note');
}

function validateConversationNoteCsrfToken(?string $token): bool
{
    return CsrfHelper::validateToken($token, 'conversation_note');
}

$companyId    = (int) $_SESSION['company_id'];
$role         = $_SESSION['role'] ?? '';
$userId       = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$isAdmin      = ($role === 'admin');
$hasCreatedBy = true;

$tenantContext = TenantContext::fromSession();
$csrfToken = generateConversationNoteCsrfToken();
$savedReplyService = new SavedReplyService(new SavedReplyRepository($conn));
$conversationNoteService = new ConversationNoteService(new ConversationNoteRepository($conn));
$savedReplies = $savedReplyService->list($tenantContext);
$conversationNotes = [];

$convRepo = new ConversationRepository($conn);

// ── 1. Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // إنشاء محادثة جديدة (via ConversationService)
    if ($action === 'new_chat') {
        $newNumber = trim($_POST['new_number']);
        if (!empty($newNumber)) {
            $convService = new ConversationService($convRepo);
            $newId       = $convService->findOrCreate($companyId, $newNumber, $userId);
            header("Location: chat.php?conv=" . $newId);
            exit;
        }
    }

    if ($action === 'saved_reply_create') {
        $title = trim($_POST['saved_reply_title'] ?? '');
        $body = trim($_POST['saved_reply_body'] ?? '');
        if ($title !== '' && $body !== '') {
            $savedReplyService->create($tenantContext, $title, $body, $userId);
        }
        header("Location: chat.php" . (isset($_GET['conv']) ? "?conv=" . (int) $_GET['conv'] : ""));
        exit;
    }

    if ($action === 'saved_reply_delete') {
        $savedReplyId = (int) ($_POST['saved_reply_id'] ?? 0);
        if ($savedReplyId > 0) {
            $savedReplyService->delete($tenantContext, $savedReplyId);
        }
        header("Location: chat.php" . (isset($_GET['conv']) ? "?conv=" . (int) $_GET['conv'] : ""));
        exit;
    }

    if ($action === 'conversation_note_create') {
        if (!validateConversationNoteCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit;
        }

        $noteBody = trim($_POST['conversation_note_body'] ?? '');
        $convId = (int) ($_POST['conversation_id'] ?? 0);
        if ($convId > 0 && $noteBody !== '' && $convRepo->checkAccess($convId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $conversationNoteService->create($tenantContext, $convId, $noteBody, $userId);
        }
        header("Location: chat.php?conv=" . $convId);
        exit;
    }

    if ($action === 'conversation_note_update') {
        if (!validateConversationNoteCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit;
        }

        $noteId = (int) ($_POST['conversation_note_id'] ?? 0);
        $noteBody = trim($_POST['conversation_note_body'] ?? '');
        if ($noteId > 0 && $noteBody !== '') {
            $note = $conversationNoteService->getById($tenantContext, $noteId);
            if ($note && $convRepo->checkAccess($note->conversationId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
                $conversationNoteService->update($tenantContext, $noteId, $noteBody);
            }
        }
        header("Location: chat.php" . (isset($_GET['conv']) ? "?conv=" . (int) $_GET['conv'] : ""));
        exit;
    }

    if ($action === 'conversation_note_delete') {
        if (!validateConversationNoteCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit;
        }

        $noteId = (int) ($_POST['conversation_note_id'] ?? 0);
        if ($noteId > 0) {
            $note = $conversationNoteService->getById($tenantContext, $noteId);
            if ($note && $convRepo->checkAccess($note->conversationId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
                $conversationNoteService->delete($tenantContext, $noteId);
            }
        }
        header("Location: chat.php" . (isset($_GET['conv']) ? "?conv=" . (int) $_GET['conv'] : ""));
        exit;
    }

    // تثبيت / إلغاء تثبيت المحادثة
    if ($action === 'toggle_pin') {
        $cId = (int) $_POST['conv_id'];
        $status = (int) $_POST['pin_status'];
        if ($convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService($convRepo);
            $convService->updatePin($cId, $companyId, $status);
        }
        header("Location: chat.php" . (isset($_GET['conv']) ? "?conv=" . (int) $_GET['conv'] : ""));
        exit;
    }

    // تعديل اسم أو رقم المحادثة الجانبية
    if ($action === 'edit_chat') {
        $cId = (int) $_POST['conv_id'];
        $updatedNumber = trim($_POST['edit_number']);
        if ($convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService($convRepo);
            $convService->updateContactNumber($cId, $companyId, $updatedNumber);
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    // حذف المحادثة بالكامل ورسائلها محلياً
    if ($action === 'delete_chat') {
        $cId = (int) $_POST['conv_id'];
        if ($convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService($convRepo);
            $convService->delete($cId, $companyId);
        }
        header("Location: chat.php");
        exit;
    }

    // ── تعديل رسالة معينة لتسمع عند الطرف الآخر ──
    if ($action === 'edit_message') {
        $msgId = (int) $_POST['message_id'];
        $newBody = trim($_POST['message_body']);
        $cId = (int) $_GET['conv'];

        if ($convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $chatMsgService = new ChatMessageService(new ChatMessageRepository($conn));
            $wamid = $chatMsgService->getWamid($msgId);

            if ($wamid) {
                $accessToken = "EAAZBGtwMbSu0BRxJrTffY3W0l3G3h6DVnGL3ZAkpOHemZC4VpKb937MrA112fy5VdrNCeWiyTqCZAACzoCoA7M3Pon9ZBP5syGIHBi6JmLOKEhZAGB06sxEZBD9yW8y0eVMAKzp3iwi4e7uq6m7rXN3ZBI9sZBDQNaXhCyq7A889DZA0BlHvZAZBQw3U0FzNrmKFg24g8Qx6u20ZAcfmdUwZB5Ekt7iWIpO5yceRFH1hocqmG9ZApM9cX1UFElPmXfltYutkk7ELeJ9anO0Mc60i2EqxBlt2gZDZD";
                $phoneNumberId = "1165715256628007";
                $whatsAppService = new WhatsAppService();
                $whatsAppService->editMessage($phoneNumberId, $wamid, $newBody, $accessToken);
            }

            $chatMsgService->updateBody($msgId, $newBody);
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    // ── حذف رسالة معينة من الطرفين (Delete for everyone) ──
    if ($action === 'delete_message_everyone') {
        $msgId = (int) $_POST['message_id'];
        $cId = (int) $_GET['conv'];

        if ($convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $chatMsgService = new ChatMessageService(new ChatMessageRepository($conn));
            $wamid = $chatMsgService->getWamid($msgId);

            if ($wamid) {
                $accessToken = "EAAZBGtwMbSu0BR45Cvl2BYZCkupVr6DgwytZAt7sXfuBdyQy8bmlyLfKOuVmERuGXZCjtmt7wkgRKRcPUWLajhIrhi6ZCSU50SBzfjUsEw1aIZCSqwbhYIkdTEDd2WA0OZBDH4mYjoaaoQgxjTZCAy0y9akeZAZAwQgcUxkYxuaE2k3uCflKVz6wmZB33Twk5VoBzwaY8kkaBStt8iX5ZA1BCV9XZBb1G1ip7RXMZCp7dE6ZC2v8MvL3rxK6ZCoHtQi6njFGHRrV98rC4s4vwc9OP2m1nbDl";
                $phoneNumberId = "1165715256628007";
                $whatsAppService = new WhatsAppService();
                $whatsAppService->deleteMessage($phoneNumberId, $wamid, $accessToken);
            }

            $chatMsgService->delete($msgId);
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    // Update conversation assignee
    if ($action === 'update_assignee') {
        $cId = (int) $_POST['conv_id'];
        $assignedTo = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int) $_POST['assigned_to'] : null;
        if ($convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService($convRepo);
            $convService->updateAssignee($cId, $companyId, $assignedTo);

            require_once(__DIR__ . '/../core/Services/AuditLogService.php');
            $auditService = new \Core\Services\AuditLogService($conn);
            $auditService->log($companyId, $_SESSION['user_id'] ?? null, 'assign_chat', "Assigned conversation ID $cId to employee ID " . ($assignedTo ?? 'unassigned'));
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    // Update conversation status
    if ($action === 'update_status') {
        $cId = (int) $_POST['conv_id'];
        $status = trim($_POST['status']);
        if (in_array($status, ['open', 'pending', 'closed']) && $convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService($convRepo);
            $convService->updateStatus($cId, $companyId, $status);

            require_once(__DIR__ . '/../core/Services/AuditLogService.php');
            $auditService = new \Core\Services\AuditLogService($conn);
            $auditService->log($companyId, $_SESSION['user_id'] ?? null, 'status_change', "Updated conversation ID $cId status to $status");
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    // Create a new tag
    if ($action === 'create_tag') {
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagColor = trim($_POST['tag_color'] ?? '#6366f1');
        if ($tagName !== '') {
            $stmt = $conn->prepare("INSERT IGNORE INTO tags (company_id, name, color) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $companyId, $tagName, $tagColor);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: chat.php" . (isset($_GET['conv']) ? "?conv=" . (int) $_GET['conv'] : ""));
        exit;
    }

    // Attach tag to conversation
    if ($action === 'attach_tag') {
        $cId = (int) $_POST['conv_id'];
        $tagId = (int) $_POST['tag_id'];
        if ($cId > 0 && $tagId > 0 && $convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $stmt = $conn->prepare("INSERT IGNORE INTO conversation_tags (conversation_id, tag_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $cId, $tagId);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    // Detach tag from conversation
    if ($action === 'detach_tag') {
        $cId = (int) $_POST['conv_id'];
        $tagId = (int) $_POST['tag_id'];
        if ($cId > 0 && $tagId > 0 && $convRepo->checkAccess($cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $stmt = $conn->prepare("DELETE FROM conversation_tags WHERE conversation_id = ? AND tag_id = ?");
            $stmt->bind_param("ii", $cId, $tagId);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    if ($action === 'create_segment') {
        $segmentName = trim($_POST['segment_name'] ?? '');
        $statusVal = trim($_POST['status'] ?? '');
        $assigneeVal = trim($_POST['assigned_to'] ?? '');
        $tagVal = isset($_POST['tag_id']) && $_POST['tag_id'] !== '' ? (int)$_POST['tag_id'] : null;
        
        $criteria = [];
        if ($statusVal !== '') $criteria['status'] = $statusVal;
        if ($assigneeVal !== '') $criteria['assigned_to'] = $assigneeVal;
        if ($tagVal > 0) $criteria['tag_id'] = $tagVal;
        
        $criteriaJson = json_encode($criteria);
        
        if ($segmentName !== '') {
            $stmt = $conn->prepare("INSERT INTO segments (company_id, name, filter_criteria) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $companyId, $segmentName, $criteriaJson);
            $stmt->execute();
            $stmt->close();

            require_once(__DIR__ . '/../core/Services/AuditLogService.php');
            $auditService = new \Core\Services\AuditLogService($conn);
            $auditService->log($companyId, $_SESSION['user_id'] ?? null, 'create_segment', "Created smart filter segment: $segmentName");
        }
        header("Location: chat.php");
        exit;
    }

    if ($action === 'delete_segment') {
        $segmentId = (int)$_POST['segment_id'];
        if ($segmentId > 0) {
            $stmt = $conn->prepare("DELETE FROM segments WHERE id = ? AND company_id = ?");
            $stmt->bind_param("ii", $segmentId, $companyId);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: chat.php");
        exit;
    }
}

// ── 2. Get status filtering, segments and conversations ──
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['open', 'pending', 'closed']) ? $_GET['status'] : null;

// Resolve segment criteria
$segmentCriteria = null;
$activeSegmentId = isset($_GET['segment']) ? (int)$_GET['segment'] : 0;
if ($activeSegmentId > 0) {
    $stmt = $conn->prepare("SELECT filter_criteria FROM segments WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("ii", $activeSegmentId, $companyId);
    $stmt->execute();
    if ($segRow = $stmt->get_result()->fetch_assoc()) {
        $segmentCriteria = json_decode($segRow['filter_criteria'], true);
    }
    $stmt->close();
}

$conversations = $convRepo->listWithDetails($companyId, $isAdmin, $userId, $hasCreatedBy, 50, $statusFilter, $segmentCriteria);

$activeConvId = isset($_GET['conv']) ? (int) $_GET['conv'] : ($conversations[0]['id'] ?? 0);
$activeConv = null;
$initMessages = [];

// Fetch segments list
$segmentsList = [];
$stmt = $conn->prepare("SELECT * FROM segments WHERE company_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$segmentsList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch employees and tags for assignment/segment creation modals (always loaded)
$employees = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE company_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$companyTags = [];
$stmt = $conn->prepare("SELECT * FROM tags WHERE company_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$companyTags = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activeConvTags = [];
$stats = ['sent_count' => 0, 'rcv_count' => 0, 'first_contact' => 'N/A'];

if ($activeConvId > 0) {
    $activeConv = $convRepo->getActiveConversation($activeConvId, $companyId, $isAdmin, $userId, $hasCreatedBy);

    if ($activeConv) {
        $chatMsgService = new ChatMessageService(new ChatMessageRepository($conn));
        $initMessages = $chatMsgService->listForConversation($activeConvId, 50);

        $conversationNotes = $conversationNoteService->listForConversation($tenantContext, $activeConvId);

        // Fetch active conversation tags
        $stmt = $conn->prepare("
            SELECT t.* FROM tags t 
            JOIN conversation_tags ct ON t.id = ct.tag_id 
            WHERE ct.conversation_id = ?
        ");
        $stmt->bind_param("i", $activeConvId);
        $stmt->execute();
        $activeConvTags = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch statistics
        $stmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN direction = 'out' THEN 1 END) as sent_count,
                COUNT(CASE WHEN direction = 'in' THEN 1 END) as rcv_count,
                MIN(sent_at) as first_contact
            FROM chat_messages 
            WHERE conversation_id = ?
        ");
        $stmt->bind_param("i", $activeConvId);
        $stmt->execute();
        if ($res = $stmt->get_result()->fetch_assoc()) {
            $stats['sent_count'] = (int) $res['sent_count'];
            $stats['rcv_count'] = (int) $res['rcv_count'];
            $stats['first_contact'] = $res['first_contact'] ? date('Y-m-d H:i', strtotime($res['first_contact'])) : 'N/A';
        }
        $stmt->close();
    } else {
        $activeConvId = 0;
    }
}

$lastMsgId = 0;
if (!empty($initMessages)) {
    $ids = array_column($initMessages, 'id');
    $lastMsgId = max($ids);
}

include("../layouts/header.php");
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.chat-wrapper { display: flex; height: calc(100vh - 120px); background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; position: relative; }
.conv-list { width: 340px; min-width: 340px; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; background: #fff; }
.conv-list-header { padding: 12px 16px; font-weight: 700; font-size: 16px; border-bottom: 1px solid #e2e8f0; color: #111b21; display: flex; justify-content: space-between; align-items: center; background: #f0f2f5; }
.search-chat-bar { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; background: #fff; display: flex; gap: 8px; align-items: center; }
.search-input-wrapper { flex: 1; background: #f0f2f5; border-radius: 8px; padding: 4px 10px; display: flex; align-items: center; gap: 8px; }
.search-input-wrapper input { border: none; background: transparent; width: 100%; outline: none; font-size: 13px; color: #111b21; }
.btn-new-chat { background: #00a884; color: white; border: none; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.conv-list-body { flex: 1; overflow-y: auto; background: #fff; }
.conv-item { display: flex; align-items: center; gap: 12px; padding: 13px 16px; cursor: pointer; border-bottom: 1px solid #f8f9fa; text-decoration: none; transition: background .15s; position: relative; }
.conv-item:hover { background: #f5f6f6; }
.conv-item.active { background: #eaeaea; }
.conv-avatar { width: 44px; height: 44px; border-radius: 50%; background: #00a884; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 14px; flex-shrink: 0; }
.conv-info { flex: 1; min-width: 0; }
.conv-name { font-size: 14px; font-weight: 600; color: #111b21; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.conv-preview { font-size: 13px; color: #667781; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.conv-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; position: relative; }
.conv-time { font-size: 11px; color: #667781; }
.unread-badge { background: #00a884; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.conv-actions-dropdown { display: none; position: absolute; right: 10px; bottom: 2px; background: transparent; border: none; color: #8696a0; cursor: pointer; font-size: 18px; }
.conv-item:hover .conv-actions-dropdown { display: block; }
.dropdown-menu-wa { display: none; position: absolute; top: 25px; right: 0; background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; min-width: 150px; }
.dropdown-menu-wa button { background: none; border: none; width: 100%; text-align: left; padding: 10px 12px; font-size: 13px; color: #111b21; cursor: pointer; display: flex; align-items: center; gap: 8px; }
.dropdown-menu-wa button:hover { background: #f5f6f6; }

.chat-window { flex: 1; display: flex; flex-direction: column; min-width: 0; background-color: #efeae2; background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); }
.chat-header { padding: 10px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; background: #f0f2f5; z-index: 10; }
.chat-header-left { display: flex; align-items: center; gap: 12px; }
.chat-header-avatar { width: 40px; height: 40px; border-radius: 50%; background: #6366f1; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
.chat-header-info .name { font-size: 15px; font-weight: 600; color: #111b21; display: flex; align-items: center; gap: 8px; }
.quick-replies-bar { padding: 8px 16px; background: #f0f2f5; border-bottom: 1px solid #e2e8f0; display: flex; gap: 8px; overflow-x: auto; }
.quick-reply-btn { background: #fff; border: 1px solid #d1d7db; border-radius: 16px; padding: 4px 12px; font-size: 12.5px; color: #111b21; cursor: pointer; white-space: nowrap; }
.chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column-reverse; gap: 12px; }
.msg-row { display: flex; width: 100%; position: relative; }
.msg-row.in { justify-content: flex-start; }
.msg-row.out { justify-content: flex-end; }
.msg-bubble { max-width: 60%; padding: 8px 10px; border-radius: 8px; font-size: 14px; line-height: 1.4; word-break: break-word; display: flex; flex-direction: column; position: relative; box-shadow: 0 1px 0.5px rgba(11,20,26,.13); }
.msg-row.in .msg-bubble { background: #fff; border-top-left-radius: 0; color: #111b21; }
.msg-row.out .msg-bubble { background: #d9fdd3; border-top-right-radius: 0; color: #111b21; }
.msg-options-trigger { display: none; position: absolute; left: -20px; top: 2px; background: transparent; border: none; color: #8696a0; cursor: pointer; font-size: 14px; }
.msg-row.out .msg-options-trigger { left: auto; right: -20px; }
.msg-bubble:hover .msg-options-trigger { display: block; }
.msg-dropdown { display: none; position: absolute; top: 22px; left: -10px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 999; min-width: 120px; }
.msg-row.out .msg-dropdown { left: auto; right: -10px; }
.msg-dropdown button { background: none; border: none; width: 100%; text-align: right; padding: 6px 10px; font-size: 12px; color: #111b21; cursor: pointer; display: flex; align-items: center; gap: 6px; }
.msg-dropdown button:hover { background: #f5f6f6; }
.msg-media-img { max-width: 100%; max-height: 250px; border-radius: 6px; object-fit: cover; margin-bottom: 4px; cursor: pointer; }
.msg-media-doc { display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.05); padding: 8px; border-radius: 6px; text-decoration: none; color: #111b21; margin-bottom: 4px; }
.msg-media-sticker { width: 120px; height: 120px; object-fit: contain; }
.msg-time { font-size: 11px; color: #667781; margin-top: 2px; text-align: right; display: flex; align-items: center; justify-content: flex-end; gap: 3px; }
.chat-input-area { padding: 10px 16px; background: #f0f2f5; display: flex; gap: 12px; align-items: center; z-index: 10; position: relative; }
.input-action-btn { background: transparent; border: none; color: #54656f; font-size: 22px; cursor: pointer; }
.chat-input-container { flex: 1; background: #fff; border-radius: 8px; padding: 6px 12px; display: flex; align-items: center; }
.chat-input-container textarea { flex: 1; border: none; resize: none; max-height: 100px; outline: none; font-size: 15px; font-family: inherit; line-height: 1.3; padding: 4px 0; }
.send-btn-wa { background: transparent; border: none; color: #54656f; font-size: 22px; cursor: pointer; }
.native-emoji-picker { position: absolute; bottom: 65px; left: 16px; width: 280px; height: 220px; background: #fff; border: 1px solid #ccc; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: none; flex-direction: column; z-index: 999; }
.emoji-picker-header { padding: 8px; background: #f0f2f5; font-size: 12px; font-weight: bold; color: #667781; border-bottom: 1px solid #e2e8f0; border-top-left-radius: 10px; border-top-right-radius: 10px; }
.emoji-picker-grid { flex: 1; overflow-y: auto; padding: 8px; display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; font-size: 22px; text-align: center; }
.emoji-item { cursor: pointer; user-select: none; }
.chat-empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #667781; gap: 12px; background: #f8f9fa; border-bottom: 6px solid #00a884; }
.chat-empty-state i { font-size: 80px; color: #ced5d8; }
#fileInput { display: none; }
.saved-replies-panel { padding: 10px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.saved-replies-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #334155; }
.saved-replies-new-btn { border: none; background: #2563eb; color: #fff; border-radius: 999px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
.saved-replies-list { display: flex; flex-wrap: wrap; gap: 8px; }
.saved-reply-item-row { display: inline-flex; align-items: center; gap: 6px; }
.saved-reply-item { border: 1px solid #dbeafe; background: #fff; color: #1e3a8a; border-radius: 999px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
.saved-reply-delete { border: none; background: #fee2e2; color: #b91c1c; border-radius: 999px; width: 22px; height: 22px; cursor: pointer; font-size: 12px; }
.saved-reply-modal-body { display: flex; flex-direction: column; gap: 10px; }
.saved-reply-modal-body input, .saved-reply-modal-body textarea { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }

/* ── Collapsible Right Customer Profile Panel ── */
.profile-panel { width: 300px; min-width: 300px; border-left: 1px solid #e2e8f0; background: #f8fafc; display: flex; flex-direction: column; height: 100%; transition: all 0.3s ease; }
.profile-panel.collapsed { width: 0; min-width: 0; border-left: none; overflow: hidden; }
.profile-header { padding: 12px 16px; font-weight: 700; font-size: 15px; border-bottom: 1px solid #e2e8f0; background: #f0f2f5; display: flex; justify-content: space-between; align-items: center; color: #111b21; }
.profile-body { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 20px; }
.profile-section { border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; }
.profile-section:last-child { border-bottom: none; }
.profile-section-title { font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.05em; display: flex; justify-content: space-between; align-items: center; }
.stats-table { width: 100%; font-size: 13px; }
.stats-table td { padding: 4px 0; color: #334155; }
.stats-table td.val { text-align: right; font-weight: 600; color: #0f172a; }
.tags-list { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.tag-badge { font-size: 11px; padding: 3px 8px; border-radius: 12px; color: #fff; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
.tag-badge form { display: inline; }
.tag-badge button { border: none; background: transparent; color: #fff; font-size: 10px; cursor: pointer; padding: 0 0 0 4px; line-height: 1; }
.notes-list { display: flex; flex-direction: column; gap: 8px; max-height: 250px; overflow-y: auto; }
.note-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 12.5px; color: #334155; }
.note-item small { color: #64748b; display: block; margin-top: 4px; }
.note-actions { display: flex; gap: 6px; margin-top: 6px; }
.note-actions button { border: none; background: #f1f5f9; color: #334155; border-radius: 999px; padding: 2px 6px; font-size: 11px; cursor: pointer; }
.note-actions button:hover { background: #e2e8f0; }
</style>
<div class="chat-wrapper">
    <div class="conv-list">
        <div class="conv-list-header">
            <span>Chats</span>
            <i class="bi bi-chat-left-text-fill" style="color: #54656f;"></i>
        </div>
        <div class="search-chat-bar">
            <div class="search-input-wrapper">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="convSearch" placeholder="Search numbers..." onkeyup="filterConversations()">
            </div>
            <button class="btn-new-chat" title="New Chat" onclick="toggleModal('newChatModal')">
                <i class="bi bi-plus-lg"></i>
            </button>
        </div>
        <!-- Filter Tabs -->
        <div class="px-2 pb-2 d-flex gap-1" style="border-bottom: 1px solid #e2e8f0; background: #fff;">
            <a href="chat.php" class="btn btn-sm btn-light flex-fill <?php echo ($statusFilter === null && $activeSegmentId === 0) ? 'fw-bold text-primary bg-white border border-primary' : ''; ?>" style="font-size:11px;">All</a>
            <a href="chat.php?status=open" class="btn btn-sm btn-light flex-fill <?php echo $statusFilter === 'open' ? 'fw-bold text-success bg-white border border-success' : ''; ?>" style="font-size:11px;">Open</a>
            <a href="chat.php?status=pending" class="btn btn-sm btn-light flex-fill <?php echo $statusFilter === 'pending' ? 'fw-bold text-warning bg-white border border-warning' : ''; ?>" style="font-size:11px;">Pending</a>
            <a href="chat.php?status=closed" class="btn btn-sm btn-light flex-fill <?php echo $statusFilter === 'closed' ? 'fw-bold text-secondary bg-white border border-secondary' : ''; ?>" style="font-size:11px;">Closed</a>
        </div>
        <!-- Segments List -->
        <?php if (!empty($segmentsList)): ?>
            <div class="px-2 py-1 bg-light border-bottom" style="font-size:11px; display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                <span class="text-secondary fw-bold me-1">Segments:</span>
                <?php foreach ($segmentsList as $seg): ?>
                    <?php
                    $isSegActive = ($activeSegmentId === (int)$seg['id']);
                    ?>
                    <span class="badge <?php echo $isSegActive ? 'bg-primary' : 'bg-secondary'; ?> d-inline-flex align-items-center gap-1" style="cursor:pointer; font-size:10px; padding: 3px 6px;" onclick="window.location.href='chat.php?segment=<?php echo $seg['id']; ?>'">
                        <?php echo htmlspecialchars($seg['name']); ?>
                        <form method="POST" action="" style="display:inline; margin:0;" onsubmit="event.stopPropagation(); return confirm('Delete this segment?');">
                            <input type="hidden" name="action" value="delete_segment">
                            <input type="hidden" name="segment_id" value="<?php echo $seg['id']; ?>">
                            <button type="submit" style="border:none; background:transparent; color:#fff; font-size:10px; padding:0; cursor:pointer; line-height:1;" onclick="event.stopPropagation();">&times;</button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="px-2 py-1 d-flex justify-content-between align-items-center bg-light border-bottom" style="font-size:11px;">
            <span class="text-secondary fw-semibold">Smart Filters</span>
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 fw-bold" style="font-size:11px;" onclick="toggleModal('createSegmentModal')">+ New Segment</button>
        </div>
        <div class="conv-list-body" id="convListBody">
            <?php if (empty($conversations)): ?>
                <div style="padding:40px; text-align:center; color:#667781;">No conversations found.</div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php
                    $isActive = ($conv['id'] == $activeConvId);
                    $preview = $conv['last_message'] ? mb_substr($conv['last_message'], 0, 30) . '...' : 'Media/Attachment';
                    $timeAgo = date('H:i', strtotime($conv['last_message_at']));
                    $st = $conv['status'] ?? 'open';
                    $badgeClass = ($st === 'open') ? 'bg-success' : (($st === 'pending') ? 'bg-warning text-dark' : 'bg-secondary');
                    ?>
                    <div class="conv-item <?php echo $isActive ? 'active' : ''; ?>"
                          data-number="<?php echo htmlspecialchars($conv['contact_number']); ?>"
                          onclick="if(!event.target.closest('.dropdown-zone')) window.location.href='?conv=<?php echo $conv['id']; ?><?php echo $statusFilter ? "&status=" . $statusFilter : ""; ?>'">
                        <div class="conv-avatar"><?php echo substr($conv['contact_number'], -2); ?></div>
                        <div class="conv-info">
                            <div class="conv-name">
                                <?php echo htmlspecialchars($conv['contact_number']); ?>
                                <span class="badge <?php echo $badgeClass; ?>" style="font-size:9px; padding: 2px 4px;"><?php echo htmlspecialchars(ucfirst($st)); ?></span>
                                <?php
                                if (!empty($conv['last_incoming_at'])) {
                                    $waitingSeconds = time() - strtotime($conv['last_incoming_at']);
                                    $waitingMinutes = max(0, round($waitingSeconds / 60));
                                    $slaClass = ($waitingMinutes >= 60) ? 'bg-danger text-white' : 'bg-warning text-dark';
                                    $slaText = ($waitingMinutes >= 60) ? 'Breached ' . round($waitingMinutes / 60, 1) . 'h' : 'SLA ' . $waitingMinutes . 'm';
                                    echo '<span class="badge ' . $slaClass . ' ms-1" style="font-size:9px; padding: 2px 4px;" title="Customer waiting duration"><i class="bi bi-clock me-1"></i>' . $slaText . '</span>';
                                }
                                ?>
                                <?php if ($conv['is_pinned']): ?>
                                    <i class="bi bi-pin-angle-fill text-secondary fs-6" title="Pinned"></i>
                                <?php endif; ?>
                            </div>
                            <div class="conv-preview"><?php echo htmlspecialchars($preview); ?></div>
                        </div>
                        <div class="conv-meta">
                            <span class="conv-time"><?php echo $timeAgo; ?></span>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <span class="unread-badge"><?php echo $conv['unread_count']; ?></span>
                            <?php endif; ?>
                            <div class="dropdown-zone" style="position:relative; z-index: 999;">
                                <button type="button" class="conv-actions-dropdown" style="display: block !important;" onclick="toggleDropdownMenu(event, <?php echo $conv['id']; ?>)">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <div class="dropdown-menu-wa" id="dropdown-<?php echo $conv['id']; ?>">
                                    <form method="POST" action="" style="margin:0;">
                                        <input type="hidden" name="conv_id" value="<?php echo $conv['id']; ?>">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="pin_status" value="<?php echo $conv['is_pinned'] ? '0' : '1'; ?>">
                                        <button type="submit"><i class="bi bi-pin-angle"></i> <?php echo $conv['is_pinned'] ? 'Unpin' : 'Pin Chat'; ?></button>
                                    </form>
                                    <button type="button" onclick="openEditModal(<?php echo $conv['id']; ?>, '<?php echo htmlspecialchars($conv['contact_number']); ?>')"><i class="bi bi-pencil"></i> Edit Details</button>
                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this conversation and all its messages?');" style="margin:0;">
                                        <input type="hidden" name="conv_id" value="<?php echo $conv['id']; ?>">
                                        <input type="hidden" name="action" value="delete_chat">
                                        <button type="submit" style="color:red;"><i class="bi bi-trash"></i> Delete Chat</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($activeConv): ?>
        <div class="chat-window">
            <div class="chat-header">
                <div class="chat-header-left">
                    <div class="chat-header-avatar"><i class="bi bi-person-fill"></i></div>
                    <div class="chat-header-info">
                        <div class="name">
                            <?php echo htmlspecialchars($activeConv['contact_number']); ?>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="/wa_saas/api/export_chat_excel.php?conv_id=<?php echo $activeConvId; ?>" class="btn btn-sm btn-outline-success" style="font-size:12px;" title="Export to Excel">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </a>
                    <a href="/wa_saas/dashboard/export_chat_print.php?conv_id=<?php echo $activeConvId; ?>" target="_blank" class="btn btn-sm btn-outline-danger" style="font-size:12px;" title="Export to PDF/Print">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" style="font-size:12px;" onclick="toggleProfilePanel()" title="Profile & Notes">
                        <i class="bi bi-person-badge"></i> Profile Info
                    </button>
                </div>
            </div>
            <div class="quick-replies-bar">
                <button class="quick-reply-btn" onclick="sendQuickReply('أهلاً بك في شركتنا! كيف يمكننا مساعدتك اليوم؟')">👋 رسالة ترحيبية عمومية</button>
                <button class="quick-reply-btn" onclick="sendQuickReply('نشكرك على تواصلك معنا، سيقوم أحد ممثلي خدمة العملاء بالرد عليك خلال دقائق.')">⏳ رسالة الانتظار</button>
            </div>
            <div class="chat-messages" id="chatMessages">
                <?php foreach ($initMessages as $msg): ?>
                    <div class="msg-row <?php echo $msg['direction']; ?>" data-id="<?php echo $msg['id']; ?>">
                        <div class="msg-bubble">
                            <button type="button" class="msg-options-trigger" onclick="toggleMsgDropdown(event, <?php echo $msg['id']; ?>)">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                            <div class="msg-dropdown" id="msg-drop-<?php echo $msg['id']; ?>">
                                <?php if ($msg['message_type'] === 'text'): ?>
                                    <button type="button" onclick="openEditMsgModal(<?php echo $msg['id']; ?>, '<?php echo htmlspecialchars($msg['body'], ENT_QUOTES); ?>')"><i class="bi bi-pencil"></i> تعديل للجميع</button>
                                <?php endif; ?>
                                <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذه الرسالة لدى الجميع؟');" style="margin:0;">
                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                    <input type="hidden" name="action" value="delete_message_everyone">
                                    <button type="submit" style="color:red;"><i class="bi bi-trash"></i> حذف للجميع</button>
                                </form>
                            </div>
                            <?php if ($msg['message_type'] === 'image'): ?>
                                <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" class="msg-media-img" onclick="window.open(this.src)">
                            <?php elseif ($msg['message_type'] === 'sticker'): ?>
                                <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" class="msg-media-sticker">
                            <?php elseif ($msg['message_type'] === 'document'): ?>
                                <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" target="_blank" class="msg-media-doc">
                                    <i class="bi bi-file-earmark-arrow-down-fill text-secondary fs-3"></i>
                                    <span>Download File</span>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($msg['body'])): ?>
                                <div class="msg-body-text"><?php echo nl2br(htmlspecialchars($msg['body'])); ?></div>
                            <?php endif; ?>
                            <div class="msg-time">
                                <?php echo date('H:i', strtotime($msg['sent_at'])); ?>
                                <?php if ($msg['direction'] === 'out'): ?>
                                    <i class="bi bi-check2-all text-primary"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="saved-replies-panel">
                <div class="saved-replies-header">
                    <span>Saved Replies</span>
                    <button type="button" class="saved-replies-new-btn" onclick="toggleModal('savedReplyModal')">+ New</button>
                </div>
                <div class="saved-replies-list">
                    <?php if (empty($savedReplies)): ?>
                        <span style="font-size:12px;color:#64748b;">No saved replies yet.</span>
                    <?php else: ?>
                        <?php foreach ($savedReplies as $reply): ?>
                            <div class="saved-reply-item-row">
                                <button type="button" class="saved-reply-item" data-body="<?php echo htmlspecialchars($reply->body, ENT_QUOTES); ?>" onclick="insertSavedReply(this)">
                                    <?php echo htmlspecialchars($reply->title); ?>
                                </button>
                                <form method="POST" action="" onsubmit="return confirm('Delete this saved reply?');" style="margin:0;">
                                    <input type="hidden" name="action" value="saved_reply_delete">
                                    <input type="hidden" name="saved_reply_id" value="<?php echo (int) $reply->id; ?>">
                                    <button type="submit" class="saved-reply-delete" title="Delete">×</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="chat-input-area">
                <div class="native-emoji-picker" id="nativeEmojiPicker">
                    <div class="emoji-picker-header">Emojis & Stickers</div>
                    <div class="emoji-picker-grid" id="emojiGrid"></div>
                </div>
                <button class="input-action-btn" id="emojiBtn" type="button"><i class="bi bi-emoji-smile"></i></button>
                <button class="input-action-btn" type="button" onclick="document.getElementById('fileInput').click()"><i class="bi bi-plus-lg"></i></button>
                <input type="file" id="fileInput" onchange="handleFileSelect(this)">
                <div class="chat-input-container"><textarea id="msgInput" placeholder="Type a message..."></textarea></div>
                <button class="send-btn-wa" id="sendBtn"><i class="bi bi-send-fill"></i></button>
            </div>
        </div>

        <!-- ── Collapsible Right Customer Profile Panel ── -->
        <div class="profile-panel" id="profilePanel">
            <div class="profile-header">
                <span>Customer Profile</span>
                <button type="button" class="btn-close text-reset" style="font-size:12px;" onclick="toggleProfilePanel()"></button>
            </div>
            <div class="profile-body">
                <!-- Status & Assignee Settings -->
                <div class="profile-section">
                    <div class="profile-section-title">Assignment & Status</div>
                    
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="conv_id" value="<?php echo $activeConvId; ?>">
                        <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Status</label>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="open" <?php echo $activeConv['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="pending" <?php echo $activeConv['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="closed" <?php echo $activeConv['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </form>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_assignee">
                        <input type="hidden" name="conv_id" value="<?php echo $activeConvId; ?>">
                        <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Assignee</label>
                        <select name="assigned_to" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Unassigned</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo $activeConv['assigned_to'] == $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <!-- Tags Section -->
                <div class="profile-section">
                    <div class="profile-section-title">Tags</div>
                    <div class="tags-list">
                        <?php if (empty($activeConvTags)): ?>
                            <span class="text-muted" style="font-size:12px;">No tags applied.</span>
                        <?php else: ?>
                            <?php foreach ($activeConvTags as $tag): ?>
                                <span class="tag-badge" style="background-color: <?php echo htmlspecialchars($tag['color']); ?>">
                                    <?php echo htmlspecialchars($tag['name']); ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="detach_tag">
                                        <input type="hidden" name="conv_id" value="<?php echo $activeConvId; ?>">
                                        <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                                        <button type="submit" title="Remove Tag">&times;</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Attach existing tag -->
                    <?php if (!empty($companyTags)): ?>
                        <form method="POST" class="mb-2">
                            <input type="hidden" name="action" value="attach_tag">
                            <input type="hidden" name="conv_id" value="<?php echo $activeConvId; ?>">
                            <select name="tag_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">+ Add Tag...</option>
                                <?php foreach ($companyTags as $tag): ?>
                                    <!-- Skip tags already attached -->
                                    <?php
                                    $alreadyAttached = false;
                                    foreach ($activeConvTags as $act) {
                                        if ($act['id'] == $tag['id']) $alreadyAttached = true;
                                    }
                                    if ($alreadyAttached) continue;
                                    ?>
                                    <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>

                    <!-- Create new tag -->
                    <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" style="font-size:12px;" onclick="toggleModal('createTagModal')">
                        + Create New Custom Tag
                    </button>
                </div>

                <!-- Statistics Section -->
                <div class="profile-section">
                    <div class="profile-section-title">Statistics</div>
                    <table class="stats-table">
                        <tr>
                            <td>Messages Out</td>
                            <td class="val"><?php echo number_format($stats['sent_count']); ?></td>
                        </tr>
                        <tr>
                            <td>Messages In</td>
                            <td class="val"><?php echo number_format($stats['rcv_count']); ?></td>
                        </tr>
                        <tr>
                            <td>First Contact</td>
                            <td class="val" style="font-size:11.5px;"><?php echo htmlspecialchars($stats['first_contact']); ?></td>
                        </tr>
                    </table>
                </div>

                <!-- Relocated Internal Notes Section -->
                <div class="profile-section">
                    <div class="profile-section-title">
                        <span>Internal Notes</span>
                        <button type="button" class="notes-new-btn" onclick="toggleModal('conversationNoteModal')">+ Add</button>
                    </div>
                    <div class="notes-list">
                        <?php if (empty($conversationNotes)): ?>
                            <span style="font-size:12px;color:#64748b;">No internal notes yet.</span>
                        <?php else: ?>
                            <?php foreach ($conversationNotes as $note): ?>
                                <div class="note-item">
                                    <div><?php echo nl2br(htmlspecialchars($note->body)); ?></div>
                                    <small>Added <?php echo htmlspecialchars($note->createdAt); ?></small>
                                    <div class="note-actions">
                                        <button type="button" onclick="openNoteEditModal(<?php echo (int) $note->id; ?>, '<?php echo htmlspecialchars($note->body, ENT_QUOTES); ?>')">Edit</button>
                                        <form method="POST" action="" onsubmit="return confirm('Delete this internal note?');" style="margin:0;display:inline;">
                                            <input type="hidden" name="action" value="conversation_note_delete">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="conversation_note_id" value="<?php echo (int) $note->id; ?>">
                                            <button type="submit">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="chat-empty-state"><i class="bi bi-whatsapp"></i><h4>WhatsApp Web for SaaS</h4><p>Manage, pin, and organize chats smoothly.</p></div>
    <?php endif; ?>
</div>

<div id="newChatModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:340px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Start New Conversation</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="new_chat">
            <div style="margin-bottom:16px;">
                <input type="text" name="new_number" placeholder="e.g. 9665XXXXXXXX" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="toggleModal('newChatModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none;">Cancel</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#00a884; color:#fff; border:none;">Open Chat</button>
            </div>
        </form>
    </div>
</div>

<div id="editChatModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:340px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Edit Details</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_chat">
            <input type="hidden" name="conv_id" id="editConvId">
            <div style="margin-bottom:16px;">
                <input type="text" name="edit_number" id="editNumberInput" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="toggleModal('editChatModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none;">Cancel</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#00a884; color:#fff; border:none;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="editMsgModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:400px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">تعديل الرسالة للجميع</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_message">
            <input type="hidden" name="message_id" id="editMsgId">
            <div style="margin-bottom:16px;">
                <textarea name="message_body" id="editMsgBodyInput" rows="4" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px; resize:vertical;"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="toggleModal('editMsgModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none;">إلغاء</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#00a884; color:#fff; border:none;">تحديث الرسالة</button>
            </div>
        </form>
    </div>
</div>

<div id="savedReplyModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:420px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Create Saved Reply</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="saved_reply_create">
            <div class="saved-reply-modal-body">
                <input type="text" name="saved_reply_title" placeholder="Reply title" required>
                <textarea name="saved_reply_body" rows="5" placeholder="Reply text" required></textarea>
                <div style="font-size:11px; color:#64748b; margin-top:4px; line-height:1.4;">
                    Tip: You can use dynamic variables like `{contact_number}`, `{my_name}`, and `{company_name}` in the text.
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
                <button type="button" onclick="toggleModal('savedReplyModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none;">Cancel</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#2563eb; color:#fff; border:none;">Save</button>
            </div>
        </form>
    </div>
</div>

<div id="conversationNoteModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:420px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Add Internal Note</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="conversation_note_create">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="conversation_id" value="<?php echo (int) $activeConvId; ?>">
            <div class="saved-reply-modal-body">
                <textarea name="conversation_note_body" rows="5" placeholder="Write an internal note for this conversation" required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
                <button type="button" onclick="toggleModal('conversationNoteModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none;">Cancel</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#0f766e; color:#fff; border:none;">Save Note</button>
            </div>
        </form>
    </div>
</div>

<div id="conversationNoteEditModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:420px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Edit Internal Note</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="conversation_note_update">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="conversation_note_id" id="editNoteId">
            <div class="saved-reply-modal-body">
                <textarea name="conversation_note_body" id="editNoteBody" rows="5" required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
                <button type="button" onclick="toggleModal('conversationNoteEditModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none;">Cancel</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#0f766e; color:#fff; border:none;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="createTagModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:340px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Create Custom Tag</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_tag">
            <div style="display:flex; flex-direction:column; gap:12px;">
                <div>
                    <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Tag Name</label>
                    <input type="text" name="tag_name" placeholder="e.g. VIP" required class="form-control form-control-sm">
                </div>
                <div>
                    <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Tag Color</label>
                    <input type="color" name="tag_color" value="#6366f1" class="form-control form-control-color w-100" style="height:38px;">
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                <button type="button" onclick="toggleModal('createTagModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none; font-size:13px;">Cancel</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#6366f1; color:#fff; border:none; font-size:13px;">Create Tag</button>
            </div>
        </form>
    </div>
</div>

<div id="createSegmentModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:24px; border-radius:12px; width:360px;">
        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Create Smart Filter Segment</h5>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_segment">
            <div style="display:flex; flex-direction:column; gap:12px;">
                <div>
                    <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Segment Name</label>
                    <input type="text" name="segment_name" placeholder="e.g. Unassigned VIPs" required class="form-control form-control-sm">
                </div>
                <div>
                    <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Filter by Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Any Status</option>
                        <option value="open">Open</option>
                        <option value="pending">Pending</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div>
                    <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Filter by Assignee</label>
                    <select name="assigned_to" class="form-select form-select-sm">
                        <option value="">Any Assignee</option>
                        <option value="unassigned">Unassigned</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label text-secondary mb-1" style="font-size:11px;font-weight:600;">Filter by Tag</label>
                    <select name="tag_id" class="form-select form-select-sm">
                        <option value="">Any Tag</option>
                        <?php foreach ($companyTags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>"><?php echo htmlspecialchars($tag['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
                <button type="button" onclick="toggleModal('createSegmentModal')" style="padding:6px 12px; border-radius:6px; background:#eee; border:none; font-size:13px;">Cancel</button>
                <button type="submit" style="padding:6px 12px; border-radius:6px; background:#2563eb; color:#fff; border:none; font-size:13px;">Create Segment</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDropdownMenu(event, id) {
    if (event) { event.stopPropagation(); event.preventDefault(); }
    const menus = document.querySelectorAll('.dropdown-menu-wa');
    menus.forEach(menu => { if (menu.id !== 'dropdown-' + id) menu.style.display = 'none'; });
    const current = document.getElementById('dropdown-' + id);
    if (current) current.style.display = current.style.display === 'block' ? 'none' : 'block';
}
function toggleMsgDropdown(event, id) {
    if (event) { event.stopPropagation(); event.preventDefault(); }
    const menus = document.querySelectorAll('.msg-dropdown');
    menus.forEach(menu => { if (menu.id !== 'msg-drop-' + id) menu.style.display = 'none'; });
    const current = document.getElementById('msg-drop-' + id);
    if (current) current.style.display = current.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function (e) {
    if (!e.target.closest('.dropdown-zone')) {
        document.querySelectorAll('.dropdown-menu-wa').forEach(m => m.style.display = 'none');
    }
    if (!e.target.closest('.msg-bubble')) {
        document.querySelectorAll('.msg-dropdown').forEach(m => m.style.display = 'none');
    }
});
function openEditModal(id, currentVal) {
    if (event) event.stopPropagation();
    document.getElementById('editConvId').value = id;
    document.getElementById('editNumberInput').value = currentVal;
    toggleModal('editChatModal');
}
function openEditMsgModal(id, currentText) {
    if (event) event.stopPropagation();
    document.getElementById('editMsgId').value = id;
    document.getElementById('editMsgBodyInput').value = currentText;
    toggleModal('editMsgModal');
}
function openNoteEditModal(id, currentText) {
    if (event) event.stopPropagation();
    document.getElementById('editNoteId').value = id;
    document.getElementById('editNoteBody').value = currentText;
    toggleModal('conversationNoteEditModal');
}
function toggleModal(modalId) {
    const m = document.getElementById(modalId);
    if (m) m.style.display = m.style.display === 'flex' ? 'none' : 'flex';
}
function filterConversations() {
    const query = document.getElementById('convSearch').value.toLowerCase();
    document.querySelectorAll('.conv-item').forEach(item => {
        const num = item.getAttribute('data-number').toLowerCase();
        item.style.display = num.includes(query) ? 'flex' : 'none';
    });
}
function insertSavedReply(button) {
    const input = document.getElementById('msgInput');
    let body = button.getAttribute('data-body') || '';
    if (input) {
        const contactNumber = "<?php echo isset($activeConv) ? htmlspecialchars($activeConv['contact_number']) : ''; ?>";
        const myName = "<?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Agent'); ?>";
        const companyName = "<?php echo htmlspecialchars($_SESSION['company_name'] ?? 'Company'); ?>";
        
        body = body.replace(/{contact_number}/gi, contactNumber);
        body = body.replace(/{my_name}/gi, myName);
        body = body.replace(/{company_name}/gi, companyName);
        
        input.value = body;
        input.focus();
        input.dispatchEvent(new Event('input'));
    }
}

// Collapsible right profile panel controls
function toggleProfilePanel() {
    const panel = document.getElementById('profilePanel');
    if (panel) {
        panel.classList.toggle('collapsed');
        localStorage.setItem('chatProfileCollapsed', panel.classList.contains('collapsed') ? '1' : '0');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const collapsed = localStorage.getItem('chatProfileCollapsed');
    const panel = document.getElementById('profilePanel');
    if (panel) {
        if (collapsed === '1') {
            panel.classList.add('collapsed');
        } else {
            panel.classList.remove('collapsed');
        }
    }
});
</script>

<script>
const CONV_ID = <?php echo (int) $activeConvId; ?>;
const API_BASE = '/wa_saas/api';
let lastMsgId = <?php echo (int) $lastMsgId; ?>;
let polling = null;

const msgInput = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');
const emojiBtn = document.getElementById('emojiBtn');
const pickerEl = document.getElementById('nativeEmojiPicker');
const grid = document.getElementById('emojiGrid');

const emojisList = [
    '😀', '😃', '😄', '😁', '😆', '😅', '😂', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😗',
    '👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤌', '📌', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👍', '👎', '❤️'
];

if (grid) {
    emojisList.forEach(emoji => {
        const span = document.createElement('span');
        span.className = 'emoji-item';
        span.innerText = emoji;
        span.onclick = () => { if (msgInput) msgInput.value += emoji; };
        grid.appendChild(span);
    });
}

if (emojiBtn && pickerEl) {
    emojiBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        pickerEl.style.display = pickerEl.style.display === 'flex' ? 'none' : 'flex';
    });
    document.addEventListener('click', (e) => {
        if (!pickerEl.contains(e.target) && e.target !== emojiBtn) pickerEl.style.display = 'none';
    });
}

if (msgInput) {
    msgInput.addEventListener('input', () => {
        msgInput.style.height = 'auto';
        msgInput.style.height = Math.min(msgInput.scrollHeight, 100) + 'px';
    });
}

function scrollToBottom() {
    const box = document.getElementById('chatMessages');
    if (box) box.scrollTop = box.scrollHeight;
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function renderMessage(msg) {
    const time = msg.sent_at.substring(11, 16);
    const ticks = msg.direction === 'out' ? '<i class="bi bi-check2-all text-primary"></i>' : '';
    let mediaContent = '';
    if (msg.message_type === 'image') mediaContent = `<img src="${msg.file_path}" class="msg-media-img" onclick="window.open(this.src)">`;
    else if (msg.message_type === 'sticker') mediaContent = `<img src="${msg.file_path}" class="msg-media-sticker">`;
    else if (msg.message_type === 'document') mediaContent = `<a href="${msg.file_path}" target="_blank" class="msg-media-doc"><i class="bi bi-file-earmark-arrow-down-fill text-secondary fs-3"></i><span>Download File</span></a>`;
    let bodyContent = msg.body ? `<div class="msg-body-text">${escapeHtml(msg.body).replace(/\n/g, '<br>')}</div>` : '';
    let dropdownMenu = `
        <button type="button" class="msg-options-trigger" onclick="toggleMsgDropdown(event, ${msg.id})"><i class="bi bi-chevron-down"></i></button>
        <div class="msg-dropdown" id="msg-drop-${msg.id}">
            ${msg.message_type === 'text' ? `<button type="button" onclick="openEditMsgModal(${msg.id}, '${escapeHtml(msg.body)}')"><i class="bi bi-pencil"></i> تعديل للجميع</button>` : ''}
            <form method="POST" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذه الرسالة لدى الجميع؟');" style="margin:0;">
                <input type="hidden" name="message_id" value="${msg.id}">
                <input type="hidden" name="action" value="delete_message_everyone">
                <button type="submit" style="color:red;"><i class="bi bi-trash"></i> حذف للجميع</button>
            </form>
        </div>
    `;
    return `<div class="msg-row ${msg.direction}" data-id="${msg.id}"><div class="msg-bubble">${dropdownMenu}${mediaContent}${bodyContent}<div class="msg-time">${time} ${ticks}</div></div></div>`;
}

function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        let type = 'document';
        if (file.type.startsWith('image/')) type = file.name.endsWith('.webp') ? 'sticker' : 'image';
        sendData(type, file, '');
        input.value = '';
    }
}

function sendQuickReply(text) { sendData('text', null, text); }

async function sendData(type, file, textBody) {
    if (!CONV_ID) {
        console.warn('لا توجد محادثة نشطة لإرسال رسالة إليها.');
        alert('لا يمكن الإرسال: لا توجد محادثة مفتوحة حالياً، أو لا تملك صلاحية الوصول لهذه المحادثة. اختر محادثة من القائمة الجانبية أولاً.');
        return;
    }
    if (sendBtn) sendBtn.disabled = true;
    const formData = new FormData();
    formData.append('conversation_id', CONV_ID);
    formData.append('message_type', type);
    if (file) formData.append('attachment', file);
    if (textBody) formData.append('message', textBody);
    try {
        const res = await fetch(`${API_BASE}/send_chat.php`, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.id) {
            document.getElementById('chatMessages').insertAdjacentHTML('afterbegin', renderMessage({
                id: data.id, direction: 'out', message_type: type, file_path: data.file_path || '',
                body: textBody, sent_at: data.sent_at || new Date().toISOString()
            }));
            lastMsgId = Math.max(lastMsgId, data.id);
        }
    } catch (e) {
        console.error(e);
    } finally {
        if (sendBtn) sendBtn.disabled = false;
        if (msgInput) msgInput.focus();
    }
}

async function sendMessage() {
    if (!msgInput) return;
    const text = msgInput.value.trim();
    if (!text) return;
    msgInput.value = '';
    msgInput.style.height = 'auto';
    await sendData('text', null, text);
}

function pollMessages() {
    if (!CONV_ID) return;
    fetch(`${API_BASE}/get_messages.php?conversation_id=${CONV_ID}&after_id=${lastMsgId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.messages || data.messages.length === 0) return;
            const box = document.getElementById('chatMessages');
            if (!box) return;
            data.messages.forEach(msg => {
                if (document.querySelector(`[data-id="${msg.id}"]`)) return;
                box.insertAdjacentHTML('afterbegin', renderMessage(msg));
                lastMsgId = Math.max(lastMsgId, msg.id);
            });
        }).catch(() => {});
}

if (msgInput) {
    msgInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
}
if (sendBtn) {
    sendBtn.addEventListener('click', sendMessage);
}

// Polling starts if active conversation exists
if (CONV_ID > 0) {
    polling = setInterval(pollMessages, 3000);
}
window.addEventListener('beforeunload', () => { if (polling) clearInterval(polling); });
</script>

<?php include("../layouts/footer.php"); ?>
