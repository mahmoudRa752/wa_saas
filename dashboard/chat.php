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

function generateConversationNoteCsrfToken(): string
{
    if (empty($_SESSION['conversation_note_csrf'])) {
        $_SESSION['conversation_note_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['conversation_note_csrf'];
}

function validateConversationNoteCsrfToken(?string $token): bool
{
    return is_string($token) && hash_equals($_SESSION['conversation_note_csrf'] ?? '', $token);
}

$companyId    = (int) $_SESSION['company_id'];
$role         = $_SESSION['role'] ?? '';
$userId       = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$isAdmin      = ($role === 'admin');
// created_by confirmed present in live DB (schema audit 2025). No runtime ALTER needed.
$hasCreatedBy = true;

$tenantContext = TenantContext::fromSession();
$csrfToken = generateConversationNoteCsrfToken();
$savedReplyService = new SavedReplyService(new SavedReplyRepository($conn));
$conversationNoteService = new ConversationNoteService(new ConversationNoteRepository($conn));
$conn->query("CREATE TABLE IF NOT EXISTS saved_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY company_id (company_id)
)");
$savedReplies = $savedReplyService->list($tenantContext);
$conversationNotes = [];

/**
 * Builds the WHERE clause fragment for conversation isolation by role.
 * Admin: sees all company conversations.
 * Employee: sees only conversations they created (created_by) or participated in (chat_messages.user_id).
 */
function buildIsolationClause(bool $isAdmin, int $userId, bool $hasCreatedBy = true): array
{
    if ($isAdmin) {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if ($hasCreatedBy) {
        $sql = " AND (c.created_by = ? OR c.id IN (
                    SELECT DISTINCT cm.conversation_id FROM chat_messages cm WHERE cm.user_id = ?
                )) ";
        return ['sql' => $sql, 'types' => 'ii', 'params' => [$userId, $userId]];
    }
    $sql = " AND c.id IN (
                SELECT DISTINCT cm.conversation_id FROM chat_messages cm WHERE cm.user_id = ?
            ) ";
    return ['sql' => $sql, 'types' => 'i', 'params' => [$userId]];
}

