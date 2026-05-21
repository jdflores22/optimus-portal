<?php

namespace App\Controller\Api;

use App\Entity\Container;
use App\Entity\ContainerType;
use App\Entity\ContainerSize;
use App\Entity\Consignee;
use App\Entity\ShippingLineTerminalAllocation;
use App\Exception\AllocationLockedException;
use App\Exception\InsufficientCapacityException;
use App\Exception\InvalidAllocationException;
use App\Exception\UnauthorizedAllocationException;
use App\Service\CYAllocationService;
use App\Service\ContainerAllocationAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ContainerMetadataController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CYAllocationService $cyAllocationService,
        private ContainerAllocationAuditService $auditService
    ) {
    }

    #[Route('/api/container-types', name: 'api_container_types', methods: ['GET'])]
    public function getContainerTypes(): JsonResponse
    {
        $types = $this->entityManager->getRepository(ContainerType::class)
            ->findAll();

        $result = [];
        foreach ($types as $type) {
            $result[] = [
                'id' => $type->getId(),
                'name' => $type->getName(),
                'code' => $type->getCode()
            ];
        }

        return new JsonResponse(['types' => $result]);
    }

    #[Route('/api/container-sizes', name: 'api_container_sizes', methods: ['GET'])]
    public function getContainerSizes(): JsonResponse
    {
        $sizes = $this->entityManager->getRepository(ContainerSize::class)
            ->findAll();

        $result = [];
        foreach ($sizes as $size) {
            $result[] = [
                'id' => $size->getId(),
                'name' => $size->getName(),
                'teuValue' => $size->getTeuValue()
            ];
        }

        return new JsonResponse(['sizes' => $result]);
    }

    #[Route('/api/consignees/all', name: 'api_consignees_all', methods: ['GET'])]
    public function getConsignees(Request $request): JsonResponse
    {
        $search = $request->query->get('search', '');
        
        $qb = $this->entityManager->getRepository(Consignee::class)
            ->createQueryBuilder('c');
        
        if ($search) {
            $qb->where('c.businessName LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        
        $consignees = $qb->setMaxResults(50)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($consignees as $consignee) {
            $result[] = [
                'id' => $consignee->getId(),
                'businessName' => $consignee->getBusinessName(),
                'email' => $consignee->getEmail()
            ];
        }

        return new JsonResponse(['consignees' => $result]);
    }

    #[Route('/api/cy-allocations/all', name: 'api_cy_allocations_all', methods: ['GET'])]
    public function getCYAllocations(Request $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $this->getUser();
            
            if (!$user) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Get the user's shipping line scope
            try {
                $shippingLine = $user->getShippingLineScope();
            } catch (\Exception $e) {
                error_log('Error getting shipping line scope: ' . $e->getMessage());
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Error retrieving shipping line information: ' . $e->getMessage()
                ], 500);
            }
            
            if (!$shippingLine) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'No shipping line associated with your account'
                ], 403);
            }
            
            // Query allocations filtered by the user's shipping line
            $qb = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
                ->createQueryBuilder('alloc')
                ->leftJoin('alloc.terminal', 'terminal')
                ->leftJoin('alloc.shippingLine', 'sl')
                ->where('alloc.shippingLine = :shippingLine')
                ->setParameter('shippingLine', $shippingLine);
            
            $allocations = $qb->getQuery()->getResult();

            $result = [];
            foreach ($allocations as $allocation) {
                $terminal = $allocation->getTerminal();
                $allocatedTeu = $allocation->getAllocatedCapacity();
                
                $containerRepo = $this->entityManager->getRepository(Container::class);
                
                // Calculate TEU from containers with allocation_status = 'allocated' (physically at CY)
                $allocatedContainers = $containerRepo->createQueryBuilder('c')
                    ->leftJoin('c.containerSize', 'cs')
                    ->addSelect('cs')
                    ->where('c.cyAllocation = :allocation')
                    ->andWhere('c.allocationStatus = :status')
                    ->andWhere('c.shippingLine = :shippingLine')
                    ->setParameter('allocation', $allocation)
                    ->setParameter('status', \App\Entity\Enum\AllocationStatus::ALLOCATED)
                    ->setParameter('shippingLine', $shippingLine)
                    ->getQuery()
                    ->getResult();
                
                $allocatedTeuCount = 0;
                foreach ($allocatedContainers as $container) {
                    $size = $container->getContainerSize();
                    if ($size) {
                        $allocatedTeuCount += $size->getTeuValue();
                    }
                }
                
                // Calculate TEU from containers with allocation_status = 'pre_forecast' (announced but not yet at CY)
                $preForecastContainers = $containerRepo->createQueryBuilder('c')
                    ->leftJoin('c.containerSize', 'cs')
                    ->addSelect('cs')
                    ->where('c.cyAllocation = :allocation')
                    ->andWhere('c.allocationStatus = :status')
                    ->andWhere('c.shippingLine = :shippingLine')
                    ->setParameter('allocation', $allocation)
                    ->setParameter('status', \App\Entity\Enum\AllocationStatus::PRE_FORECAST)
                    ->setParameter('shippingLine', $shippingLine)
                    ->getQuery()
                    ->getResult();
                
                $preForecastTeuCount = 0;
                foreach ($preForecastContainers as $container) {
                    $size = $container->getContainerSize();
                    if ($size) {
                        $preForecastTeuCount += $size->getTeuValue();
                    }
                }
                
                // Total used TEU = allocated + pre-forecast
                $totalUsedTeu = $allocatedTeuCount + $preForecastTeuCount;
                
                // Task 2.1: Calculate 20ft container counts
                $allocated20ft = $this->countContainersBySize(
                    $allocation,
                    \App\Entity\Enum\AllocationStatus::ALLOCATED,
                    1.0
                );
                
                $preForecast20ft = $this->countContainersBySize(
                    $allocation,
                    \App\Entity\Enum\AllocationStatus::PRE_FORECAST,
                    1.0
                );
                
                // Task 2.1: Calculate 40ft container counts
                $allocated40ft = $this->countContainersBySize(
                    $allocation,
                    \App\Entity\Enum\AllocationStatus::ALLOCATED,
                    2.0
                );
                
                $preForecast40ft = $this->countContainersBySize(
                    $allocation,
                    \App\Entity\Enum\AllocationStatus::PRE_FORECAST,
                    2.0
                );
                
                // Task 2.2 & 2.3: Calculate size-specific metrics
                $capacity20ft = $allocation->getCapacity20ft();
                $capacity40ft = $allocation->getCapacity40ft();
                
                $used20ft = $allocated20ft + $preForecast20ft;
                $used40ft = $allocated40ft + $preForecast40ft;
                
                $available20ft = max(0, $capacity20ft - $used20ft);
                $available40ft = max(0, $capacity40ft - $used40ft);
                
                $utilization20ft = $capacity20ft > 0 
                    ? ($used20ft / $capacity20ft) * 100 
                    : 0;
                    
                $utilization40ft = $capacity40ft > 0 
                    ? ($used40ft / $capacity40ft) * 100 
                    : 0;
                
                // Task 2.4: Maintain backward compatibility with TEU-based fields
                $result[] = [
                    'id' => $allocation->getId(),
                    'terminal_name' => $terminal->getName(),
                    'location' => $terminal->getLocation() ?? 'N/A',
                    
                    // TEU-based fields (backward compatibility)
                    'total_teu_capacity' => $allocatedTeu,
                    'allocated_teu' => $allocatedTeuCount,
                    'pre_forecast_teu' => $preForecastTeuCount,
                    'used_teu' => $totalUsedTeu,
                    'available_teu' => $allocatedTeu - $totalUsedTeu,
                    
                    // Task 2.2: 20ft container fields
                    'capacity_20ft' => $capacity20ft,
                    'allocated_20ft' => $allocated20ft,
                    'pre_forecast_20ft' => $preForecast20ft,
                    'available_20ft' => $available20ft,
                    'utilization_percentage_20ft' => round($utilization20ft, 1),
                    
                    // Task 2.3: 40ft container fields
                    'capacity_40ft' => $capacity40ft,
                    'allocated_40ft' => $allocated40ft,
                    'pre_forecast_40ft' => $preForecast40ft,
                    'available_40ft' => $available40ft,
                    'utilization_percentage_40ft' => round($utilization40ft, 1),
                ];
            }

            return new JsonResponse(['allocations' => $result]);
            
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('CY Allocations API Error: ' . $e->getMessage());
            
            return new JsonResponse([
                'error' => true,
                'message' => 'An error occurred while fetching allocations',
                'debug' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Task 6.1: Get available CY allocations for shipping line
     * Accept shipping_line_id and optional min_capacity_teu query parameters
     * Call CYAllocationService.getAvailableAllocations()
     * Return JSON response with allocations and utilization data
     */
    #[Route('/api/cy-allocations', name: 'api_get_cy_allocations', methods: ['GET'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function getCYAllocationsAction(Request $request): JsonResponse
    {
        try {
            $user = $this->getUser();
            $shippingLine = $user->getShippingLineScope();
            
            if (!$shippingLine) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'No shipping line associated with your account'
                ], 403);
            }

            // Get optional min_capacity_teu filter
            $minCapacityTeu = $request->query->get('min_capacity_teu');
            
            // Get available allocations using service
            $allocationsData = $this->cyAllocationService->getAvailableAllocations($shippingLine);
            
            $result = [];
            foreach ($allocationsData as $data) {
                $allocation = $data['allocation'];
                $utilization = $data['utilization'];
                
                // Apply min capacity filter if specified
                if ($minCapacityTeu !== null && $utilization->getAvailableTEU() < (float)$minCapacityTeu) {
                    continue;
                }
                
                $terminal = $allocation->getTerminal();
                
                // Calculate size-specific utilization
                $capacity20ft = $allocation->getCapacity20ft();
                $capacity40ft = $allocation->getCapacity40ft();
                
                // Count 20ft containers (teuValue = 1.0)
                $count20ft = $this->entityManager->getRepository(\App\Entity\Container::class)
                    ->createQueryBuilder('c')
                    ->select('COUNT(c.id)')
                    ->leftJoin('c.containerSize', 'cs')
                    ->where('c.cyAllocation = :allocation')
                    ->andWhere('cs.teuValue = 1.0')
                    ->setParameter('allocation', $allocation)
                    ->getQuery()
                    ->getSingleScalarResult();
                
                // Count 40ft+ containers (teuValue >= 2.0)
                $count40ft = $this->entityManager->getRepository(\App\Entity\Container::class)
                    ->createQueryBuilder('c')
                    ->select('COUNT(c.id)')
                    ->leftJoin('c.containerSize', 'cs')
                    ->where('c.cyAllocation = :allocation')
                    ->andWhere('cs.teuValue >= 2.0')
                    ->setParameter('allocation', $allocation)
                    ->getQuery()
                    ->getSingleScalarResult();
                
                $available20ft = $capacity20ft - $count20ft;
                $available40ft = $capacity40ft - $count40ft;
                
                $result[] = [
                    'id' => $allocation->getId(),
                    'terminal' => [
                        'id' => $terminal->getId(),
                        'name' => $terminal->getName(),
                        'location' => $terminal->getLocation() ?? 'N/A',
                    ],
                    'allocated_capacity_teu' => $utilization->getTotalCapacityTEU(),
                    'used_capacity_teu' => $utilization->getUsedTEU(),
                    'available_capacity_teu' => $utilization->getAvailableTEU(),
                    'utilization_percentage' => round($utilization->getUtilizationPercentage(), 2),
                    'container_count' => $utilization->getContainerCount(),
                    // Size-specific capacity
                    'capacity_20ft' => $capacity20ft,
                    'used_20ft' => (int)$count20ft,
                    'available_20ft' => max(0, $available20ft),
                    'capacity_40ft' => $capacity40ft,
                    'used_40ft' => (int)$count40ft,
                    'available_40ft' => max(0, $available40ft),
                ];
            }

            return new JsonResponse([
                'success' => true,
                'allocations' => $result
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => true,
                'message' => 'Failed to fetch CY allocations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Task 6.3: Allocate container to CY allocation
     * Accept container ID and allocation_id in request body
     * Call CYAllocationService.assignContainer()
     * Return JSON response with success status and updated utilization
     * Handle InsufficientCapacityException with 400 response
     */
    #[Route('/api/containers/{id}/allocate', name: 'api_allocate_container', methods: ['POST'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function allocateContainerAction(int $id, Request $request): JsonResponse
    {
        try {
            // Get container
            $container = $this->entityManager->getRepository(Container::class)->find($id);
            
            if (!$container) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Container not found'
                ], 404);
            }

            // Get allocation_id from request body
            $data = json_decode($request->getContent(), true);
            $allocationId = $data['allocation_id'] ?? null;
            
            if (!$allocationId) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'allocation_id is required'
                ], 400);
            }

            // Get allocation
            $allocation = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->find($allocationId);
            
            if (!$allocation) {
                throw new InvalidAllocationException($allocationId, 'CY allocation not found');
            }

            // Verify shipping line context
            $user = $this->getUser();
            $userShippingLine = $user->getShippingLineScope();
            
            if ($allocation->getShippingLine()->getId() !== $userShippingLine->getId()) {
                throw new UnauthorizedAllocationException($allocation, $userShippingLine);
            }

            // Validate capacity by size - this will throw InsufficientCapacityException if validation fails
            $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
                $container,
                $allocation
            );
            
            if (!$validationResult->isSuccess()) {
                // Extract container size from validation result
                $capacityDetails = $validationResult->getCapacityDetails();
                $containerSize = $capacityDetails['size'] ?? null;
                
                throw new InsufficientCapacityException(
                    $validationResult->getRequiredTEU(),
                    $validationResult->getAvailableTEU(),
                    $allocation,
                    null,
                    $containerSize
                );
            }

            // Assign container
            $this->cyAllocationService->assignContainer($container, $allocation);
            
            // Get updated utilization
            $utilization = $this->cyAllocationService->calculateUtilization($allocation);

            return new JsonResponse([
                'success' => true,
                'message' => 'Container allocated successfully',
                'container' => [
                    'id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'allocation_status' => $container->getAllocationStatus()->value,
                    'allocated_at' => $container->getAllocatedAt()?->format('Y-m-d H:i:s'),
                ],
                'utilization' => [
                    'used_capacity_teu' => $utilization->getUsedTEU(),
                    'available_capacity_teu' => $utilization->getAvailableTEU(),
                    'utilization_percentage' => round($utilization->getUtilizationPercentage(), 2),
                    'container_count' => $utilization->getContainerCount(),
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => true,
                'message' => 'Failed to allocate container: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Task 6.5: Reallocate container to different CY allocation
     * Accept container ID and new_allocation_id in request body
     * Validate container has PRE_FORECAST status
     * Call CYAllocationService.reassignContainer()
     * Log allocation change via ContainerAllocationAuditService
     * Return JSON response with success status and audit log entry
     * Handle AllocationLockedException with 403 response
     */
    #[Route('/api/containers/{id}/reallocate', name: 'api_reallocate_container', methods: ['PUT'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function reallocateContainerAction(int $id, Request $request): JsonResponse
    {
        try {
            // Get container
            $container = $this->entityManager->getRepository(Container::class)->find($id);
            
            if (!$container) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'Container not found'
                ], 404);
            }

            // Check if container can be modified
            if (!$container->canModifyAllocation()) {
                throw new AllocationLockedException(
                    $container->getContainerNumber(),
                    $container->getAllocationStatus(),
                    $container
                );
            }

            // Get new_allocation_id from request body
            $data = json_decode($request->getContent(), true);
            $newAllocationId = $data['new_allocation_id'] ?? null;
            $reason = $data['reason'] ?? null;
            
            if (!$newAllocationId) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'new_allocation_id is required'
                ], 400);
            }

            // Get new allocation
            $newAllocation = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->find($newAllocationId);
            
            if (!$newAllocation) {
                throw new InvalidAllocationException($newAllocationId, 'CY allocation not found');
            }

            // Verify shipping line context
            $user = $this->getUser();
            $userShippingLine = $user->getShippingLineScope();
            
            if ($newAllocation->getShippingLine()->getId() !== $userShippingLine->getId()) {
                throw new UnauthorizedAllocationException($newAllocation, $userShippingLine);
            }

            // Store previous allocation for audit
            $previousAllocation = $container->getCyAllocation();

            // Reassign container (includes capacity validation)
            $this->cyAllocationService->reassignContainer($container, $newAllocation);
            
            // Log allocation change
            $auditLog = $this->auditService->logAllocationChange(
                $container,
                $previousAllocation,
                $newAllocation,
                $this->getUser(),
                $reason
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Container reallocated successfully',
                'container' => [
                    'id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'allocation_status' => $container->getAllocationStatus()->value,
                    'allocated_at' => $container->getAllocatedAt()?->format('Y-m-d H:i:s'),
                ],
                'audit_log' => [
                    'id' => $auditLog->getId(),
                    'change_type' => $auditLog->getChangeType(),
                    'changed_at' => $auditLog->getChangedAt()->format('Y-m-d H:i:s'),
                    'changed_by' => $auditLog->getChangedBy()->getEmail(),
                    'previous_terminal' => $previousAllocation ? $previousAllocation->getTerminal()->getName() : null,
                    'new_terminal' => $newAllocation->getTerminal()->getName(),
                ]
            ]);
            
        } catch (AllocationLockedException | InsufficientCapacityException | InvalidAllocationException | UnauthorizedAllocationException $e) {
            // These exceptions are handled by the CYAllocationExceptionSubscriber
            throw $e;
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => true,
                'message' => 'Failed to reallocate container: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Task 6.7: Get utilization for specific allocation
     * Accept allocation ID as route parameter
     * Call CYAllocationService.calculateUtilization()
     * Return JSON response with utilization metrics
     */
    #[Route('/api/allocations/{id}/utilization', name: 'api_get_allocation_utilization', methods: ['GET'])]
    #[IsGranted('ROLE_SL_STAFF')]
    public function getUtilizationAction(int $id): JsonResponse
    {
        try {
            // Get allocation
            $allocation = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->find($id);
            
            if (!$allocation) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'CY allocation not found'
                ], 404);
            }

            // Calculate utilization
            $utilization = $this->cyAllocationService->calculateUtilization($allocation);
            $terminal = $allocation->getTerminal();

            return new JsonResponse([
                'success' => true,
                'allocation_id' => $allocation->getId(),
                'terminal_name' => $terminal->getName(),
                'terminal_location' => $terminal->getLocation() ?? 'N/A',
                'total_capacity_teu' => $utilization->getTotalCapacityTEU(),
                'used_capacity_teu' => $utilization->getUsedTEU(),
                'available_capacity_teu' => $utilization->getAvailableTEU(),
                'utilization_percentage' => round($utilization->getUtilizationPercentage(), 2),
                'container_count' => $utilization->getContainerCount(),
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => true,
                'message' => 'Failed to fetch utilization: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Task 2.1: Helper method to count containers by size
     * Query containers by allocation, status, and TEU value
     * 
     * @param ShippingLineTerminalAllocation $allocation The allocation to query
     * @param AllocationStatus $status The allocation status to filter by
     * @param float $teuValue The TEU value (1.0 for 20ft, 2.0 for 40ft)
     * @return int Count of containers matching criteria
     */
    private function countContainersBySize(
        ShippingLineTerminalAllocation $allocation,
        \App\Entity\Enum\AllocationStatus $status,
        float $teuValue
    ): int {
        return $this->entityManager
            ->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->leftJoin('c.containerSize', 'cs')
            ->where('c.cyAllocation = :allocation')
            ->andWhere('c.allocationStatus = :status')
            ->andWhere('cs.teuValue = :teuValue')
            ->setParameter('allocation', $allocation)
            ->setParameter('status', $status)
            ->setParameter('teuValue', $teuValue)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
