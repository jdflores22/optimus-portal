<?php

namespace App\MessageHandler;

use App\Message\SendInAppNotificationMessage;
use App\Service\InAppNotificationService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
class SendInAppNotificationMessageHandler
{
    public function __construct(
        private InAppNotificationService $notificationService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(SendInAppNotificationMessage $message): void
    {
        try {
            $this->logger->info('Processing async in-app notification', [
                'user_id' => $message->getUserId(),
                'type' => $message->getType()
            ]);

            $user = $this->entityManager->getRepository(User::class)->find($message->getUserId());
            
            if (!$user) {
                $this->logger->warning('User not found for in-app notification', [
                    'user_id' => $message->getUserId()
                ]);
                return;
            }

            $this->notificationService->createNotification(
                $user,
                $message->getTitle(),
                $message->getMessage(),
                $message->getType(),
                $message->getMetadata()
            );

            $this->logger->info('In-app notification created successfully', [
                'user_id' => $message->getUserId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create in-app notification', [
                'user_id' => $message->getUserId(),
                'error' => $e->getMessage()
            ]);
            throw $e; // Re-throw to trigger retry
        }
    }
}
