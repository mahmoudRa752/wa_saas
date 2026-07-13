<?php
/**
 * WA Manager — Background Broadcast Worker
 * File: cron/process_broadcasts.php
 * Type: CLI/Background Worker
 */

// Prevent execution via web browser directly if desired, but allow CLI or lazy trigger
if (php_sapi_name() !== 'cli' && !defined('LAZY_CRON_TRIGGER')) {
    // If called via web trigger, define constant and run
    define('LAZY_CRON_TRIGGER', true);
}

require_once(__DIR__ . '/../config/db.php');
require_once(__DIR__ . '/../core/Services/WhatsAppService.php');
require_once(__DIR__ . '/../core/WhatsAppNumber/WhatsAppNumberRepository.php');
require_once(__DIR__ . '/../core/MessageLog/MessageLogRepository.php');

use Core\Services\WhatsAppService;
use Core\WhatsAppNumber\WhatsAppNumberRepository;
use Core\MessageLog\MessageLogRepository;

// Prevent multiple scripts from running concurrently
$lockFile = __DIR__ . '/broadcast_worker.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit("Broadcast worker already running.\n");
}

$whatsAppService = new WhatsAppService();
$waNumberRepo = new WhatsAppNumberRepository($conn);
$msgLogRepo = new MessageLogRepository($conn);

// Fetch scheduled campaigns due to send
$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare("
    SELECT * FROM broadcast_campaigns 
    WHERE status = 'scheduled' AND scheduled_at <= ? 
    ORDER BY scheduled_at ASC
");
$stmt->bind_param("s", $now);
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($result as $campaign) {
    $campaignId = (int)$campaign['id'];
    $companyId = (int)$campaign['company_id'];
    $userId = (int)$campaign['user_id'];
    $messageText = $campaign['message_text'];
    $recipientsText = $campaign['recipients'];
    
    // Update campaign status to sending
    $conn->query("UPDATE broadcast_campaigns SET status = 'sending' WHERE id = $campaignId");
    
    $recipients = array_filter(array_map('trim', explode(',', $recipientsText)));
    $total = count($recipients);
    
    // Fetch WhatsApp Number ID for the creator user
    $phoneNumberId = $waNumberRepo->getPhoneNumberIdByUserId($userId);
    
    $accessToken = "EAAZBGtwMbSu0BRxJrTffY3W0l3G3h6DVnGL3ZAkpOHemZC4VpKb937MrA112fy5VdrNCeWiyTqCZAACzoCoA7M3Pon9ZBP5syGIHBi6JmLOKEhZAGB06sxEZBD9yW8y0eVMAKzp3iwi4e7uq6m7rXN3ZBI9sZBDQNaXhCyq7A889DZA0BlHvZAZBQw3U0FzNrmKFg24g8Qx6u20ZAcfmdUwZB5Ekt7iWIpO5yceRFH1hocqmG9ZApM9cX1UFElPmXfltYutkk7ELeJ9anO0Mc60i2EqxBlt2gZDZD";
    if (defined('WHATSAPP_TOKEN')) {
        $accessToken = WHATSAPP_TOKEN;
    }
    
    if (!$phoneNumberId) {
        $conn->query("UPDATE broadcast_campaigns SET status = 'failed' WHERE id = $campaignId");
        continue;
    }
    
    $sent = 0;
    foreach ($recipients as $recipient) {
        $recipient = preg_replace('/[^0-9]/', '', $recipient);
        if (empty($recipient)) continue;
        
        $res = $whatsAppService->sendText($phoneNumberId, $recipient, $messageText, $accessToken);
        if ($res['httpCode'] != 200) {
            // Send default template as fallback
            $whatsAppService->sendTemplate($phoneNumberId, $recipient, $accessToken);
        }
        
        // Log message
        $msgLogRepo->logSentMessage($userId, $recipient, $messageText);
        
        $sent++;
        
        // Update database progress
        $uStmt = $conn->prepare("UPDATE broadcast_campaigns SET sent_contacts = ? WHERE id = ?");
        $uStmt->bind_param("ii", $sent, $campaignId);
        $uStmt->execute();
        $uStmt->close();
        
        // Sleep slightly to avoid WhatsApp API rate limits
        usleep(250000); // 250ms
    }
    
    // Mark as completed
    $conn->query("UPDATE broadcast_campaigns SET status = 'completed' WHERE id = $campaignId");
}

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);
echo "Broadcast processing completed.\n";