/** Checks whether the current user may access a given conversation. */
function userCanAccessConversation(mysqli $conn, int $convId, int $companyId, bool $isAdmin, ?int $userId, bool $hasCreatedBy = true): bool
{
    $iso    = buildIsolationClause($isAdmin, (int) $userId, $hasCreatedBy);
    $sql    = "SELECT c.id FROM conversations c WHERE c.id = ? AND c.company_id = ?" . $iso['sql'] . " LIMIT 1";
    $stmt   = $conn->prepare($sql);
    $types  = 'ii' . $iso['types'];
    $params = array_merge([$convId, $companyId], $iso['params']);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

// ── 1. Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // إنشاء محادثة جديدة (via ConversationService)
    if ($action === 'new_chat') {
        $newNumber = trim($_POST['new_number']);
        if (!empty($newNumber)) {
            $convService = new ConversationService(new ConversationRepository($conn));
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
        if ($convId > 0 && $noteBody !== '' && userCanAccessConversation($conn, $convId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
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
            if ($note && userCanAccessConversation($conn, $note->conversationId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
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
            if ($note && userCanAccessConversation($conn, $note->conversationId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
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
        if (userCanAccessConversation($conn, $cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService(new ConversationRepository($conn));
            $convService->updatePin($cId, $companyId, $status);
        }
        header("Location: chat.php" . (isset($_GET['conv']) ? "?conv=" . (int) $_GET['conv'] : ""));
        exit;
    }

    // تعديل اسم أو رقم المحادثة الجانبية
    if ($action === 'edit_chat') {
        $cId = (int) $_POST['conv_id'];
        $updatedNumber = trim($_POST['edit_number']);
        if (userCanAccessConversation($conn, $cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService(new ConversationRepository($conn));
            $convService->updateContactNumber($cId, $companyId, $updatedNumber);
        }
        header("Location: chat.php?conv=" . $cId);
        exit;
    }

    // حذف المحادثة بالكامل ورسائلها محلياً
    if ($action === 'delete_chat') {
        $cId = (int) $_POST['conv_id'];
        if (userCanAccessConversation($conn, $cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
            $convService = new ConversationService(new ConversationRepository($conn));
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

        if (userCanAccessConversation($conn, $cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
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

        if (userCanAccessConversation($conn, $cId, $companyId, $isAdmin, $userId, $hasCreatedBy)) {
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
} // ✅ تم إغلاق قوس التحقق الرئيسي هنا بنجاح

// ── 2. جلب المحادثات الجانبية (بدون JOIN يخفي المحادثات الجديدة/الفارغة) ──
$iso = buildIsolationClause($isAdmin, (int) $userId, $hasCreatedBy);

$convSql = "
    SELECT c.id, c.contact_number, c.last_message_at, c.is_pinned,
        (SELECT cm.body FROM chat_messages cm WHERE cm.conversation_id = c.id
            ORDER BY cm.sent_at DESC LIMIT 1) AS last_message,
        (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id
            AND cm.direction = 'in' AND cm.sent_at > IFNULL((SELECT MAX(cm2.sent_at)
                FROM chat_messages cm2 WHERE cm2.conversation_id = c.id AND cm2.direction = 'out'), '2000-01-01')
        ) AS unread_count
    FROM conversations c
    WHERE c.company_id = ?" . $iso['sql'] . "
    ORDER BY c.is_pinned DESC, c.last_message_at DESC
    LIMIT 50
";
$convStmt = $conn->prepare($convSql);
$convTypes = 'i' . $iso['types'];
$convParams = array_merge([$companyId], $iso['params']);
$convStmt->bind_param($convTypes, ...$convParams);
$convStmt->execute();
$conversations = $convStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$convStmt->close();

$activeConvId = isset($_GET['conv']) ? (int) $_GET['conv'] : ($conversations[0]['id'] ?? 0);
$activeConv = null;
$initMessages = [];

// ── 3. جلب الرسائل (نفس شرط العزل حتى لا يفتح الموظف محادثة غيره عبر الرابط) ──
if ($activeConvId > 0) {
    $acSql = "SELECT c.* FROM conversations c WHERE c.id = ? AND c.company_id = ?" . $iso['sql'] . " LIMIT 1";
    $acStmt = $conn->prepare($acSql);
    $acTypes = 'ii' . $iso['types'];
    $acParams = array_merge([$activeConvId, $companyId], $iso['params']);
    $acStmt->bind_param($acTypes, ...$acParams);
    $acStmt->execute();
    $activeConv = $acStmt->get_result()->fetch_assoc();
    $acStmt->close();

    if ($activeConv) {
        $msgStmt = $conn->prepare("
            SELECT cm.id, cm.direction, cm.body, cm.message_type, cm.file_path, cm.sent_at, u.name AS sender_name
            FROM chat_messages cm LEFT JOIN users u ON cm.user_id = u.id
            WHERE cm.conversation_id = ? ORDER BY cm.sent_at DESC LIMIT 50
        ");
        $msgStmt->bind_param('i', $activeConvId);
        $msgStmt->execute();
        $initMessages = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $msgStmt->close();

        $conversationNotes = $conversationNoteService->listForConversation($tenantContext, $activeConvId);
    } else {
        // المحادثة غير موجودة أو لا يملك الموظف صلاحية الوصول إليها
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
.conv-name { font-size: 14px; font-weight: 600; color: #111b21; display: flex; align-items: center; gap: 6px; }
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
.chat-header { padding: 10px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 12px; background: #f0f2f5; z-index: 10; }
.chat-header-avatar { width: 40px; height: 40px; border-radius: 50%; background: #6366f1; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
.chat-header-info .name { font-size: 15px; font-weight: 600; color: #111b21; }
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
.notes-panel { padding: 10px 16px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
.notes-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #334155; }
.notes-new-btn { border: none; background: #0f766e; color: #fff; border-radius: 999px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
.notes-list { display: flex; flex-direction: column; gap: 8px; }
.note-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 13px; color: #334155; }
.note-item small { color: #64748b; display: block; margin-top: 4px; }
.note-actions { display: flex; gap: 6px; margin-top: 6px; }
.note-actions button { border: none; background: #f1f5f9; color: #334155; border-radius: 999px; padding: 3px 8px; font-size: 12px; cursor: pointer; }
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
        <div class="conv-list-body" id="convListBody">
            <?php if (empty($conversations)): ?>
                <div style="padding:40px; text-align:center; color:#667781;">No conversations found.</div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php
                    $isActive = ($conv['id'] == $activeConvId);
                    $preview = $conv['last_message'] ? mb_substr($conv['last_message'], 0, 30) . '...' : 'Media/Attachment';
                    $timeAgo = date('H:i', strtotime($conv['last_message_at']));
                    ?>
                    <div class="conv-item <?php echo $isActive ? 'active' : ''; ?>"
                         data-number="<?php echo htmlspecialchars($conv['contact_number']); ?>"
                         onclick="if(!event.target.closest('.dropdown-zone')) window.location.href='?conv=<?php echo $conv['id']; ?>'">
                        <div class="conv-avatar"><?php echo substr($conv['contact_number'], -2); ?></div>
                        <div class="conv-info">
                            <div class="conv-name">
                                <?php echo htmlspecialchars($conv['contact_number']); ?>
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
                <div class="chat-header-avatar"><i class="bi bi-person-fill"></i></div>
                <div class="chat-header-info">
                    <div class="name"><?php echo htmlspecialchars($activeConv['contact_number']); ?></div>
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
            <div class="notes-panel">
                <div class="notes-header">
                    <span>Internal Notes</span>
                    <button type="button" class="notes-new-btn" onclick="toggleModal('conversationNoteModal')">+ Add</button>
                </div>
                <div class="notes-list">
                    <?php if (empty($conversationNotes)): ?>
                        <span style="font-size:12px;color:#64748b;">No internal notes for this conversation yet.</span>
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

<script>
// ── سكربت عام: يعمل دائماً بغض النظر عن وجود محادثة نشطة ──
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
    const body = button.getAttribute('data-body') || '';
    if (input) {
        input.value = body;
        input.focus();
        input.dispatchEvent(new Event('input'));
    }
}
</script>

<script>
/*
 * ── سكربت الرسائل/المحادثة النشطة ──
 * هام: هذا السكربت يُطبع دائماً (غير موضوع داخل شرط PHP واحد) حتى تبقى
 * CONV_ID و lastMsgId معرّفتين بقيمة افتراضية (0) في كل الحالات، فلا
 * تتوقف أزرار الإرسال/الإيموجي حتى عند عدم وجود محادثة نشطة.
 */
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

// الاستطلاع (polling) يبدأ فقط عند وجود محادثة نشطة فعلياً
if (CONV_ID > 0) {
    polling = setInterval(pollMessages, 3000);
}
window.addEventListener('beforeunload', () => { if (polling) clearInterval(polling); });
</script>

<?php include("../layouts/footer.php"); ?>
