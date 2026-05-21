<?php

namespace App\Message;

/**
 * Message for async in-app notification creation
 */
class SendInAppNotificationMessage
{
    public function __construct(
        private int $userId,
        private string $title,
        private string $message,
        private string $type,
        private array $metadata
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
