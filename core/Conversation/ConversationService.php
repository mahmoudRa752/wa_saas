<?php

namespace Core\Conversation;

/**
 * ConversationService
 * Orchestrates conversation lifecycle.
 *
 * Entry points:
 *   - webhook/receive.php  (inbound message, no creator)
 *   - api/send_chat.php    (ownership check + touch)
 *   - dashboard/chat.php   (new_chat action, ownership check, touch)
 */
class ConversationService
{
    public function __construct(
        private readonly ConversationRepository $repo
    ) {}

    /**
     * Find or create a conversation.
     * $createdBy: pass the user_id when a human opens a new chat from the dashboard.
     *             Pass null for inbound webhook messages.
     *
     * @return int conversation ID (always valid)
     */
    public function findOrCreate(int $companyId, string $contactNumber, ?int $createdBy = null): int
    {
        $existing = $this->repo->findByContact($companyId, $contactNumber);

        if ($existing !== null) {
            return $existing->id;
        }

        return $this->repo->create($companyId, $contactNumber, $createdBy);
    }

    /**
     * Verify a conversation belongs to a company and return it.
     * Returns null if not found or access denied (multi-tenancy guard).
     */
    public function getForCompany(int $conversationId, int $companyId): ?Conversation
    {
        return $this->repo->findById($conversationId, $companyId);
    }

    /**
     * Update last_message_at after a message is stored.
     */
    public function touch(int $conversationId): void
    {
        $this->repo->touchTimestamp($conversationId);
    }

    public function updatePin(int $conversationId, int $companyId, int $isPinned): void
    {
        $this->repo->updatePin($conversationId, $companyId, $isPinned);
    }

    public function updateContactNumber(int $conversationId, int $companyId, string $contactNumber): void
    {
        $this->repo->updateContactNumber($conversationId, $companyId, $contactNumber);
    }

    public function delete(int $conversationId, int $companyId): void
    {
        $this->repo->deleteById($conversationId, $companyId);
    }
}
