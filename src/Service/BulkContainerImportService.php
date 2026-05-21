<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ContainerType;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\User;
use App\Entity\Enum\AllocationStatus;
use App\Entity\Enum\ContainerStatus;
use App\Repository\ContainerRepository;
use App\Repository\ContainerSizeRepository;
use App\Repository\ContainerTypeRepository;
use App\ValueObject\BulkValidationResult;
use App\ValueObject\ImportResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Psr\Log\LoggerInterface;

/**
 * Service for bulk container import with CY allocation support
 */
class BulkContainerImportService implements BulkContainerImportServiceInterface
{
    private const CSV_DELIMITER = ',';
    private const CSV_ENCLOSURE = '"';
    
    // Expected CSV columns
    private const COLUMN_CONTAINER_NUMBER = 'container_number';
    private const COLUMN_CONTAINER_TYPE = 'container_type';
    private const COLUMN_CONTAINER_SIZE = 'container_size';
    private const COLUMN_CY_LOCATION = 'cy_location';
    private const COLUMN_EXPECTED_RETURN_DATE = 'expected_return_date';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CYAllocationService $cyAllocationService,
        private ContainerAllocationAuditServiceInterface $auditService,
        private ContainerRepository $containerRepository,
        private ContainerTypeRepository $containerTypeRepository,
        private ContainerSizeRepository $containerSizeRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritdoc}
     * Task 4.2: Implement size-specific capacity validation for bulk imports
     */
    public function validateBulkAllocations(
        array $containerData,
        ShippingLine $shippingLine
    ): BulkValidationResult {
        $errors = [];
        $warnings = [];
        $capacityByAllocation = [];
        $validContainers = 0;

        // Get all available allocations for the shipping line
        $availableAllocations = $this->cyAllocationService->getAvailableAllocations($shippingLine);
        $allocationMap = $this->buildAllocationMap($availableAllocations);

        // First pass: validate each container and aggregate by size per allocation
        foreach ($containerData as $rowIndex => $row) {
            $rowNumber = $rowIndex + 2; // +2 for header row and 0-based index
            
            // Validate required fields
            $validationError = $this->validateRequiredFields($row, $rowNumber);
            if ($validationError) {
                $errors[] = $validationError;
                continue;
            }

            // Get container size to calculate TEU
            $containerSize = $this->containerSizeRepository->findOneBy([
                'name' => $row[self::COLUMN_CONTAINER_SIZE]
            ]);

            if (!$containerSize) {
                $errors[] = [
                    'row' => $rowNumber,
                    'container' => $row[self::COLUMN_CONTAINER_NUMBER],
                    'error' => "Invalid container size: {$row[self::COLUMN_CONTAINER_SIZE]}"
                ];
                continue;
            }

            $teuValue = $containerSize->getTeuValue();

            // Get CY allocation
            $cyLocationName = $row[self::COLUMN_CY_LOCATION] ?? null;
            
            if (!$cyLocationName) {
                // Will use default allocation - skip for now
                $warnings[] = [
                    'row' => $rowNumber,
                    'container' => $row[self::COLUMN_CONTAINER_NUMBER],
                    'warning' => 'No CY location specified, will use default allocation'
                ];
                continue;
            }

            // Map CY name to allocation
            if (!isset($allocationMap[$cyLocationName])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'container' => $row[self::COLUMN_CONTAINER_NUMBER],
                    'error' => "CY location '{$cyLocationName}' not found or not available for this shipping line"
                ];
                continue;
            }

            $allocation = $allocationMap[$cyLocationName];
            $allocationId = $allocation->getId();

            // Initialize allocation tracking with size-specific data
            if (!isset($capacityByAllocation[$allocationId])) {
                $utilization = $this->cyAllocationService->calculateUtilizationBySize($allocation);
                
                $capacityByAllocation[$allocationId] = [
                    'allocation' => $allocation,
                    'terminal_name' => $allocation->getTerminal()->getName(),
                    // Size-specific tracking
                    'required_20ft' => 0,
                    'required_40ft' => 0,
                    'available_20ft' => $utilization['20ft']->getAvailableTEU(),
                    'available_40ft' => $utilization['40ft']->getAvailableTEU(),
                    'containers_20ft' => [],
                    'containers_40ft' => [],
                    // Legacy TEU tracking for backward compatibility
                    'required_teu' => 0,
                    'available_teu' => $this->cyAllocationService->calculateUtilization($allocation)->getAvailableTeu(),
                    'containers' => []
                ];
            }

            // Aggregate by container size
            if ($teuValue == 1.0) {
                // 20ft container
                $capacityByAllocation[$allocationId]['required_20ft']++;
                $capacityByAllocation[$allocationId]['containers_20ft'][] = $row[self::COLUMN_CONTAINER_NUMBER];
            } elseif ($teuValue == 2.0) {
                // 40ft container
                $capacityByAllocation[$allocationId]['required_40ft']++;
                $capacityByAllocation[$allocationId]['containers_40ft'][] = $row[self::COLUMN_CONTAINER_NUMBER];
            }

            // Legacy TEU tracking
            $capacityByAllocation[$allocationId]['required_teu'] += $teuValue;
            $capacityByAllocation[$allocationId]['containers'][] = $row[self::COLUMN_CONTAINER_NUMBER];
            
            $validContainers++;
        }

        // Second pass: validate capacity separately for 20ft and 40ft containers
        foreach ($capacityByAllocation as $allocationId => $data) {
            $allocation = $data['allocation'];
            $terminalName = $data['terminal_name'];
            $sizeFailures = [];

            // Validate 20ft capacity
            if ($data['required_20ft'] > 0) {
                if ($data['required_20ft'] > $data['available_20ft']) {
                    $shortage20ft = $data['required_20ft'] - $data['available_20ft'];
                    $sizeFailures['20ft'] = [
                        'required' => $data['required_20ft'],
                        'available' => (int)$data['available_20ft'],
                        'shortage' => $shortage20ft,
                        'containers' => $data['containers_20ft']
                    ];
                }
            }

            // Validate 40ft capacity
            if ($data['required_40ft'] > 0) {
                if ($data['required_40ft'] > $data['available_40ft']) {
                    $shortage40ft = $data['required_40ft'] - $data['available_40ft'];
                    $sizeFailures['40ft'] = [
                        'required' => $data['required_40ft'],
                        'available' => (int)$data['available_40ft'],
                        'shortage' => $shortage40ft,
                        'containers' => $data['containers_40ft']
                    ];
                }
            }

            // If any size failed validation, create detailed error
            if (!empty($sizeFailures)) {
                $errors[] = [
                    'error_code' => 'BULK_IMPORT_CAPACITY_FAILURE',
                    'allocation' => $terminalName,
                    'allocation_id' => $allocationId,
                    'size_failures' => $sizeFailures,
                    'error' => $this->buildSizeSpecificErrorMessage($terminalName, $sizeFailures)
                ];
            }
        }

        $isValid = empty($errors);

        return new BulkValidationResult(
            $isValid,
            $errors,
            $warnings,
            count($containerData),
            $validContainers,
            $capacityByAllocation
        );
    }

    /**
     * Task 4.3: Build detailed error message showing which sizes failed and by how much
     * 
     * @param string $terminalName Terminal name
     * @param array $sizeFailures Array of size-specific failures
     * @return string Formatted error message
     */
    private function buildSizeSpecificErrorMessage(string $terminalName, array $sizeFailures): string
    {
        $messages = [];
        
        foreach ($sizeFailures as $size => $failure) {
            $messages[] = sprintf(
                '%s: Required %d containers, Available %d containers, Shortage %d containers. Affected: %s',
                $size,
                $failure['required'],
                $failure['available'],
                $failure['shortage'],
                implode(', ', $failure['containers'])
            );
        }
        
        return sprintf(
            'Insufficient capacity at %s. %s',
            $terminalName,
            implode(' | ', $messages)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function importWithAllocations(
        UploadedFile $file,
        ShippingLine $shippingLine,
        User $user,
        ?ShippingLineTerminalAllocation $defaultAllocation = null
    ): ImportResult {
        try {
            // Parse CSV file
            $containerData = $this->parseCsvFile($file);

            if (empty($containerData)) {
                return new ImportResult(
                    false,
                    0,
                    0,
                    [['error' => 'No valid data found in import file']],
                    [],
                    [],
                    'Import failed: No valid data found'
                );
            }

            // Validate all allocations atomically
            $validationResult = $this->validateBulkAllocations($containerData, $shippingLine);

            if (!$validationResult->isValid()) {
                return new ImportResult(
                    false,
                    0,
                    $validationResult->getTotalContainers(),
                    $validationResult->getErrors(),
                    $validationResult->getWarnings(),
                    [],
                    'Import failed: Validation errors found'
                );
            }

            // Get all available allocations for mapping
            $availableAllocations = $this->cyAllocationService->getAvailableAllocations($shippingLine);
            $allocationMap = $this->buildAllocationMap($availableAllocations);

            // Begin transaction
            $this->entityManager->beginTransaction();

            try {
                $importedContainerIds = [];
                $errors = [];
                $warnings = $validationResult->getWarnings();

                // Import each container
                foreach ($containerData as $rowIndex => $row) {
                    $rowNumber = $rowIndex + 2;

                    try {
                        $container = $this->createContainerFromRow(
                            $row,
                            $shippingLine,
                            $allocationMap,
                            $defaultAllocation
                        );

                        $this->entityManager->persist($container);
                        $this->entityManager->flush();

                        $importedContainerIds[] = $container->getId();

                        // Log allocation if assigned
                        if ($container->getCyAllocation()) {
                            $this->auditService->logAllocationChange(
                                $container,
                                null,
                                $container->getCyAllocation(),
                                $user,
                                'Bulk import'
                            );
                        }

                    } catch (\Exception $e) {
                        $errors[] = [
                            'row' => $rowNumber,
                            'container' => $row[self::COLUMN_CONTAINER_NUMBER] ?? 'Unknown',
                            'error' => $e->getMessage()
                        ];
                    }
                }

                // If any errors occurred, rollback
                if (!empty($errors)) {
                    $this->entityManager->rollback();
                    return new ImportResult(
                        false,
                        0,
                        count($containerData),
                        $errors,
                        $warnings,
                        [],
                        'Import failed: Errors occurred during container creation'
                    );
                }

                // Commit transaction
                $this->entityManager->commit();

                $this->logger->info('Bulk container import completed', [
                    'shipping_line_id' => $shippingLine->getId(),
                    'imported_count' => count($importedContainerIds),
                    'total_rows' => count($containerData)
                ]);

                return new ImportResult(
                    true,
                    count($importedContainerIds),
                    0,
                    [],
                    $warnings,
                    $importedContainerIds,
                    sprintf('Successfully imported %d containers', count($importedContainerIds))
                );

            } catch (\Exception $e) {
                $this->entityManager->rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->logger->error('Bulk container import failed', [
                'shipping_line_id' => $shippingLine->getId(),
                'error' => $e->getMessage()
            ]);

            return new ImportResult(
                false,
                0,
                0,
                [['error' => 'Import failed: ' . $e->getMessage()]],
                [],
                [],
                'Import failed due to system error'
            );
        }
    }

    /**
     * Parse CSV file and return array of container data
     */
    private function parseCsvFile(UploadedFile $file): array
    {
        $containerData = [];
        $handle = fopen($file->getPathname(), 'r');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open import file');
        }

        // Read header row
        $headers = fgetcsv($handle, 0, self::CSV_DELIMITER, self::CSV_ENCLOSURE);
        
        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException('Unable to read CSV headers');
        }

        // Normalize headers (lowercase, trim)
        $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

        // Read data rows
        while (($row = fgetcsv($handle, 0, self::CSV_DELIMITER, self::CSV_ENCLOSURE)) !== false) {
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Combine headers with row data
            $rowData = array_combine($headers, $row);
            $containerData[] = $rowData;
        }

        fclose($handle);

        return $containerData;
    }

    /**
     * Build a map of CY location names to allocation entities
     */
    private function buildAllocationMap(array $allocations): array
    {
        $map = [];
        
        foreach ($allocations as $allocation) {
            $terminalName = $allocation->getTerminal()->getName();
            $map[$terminalName] = $allocation;
        }

        return $map;
    }

    /**
     * Validate required fields in a row
     */
    private function validateRequiredFields(array $row, int $rowNumber): ?array
    {
        $requiredFields = [
            self::COLUMN_CONTAINER_NUMBER,
            self::COLUMN_CONTAINER_TYPE,
            self::COLUMN_CONTAINER_SIZE,
            self::COLUMN_EXPECTED_RETURN_DATE
        ];

        foreach ($requiredFields as $field) {
            if (empty($row[$field])) {
                return [
                    'row' => $rowNumber,
                    'error' => "Missing required field: {$field}"
                ];
            }
        }

        return null;
    }

    /**
     * Create a Container entity from CSV row data
     */
    private function createContainerFromRow(
        array $row,
        ShippingLine $shippingLine,
        array $allocationMap,
        ?ShippingLineTerminalAllocation $defaultAllocation
    ): Container {
        // Check if container already exists
        $existingContainer = $this->containerRepository->findByContainerNumber(
            $row[self::COLUMN_CONTAINER_NUMBER]
        );

        if ($existingContainer) {
            throw new \RuntimeException(
                "Container {$row[self::COLUMN_CONTAINER_NUMBER]} already exists"
            );
        }

        // Get container type
        $containerType = $this->containerTypeRepository->findOneBy([
            'name' => $row[self::COLUMN_CONTAINER_TYPE]
        ]);

        if (!$containerType) {
            throw new \RuntimeException(
                "Invalid container type: {$row[self::COLUMN_CONTAINER_TYPE]}"
            );
        }

        // Get container size
        $containerSize = $this->containerSizeRepository->findOneBy([
            'name' => $row[self::COLUMN_CONTAINER_SIZE]
        ]);

        if (!$containerSize) {
            throw new \RuntimeException(
                "Invalid container size: {$row[self::COLUMN_CONTAINER_SIZE]}"
            );
        }

        // Parse expected return date
        $expectedReturnDate = \DateTime::createFromFormat('Y-m-d', $row[self::COLUMN_EXPECTED_RETURN_DATE]);
        if (!$expectedReturnDate) {
            throw new \RuntimeException(
                "Invalid date format for expected_return_date. Expected: YYYY-MM-DD"
            );
        }

        // Determine CY allocation
        $cyAllocation = null;
        $cyLocationName = $row[self::COLUMN_CY_LOCATION] ?? null;

        if ($cyLocationName && isset($allocationMap[$cyLocationName])) {
            $cyAllocation = $allocationMap[$cyLocationName];
        } elseif ($defaultAllocation) {
            $cyAllocation = $defaultAllocation;
        }

        // Create container entity
        $container = new Container();
        $container->setContainerNumber($row[self::COLUMN_CONTAINER_NUMBER]);
        $container->setContainerType($containerType);
        $container->setContainerSize($containerSize);
        $container->setStatus(ContainerStatus::IN_TRANSIT);
        $container->setExpectedReturnDate($expectedReturnDate);
        $container->setShippingLine($shippingLine);

        // Assign CY allocation if available
        if ($cyAllocation) {
            $container->setCyAllocation($cyAllocation);
            $container->setAllocationStatus(AllocationStatus::PRE_FORECAST);
            $container->setAllocatedAt(new \DateTime());
        }

        return $container;
    }
}
