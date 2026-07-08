<?php

namespace Core\ConversationNote;

/**
 * ConversationNote — internal, tenant-scoped note attached to a conversation.
 */
class ConversationNote
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly int $conversationId,
        public readonly string $body,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            companyId: (int) $row['company_id'],
            conversationId: (int) $row['conversation_id'],
            body: (string) $row['body'],
            createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? $row['created_at'] ?? '')
        );
    }
}
