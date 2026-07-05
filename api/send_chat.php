<?php
// ==========================================
// الملف المُعدل: wa_saas/api/send_chat.php
// ==========================================
session_start();
require_once("../config/db.php");

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

// 1. جلب بيانات رقم الواتساب المخصص للمستخدم الحالي (أدمن أو موظف) لضمان عدم التداخل
$numResult = null;
if ($currentUserId > 0) {
    $numQuery = $conn->prepare("SELECT phone_number_id, whatsapp_business_account_id, access_token FROM whatsapp_numbers WHERE user_id = ? LIMIT 1");
    $numQuery->bind_param('i', $currentUserId);
    $numQuery->execute();
    $numResult = $numQuery->get_result()->fetch_assoc();
    $numQuery->close();
}

// Fallback: حسابات الأدمن/الشركة (auth/login_company.php) لا تُسجّل user_id في
// الجلسة أصلاً لأن الأدمن ليس له صف في جدول users، فـ $currentUserId يكون 0
// ولن يوجد له رقم واتساب شخصي مباشرة. في هذه الحالة نستخدم أي رقم واتساب
// مربوط بأحد موظفي نفس الشركة كرقم افتراضي، حتى لا يفشل الإرسال بالكامل.
if (!$numResult) {
    $fallbackQuery = $conn->prepare("
        SELECT wn.phone_number_id, wn.whatsapp_business_account_id, wn.access_token
        FROM whatsapp_numbers wn
        INNER JOIN users u ON u.id = wn.user_id
        WHERE u.company_id = ?
        LIMIT 1
    ");
    $fallbackQuery->bind_param('i', $companyId);
    $fallbackQuery->execute();
    $numResult = $fallbackQuery->get_result()->fetch_assoc();
    $fallbackQuery->close();
}

if (!$numResult) {
    echo json_encode(['error' => 'لم يتم العثور على رقم واتساب مخصص لهذا الحساب أو لأي موظف في الشركة. تأكد من ربط رقم واتساب واحد على الأقل من صفحة "أرقام واتساب".']);
    exit;
}

$phoneNumberId = $numResult['phone_number_id'];
$accessToken   = $numResult['access_token'];

// 2. جلب رقم هاتف العميل من المحادثة الحالية
$convQuery = $conn->prepare("SELECT contact_number FROM conversations WHERE id = ? AND company_id = ? LIMIT 1");
$convQuery->bind_param('ii', $conversationId, $companyId);
$convQuery->execute();
$conv = $convQuery->get_result()->fetch_assoc();
$convQuery->close();

if (!$conv) {
    echo json_encode(['error' => 'Conversation not found']);
    exit;
}

$to = $conv['contact_number'];
$filePath = null;

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
$url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";
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

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $accessToken,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resData = json_encode($response);
$wamid = null;

if ($httpCode >= 200 && $httpCode < 300) {
    $resObj = json_decode($response, true);
    $wamid = $resObj['messages'][0]['id'] ?? null;
}

// 5. حفظ الرسالة في قاعدة البيانات مع ربطها بالـ user_id الخاص بالمرسل الحالي
$insStmt = $conn->prepare("
    INSERT INTO chat_messages (conversation_id, user_id, direction, body, message_type, file_path, whatsapp_msg_id, sent_at) 
    VALUES (?, ?, 'out', ?, ?, ?, ?, NOW())
");
$insStmt->bind_param('iisssss', $conversationId, $currentUserId, $messageBody, $messageType, $filePath, $wamid);
$insStmt->execute();
$newMsgId = $insStmt->insert_id;
$insStmt->close();

// تحديث وقت آخر رسالة في المحادثة
$upd = $conn->prepare("UPDATE conversations SET last_message_at = NOW() WHERE id = ?");
$upd->bind_param('i', $conversationId);
$upd->execute();
$upd->close();

echo json_encode([
    'success'      => true,
    'id'           => $newMsgId,
    'file_path'    => $filePath,
    'sent_at'      => date('Y-m-d H:i:s'),
    'meta_status'  => $httpCode
]);