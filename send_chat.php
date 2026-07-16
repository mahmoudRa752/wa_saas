<?php
// ==========================================
// الملف المُعدل: wa_saas/api/send_chat.php
// ==========================================
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/core/Services/WhatsAppService.php');

use Core\Services\WhatsAppService;
use Core\WhatsAppNumber\WhatsAppNumberRepository;
use Core\Conversation\ConversationRepository;
use Core\ChatMessage\ChatMessageRepository;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once(__DIR__ . '/core/WhatsAppNumber/WhatsAppNumberRepository.php');
require_once(__DIR__ . '/core/Conversation/ConversationRepository.php');
require_once(__DIR__ . '/core/Conversation/Conversation.php');
require_once(__DIR__ . '/core/ChatMessage/ChatMessageRepository.php');

$companyId      = (int) $_SESSION['company_id'];
$currentUserId  = (int) $_SESSION['user_id'];
$conversationId = (int) ($_POST['conversation_id'] ?? 0);
$messageType    = $_POST['message_type'] ?? 'text';
$messageBody    = trim($_POST['message'] ?? '');

if ($conversationId <= 0) {
    echo json_encode(['error' => 'Invalid conversation ID']);
    exit;
}

const WHATSAPP_ACCESS_TOKEN = "EAAZBGtwMbSu0BR2aV2JmjOSNmHHBQAHrYyCcjDoSZCktJ5seX4XXGy8ci42Gb46oz2ZCZAWwOZBCF35kGXH9euUYgSeiZBJL5qeXtk1VXcPW9HM3idG1pZBh8R3LuAxOUKkjHA0cDd7j3gA6j57ym9EjPCfoSFRmtukVfP4nktiHnsmZCUCSlmGvWn6A7UYtklN0Brsk2LdKavex72zsvvzcRxSFaR1ZArhzO60furZCFxo7jS2hfTuVGl1aypLrqCAihmvNVYPVFcegtjDHZB91xApTZCn3";

$waNumberRepo = new WhatsAppNumberRepository($conn);
$phoneNumberId = null;

if ($currentUserId > 0) {
    $phoneNumberId = $waNumberRepo->getPhoneNumberIdByUserId($currentUserId);
}

if (!$phoneNumberId) {
    $phoneNumberId = $waNumberRepo->getPhoneNumberIdByCompanyId($companyId);
}

if (!$phoneNumberId) {
    echo json_encode(['error' => 'لم يتم العثور على رقم واتساب مخصص لهذا الحساب أو لأي موظف في الشركة. تأكد من ربط رقم واتساب واحد على الأقل من صفحة "أرقام واتساب".']);
    exit;
}

$accessToken = getenv('WHATSAPP_TOKEN') ?: (defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : WHATSAPP_ACCESS_TOKEN);

$convRepo = new ConversationRepository($conn);
$conv = $convRepo->findById($conversationId, $companyId);

if (!$conv) {
    echo json_encode(['error' => 'Conversation not found']);
    exit;
}

$to = $conv->contactNumber;
$filePath = null;
$whatsAppService = new WhatsAppService();

if ($messageType !== 'text' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['attachment'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . uniqid() . '.' . $ext;

    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $targetPath = $uploadDir . $fileName;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $filePath = '/wa_saas/uploads/' . $fileName;
    }
}

$payload = [
    "messaging_product" => "whatsapp",
    "recipient_type"    => "individual",
    "to"                => $to
];

if ($messageType === 'text') {
    if (empty($messageBody)) {
        echo json_encode(['error' => 'Message body is empty']);
        exit;
    }
    $payload['type'] = 'text';
    $payload['text'] = ['body' => $messageBody];
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $fullFileUrl = $protocol . $_SERVER['HTTP_HOST'] . $filePath;

    $payload['type'] = $messageType;
    $payload[$messageType] = ['link' => $fullFileUrl];
    if (!empty($messageBody) && $messageType === 'image') {
        $payload['image']['caption'] = $messageBody;
    }
}

$sendResult = $whatsAppService->sendPayload($phoneNumberId, $payload, $accessToken);
$response = $sendResult['response'];
$httpCode = $sendResult['httpCode'];

$resData = json_encode($response);
$wamid = null;

if ($httpCode >= 200 && $httpCode < 300) {
    $resObj = json_decode($response, true);
    $wamid = $resObj['messages'][0]['id'] ?? null;
}

$chatMsgRepo = new ChatMessageRepository($conn);
$newMsgId = $chatMsgRepo->insertMessage(
    $conversationId,
    'out',
    $messageBody,
    $currentUserId,
    $wamid,
    $messageType,
    $filePath
);

$convRepo->touchTimestamp($conversationId);

echo json_encode([
    'success'       => true,
    'id'            => $newMsgId,
    'file_path'     => $filePath,
    'sent_at'       => date('Y-m-d H:i:s'),
    'meta_status'   => $httpCode,
    // مؤقت للتشخيص فقط: نص خطأ Meta الكامل حتى نعرف السبب الدقيق للفشل.
    // يُحذف بعد حل المشكلة.
    'meta_response' => $response,
    'phone_number_id_used' => $phoneNumberId
]);