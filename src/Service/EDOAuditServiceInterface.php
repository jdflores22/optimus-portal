<?php

namespace App\Service;

use App\Entity\EDOAuditLog;
use App\Entity\EDOBilling;
use App\Entity\EDOPaymentReceipt;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\RegenerationRequest;
use App\Entity\User;

/**
 * Interface for eDO audit trail service
 * 
 * Provides logging methods for all eDO lifecycle events and query capabilities
 * for compliance and troubleshooting.
 * 
 * Requirements: 14.1-14.10
 */
interface EDOAuditServiceInterface
{
    /**
     * Log eDO creation event
     * 
     * @param ElectronicDeliveryOrder $edo The created eDO
     * @param User $creator User who created the eDO
     * @return EDOAuditLog The created audit log entry
     */
    public function logEDOCreation(ElectronicDeliveryOrder $edo, User $creator): EDOAuditLog;

    /**
     * Log eDO expiration event
     * 
     * @param ElectronicDeliveryOrder $edo The expired eDO
     * @return EDOAuditLog The created audit log entry
     */
    public function logExpiration(ElectronicDeliveryOrder $edo): EDOAuditLog;

    /**
     * Log regeneration request submission
     * 
     * @param RegenerationRequest $request The regeneration request
     * @param User $requester User who submitted the request
     * @return EDOAuditLog The created audit log entry
     */
    public function logRegenerationRequest(RegenerationRequest $request, User $requester): EDOAuditLog;

    /**
     * Log billing generation event
     * 
     * @param EDOBilling $billing The generated billing
     * @param User $accountingUser User who generated the billing
     * @return EDOAuditLog The created audit log entry
     */
    public function logBillingGeneration(EDOBilling $billing, User $accountingUser): EDOAuditLog;

    /**
     * Log payment receipt submission
     * 
     * @param EDOPaymentReceipt $payment The submitted payment
     * @param User $submitter User who submitted the payment
     * @return EDOAuditLog The created audit log entry
     */
    public function logPaymentSubmission(EDOPaymentReceipt $payment, User $submitter): EDOAuditLog;

    /**
     * Log payment confirmation event
     * 
     * @param EDOPaymentReceipt $payment The confirmed payment
     * @param User $accountingUser User who confirmed the payment
     * @return EDOAuditLog The created audit log entry
     */
    public function logPaymentConfirmation(EDOPaymentReceipt $payment, User $accountingUser): EDOAuditLog;

    /**
     * Log payment rejection event
     * 
     * @param EDOPaymentReceipt $payment The rejected payment
     * @param User $accountingUser User who rejected the payment
     * @param string $reason Rejection reason
     * @return EDOAuditLog The created audit log entry
     */
    public function logPaymentRejection(EDOPaymentReceipt $payment, User $accountingUser, string $reason): EDOAuditLog;

    /**
     * Log admin unlock event
     * 
     * @param ElectronicDeliveryOrder $edo The unlocked eDO
     * @param User $admin Admin user who unlocked the eDO
     * @param string $reason Unlock reason
     * @return EDOAuditLog The created audit log entry
     */
    public function logAdminUnlock(ElectronicDeliveryOrder $edo, User $admin, string $reason): EDOAuditLog;

    /**
     * Log eDO release event
     * 
     * @param ElectronicDeliveryOrder $edo The released eDO
     * @param User $releaser User who released the eDO
     * @return EDOAuditLog The created audit log entry
     */
    public function logEDORelease(ElectronicDeliveryOrder $edo, User $releaser): EDOAuditLog;

    /**
     * Query audit logs by container number
     * 
     * @param string $containerNumber Container number to query
     * @return array<EDOAuditLog> Array of audit log entries
     */
    public function queryByContainer(string $containerNumber): array;

    /**
     * Query audit logs by eDO number
     * 
     * @param string $edoNumber eDO number to query
     * @return array<EDOAuditLog> Array of audit log entries
     */
    public function queryByEDO(string $edoNumber): array;

    /**
     * Log batch generation start event
     * 
     * @param User $user User who initiated batch generation
     * @param string $sessionId Batch session ID
     * @param int $containerCount Number of containers in batch
     * @param \DateTime $expirationDate Unified expiration date for batch
     * @param ElectronicDeliveryOrder $edo First eDO in batch (for entity reference)
     * @return EDOAuditLog The created audit log entry
     */
    public function logBatchGenerationStart(
        User $user,
        string $sessionId,
        int $containerCount,
        \DateTime $expirationDate,
        ElectronicDeliveryOrder $edo
    ): EDOAuditLog;

    /**
     * Log individual eDO generation within batch
     * 
     * @param ElectronicDeliveryOrder $edo The generated eDO
     * @param User $user User who initiated batch generation
     * @param string $sessionId Batch session ID
     * @param int $batchSequence Sequence number within batch
     * @return EDOAuditLog The created audit log entry
     */
    public function logBatchEDOGeneration(
        ElectronicDeliveryOrder $edo,
        User $user,
        string $sessionId,
        int $batchSequence
    ): EDOAuditLog;

    /**
     * Log batch generation completion event
     * 
     * @param User $user User who initiated batch generation
     * @param string $sessionId Batch session ID
     * @param int $successCount Number of successful generations
     * @param int $failureCount Number of failed generations
     * @param ElectronicDeliveryOrder $edo Last eDO in batch (for entity reference)
     * @return EDOAuditLog The created audit log entry
     */
    public function logBatchGenerationCompletion(
        User $user,
        string $sessionId,
        int $successCount,
        int $failureCount,
        ElectronicDeliveryOrder $edo
    ): EDOAuditLog;

    /**
     * Log batch generation cancellation event
     * 
     * @param User $user User who cancelled batch generation
     * @param string $sessionId Batch session ID
     * @param ElectronicDeliveryOrder $edo Last completed eDO (for entity reference)
     * @return EDOAuditLog The created audit log entry
     */
    public function logBatchGenerationCancellation(
        User $user,
        string $sessionId,
        ElectronicDeliveryOrder $edo
    ): EDOAuditLog;

    /**
     * Query audit logs by batch session ID
     * 
     * @param string $sessionId Batch session ID to query
     * @return array<EDOAuditLog> Array of audit log entries for the session
     */
    public function queryByBatchSession(string $sessionId): array;
}
