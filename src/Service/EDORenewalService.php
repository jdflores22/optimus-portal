<?php

namespace App\Service;

use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Enum\RenewalRequestStatus;
use App\Entity\Enum\EDOStatus;
use App\Repository\EDORenewalRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing eDO renewal requests and workflow
 * 
 * This service handles:
 * - Creation of renewal requests with detention charge calculation
 * - Validation of request dates (office hours, past dates)
 * - Payment verification workflow
 * - Generation of new eDOs from renewal requests
 * - Eligibility checks for eDO renewal
 */
class EDORenewalService implements EDORenewalServiceInterface
{
    /**
     * Office hours configuration (24-hour format)
     * Requests are only allowed between these hours
     */
    private const OFFICE_HOURS_START = 8;  // 8:00 AM
    private const OFFICE_HOURS_END = 22;   // 10:00 PM

    /**
     * Office days (1 = Monday, 7 = Sunday)
     */
    private const OFFICE_DAYS = [1, 2, 3, 4, 5]; // Monday to Friday

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EDORenewalRequestRepository $renewalRequestRepository,
        private DetentionChargeServiceInterface $detentionChargeService,
        private AuditService $auditService,
        private ActivityLogService $activityLogService,
        private LoggerInterface $logger,
        private DocumentService $documentService,
        private \App\Repository\EDOVersionRepository $edoVersionRepository,
        private EDODocumentGenerator $edoDocumentGenerator,
        private string $projectDir
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function createRenewalRequest(
        ElectronicDeliveryOrder $expiredEdo,
        User $requestedBy,
        \DateTimeInterface $returnDate,
        ?string $notes = null
    ): EDORenewalRequest {
        // Validate eDO is eligible for renewal
        if (!$this->isEligibleForRenewal($expiredEdo)) {
            throw new \InvalidArgumentException(
                'eDO is not eligible for renewal. It must be expired and have a terminal assigned.'
            );
        }

        // Validate request date
        if (!$this->validateRequestDate($returnDate)) {
            throw new \InvalidArgumentException(
                'Invalid return date. Date must be in the future.'
            );
        }

        // Calculate overdue days and detention charges based on return date
        $expirationDate = $expiredEdo->getExpiresAt();
        
        if ($expirationDate && $returnDate > $expirationDate) {
            $interval = $returnDate->diff($expirationDate);
            $overdueDays = $interval->days;
        } else {
            $overdueDays = 0;
        }
        
        $detentionCharge = $this->detentionChargeService->calculateDetentionCharge($overdueDays, $expiredEdo);

        // Create renewal request entity
        $renewalRequest = new EDORenewalRequest();
        $renewalRequest->setExpiredEdo($expiredEdo);
        $renewalRequest->setRequestedBy($requestedBy);
        $renewalRequest->setEmptyContainerReturnDate($returnDate);
        $renewalRequest->setOverdueDays($overdueDays);
        $renewalRequest->setDetentionChargeAmount($detentionCharge);
        $renewalRequest->setAdditionalNotes($notes);

        // Set initial status based on whether detention charges are required
        if ($detentionCharge > 0) {
            // If detention charges apply, set to PENDING_REVIEW
            // Accounting will create billing and change status to AWAITING_PAYMENT
            $renewalRequest->setStatus(RenewalRequestStatus::PENDING_REVIEW);
        } else {
            // No detention charges, ready for generation immediately
            $renewalRequest->setStatus(RenewalRequestStatus::READY_FOR_GENERATION);
        }

        // Persist to database
        $this->entityManager->persist($renewalRequest);
        $this->entityManager->flush();

        // Log renewal request creation via AuditService
        $this->auditService->logAction(
            $requestedBy,
            'edo_renewal_requested',
            'EDORenewalRequest',
            $renewalRequest->getId(),
            [
                'expired_edo_id' => $expiredEdo->getId(),
                'expired_edo_reference' => $expiredEdo->getEdoNumber(),
                'requested_return_date' => $returnDate->format('Y-m-d H:i:s'),
                'overdue_days' => $overdueDays,
                'detention_charge_amount' => $detentionCharge,
                'requires_payment' => $detentionCharge > 0,
                'initial_status' => $renewalRequest->getStatus()->value
            ]
        );

        // Log user activity via ActivityLogService
        $this->activityLogService->logActivity(
            $requestedBy,
            'edo_renewal_request_created',
            'EDORenewalRequest',
            $renewalRequest->getId(),
            null,
            [
                'renewal_request_id' => $renewalRequest->getId(),
                'expired_edo_number' => $expiredEdo->getEdoNumber(),
                'return_date' => $returnDate->format('Y-m-d'),
                'detention_charge' => $detentionCharge
            ],
            [
                'overdue_days' => $overdueDays,
                'requires_payment' => $detentionCharge > 0
            ]
        );

        $this->logger->info('eDO renewal request created', [
            'renewal_request_id' => $renewalRequest->getId(),
            'expired_edo_id' => $expiredEdo->getId(),
            'expired_edo_number' => $expiredEdo->getEdoNumber(),
            'requested_by' => $requestedBy->getEmail(),
            'return_date' => $returnDate->format('Y-m-d H:i:s'),
            'overdue_days' => $overdueDays,
            'detention_charge' => $detentionCharge,
            'status' => $renewalRequest->getStatus()->value
        ]);

        return $renewalRequest;
    }

