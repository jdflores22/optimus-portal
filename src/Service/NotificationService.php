<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\PreAdviceRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\EDORenewalRequest;
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

    /**
     * Send notification when eDO expires
     * Notifies both broker and consignee
     */
    public function notifyEDOExpiration(ElectronicDeliveryOrder $edo): void
    {
        if (!$this->inAppNotificationService) {
            return;
        }

        try {
            $container = $edo->getContainer();
            $containerNumber = $container ? $container->getContainerNumber() : 'N/A';
            $expirationDate = $edo->getExpiresAt() ? $edo->getExpiresAt()->format('Y-m-d') : 'N/A';

            // Notify broker
            $broker = $edo->getManifest()->getBroker();
            if ($broker) {
                $this->inAppNotificationService->createNotification(
                    $broker,
                    'eDO Expired',
                    sprintf(
                        'Your eDO %s for container %s has expired on %s. Please request a renewal to continue operations.',
                        $edo->getEdoNumber(),
                        $containerNumber,
                        $expirationDate
                    ),
                    'edo_renewal',
                    [
                        'edo_id' => $edo->getId(),
                        'edo_number' => $edo->getEdoNumber(),
                        'container_number' => $containerNumber,
                        'expiration_date' => $expirationDate
                    ]
                );
            }

            // Notify consignee
            $consignee = $edo->getManifest()->getConsignee();
            if ($consignee) {
                $this->inAppNotificationService->createNotification(
                    $consignee,
                    'eDO Expired',
                    sprintf(
                        'eDO %s for container %s has expired on %s. Please contact your broker to request a renewal.',
                        $edo->getEdoNumber(),
                        $containerNumber,
                        $expirationDate
                    ),
                    'edo_renewal',
                    [
                        'edo_id' => $edo->getId(),
                        'edo_number' => $edo->getEdoNumber(),
                        'container_number' => $containerNumber,
                        'expiration_date' => $expirationDate
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send eDO expiration notification', [
                'error' => $e->getMessage(),
                'edoId' => $edo->getId()
            ]);
        }
    }

    /**
     * Send notification to SL staff when renewal request is created
     */
    public function notifyRenewalRequestCreated(EDORenewalRequest $renewalRequest): void
    {
        if (!$this->inAppNotificationService || !$this->entityManager) {
            return;
        }

        try {
            $expiredEdo = $renewalRequest->getExpiredEdo();
            $shippingLine = $expiredEdo->getShippingLine();
            $broker = $renewalRequest->getRequestedBy();
            $container = $expiredEdo->getContainer();
            $containerNumber = $container ? $container->getContainerNumber() : 'N/A';

            // Find all SL staff users for this shipping line
            $slStaffUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.shippingLineAdmin = :shippingLine')
                ->setParameter('role', UserRole::SL_STAFF)
                ->setParameter('shippingLine', $shippingLine)
                ->getQuery()
                ->getResult();

            $detentionInfo = '';
            if ($renewalRequest->getDetentionChargeAmount() > 0) {
                $detentionInfo = sprintf(
                    ' Detention charges: $%.2f (%d overdue days).',
                    $renewalRequest->getDetentionChargeAmount(),
                    $renewalRequest->getOverdueDays()
                );
            }

            foreach ($slStaffUsers as $slStaff) {
                $this->inAppNotificationService->createNotification(
                    $slStaff,
                    'New eDO Renewal Request',
                    sprintf(
                        'Broker %s has requested renewal for expired eDO %s (Container: %s).%s',
                        $broker->getEmail(),
                        $expiredEdo->getEdoNumber(),
                        $containerNumber,
                        $detentionInfo
                    ),
                    'edo_renewal',
                    [
                        'renewal_request_id' => $renewalRequest->getId(),
                        'expired_edo_id' => $expiredEdo->getId(),
                        'edo_number' => $expiredEdo->getEdoNumber(),
                        'broker_email' => $broker->getEmail(),
                        'detention_charge' => $renewalRequest->getDetentionChargeAmount()
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send renewal request created notification', [
                'error' => $e->getMessage(),
                'renewalRequestId' => $renewalRequest->getId()
            ]);
        }
    }

    /**
     * Send notification to SL staff when payment is verified
     */
    public function notifyPaymentVerified(EDORenewalRequest $renewalRequest): void
    {
        if (!$this->inAppNotificationService || !$this->entityManager) {
            return;
        }

        try {
            $expiredEdo = $renewalRequest->getExpiredEdo();
            $shippingLine = $expiredEdo->getShippingLine();
            $container = $expiredEdo->getContainer();
            $containerNumber = $container ? $container->getContainerNumber() : 'N/A';

            // Find all SL staff users for this shipping line
            $slStaffUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.shippingLineAdmin = :shippingLine')
                ->setParameter('role', UserRole::SL_STAFF)
                ->setParameter('shippingLine', $shippingLine)
                ->getQuery()
                ->getResult();

            foreach ($slStaffUsers as $slStaff) {
                $this->inAppNotificationService->createNotification(
                    $slStaff,
                    'Detention Payment Verified',
                    sprintf(
                        'Detention payment of $%.2f for eDO %s (Container: %s) has been verified. Ready for eDO generation.',
                        $renewalRequest->getDetentionChargeAmount(),
                        $expiredEdo->getEdoNumber(),
                        $containerNumber
                    ),
                    'edo_renewal',
                    [
                        'renewal_request_id' => $renewalRequest->getId(),
                        'expired_edo_id' => $expiredEdo->getId(),
                        'edo_number' => $expiredEdo->getEdoNumber(),
                        'payment_amount' => $renewalRequest->getDetentionChargeAmount()
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send payment verified notification', [
                'error' => $e->getMessage(),
                'renewalRequestId' => $renewalRequest->getId()
            ]);
        }
    }

    /**
     * Send notification to broker when new eDO is generated
     */
    public function notifyNewEDOGenerated(EDORenewalRequest $renewalRequest): void
    {
        if (!$this->inAppNotificationService) {
            return;
        }

        try {
            $newEdo = $renewalRequest->getNewEdo();
            if (!$newEdo) {
                $this->logger?->warning('Cannot send new eDO notification - no new eDO associated', [
                    'renewalRequestId' => $renewalRequest->getId()
                ]);
                return;
            }

            $broker = $renewalRequest->getRequestedBy();
            $container = $newEdo->getContainer();
            $containerNumber = $container ? $container->getContainerNumber() : 'N/A';
            $cyLocation = $newEdo->getCyLocation() ?? 'Not specified';
            $returnDate = $renewalRequest->getEmptyContainerReturnDate()->format('Y-m-d');

            $this->inAppNotificationService->createNotification(
                $broker,
                'New eDO Generated',
                sprintf(
                    'Your new eDO %s for container %s is ready. Return location: %s. Scheduled return date: %s.',
                    $newEdo->getEdoNumber(),
                    $containerNumber,
                    $cyLocation,
                    $returnDate
                ),
                'edo_renewal',
                [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'new_edo_id' => $newEdo->getId(),
                    'edo_number' => $newEdo->getEdoNumber(),
                    'container_number' => $containerNumber,
                    'cy_location' => $cyLocation,
                    'return_date' => $returnDate
                ]
            );
        } catch (\Exception $e) {
            $this->logger?->error('Failed to send new eDO generated notification', [
                'error' => $e->getMessage(),
                'renewalRequestId' => $renewalRequest->getId()
            ]);
        }
    }
}
