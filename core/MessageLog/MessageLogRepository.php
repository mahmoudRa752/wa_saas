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
}
