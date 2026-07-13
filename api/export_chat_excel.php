<?php
/**
 * WA Manager — Export Chat history to Excel
 * File: api/export_chat_excel.php
 */
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/Conversation/ConversationRepository.php');

use Core\Conversation\ConversationRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Chat History");

// Add headers
$sheet->setCellValue('A1', 'Sender Name');
$sheet->setCellValue('B1', 'Direction');
$sheet->setCellValue('C1', 'Message Type');
$sheet->setCellValue('D1', 'Message Content');
$sheet->setCellValue('E1', 'Sent At');

// Format headers
$sheet->getStyle('A1:E1')->getFont()->setBold(true);

$rowNum = 2;
foreach ($messages as $msg) {
    $sender = $msg['direction'] === 'in' ? $conv['contact_number'] : ($msg['employee_name'] ?? 'System/Auto-reply');
    $direction = $msg['direction'] === 'in' ? 'Inbound' : 'Outbound';
    
    $sheet->setCellValue('A' . $rowNum, $sender);
    $sheet->setCellValue('B' . $rowNum, $direction);
    $sheet->setCellValue('C' . $rowNum, $msg['message_type']);
    $sheet->setCellValue('D' . $rowNum, $msg['body']);
    $sheet->setCellValue('E' . $rowNum, $msg['sent_at']);
    $rowNum++;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="chat_' . $conv['contact_number'] . '_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
