<?php
namespace Core\ChatMessage;

class ChatMessageService
{
    public function __construct(private ChatMessageRepository $repo)
    {
    }

    public function listForConversation(int $conversationId, int $limit = 50): array
    {
        return $this->repo->listForConversation($conversationId, $limit);
    }

    public function getMessagesSince(int $conversationId, int $lastId): array
    {
        return $this->repo->getMessagesSince($conversationId, $lastId);
    }

    public function insertMessage(
        int $conversationId,
        string $direction,
        string $body,
        ?int $userId = null,
        ?string $wamid = null,
        string $messageType = 'text',
        ?string $filePath = null
    ): int {
        return $this->repo->insertMessage($conversationId, $direction, $body, $userId, $wamid, $messageType, $filePath);
    }

    public function getWamid(int $messageId): ?string
    {
        return $this->repo->getWamid($messageId);
    }

    public function updateBody(int $messageId, string $newBody): void
    {
        $this->repo->updateBody($messageId, $newBody);
    }

    public function delete(int $messageId): void
    {
        $this->repo->deleteById($messageId);
    }
}
