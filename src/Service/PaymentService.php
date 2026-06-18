<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\WorkflowState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PaymentService implements PaymentServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileService $fileService,
        private AuditService $auditService,
        private ManifestNotificationService $notificationService,
        private ActivityLogService $activityLogService,
        private PaymentFeeConfigurationServiceInterface $paymentFeeConfigService,
        private OfficialReceiptDocumentGenerator $officialReceiptDocumentGenerator,
        private BillingDocumentGenerator $billingDocumentGenerator,
        private WorkflowOrchestrator $workflowOrchestrator,
        private string $projectDir
    ) {
    }

    public function submitFinalPayment(int $manifestId, float $amount, UploadedFile $receipt, User $broker): Payment
    {
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($manifestId);
        if (!$manifest) {
            throw new \InvalidArgumentException('Manifest not found');
        }

        // Validate workflow state
        if ($manifest->getWorkflowState() !== WorkflowState::BILLING_GENERATED) {
            throw new \InvalidArgumentException('Manifest must be in billing_generated state');
        }

        // Validate amount against billing
        $billing = $manifest->getBilling();
        if (!$billing) {
            throw new \InvalidArgumentException('Billing not found for manifest');
        }

        // Extract currency from billing (defaults to 'PHP' for legacy data)
        $currency = $billing->getOriginalCurrency() ?? 'PHP';

        if (abs($amount - $billing->getTotalAmount()) > 0.01) {
            // Log discrepancy but allow submission
            $this->auditService->logAction(
                $broker,
                'payment_amount_discrepancy',
                'Payment',
                0,
                [
                    'manifest_id' => $manifestId,
                    'expected_amount' => $billing->getTotalAmount(),
                    'submitted_amount' => $amount,
                    'discrepancy' => abs($amount - $billing->getTotalAmount()),
                    'currency' => $currency
                ]
            );
        }

        // Upload receipt file
        $storedFile = $this->fileService->uploadFile(
            $receipt,
            'receipt',
            $broker
        );

        // Convert absolute path to relative path for web access
        $relativePath = $this->getRelativePath($storedFile->getEncryptedPath());

        $payment = new Payment();
        $payment->setManifest($manifest);
        $payment->setShippingLine($manifest->getShippingLine()); // Set shipping line from manifest
        $payment->setPaymentType(PaymentType::FINAL_PAYMENT);
        $payment->setAmount($amount);
        $payment->setCurrency($currency); // Store currency from billing
        $payment->setReceiptFilePath($relativePath);
        $payment->setSubmittedBy($broker);
        $payment->setStatus(PaymentStatus::PENDING_VALIDATION);
        
        // Set initial version (version = 1, no previous payment)
        $payment->setVersion(1);
        $payment->setPreviousPayment(null);

        // Validate version integrity before persisting
        $this->validateVersionIntegrity($payment);

        $this->entityManager->persist($payment);
        
        $this->workflowOrchestrator->transitionState(
            $manifest,
            WorkflowState::PAYMENT_SUBMITTED,
            $broker,
            'Final payment submitted'
        );
        
        $this->entityManager->flush();

        // Log payment submission with version information
        $this->auditService->logAction(
            $broker,
            'payment_submission',
            'Payment',
            $payment->getId(),
            [
                'payment_type' => PaymentType::FINAL_PAYMENT->value,
                'amount' => $amount,
                'currency' => $currency,
                'manifest_id' => $manifestId,
                'version' => 1,
                'is_initial_version' => true
            ]
        );

        // Log to activity log for notifications
        $this->activityLogService->logManifestPaymentSubmission(
            $broker,
            $payment,
            $manifest
        );

        // Notify SYSTEM_ADMIN users about the payment submission
        $this->notificationService->notifyPaymentSubmitted($payment);

        return $payment;
    }

    public function validateFinalPayment(int $paymentId, bool $approved, ?string $reason, User $accounting): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$payment) {
            throw new \InvalidArgumentException('Payment not found');
        }

        if ($payment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw new \InvalidArgumentException('Payment is not a final payment');
        }

        if ($approved) {
            $payment->verify($accounting, $reason);
            
            $manifest = $payment->getManifest();
            $this->workflowOrchestrator->transitionState(
                $manifest,
                WorkflowState::PAYMENT_VERIFIED,
                $accounting,
                $reason ?? 'Final payment approved'
            );
            
            // Flush payment and manifest changes together
            $this->entityManager->flush();

            $billing = $manifest->getBilling();
            if ($billing) {
                try {
                    $this->billingDocumentGenerator->generatePDF($billing, true);
                    $this->entityManager->flush();
                } catch (\Exception $e) {
                    $this->auditService->logAction(
                        $accounting,
                        'billing_paid_regeneration_failed',
                        'Billing',
                        $billing->getId(),
                        [
                            'error' => $e->getMessage(),
                            'manifest_id' => $manifest->getId(),
                            'payment_id' => $payment->getId(),
                        ]
                    );
                }
            }
            
            // Generate official receipt PDF from active document template
            try {
                $officialReceiptPath = $this->officialReceiptDocumentGenerator->generateOfficialReceipt($payment);
                $payment->setOfficialReceiptPath($officialReceiptPath);
                $this->entityManager->flush();
                
                // Log official receipt generation
                $this->auditService->logAction(
                    $accounting,
                    'official_receipt_generated',
                    'Payment',
                    $payment->getId(),
                    [
                        'receipt_path' => $officialReceiptPath,
                        'manifest_id' => $payment->getManifest()->getId()
                    ]
                );
            } catch (\Exception $e) {
                // Log error but don't fail the payment validation
                $this->auditService->logAction(
                    $accounting,
                    'official_receipt_generation_failed',
                    'Payment',
                    $payment->getId(),
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'manifest_id' => $payment->getManifest()->getId()
                    ]
                );
            }
            
            // Notify broker, consignee, and SL_STAFF about approval
            // SL_STAFF will now generate the eDO
            $this->notificationService->notifyPaymentValidated($payment, true);
        } else {
            if (!$reason) {
                throw new \InvalidArgumentException('Rejection reason is required');
            }
            $payment->reject($accounting, $reason);
            
            $manifest = $payment->getManifest();
            $this->workflowOrchestrator->transitionState(
                $manifest,
                WorkflowState::BILLING_GENERATED,
                $accounting,
                $reason ?? 'Final payment rejected'
            );
            $this->entityManager->flush();
            
            // Notify broker, consignee, and SL_STAFF about rejection
            $this->notificationService->notifyPaymentValidated($payment, false, $reason);
        }

        // Log payment validation with version information
        $this->auditService->logAction(
            $accounting,
            'payment_validation',
            'Payment',
            $payment->getId(),
            [
                'payment_type' => PaymentType::FINAL_PAYMENT->value,
                'approved' => $approved,
                'reason' => $reason,
                'manifest_id' => $payment->getManifest()->getId(),
                'version' => $payment->getVersion(),
                'is_resubmission' => $payment->isResubmission(),
                'previous_payment_id' => $payment->getPreviousPayment()?->getId()
            ]
        );

        // Log to activity log for notifications
        $this->activityLogService->logManifestPaymentValidation(
            $accounting,
            $payment,
            $payment->getManifest(),
            $approved
        );
    }

    public function getPendingFinalPayments(): array
    {
        return $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('m.billing', 'bill')
            ->addSelect('bill')
            ->leftJoin('p.submittedBy', 's')
            ->addSelect('s')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getPaymentById(int $paymentId): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)->find($paymentId);
    }

    public function resubmitRejectedPayment(int $paymentId, float $amount, UploadedFile $receipt, User $broker): Payment
    {
        $oldPayment = $this->entityManager->getRepository(Payment::class)->find($paymentId);
        if (!$oldPayment) {
            throw new \InvalidArgumentException('Payment not found');
        }

        if ($oldPayment->getStatus() !== PaymentStatus::REJECTED) {
            throw new \InvalidArgumentException('Only rejected payments can be resubmitted');
        }

        if ($oldPayment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw new \InvalidArgumentException('Only final payments can be resubmitted');
        }

        $manifest = $oldPayment->getManifest();

        // Validate workflow state
        if ($manifest->getWorkflowState() !== WorkflowState::BILLING_GENERATED) {
            throw new \InvalidArgumentException('Manifest must be in billing_generated state');
        }

        // Extract currency from billing (preserve currency through resubmissions)
        $billing = $manifest->getBilling();
        if (!$billing) {
            throw new \InvalidArgumentException('Billing not found for manifest');
        }
        $currency = $billing->getOriginalCurrency() ?? 'PHP';

        // Calculate next version number
        $nextVersion = $oldPayment->getVersion() + 1;

        // Upload new receipt file
        $storedFile = $this->fileService->uploadFile(
            $receipt,
            'receipt',
            $broker
        );

        // Convert absolute path to relative path for web access
        $relativePath = $this->getRelativePath($storedFile->getEncryptedPath());

        // Create new payment record with version tracking
        $payment = new Payment();
        $payment->setManifest($manifest);
        $payment->setShippingLine($manifest->getShippingLine());
        $payment->setPaymentType(PaymentType::FINAL_PAYMENT);
        $payment->setAmount($amount);
        $payment->setCurrency($currency); // Store currency from billing
        $payment->setReceiptFilePath($relativePath);
        $payment->setSubmittedBy($broker);
        $payment->setStatus(PaymentStatus::PENDING_VALIDATION);
        
        // Set version control fields
        $payment->setVersion($nextVersion);
        $payment->setPreviousPayment($oldPayment);

        // Validate version integrity before persisting
        $this->validateVersionIntegrity($payment);

        $this->entityManager->persist($payment);
        
        $this->workflowOrchestrator->transitionState(
            $manifest,
            WorkflowState::PAYMENT_SUBMITTED,
            $broker,
            'Final payment submitted'
        );
        
        $this->entityManager->flush();

        // Enhanced audit log with version information
        $this->auditService->logAction(
            $broker,
            'payment_resubmission',
            'Payment',
            $payment->getId(),
            [
                'payment_type' => PaymentType::FINAL_PAYMENT->value,
                'amount' => $amount,
                'currency' => $currency,
                'manifest_id' => $manifest->getId(),
                'version' => $nextVersion,
                'previous_version' => $oldPayment->getVersion(),
                'previous_payment_id' => $oldPayment->getId(),
                'previous_rejection_reason' => $oldPayment->getRejectionReason()
            ]
        );

        // Log to activity log for notifications
        $this->activityLogService->logManifestPaymentSubmission(
            $broker,
            $payment,
            $manifest
        );

        // Notify SYSTEM_ADMIN users about the payment resubmission
        $this->notificationService->notifyPaymentSubmitted($payment);

        return $payment;
    }

    /**
     * Convert absolute file path to relative path for web access
     */
    private function getRelativePath(string $absolutePath): string
    {
        // Remove the project directory and public folder from the path
        $publicDir = $this->projectDir . '/public';
        
        // If the path starts with the public directory, remove it
        if (str_starts_with($absolutePath, $publicDir)) {
            return str_replace($publicDir, '', $absolutePath);
        }
        
        // If the path already looks relative (starts with /uploads), return as is
        if (str_starts_with($absolutePath, '/uploads')) {
            return $absolutePath;
        }
        
        // Otherwise, try to extract the uploads part
        if (str_contains($absolutePath, '/uploads/')) {
            $parts = explode('/uploads/', $absolutePath);
            return '/uploads/' . end($parts);
        }
        
        // Fallback: return the original path
        return $absolutePath;
    }

    /**
     * Validate payment version integrity
     * Ensures version numbers are sequential and consistent
     */
    private function validateVersionIntegrity(Payment $payment): void
    {
        // Validate version number is positive
        if ($payment->getVersion() < 1) {
            throw new \InvalidArgumentException('Payment version must be at least 1');
        }

        // If this is a resubmission (has previous payment), validate the chain
        if ($payment->getPreviousPayment() !== null) {
            $previousPayment = $payment->getPreviousPayment();
            
            // Validate version is exactly previous + 1
            $expectedVersion = $previousPayment->getVersion() + 1;
            if ($payment->getVersion() !== $expectedVersion) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid version sequence. Expected version %d, got %d',
                        $expectedVersion,
                        $payment->getVersion()
                    )
                );
            }

            // Validate both payments belong to the same manifest
            if ($payment->getManifest()->getId() !== $previousPayment->getManifest()->getId()) {
                throw new \InvalidArgumentException('Payment version chain must belong to the same manifest');
            }

            // Validate both payments have the same payment type
            if ($payment->getPaymentType() !== $previousPayment->getPaymentType()) {
                throw new \InvalidArgumentException('Payment version chain must have the same payment type');
            }

            // Validate previous payment was rejected
            if ($previousPayment->getStatus() !== PaymentStatus::REJECTED) {
                throw new \InvalidArgumentException('Previous payment must be rejected to create a new version');
            }
        } else {
            // If no previous payment, this must be version 1
            if ($payment->getVersion() !== 1) {
                throw new \InvalidArgumentException('Initial payment must be version 1');
            }
        }
    }
}