    /**
     * {@inheritdoc}
     */
    public function validateRequestDate(\DateTimeInterface $returnDate): bool
    {
        $now = new \DateTime();

        // Check if date is in the past
        if ($returnDate < $now) {
            $this->logger->warning('Request date validation failed: date is in the past', [
                'return_date' => $returnDate->format('Y-m-d H:i:s'),
                'current_date' => $now->format('Y-m-d H:i:s')
            ]);
            return false;
        }

        // No restrictions on day of week or time - brokers can submit anytime
        // Shipping line operations are Monday-Friday 8 AM - 5 PM, but requests can be submitted 24/7
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function markPaymentVerified(EDORenewalRequest $request, User $verifiedBy): void
    {
        $oldStatus = $request->getStatus();

        // Update payment verification fields
        $request->setPaymentVerified(true);
        $request->setPaymentVerifiedAt(new \DateTime());
        $request->setPaymentVerifiedBy($verifiedBy);
        $request->setStatus(RenewalRequestStatus::READY_FOR_GENERATION);

        $this->entityManager->flush();

        // Log payment verification via AuditService
        $this->auditService->logAction(
            $verifiedBy,
            'detention_payment_verified',
            'EDORenewalRequest',
            $request->getId(),
            [
                'renewal_request_id' => $request->getId(),
                'billing_id' => $request->getDetentionBilling()?->getId(),
                'amount_verified' => $request->getDetentionChargeAmount(),
                'verified_at' => $request->getPaymentVerifiedAt()->format('Y-m-d H:i:s'),
                'previous_status' => $oldStatus->value,
                'new_status' => $request->getStatus()->value
            ]
        );

        // Log status change via AuditService
        $this->auditService->logAction(
            $verifiedBy,
            'renewal_status_changed',
            'EDORenewalRequest',
            $request->getId(),
            [
                'old_status' => $oldStatus->value,
                'new_status' => $request->getStatus()->value,
                'triggered_by' => 'payment_verification',
                'verified_by' => $verifiedBy->getEmail()
            ]
        );

        // Log user activity via ActivityLogService
        $this->activityLogService->logActivity(
            $verifiedBy,
            'detention_payment_verified',
            'EDORenewalRequest',
            $request->getId(),
            ['status' => $oldStatus->value],
            ['status' => $request->getStatus()->value],
            [
                'renewal_request_id' => $request->getId(),
                'billing_id' => $request->getDetentionBilling()?->getId(),
                'amount' => $request->getDetentionChargeAmount()
            ]
        );

        $this->logger->info('Payment verified for eDO renewal request', [
            'renewal_request_id' => $request->getId(),
            'verified_by' => $verifiedBy->getEmail(),
            'amount' => $request->getDetentionChargeAmount(),
            'old_status' => $oldStatus->value,
            'new_status' => $request->getStatus()->value
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function generateNewEDO(
        EDORenewalRequest $request,
        User $generatedBy,
        ShippingLineTerminalAllocation $cyAllocation,
        ?string $additionalNotes = null
    ): ElectronicDeliveryOrder {
        // Validate payment is verified when detention charges are required
        if ($this->detentionChargeService->requiresDetentionCharges($request) && !$request->isPaymentVerified()) {
            throw new \RuntimeException(
                'Payment must be verified before generating new eDO when detention charges are required.'
            );
        }

        $expiredEdo = $request->getExpiredEdo();
        $container = $expiredEdo->getContainer();
        $terminal = $cyAllocation->getTerminal();

        // Create new eDO entity by copying from expired eDO
        $newEdo = new ElectronicDeliveryOrder();
        
        // Copy all relevant data from expired eDO
        $newEdo->setManifest($expiredEdo->getManifest());
        $newEdo->setShippingLine($expiredEdo->getShippingLine());
        $newEdo->setContainer($container);
        $newEdo->setFeeAmount($expiredEdo->getFeeAmount());
        
        // Generate new eDO number based on the original eDO number with renewal counter
        // Original format: EDO-YYYYMM-NNNN (e.g., EDO-202605-0015)
        // Renewal format: EDO-YYYYMM-NNNN-RRRR (e.g., EDO-202605-0015-0001)
        $baseEdoNumber = $expiredEdo->getEdoNumber();
        
        // Check if this is already a renewal by counting the number of dashes
        // Original eDO: 2 dashes (EDO-202605-0015)
        // Renewal eDO: 3 dashes (EDO-202605-0015-0001)
        $dashCount = substr_count($baseEdoNumber, '-');
        
        if ($dashCount >= 3) {
            // This is already a renewal, extract the root (first 3 parts)
            $parts = explode('-', $baseEdoNumber);
            $rootEdoNumber = $parts[0] . '-' . $parts[1] . '-' . $parts[2];
        } else {
            // This is the original eDO
            $rootEdoNumber = $baseEdoNumber;
        }
        
        // Count how many renewals exist for this root eDO number
        $renewalCount = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from('App\Entity\ElectronicDeliveryOrder', 'e')
            ->where('e.edoNumber LIKE :pattern')
            ->setParameter('pattern', $rootEdoNumber . '-%')
            ->getQuery()
            ->getSingleScalarResult();
        
        // Generate the new eDO number with incremented counter
        $renewalCounter = str_pad((string)($renewalCount + 1), 4, '0', STR_PAD_LEFT);
        $edoNumber = $rootEdoNumber . '-' . $renewalCounter;
        
        $newEdo->setEdoNumber($edoNumber);

        // Set new CY location from provided allocation
        $newEdo->setCyLocation($terminal->getName());

        // Set generated_by_name from user's full name
        $generatedByName = $this->getUserFullName($generatedBy);
        $newEdo->setGeneratedByName($generatedByName);

        // Set additional notes with RENEWAL marker
        $renewalNote = "RENEWED FROM: " . $expiredEdo->getEdoNumber();
        if ($additionalNotes !== null && trim($additionalNotes) !== '') {
            $renewalNote .= " | " . $additionalNotes;
        }
        $newEdo->setAdditionalNotes($renewalNote);

        // Link new eDO to expired eDO via previousVersion relationship
        $newEdo->setPreviousVersion($expiredEdo);
        $newEdo->setVersion($expiredEdo->getVersion() + 1);

        // Set expiration date (7 days from now)
        $expirationDate = new \DateTime();
        $expirationDate->modify('+7 days');
        $newEdo->setExpiresAt($expirationDate);

        // Set status to RELEASED since payment was already made
        $newEdo->setStatus(EDOStatus::RELEASED);
        $newEdo->setReleasedAt(new \DateTime());
        $newEdo->setReleasedBy($generatedBy);

        // Set temporary PDF path placeholder (will be updated after PDF generation)
        $newEdo->setPdfPath('pending');

        // Persist new eDO first to get an ID (required for PDF generation)
        if (!$this->entityManager->contains($newEdo)) {
            $this->entityManager->persist($newEdo);
        }
        $this->entityManager->flush();

        // Generate NEW PDF using the same template as original eDO generation
        try {
            $pdfPath = $this->edoDocumentGenerator->generatePDF($newEdo, $generatedBy);
            $newEdo->setPdfPath($pdfPath);
            
            // Generate digital signature for the new PDF
            $signature = hash_file('sha256', $pdfPath);
            $newEdo->setDigitalSignature($signature);
            
            $this->logger->info('Generated new PDF for renewed eDO', [
                'new_edo_id' => $newEdo->getId(),
                'new_edo_number' => $newEdo->getEdoNumber(),
                'pdf_path' => $pdfPath
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate PDF for renewed eDO', [
                'new_edo_id' => $newEdo->getId(),
                'new_edo_number' => $newEdo->getEdoNumber(),
                'error' => $e->getMessage()
            ]);
            
            throw new \RuntimeException(
                'Failed to generate PDF for renewed eDO: ' . $e->getMessage()
            );
        }

        // Update renewal request with new eDO reference
        $request->setNewEdo($newEdo);
        $request->setStatus(RenewalRequestStatus::COMPLETED);
        $request->setCompletedAt(new \DateTime());

        // Flush all changes in one transaction
        $this->entityManager->flush();

        // Save version history for both expired and new eDO (after flush so they have IDs)
        $this->saveVersionHistory($expiredEdo, $generatedBy, 'Expired - Renewal requested');
        $this->saveVersionHistory($newEdo, $generatedBy, 'Renewed from ' . $expiredEdo->getEdoNumber(), true);
        
        // Flush version history
        $this->entityManager->flush();

        // Log new eDO generation via AuditService
        $this->auditService->logAction(
            $generatedBy,
            'new_edo_generated_from_renewal',
            'ElectronicDeliveryOrder',
            $newEdo->getId(),
            [
                'renewal_request_id' => $request->getId(),
                'expired_edo_id' => $expiredEdo->getId(),
                'expired_edo_reference' => $expiredEdo->getEdoNumber(),
                'new_edo_id' => $newEdo->getId(),
                'new_edo_reference' => $newEdo->getEdoNumber(),
                'container_yard' => $terminal->getName(),
                'generated_by_name' => $generatedByName,
                'additional_notes' => $renewalNote,
                'expiration_date' => $expirationDate->format('Y-m-d H:i:s')
            ]
        );

        // Log user activity via ActivityLogService
        $this->activityLogService->logActivity(
            $generatedBy,
            'new_edo_generated',
            'ElectronicDeliveryOrder',
            $newEdo->getId(),
            null,
            [
                'edo_id' => $newEdo->getId(),
                'edo_number' => $newEdo->getEdoNumber(),
                'container_yard' => $terminal->getName()
            ],
            [
                'renewal_request_id' => $request->getId(),
                'expired_edo_number' => $expiredEdo->getEdoNumber(),
                'generated_by_name' => $generatedByName
            ]
        );

        $this->logger->info('New eDO generated from renewal request', [
            'renewal_request_id' => $request->getId(),
            'expired_edo_id' => $expiredEdo->getId(),
            'expired_edo_number' => $expiredEdo->getEdoNumber(),
            'new_edo_id' => $newEdo->getId(),
            'new_edo_number' => $newEdo->getEdoNumber(),
            'generated_by' => $generatedBy->getEmail(),
            'container_yard' => $terminal->getName(),
            'expiration_date' => $expirationDate->format('Y-m-d H:i:s')
        ]);

        return $newEdo;
    }

    /**
     * Save eDO version history
     *
     * @param ElectronicDeliveryOrder $edo
     * @param User $user
     * @param string|null $notes
     * @param bool $isCurrent
     */
    private function saveVersionHistory(
        ElectronicDeliveryOrder $edo,
        User $user,
        ?string $notes = null,
        bool $isCurrent = false
    ): void {
        // If marking as current, mark all other versions as not current
        // Only do this if the eDO has been persisted (has an ID)
        if ($isCurrent && $edo->getId() !== null) {
            $this->edoVersionRepository->markAllAsNotCurrent($edo);
        }

        $version = new \App\Entity\EDOVersion();
        $version->setEdo($edo);
        $version->setVersionNumber($edo->getVersion());
        $version->setPdfPath($edo->getPdfPath());
        $version->setEdoNumber($edo->getEdoNumber());
        $version->setStatus($edo->getStatus());
        $version->setCreatedBy($user);
        $version->setExpiresAt($edo->getExpiresAt());
        $version->setCyLocation($edo->getCyLocation());
        $version->setNotes($notes);
        $version->setIsCurrent($isCurrent);

        $this->entityManager->persist($version);
        // Don't flush here - let the caller handle the transaction

        $this->logger->info('eDO version history prepared', [
            'edo_id' => $edo->getId(),
            'edo_number' => $edo->getEdoNumber(),
            'version' => $edo->getVersion(),
            'is_current' => $isCurrent
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function isEligibleForRenewal(ElectronicDeliveryOrder $edo): bool
    {
        // Check if eDO is expired
        $expiresAt = $edo->getExpiresAt();
        if ($expiresAt === null) {
            return false;
        }

        $now = new \DateTime();
        if ($expiresAt >= $now) {
            return false; // Not yet expired
        }

        // Check if terminal is assigned
        $container = $edo->getContainer();
        if ($container === null) {
            return false;
        }

        // Check if container has terminal assignment (via CY allocation)
        $cyAllocation = $container->getCyAllocation();
        if ($cyAllocation === null) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getPendingRenewalRequests(User $slStaff): array
    {
        // Get shipping line scope for the SL staff user
        $shippingLine = $slStaff->getShippingLineScope();

        if ($shippingLine === null) {
            $this->logger->warning('SL staff user has no shipping line scope', [
                'user_id' => $slStaff->getId(),
                'user_email' => $slStaff->getEmail()
            ]);
            return [];
        }

        // Fetch pending renewal requests for the shipping line
        $requests = $this->renewalRequestRepository->findPendingRequestsForShippingLine($shippingLine);

        $this->logger->info('Retrieved pending renewal requests for SL staff', [
            'user_id' => $slStaff->getId(),
            'user_email' => $slStaff->getEmail(),
            'shipping_line_id' => $shippingLine->getId(),
            'request_count' => count($requests)
        ]);

        return $requests;
    }

    /**
     * Get the next eDO sequence number
     * 
     * @return int The next sequence number
     */
    private function getNextEdoSequence(): int
    {
        // Query for the highest sequence number today
        $today = (new \DateTime())->format('Ymd');
        
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('e.edoNumber')
           ->from(ElectronicDeliveryOrder::class, 'e')
           ->where('e.edoNumber LIKE :prefix')
           ->setParameter('prefix', 'EDO-' . $today . '%')
           ->orderBy('e.edoNumber', 'DESC')
           ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();

        if ($result === null) {
            return 1;
        }

        // Extract sequence from EDO number (format: EDO-YYYYMMDD-CONTAINER-XXXX)
        $edoNumber = $result['edoNumber'];
        $parts = explode('-', $edoNumber);
        
        if (count($parts) < 4) {
            return 1;
        }

        $sequence = (int) $parts[3];
        return $sequence + 1;
    }

    /**
     * Get user's full name
     * 
     * @param User $user The user
     * @return string The full name
     */
    private function getUserFullName(User $user): string
    {
        // Try to get full name from user entity
        if (method_exists($user, 'getFullName')) {
            $fullName = $user->getFullName();
            if ($fullName !== null && $fullName !== '') {
                return $fullName;
            }
        }

        // Fallback to email if full name is not available
        return $user->getEmail();
    }
}
