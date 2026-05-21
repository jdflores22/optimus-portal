<?php

namespace App\Message;

/**
 * Message for async email notification sending
 */
class SendEmailNotificationMessage
{
    public function __construct(
        private string $recipientEmail,
        private string $subject,
        private string $template,
        private array $context
    ) {
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
