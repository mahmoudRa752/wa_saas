<?php

namespace Core\SavedReply;

/**
 * SavedReply entity — maps to the `saved_replies` table.
 */
final class SavedReply
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $companyId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?int   $createdBy,
        public readonly string $createdAt
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int)    $row['id'],
            companyId: (int)    $row['company_id'],
            title:              $row['title'],
            body:               $row['body'],
            createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
            createdAt:          $row['created_at']
        );
    }
}
