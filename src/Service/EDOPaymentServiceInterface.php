<?php

namespace App\Service;

use App\Entity\EDOPayment;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface EDOPaymentServiceInterface
{
    /**
     * Submit an eDO access payment (legacy manifest-based workflow)
     *
     * @param int $manifestId The manifest ID
     * @param UploadedFile $receipt The payment receipt file
     * @param User $broker The broker submitting the payment
     * @return EDOPayment The created payment entity
     * @throws \InvalidArgumentException If manifest not found, EDO not found, or payment already exists
     */
    public function submitEDOAccessPayment(int $manifestId, UploadedFile $receipt, User $broker): EDOPayment;

    /**
     * Validate an eDO access payment (legacy manifest-based workflow)
     *
     * @param int $paymentId The payment ID
     * @param bool $approved Whether the payment is approved
     * @param string|null $reason The rejection reason (required if not approved)
     * @param User $systemAdmin The SYSTEM_ADMIN validating the payment
     * @throws \InvalidArgumentException If payment not found or rejection reason missing
     */
    public function validateEDOAccessPayment(int $paymentId, bool $approved, ?string $reason, User $systemAdmin): void;

    /**
     * Get all pending eDO access payments (legacy manifest-based workflow)
     *
     * @return EDOPayment[] Array of pending eDO payments with eager-loaded relationships
     */
    public function getPendingEDOAccessPayments(): array;

    /**
     * Get an eDO payment by ID
     *
     * @param int $paymentId The payment ID
     * @return EDOPayment|null The payment entity or null if not found
     */
    public function getEDOPaymentById(int $paymentId): ?EDOPayment;
    
    /**
     * Get all verified eDO payments (legacy manifest-based workflow)
     *
     * @return EDOPayment[] Array of verified eDO payments
     */
    public function getVerifiedEDOPayments(): array;

    // ========== Per-Container Payment Workflow Methods ==========

    /**
     * Submit payment for specific eDO (per-container workflow)
     * 
     * @param ElectronicDeliveryOrder $edo The eDO to pay for
     * @param UploadedFile $receiptFile The payment receipt file
     * @param User $broker The broker submitting the payment
     * @return EDOPayment The created payment entity
     * @throws \App\Exception\EDOPaymentException If eDO already has payment submitted or is released
     * @throws \App\Exception\FileUploadException If receipt file validation fails
     */
    public function submitPayment(
        ElectronicDeliveryOrder $edo,
        UploadedFile $receiptFile,
        User $broker
    ): EDOPayment;

    /**
     * Approve eDO payment and release eDO (per-container workflow)
     * 
     * @param EDOPayment $payment The payment to approve
     * @param User $systemAdmin The system admin approving the payment
     * @throws \App\Exception\EDOPaymentException If payment is not in PENDING_VALIDATION status
     */
    public function approvePayment(EDOPayment $payment, User $systemAdmin): void;

    /**
     * Reject eDO payment with reason (per-container workflow)
     * 
     * @param EDOPayment $payment The payment to reject
     * @param string $rejectionReason The reason for rejection
     * @param User $systemAdmin The system admin rejecting the payment
     * @throws \App\Exception\EDOPaymentException If payment is not in PENDING_VALIDATION status
     * @throws \InvalidArgumentException If rejection reason is less than 10 characters
     */
    public function rejectPayment(
        EDOPayment $payment,
        string $rejectionReason,
        User $systemAdmin
    ): void;

    /**
     * Get all eDOs for broker's manifests (per-container workflow)
     * 
     * @param User $broker The broker user
     * @param string|null $statusFilter Optional status filter
     * @return ElectronicDeliveryOrder[] Array of eDOs with eager-loaded relationships
     */
    public function getBrokerEDOs(User $broker, ?string $statusFilter = null): array;

    /**
     * Get all pending eDO payments for system admin (per-container workflow)
     * 
     * @return EDOPayment[] Array of pending payments with eager-loaded relationships
     */
    public function getPendingPayments(): array;

    /**
     * Get payment history for specific eDO (per-container workflow)
     * 
     * @param ElectronicDeliveryOrder $edo The eDO to get payment history for
     * @return EDOPayment[] Array of payments ordered by creation date descending
     */
    public function getPaymentHistory(ElectronicDeliveryOrder $edo): array;
}
