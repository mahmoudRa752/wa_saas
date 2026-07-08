<?php
// ==========================================
// الملف المُعدل: wa_saas/api/send_chat.php
// ==========================================
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Conversation/Conversation.php');
require_once(__DIR__ . '/../core/Conversation/ConversationRepository.php');
require_once(__DIR__ . '/../core/Conversation/ConversationService.php');
require_once(__DIR__ . '/../core/WhatsAppNumber/WhatsAppNumberRepository.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageRepository.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageService.php');
require_once(__DIR__ . '/../core/Services/WhatsAppService.php');

use Core\Conversation\ConversationRepository;
use Core\Conversation\ConversationService;
use Core\WhatsAppNumber\WhatsAppNumberRepository;
use Core\ChatMessage\ChatMessageRepository;
use Core\ChatMessage\ChatMessageService;
use Core\Services\WhatsAppService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$companyId      = (int) $_SESSION['company_id'];
$currentUserId  = (int) $_SESSION['user_id']; // معرف المستخدم الحالي (سواء أدمن أو موظف)
$conversationId = (int) ($_POST['conversation_id'] ?? 0);
$messageType    = $_POST['message_type'] ?? 'text';
$messageBody    = trim($_POST['message'] ?? '');

if ($conversationId <= 0) {
    echo json_encode(['error' => 'Invalid conversation ID']);
    exit;
}

// ملاحظة: جدول whatsapp_numbers الحالي لا يحتوي على عمود access_token
// (نفس التوكن المستخدم قديمًا داخل dashboard/chat.php مباشرة قبل الفصل).
// نُبقيه هنا كثابت مؤقت لحين نقله لمكان آمن (متغير بيئة / إعدادات)، وهذا
// نفس التوكن الذي كان يعمل فعليًا في الكود الأصلي.
const WHATSAPP_ACCESS_TOKEN = "EAAZBGtwMbSu0BR2aV2JmjOSNmHHBQAHrYyCcjDoSZCktJ5seX4XXGy8ci42Gb46oz2ZCZAWwOZBCF35kGXH9euUYgSeiZBJL5qeXtk1VXcPW9HM3idG1pZBh8R3LuAxOUKkjHA0cDd7j3gA6j57ym9EjPCfoSFRmtukVfP4nktiHnsmZCUCSlmGvWn6A7UYtklN0Brsk2LdKavex72zsvvzcRxSFaR1ZArhzO60furZCFxo7jS2hfTuVGl1aypLrqCAihmvNVYPVFcegtjDHZB91xApTZCn3";

// 1. جلب بيانات رقم الواتساب المخصص للمستخدم الحالي (أدمن أو موظف) لضمان عدم التداخل
$waNumberRepo = new WhatsAppNumberRepository($conn);
$phoneNumberId = null;

if ($currentUserId > 0) {
    $phoneNumberId = $waNumberRepo->getPhoneNumberIdByUserId($currentUserId);
}

// Fallback: حسابات الأدمن/الشركة (auth/login_company.php) لا تُسجّل user_id في
// الجلسة أصلاً لأن الأدمن ليس له صف في جدول users، فـ $currentUserId يكون 0
// ولن يوجد له رقم واتساب شخصي مباشرة. في هذه الحالة نستخدم أي رقم واتساب
// مربوط بأحد موظفي نفس الشركة كرقم افتراضي، حتى لا يفشل الإرسال بالكامل.
if (!$phoneNumberId) {
    $phoneNumberId = $waNumberRepo->getPhoneNumberIdByCompanyId($companyId);
}

if (!$phoneNumberId) {
    echo json_encode(['error' => 'لم يتم العثور على رقم واتساب مخصص لهذا الحساب أو لأي موظف في الشركة. تأكد من ربط رقم واتساب واحد على الأقل من صفحة "أرقام واتساب".']);
    exit;
}

$accessToken   = WHATSAPP_ACCESS_TOKEN;

// 2. جلب رقم هاتف العميل من المحادثة الحالية (via ConversationService)
$convService = new ConversationService(new ConversationRepository($conn));
$conv        = $convService->getForCompany($conversationId, $companyId);

if (!$conv) {
    echo json_encode(['error' => 'Conversation not found']);
    exit;
}

$to = $conv->contactNumber;
$filePath = null;
$whatsAppService = new WhatsAppService();

// 3. معالجة المرفقات (إن وجدت)
if ($messageType !== 'text' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['attachment'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . uniqid() . '.' . $ext;

    // تأكد من وجود المجلد المناسب للمرفقات
    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $targetPath = $uploadDir . $fileName;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $filePath = '/wa_saas/uploads/' . $fileName;
    }
}

// 4. إعداد وإرسال طلب Meta API بناءً على بيانات رقم المستخدم الحالي
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
    // في حالة إرسال المرفقات (الصور، الملفات، الستيكرز) عبر رابط خارجي أو محلي
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

$wamid = null;

if ($httpCode >= 200 && $httpCode < 300) {
    $resObj = json_decode($response, true);
    $wamid = $resObj['messages'][0]['id'] ?? null;
}

// 5. حفظ الرسالة في قاعدة البيانات مع ربطها بالـ user_id الخاص بالمرسل الحالي
$chatMsgService = new ChatMessageService(new ChatMessageRepository($conn));
$newMsgId = $chatMsgService->insertMessage(
    $conversationId,
    'out',
    $messageBody,
    $currentUserId,
    $wamid,
    $messageType,
    $filePath
);

// تحديث وقت آخر رسالة في المحادثة (via ConversationService)
$convService->touch($conversationId);

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
