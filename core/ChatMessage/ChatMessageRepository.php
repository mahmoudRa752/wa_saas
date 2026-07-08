<?php
namespace Core\ChatMessage;

use mysqli;

class ChatMessageRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function getWamid(int $messageId): ?string
    {
        $stmt = $this->db->prepare("SELECT whatsapp_msg_id FROM chat_messages WHERE id = ?");
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['whatsapp_msg_id'] ?? null;
    }

    public function updateBody(int $messageId, string $newBody): void
    {
        $stmt = $this->db->prepare("UPDATE chat_messages SET body = ? WHERE id = ?");
        $stmt->bind_param('si', $newBody, $messageId);
        $stmt->execute();
        $stmt->close();
    }

    public function deleteById(int $messageId): void
    {
        $stmt = $this->db->prepare("DELETE FROM chat_messages WHERE id = ?");
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $stmt->close();
    }
}
