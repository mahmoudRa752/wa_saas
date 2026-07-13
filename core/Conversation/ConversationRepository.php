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
    /**
     * Update pin status.
     */
    public function updatePin(int $id, int $companyId, int $isPinned): void
    {
        $stmt = $this->db->prepare('UPDATE conversations SET is_pinned = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('iii', $isPinned, $id, $companyId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update contact number.
     */
    public function updateContactNumber(int $id, int $companyId, string $contactNumber): void
    {
        $stmt = $this->db->prepare('UPDATE conversations SET contact_number = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('sii', $contactNumber, $id, $companyId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete conversation.
     */
    public function deleteById(int $id, int $companyId): void
    {
        $stmt = $this->db->prepare('DELETE FROM conversations WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update conversation status.
     */
    public function updateStatus(int $id, int $companyId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE conversations SET status = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('sii', $status, $id, $companyId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update conversation assignee.
     */
    public function updateAssignee(int $id, int $companyId, ?int $assignedTo): void
    {
        if ($assignedTo === null || $assignedTo <= 0) {
            $stmt = $this->db->prepare('UPDATE conversations SET assigned_to = NULL WHERE id = ? AND company_id = ?');
            $stmt->bind_param('ii', $id, $companyId);
        } else {
            $stmt = $this->db->prepare('UPDATE conversations SET assigned_to = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('iii', $assignedTo, $id, $companyId);
        }
        $stmt->execute();
        $stmt->close();
    }

    private function buildIsolationClause(bool $isAdmin, int $userId, bool $hasCreatedBy = true): array
    {
        if ($isAdmin) {
            return ['sql' => '', 'types' => '', 'params' => []];
        }
        if ($hasCreatedBy) {
            $sql = " AND (c.created_by = ? OR c.assigned_to = ? OR c.id IN (
                        SELECT DISTINCT cm.conversation_id FROM chat_messages cm WHERE cm.user_id = ?
                    )) ";
            return ['sql' => $sql, 'types' => 'iii', 'params' => [$userId, $userId, $userId]];
        }
        $sql = " AND (c.assigned_to = ? OR c.id IN (
                    SELECT DISTINCT cm.conversation_id FROM chat_messages cm WHERE cm.user_id = ?
                )) ";
        return ['sql' => $sql, 'types' => 'ii', 'params' => [$userId, $userId]];
    }

    public function checkAccess(int $convId, int $companyId, bool $isAdmin, ?int $userId, bool $hasCreatedBy = true): bool
    {
        $iso    = $this->buildIsolationClause($isAdmin, (int) $userId, $hasCreatedBy);
        $sql    = "SELECT c.id FROM conversations c WHERE c.id = ? AND c.company_id = ?" . $iso['sql'] . " LIMIT 1";
        $stmt   = $this->db->prepare($sql);
        $types  = 'ii' . $iso['types'];
        $params = array_merge([$convId, $companyId], $iso['params']);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }

    public function getActiveConversation(int $convId, int $companyId, bool $isAdmin, ?int $userId, bool $hasCreatedBy = true): ?array
    {
        $iso = $this->buildIsolationClause($isAdmin, (int) $userId, $hasCreatedBy);
        $sql = "SELECT c.* FROM conversations c WHERE c.id = ? AND c.company_id = ?" . $iso['sql'] . " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $types = 'ii' . $iso['types'];
        $params = array_merge([$convId, $companyId], $iso['params']);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function listWithDetails(int $companyId, bool $isAdmin, ?int $userId, bool $hasCreatedBy = true, int $limit = 50, ?string $statusFilter = null, ?array $segmentCriteria = null): array
    {
        $iso = $this->buildIsolationClause($isAdmin, (int) $userId, $hasCreatedBy);
        $statusSql = "";
        $statusParams = [];
        $statusTypes = "";
        if ($statusFilter !== null) {
            $statusSql = " AND c.status = ? ";
            $statusTypes = "s";
            $statusParams = [$statusFilter];
        }

        $segmentSql = "";
        if ($segmentCriteria !== null) {
            if (!empty($segmentCriteria['status'])) {
                $segmentSql .= " AND c.status = '" . $this->db->real_escape_string($segmentCriteria['status']) . "' ";
            }
            if (isset($segmentCriteria['assigned_to'])) {
                if ($segmentCriteria['assigned_to'] === 'unassigned' || $segmentCriteria['assigned_to'] === '') {
                    $segmentSql .= " AND c.assigned_to IS NULL ";
                } elseif ($segmentCriteria['assigned_to'] > 0) {
                    $segmentSql .= " AND c.assigned_to = " . (int)$segmentCriteria['assigned_to'] . " ";
                }
            }
            if (!empty($segmentCriteria['tag_id'])) {
                $segmentSql .= " AND c.id IN (SELECT conversation_id FROM conversation_tags WHERE tag_id = " . (int)$segmentCriteria['tag_id'] . ") ";
            }
        }

        $sql = "
            SELECT c.id, c.contact_number, c.last_message_at, c.is_pinned, c.status, c.assigned_to,
                (SELECT cm.body FROM chat_messages cm WHERE cm.conversation_id = c.id
                    ORDER BY cm.sent_at DESC LIMIT 1) AS last_message,
                (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = c.id
                    AND cm.direction = 'in' AND cm.sent_at > IFNULL((SELECT MAX(cm2.sent_at)
                        FROM chat_messages cm2 WHERE cm2.conversation_id = c.id AND cm2.direction = 'out'), '2000-01-01')
                ) AS unread_count
            FROM conversations c
            WHERE c.company_id = ?" . $iso['sql'] . $statusSql . $segmentSql . "
            ORDER BY c.is_pinned DESC, c.last_message_at DESC
            LIMIT ?
        ";
        $stmt = $this->db->prepare($sql);
        $types = 'i' . $iso['types'] . $statusTypes . 'i';
        $params = array_merge([$companyId], $iso['params'], $statusParams, [$limit]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }
}
