<?php

namespace App\Controller;

use App\Entity\Consignee;
use App\Entity\ContainerType;
use App\Entity\ContainerSize;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Enum\UserRole;
use App\Exception\BulkImportValidationException;
use App\Exception\InsufficientCapacityException;
use App\Exception\InvalidAllocationException;
use App\Exception\UnauthorizedAllocationException;
use App\Service\NOAServiceInterface;
use App\Service\CYAllocationService;
use App\Service\ContainerAllocationAuditServiceInterface;
use App\Service\NOADocumentGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/noa')]
class NOAController extends AbstractController
{
    public function __construct(
        private NOAServiceInterface $noaService,
        private CYAllocationService $cyAllocationService,
        private NOADocumentGenerator $noaDocumentGenerator,
        private ContainerAllocationAuditServiceInterface $auditService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Create a new NOA
     */
    #[Route('/create', name: 'noa_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // Requirement 12.1: Restrict NOA creation to Shipping_Lines_Terminal_Team and SL_STAFF
        $this->denyAccessUnlessGranted('create', 'NOA');

        try {
            // Get request data
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (empty($data['blNumber'])) {
                return $this->json(['error' => 'BL number is required'], Response::HTTP_BAD_REQUEST);
            }
            if (empty($data['vesselNumber'])) {
                return $this->json(['error' => 'Vessel number is required'], Response::HTTP_BAD_REQUEST);
            }
            if (empty($data['eta'])) {
                return $this->json(['error' => 'ETA is required'], Response::HTTP_BAD_REQUEST);
            }
            if (empty($data['consigneeId'])) {
                return $this->json(['error' => 'Consignee is required'], Response::HTTP_BAD_REQUEST);
            }
            if (empty($data['containers']) || !is_array($data['containers'])) {
                return $this->json(['error' => 'At least one container is required'], Response::HTTP_BAD_REQUEST);
            }

            // Get consignee
            $consignee = $this->entityManager->getRepository(Consignee::class)->find($data['consigneeId']);
            if (!$consignee) {
                return $this->json(['error' => 'Consignee not found'], Response::HTTP_NOT_FOUND);
            }

            // Parse ETA
            try {
                $eta = new \DateTime($data['eta']);
            } catch (\Exception $e) {
                return $this->json(['error' => 'Invalid ETA format'], Response::HTTP_BAD_REQUEST);
            }

            // Process containers
            $containers = [];
            $containerAllocations = []; // Track allocations for each container
            $cyLocationName = null; // Will be set from first container's allocation
            
            foreach ($data['containers'] as $containerData) {
                if (empty($containerData['number'])) {
                    return $this->json(['error' => 'Container number is required for all containers'], Response::HTTP_BAD_REQUEST);
                }
                if (empty($containerData['typeId'])) {
                    return $this->json(['error' => 'Container type is required for all containers'], Response::HTTP_BAD_REQUEST);
                }
                if (empty($containerData['sizeId'])) {
                    return $this->json(['error' => 'Container size is required for all containers'], Response::HTTP_BAD_REQUEST);
                }
                if (empty($containerData['cyAllocationId'])) {
                    return $this->json(['error' => 'CY location is required for all containers'], Response::HTTP_BAD_REQUEST);
                }

                $type = $this->entityManager->getRepository(ContainerType::class)->find($containerData['typeId']);
                if (!$type) {
                    return $this->json(['error' => 'Invalid container type'], Response::HTTP_BAD_REQUEST);
                }

                $size = $this->entityManager->getRepository(ContainerSize::class)->find($containerData['sizeId']);
                if (!$size) {
                    return $this->json(['error' => 'Invalid container size'], Response::HTTP_BAD_REQUEST);
                }

                // Task 7.1: Handle CY allocation for each container
                $cyAllocation = $this->entityManager
                    ->getRepository(ShippingLineTerminalAllocation::class)
                    ->find($containerData['cyAllocationId']);
                
                if (!$cyAllocation) {
                    return $this->json([
                        'error' => 'Invalid CY allocation',
                        'message' => sprintf('CY allocation ID %d not found', $containerData['cyAllocationId'])
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Validate shipping line context
                $user = $this->getUser();
                if ($user->getShippingLineScope() && $cyAllocation->getShippingLine()->getId() !== $user->getShippingLineScope()->getId()) {
                    return $this->json([
                        'error' => 'Unauthorized allocation',
                        'message' => 'Cannot assign container to CY allocation from different shipping line'
                    ], Response::HTTP_FORBIDDEN);
                }
                
                // Set cyLocationName from first container's allocation (for NOA record)
                if ($cyLocationName === null) {
                    $cyLocationName = $cyAllocation->getTerminal()->getName();
                }

                $containers[] = [
                    'number' => $containerData['number'],
                    'type' => $type,
                    'size' => $size,
                ];
                
                // Store allocation for later assignment
                $containerAllocations[] = $cyAllocation;
            }

            // Get CY allocation data before creating NOA
            // Note: CY capacity validation is handled at the allocation level
            // Each shipping line has specific terminal allocations
            $cyAllocationData = [
                'isValid' => true,
                'cyLocation' => $cyLocationName,
                'allocationId' => $containerAllocations[0]->getId() ?? null
            ];

            // Create NOA (wrapped in transaction)
            $this->entityManager->beginTransaction();
            
            try {
                $noa = $this->noaService->createNOA(
                    $data['blNumber'],
                    $data['vesselNumber'],
                    $eta,
                    $cyLocationName, // Use derived CY location name from first container
                    $consignee,
                    $containers,
                    $this->getUser()
                );

                // Task 7.1: Assign containers to CY allocations and log audit trail
                $createdContainers = $noa->getContainers()->toArray();
                $allocationErrors = [];
                
                foreach ($createdContainers as $index => $container) {
                    $allocation = $containerAllocations[$index];
                    
                    // Task 3.1: Validate capacity by container size (20ft or 40ft)
                    $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
                        $container,
                        $allocation
                    );
                    
                    if (!$validationResult->isSuccess()) {
                        // Task 3.2 & 3.3: Build error response with size-specific details
                        $details = $validationResult->getCapacityDetails();
                        $containerSize = $details['size'] ?? 'unknown';
                        
                        // Task 3.4: Get alternative location suggestions
                        $alternatives = $this->getAlternativeLocations(
                            $container,
                            $allocation,
                            $this->getUser()->getShippingLineScope()
                        );
                        
                        $allocationErrors[] = [
                            'container' => $container->getContainerNumber(),
                            'error_code' => $containerSize === '20ft' ? 'INSUFFICIENT_20FT_CAPACITY' : 'INSUFFICIENT_40FT_CAPACITY',
                            'message' => $validationResult->getMessage(),
                            'terminal_name' => $details['terminal_name'] ?? 'Unknown',
                            'terminal_id' => $details['terminal_id'] ?? null,
                            'container_size' => $containerSize,
                            'required_count' => 1,
                            'available_count' => (int)$validationResult->getAvailableTEU(),
                            'alternatives' => $alternatives,
                        ];
                        continue; // Skip this container allocation
                    }
                    
                    // Assign container to allocation
                    $this->cyAllocationService->assignContainer($container, $allocation);
                    
                    // Log initial allocation to audit trail
                    $this->auditService->logAllocationChange(
                        $container,
                        null, // No previous allocation
                        $allocation,
                        $this->getUser(),
                        'Initial allocation during NOA creation'
                    );
                }

                // If there were capacity validation errors, rollback and return errors
                if (!empty($allocationErrors)) {
                    $this->entityManager->rollback();
                    
                    return $this->json([
                        'success' => false,
                        'error' => 'Capacity validation failed',
                        'message' => 'One or more containers could not be allocated due to insufficient capacity',
                        'allocation_errors' => $allocationErrors
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                // Flush to database within transaction
                $this->entityManager->flush();

                // Create audit log for NOA creation
                $auditLog = new \App\Entity\AuditLog();
                $auditLog->setUser($this->getUser());
                $auditLog->setAction('NOA_CREATED');
                $auditLog->setEntityType('NOA');
                $auditLog->setEntityId($noa->getId());
                $auditLog->setChanges([
                    'changes' => [
                        sprintf('NOA %s created', $noa->getNoaNumber()),
                        sprintf('BL Number: %s', $noa->getBlNumber()),
                        sprintf('Vessel: %s', $noa->getVesselNumber()),
                        sprintf('ETA: %s', $noa->getEta()->format('M j, Y g:i A')),
                        sprintf('CY Location: %s', $noa->getCyLocation()),
                        sprintf('Consignee: %s', $noa->getConsignee()->getBusinessName()),
                        sprintf('Total Containers: %d', count($containers))
                    ]
                ]);
                $auditLog->setIpAddress($request->getClientIp() ?? '0.0.0.0');
                $this->entityManager->persist($auditLog);
                $this->entityManager->flush();

                // Generate PDF document
                $pdfPath = null;
                try {
                    $pdfPath = $this->noaDocumentGenerator->generatePDF($noa);
                    error_log('SUCCESS: PDF generated for NOA ' . $noa->getNoaNumber() . ' at: ' . $pdfPath);
                    
                    // Persist the PDF path to database
                    $this->entityManager->flush();
                } catch (\Throwable $pdfError) {
                    // Log PDF generation error but don't fail the entire operation
                    error_log('WARNING: PDF generation failed for NOA ' . $noa->getNoaNumber() . ': ' . $pdfError->getMessage());
                    error_log('PDF Error details: ' . $pdfError->getTraceAsString());
                    // PDF can be regenerated later if needed
                    $pdfPath = null;
                }
                
                // Commit transaction if NOA creation succeeded
                $this->entityManager->commit();

                // AUTO-LINK CONTAINERS: Create manifest and link containers automatically
                // This eliminates the need for brokers to manually add containers
                try {
                    $this->entityManager->beginTransaction();
                    
                    // Get the broker linked to the consignee
                    $broker = $consignee->getLinkedBroker();
                    
                    if ($broker) {
                        // Create a new manifest for this NOA
                        $manifest = new \App\Entity\Manifest();
                        $manifest->setNoa($noa);
                        $manifest->setConsignee($consignee);
                        $manifest->setBroker($broker);
                        $manifest->setWorkflowState(\App\Entity\Enum\WorkflowState::NOA_GENERATED);
                        $manifest->setCreatedAt(new \DateTime());
                        
                        $this->entityManager->persist($manifest);
                        
                        // Auto-link all containers to the manifest
                        foreach ($noa->getContainers() as $container) {
                            $container->setManifest($manifest);
                        }
                        
                        $this->entityManager->flush();
                        $this->entityManager->commit();
                        
                        error_log('SUCCESS: Auto-created manifest and linked ' . $noa->getContainers()->count() . ' containers for NOA ' . $noa->getNoaNumber());
                    } else {
                        $this->entityManager->rollback();
                        error_log('WARNING: No broker linked to consignee ' . $consignee->getEmail() . ' - containers not auto-linked');
                    }
                } catch (\Exception $manifestError) {
                    $this->entityManager->rollback();
                    error_log('WARNING: Failed to auto-create manifest for NOA ' . $noa->getNoaNumber() . ': ' . $manifestError->getMessage());
                    // Don't fail the entire NOA creation if manifest auto-creation fails
                    // Broker can still manually link containers later
                }

                return $this->json([
                    'success' => true,
                    'message' => 'NOA created successfully',
                    'noa' => [
                        'id' => $noa->getId(),
                        'noaNumber' => $noa->getNoaNumber(),
                        'blNumber' => $noa->getBlNumber(),
                        'vesselNumber' => $noa->getVesselNumber(),
                        'eta' => $noa->getEta()->format('Y-m-d H:i:s'),
                        'cyLocation' => $noa->getCyLocation(),
                        'containerCount' => $noa->getContainers()->count(),
                        'pdfPath' => $pdfPath,
                    ],
                    'cyAllocation' => $cyAllocationData
                ], Response::HTTP_CREATED);
                
            } catch (\Exception $e) {
                // Rollback transaction on any error
                $this->entityManager->rollback();
                throw $e; // Re-throw to be caught by outer catch blocks
            }

        } catch (\App\Exception\NOAValidationException $e) {
            error_log('NOA Validation Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return $this->json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            error_log('NOA Invalid Argument Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return $this->json([
                'success' => false,
                'error' => 'Validation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            // Log the actual error for debugging
            error_log('NOA Creation Error: ' . $e->getMessage());
            error_log('Error class: ' . get_class($e));
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'success' => false,
                'error' => 'Failed to create NOA',
                'message' => $e->getMessage(),
                'error_class' => get_class($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get NOA by ID
     */
    #[Route('/{id}', name: 'noa_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($id);

        if (!$noa) {
            return $this->json(['error' => 'NOA not found'], Response::HTTP_NOT_FOUND);
        }

        $containers = [];
        foreach ($noa->getContainers() as $container) {
            $containers[] = [
                'id' => $container->getId(),
                'number' => $container->getContainerNumber(),
                'type' => $container->getContainerType()->getName(),
                'size' => $container->getContainerSize()->getName(),
                'teu' => $container->getContainerSize()->getTeuValue(),
            ];
        }

        return $this->json([
            'id' => $noa->getId(),
            'noaNumber' => $noa->getNoaNumber(),
            'blNumber' => $noa->getBlNumber(),
            'vesselNumber' => $noa->getVesselNumber(),
            'eta' => $noa->getEta()->format('Y-m-d H:i:s'),
            'cyLocation' => $noa->getCyLocation(),
            'consignee' => [
                'id' => $noa->getConsignee()->getId(),
                'email' => $noa->getConsignee()->getEmail(),
            ],
            'containers' => $containers,
            'createdAt' => $noa->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Task 3.4: Get alternative CY locations with sufficient capacity
     * Returns up to 3 alternative locations sorted by available capacity
     * 
     * @param Container $container The container needing allocation
     * @param ShippingLineTerminalAllocation $failedAllocation The allocation that failed
     * @param ShippingLine|null $shippingLine The shipping line context
     * @return array Array of alternative locations with capacity info
     */
    private function getAlternativeLocations(
        Container $container,
        ShippingLineTerminalAllocation $failedAllocation,
        ?ShippingLine $shippingLine
    ): array {
        if (!$shippingLine) {
            return [];
        }

        $teuValue = $container->getContainerSize()->getTeuValue();
        $alternatives = [];

        // Get all available allocations for this size
        $availableAllocations = $this->cyAllocationService->getAvailableAllocationsBySize(
            $shippingLine,
            $teuValue
        );

        // Filter out the failed allocation and get top 3
        $count = 0;
        foreach ($availableAllocations as $allocationData) {
            if ($count >= 3) {
                break;
            }

            $allocation = $allocationData['allocation'];
            
            // Skip the allocation that failed
            if ($allocation->getId() === $failedAllocation->getId()) {
                continue;
            }

            $utilization = $allocationData['utilization'];
            $terminal = $allocation->getTerminal();
            $sizeKey = $allocationData['size'];

            $alternatives[] = [
                'allocation_id' => $allocation->getId(),
                'terminal_id' => $terminal->getId(),
                'terminal_name' => $terminal->getName(),
                'terminal_location' => $terminal->getLocation(),
                'container_size' => $sizeKey,
                'available_capacity' => (int)$utilization->getAvailableTEU(),
                'utilization_percentage' => round($utilization->getUtilizationPercentage(), 1),
            ];

            $count++;
        }

        return $alternatives;
    }

    /**
     * Task 7.3: Update container allocation
     * Accept container ID and new allocation ID
     * Validate container belongs to user's shipping line
     * Call CYAllocationService.reassignContainer()
     * Return success response with updated utilization
     */
    #[Route('/containers/{id}/allocation', name: 'noa_update_container_allocation', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateContainerAllocation(int $id, Request $request): JsonResponse
    {
        try {
            // Get container
            $container = $this->entityManager->getRepository(\App\Entity\Container::class)->find($id);
            
            if (!$container) {
                return $this->json([
                    'success' => false,
                    'error' => 'Container not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate container belongs to user's shipping line
            $user = $this->getUser();
            if ($user->getShippingLineScope() && $container->getShippingLine() && 
                $container->getShippingLine()->getId() !== $user->getShippingLineScope()->getId()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Container does not belong to your shipping line'
                ], Response::HTTP_FORBIDDEN);
            }

            // Get request data
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['newAllocationId'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'New allocation ID is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get new allocation
            $newAllocation = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->find($data['newAllocationId']);
            
            if (!$newAllocation) {
                return $this->json([
                    'success' => false,
                    'error' => 'Allocation not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate allocation belongs to user's shipping line
            if ($user->getShippingLineScope() && 
                $newAllocation->getShippingLine()->getId() !== $user->getShippingLineScope()->getId()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Cannot assign container to CY allocation from different shipping line'
                ], Response::HTTP_FORBIDDEN);
            }

            // Store previous allocation for audit
            $previousAllocation = $container->getCyAllocation();

            // Reassign container using service
            $this->cyAllocationService->reassignContainer($container, $newAllocation);

            // Log allocation change
            $this->auditService->logAllocationChange(
                $container,
                $previousAllocation,
                $newAllocation,
                $user,
                $data['reason'] ?? 'Container reassignment'
            );

            // Get updated utilization for both allocations
            $newUtilization = $this->cyAllocationService->calculateUtilization($newAllocation);
            $previousUtilization = $previousAllocation 
                ? $this->cyAllocationService->calculateUtilization($previousAllocation)
                : null;

            return $this->json([
                'success' => true,
                'message' => 'Container allocation updated successfully',
                'container' => [
                    'id' => $container->getId(),
                    'number' => $container->getContainerNumber(),
                    'allocation_status' => $container->getAllocationStatus()->value,
                    'allocated_at' => $container->getAllocatedAt()?->format('Y-m-d H:i:s'),
                ],
                'new_allocation' => [
                    'id' => $newAllocation->getId(),
                    'terminal_name' => $newAllocation->getTerminal()->getName(),
                    'utilization' => [
                        'used_teu' => $newUtilization->getUsedTEU(),
                        'available_teu' => $newUtilization->getAvailableTEU(),
                        'total_capacity_teu' => $newUtilization->getTotalCapacityTEU(),
                        'utilization_percentage' => $newUtilization->getUtilizationPercentage(),
                    ],
                ],
                'previous_allocation' => $previousAllocation ? [
                    'id' => $previousAllocation->getId(),
                    'terminal_name' => $previousAllocation->getTerminal()->getName(),
                    'utilization' => [
                        'used_teu' => $previousUtilization->getUsedTEU(),
                        'available_teu' => $previousUtilization->getAvailableTEU(),
                        'total_capacity_teu' => $previousUtilization->getTotalCapacityTEU(),
                        'utilization_percentage' => $previousUtilization->getUtilizationPercentage(),
                    ],
                ] : null,
            ], Response::HTTP_OK);

        } catch (\RuntimeException $e) {
            // Handle allocation locked or capacity validation errors
            return $this->json([
                'success' => false,
                'error' => 'Allocation update failed',
                'message' => $e->getMessage()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            error_log('Container allocation update error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'success' => false,
                'error' => 'Failed to update container allocation',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Task 7.4: Validate capacity for container allocation
     * Accept container TEU value and allocation ID
     * Call CYAllocationService.validateCapacity()
     * Return validation result with capacity details
     * Include alternative location suggestions if validation fails
     */
    #[Route('/validate-capacity', name: 'noa_validate_capacity', methods: ['POST'])]
    public function validateCapacity(Request $request): JsonResponse
    {
        try {
            // Get request data
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['teuValue'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'TEU value is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($data['allocationId'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Allocation ID is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get allocation
            $allocation = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->find($data['allocationId']);
            
            if (!$allocation) {
                return $this->json([
                    'success' => false,
                    'error' => 'Allocation not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate allocation belongs to user's shipping line
            $user = $this->getUser();
            if ($user->getShippingLineScope() && 
                $allocation->getShippingLine()->getId() !== $user->getShippingLineScope()->getId()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Cannot validate capacity for CY allocation from different shipping line'
                ], Response::HTTP_FORBIDDEN);
            }

            // Calculate available capacity
            $utilization = $this->cyAllocationService->calculateUtilization($allocation);
            $requiredTEU = (float) $data['teuValue'];
            $availableTEU = $utilization->getAvailableTEU();
            $isValid = $availableTEU >= $requiredTEU;

            $response = [
                'success' => true,
                'is_valid' => $isValid,
                'allocation' => [
                    'id' => $allocation->getId(),
                    'terminal_name' => $allocation->getTerminal()->getName(),
                    'terminal_location' => $allocation->getTerminal()->getLocation(),
                ],
                'capacity' => [
                    'required_teu' => $requiredTEU,
                    'available_teu' => $availableTEU,
                    'total_capacity_teu' => $utilization->getTotalCapacityTEU(),
                    'used_teu' => $utilization->getUsedTEU(),
                    'utilization_percentage' => $utilization->getUtilizationPercentage(),
                ],
            ];

            // If validation fails, include shortage and alternative suggestions
            if (!$isValid) {
                $shortage = $requiredTEU - $availableTEU;
                $response['capacity']['shortage_teu'] = $shortage;
                $response['message'] = sprintf(
                    'Insufficient capacity at %s. Required: %.1f TEU, Available: %.1f TEU, Shortage: %.1f TEU',
                    $allocation->getTerminal()->getName(),
                    $requiredTEU,
                    $availableTEU,
                    $shortage
                );

                // Find alternative locations with sufficient capacity
                $alternatives = [];
                if ($user->getShippingLineScope()) {
                    $allAllocations = $this->cyAllocationService->getAvailableAllocations($user->getShippingLineScope());
                    
                    foreach ($allAllocations as $allocationData) {
                        $altAllocation = $allocationData['allocation'];
                        $altUtilization = $allocationData['utilization'];
                        
                        // Skip the current allocation
                        if ($altAllocation->getId() === $allocation->getId()) {
                            continue;
                        }
                        
                        // Only include allocations with sufficient capacity
                        if ($altUtilization->getAvailableTEU() >= $requiredTEU) {
                            $alternatives[] = [
                                'id' => $altAllocation->getId(),
                                'terminal_name' => $altAllocation->getTerminal()->getName(),
                                'terminal_location' => $altAllocation->getTerminal()->getLocation(),
                                'available_teu' => $altUtilization->getAvailableTEU(),
                                'utilization_percentage' => $altUtilization->getUtilizationPercentage(),
                            ];
                        }
                    }
                }

                $response['alternatives'] = $alternatives;
                
                if (empty($alternatives)) {
                    $response['message'] .= ' No alternative locations with sufficient capacity available.';
                }
            }

            return $this->json($response, Response::HTTP_OK);

        } catch (\Exception $e) {
            error_log('Capacity validation error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'success' => false,
                'error' => 'Failed to validate capacity',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
