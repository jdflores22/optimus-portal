<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\PreAdviceRequest;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Legacy notification service for accreditation workflow
 * Extended to support FREE-ADVICE request notifications
 */
class NotificationService
{
    public function __construct(
        private ?EntityManagerInterface $entityManager = null,
        private ?InAppNotificationService $inAppNotificationService = null,
        private ?LoggerInterface $logger = null
    ) {
        // Stub implementation with optional dependencies
    }

    public function sendAccreditationStatusChange(User $user, AccreditationStatus $status, ?string $message = null): void
    {
        // Stub implementation - in production this would send actual notifications
    }

    public function sendBrokerLinkageNotification($broker, $consignee): void
    {
        // Stub implementation - in production this would send actual notifications
    }

    public function sendAccountLockNotification(User $user): void
    {
        // Stub implementation - in production this would send actual notifications
    }

    /**
     * Send notification to trucker when FREE-ADVICE is submitted
     */
    public function sendPreAdviceSubmitted(PreAdviceRequest $preAdvice): void
    {
        if (!$this->inAppNotificationService) {
            return;
        }

        try {
            $this->inAppNotificationService->createNotification(
                $preAdvice->getTrucker(),
                'FREE-ADVICE Request Submitted',
                sprintf(
                    'Your FREE-ADVICE request for container %s has been submitted and is pending review by the terminal team.',
                    $preAdvice->getContainer()->getContainerNumber()
                ),
                'pre_advice',
                $preAdvice->getId()
            );
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send FREE-ADVICE submitted notification', [
                'error' => $e->getMessage(),
                'preAdviceId' => $preAdvice->getId()
            ]);
        }
    }

    /**
     * Send notification to Terminal Team when new FREE-ADVICE request is created
     */
    public function sendPreAdviceNewRequest(PreAdviceRequest $preAdvice): void
    {
        if (!$this->inAppNotificationService || !$this->entityManager) {
            return;
        }

        try {
            // Get shipping line from the pre-advice request
            $shippingLine = $preAdvice->getShippingLine();
            
            if (!$shippingLine) {
                $this->logger?->warning('Cannot send Terminal Team notification - no shipping line associated', [
                    'preAdviceId' => $preAdvice->getId()
                ]);
                return;
            }

            // Find all Terminal Team users associated with this shipping line
            $terminalTeamUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.shippingLineAdmin IS NOT NULL')
                ->setParameter('role', UserRole::TERMINAL_TEAM)
                ->getQuery()
                ->getResult();

            // Filter users by shipping line scope
            foreach ($terminalTeamUsers as $user) {
                if (method_exists($user, 'getShippingLineScope')) {
                    $userShippingLine = $user->getShippingLineScope();
                    
                    if ($userShippingLine && $userShippingLine->getId() === $shippingLine->getId()) {
                        $this->inAppNotificationService->createNotification(
                            $user,
                            'New FREE-ADVICE Request',
                            sprintf(
                                'New FREE-ADVICE request from %s for container %s requires your review.',
                                $preAdvice->getTrucker()->getEmail(),
                                $preAdvice->getContainer()->getContainerNumber()
                            ),
                            'pre_advice',
                            $preAdvice->getId()
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send Terminal Team notification', [
                'error' => $e->getMessage(),
                'preAdviceId' => $preAdvice->getId()
            ]);
        }
    }

    /**
     * Send notification to trucker when FREE-ADVICE is approved
     */
    public function sendPreAdviceApproved(PreAdviceRequest $preAdvice): void
    {
        if (!$this->inAppNotificationService) {
            return;
        }

        try {
            $this->inAppNotificationService->createNotification(
                $preAdvice->getTrucker(),
                'FREE-ADVICE Request Approved',
                sprintf(
                    'Your FREE-ADVICE request for container %s has been approved. EDO will be generated after payment verification.',
                    $preAdvice->getContainer()->getContainerNumber()
                ),
                'pre_advice',
                $preAdvice->getId()
            );
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send FREE-ADVICE approved notification', [
                'error' => $e->getMessage(),
                'preAdviceId' => $preAdvice->getId()
            ]);
        }
    }

    /**
     * Send notification to trucker when FREE-ADVICE is rejected
     */
    public function sendPreAdviceRejected(PreAdviceRequest $preAdvice): void
    {
        if (!$this->inAppNotificationService) {
            return;
        }

        try {
            $this->inAppNotificationService->createNotification(
                $preAdvice->getTrucker(),
                'FREE-ADVICE Request Rejected',
                sprintf(
                    'Your FREE-ADVICE request for container %s has been rejected. Reason: %s',
                    $preAdvice->getContainer()->getContainerNumber(),
                    $preAdvice->getRejectionReason() ?? 'Not specified'
                ),
                'pre_advice',
                $preAdvice->getId()
            );
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send FREE-ADVICE rejected notification', [
                'error' => $e->getMessage(),
                'preAdviceId' => $preAdvice->getId()
            ]);
        }
    }

    /**
     * Send notification to trucker when EDO is ready
     */
    public function sendPreAdviceEDOReady(PreAdviceRequest $preAdvice): void
    {
        if (!$this->inAppNotificationService) {
            return;
        }

        try {
            $this->inAppNotificationService->createNotification(
                $preAdvice->getTrucker(),
                'EDO Ready for Download',
                sprintf(
                    'Your EDO (Electronic Delivery Order) for container %s is ready. EDO Number: %s',
                    $preAdvice->getContainer()->getContainerNumber(),
                    $preAdvice->getEdoNumber()
                ),
                'pre_advice',
                $preAdvice->getId()
            );
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send EDO ready notification', [
                'error' => $e->getMessage(),
                'preAdviceId' => $preAdvice->getId()
            ]);
        }
    }
}