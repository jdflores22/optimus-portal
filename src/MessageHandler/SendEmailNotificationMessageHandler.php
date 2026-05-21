<?php

namespace App\MessageHandler;

use App\Message\SendEmailNotificationMessage;
use App\Service\EmailNotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
class SendEmailNotificationMessageHandler
{
    public function __construct(
        private EmailNotificationService $emailService,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(SendEmailNotificationMessage $message): void
    {
        try {
            $this->logger->info('Processing async email notification', [
                'recipient' => $message->getRecipientEmail(),
                'subject' => $message->getSubject(),
                'template' => $message->getTemplate()
            ]);

            $this->emailService->sendEmail(
                $message->getRecipientEmail(),
                $message->getSubject(),
                $message->getTemplate(),
                $message->getContext()
            );

            $this->logger->info('Email notification sent successfully', [
                'recipient' => $message->getRecipientEmail()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email notification', [
                'recipient' => $message->getRecipientEmail(),
                'error' => $e->getMessage()
            ]);
            throw $e; // Re-throw to trigger retry
        }
    }
}
