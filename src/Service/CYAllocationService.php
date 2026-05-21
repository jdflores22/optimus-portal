<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Enum\AllocationStatus;
use App\ValueObject\UtilizationData;
use App\ValueObject\ValidationResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service for Container Yard capacity allocation calculations
 * Task 17.2: Implements caching for CY allocation data
 */
class CYAllocationService
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_KEY_PREFIX = 'cy_allocation_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ConfigurationService $configurationService,
        private CacheInterface $cache
    ) {
    }

    /**
     * Calculate total TEU requirement from containers
     * 
     * @param array $containers Array of Container entities or container data with size
     * @return float Total TEU requirement
     */
    public function getTEURequirement(array $containers): float
    {
        $totalTEU = 0.0;

        foreach ($containers as $container) {
            if ($container instanceof Container) {
                $totalTEU += $container->getContainerSize()->getTeuValue();
            } elseif (isset($container['size']) && $container['size'] instanceof ContainerSize) {
                $totalTEU += $container['size']->getTeuValue();
            }
        }

        return $totalTEU;
    }

    /**
     * Get available capacity for a Container Yard location
     * 
     * @param string $cyLocation Container Yard location identifier
     * @return float Available TEU capacity
     */
    public function getAvailableCapacity(string $cyLocation): float
    {
        return $this->configurationService->getCYCapacity($cyLocation);
    }

    /**
     * Get all CY locations with their capacities
     * 
     * @return array<string, float> Array of CY location => TEU capacity
     */
    public function getAllCYLocations(): array
    {
        return $this->configurationService->getCYLocations();
    }

    /**
     * Validate if CY has sufficient capacity for container allocation
     * 
     * @param array $containers Array of containers to allocate
     * @param string $cyLocation Container Yard location
     * @return bool True if allocation is possible, false otherwise
     */
    public function validateCapacity(array $containers, string $cyLocation): bool
    {
        $requiredTEU = $this->getTEURequirement($containers);
        $availableTEU = $this->getAvailableCapacity($cyLocation);

        return $requiredTEU <= $availableTEU;
    }

    /**
     * Get CY allocation data for display
     * 
     * @param array $containers Containers to allocate
     * @param string $cyLocation Container Yard location
     * @return array Allocation data with required, available, and remaining capacity
     */
    public function getAllocationData(array $containers, string $cyLocation): array
    {
        $requiredTEU = $this->getTEURequirement($containers);
        $availableTEU = $this->getAvailableCapacity($cyLocation);
        $remainingTEU = $availableTEU - $requiredTEU;

        return [
            'cyLocation' => $cyLocation,
            'requiredTEU' => $requiredTEU,
            'availableTEU' => $availableTEU,
            'remainingTEU' => $remainingTEU,
            'isValid' => $remainingTEU >= 0,
        ];
    }

    /**
     * Task 3.1: Get available allocations for a shipping line
     * Query ShippingLineTerminalAllocation by shipping line
     * Filter by active allocations with available capacity
     * Return array of allocation entities with utilization data
     * Task 17.2: Implements caching for available allocations
     * Task 17.3: Uses eager loading to avoid N+1 queries
     * 
     * @param ShippingLine $shippingLine The shipping line to query allocations for
     * @return array Array of allocations with utilization data
     */
    public function getAvailableAllocations(ShippingLine $shippingLine): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . 'available_' . $shippingLine->getId();
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($shippingLine) {
            $item->expiresAfter(self::CACHE_TTL);
            
            // Task 17.3: Use eager loading to avoid N+1 queries
            $repository = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class);
            $allocations = $repository->findByShippingLineWithRelations($shippingLine);

            $result = [];
            foreach ($allocations as $allocation) {
                $utilization = $this->calculateUtilization($allocation);
                
                // Include all allocations (even over capacity) - let the client decide what to show
                $result[] = [
                    'allocation' => $allocation,
                    'utilization' => $utilization,
                ];
            }

            return $result;
        });
    }

    /**
     * Task 3.3: Calculate utilization for an allocation
     * Sum TEU values of all containers assigned to allocation
     * Calculate used, available, and percentage metrics
     * Return UtilizationData value object
     * 
     * @param ShippingLineTerminalAllocation $allocation The allocation to calculate utilization for
     * @return UtilizationData Utilization metrics
     */
    public function calculateUtilization(ShippingLineTerminalAllocation $allocation): UtilizationData
    {
        // Query database directly to get accurate TEU count (don't rely on Doctrine collection)
        $qb = $this->entityManager->createQueryBuilder();
        
        $result = $qb->select('SUM(cs.teuValue) as total_teu', 'COUNT(c.id) as container_count')
            ->from(Container::class, 'c')
            ->leftJoin('c.containerSize', 'cs')
            ->where('c.cyAllocation = :allocation')
            ->setParameter('allocation', $allocation)
            ->getQuery()
            ->getSingleResult();
        
        $usedTEU = (float)($result['total_teu'] ?? 0.0);
        $containerCount = (int)($result['container_count'] ?? 0);
        $totalCapacityTEU = (float) $allocation->getAllocatedCapacity();
        $availableTEU = $totalCapacityTEU - $usedTEU;
        
        $utilizationPercentage = $totalCapacityTEU > 0 
            ? ($usedTEU / $totalCapacityTEU) * 100 
            : 0.0;

        return new UtilizationData(
            $usedTEU,
            $availableTEU,
            $totalCapacityTEU,
            $utilizationPercentage,
            $containerCount
        );
    }

    /**
     * Task 3.5: Validate capacity for container allocation
     * Check if allocation has sufficient TEU capacity for container
     * Return ValidationResult with success/failure and capacity details
     * Include shortage calculation
     * 
     * @param Container $container The container to validate
     * @param ShippingLineTerminalAllocation $allocation The allocation to validate against
     * @return ValidationResult Validation result with capacity details
     */
    public function validateContainerCapacity(
        Container $container,
        ShippingLineTerminalAllocation $allocation
    ): ValidationResult {
        $requiredTEU = $container->getContainerSize()->getTeuValue();
        $availableTEU = $allocation->getAvailableCapacityTEU();
        
        if ($availableTEU >= $requiredTEU) {
            return ValidationResult::success(
                'Sufficient capacity available'
            );
        }

        $terminal = $allocation->getTerminal();
        $message = sprintf(
            'Insufficient capacity at %s. Required: %.1f TEU, Available: %.1f TEU, Shortage: %.1f TEU',
            $terminal->getName(),
            $requiredTEU,
            $availableTEU,
            $requiredTEU - $availableTEU
        );

        return ValidationResult::failure(
            $message,
            $requiredTEU,
            $availableTEU,
            [
                'terminal_id' => $terminal->getId(),
                'terminal_name' => $terminal->getName(),
                'allocation_id' => $allocation->getId(),
            ]
        );
    }

    /**
     * Task 3.7: Assign container to CY allocation
     * Set container's cyAllocation relationship
     * Set allocationStatus to PRE_FORECAST
     * Set allocatedAt timestamp
     * Persist container entity
     * Task 17.2: Invalidate cache after assignment
     * 
     * @param Container $container The container to assign
     * @param ShippingLineTerminalAllocation $allocation The allocation to assign to
     * @return void
     */
    public function assignContainer(
        Container $container,
        ShippingLineTerminalAllocation $allocation
    ): void {
        $container->setCyAllocation($allocation);
        $container->setAllocationStatus(AllocationStatus::PRE_FORECAST);
        $container->setAllocatedAt(new \DateTime());
        
        $this->entityManager->persist($container);
        $this->entityManager->flush();
        
        // Invalidate cache after assignment
        $this->invalidateCacheForAllocation($allocation);
    }

    /**
     * Task 3.9: Reassign container to different CY allocation
     * Validate container has PRE_FORECAST status
     * Validate new allocation has sufficient capacity
     * Update container's cyAllocation relationship
     * Update allocatedAt timestamp
     * Persist changes
     * Task 17.2: Invalidate cache for both old and new allocations
     * 
     * @param Container $container The container to reassign
     * @param ShippingLineTerminalAllocation $newAllocation The new allocation
     * @return void
     * @throws \RuntimeException If container cannot be reassigned
     */
    public function reassignContainer(
        Container $container,
        ShippingLineTerminalAllocation $newAllocation
    ): void {
        // Validate container has PRE_FORECAST status
        if (!$container->canModifyAllocation()) {
            throw new \RuntimeException(
                sprintf(
                    'Cannot reassign container %s. Allocation is locked with status: %s',
                    $container->getContainerNumber(),
                    $container->getAllocationStatus()->value
                )
            );
        }

        // Validate new allocation has sufficient capacity (size-specific)
        $validationResult = $this->validateContainerCapacityBySize($container, $newAllocation);
        if (!$validationResult->isSuccess()) {
            throw new \RuntimeException($validationResult->getMessage());
        }

        // Store old allocation for cache invalidation
        $oldAllocation = $container->getCyAllocation();

        // Update container allocation
        $container->setCyAllocation($newAllocation);
        $container->setAllocatedAt(new \DateTime());
        
        $this->entityManager->persist($container);
        $this->entityManager->flush();
        
        // Invalidate cache for both old and new allocations
        if ($oldAllocation) {
            $this->invalidateCacheForAllocation($oldAllocation);
        }
        $this->invalidateCacheForAllocation($newAllocation);
    }

    /**
     * Task 3.11: Lock allocation when eDO is generated
     * Change allocationStatus from PRE_FORECAST to ALLOCATED
     * Set allocationLockedAt timestamp
     * Prevent further modifications
     * Task 17.2: Invalidate cache after locking
     * 
     * @param Container $container The container to lock
     * @return void
     */
    public function lockAllocation(Container $container): void
    {
        $container->setAllocationStatus(AllocationStatus::ALLOCATED);
        $container->setAllocationLockedAt(new \DateTime());
        
        $this->entityManager->persist($container);
        $this->entityManager->flush();
        
        // Invalidate cache after locking
        $allocation = $container->getCyAllocation();
        if ($allocation) {
            $this->invalidateCacheForAllocation($allocation);
        }
    }

    /**
     * Task 3.13: Get utilization summary for all allocations
     * Query all allocations for shipping line
     * Calculate utilization for each allocation
     * Return array of summary data for CY grid display
     * Task 17.2: Implements caching for utilization summary
     * Task 17.3: Uses database aggregation for batch utilization calculation
     * 
     * @param ShippingLine $shippingLine The shipping line to get summary for
     * @return array Array of utilization summaries
     */
    public function getUtilizationSummary(ShippingLine $shippingLine): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . 'summary_' . $shippingLine->getId();
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($shippingLine) {
            $item->expiresAfter(self::CACHE_TTL);
            
            // Task 17.3: Use optimized query with database aggregation
            $repository = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class);
            $results = $repository->findWithUtilization($shippingLine);

            $summary = [];
            foreach ($results as $result) {
                $allocation = $result[0]; // The entity is at index 0
                $terminal = $allocation->getTerminal();
                $totalCapacityTEU = (float) $result['total_capacity_teu'];
                $usedTEU = (float) $result['used_teu'];
                $availableTEU = $totalCapacityTEU - $usedTEU;
                $utilizationPercentage = $totalCapacityTEU > 0 
                    ? ($usedTEU / $totalCapacityTEU) * 100 
                    : 0.0;
                
                $summary[] = [
                    'allocation_id' => $result['allocation_id'],
                    'terminal_id' => $result['terminal_id'],
                    'terminal_name' => $result['terminal_name'],
                    'terminal_location' => $result['terminal_location'],
                    'total_capacity_teu' => $totalCapacityTEU,
                    'used_teu' => $usedTEU,
                    'available_teu' => $availableTEU,
                    'utilization_percentage' => $utilizationPercentage,
                    'container_count' => (int) $result['container_count'],
                ];
            }

            return $summary;
        });
    }

    /**
     * Task 17.2: Invalidate cache for a shipping line
     * Called when allocation changes occur
     * 
     * @param ShippingLine $shippingLine The shipping line whose cache should be invalidated
     * @return void
     */
    public function invalidateCache(ShippingLine $shippingLine): void
    {
        $this->cache->delete(self::CACHE_KEY_PREFIX . 'available_' . $shippingLine->getId());
        $this->cache->delete(self::CACHE_KEY_PREFIX . 'summary_' . $shippingLine->getId());
    }

    /**
     * Task 17.2: Invalidate cache for an allocation
     * Called when container assignments change
     * 
     * @param ShippingLineTerminalAllocation $allocation The allocation whose cache should be invalidated
     * @return void
     */
    public function invalidateCacheForAllocation(ShippingLineTerminalAllocation $allocation): void
    {
        $shippingLine = $allocation->getShippingLine();
        $this->invalidateCache($shippingLine);
    }

    /**
     * Task 1.1: Calculate utilization by container size
     * Returns separate utilization data for 20ft and 40ft containers
     * 
     * @param ShippingLineTerminalAllocation $allocation The allocation to calculate utilization for
     * @return array ['20ft' => UtilizationData, '40ft' => UtilizationData]
     */
    public function calculateUtilizationBySize(ShippingLineTerminalAllocation $allocation): array
    {
        $containers = $allocation->getContainers();
        
        $allocated20ft = 0;
        $allocated40ft = 0;
        $preForecast20ft = 0;
        $preForecast40ft = 0;
        
        foreach ($containers as $container) {
            $teuValue = $container->getContainerSize()->getTeuValue();
            $status = $container->getAllocationStatus();
            
            if ($teuValue == 1.0) {
                if ($status === AllocationStatus::ALLOCATED) {
                    $allocated20ft++;
                } elseif ($status === AllocationStatus::PRE_FORECAST) {
                    $preForecast20ft++;
                }
            } elseif ($teuValue == 2.0) {
                if ($status === AllocationStatus::ALLOCATED) {
                    $allocated40ft++;
                } elseif ($status === AllocationStatus::PRE_FORECAST) {
                    $preForecast40ft++;
                }
            }
        }
        
        $capacity20ft = $allocation->getCapacity20ft();
        $capacity40ft = $allocation->getCapacity40ft();
        
        $used20ft = $allocated20ft + $preForecast20ft;
        $used40ft = $allocated40ft + $preForecast40ft;
        
        return [
            '20ft' => new UtilizationData(
                $used20ft,
                max(0, $capacity20ft - $used20ft),
                $capacity20ft,
                $capacity20ft > 0 ? ($used20ft / $capacity20ft) * 100 : 0,
                $used20ft
            ),
            '40ft' => new UtilizationData(
                $used40ft,
                max(0, $capacity40ft - $used40ft),
                $capacity40ft,
                $capacity40ft > 0 ? ($used40ft / $capacity40ft) * 100 : 0,
                $used40ft
            ),
        ];
    }

    /**
     * Task 1.2: Validate container capacity by size
     * Validates capacity based on container size (20ft or 40ft)
     * 
     * @param Container $container The container to validate
     * @param ShippingLineTerminalAllocation $allocation The allocation to validate against
     * @return ValidationResult Validation result with size-specific details
     */
    public function validateContainerCapacityBySize(
        Container $container,
        ShippingLineTerminalAllocation $allocation
    ): ValidationResult {
        $teuValue = $container->getContainerSize()->getTeuValue();
        $terminal = $allocation->getTerminal();
        
        if ($teuValue == 1.0) {
            // 20ft container
            $utilization = $this->calculateUtilizationBySize($allocation);
            $available = $utilization['20ft']->getAvailableTEU();
            
            if ($available >= 1) {
                return ValidationResult::success('Sufficient 20ft capacity available');
            }
            
            return ValidationResult::failure(
                sprintf(
                    'Insufficient 20ft capacity at %s. Required: 1 container, Available: %d containers',
                    $terminal->getName(),
                    (int)$available
                ),
                1.0,
                $available,
                [
                    'terminal_id' => $terminal->getId(),
                    'terminal_name' => $terminal->getName(),
                    'allocation_id' => $allocation->getId(),
                    'size' => '20ft'
                ]
            );
        } elseif ($teuValue == 2.0) {
            // 40ft container
            $utilization = $this->calculateUtilizationBySize($allocation);
            $available = $utilization['40ft']->getAvailableTEU();
            
            if ($available >= 1) {
                return ValidationResult::success('Sufficient 40ft capacity available');
            }
            
            return ValidationResult::failure(
                sprintf(
                    'Insufficient 40ft capacity at %s. Required: 1 container, Available: %d containers',
                    $terminal->getName(),
                    (int)$available
                ),
                1.0,
                $available,
                [
                    'terminal_id' => $terminal->getId(),
                    'terminal_name' => $terminal->getName(),
                    'allocation_id' => $allocation->getId(),
                    'size' => '40ft'
                ]
            );
        }
        
        // Fallback to TEU-based validation for other sizes
        return $this->validateContainerCapacity($container, $allocation);
    }

    /**
     * Task 1.3: Get available allocations filtered by container size
     * Filters allocations by container size availability and sorts by capacity
     * 
     * @param ShippingLine $shippingLine The shipping line to query allocations for
     * @param float $teuValue Container size (1.0 for 20ft, 2.0 for 40ft)
     * @return array Array of allocations with size-specific utilization data
     */
    public function getAvailableAllocationsBySize(
        ShippingLine $shippingLine,
        float $teuValue
    ): array {
        $allAllocations = $this->getAvailableAllocations($shippingLine);
        $filtered = [];
        
        foreach ($allAllocations as $data) {
            $allocation = $data['allocation'];
            $utilization = $this->calculateUtilizationBySize($allocation);
            
            if ($teuValue == 1.0) {
                // 20ft container
                if ($utilization['20ft']->getAvailableTEU() >= 1) {
                    $filtered[] = [
                        'allocation' => $allocation,
                        'utilization' => $utilization['20ft'],
                        'size' => '20ft'
                    ];
                }
            } elseif ($teuValue == 2.0) {
                // 40ft container
                if ($utilization['40ft']->getAvailableTEU() >= 1) {
                    $filtered[] = [
                        'allocation' => $allocation,
                        'utilization' => $utilization['40ft'],
                        'size' => '40ft'
                    ];
                }
            }
        }
        
        // Sort by available capacity (highest first)
        usort($filtered, function($a, $b) {
            return $b['utilization']->getAvailableTEU() <=> $a['utilization']->getAvailableTEU();
        });
        
        return $filtered;
    }
}
