<?php

namespace App\Service;

use App\Entity\EDOPayment;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\EDOStatus;
use App\Exception\EDOPaymentException;
use App\Exception\FileUploadException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class EDOPaymentService implements EDOPaymentServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileService $fileService,
        private AuditService $auditService,
        private ManifestNotificationService $notificationService,
        private ActivityLogService $activityLogService,
        private PaymentFeeConfigurationServiceInterface $paymentFeeConfigService,
        private FileUploadServiceInterface $fileUploadService,
        private OfficialReceiptGeneratorServiceInterface $officialReceiptGenerator,
        private string $projectDir
    ) {
    }

    public function submitEDOAccessPayment(int $manifestId, UploadedFile $receipt, User $broker): EDOPayment
    {
        // Validate manifest exists
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($manifestId);
        if (!$manifest) {
            throw new \InvalidArgumentException('Manifest not found');
        }

        // Validate that EDO exists
        $edo = $manifest->getEdo();
        if (!$edo) {
            throw new \InvalidArgumentException('EDO not found for manifest');
        }

        // Check for existing eDO payment
        $existingPayment = $manifest->getManifestAccessPayment();
        if ($existingPayment) {
            throw new \InvalidArgumentException('eDO payment already submitted for this manifest');
        }

        // Upload receipt file using FileService
        $storedFile = $this->fileService->uploadFile($receipt, 'receipt', $broker);
        $relativePath = $this->getRelativePath($storedFile->getEncryptedPath());

        // Get current manifest access fee from configuration
        $feeAmount = $this->paymentFeeConfigService->getCurrentManifestAccessFee();

        // Create EDOPayment entity
        $edoPayment = new EDOPayment();
        $edoPayment->setManifest($manifest);
        $edoPayment->setShippingLine($manifest->getShippingLine()); // Set shipping line from manifest
        $edoPayment->setAmount($feeAmount);
        $edoPayment->setReceiptFilePath($relativePath);
        $edoPayment->setSubmittedBy($broker);
        $edoPayment->setStatus(PaymentStatus::PENDING_VALIDATION);

        // Update EDO to reference this payment
        $edo->setEdoPayment($edoPayment);

        // Persist to database
        $this->entityManager->persist($edoPayment);
        $this->entityManager->flush();

        // Log to AuditService
        $this->auditService->logAction(
            $broker,
            'edo_payment_submission',
            'EDOPayment',
            $edoPayment->getId(),
            [
                'amount' => $feeAmount,
                'manifest_id' => $manifestId,
                'edo_number' => $edo->getEdoNumber()
            ]
        );

        // Log to ActivityLogService
        $this->activityLogService->logEDOPaymentSubmission($broker, $edoPayment, $manifest);

        // Send notification to SYSTEM_ADMIN
        $this->notificationService->notifyEDOPaymentSubmitted($edoPayment);

        return $edoPayment;
    }

    public function validateEDOAccessPayment(int $paymentId, bool $approved, ?string $reason, User $systemAdmin): void
    {
        // Validate payment exists
        $edoPayment = $this->entityManager->getRepository(EDOPayment::class)->find($paymentId);
        if (!$edoPayment) {
            throw new \InvalidArgumentException('eDO payment not found');
        }

        if ($approved) {
            // Call the approvePayment method which handles official receipt generation
            $this->approvePayment($edoPayment, $systemAdmin);
        } else {
            // Handle rejection: reject payment with reason
            if (!$reason) {
                throw new \InvalidArgumentException('Rejection reason is required');
            }
            $this->rejectPayment($edoPayment, $reason, $systemAdmin);
        }
    }

    public function getPendingEDOAccessPayments(): array
    {
        return $this->entityManager->getRepository(EDOPayment::class)
            ->createQueryBuilder('ep')
            ->leftJoin('ep.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('ep.edo', 'e')
            ->addSelect('e')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('ep.submittedBy', 's')
            ->addSelect('s')
            ->where('ep.status = :status')
            ->andWhere('e.id IS NOT NULL')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('ep.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getEDOPaymentById(int $paymentId): ?EDOPayment
    {
        return $this->entityManager->getRepository(EDOPayment::class)->find($paymentId);
    }

    public function getVerifiedEDOPayments(): array
    {
        return $this->entityManager->getRepository(EDOPayment::class)
            ->createQueryBuilder('ep')
            ->leftJoin('ep.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('ep.edo', 'e')
            ->addSelect('e')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->where('ep.status = :status')
            ->setParameter('status', PaymentStatus::VERIFIED)
            ->orderBy('ep.validatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Convert absolute file path to relative path for web access
     */
    private function getRelativePath(string $absolutePath): string
    {
        $publicDir = $this->projectDir . '/public';
        
        if (str_starts_with($absolutePath, $publicDir)) {
            return str_replace($publicDir, '', $absolutePath);
        }
        
        if (str_starts_with($absolutePath, '/uploads')) {
            return $absolutePath;
        }
        
        if (str_contains($absolutePath, '/uploads/')) {
            $parts = explode('/uploads/', $absolutePath);
            return '/uploads/' . end($parts);
        }
        
        return $absolutePath;
    }

    // ========== Per-Container Payment Workflow Methods ==========

    /**
     * Submit payment for specific eDO (per-container workflow)
     * Requirement 2.1, 2.4, 2.6, 2.7, 2.8, 2.9, 8.3, 8.4, 17.2, 17.3, 17.4
     */
    public function submitPayment(
        ElectronicDeliveryOrder $edo,
        UploadedFile $receiptFile,
        User $broker
    ): EDOPayment {
        // Step 1: Simple validation
        if ($edo->getStatus() === EDOStatus::RELEASED) {
            throw new \Exception('eDO already released');
        }

        // Step 2: Check for existing pending payment
        $existingPayment = $this->entityManager->getRepository(EDOPayment::class)
            ->findOneBy(['edo' => $edo, 'status' => PaymentStatus::PENDING_VALIDATION]);
        
        if ($existingPayment) {
            throw new \Exception('Payment already submitted for this eDO');
        }

        // Step 3: Create payment record
        $payment = new EDOPayment();
        $payment->setEdo($edo);
        $payment->setManifest($edo->getManifest());
        $payment->setShippingLine($edo->getShippingLine());
        $payment->setAmount($edo->getFeeAmount() ?? 500.0);
        $payment->setSubmittedBy($broker);
        $payment->setStatus(PaymentStatus::PENDING_VALIDATION);
        $payment->setReceiptFilePath(null); // Will update after upload

        // Step 4: Save to database to get ID
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        // Step 5: Upload file
        try {
            $receiptPath = $this->fileUploadService->storePaymentReceipt(
                $receiptFile, 
                $edo->getId(), 
                $payment->getId()
            );
            
            // Step 6: Update payment with file path
            $payment->setReceiptFilePath($receiptPath);
            
            // Step 7: Link eDO to this payment (but keep eDO status as PENDING_RELEASE)
            $edo->setEdoPayment($payment);
            
            $this->entityManager->flush();
            
        } catch (\Exception $e) {
            // If file upload fails, delete the payment record
            $this->entityManager->remove($payment);
            $this->entityManager->flush();
            throw new \Exception('File upload failed: ' . $e->getMessage());
        }

        // Step 7: Send notification (optional, can fail without breaking)
        try {
            $this->notificationService->notifyEDOPaymentSubmitted($payment);
        } catch (\Exception $e) {
            // Log but don't fail
        }

        // Step 8: Log audit (optional, can fail without breaking)
        try {
            $this->auditService->logAction(
                $broker,
                'edo_payment_submitted',
                'EDOPayment',
                $payment->getId(),
                [
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber(),
                    'amount' => $payment->getAmount(),
                ]
            );
        } catch (\Exception $e) {
            // Log but don't fail
        }

        return $payment;
    }

    /**
     * Approve eDO payment and release eDO (per-container workflow)
     * Requirement 6.1, 6.2, 6.3, 6.4, 6.5, 6.7, 20.1, 20.3
     */
    public function approvePayment(EDOPayment $payment, User $systemAdmin): void
    {
        // Validate payment status is PENDING_VALIDATION
        if ($payment->getStatus() !== PaymentStatus::PENDING_VALIDATION) {
            throw EDOPaymentException::invalidPaymentStatus(
                $payment->getStatus()->value,
                PaymentStatus::PENDING_VALIDATION->value
            );
        }

        $edo = $payment->getEdo();
        if (!$edo) {
            throw new \InvalidArgumentException('Payment has no associated eDO');
        }

        // Begin transaction
        $this->entityManager->beginTransaction();

        try {
            // Update payment status to VERIFIED
            $payment->setStatus(PaymentStatus::VERIFIED);
            $payment->setValidatedBy($systemAdmin);
            $payment->setValidatedAt(new \DateTime());

            // Update associated eDO status to RELEASED
            $edo->setStatus(EDOStatus::RELEASED);
            $edo->setReleasedBy($systemAdmin);
            $edo->setReleasedAt(new \DateTime());

            // Call OfficialReceiptGeneratorService to generate receipt PDF
            $officialReceiptPath = $this->officialReceiptGenerator->generateOfficialReceipt($payment);

            // Store official receipt path in payment record
            $payment->setOfficialReceiptPath($officialReceiptPath);

            // Flush changes
            $this->entityManager->flush();
            $this->entityManager->commit();

            // Call NotificationService to notify broker with download link
            $this->notificationService->notifyEDOPaymentValidated($payment, true);

            // Call AuditService to log "edo_payment_approved" action
            $this->auditService->logAction(
                $systemAdmin,
                'edo_payment_approved',
                'EDOPayment',
                $payment->getId(),
                [
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber(),
                    'container_number' => $edo->getContainer()?->getContainerNumber(),
                    'amount' => $payment->getAmount(),
                    'broker_id' => $payment->getSubmittedBy()->getId(),
                    'official_receipt_path' => $officialReceiptPath
                ]
            );

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Reject eDO payment with reason (per-container workflow)
     * Requirement 7.2, 7.3, 7.4, 7.5, 7.6, 7.8, 8.6
     */
    public function rejectPayment(
        EDOPayment $payment,
        string $rejectionReason,
        User $systemAdmin
    ): void {
        // Validate payment status is PENDING_VALIDATION
        if ($payment->getStatus() !== PaymentStatus::PENDING_VALIDATION) {
            throw EDOPaymentException::invalidPaymentStatus(
                $payment->getStatus()->value,
                PaymentStatus::PENDING_VALIDATION->value
            );
        }

        // Validate rejection reason has minimum 10 characters
        if (strlen(trim($rejectionReason)) < 10) {
            throw EDOPaymentException::invalidRejectionReason();
        }

        $edo = $payment->getEdo();
        if (!$edo) {
            throw new \InvalidArgumentException('Payment has no associated eDO');
        }

        // Begin transaction
        $this->entityManager->beginTransaction();

        try {
            // Update payment status to REJECTED
            $payment->setStatus(PaymentStatus::REJECTED);
            $payment->setValidatedBy($systemAdmin);
            $payment->setValidatedAt(new \DateTime());
            $payment->setRejectionReason($rejectionReason);

            // Update associated eDO status to PENDING_RELEASE
            $edo->setStatus(EDOStatus::PENDING_RELEASE);

            // Flush changes (retain rejected payment record for audit purposes)
            $this->entityManager->flush();
            $this->entityManager->commit();

            // Call NotificationService to notify broker with rejection reason
            $this->notificationService->notifyEDOPaymentValidated($payment, false, $rejectionReason);

            // Call AuditService to log "edo_payment_rejected" action
            $this->auditService->logAction(
                $systemAdmin,
                'edo_payment_rejected',
                'EDOPayment',
                $payment->getId(),
                [
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber(),
                    'container_number' => $edo->getContainer()?->getContainerNumber(),
                    'amount' => $payment->getAmount(),
                    'broker_id' => $payment->getSubmittedBy()->getId(),
                    'rejection_reason' => $rejectionReason
                ]
            );

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Get all eDOs for broker's manifests (per-container workflow)
     * Requirement 1.1, 1.4, 4.1, 18.2
     */
    public function getBrokerEDOs(User $broker, ?string $statusFilter = null): array
    {
        $status = null;
        if ($statusFilter !== null) {
            $status = EDOStatus::tryFrom($statusFilter);
        }

        return $this->entityManager->getRepository(ElectronicDeliveryOrder::class)
            ->findByBrokerWithPayments($broker, $status);
    }

    /**
     * Get all pending eDO payments for system admin (per-container workflow)
     * Requirement 4.1
     */
    public function getPendingPayments(): array
    {
        return $this->entityManager->getRepository(EDOPayment::class)
            ->findPendingPaymentsWithRelations();
    }

    /**
     * Get payment history for specific eDO (per-container workflow)
     * Requirement 18.2
     */
    public function getPaymentHistory(ElectronicDeliveryOrder $edo): array
    {
        return $this->entityManager->getRepository(EDOPayment::class)
            ->findByEDO($edo);
    }
}
