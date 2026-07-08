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
