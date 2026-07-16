<?php

namespace Core\Conversation;

/**
 * Conversation — domain entity.
 * Reflects the live `conversations` table as audited.
 *
 * Live columns: id, company_id, contact_number, last_message_at,
 *               created_at, is_pinned, created_by
 *
 * Deprecated columns (exist in live DB, never referenced in PHP):
 *   - created_by_user_id  (orphaned, do not use)
 *
 * Absent columns (in chat_schema.sql only, not in live DB):
 *   - user_id      (legacy schema artifact)
 *   - contact_name (legacy schema artifact)
 */
class Conversation
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $companyId,
        public readonly string  $contactNumber,
        public readonly string  $lastMessageAt,
        public readonly string  $createdAt,
        public readonly bool    $isPinned,
        public readonly ?int    $createdBy,
        public readonly ?int    $assignedTo,
        public readonly string  $status
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:            (int)    $row['id'],
            companyId:     (int)    $row['company_id'],
            contactNumber: (string) $row['contact_number'],
            lastMessageAt: (string) $row['last_message_at'],
            createdAt:     (string) ($row['created_at'] ?? $row['last_message_at']),
            isPinned:      (bool)   ($row['is_pinned'] ?? false),
            createdBy:     isset($row['created_by']) ? (int) $row['created_by'] : null,
            assignedTo:    isset($row['assigned_to']) ? (int) $row['assigned_to'] : null,
            status:        (string) ($row['status'] ?? 'open')
        );
    }
}
