<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\GenerationSession;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\EDOStatus;
use App\Exception\EDOWorkflowException;
use App\Utility\EDONumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service for batch eDO generation with progress tracking
 * Orchestrates sequential container processing with unified expiration date
 */
class BatchEDOGenerationService implements BatchEDOGenerationServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EDOGenerationServiceInterface $edoGenerationService,
        private LoggerInterface $logger,
        private EDONumberGenerator $edoNumberGenerator,
        private NotificationService $notificationService,
        private ConfigurationService $configurationService,
        private InAppNotificationService $inAppNotificationService,
        private EmailNotificationService $emailNotificationService,
        private \Symfony\Component\Routing\Generator\UrlGeneratorInterface $urlGenerator,
        private CYAllocationService $cyAllocationService,
        private EDODocumentGenerator $edoDocumentGenerator,
        private WorkflowOrchestrator $workflowOrchestrator
    ) {
    }

    /**
     * Generate eDOs for all containers linked to a manifest
     * 
     * @param Manifest $manifest The manifest for which to generate eDOs
     * @param \DateTimeInterface $expirationDate The expiration date for all generated eDOs
     * @param User $generatedBy The SL_STAFF user generating the eDOs
     * @return array An array containing count and edos
     * @throws EDOWorkflowException When validation fails or generation errors occur
     */
    public function generateEDOsForManifest(
        Manifest $manifest,
        \DateTimeInterface $expirationDate,
        User $generatedBy
    ): array {
        // Validate manifest is ready for eDO generation
        $this->validateManifestForEDOGeneration($manifest);
        
        // Get linked containers
        $containers = $manifest->getContainersLinkedToManifest();
        
        if ($containers->count() === 0) {
            throw new EDOWorkflowException('No containers linked to manifest');
        }
        
        // Get current eDO fee from configuration
        $edoFee = $this->configurationService->getEDOFee();
        
        $generatedEDOs = [];
        $usedEdoNumbers = []; // Track generated numbers in this batch
        
        // Start transaction
        $this->entityManager->beginTransaction();
        
        try {
            // Generate eDO for each container
            $firstEdo = null;
            foreach ($containers as $container) {
                $edo = new ElectronicDeliveryOrder();
                
                // Generate unique eDO number, ensuring no duplicates within this batch
                $edoNumber = $this->edoNumberGenerator->generate($container->getContainerNumber());
                while (in_array($edoNumber, $usedEdoNumbers)) {
                    // If number was already used in this batch, generate a new one
                    $edoNumber = $this->edoNumberGenerator->generate($container->getContainerNumber());
                }
                $usedEdoNumbers[] = $edoNumber;
                
                $edo->setEdoNumber($edoNumber);
                $edo->setContainer($container);
                $edo->setManifest($manifest);
                $edo->setShippingLine($manifest->getShippingLine());
                $edo->setStatus(EDOStatus::PENDING_RELEASE);
                $edo->setExpiresAt($expirationDate);
                $edo->setFeeAmount($edoFee);
                
                // Calculate validity days from today to expiration date
                $today = new \DateTime('today');
                $validityDays = $today->diff($expirationDate)->days;
                $edo->setExpiredDays($validityDays);
                
                // Set CY location from NOA if available
                $noa = $container->getNoa();
                if ($noa && $noa->getPortLocation()) {
                    $edo->setCyLocation($container->getCyAllocation()?->getTerminal()?->getName() ?? $noa->getPortLocation());
                }
                
                // Set PDF path (will be generated later)
                $edo->setPdfPath(''); // Will be set when PDF is generated
                
                $this->entityManager->persist($edo);
                $generatedEDOs[] = $edo;
                
                // Lock CY allocation when eDO is generated (Task 14.1)
                // Change allocation status from PRE_FORECAST to ALLOCATED
                if ($container->getCyAllocation() !== null) {
                    $this->cyAllocationService->lockAllocation($container);
                }
                
                // Track first eDO for audit logging
                if ($firstEdo === null) {
                    $firstEdo = $edo;
                }
            }
            
            $this->workflowOrchestrator->transitionToEdoGeneratedIfNeeded(
                $manifest,
                $generatedBy,
                'Batch eDO generation completed'
            );
            
            // Flush changes to get eDO IDs
            $this->entityManager->flush();
            
            // Generate ONE PDF for all eDOs
            try {
                $bulkPdfPath = $this->edoDocumentGenerator->generateBulkPDF($generatedEDOs);
                
                // Set the same PDF path for all eDOs
                foreach ($generatedEDOs as $edo) {
                    $edo->setPdfPath($bulkPdfPath);
                }
                
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $this->logger->error('Failed to generate bulk eDO PDF', [
                    'manifest_id' => $manifest->getId(),
                    'error' => $e->getMessage()
                ]);
                // Continue even if PDF generation fails
            }
            
            // Log batch generation start and completion with first eDO
            // TODO: Re-implement audit logging with general AuditService
            
            // Commit transaction
            $this->entityManager->commit();
            
            // Send notification to broker
            $this->notifyBrokerEDOsGenerated($manifest, $generatedEDOs);
            
            return [
                'count' => count($generatedEDOs),
                'edos' => $generatedEDOs,
            ];
            
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new EDOWorkflowException(
                'Failed to generate eDOs: ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /**
     * Validate that a manifest is ready for eDO generation
     * 
     * @param Manifest $manifest The manifest to validate
     * @return bool True if validation passes
     * @throws EDOWorkflowException When validation fails
     */
    public function validateManifestForEDOGeneration(Manifest $manifest): bool
    {
        $manifestId = $manifest->getId();
        $manifestNumber = $manifest->getManifestNumber();
        
        // Validation 1: Check workflow state - allow payment_verified, edo_generated, or edo_released
        $allowedStates = [
            WorkflowState::PAYMENT_VERIFIED,
            WorkflowState::EDO_GENERATED,
            WorkflowState::EDO_RELEASED
        ];
        
        if (!in_array($manifest->getWorkflowState(), $allowedStates)) {
            $currentState = $manifest->getWorkflowState()->value;
            $errorMessage = sprintf(
                'Manifest workflow state must be payment_verified, edo_generated, or edo_released. Current state: %s',
                $currentState
            );
            
            // Log validation failure for audit
            $this->logger->warning('eDO generation validation failed: Invalid workflow state', [
                'manifest_id' => $manifestId,
                'manifest_number' => $manifestNumber,
                'current_state' => $currentState,
                'allowed_states' => ['payment_verified', 'edo_generated', 'edo_released'],
                'validation_type' => 'workflow_state'
            ]);
            
            throw new EDOWorkflowException($errorMessage);
        }
        
        // Validation 2: Check for final payment existence
        $finalPayment = $manifest->getFinalPayment();
        
        if (!$finalPayment) {
            $errorMessage = sprintf(
                'No final payment found for manifest %s. A verified final payment is required before eDO generation.',
                $manifestNumber
            );
            
            // Log validation failure for audit
            $this->logger->warning('eDO generation validation failed: No final payment', [
                'manifest_id' => $manifestId,
                'manifest_number' => $manifestNumber,
                'validation_type' => 'final_payment_existence'
            ]);
            
            throw new EDOWorkflowException($errorMessage);
        }
        
        // Validation 3: Check payment status is verified
        if ($finalPayment->getStatus() !== PaymentStatus::VERIFIED) {
            $currentStatus = $finalPayment->getStatus()->value;
            $errorMessage = sprintf(
                'Final payment for manifest %s is not verified. Current status: %s. Payment must be verified by Accounting before eDO generation.',
                $manifestNumber,
                $currentStatus
            );
            
            // Log validation failure for audit
            $this->logger->warning('eDO generation validation failed: Payment not verified', [
                'manifest_id' => $manifestId,
                'manifest_number' => $manifestNumber,
                'payment_id' => $finalPayment->getId(),
                'current_status' => $currentStatus,
                'required_status' => 'verified',
                'validation_type' => 'payment_status'
            ]);
            
            throw new EDOWorkflowException($errorMessage);
        }
        
        // Validation 4: Check for linked containers
        $containers = $manifest->getContainersLinkedToManifest();
        if ($containers->count() === 0) {
            $errorMessage = sprintf(
                'No containers linked to manifest %s. At least one container must be linked before eDO generation.',
                $manifestNumber
            );
            
            // Log validation failure for audit
            $this->logger->warning('eDO generation validation failed: No linked containers', [
                'manifest_id' => $manifestId,
                'manifest_number' => $manifestNumber,
                'validation_type' => 'container_linkage'
            ]);
            
            throw new EDOWorkflowException($errorMessage);
        }
        
        // Note: We allow regeneration, so we don't check for existing eDOs
        // The service layer will handle updating existing eDOs or creating new ones
        
        // All validations passed - log success
        $this->logger->info('eDO generation validation passed', [
            'manifest_id' => $manifestId,
            'manifest_number' => $manifestNumber,
            'container_count' => $containers->count(),
            'payment_id' => $finalPayment->getId(),
            'payment_status' => $finalPayment->getStatus()->value,
            'workflow_state' => $manifest->getWorkflowState()->value
        ]);
        
        return true;
    }

    /**
     * Send notification to broker after eDO generation
     */
    private function notifyBrokerEDOsGenerated(Manifest $manifest, array $edos): void
    {
        try {
            $broker = $manifest->getBroker();
            if (!$broker) {
                $this->logger->warning('Cannot send broker notification - no broker assigned to manifest', [
                    'manifest_id' => $manifest->getId()
                ]);
                return;
            }

            $edoCount = count($edos);
            $containerCount = count($edos);
            $manifestNumber = $manifest->getManifestNumber();

            // Create in-app notification
            $this->inAppNotificationService->createNotification(
                $broker,
                'eDOs Generated',
                sprintf(
                    '%d Electronic Delivery Order(s) have been generated for manifest %s. You can now proceed to pay for and download the eDOs.',
                    $edoCount,
                    $manifestNumber
                ),
                'edo_generated',
                ['manifest_id' => $manifest->getId()]
            );

            // Send email notification
            $edoListUrl = $this->urlGenerator->generate(
                'broker_edo_list',
                [],
                \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Prepare container data for email
            $containers = [];
            foreach ($edos as $edo) {
                $container = $edo->getContainer();
                if ($container) {
                    $containers[] = [
                        'containerNumber' => $container->getContainerNumber(),
                        'size' => $container->getSize(),
                        'type' => $container->getType()
                    ];
                }
            }

            $emailData = [
                'broker' => $broker,
                'manifestNumber' => $manifestNumber,
                'containerCount' => $containerCount,
                'edoCount' => $edoCount,
                'generatedAt' => new \DateTime(),
                'edoListUrl' => $edoListUrl,
                'containers' => $containers
            ];

            $this->emailNotificationService->sendTemplatedEmail(
                $broker->getEmail(),
                'eDOs Generated - OPTIMUS Portal',
                'emails/edo_generated.html.twig',
                $emailData
            );

            $this->logger->info('eDO generation notification sent to broker', [
                'broker_id' => $broker->getId(),
                'broker_email' => $broker->getEmail(),
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifestNumber,
                'edo_count' => $edoCount,
                'container_count' => $containerCount
            ]);
            
        } catch (\Exception $e) {
            // Log error but don't throw - notification failure shouldn't break eDO generation
            $this->logger->error('Failed to send broker notification', [
                'error' => $e->getMessage(),
                'manifest_id' => $manifest->getId(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function generateEDOsForContainers(
        array $containers,
        \DateTime $expirationDate,
        Manifest $manifest,
        User $user,
        string $documentType = 'manifest',
        ?string $documentNumber = null
    ): GenerationSession {
        // Create generation session with UUID
        $session = new GenerationSession();
        $sessionId = Uuid::v4()->toRfc4122();
        $session->setSessionId($sessionId);
        $session->setManifest($manifest);
        $session->setInitiatedBy($user);
        $session->setTotalContainers(count($containers));
        $session->setExpirationDate($expirationDate);
        $session->setStatus('in_progress');
        $session->setDocumentType($documentType);
        $session->setDocumentNumber($documentNumber);
        
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        // Log batch generation start
        $this->logger->info('Batch eDO generation started', [
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'manifest_id' => $manifest->getId(),
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'container_count' => count($containers),
            'expiration_date' => $expirationDate->format('Y-m-d')
        ]);

        // Process containers sequentially - create eDO records without PDFs
        $batchSequence = 0;
        $firstEdo = null;
        $lastEdo = null;
        $generatedEdos = []; // Collect all successfully generated eDOs
        
        foreach ($containers as $container) {
            $batchSequence++;
            
            try {
                // Update current container being processed
                $session->setCurrentContainer($container->getContainerNumber());
                $this->entityManager->flush();

                // Execute eDO generation with 30-second timeout
                $startTime = microtime(true);
                $timeoutSeconds = 30;
                
                // Retrieve CY location from NOA
                $noa = $container->getNoa();
                if (!$noa) {
                    throw new EDOWorkflowException('Container must have associated NOA');
                }

                $cyLocation = $container->getCyAllocation()?->getTerminal()?->getName();
                if (empty($cyLocation)) {
                    throw new EDOWorkflowException('Container must have CY allocation for eDO generation');
                }

                // Check if we've exceeded timeout before generation
                if ((microtime(true) - $startTime) > $timeoutSeconds) {
                    throw new \RuntimeException('eDO generation timeout exceeded');
                }

                // Generate eDO for container (this will create the record with 'pending' PDF path)
                $edo = $this->edoGenerationService->generateEDOForContainer($container, $manifest);

                // Check timeout after generation
                if ((microtime(true) - $startTime) > $timeoutSeconds) {
                    throw new \RuntimeException('eDO generation timeout exceeded');
                }

                // Apply unified expiration date
                $edo->setExpiresAt($expirationDate);

                // Calculate validity days from today to expiration date
                $today = new \DateTime('today');
                $validityDays = $today->diff($expirationDate)->days;
                $edo->setExpiredDays($validityDays);

                // Set CY location from NOA
                $edo->setCyLocation($cyLocation);

                // Ensure status is PENDING_RELEASE (awaiting payment)
                $edo->setStatus(\App\Entity\Enum\EDOStatus::PENDING_RELEASE);

                $this->entityManager->persist($edo);
                $this->entityManager->flush();

                // Add to collection for bulk PDF generation
                $generatedEdos[] = $edo;

                // Track first and last eDO for audit logging
                if ($firstEdo === null) {
                    $firstEdo = $edo;
                    // TODO: Re-implement audit logging
                    // Log batch generation start with first eDO
                }
                $lastEdo = $edo;

                // Increment completed count
                $session->setCompletedContainers($session->getCompletedContainers() + 1);
                $this->entityManager->flush();

                // Log individual eDO generation with batch context
                $this->logger->info('eDO generated in batch', [
                    'session_id' => $sessionId,
                    'batch_sequence' => $batchSequence,
                    'container_number' => $container->getContainerNumber(),
                    'edo_number' => $edo->getEdoNumber()
                ]);

                // TODO: Re-implement audit logging
                // $this->auditService->logBatchEDOGeneration(...);

            } catch (\Exception $e) {
                // Handle container-level error (including timeout)
                $session->setFailedContainers($session->getFailedContainers() + 1);

                // Determine error type for logging
                $errorType = $e instanceof \RuntimeException && str_contains($e->getMessage(), 'timeout') 
                    ? 'timeout' 
                    : 'error';

                // Add failure details to session
                $failures = $session->getFailures() ?? [];
                $failures[] = [
                    'container' => $container->getContainerNumber(),
                    'error' => $e->getMessage(),
                    'error_type' => $errorType,
                    'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
                ];
                $session->setFailures($failures);

                $this->entityManager->flush();

                // Log error with session context
                $this->logger->error('eDO generation failed in batch', [
                    'session_id' => $sessionId,
                    'batch_sequence' => $batchSequence,
                    'container_number' => $container->getContainerNumber(),
                    'error' => $e->getMessage(),
                    'error_type' => $errorType
                ]);

                // Continue processing remaining containers
            }
        }

        // Generate bulk PDF for all successfully generated eDOs
        if (!empty($generatedEdos)) {
            try {
                $this->logger->info('Generating bulk PDF for eDOs', [
                    'session_id' => $sessionId,
                    'edo_count' => count($generatedEdos)
                ]);

                // Generate one PDF with all containers
                $bulkPdfPath = $this->edoDocumentGenerator->generateBulkPDF($generatedEdos);

                // Update all eDOs with the same PDF path
                foreach ($generatedEdos as $edo) {
                    $edo->setPdfPath($bulkPdfPath);
                }
                $this->entityManager->flush();

                $this->logger->info('Bulk PDF generated successfully', [
                    'session_id' => $sessionId,
                    'pdf_path' => $bulkPdfPath,
                    'edo_count' => count($generatedEdos)
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Bulk PDF generation failed', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage()
                ]);
                
                // Mark all eDOs as failed PDF generation
                foreach ($generatedEdos as $edo) {
                    $edo->setPdfPath('failed');
                }
                $this->entityManager->flush();
            }
        }

        // Update session completion status
        $session->setCurrentContainer(null);
        if ($session->getFailedContainers() === 0) {
            $session->setStatus('completed');
        } else if ($session->getCompletedContainers() === 0) {
            $session->setStatus('failed');
        } else {
            $session->setStatus('completed'); // Partial success
        }
        $session->setCompletedAt(new \DateTime());
        
        // Update manifest workflow state to EDO_GENERATED if at least one eDO was generated successfully
        if ($session->getCompletedContainers() > 0) {
            $this->workflowOrchestrator->transitionToEdoGeneratedIfNeeded(
                $manifest,
                $session->getInitiatedBy(),
                'Batch eDO generation session completed'
            );
        }
        
        $this->entityManager->flush();

        // Log batch completion with last eDO (or first if all failed)
        if ($lastEdo !== null) {
            $this->logger->info('Batch eDO generation completed', [
                'session_id' => $sessionId,
                'completed' => $session->getCompletedContainers(),
                'failed' => $session->getFailedContainers(),
                'total' => $session->getTotalContainers()
            ]);

            // TODO: Re-implement audit logging
            // $this->auditService->logBatchGenerationCompletion(...);
        }

        return $session;
    }

    /**
     * {@inheritdoc}
     */
    public function getProgress(string $sessionId): array
    {
        $session = $this->entityManager
            ->getRepository(GenerationSession::class)
            ->findOneBy(['sessionId' => $sessionId]);

        if (!$session) {
            throw new \InvalidArgumentException('Session not found');
        }

        $percentage = $session->getTotalContainers() > 0
            ? round(($session->getCompletedContainers() / $session->getTotalContainers()) * 100)
            : 0;

        return [
            'session_id' => $session->getSessionId(),
            'status' => $session->getStatus(),
            'completed' => $session->getCompletedContainers(),
            'total' => $session->getTotalContainers(),
            'failed' => $session->getFailedContainers(),
            'current_container' => $session->getCurrentContainer(),
            'failures' => $session->getFailures() ?? [],
            'percentage' => $percentage,
            'document_type' => $session->getDocumentType(),
            'document_number' => $session->getDocumentNumber(),
            'started_at' => $session->getStartedAt()->format('Y-m-d H:i:s'),
            'completed_at' => $session->getCompletedAt()?->format('Y-m-d H:i:s')
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function cancelGeneration(string $sessionId, User $user): bool
    {
        $session = $this->entityManager
            ->getRepository(GenerationSession::class)
            ->findOneBy(['sessionId' => $sessionId]);

        if (!$session || $session->getStatus() !== 'in_progress') {
            return false;
        }

        // Update session status to cancelled
        $session->setStatus('cancelled');
        $session->setCancelledAt(new \DateTime());
        $session->setCancelledBy($user);
        $session->setCompletedAt(new \DateTime());
        $session->setCurrentContainer(null);

        $this->entityManager->flush();

        // Log cancellation event - need to find the last completed eDO for audit reference
        $this->logger->info('Batch eDO generation cancelled', [
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'completed_before_cancel' => $session->getCompletedContainers(),
            'total' => $session->getTotalContainers()
        ]);

        // TODO: Re-implement audit logging with general AuditService
        // Find the last completed eDO in this session for audit log reference

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function retryFailedContainers(string $sessionId, User $user): GenerationSession
    {
        // Retrieve previous session
        $previousSession = $this->entityManager
            ->getRepository(GenerationSession::class)
            ->findOneBy(['sessionId' => $sessionId]);

        if (!$previousSession) {
            throw new \InvalidArgumentException('Previous session not found');
        }

        $failures = $previousSession->getFailures();
        if (empty($failures)) {
            throw new \InvalidArgumentException('No failed containers to retry');
        }

        // Extract failed container numbers
        $failedContainerNumbers = array_map(fn($failure) => $failure['container'], $failures);

        // Retrieve failed containers
        $manifest = $previousSession->getManifest();
        $noa = $manifest->getNoa();
        if (!$noa) {
            throw new EDOWorkflowException('Manifest must have associated NOA');
        }

        $allContainers = $noa->getContainers()->toArray();
        $failedContainers = array_filter(
            $allContainers,
            fn($container) => in_array($container->getContainerNumber(), $failedContainerNumbers)
        );

        if (empty($failedContainers)) {
            throw new \InvalidArgumentException('Failed containers not found');
        }

        // Create new session for retry with same expiration date
        return $this->generateEDOsForContainers(
            $failedContainers,
            $previousSession->getExpirationDate(),
            $manifest,
            $user
        );
    }
}

