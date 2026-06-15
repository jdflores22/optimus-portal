<?php

namespace App\Repository;

use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Enum\TerminalType;
use App\Entity\ShippingLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for ShippingLineTerminalAllocation entity
 * Task 17.3: Optimized queries for utilization calculations
 */
class ShippingLineTerminalAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingLineTerminalAllocation::class);
    }

    /**
     * Task 17.3: Get allocations with eager-loaded relationships
     * Avoids N+1 queries by loading terminal and shipping line in one query
     * 
     * @param ShippingLine $shippingLine
     * @return array
     */
    public function findByShippingLineWithRelations(ShippingLine $shippingLine): array
    {
        return $this->findCyAllocationsByShippingLine($shippingLine);
    }

    /**
     * CY-type allocations only — excludes port/terminals (ATI, ICTSI).
     */
    public function findCyAllocationsByShippingLine(ShippingLine $shippingLine): array
    {
        return $this->createQueryBuilder('slta')
            ->select('slta', 't', 'sl')
            ->leftJoin('slta.terminal', 't')
            ->leftJoin('slta.shippingLine', 'sl')
            ->where('slta.shippingLine = :shippingLine')
            ->andWhere('t.type = :cyType')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('cyType', TerminalType::CY)
            ->getQuery()
            ->getResult();
    }

    /**
     * Task 17.3: Calculate utilization using database aggregation
     * Returns allocation ID => total TEU mapping
     * Avoids loading all containers into memory
     * 
     * @param array $allocationIds Array of allocation IDs
     * @return array Array mapping allocation_id => total_teu
     */
    public function calculateUtilizationBatch(array $allocationIds): array
    {
        if (empty($allocationIds)) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder();
        
        $results = $qb->select('IDENTITY(c.cyAllocation) as allocation_id')
            ->addSelect('SUM(
                CASE 
                    WHEN cs.size = \'20ft\' THEN 1.0
                    WHEN cs.size = \'40ft\' THEN 2.0
                    WHEN cs.size = \'45ft\' THEN 2.5
                    ELSE 1.0
                END
            ) as total_teu')
            ->from('App\Entity\Container', 'c')
            ->leftJoin('c.containerSize', 'cs')
            ->where('c.cyAllocation IN (:allocationIds)')
            ->andWhere('c.cyAllocation IS NOT NULL')
            ->groupBy('c.cyAllocation')
            ->setParameter('allocationIds', $allocationIds)
            ->getQuery()
            ->getResult();

        $utilizationMap = [];
        foreach ($results as $result) {
            $utilizationMap[(int)$result['allocation_id']] = (float)$result['total_teu'];
        }

        // Fill in zero values for allocations with no containers
        foreach ($allocationIds as $id) {
            if (!isset($utilizationMap[$id])) {
                $utilizationMap[$id] = 0.0;
            }
        }

        return $utilizationMap;
    }

    /**
     * Task 17.3: Get allocations with utilization data in a single query
     * Returns allocations with pre-calculated utilization
     * 
     * @param ShippingLine $shippingLine
     * @return array Array of allocation data with utilization
     */
    public function findWithUtilization(ShippingLine $shippingLine): array
    {
        $qb = $this->createQueryBuilder('slta');
        
        return $qb->select('slta', 't', 
                'slta.id as allocation_id',
                't.id as terminal_id',
                't.name as terminal_name',
                't.location as terminal_location',
                'slta.allocatedCapacity as total_capacity_teu',
                'COALESCE(SUM(
                    CASE 
                        WHEN cs.size = \'20ft\' THEN 1.0
                        WHEN cs.size = \'40ft\' THEN 2.0
                        WHEN cs.size = \'45ft\' THEN 2.5
                        ELSE 1.0
                    END
                ), 0) as used_teu',
                'COUNT(c.id) as container_count'
            )
            ->leftJoin('slta.terminal', 't')
            ->leftJoin('slta.containers', 'c')
            ->leftJoin('c.containerSize', 'cs')
            ->where('slta.shippingLine = :shippingLine')
            ->groupBy('slta.id', 't.id', 't.name', 't.location', 'slta.allocatedCapacity')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getResult();
    }

    /**
     * Task 17.3: Get single allocation utilization using database aggregation
     * 
     * @param int $allocationId
     * @return array Utilization data
     */
    public function getUtilization(int $allocationId): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        
        $result = $qb->select(
                'slta.allocatedCapacity as total_capacity_teu',
                'COALESCE(SUM(
                    CASE 
                        WHEN cs.size = \'20ft\' THEN 1.0
                        WHEN cs.size = \'40ft\' THEN 2.0
                        WHEN cs.size = \'45ft\' THEN 2.5
                        ELSE 1.0
                    END
                ), 0) as used_teu',
                'COUNT(c.id) as container_count'
            )
            ->from('App\Entity\ShippingLineTerminalAllocation', 'slta')
            ->leftJoin('slta.containers', 'c')
            ->leftJoin('c.containerSize', 'cs')
            ->where('slta.id = :allocationId')
            ->groupBy('slta.allocatedCapacity')
            ->setParameter('allocationId', $allocationId)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$result) {
            return [
                'total_capacity_teu' => 0.0,
                'used_teu' => 0.0,
                'container_count' => 0
            ];
        }

        return $result;
    }
}
