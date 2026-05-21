<?php

namespace App\Service;

use App\ValueObject\Container;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing container data operations
 * 
 * This service provides container data for the detailed stack view.
 * It's structured to facilitate future API integration by maintaining
 * a consistent data format and separation of concerns.
 */
class ContainerDataService implements ContainerDataInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }
    /**
     * Get container data for a specific depot
     * 
     * @param string|null $depotId Optional depot identifier for depot-specific data
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param int $page Page number for pagination (1-indexed)
     * @param int $itemsPerPage Number of items per page
     * @param string|null $containerNumber Optional container number to filter by
     * @return Container[] Array of Container value objects
     */
    public function getContainerData(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, int $page = 1, int $itemsPerPage = 20, ?string $containerNumber = null): array
    {
        $sampleData = $this->getSampleContainerData($depotId, $shippingLine, $page, $itemsPerPage, $containerNumber);
        $containers = [];
        
        foreach ($sampleData as $data) {
            $containers[] = Container::fromArray($data);
        }
        
        return $containers;
    }

    /**
     * Get container data formatted for API responses
     * 
     * @param string|null $depotId Optional depot identifier for depot-specific data
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param int $page Page number for pagination (1-indexed)
     * @param int $itemsPerPage Number of items per page
     * @param string|null $containerNumber Optional container number to filter by
     * @return array Array of container data in JSON-serializable format
     */
    public function getFormattedContainerData(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, int $page = 1, int $itemsPerPage = 20, ?string $containerNumber = null): array
    {
        $containers = $this->getContainerData($depotId, $shippingLine, $page, $itemsPerPage, $containerNumber);
        $formattedData = [];
        
        foreach ($containers as $container) {
            $formattedData[] = $container->jsonSerialize();
        }
        
        return $formattedData;
    }

    /**
     * Get total count of containers matching the criteria
     * 
     * @param string|null $depotId Optional depot identifier for depot-specific data
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param string|null $containerNumber Optional container number to filter by
     * @return int Total number of containers
     */
    public function getContainerCount(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, ?string $containerNumber = null): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(c.id)')
            ->from(\App\Entity\Container::class, 'c')
            ->leftJoin('c.cyAllocation', 'allocation')
            ->leftJoin('allocation.terminal', 'terminal')
            ->where('c.allocationStatus IN (:allocationStatuses)')
            ->setParameter('allocationStatuses', [
                \App\Entity\Enum\AllocationStatus::ALLOCATED,
                \App\Entity\Enum\AllocationStatus::PRE_FORECAST
            ]);
        
        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->andWhere('c.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        // Filter by depot/terminal name if provided
        if ($depotId !== null && $depotId !== '') {
            $qb->andWhere('terminal.name LIKE :depot')
               ->setParameter('depot', '%' . $depotId . '%');
        }
        
        // Filter by container number if provided
        if ($containerNumber !== null && $containerNumber !== '') {
            $qb->andWhere('c.containerNumber = :containerNumber')
               ->setParameter('containerNumber', $containerNumber);
        }
        
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Calculate total TEU count for all containers matching the query
     * 
     * @param string|null $depotId Optional depot identifier for depot-specific data
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param string|null $containerNumber Optional container number to filter by
     * @return int Total TEU count
     */
    public function calculateTotalTEUForQuery(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, ?string $containerNumber = null): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Entity\Container::class, 'c')
            ->leftJoin('c.cyAllocation', 'allocation')
            ->leftJoin('allocation.terminal', 'terminal')
            ->where('c.allocationStatus IN (:allocationStatuses)')
            ->setParameter('allocationStatuses', [
                \App\Entity\Enum\AllocationStatus::ALLOCATED,
                \App\Entity\Enum\AllocationStatus::PRE_FORECAST
            ]);
        
        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->andWhere('c.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        // Filter by depot/terminal name if provided
        if ($depotId !== null && $depotId !== '') {
            $qb->andWhere('terminal.name LIKE :depot')
               ->setParameter('depot', '%' . $depotId . '%');
        }
        
        // Filter by container number if provided
        if ($containerNumber !== null && $containerNumber !== '') {
            $qb->andWhere('c.containerNumber = :containerNumber')
               ->setParameter('containerNumber', $containerNumber);
        }
        
        $containers = $qb->getQuery()->getResult();
        
        $totalTEU = 0;
        foreach ($containers as $container) {
            // 20ft = 1 TEU, 40ft = 2 TEU
            $size = $container->getContainerSize()->getCode();
            $totalTEU += (strpos($size, '20') !== false) ? 1 : 2;
        }
        
        return $totalTEU;
    }

    /**
     * Get container data from database with dwell time information
     * 
     * This method fetches real container data from the database including
     * dwell time fields and formats it for display in the detailed stack view.
     * 
     * @param string|null $depotId Optional depot identifier for depot-specific data
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param int $page Page number for pagination (1-indexed)
     * @param int $itemsPerPage Number of items per page
     * @param string|null $containerNumber Optional container number to filter by
     * @return array Array of container data with consistent schema
     */
    public function getSampleContainerData(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, int $page = 1, int $itemsPerPage = 20, ?string $containerNumber = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Entity\Container::class, 'c')
            ->leftJoin('c.cyAllocation', 'allocation')
            ->leftJoin('allocation.terminal', 'terminal')
            ->leftJoin('allocation.shippingLine', 'allocationSL')
            ->leftJoin('c.shippingLine', 'sl')
            ->where('c.allocationStatus IN (:allocationStatuses)')
            ->setParameter('allocationStatuses', [
                \App\Entity\Enum\AllocationStatus::ALLOCATED,
                \App\Entity\Enum\AllocationStatus::PRE_FORECAST
            ])
            ->orderBy('c.allocationStatus', 'ASC')
            ->addOrderBy('c.currentDwellTime', 'DESC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);
        
        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->andWhere('c.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        // Filter by depot/terminal name if provided
        if ($depotId !== null && $depotId !== '') {
            $qb->andWhere('terminal.name LIKE :depot')
               ->setParameter('depot', '%' . $depotId . '%');
        }
        
        // Filter by container number if provided
        if ($containerNumber !== null && $containerNumber !== '') {
            $qb->andWhere('c.containerNumber = :containerNumber')
               ->setParameter('containerNumber', $containerNumber);
        }
        
        $containers = $qb->getQuery()->getResult();
        
        $containerData = [];
        foreach ($containers as $container) {
            // Use terminal arrival date if available, otherwise use created_at
            $gateInDate = $container->getTerminalArrivalDate() ?? $container->getCreatedAt();
            
            // Calculate dwell time if not set
            $dwellTime = $container->getCurrentDwellTime();
            if ($dwellTime === null || $dwellTime === 0) {
                // Calculate dwell time from gate-in date to now
                $now = new \DateTime();
                $interval = $gateInDate->diff($now);
                $dwellTime = $interval->days;
                
                // Subtract paused days if any
                $pausedDays = $container->getTotalPausedDays() ?? 0;
                $dwellTime = max(0, $dwellTime - $pausedDays);
            }
            
            // For allocated containers, use the terminal name from cy_allocation
            $location = 'Unknown';
            if ($container->getCyAllocation() !== null 
                && $container->getCyAllocation()->getTerminal() !== null) {
                $location = $container->getCyAllocation()->getTerminal()->getName();
            } elseif ($container->getCurrentLocation() !== null) {
                $location = $container->getCurrentLocation();
            }
            
            // Map allocation status to display status
            $displayStatus = match($container->getAllocationStatus()) {
                \App\Entity\Enum\AllocationStatus::ALLOCATED => 'Available',
                \App\Entity\Enum\AllocationStatus::PRE_FORECAST => 'Pre-Forecast',
                \App\Entity\Enum\AllocationStatus::RELEASED => 'Released',
                default => 'Unknown'
            };
            
            // Get shipping line - check container first, then cyAllocation
            $shippingLineName = null;
            if ($container->getShippingLine() !== null) {
                $shippingLineName = $container->getShippingLine()->getBrandName();
            } elseif ($container->getCyAllocation() !== null 
                      && $container->getCyAllocation()->getShippingLine() !== null) {
                $shippingLineName = $container->getCyAllocation()->getShippingLine()->getBrandName();
            }
            
            $containerData[] = [
                'containerNumber' => $container->getContainerNumber(),
                'sizeType' => $this->formatSizeType($container->getContainerSize()->getCode(), $container->getContainerType()->getCode()),
                'gateInDate' => $gateInDate,
                'dwellTime' => $dwellTime,
                'condition' => $this->mapStatusToCondition($container->getStatus()),
                'status' => $displayStatus,
                'location' => $location,
                'totalPausedDays' => $container->getTotalPausedDays(),
                'dwellTimePausedAt' => $container->getDwellTimePausedAt(),
                'nextNotificationDate' => $container->getNextNotificationDate(),
                'automaticReturnDate' => $container->getAutomaticReturnDate(),
                'allocationStatus' => $container->getAllocationStatus()->value,
                'shippingLine' => $shippingLineName
            ];
        }
        
        return $containerData;
    }
    
    /**
     * Format container size and type for display
     */
    private function formatSizeType(string $size, string $type): string
    {
        return $size . ' ' . $type;
    }
    
    /**
     * Map container status enum to display condition
     */
    private function mapStatusToCondition(\App\Entity\Enum\ContainerStatus $status): string
    {
        return match($status) {
            \App\Entity\Enum\ContainerStatus::AVAILABLE_FOR_RETURN => 'Good',
            \App\Entity\Enum\ContainerStatus::PA_APPROVED => 'Good',
            \App\Entity\Enum\ContainerStatus::IN_TRANSIT => 'Good',
            \App\Entity\Enum\ContainerStatus::AT_TERMINAL => 'Good',
            \App\Entity\Enum\ContainerStatus::RETURNED => 'Good',
            \App\Entity\Enum\ContainerStatus::ALERT => 'Fair',
            \App\Entity\Enum\ContainerStatus::MAINTENANCE => 'Damaged',
            default => 'Good'
        };
    }
    
    /**
     * Map container status enum to display status
     */
    private function mapContainerStatus(\App\Entity\Enum\ContainerStatus $status): string
    {
        return match($status) {
            \App\Entity\Enum\ContainerStatus::AVAILABLE_FOR_RETURN => 'Available',
            \App\Entity\Enum\ContainerStatus::PA_APPROVED => 'Reserved',
            \App\Entity\Enum\ContainerStatus::IN_TRANSIT => 'In Transit',
            \App\Entity\Enum\ContainerStatus::AT_TERMINAL => 'Available',
            \App\Entity\Enum\ContainerStatus::RETURNED => 'Available',
            \App\Entity\Enum\ContainerStatus::ALERT => 'Hold',
            \App\Entity\Enum\ContainerStatus::MAINTENANCE => 'Maintenance',
            default => 'Available'
        };
    }

    /**
     * Calculate total TEU (Twenty-foot Equivalent Unit) count from container data
     * 
     * @param array $containers Array of container data or Container objects
     * @return int Total TEU count
     */
    public function calculateTotalTEU(array $containers): int
    {
        $totalTEU = 0;
        
        foreach ($containers as $container) {
            if ($container instanceof Container) {
                $totalTEU += $container->getTeuCount();
            } else {
                // Backward compatibility with array format
                if (strpos($container['sizeType'], '20ft') !== false) {
                    $totalTEU += 1; // 20ft = 1 TEU
                } else {
                    $totalTEU += 2; // 40ft = 2 TEU
                }
            }
        }
        
        return $totalTEU;
    }

    /**
     * Get depot name mapping for display purposes
     * 
     * @return array Associative array of depot ID to full name mappings
     */
    public function getDepotNames(): array
    {
        return [
            'MICT' => 'Manila International Container Terminal',
            'SBTC' => 'South Bay Terminal Corporation',
            'ICTSI' => 'International Container Terminal Services',
            'SBFZ' => 'Subic Bay Freeport Zone Terminal',
            'CICT' => 'Cebu International Container Terminal',
            'DPCT' => 'Davao Port Container Terminal',
            'IICT' => 'Iloilo International Container Terminal',
            'COCT' => 'Cagayan de Oro Container Terminal'
        ];
    }

    /**
     * Get full depot name by depot ID
     * 
     * @param string $depotId The depot identifier
     * @return string Full depot name or the depot ID if not found
     */
    public function getDepotFullName(string $depotId): string
    {
        $depotNames = $this->getDepotNames();
        return $depotNames[$depotId] ?? $depotId;
    }

    /**
     * Prepare container data for API-compatible format
     * 
     * This method ensures the data structure is consistent and ready
     * for future API integration by standardizing the format.
     * 
     * @param array $containers Raw container data
     * @return array Formatted container data suitable for JSON API responses
     */
    public function formatContainerDataForApi(array $containers): array
    {
        $formattedData = [];
        
        foreach ($containers as $container) {
            $formattedData[] = [
                'containerNumber' => $container['containerNumber'],
                'sizeType' => $container['sizeType'],
                'gateInDate' => $container['gateInDate']->format('Y-m-d'),
                'dwellTime' => $container['dwellTime'],
                'condition' => $container['condition'],
                'status' => $container['status'],
                'location' => $container['location']
            ];
        }
        
        return $formattedData;
    }

    /**
     * Filter containers by status
     * 
     * @param Container[] $containers Array of Container objects
     * @param string $status Status to filter by
     * @return Container[] Filtered array of Container objects
     */
    public function filterContainersByStatus(array $containers, string $status): array
    {
        return array_filter($containers, function (Container $container) use ($status) {
            return $container->getStatus() === $status;
        });
    }

    /**
     * Filter containers by condition
     * 
     * @param Container[] $containers Array of Container objects
     * @param string $condition Condition to filter by
     * @return Container[] Filtered array of Container objects
     */
    public function filterContainersByCondition(array $containers, string $condition): array
    {
        return array_filter($containers, function (Container $container) use ($condition) {
            return $container->getCondition() === $condition;
        });
    }

    /**
     * Get containers with dwell time exceeding specified days
     * 
     * @param Container[] $containers Array of Container objects
     * @param int $days Minimum dwell time in days
     * @return Container[] Filtered array of Container objects
     */
    public function getContainersWithHighDwellTime(array $containers, int $days): array
    {
        return array_filter($containers, function (Container $container) use ($days) {
            return $container->getDwellTime() > $days;
        });
    }

    /**
     * Get detailed container information by container number
     * 
     * @param string $containerNumber The container number to look up
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @return array|null Container details or null if not found
     */
    public function getContainerDetailByNumber(string $containerNumber, ?\App\Entity\ShippingLine $shippingLine = null): ?array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Entity\Container::class, 'c')
            ->leftJoin('c.cyAllocation', 'allocation')
            ->leftJoin('allocation.terminal', 'terminal')
            ->where('c.containerNumber = :containerNumber')
            ->setParameter('containerNumber', $containerNumber);
        
        // Filter by shipping line if provided (security check)
        if ($shippingLine !== null) {
            $qb->andWhere('c.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        $container = $qb->getQuery()->getOneOrNullResult();
        
        if (!$container) {
            return null;
        }
        
        // Get location
        $location = 'Unknown';
        if ($container->getCyAllocation() !== null 
            && $container->getCyAllocation()->getTerminal() !== null) {
            $location = $container->getCyAllocation()->getTerminal()->getName();
        } elseif ($container->getCurrentLocation() !== null) {
            $location = $container->getCurrentLocation();
        }
        
        // Map allocation status to display status
        $displayStatus = match($container->getAllocationStatus()) {
            \App\Entity\Enum\AllocationStatus::ALLOCATED => 'Available',
            \App\Entity\Enum\AllocationStatus::PRE_FORECAST => 'Pre-Forecast',
            \App\Entity\Enum\AllocationStatus::RELEASED => 'Released',
            default => 'Unknown'
        };
        
        // Use terminal arrival date if available, otherwise use created_at
        $gateInDate = $container->getTerminalArrivalDate() ?? $container->getCreatedAt();
        
        // Build comprehensive container details
        return [
            'basicInfo' => [
                'containerNumber' => $container->getContainerNumber(),
                'sizeType' => $this->formatSizeType($container->getContainerSize()->getCode(), $container->getContainerType()->getCode()),
                'teuCount' => (strpos($container->getContainerSize()->getCode(), '20') !== false) ? 1 : 2,
                'location' => $location,
                'gateInDate' => $gateInDate,
                'dwellTime' => $container->getCurrentDwellTime() ?? 0,
                'condition' => $this->mapStatusToCondition($container->getStatus()),
                'status' => $displayStatus,
            ],
            'specifications' => [
                'manufacturer' => 'N/A', // Add if available in entity
                'yearBuilt' => 'N/A', // Add if available in entity
                'isoCode' => $container->getContainerSize()->getCode() . $container->getContainerType()->getCode(),
                'cscPlate' => 'Valid', // Add if available in entity
                'maxGrossWeight' => 'N/A', // Add if available in entity
                'tareWeight' => 'N/A', // Add if available in entity
                'maxPayload' => 'N/A', // Add if available in entity
                'dimensions' => [
                    'length' => strpos($container->getContainerSize()->getCode(), '20') !== false ? '20ft' : '40ft',
                    'width' => '8ft',
                    'height' => '8.6ft',
                ],
            ],
            'movement' => [
                'lastMovement' => $gateInDate->format('M j, Y H:i'),
                'movementType' => 'Gate In',
                'fromLocation' => 'Port',
                'toLocation' => $location,
                'operator' => 'N/A',
                'equipment' => 'N/A',
                'remarks' => 'Container received at terminal',
            ],
            'documentation' => [
                'billOfLading' => 'N/A',
                'manifest' => 'N/A',
                'customsDeclaration' => 'N/A',
                'deliveryOrder' => null,
                'gatePass' => 'N/A',
            ],
            'charges' => [
                'storageCharges' => 0,
                'handlingCharges' => 0,
                'documentationFee' => 0,
            ],
            'history' => [
                [
                    'date' => $gateInDate->format('M j, Y H:i'),
                    'type' => 'Gate In',
                    'fromLocation' => 'Port',
                    'toLocation' => $location,
                    'operator' => 'System',
                    'equipment' => 'N/A',
                    'remarks' => 'Container received at terminal',
                ],
            ],
            'inspections' => [
                [
                    'date' => $gateInDate->format('M j, Y'),
                    'type' => 'Visual Inspection',
                    'inspector' => 'Terminal Staff',
                    'result' => 'Pass',
                    'photos' => '0',
                    'remarks' => 'No visible damage',
                ],
            ],
        ];
    }

    /**
     * Get detailed statistics for containers
     * 
     * @param string|null $depotId Optional depot identifier
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param string|null $containerNumber Optional container number to filter by
     * @return array Statistics including counts by size, status, and allocation status
     */
    public function getDetailedStats(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, ?string $containerNumber = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Entity\Container::class, 'c')
            ->leftJoin('c.cyAllocation', 'allocation')
            ->leftJoin('allocation.terminal', 'terminal')
            ->leftJoin('c.containerSize', 'cs')
            ->addSelect('cs')
            ->where('c.allocationStatus IN (:allocationStatuses)')
            ->setParameter('allocationStatuses', [
                \App\Entity\Enum\AllocationStatus::ALLOCATED,
                \App\Entity\Enum\AllocationStatus::PRE_FORECAST
            ]);
        
        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->andWhere('c.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        // Filter by depot/terminal name if provided
        if ($depotId !== null && $depotId !== '') {
            $qb->andWhere('terminal.name LIKE :depot')
               ->setParameter('depot', '%' . $depotId . '%');
        }
        
        // Filter by container number if provided
        if ($containerNumber !== null && $containerNumber !== '') {
            $qb->andWhere('c.containerNumber = :containerNumber')
               ->setParameter('containerNumber', $containerNumber);
        }
        
        $containers = $qb->getQuery()->getResult();
        
        $stats = [
            'total_20ft' => 0,
            'total_40ft' => 0,
            'allocated_20ft' => 0,
            'allocated_40ft' => 0,
            'pre_forecast_20ft' => 0,
            'pre_forecast_40ft' => 0,
            'high_dwell_time' => 0,
        ];
        
        foreach ($containers as $container) {
            $size = $container->getContainerSize()->getCode();
            $is20ft = strpos($size, '20') !== false;
            $allocationStatus = $container->getAllocationStatus();
            $dwellTime = $container->getCurrentDwellTime() ?? 0;
            
            // Count by size
            if ($is20ft) {
                $stats['total_20ft']++;
                if ($allocationStatus === \App\Entity\Enum\AllocationStatus::ALLOCATED) {
                    $stats['allocated_20ft']++;
                } elseif ($allocationStatus === \App\Entity\Enum\AllocationStatus::PRE_FORECAST) {
                    $stats['pre_forecast_20ft']++;
                }
            } else {
                $stats['total_40ft']++;
                if ($allocationStatus === \App\Entity\Enum\AllocationStatus::ALLOCATED) {
                    $stats['allocated_40ft']++;
                } elseif ($allocationStatus === \App\Entity\Enum\AllocationStatus::PRE_FORECAST) {
                    $stats['pre_forecast_40ft']++;
                }
            }
            
            // Count high dwell time
            if ($dwellTime > 60) {
                $stats['high_dwell_time']++;
            }
        }
        
        $stats['total_allocated'] = $stats['allocated_20ft'] + $stats['allocated_40ft'];
        $stats['total_pre_forecast'] = $stats['pre_forecast_20ft'] + $stats['pre_forecast_40ft'];
        
        return $stats;
    }

    /**
     * Get container counts by terminal identity
     * 
     * @param string|null $depotId Optional depot identifier
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param string|null $containerNumber Optional container number to filter by
     * @return array Statistics by terminal identity (TERMINAL vs CONTAINER_YARD)
     */
    public function getStatsByTerminalIdentity(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, ?string $containerNumber = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Entity\Container::class, 'c')
            ->leftJoin('c.cyAllocation', 'allocation')
            ->leftJoin('allocation.terminal', 'terminal')
            ->where('c.allocationStatus IN (:allocationStatuses)')
            ->setParameter('allocationStatuses', [
                \App\Entity\Enum\AllocationStatus::ALLOCATED,
                \App\Entity\Enum\AllocationStatus::PRE_FORECAST
            ]);
        
        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->andWhere('c.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        // Filter by depot/terminal name if provided
        if ($depotId !== null && $depotId !== '') {
            $qb->andWhere('terminal.name LIKE :depot')
               ->setParameter('depot', '%' . $depotId . '%');
        }
        
        // Filter by container number if provided
        if ($containerNumber !== null && $containerNumber !== '') {
            $qb->andWhere('c.containerNumber = :containerNumber')
               ->setParameter('containerNumber', $containerNumber);
        }
        
        $containers = $qb->getQuery()->getResult();
        
        $stats = [
            'terminal_count' => 0,
            'container_yard_count' => 0,
        ];
        
        foreach ($containers as $container) {
            $terminal = $container->getCyAllocation()?->getTerminal();
            if ($terminal) {
                $identity = $terminal->getIdentity();
                if ($identity === \App\Entity\Enum\TerminalIdentity::TERMINAL) {
                    $stats['terminal_count']++;
                } elseif ($identity === \App\Entity\Enum\TerminalIdentity::CONTAINER_YARD) {
                    $stats['container_yard_count']++;
                }
            }
        }
        
        return $stats;
    }

    /**
     * Get total capacity by terminal identity
     * 
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @return array Capacity statistics by terminal identity
     */
    public function getCapacityByTerminalIdentity(?\App\Entity\ShippingLine $shippingLine = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
            ->from(\App\Entity\ShippingLineTerminalAllocation::class, 'a')
            ->join('a.terminal', 't');
        
        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->andWhere('a.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        $allocations = $qb->getQuery()->getResult();
        
        $capacityStats = [
            'terminal_capacity_20ft' => 0,
            'terminal_capacity_40ft' => 0,
            'container_yard_capacity_20ft' => 0,
            'container_yard_capacity_40ft' => 0,
        ];
        
        foreach ($allocations as $allocation) {
            $terminal = $allocation->getTerminal();
            $identity = $terminal->getIdentity();
            
            if ($identity === \App\Entity\Enum\TerminalIdentity::TERMINAL) {
                $capacityStats['terminal_capacity_20ft'] += $allocation->getCapacity20ft();
                $capacityStats['terminal_capacity_40ft'] += $allocation->getCapacity40ft();
            } elseif ($identity === \App\Entity\Enum\TerminalIdentity::CONTAINER_YARD) {
                $capacityStats['container_yard_capacity_20ft'] += $allocation->getCapacity20ft();
                $capacityStats['container_yard_capacity_40ft'] += $allocation->getCapacity40ft();
            }
        }
        
        // Calculate total TEU capacity
        $capacityStats['terminal_capacity_teu'] = $capacityStats['terminal_capacity_20ft'] + ($capacityStats['terminal_capacity_40ft'] * 2);
        $capacityStats['container_yard_capacity_teu'] = $capacityStats['container_yard_capacity_20ft'] + ($capacityStats['container_yard_capacity_40ft'] * 2);
        
        return $capacityStats;
    }

    public function getTerminalCapacity(string $depotId, \App\Entity\StaffUser $user): array
    {
        // Get terminal allocation for this user and depot
        $allocation = $this->entityManager->getRepository(\App\Entity\ShippingLineTerminalAllocation::class)
            ->createQueryBuilder('a')
            ->join('a.terminal', 't')
            ->where('a.staffUser = :user')
            ->andWhere('t.name LIKE :depotName')
            ->setParameter('user', $user)
            ->setParameter('depotName', '%' . $depotId . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$allocation) {
            return [
                'capacity_20ft' => 0,
                'capacity_40ft' => 0,
                'total_capacity_teu' => 0,
            ];
        }

        return [
            'capacity_20ft' => $allocation->getCapacity20ft(),
            'capacity_40ft' => $allocation->getCapacity40ft(),
            'total_capacity_teu' => $allocation->getCapacity20ft() + ($allocation->getCapacity40ft() * 2),
        ];
    }
}
