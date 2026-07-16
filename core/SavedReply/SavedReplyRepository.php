<?php

namespace Core\SavedReply;

use Core\TenantContext;
use mysqli;

/**
 * SavedReplyRepository
 * All queries are scoped to a TenantContext — never accept raw company_id.
 */
class SavedReplyRepository
{
    public function __construct(private readonly mysqli $db) {}

    /** @return SavedReply[] */
    public function findAll(TenantContext $ctx): array
    {
        $companyId = $ctx->companyId;

        $stmt = $this->db->prepare(
            'SELECT * FROM saved_replies WHERE company_id = ? ORDER BY title ASC'
        );
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(fn($r) => SavedReply::fromRow($r), $rows);
    }

    public function findById(int $id, TenantContext $ctx): ?SavedReply
    {
        $companyId = $ctx->companyId;

        $stmt = $this->db->prepare(
            'SELECT * FROM saved_replies WHERE id = ? AND company_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? SavedReply::fromRow($row) : null;
    }

    public function create(TenantContext $ctx, string $title, string $body, ?int $createdBy): int
    {
        $companyId = $ctx->companyId;

        $stmt = $this->db->prepare(
            'INSERT INTO saved_replies (company_id, title, body, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $companyId, $title, $body, $createdBy);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    public function delete(int $id, TenantContext $ctx): bool
    {
        $companyId = $ctx->companyId;

        $stmt = $this->db->prepare(
            'DELETE FROM saved_replies WHERE id = ? AND company_id = ?'
        );
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }
}
