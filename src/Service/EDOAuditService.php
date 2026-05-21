<?php

namespace App\Service;

use App\Entity\EDOAuditLog;
use App\Entity\EDOBilling;
use App\Entity\EDOPaymentReceipt;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\AuditEventType;
use App\Entity\RegenerationRequest;
use App\Entity\User;
use App\Repository\EDOAuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for eDO audit trail management
 * 
 * Logs all eDO lifecycle events with user identifier, timestamp, and action details
 * for compliance and troubleshooting purposes.
 * 
 * Requirements: 14.1-14.10
 */
class EDOAuditService implements EDOAuditServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EDOAuditLogRepository $auditLogRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function logEDOCreation(ElectronicDeliveryOrder $edo, User $creator): EDOAuditLog
    {
        $details = [
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'manifest_id' => $edo->getManifest()?->getId(),
            'manifest_number' => $edo->getManifest()?->getManifestNumber(),
            'status' => $edo->getStatus()->value,
            'generated_at' => $edo->getGeneratedAt()->format('Y-m-d H:i:s'),
            'expires_at' => $edo->getExpiresAt()?->format('Y-m-d H:i:s'),
            'creator_id' => $creator->getId(),
            'creator_email' => $creator->getEmail()
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::EDO_CREATED,
            $creator,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logExpiration(ElectronicDeliveryOrder $edo): EDOAuditLog
    {
        $details = [
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'expired_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'expires_at' => $edo->getExpiresAt()?->format('Y-m-d H:i:s'),
            'expired_days' => $edo->getExpiredDays(),
            'previous_status' => 'ACTIVE',
            'new_status' => $edo->getStatus()->value
        ];

        // Use system user for automated expiration events
        $systemUser = $this->getSystemUser();

        return $this->createAuditLog(
            $edo,
            AuditEventType::EDO_EXPIRED,
            $systemUser,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logRegenerationRequest(RegenerationRequest $request, User $requester): EDOAuditLog
    {
        $edo = $request->getEdo();
        
        $details = [
            'request_id' => $request->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'requester_id' => $requester->getId(),
            'requester_email' => $requester->getEmail(),
            'requester_role' => $requester->getRole()->value,
            'request_status' => $request->getStatus()->value,
            'requested_at' => $request->getRequestedAt()->format('Y-m-d H:i:s'),
            'expired_days' => $edo->getExpiredDays()
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::REGENERATION_REQUESTED,
            $requester,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logBillingGeneration(EDOBilling $billing, User $accountingUser): EDOAuditLog
    {
        $regenerationRequest = $billing->getRegenerationRequest();
        $edo = $regenerationRequest->getEdo();
        
        $details = [
            'billing_id' => $billing->getId(),
            'request_id' => $regenerationRequest->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'expired_days' => $billing->getExpiredDays(),
            'per_day_rate' => $billing->getPerDayRate(),
            'total_amount' => $billing->getTotalAmount(),
            'generated_by_id' => $accountingUser->getId(),
            'generated_by_email' => $accountingUser->getEmail(),
            'generated_at' => $billing->getCreatedAt()->format('Y-m-d H:i:s'),
            'billing_document_path' => $billing->getBillingDocumentPath()
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::BILLING_GENERATED,
            $accountingUser,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logPaymentSubmission(EDOPaymentReceipt $payment, User $submitter): EDOAuditLog
    {
        $billing = $payment->getBilling();
        $edo = $billing->getRegenerationRequest()->getEdo();
        
        $details = [
            'payment_id' => $payment->getId(),
            'billing_id' => $billing->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'receipt_file_path' => $payment->getReceiptFilePath(),
            'submitted_by_id' => $submitter->getId(),
            'submitted_by_email' => $submitter->getEmail(),
            'submitted_at' => $payment->getSubmittedAt()->format('Y-m-d H:i:s'),
            'payment_status' => $payment->getStatus()->value,
            'total_amount' => $billing->getTotalAmount()
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::PAYMENT_SUBMITTED,
            $submitter,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logPaymentConfirmation(EDOPaymentReceipt $payment, User $accountingUser): EDOAuditLog
    {
        $billing = $payment->getBilling();
        $edo = $billing->getRegenerationRequest()->getEdo();
        
        $details = [
            'payment_id' => $payment->getId(),
            'billing_id' => $billing->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'confirmed_by_id' => $accountingUser->getId(),
            'confirmed_by_email' => $accountingUser->getEmail(),
            'confirmed_at' => $payment->getConfirmedAt()?->format('Y-m-d H:i:s'),
            'payment_status' => $payment->getStatus()->value,
            'total_amount' => $billing->getTotalAmount()
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::PAYMENT_CONFIRMED,
            $accountingUser,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logPaymentRejection(EDOPaymentReceipt $payment, User $accountingUser, string $reason): EDOAuditLog
    {
        $billing = $payment->getBilling();
        $edo = $billing->getRegenerationRequest()->getEdo();
        
        $details = [
            'payment_id' => $payment->getId(),
            'billing_id' => $billing->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'rejected_by_id' => $accountingUser->getId(),
            'rejected_by_email' => $accountingUser->getEmail(),
            'rejected_at' => $payment->getConfirmedAt()?->format('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
            'payment_status' => $payment->getStatus()->value,
            'total_amount' => $billing->getTotalAmount()
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::PAYMENT_REJECTED,
            $accountingUser,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logAdminUnlock(ElectronicDeliveryOrder $edo, User $admin, string $reason): EDOAuditLog
    {
        $details = [
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'admin_id' => $admin->getId(),
            'admin_email' => $admin->getEmail(),
            'unlock_reason' => $reason,
            'unlocked_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'previous_status' => $edo->getStatus()->value,
            'expired_days' => $edo->getExpiredDays()
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::ADMIN_UNLOCKED,
            $admin,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function logEDORelease(ElectronicDeliveryOrder $edo, User $releaser): EDOAuditLog
    {
        $details = [
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'released_by_id' => $releaser->getId(),
            'released_by_email' => $releaser->getEmail(),
            'released_at' => $edo->getReleasedAt()?->format('Y-m-d H:i:s'),
            'previous_status' => 'ACTIVE',
            'new_status' => $edo->getStatus()->value
        ];

        return $this->createAuditLog(
            $edo,
            AuditEventType::EDO_RELEASED,
            $releaser,
            $details
        );
    }

    /**
     * {@inheritdoc}
     */
    public function queryByContainer(string $containerNumber): array
    {
        return $this->auditLogRepository->queryByContainer($containerNumber);
    }

    /**
     * {@inheritdoc}
     */
    public function queryByEDO(string $edoNumber): array
    {
        return $this->auditLogRepository->queryByEDO($edoNumber);
    }

    /**
     * {@inheritdoc}
     */
    public function logBatchGenerationStart(
        User $user,
        string $sessionId,
        int $containerCount,
        \DateTime $expirationDate,
        ElectronicDeliveryOrder $edo
    ): EDOAuditLog {
        $details = [
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'container_count' => $containerCount,
            'expiration_date' => $expirationDate->format('Y-m-d'),
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ];

        $auditLog = new EDOAuditLog();
        $auditLog->setEdo($edo);
        $auditLog->setContainer($edo->getContainer());
        $auditLog->setEventType(AuditEventType::BATCH_GENERATION_STARTED);
        $auditLog->setUser($user);
        $auditLog->setDetails($details);
        $auditLog->setTimestamp(new \DateTime());
        $auditLog->setBatchSessionId($sessionId);

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        $this->logger->info('Batch eDO generation started', [
            'event_type' => AuditEventType::BATCH_GENERATION_STARTED->value,
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'container_count' => $containerCount,
            'audit_log_id' => $auditLog->getId()
        ]);

        return $auditLog;
    }

    /**
     * {@inheritdoc}
     */
    public function logBatchEDOGeneration(
        ElectronicDeliveryOrder $edo,
        User $user,
        string $sessionId,
        int $batchSequence
    ): EDOAuditLog {
        $details = [
            'session_id' => $sessionId,
            'batch_sequence' => $batchSequence,
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'manifest_id' => $edo->getManifest()?->getId(),
            'manifest_number' => $edo->getManifest()?->getManifestNumber(),
            'status' => $edo->getStatus()->value,
            'generated_at' => $edo->getGeneratedAt()->format('Y-m-d H:i:s'),
            'expires_at' => $edo->getExpiresAt()?->format('Y-m-d H:i:s'),
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail()
        ];

        $auditLog = new EDOAuditLog();
        $auditLog->setEdo($edo);
        $auditLog->setContainer($edo->getContainer());
        $auditLog->setEventType(AuditEventType::EDO_CREATED);
        $auditLog->setUser($user);
        $auditLog->setDetails($details);
        $auditLog->setTimestamp(new \DateTime());
        $auditLog->setBatchSessionId($sessionId);
        $auditLog->setBatchSequence($batchSequence);

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        $this->logger->info('eDO generated in batch', [
            'event_type' => AuditEventType::EDO_CREATED->value,
            'session_id' => $sessionId,
            'batch_sequence' => $batchSequence,
            'edo_id' => $edo->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'container_number' => $edo->getContainer()->getContainerNumber(),
            'audit_log_id' => $auditLog->getId()
        ]);

        return $auditLog;
    }

    /**
     * {@inheritdoc}
     */
    public function logBatchGenerationCompletion(
        User $user,
        string $sessionId,
        int $successCount,
        int $failureCount,
        ElectronicDeliveryOrder $edo
    ): EDOAuditLog {
        $details = [
            'session_id' => $sessionId,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'total_count' => $successCount + $failureCount,
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'completed_at' => (new \DateTime())->format('Y-m-d H:i:s')
        ];

        $auditLog = new EDOAuditLog();
        $auditLog->setEdo($edo);
        $auditLog->setContainer($edo->getContainer());
        $auditLog->setEventType(AuditEventType::BATCH_GENERATION_COMPLETED);
        $auditLog->setUser($user);
        $auditLog->setDetails($details);
        $auditLog->setTimestamp(new \DateTime());
        $auditLog->setBatchSessionId($sessionId);

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        $this->logger->info('Batch eDO generation completed', [
            'event_type' => AuditEventType::BATCH_GENERATION_COMPLETED->value,
            'session_id' => $sessionId,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'audit_log_id' => $auditLog->getId()
        ]);

        return $auditLog;
    }

    /**
     * {@inheritdoc}
     */
    public function logBatchGenerationCancellation(
        User $user,
        string $sessionId,
        ElectronicDeliveryOrder $edo
    ): EDOAuditLog {
        $details = [
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'cancelled_at' => (new \DateTime())->format('Y-m-d H:i:s')
        ];

        $auditLog = new EDOAuditLog();
        $auditLog->setEdo($edo);
        $auditLog->setContainer($edo->getContainer());
        $auditLog->setEventType(AuditEventType::BATCH_GENERATION_CANCELLED);
        $auditLog->setUser($user);
        $auditLog->setDetails($details);
        $auditLog->setTimestamp(new \DateTime());
        $auditLog->setBatchSessionId($sessionId);

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        $this->logger->info('Batch eDO generation cancelled', [
            'event_type' => AuditEventType::BATCH_GENERATION_CANCELLED->value,
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'audit_log_id' => $auditLog->getId()
        ]);

        return $auditLog;
    }

    /**
     * {@inheritdoc}
     */
    public function queryByBatchSession(string $sessionId): array
    {
        return $this->auditLogRepository->queryByBatchSession($sessionId);
    }

    /**
     * Create and persist an audit log entry
     * 
     * @param ElectronicDeliveryOrder $edo
     * @param AuditEventType $eventType
     * @param User $user
     * @param array $details
     * @return EDOAuditLog
     */
    private function createAuditLog(
        ElectronicDeliveryOrder $edo,
        AuditEventType $eventType,
        User $user,
        array $details
    ): EDOAuditLog {
        $auditLog = new EDOAuditLog();
        $auditLog->setEdo($edo);
        $auditLog->setContainer($edo->getContainer());
        $auditLog->setEventType($eventType);
        $auditLog->setUser($user);
        $auditLog->setDetails($details);
        $auditLog->setTimestamp(new \DateTime());

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        $this->logger->info('eDO audit log created', [
            'event_type' => $eventType->value,
            'edo_id' => $edo->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'user_id' => $user->getId(),
            'audit_log_id' => $auditLog->getId()
        ]);

        return $auditLog;
    }

    /**
     * Get or create system user for automated events
     * 
     * @return User
     */
    private function getSystemUser(): User
    {
        // Try to find existing system user
        $systemUser = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'system@edo.local']);

        if (!$systemUser) {
            // This should ideally be created during system setup
            // For now, we'll throw an exception to indicate configuration issue
            throw new \RuntimeException(
                'System user not found. Please create a system user with email "system@edo.local"'
            );
        }

        return $systemUser;
    }
}
