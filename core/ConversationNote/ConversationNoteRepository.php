<?php

namespace Core\ConversationNote;

use Core\TenantContext;
use mysqli;

/**
 * ConversationNoteRepository
 * Persists conversation notes scoped to a tenant via TenantContext.
 */
class ConversationNoteRepository
{
    public function __construct(private readonly mysqli $db) {}

    /** @return ConversationNote[] */
    public function findAllForConversation(TenantContext $ctx, int $conversationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM conversation_notes WHERE company_id = ? AND conversation_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->bind_param('ii', $ctx->companyId, $conversationId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(fn($row) => ConversationNote::fromRow($row), $rows);
    }

    public function create(TenantContext $ctx, int $conversationId, string $body, ?int $createdBy): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Note body is required');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO conversation_notes (company_id, conversation_id, body, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iisi', $ctx->companyId, $conversationId, $body, $createdBy);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    public function update(TenantContext $ctx, int $noteId, string $body): bool
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Note body is required');
        }

        $stmt = $this->db->prepare(
            'UPDATE conversation_notes SET body = ? WHERE id = ? AND company_id = ?'
        );
        $stmt->bind_param('sii', $body, $noteId, $ctx->companyId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();

        return $changed;
    }

    public function delete(TenantContext $ctx, int $noteId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM conversation_notes WHERE id = ? AND company_id = ?'
        );
        $stmt->bind_param('ii', $noteId, $ctx->companyId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        return $deleted;
    }
}
