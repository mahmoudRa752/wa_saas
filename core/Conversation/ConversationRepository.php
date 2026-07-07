<?php

namespace Core\Conversation;

use mysqli;

/**
 * ConversationRepository
 * Single source of truth for all conversation DB operations.
 *
 * Operates against the live `conversations` table columns:
 *   id, company_id, contact_number, last_message_at,
 *   created_at, is_pinned, created_by
 */
class ConversationRepository
{
    public function __construct(private readonly mysqli $db) {}

    /**
     * Find a conversation by company + contact number.
     */
    public function findByContact(int $companyId, string $contactNumber): ?Conversation
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM conversations WHERE company_id = ? AND contact_number = ? LIMIT 1'
        );
        $stmt->bind_param('is', $companyId, $contactNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? Conversation::fromRow($row) : null;
    }

    /**
     * Find a conversation by ID scoped to a company (multi-tenancy guard).
     */
    public function findById(int $id, int $companyId): ?Conversation
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM conversations WHERE id = ? AND company_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? Conversation::fromRow($row) : null;
    }

    /**
     * Create a new conversation and return its ID.
     * $createdBy: user_id of the employee who opened the chat (null for webhook/inbound).
     */
    public function create(int $companyId, string $contactNumber, ?int $createdBy = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO conversations (company_id, contact_number, created_by, last_message_at)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->bind_param('isi', $companyId, $contactNumber, $createdBy);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Touch last_message_at — called after every message insert.
     */
    public function touchTimestamp(int $conversationId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE conversations SET last_message_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $stmt->close();
    }
}
