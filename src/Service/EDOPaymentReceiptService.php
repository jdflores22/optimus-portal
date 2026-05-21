<?php

namespace App\Service;

use App\Entity\EDOBilling;
use App\Entity\EDOPaymentReceipt;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOPaymentReceiptStatus;
use App\Entity\Enum\RequestStatus;
use App\Entity\User;
use App\Exception\PaymentException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service for eDO payment receipt management
 * 
 * Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 9.2
 */
class EDOPaymentReceiptService implements EDOPaymentReceiptServiceInterface
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_FILE_SIZE = 10485760; // 10MB in bytes
    private string $paymentReceiptsPath;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EDOGenerationServiceInterface $edoGenerationService,
        private EDONotificationServiceInterface $notificationService,
        private EDOAuditServiceInterface $auditService,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->paymentReceiptsPath = $projectDir . '/public/uploads/payment_receipts';
        
        // Ensure payment receipts directory exists
        if (!is_dir($this->paymentReceiptsPath)) {
            mkdir($this->paymentReceiptsPath, 0755, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitPaymentReceipt(EDOBilling $billing, UploadedFile $receiptFile, User $submitter): EDOPaymentReceipt
    {
        // Check if payment already exists for this billing
        if ($billing->getPayment() !== null) {
            throw new PaymentException('Payment receipt already submitted for this billing');
        }

        // Validate file
        if (!$this->validateReceiptFile($receiptFile)) {
            throw new PaymentException('Invalid receipt file format or size. Allowed formats: PDF, JPG, PNG (max 10MB)');
        }

        // Upload file
        $filename = $this->uploadReceiptFile($receiptFile, $billing);

        // Create payment receipt entity
        $payment = new EDOPaymentReceipt();
        $payment->setBilling($billing);
        $payment->setReceiptFilePath($filename);
        $payment->setSubmittedBy($submitter);
        $payment->setStatus(EDOPaymentReceiptStatus::SUBMITTED);

        // Update regeneration request status
        $regenerationRequest = $billing->getRegenerationRequest();
        $regenerationRequest->setStatus(RequestStatus::PAYMENT_SUBMITTED);

        // Persist payment
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        // Log payment submission
        $this->auditService->logPaymentSubmission($payment, $submitter);

        $this->logger->info('Payment receipt submitted', [
            'paymentId' => $payment->getId(),
            'billingId' => $billing->getId(),
            'submitterId' => $submitter->getId()
        ]);

        return $payment;
    }

    /**
     * {@inheritdoc}
     */
    public function confirmPayment(EDOPaymentReceipt $payment, User $accountingUser): ElectronicDeliveryOrder
    {
        if ($payment->getStatus() !== EDOPaymentReceiptStatus::SUBMITTED) {
            throw new PaymentException('Payment is not in submitted status');
        }

        // Update payment status
        $payment->setStatus(EDOPaymentReceiptStatus::CONFIRMED);
        $payment->setConfirmedBy($accountingUser);
        $payment->setConfirmedAt(new \DateTime());

        // Update regeneration request status
        $billing = $payment->getBilling();
        $regenerationRequest = $billing->getRegenerationRequest();
        $regenerationRequest->setStatus(RequestStatus::COMPLETED);

        // Get the expired eDO
        $expiredEdo = $regenerationRequest->getEdo();
        $container = $expiredEdo->getContainer();
        $manifest = $expiredEdo->getManifest();

        // Generate new eDO
        $newEdo = $this->regenerateEDO($container, $manifest, $expiredEdo);

        $this->entityManager->flush();

        // Log payment confirmation
        $this->auditService->logPaymentConfirmation($payment, $accountingUser);

        // Send notifications
        $this->notificationService->notifyEDOGenerated($newEdo);

        $this->logger->info('Payment confirmed and eDO regenerated', [
            'paymentId' => $payment->getId(),
            'oldEdoId' => $expiredEdo->getId(),
            'newEdoId' => $newEdo->getId(),
            'confirmedBy' => $accountingUser->getId()
        ]);

        return $newEdo;
    }

    /**
     * {@inheritdoc}
     */
    public function rejectPayment(EDOPaymentReceipt $payment, User $accountingUser, string $reason): void
    {
        if ($payment->getStatus() !== EDOPaymentReceiptStatus::SUBMITTED) {
            throw new PaymentException('Payment is not in submitted status');
        }

        if (empty($reason)) {
            throw new PaymentException('Rejection reason is required');
        }

        // Update payment status
        $payment->setStatus(EDOPaymentReceiptStatus::REJECTED);
        $payment->setConfirmedBy($accountingUser);
        $payment->setConfirmedAt(new \DateTime());
        $payment->setRejectionReason($reason);

        $this->entityManager->flush();

        // Log payment rejection
        $this->auditService->logPaymentRejection($payment, $accountingUser, $reason);

        $this->logger->info('Payment rejected', [
            'paymentId' => $payment->getId(),
            'rejectedBy' => $accountingUser->getId(),
            'reason' => $reason
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function validateReceiptFile(UploadedFile $file): bool
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return false;
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            return false;
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/jpg',
            'image/png'
        ];

        if (!in_array($mimeType, $allowedMimeTypes)) {
            return false;
        }

        return true;
    }

    /**
     * Upload receipt file to storage
     * 
     * @param UploadedFile $file
     * @param EDOBilling $billing
     * @return string Relative path to uploaded file
     */
    private function uploadReceiptFile(UploadedFile $file, EDOBilling $billing): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = sprintf(
            'receipt_%d_%s.%s',
            $billing->getId(),
            date('YmdHis'),
            $extension
        );

        $file->move($this->paymentReceiptsPath, $filename);

        return '/uploads/payment_receipts/' . $filename;
    }

    /**
     * Regenerate eDO after payment confirmation
     * 
     * @param \App\Entity\Container $container
     * @param \App\Entity\Manifest $manifest
     * @param ElectronicDeliveryOrder $previousEdo
     * @return ElectronicDeliveryOrder
     */
    private function regenerateEDO(
        \App\Entity\Container $container,
        \App\Entity\Manifest $manifest,
        ElectronicDeliveryOrder $previousEdo
    ): ElectronicDeliveryOrder {
        // Generate new eDO
        $newEdo = $this->edoGenerationService->generateEDOForContainer($container, $manifest);

        // Set version (increment from previous)
        $newVersion = $previousEdo->getVersion() + 1;
        $newEdo->setVersion($newVersion);

        // Link to previous version for history
        $newEdo->setPreviousVersion($previousEdo);

        // Mark previous eDO as superseded
        $previousEdo->setStatus(\App\Entity\Enum\EDOStatus::SUPERSEDED);

        $this->entityManager->persist($newEdo);

        return $newEdo;
    }
}
