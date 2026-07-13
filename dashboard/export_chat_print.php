<?php
/**
 * WA Manager — Print Chat Transcript (Save as PDF)
 * File: dashboard/export_chat_print.php
 */
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Conversation/ConversationRepository.php');

use Core\Conversation\ConversationRepository;

if (!isset($_SESSION['company_id'])) {
    die("Unauthorized");
}

$companyId = (int)$_SESSION['company_id'];
$isAdmin = ($_SESSION['role'] === 'admin');
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$convId = isset($_GET['conv_id']) ? (int)$_GET['conv_id'] : 0;

$convRepo = new ConversationRepository($conn);
if (!$convRepo->checkAccess($convId, $companyId, $isAdmin, $userId)) {
    die("Access denied");
}

$conv = $convRepo->getActiveConversation($convId, $companyId, $isAdmin, $userId);
if (!$conv) {
    die("Conversation not found");
}

// Fetch messages
$stmt = $conn->prepare("
    SELECT cm.*, u.name AS employee_name 
    FROM chat_messages cm
    LEFT JOIN users u ON cm.user_id = u.id
    WHERE cm.conversation_id = ?
    ORDER BY cm.sent_at ASC
");
$stmt->bind_param("i", $convId);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chat Transcript - <?php echo htmlspecialchars($conv['contact_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; color: #333; padding: 30px; }
        .message-row { margin-bottom: 12px; display: flex; flex-direction: column; }
        .message-bubble { max-width: 75%; padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.4; position: relative; }
        .message-in { align-self: flex-start; background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #64748b; }
        .message-out { align-self: flex-end; background: #eff6ff; border: 1px solid #dbeafe; border-right: 4px solid #3b82f6; }
        .message-meta { font-size: 10px; color: #64748b; margin-top: 4px; display: block; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-whatsapp text-success me-2"></i>WA Chat Transcript</h4>
            <div class="text-secondary" style="font-size:12px;">Customer: <strong><?php echo htmlspecialchars($conv['contact_number']); ?></strong> &middot; Status: <?php echo ucfirst($conv['status']); ?></div>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-primary no-print fw-semibold"><i class="bi bi-printer me-1"></i> Print / Save as PDF</button>
    </div>

    <div class="d-flex flex-column" style="gap: 8px;">
        <?php foreach ($messages as $msg): ?>
            <?php
            $isIn = ($msg['direction'] === 'in');
            $sender = $isIn ? $conv['contact_number'] : ($msg['employee_name'] ?? 'System/Auto-reply');
            ?>
            <div class="message-row">
                <div class="message-bubble <?php echo $isIn ? 'message-in' : 'message-out'; ?>">
                    <div class="fw-bold mb-1" style="font-size:10px; color: #475569;"><?php echo htmlspecialchars($sender); ?></div>
                    <div style="white-space: pre-wrap; word-break: break-all;"><?php echo htmlspecialchars($msg['body']); ?></div>
                    <span class="message-meta"><?php echo date('M d, Y H:i', strtotime($msg['sent_at'])); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 600);
        });
    </script>
</body>
</html>
