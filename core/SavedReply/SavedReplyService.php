<?php

namespace Core\SavedReply;

use Core\TenantContext;

/**
 * SavedReplyService
 * Orchestrates saved-reply lifecycle.
 * All operations are tenant-scoped via TenantContext.
 */
class SavedReplyService
{
    public function __construct(
        private readonly SavedReplyRepository $repo
    ) {}

    /** @return SavedReply[] */
    public function list(TenantContext $ctx): array
    {
        return $this->repo->findAll($ctx);
    }

    /**
     * Create a new saved reply.
     * Returns the new record's ID.
     */
    public function create(TenantContext $ctx, string $title, string $body, ?int $createdBy = null): int
    {
        $title = trim($title);
        $body  = trim($body);

        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Title and body are required');
        }

        return $this->repo->create($ctx, $title, $body, $createdBy);
    }

    /**
     * Delete a saved reply owned by this tenant.
     * Returns false if not found or access denied.
     */
    public function delete(TenantContext $ctx, int $id): bool
    {
        return $this->repo->delete($id, $ctx);
    }
}
