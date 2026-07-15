<?php
namespace Core\MessageLog;

use mysqli;

class MessageLogRepository
{
    public function __construct(private mysqli $conn)
    {
    }

    public function logSentMessage(int $userId, string $recipient, string $message): bool
    {
        $stmt = $this->conn->prepare("INSERT INTO messages (user_id, recipient, message, status) VALUES (?, ?, ?, 'sent')");
        $stmt->bind_param("iss", $userId, $recipient, $message);
        $result = $stmt->execute();
        $stmt->close();

        // Log outgoing WhatsApp message in active CRM Deal timeline
        $companyId = 0;
        $userStmt = $this->conn->prepare("SELECT company_id FROM users WHERE id = ? LIMIT 1");
        if ($userStmt) {
            $userStmt->bind_param("i", $userId);
            $userStmt->execute();
            $userRow = $userStmt->get_result()->fetch_assoc();
            $companyId = $userRow ? (int)$userRow['company_id'] : 0;
            $userStmt->close();
        }
        if ($companyId > 0) {
            require_once(__DIR__ . '/../TenantContext.php');
            require_once(__DIR__ . '/../Customer/Customer.php');
            require_once(__DIR__ . '/../Customer/CustomerRepository.php');
            require_once(__DIR__ . '/../Deal/Deal.php');
            require_once(__DIR__ . '/../Deal/DealRepository.php');
            require_once(__DIR__ . '/../Deal/DealService.php');
            
            $custRepo = new \Core\Customer\CustomerRepository($this->conn);
            $dealRepo = new \Core\Deal\DealRepository($this->conn);
            $dealService = new \Core\Deal\DealService($dealRepo, $custRepo);
            $dealService->logWhatsAppActivity($companyId, $recipient, 'out', $message);
        }

        return $result;
    }

    public function getTotalMessagesForCompany(int $companyId): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE u.company_id = ?
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total;
    }

    public function getRecentMessagesForCompany(int $companyId, int $limit = 5): array
    {
        $stmt = $this->conn->prepare("
            SELECT m.recipient, m.status, m.sent_at
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE u.company_id = ?
            ORDER BY m.sent_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $companyId, $limit);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }
}
