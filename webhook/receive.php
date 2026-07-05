<?php
// ملف مستقل تماماً لاستقبال بيانات Meta (Standalone)
require_once("../config/db.php");

// 1. مرحلة التحقق المبدئي من فيسبوك (Webhook Verification Challenge)
// يتم استدعاء هذا الجزء بطلب GET عند ربط الرابط لأول مرة في Meta Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verifyToken = "MY_SECRET_WA_TOKEN_2026"; // يمكنك تغيير هذا الرمز لأي كلمة سر تختارها

    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verifyToken) {
        http_response_code(200);
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        exit;
    }
}

// 2. مرحلة استقبال الرسائل الحقيقية (Webhook Event Notification)
// يتم استدعاؤه بطلب POST محمل بـ JSON عند قيام عميل بإرسال رسالة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // التحقق من أن الطلب يحتوي على رسالة نصية قادمة من واتساب
    if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {

        $value    = $input['entry'][0]['changes'][0]['value'];
        $phoneId  = $value['metadata']['phone_number_id'] ?? ''; // رقم الواتساب المستهدف
        $message  = $value['messages'][0];

        $fromNumber = $message['from']; // رقم العميل (مثال: 966506493268)
        $msgBody    = '';

        // استخراج نص الرسالة بناءً على نوعها (تدعم النص حالياً)
        if ($message['type'] === 'text') {
            $msgBody = $message['text']['body'];
        } elseif ($message['type'] === 'button') {
            $msgBody = $message['button']['text'];
        } else {
            $msgBody = "[" . ucfirst($message['type']) . " Message]";
        }

        if (!empty($msgBody) && !empty($phoneId)) {

            // معرفة الشركة والموظف المرتبطين بهذا الـ Phone Number ID
            $stmt = $conn->prepare("
                SELECT user_id, company_id 
                FROM whatsapp_numbers 
                JOIN users ON whatsapp_numbers.user_id = users.id 
                WHERE whatsapp_numbers.phone_number_id = ? 
                LIMIT 1
            ");
            $stmt->bind_param("s", $phoneId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($result) {
                $companyId = (int)$result['company_id'];

                // 1. التحقق من وجود محادثة سابقة أو إنشائها لضمان الـ Multi-Tenancy
                $convStmt = $conn->prepare("
                    INSERT INTO conversations (company_id, contact_number, last_message_at) 
                    VALUES (?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE last_message_at = NOW()
                ");
                $convStmt->bind_param("is", $companyId, $fromNumber);
                $convStmt->execute();
                $convStmt->close();

                // جلب الـ ID الخاص بالمحادثة الحالية
                $getConv = $conn->prepare("SELECT id FROM conversations WHERE company_id = ? AND contact_number = ? LIMIT 1");
                $getConv->bind_param("is", $companyId, $fromNumber);
                $getConv->execute();
                $convId = (int)$getConv->get_result()->fetch_assoc()['id'];
                $getConv->close();

                // 2. إدخال الرسالة الواردة في جدول الشات الحي باتجاه 'in'
                $msgStmt = $conn->prepare("
                    INSERT INTO chat_messages (conversation_id, user_id, direction, body, sent_at) 
                    VALUES (?, NULL, 'in', ?, NOW())
                ");
                $msgStmt->bind_param("is", $convId, $msgBody);
                $msgStmt->execute();
                $msgStmt->close();
            }
        }
    }

    // إشعار فيسبوك باستلام البيانات بنجاح
    http_response_code(200);
    echo json_encode(["status" => "success"]);
    exit;
}