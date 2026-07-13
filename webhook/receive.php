<?php
// ملف مستقل تماماً لاستقبال بيانات Meta (Standalone)
require_once("../config/db.php");
require_once("../core/Conversation/Conversation.php");
require_once(__DIR__ . '/../core/Conversation/ConversationRepository.php');
require_once(__DIR__ . '/../core/Conversation/ConversationService.php');
require_once(__DIR__ . '/../core/WhatsAppNumber/WhatsAppNumberRepository.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageRepository.php');
require_once(__DIR__ . '/../core/ChatMessage/ChatMessageService.php');

use Core\Conversation\ConversationRepository;
use Core\Conversation\ConversationService;
use Core\WhatsAppNumber\WhatsAppNumberRepository;
use Core\ChatMessage\ChatMessageRepository;
use Core\ChatMessage\ChatMessageService;

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
        $wamid      = $message['id'];
        $msgType    = $message['type'];
        $filePath   = null;

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
            $waNumberRepo = new WhatsAppNumberRepository($conn);
            $companyId = $waNumberRepo->getCompanyIdByPhoneNumberId($phoneId);

            if ($companyId) {

                // Resolve conversation via ConversationService (find or create)
                $convService = new ConversationService(new ConversationRepository($conn));
                $convId      = $convService->findOrCreate($companyId, $fromNumber);

                $chatMsgService = new ChatMessageService(new ChatMessageRepository($conn));
                $chatMsgService->insertMessage(
                    $convId,
                    'in',
                    $msgBody,
                    null,
                    $wamid,
                    $msgType,
                    $filePath
                );

                $convService->touch($convId);

                // ── Automation Rules Trigger ──
                $rulesStmt = $conn->prepare("SELECT * FROM automation_rules WHERE company_id = ? AND is_active = 1");
                $rulesStmt->bind_param("i", $companyId);
                $rulesStmt->execute();
                $rules = $rulesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $rulesStmt->close();

                $matchedRule = null;
                foreach ($rules as $rule) {
                    if ($rule['trigger_type'] === 'always') {
                        $matchedRule = $rule;
                        break;
                    } elseif ($rule['trigger_type'] === 'keyword') {
                        $kw = trim($rule['keyword']);
                        if ($kw !== '' && mb_stripos($msgBody, $kw) !== false) {
                            $matchedRule = $rule;
                            break;
                        }
                    }
                }

                if ($matchedRule !== null) {
                    $replyText = $matchedRule['reply_text'];
                    $accessToken = "EAAZBGtwMbSu0BR2aV2JmjOSNmHHBQAHrYyCcjDoSZCktJ5seX4XXGy8ci42Gb46oz2ZCZAWwOZBCF35kGXH9euUYgSeiZBJL5qeXtk1VXcPW9HM3idG1pZBh8R3LuAxOUKkjHA0cDd7j3gA6j57ym9EjPCfoSFRmtukVfP4nktiHnsmZCUCSlmGvWn6A7UYtklN0Brsk2LdKavex72zsvvzcRxSFaR1ZArhzO60furZCFxo7jS2hfTuVGl1aypLrqCAihmvNVYPVFcegtjDHZB91xApTZCn3";
                    
                    $whatsAppService = new WhatsAppService();
                    $sendRes = $whatsAppService->sendText($phoneId, $fromNumber, $replyText, $accessToken);
                    
                    $outWamid = null;
                    if ($sendRes['httpCode'] >= 200 && $sendRes['httpCode'] < 300) {
                        $resObj = json_decode($sendRes['response'], true);
                        $outWamid = $resObj['messages'][0]['id'] ?? null;
                    }
                    
                    $chatMsgService->insertMessage(
                        $convId,
                        'out',
                        $replyText,
                        null, // Automated reply (no human sender)
                        $outWamid,
                        'text',
                        null
                    );
                    
                    $convService->touch($convId);
                }
            }
        }
    }

    // إشعار فيسبوك باستلام البيانات بنجاح
    http_response_code(200);
    echo json_encode(["status" => "success"]);
    exit;
}