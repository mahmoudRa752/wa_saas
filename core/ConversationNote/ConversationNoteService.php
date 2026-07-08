<?php

namespace Core\ConversationNote;

use Core\TenantContext;

/**
 * ConversationNoteService
 * Orchestrates internal note CRUD for a conversation.
 */
class ConversationNoteService
{
    public function __construct(
        private readonly ConversationNoteRepository $repo
    ) {}

    /** @return ConversationNote[] */
    public function listForConversation(TenantContext $ctx, int $conversationId): array
    {
        return $this->repo->findAllForConversation($ctx, $conversationId);
    }

    public function create(TenantContext $ctx, int $conversationId, string $body, ?int $createdBy = null): int
    {
        return $this->repo->create($ctx, $conversationId, $body, $createdBy);
    }

    public function update(TenantContext $ctx, int $noteId, string $body): bool
    {
        return $this->repo->update($ctx, $noteId, $body);
    }

    public function delete(TenantContext $ctx, int $noteId): bool
    {
        return $this->repo->delete($ctx, $noteId);
    }
}
