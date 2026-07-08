<?php
namespace Core\ChatMessage;

use mysqli;

class ChatMessageRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function listForConversation(int $conversationId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT cm.id, cm.direction, cm.body, cm.message_type, cm.file_path, cm.sent_at, u.name AS sender_name
            FROM chat_messages cm LEFT JOIN users u ON cm.user_id = u.id
            WHERE cm.conversation_id = ? ORDER BY cm.sent_at DESC LIMIT ?
        ");
        $stmt->bind_param('ii', $conversationId, $limit);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    public function getMessagesSince(int $conversationId, int $lastId): array
    {
        $stmt = $this->db->prepare("
            SELECT cm.id, cm.direction, cm.body, cm.message_type, cm.file_path, cm.sent_at, u.name AS sender_name
            FROM chat_messages cm LEFT JOIN users u ON cm.user_id = u.id
            WHERE cm.conversation_id = ? AND cm.id > ? ORDER BY cm.sent_at ASC
        ");
        $stmt->bind_param('ii', $conversationId, $lastId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    public function insertMessage(
        int $conversationId,
        string $direction,
        string $body,
        ?int $userId = null,
        ?string $wamid = null,
        string $messageType = 'text',
        ?string $filePath = null
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO chat_messages (conversation_id, user_id, direction, body, whatsapp_msg_id, message_type, file_path)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iisssss', $conversationId, $userId, $direction, $body, $wamid, $messageType, $filePath);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
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
