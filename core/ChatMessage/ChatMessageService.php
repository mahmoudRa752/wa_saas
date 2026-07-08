<?php
namespace Core\ChatMessage;

class ChatMessageService
{
    public function __construct(private ChatMessageRepository $repo)
    {
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
