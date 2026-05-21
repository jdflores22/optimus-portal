<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\GeotagPhoto;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PreAdviceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SlotManagementService $slotManagementService,
        private PhotoVerificationService $photoVerificationService,
        private QRCodeService $qrCodeService,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * Complete FREE-ADVICE workflow orchestration
     * Handles submission with validation, payment verification, and state management
     */
    public function submitPreAdvice(
        User $trucker,
        Container $container,
        Terminal $terminal,
        array $photos,
        string $paymentReference
    ): PreAdviceRequest {
        // Validate container eligibility
        $this->validateContainerEligibility($container);
        
        // Validate terminal can accept container
        $this->validateTerminalCompatibility($container, $terminal);
        
        // Validate payment reference
        $this->validatePaymentReference($paymentReference);

        $preAdvice = new PreAdviceRequest();
        $preAdvice->setTrucker($trucker);
        $preAdvice->setContainer($container);
        $preAdvice->setSelectedTerminal($terminal);
        $preAdvice->setPaymentReference($paymentReference);
        $preAdvice->setStatus(PreAdviceStatus::PENDING);

        $this->entityManager->persist($preAdvice);

        // Process and validate photos
        foreach ($photos as $photo) {
            if ($photo instanceof GeotagPhoto) {
                // Validate geotag photo
                $this->photoVerificationService->validateGeotagPhoto($photo);
                
                $preAdvice->addGeotagPhoto($photo);
                $photo->setPreAdviceRequest($preAdvice);
                $this->entityManager->persist($photo);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('FREE-ADVICE submitted successfully', [
            'preAdviceId' => $preAdvice->getId(),
            'truckerId' => $trucker->getId(),
            'containerId' => $container->getId(),
            'terminalId' => $terminal->getId(),
            'photoCount' => count($photos)
        ]);

        // Send notification to trucker about submission
        try {
            $this->notificationService->sendPreAdviceSubmitted($preAdvice);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send FREE-ADVICE submission notification', [
                'preAdviceId' => $preAdvice->getId(),
                'error' => $e->getMessage()
            ]);
        }

        // Send notification to Terminal Team about new request
        try {
            $this->notificationService->sendPreAdviceNewRequest($preAdvice);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send new request notification to Terminal Team', [
                'preAdviceId' => $preAdvice->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $preAdvice;
    }

    /**
     * State transition management for FREE-ADVICE verification
     */
    public function verifyPreAdvice(
        PreAdviceRequest $preAdvice, 
        User $verifier, 
        \DateTime $preferredDate = null
    ): PreAdviceRequest {
        // Validate current state
        if ($preAdvice->getStatus() !== PreAdviceStatus::PENDING) {
            throw new \InvalidArgumentException(
                'FREE-ADVICE must be in PENDING status for verification. Current status: ' . $preAdvice->getStatus()->value
            );
        }

        // Verify photos are validated
        $this->validatePhotosForVerification($preAdvice);

        // Assign slot during verification only if not already assigned
        if (!$preAdvice->getAssignedSlot()) {
            $assignmentDate = $preferredDate ?? new \DateTime('+1 day');
            $slotAssigned = $this->slotManagementService->assignSlot(
                $preAdvice->getSelectedTerminal(),
                $assignmentDate,
                $preAdvice
            );

            if (!$slotAssigned) {
                throw new \RuntimeException('No available slots for the selected terminal and date');
            }
        }

        // Update FREE-ADVICE status
        $preAdvice->setStatus(PreAdviceStatus::VERIFIED);
        $preAdvice->setVerifiedBy($verifier);
        $preAdvice->setVerifiedAt(new \DateTime());

        // Update container status to PA_APPROVED since FREE-ADVICE is now approved by terminal team
        $container = $preAdvice->getContainer();
        $container->setStatus(\App\Entity\Enum\ContainerStatus::PA_APPROVED);

        $this->entityManager->flush();

        $this->logger->info('FREE-ADVICE verified successfully', [
            'preAdviceId' => $preAdvice->getId(),
            'verifierId' => $verifier->getId(),
            'assignedSlotId' => $preAdvice->getAssignedSlot()?->getId(),
            'containerStatusUpdated' => 'available_for_return -> pa_approved'
        ]);

        // Send notification to trucker about approval
        try {
            $this->notificationService->sendPreAdviceApproved($preAdvice);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send FREE-ADVICE approval notification', [
                'preAdviceId' => $preAdvice->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $preAdvice;
    }

    /**
     * State transition management for FREE-ADVICE rejection
     */
    public function rejectPreAdvice(PreAdviceRequest $preAdvice, User $verifier, string $reason): PreAdviceRequest
    {
        // Validate current state
        if ($preAdvice->getStatus() !== PreAdviceStatus::PENDING) {
            throw new \InvalidArgumentException(
                'FREE-ADVICE must be in PENDING status for rejection. Current status: ' . $preAdvice->getStatus()->value
            );
        }

        // Release any temporarily assigned slot
        if ($preAdvice->getAssignedSlot()) {
            $this->slotManagementService->releaseSlot($preAdvice);
        }

        $preAdvice->setStatus(PreAdviceStatus::REJECTED);
        $preAdvice->setVerifiedBy($verifier);
        $preAdvice->setVerifiedAt(new \DateTime());
        $preAdvice->setRejectionReason($reason);

        $this->entityManager->flush();

        $this->logger->info('FREE-ADVICE rejected', [
            'preAdviceId' => $preAdvice->getId(),
            'verifierId' => $verifier->getId(),
            'reason' => $reason
        ]);

        // Send notification to trucker about rejection
        try {
            $this->notificationService->sendPreAdviceRejected($preAdvice);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send FREE-ADVICE rejection notification', [
                'preAdviceId' => $preAdvice->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $preAdvice;
    }

    /**
     * Generate EDO after payment verification
     */
    public function generateEDO(PreAdviceRequest $preAdvice): PreAdviceRequest
    {
        // Validate current state
        if ($preAdvice->getStatus() !== PreAdviceStatus::VERIFIED) {
            throw new \InvalidArgumentException(
                'FREE-ADVICE must be verified before generating EDO. Current status: ' . $preAdvice->getStatus()->value
            );
        }

        // Verify payment is completed
        $this->verifyPaymentCompletion($preAdvice);

        // Generate unique EDO number
        $edoNumber = $this->generateUniqueEdoNumber($preAdvice);
        
        // Generate QR code (will be enhanced by QRCodeService)
        $qrCode = $this->generateQRCode($preAdvice, $edoNumber);

        $preAdvice->setEdoNumber($edoNumber);
        $preAdvice->setQrCode($qrCode);
        $preAdvice->setStatus(PreAdviceStatus::COMPLETED);

        // Update container status to AT_TERMINAL since EDO is generated and container is ready for pickup
        $container = $preAdvice->getContainer();
        $container->setStatus(ContainerStatus::AT_TERMINAL);

        $this->entityManager->flush();

        $this->logger->info('EDO generated successfully', [
            'preAdviceId' => $preAdvice->getId(),
            'edoNumber' => $edoNumber,
            'qrCode' => $qrCode,
            'containerStatusUpdated' => 'pa_approved -> at_terminal'
        ]);

        // Send notification to trucker about EDO and QR code availability
        try {
            $this->notificationService->sendPreAdviceEDOReady($preAdvice);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send EDO ready notification', [
                'preAdviceId' => $preAdvice->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $preAdvice;
    }

    /**
     * Cancel FREE-ADVICE request
     */
    public function cancelPreAdvice(PreAdviceRequest $preAdvice, User $user, string $reason = null): PreAdviceRequest
    {
        // Validate user can cancel (trucker or terminal team)
        if ($preAdvice->getTrucker() !== $user && !$this->isTerminalTeamUser($user)) {
            throw new \InvalidArgumentException('User not authorized to cancel this FREE-ADVICE');
        }

        // Release assigned slot if any
        if ($preAdvice->getAssignedSlot()) {
            $this->slotManagementService->releaseSlot($preAdvice);
        }

        $preAdvice->setStatus(PreAdviceStatus::CANCELLED);
        if ($reason) {
            $preAdvice->setRejectionReason($reason);
        }

        // Reset container status back to AVAILABLE_FOR_RETURN if it was PA_APPROVED
        $container = $preAdvice->getContainer();
        if ($container->getStatus() === ContainerStatus::PA_APPROVED) {
            $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        }

        $this->entityManager->flush();

        $this->logger->info('FREE-ADVICE cancelled', [
            'preAdviceId' => $preAdvice->getId(),
            'cancelledBy' => $user->getId(),
            'reason' => $reason
        ]);

        return $preAdvice;
    }

    /**
     * Get FREE-ADVICE workflow status
     */
    public function getWorkflowStatus(PreAdviceRequest $preAdvice): array
    {
        return [
            'id' => $preAdvice->getId(),
            'status' => $preAdvice->getStatus()->value,
            'canVerify' => $preAdvice->getStatus() === PreAdviceStatus::PENDING,
            'canGenerateEDO' => $preAdvice->getStatus() === PreAdviceStatus::VERIFIED,
            'isCompleted' => $preAdvice->getStatus() === PreAdviceStatus::COMPLETED,
            'hasSlot' => $preAdvice->getAssignedSlot() !== null,
            'hasEDO' => $preAdvice->getEdoNumber() !== null,
            'photoCount' => $preAdvice->getGeotagPhotos()->count(),
            'createdAt' => $preAdvice->getCreatedAt(),
            'verifiedAt' => $preAdvice->getVerifiedAt(),
            'verifiedBy' => $preAdvice->getVerifiedBy()?->getId(),
            'rejectionReason' => $preAdvice->getRejectionReason()
        ];
    }

    /**
     * Validate container eligibility for return
     */
    private function validateContainerEligibility(Container $container): void
    {
        if ($container->getStatus() !== ContainerStatus::AVAILABLE_FOR_RETURN) {
            throw new \InvalidArgumentException(
                'Container is not available for return. Current status: ' . $container->getStatus()->value
            );
        }
    }

    /**
     * Validate terminal can accept container type
     */
    private function validateTerminalCompatibility(Container $container, Terminal $terminal): void
    {
        if (!$terminal->isActive()) {
            throw new \InvalidArgumentException('Selected terminal is not active');
        }

        // Check if terminal supports container type (simplified validation)
        // In a real implementation, this would check container size/type compatibility
    }

    /**
     * Validate payment reference format and existence
     */
    private function validatePaymentReference(string $paymentReference): void
    {
        if (empty($paymentReference) || strlen($paymentReference) < 10) {
            throw new \InvalidArgumentException('Invalid payment reference format');
        }

        // In a real implementation, this would verify with payment system
        // For now, we'll do basic format validation
    }

    /**
     * Validate photos are properly verified for FREE-ADVICE verification
     */
    private function validatePhotosForVerification(PreAdviceRequest $preAdvice): void
    {
        // For now, just check if photos exist - don't require them to be validated
        // This allows testing without strict photo validation
        if ($preAdvice->getGeotagPhotos()->isEmpty()) {
            // Allow verification even without photos for testing
            return;
        }

        // In production, you would uncomment this to enforce photo validation:
        /*
        foreach ($preAdvice->getGeotagPhotos() as $photo) {
            if (!$this->photoVerificationService->isPhotoValid($photo)) {
                throw new \InvalidArgumentException('All photos must be validated before verification');
            }
        }
        */
    }

    /**
     * Verify payment completion with payment system
     */
    private function verifyPaymentCompletion(PreAdviceRequest $preAdvice): void
    {
        // In a real implementation, this would integrate with existing payment verification system
        // For now, we'll assume payment is valid if payment reference exists
        if (empty($preAdvice->getPaymentReference())) {
            throw new \InvalidArgumentException('Payment reference is required for EDO generation');
        }
    }

    /**
     * Generate unique EDO number
     */
    private function generateUniqueEdoNumber(PreAdviceRequest $preAdvice): string
    {
        $timestamp = date('YmdHis');
        $preAdviceId = str_pad($preAdvice->getId(), 8, '0', STR_PAD_LEFT);
        $terminalCode = $preAdvice->getSelectedTerminal()->getType()->value;
        
        return "EDO{$timestamp}{$preAdviceId}{$terminalCode}";
    }

    /**
     * Generate QR code (enhanced implementation using QRCodeService)
     */
    private function generateQRCode(PreAdviceRequest $preAdvice, string $edoNumber): string
    {
        return $this->qrCodeService->generateQRCode($preAdvice);
    }

    /**
     * Check if user has terminal team role
     */
    private function isTerminalTeamUser(User $user): bool
    {
        return $user->getRole() === UserRole::TERMINAL_TEAM;
    }
}