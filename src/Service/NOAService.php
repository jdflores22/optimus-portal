<?php

namespace App\Service;

use App\Entity\NOA;
use App\Entity\NOADocument;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Consignee;
use App\Entity\Container;
use App\Entity\ContainerType;
use App\Entity\ContainerSize;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\WorkflowState;
use App\Entity\Terminal;
use App\Entity\Enum\ContainerStatus;
use App\Exception\NOAValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\NOARepository;
use App\Repository\ContainerRepository;

class NOAService implements NOAServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentService $documentService,
        private AuditService $auditService,
        private WorkflowOrchestrator $workflowOrchestrator,
        private ActivityLogService $activityLogService,
        private ManifestNotificationService $notificationService,
        private CYAllocationService $cyAllocationService,
        private InAppNotificationService $inAppNotificationService,
        private ManagerRegistry $doctrine,
        private NOADocumentGenerator $noaDocumentGenerator
    ) {
    }

    public function createNOA(
        string $blNumber,
        string $vesselNumber,
        \DateTimeInterface $eta,
        string $portLocation,
        Consignee $consignee,
        array $containers,
        User $creator
    ): NOA {
        // Validate all required fields
        if (empty($blNumber)) {
            throw new NOAValidationException('BL number is required');
        }
        if (empty($vesselNumber)) {
            throw new NOAValidationException('Vessel number is required');
        }
        if (empty($portLocation)) {
            throw new NOAValidationException('Port location is required');
        }
        if (empty($containers)) {
            throw new NOAValidationException('At least one container is required');
        }

        // Validate each container has complete data
        foreach ($containers as $containerData) {
            if (empty($containerData['number'])) {
                throw new NOAValidationException('Container number is required for all containers');
            }
            if (!isset($containerData['type']) || !($containerData['type'] instanceof ContainerType)) {
                throw new NOAValidationException('Container type is required for all containers');
            }
            if (!isset($containerData['size']) || !($containerData['size'] instanceof ContainerSize)) {
                throw new NOAValidationException('Container size is required for all containers');
            }
            
            // Check for duplicate container number in database
            $normalizedNumber = ContainerRepository::normalizeContainerNumber($containerData['number']);
            /** @var ContainerRepository $containerRepository */
            $containerRepository = $this->entityManager->getRepository(Container::class);
            $existingContainer = $containerRepository->findOneInventoryMatchByNormalizedNumber($normalizedNumber);
            
            if ($existingContainer) {
                throw new NOAValidationException(
                    sprintf(
                        'Container number "%s" already exists in the system',
                        $existingContainer['container_number'] ?? $containerData['number']
                    )
                );
            }
        }

        // Validate CY capacity
        // Note: CY capacity validation is now handled at the terminal allocation level
        // Each shipping line has specific allocations managed by admins
        // if (!$this->validateCYCapacity($containers, $cyLocation)) {
        //     throw new NOAValidationException('Insufficient CY capacity for container allocation');
        // }

        // Generate unique NOA number
        /** @var NOARepository $noaRepository */
        $noaRepository = $this->entityManager->getRepository(NOA::class);
        $sequence = $noaRepository->getNextSequenceNumber();
        $noaNumber = NOA::generateNoaNumber($sequence);

        // Create NOA entity
        $noa = new NOA();
        $noa->setNoaNumber($noaNumber);
        $noa->setBlNumber($blNumber);
        $noa->setVesselNumber($vesselNumber);
        $noa->setEta($eta);
        $noa->setPortLocation($portLocation);
        $noa->setConsignee($consignee);
        $noa->setCreatedBy($creator);

        // Create and associate containers
        foreach ($containers as $containerData) {
            $container = new Container();
            $container->setContainerNumber($containerData['number']);
            $container->setContainerType($containerData['type']);
            $container->setContainerSize($containerData['size']);
            $container->setStatus(ContainerStatus::PENDING);
            $container->setExpectedReturnDate(new \DateTime('+30 days'));
            
            $noa->addContainer($container);
            $this->entityManager->persist($container);
        }

        // Persist NOA (don't flush yet - let controller handle transaction)
        $this->entityManager->persist($noa);
        // Note: flush() is called by the controller after transaction begins

        // Send notification to consignee (don't fail if notification fails)
        try {
            $this->notifyConsignee($noa);
        } catch (\Exception $e) {
            // Log error but don't fail the NOA creation
            error_log('WARNING: Failed to send NOA notification: ' . $e->getMessage());
        }

        return $noa;
    }

    public function validateCYCapacity(array $containers, string $cyLocation): bool
    {
        return $this->cyAllocationService->validateCapacity($containers, $cyLocation);
    }

    public function notifyConsignee(NOA $noa): void
    {
        $retryCount = 0;
        $maxRetries = 3;
        
        while ($retryCount < $maxRetries) {
            try {
                $consignee = $noa->getConsignee();
                
                // Send in-app notification
                $this->inAppNotificationService->createNotification(
                    $consignee,
                    'Notice of Arrival Created',
                    sprintf(
                        'NOA %s has been created for BL %s. Vessel: %s, ETA: %s. Total containers: %d',
                        $noa->getNoaNumber(),
                        $noa->getBlNumber(),
                        $noa->getVesselNumber(),
                        $noa->getEta()->format('Y-m-d'),
                        $noa->getContainers()->count()
                    ),
                    'noa',
                    ['noa_id' => $noa->getId()]
                );
                
                // TODO: Add email notification when template is ready
                // Email notification is disabled for now as ManifestNotificationService
                // doesn't have a generic sendNotification() method
                
                return; // Success, exit retry loop
                
            } catch (\Exception $e) {
                $retryCount++;
                error_log(sprintf(
                    'ERROR: Failed to send NOA creation notification (attempt %d/%d): %s',
                    $retryCount,
                    $maxRetries,
                    $e->getMessage()
                ));
                
                if ($retryCount >= $maxRetries) {
                    // Log final failure but don't throw - notification failure shouldn't break the operation
                    error_log('ERROR: NOA creation notification failed after all retries: ' . $e->getMessage());
                    return;
                }
                
                // Wait before retry (exponential backoff: 1s, 2s, 4s)
                sleep(pow(2, $retryCount - 1));
            }
        }
    }

    // Legacy methods below

    public function generateNOA(int $manifestId, array $data, User $slStaff): NOADocument
    {
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($manifestId);
        if (!$manifest) {
            throw new \InvalidArgumentException('Manifest not found');
        }

        // Validate workflow state - NOA can be generated after manifest is uploaded
        if ($manifest->getWorkflowState() !== WorkflowState::MANIFEST_UPLOADED) {
            throw new \InvalidArgumentException(
                sprintf(
                    'NOA can only be generated when manifest is in "Manifest Uploaded" state. Current state: %s',
                    $manifest->getWorkflowState()->getDisplayName()
                )
            );
        }

        // Check if NOA already exists
        if ($manifest->getNoaDocument()) {
            throw new \InvalidArgumentException('NOA already exists for this manifest');
        }

        // Generate NOA number
        $noaNumber = $this->generateNOANumber();

        // Create NOA document entity
        $noa = new NOADocument();
        $noa->setManifest($manifest);
        $noa->setNoaNumber($noaNumber);
        $noa->setArrivalDate(new \DateTime($data['arrivalDate']));
        $noa->setVesselInfo($data['vesselInfo']);
        $noa->setGeneratedBy($slStaff);

        // Generate PDF
        $pdfPath = $this->documentService->generateNOAPDF($manifest, $data);
        $noa->setPdfPath($pdfPath);

        $this->entityManager->persist($noa);
        
        // Update manifest state using WorkflowOrchestrator
        $this->workflowOrchestrator->transitionState(
            $manifest,
            WorkflowState::NOA_GENERATED,
            $slStaff,
            'NOA document generated'
        );
        
        $this->entityManager->flush();

        // Log NOA generation
        $this->auditService->logAction(
            $slStaff,
            'noa_generation',
            'NOADocument',
            $noa->getId(),
            [
                'noa_number' => $noaNumber,
                'manifest_id' => $manifestId,
                'manifest_number' => $manifest->getManifestNumber()
            ]
        );

        // Log to activity log for notifications
        $this->activityLogService->logNOAGeneration($slStaff, $manifest);

        // Notify broker and consignee about NOA generation
        try {
            $this->notificationService->notifyNOAGenerated($manifest, $pdfPath);
        } catch (\Exception $e) {
            // Log notification error but don't fail the entire operation
            error_log('ERROR: Failed to send NOA generation notifications: ' . $e->getMessage());
            // Optionally log to a monitoring service
        }

        return $noa;
    }

    public function getNOAByManifest(int $manifestId): ?NOADocument
    {
        return $this->entityManager->getRepository(NOADocument::class)
            ->findOneBy(['manifest' => $manifestId]);
    }

    public function getNOAByNumber(string $noaNumber): ?NOADocument
    {
        return $this->entityManager->getRepository(NOADocument::class)
            ->findOneBy(['noaNumber' => $noaNumber]);
    }

    public function getWorkflowNOAByNumber(string $noaNumber): ?NOA
    {
        return $this->entityManager->getRepository(NOA::class)
            ->findOneBy(['noaNumber' => $noaNumber]);
    }

    private function generateNOANumber(): string
    {
        $year = date('Y');
        $month = date('m');
        
        // Get the last NOA number for this month
        $lastNOA = $this->entityManager->getRepository(NOADocument::class)
            ->createQueryBuilder('n')
            ->where('n.noaNumber LIKE :prefix')
            ->setParameter('prefix', "NOA-{$year}{$month}-%")
            ->orderBy('n.noaNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($lastNOA) {
            // Extract sequence number and increment
            $parts = explode('-', $lastNOA->getNoaNumber());
            $sequence = intval($parts[2] ?? 0) + 1;
        } else {
            $sequence = 1;
        }

        return sprintf('NOA-%s%s-%04d', $year, $month, $sequence);
    }

    /**
     * Process bulk NOA import from CSV/Excel file
     */
    public function processBulkImport(
        string $filePath,
        string $extension,
        User $creator,
        bool $skipDuplicates = true,
        bool $validateOnly = false
    ): array {
        $result = [
            'total_count' => 0,
            'valid_count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'skipped_count' => 0,
            'errors' => [],
            'skipped' => [],
            'imported_noas' => [],
            'valid' => true
        ];

        // Parse file based on extension
        $rows = $this->parseImportFile($filePath, $extension);
        
        if (empty($rows)) {
            throw new \Exception('File is empty or could not be parsed');
        }

        // Remove header row
        $header = array_shift($rows);
        
        $result['total_count'] = count($rows);
        $rowNumber = 2; // Start from 2 (1 is header)

        foreach ($rows as $row) {
            try {
                // Check if EntityManager is closed and reset it
                if (!$this->entityManager->isOpen()) {
                    $this->entityManager = $this->doctrine->resetManager();
                }
                
                // Parse row data
                $data = $this->parseImportRow($row, $rowNumber);
                
                // Check for duplicate BL numbers (since NOA number is auto-generated)
                if ($skipDuplicates) {
                    $existing = $this->entityManager->getRepository(NOA::class)
                        ->findOneBy(['blNumber' => $data['bl_number']]);
                    
                    if ($existing) {
                        $result['skipped_count']++;
                        $result['skipped'][] = [
                            'row' => $rowNumber,
                            'noa_number' => 'Auto-generated',
                            'reason' => 'BL number already exists: ' . $data['bl_number']
                        ];
                        $rowNumber++;
                        continue;
                    }
                }
                
                if (!$validateOnly) {
                    // Create the NOA (NOA number will be auto-generated)
                    $noa = $this->createNOAFromImportData($data, $creator);
                    $result['success_count']++;
                    $result['imported_noas'][] = $noa;
                } else {
                    $result['valid_count']++;
                }
                
            } catch (\Exception $e) {
                $result['error_count']++;
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'noa_number' => 'N/A',
                    'message' => $this->translateErrorMessage($e->getMessage())
                ];
                $result['valid'] = false;
            }
            
            $rowNumber++;
        }

        return $result;
    }

    private function parseImportFile(string $filePath, string $extension): array
    {
        $rows = [];
        
        if ($extension === 'csv') {
            $handle = fopen($filePath, 'r');
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        } else {
            // For Excel files, you would need PhpSpreadsheet library
            // For now, we'll just support CSV
            throw new \Exception('Excel import not yet implemented. Please use CSV format.');
        }
        
        return $rows;
    }

    /**
     * Parse and validate import row (exposed for controller use)
     */
    public function parseAndValidateImportRow(array $data, int $rowNumber): array
    {
        return $this->parseImportRow(array_values($data), $rowNumber);
    }

    private function parseImportRow(array $row, int $rowNumber): array
    {
        if (count($row) < 6) {
            throw new \Exception('Invalid row format. Expected 6 columns.');
        }

        $data = [
            'bl_number' => trim($row[0]),
            'vessel_number' => trim($row[1]),
            'eta' => trim($row[2]),
            'consignee_email' => trim($row[3]),
            'port_location' => trim($row[4]),
            'containers_raw' => trim($row[5])
        ];

        // Validate required fields
        if (empty($data['bl_number'])) {
            throw new \Exception('BL number is required');
        }
        if (empty($data['vessel_number'])) {
            throw new \Exception('Vessel number is required');
        }
        if (empty($data['eta'])) {
            throw new \Exception('ETA is required');
        }
        if (empty($data['consignee_email'])) {
            throw new \Exception('Consignee email is required');
        }
        if (empty($data['port_location'])) {
            throw new \Exception('Port location is required');
        }
        if (empty($data['containers_raw'])) {
            throw new \Exception('At least one container is required');
        }

        // Validate port location exists as an active discharge terminal (not a CY)
        $portTerminal = $this->resolvePortTerminal($data['port_location']);
        $data['port_terminal'] = $portTerminal;

        // Parse ETA
        try {
            $data['eta_parsed'] = new \DateTime($data['eta']);
        } catch (\Exception $e) {
            throw new \Exception('Invalid ETA format. Use YYYY-MM-DD HH:MM:SS');
        }

        // Find consignee
        $consignee = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $data['consignee_email']]);
        
        if (!$consignee) {
            throw new \Exception('Consignee not found: ' . $data['consignee_email']);
        }
        
        // Validate consignee is accredited (APPROVED status and CONSIGNEE role)
        if ($consignee->getRole()->value !== 'CONSIGNEE') {
            throw new \Exception('User is not a consignee: ' . $data['consignee_email']);
        }
        
        if ($consignee->getStatus()->value !== 'APPROVED') {
            throw new \Exception('Consignee is not accredited (status: ' . $consignee->getStatus()->value . '): ' . $data['consignee_email']);
        }
        
        $data['consignee'] = $consignee;

        // Parse containers (format: number|size|type|port_code|...)
        $data['containers'] = $this->parseContainers($data['containers_raw']);

        return $data;
    }

    /**
     * Resolve and validate a discharge port/terminal code (ATI, ICTSI — not CY yards).
     */
    private function resolvePortTerminal(string $code): Terminal
    {
        $terminal = $this->entityManager->getRepository(Terminal::class)
            ->findOneBy(['code' => $code, 'isActive' => true]);

        if (!$terminal) {
            throw new \Exception(
                'Invalid port code: ' . $code . '. Terminal not found or inactive. Use port codes like: ATI, ICTSI'
            );
        }

        if ($terminal->getType() === TerminalType::CY) {
            throw new \Exception(
                'Invalid port location: ' . $code . '. Container Yard (CY) codes cannot be used as discharge ports. Use port codes like: ATI, ICTSI'
            );
        }

        return $terminal;
    }

    private function parseContainers(string $containersRaw): array
    {
        $parts = explode('|', $containersRaw);
        
        if (count($parts) % 4 !== 0) {
            throw new \Exception('Invalid container format. Each container needs 4 values: number|size|type|port_code');
        }

        $containers = [];
        for ($i = 0; $i < count($parts); $i += 4) {
            $containerNumber = trim($parts[$i]);
            $sizeValue = trim($parts[$i + 1]);
            $typeValue = trim($parts[$i + 2]);
            $portCode = trim($parts[$i + 3]);

            // Map simple size values (20, 40) to database codes (20FT, 40FT)
            $sizeCodeMap = [
                '20' => '20FT',
                '40' => '40FT',
                '40HC' => '40HC',
                '45' => '45FT'
            ];
            
            $sizeCode = $sizeCodeMap[$sizeValue] ?? $sizeValue;

            // Find size by code
            $size = $this->entityManager->getRepository(ContainerSize::class)
                ->findOneBy(['code' => $sizeCode, 'isActive' => true]);
            if (!$size) {
                throw new \Exception("Invalid container size: {$sizeValue}. Must be 20, 40, 40HC, or 45.");
            }

            // Find type by code
            $type = $this->entityManager->getRepository(ContainerType::class)
                ->findOneBy(['code' => $typeValue, 'isActive' => true]);
            if (!$type) {
                throw new \Exception("Invalid container type: {$typeValue}. Valid types: DRY, REEFER, OPEN_TOP, FLAT_RACK, TANK, HIGH_CUBE");
            }

            // Validate per-container discharge port code
            $terminal = $this->resolvePortTerminal($portCode);

            $containers[] = [
                'number' => $containerNumber,
                'size' => $size,
                'type' => $type,
                'port_code' => $portCode,
                'port_location' => $terminal->getLocation(),
            ];
        }

        return $containers;
    }

    /**
     * Create NOA from import data (used by bulk import)
     * This method is public to allow controller access for progress tracking
     */
    public function createNOAFromImportData(array $data, User $creator): NOA
    {
        // Check for duplicate container numbers BEFORE creating anything
        foreach ($data['containers'] as $containerData) {
            $existingContainer = $this->entityManager->getRepository(Container::class)
                ->findOneBy(['containerNumber' => $containerData['number']]);
            
            if ($existingContainer) {
                throw new \Exception(
                    sprintf('Container number "%s" already exists in the system.', $containerData['number'])
                );
            }
        }
        
        // Generate unique NOA number automatically using the standard format
        /** @var NOARepository $noaRepository */
        $noaRepository = $this->entityManager->getRepository(NOA::class);
        $sequence = $noaRepository->getNextSequenceNumber();
        $noaNumber = NOA::generateNoaNumber($sequence);

        // Create NOA
        $noa = new NOA();
        $noa->setNoaNumber($noaNumber);
        $noa->setBlNumber($data['bl_number']);
        $noa->setVesselNumber($data['vessel_number']);
        $noa->setEta($data['eta_parsed']);
        $noa->setPortLocation($data['port_location']);
        $noa->setConsignee($data['consignee']);
        $noa->setCreatedBy($creator);

        $this->entityManager->persist($noa);

        // Create containers
        foreach ($data['containers'] as $containerData) {
            $container = new Container();
            $container->setContainerNumber($containerData['number']);
            $container->setContainerSize($containerData['size']);
            $container->setContainerType($containerData['type']);
            $container->setNoa($noa);
            $container->setStatus(ContainerStatus::PENDING);
            $container->setCurrentLocation($containerData['port_location']);
            $container->setExpectedReturnDate(new \DateTime('+30 days'));
            
            // Set shipping line from creator if available
            $shippingLine = $creator->getShippingLineScope();
            if ($shippingLine) {
                $container->setShippingLine($shippingLine);
            }
            
            // CY allocation is assigned later in the workflow when empty containers are routed to yards
            // Port discharge location is captured via port_location on the NOA and container currentLocation
            $this->entityManager->persist($container);
        }

        // Flush to database - this may throw exceptions
        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Clear the EntityManager to detach all entities and reset state
            $this->entityManager->clear();
            throw $e;
        }

        // Refresh NOA to load containers collection
        $this->entityManager->refresh($noa);

        // Generate NOA PDF
        try {
            $pdfPath = $this->noaDocumentGenerator->generatePDF($noa);
            $noa->setPdfPath($pdfPath);
            $this->entityManager->flush(); // Save PDF path
        } catch (\Exception $e) {
            // Log PDF generation error but don't fail the entire operation
            error_log('WARNING: Failed to generate NOA PDF for ' . $noa->getNoaNumber() . ': ' . $e->getMessage());
        }

        // Send notification to consignee
        try {
            $this->notifyConsignee($noa);
        } catch (\Exception $e) {
            // Log notification error but don't fail the entire operation
            error_log('WARNING: Failed to send NOA notification for ' . $noa->getNoaNumber() . ': ' . $e->getMessage());
        }

        // Log audit
        $this->auditService->logAction(
            $creator,
            'create',
            'NOA',
            $noa->getId(),
            [
                'noa_number' => $noa->getNoaNumber(),
                'bl_number' => $noa->getBlNumber(),
                'vessel_number' => $noa->getVesselNumber(),
                'container_count' => count($data['containers']),
                'import_type' => 'bulk_import'
            ]
        );

        return $noa;
    }

    /**
     * Translate technical database errors into user-friendly messages
     */
    private function translateErrorMessage(string $errorMessage): string
    {
        // Check for duplicate entry errors
        if (preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/", $errorMessage, $matches)) {
            $duplicateValue = $matches[1];
            $keyName = $matches[2];
            
            // Translate key names to user-friendly field names
            if (stripos($keyName, 'noa_number') !== false || stripos($keyName, '41F0954C') !== false) {
                return "NOA number '{$duplicateValue}' already exists. This NOA has already been created.";
            } elseif (stripos($keyName, 'bl_number') !== false) {
                return "BL number '{$duplicateValue}' already exists. This shipment has already been registered.";
            } elseif (stripos($keyName, 'container_number') !== false) {
                return "Container number '{$duplicateValue}' already exists in the system.";
            } else {
                return "This record already exists in the system (duplicate: {$duplicateValue}).";
            }
        }
        
        // Check for foreign key constraint errors
        if (stripos($errorMessage, 'foreign key constraint') !== false || stripos($errorMessage, 'SQLSTATE[23000]') !== false) {
            if (stripos($errorMessage, 'consignee') !== false) {
                return "Invalid consignee. Please ensure the consignee exists and is accredited.";
            } elseif (stripos($errorMessage, 'shipping_line') !== false) {
                return "Invalid shipping line. Please contact support.";
            } else {
                return "Invalid reference data. Please check that all related records exist.";
            }
        }
        
        // Check for NOT NULL constraint errors
        if (stripos($errorMessage, 'cannot be null') !== false || stripos($errorMessage, 'NOT NULL') !== false) {
            if (stripos($errorMessage, 'bl_number') !== false) {
                return "BL number is required and cannot be empty.";
            } elseif (stripos($errorMessage, 'vessel_number') !== false) {
                return "Vessel number is required and cannot be empty.";
            } elseif (stripos($errorMessage, 'eta') !== false) {
                return "ETA (Estimated Time of Arrival) is required.";
            } elseif (stripos($errorMessage, 'consignee') !== false) {
                return "Consignee is required.";
            } else {
                return "A required field is missing. Please check all required fields are filled.";
            }
        }
        
        // Check for data too long errors
        if (stripos($errorMessage, 'Data too long') !== false) {
            return "One or more fields exceed the maximum allowed length. Please shorten your input.";
        }
        
        // Check for invalid datetime format
        if (stripos($errorMessage, 'Incorrect datetime value') !== false) {
            return "Invalid date/time format. Please use format: YYYY-MM-DD HH:MM:SS (e.g., 2026-06-15 08:30:00)";
        }
        
        // If no specific pattern matched, return a cleaned version of the original message
        // Remove SQL technical details but keep the core message
        $cleanMessage = preg_replace('/SQLSTATE\[\w+\]:\s*/', '', $errorMessage);
        $cleanMessage = preg_replace('/An exception occurred while executing a query:\s*/', '', $cleanMessage);
        $cleanMessage = preg_replace('/Integrity constraint violation:\s*\d+\s*/', '', $cleanMessage);
        
        // If the cleaned message is still too technical, provide a generic message
        if (strlen($cleanMessage) > 200 || stripos($cleanMessage, 'SQLSTATE') !== false) {
            return "An error occurred while processing this record. Please check your data and try again.";
        }
        
        return $cleanMessage;
    }
}

