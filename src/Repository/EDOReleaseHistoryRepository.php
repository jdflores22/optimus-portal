<?php

namespace App\Repository;

use App\Entity\EDOReleaseHistory;
use App\Entity\ElectronicDeliveryOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EDOReleaseHistory>
 */
class EDOReleaseHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EDOReleaseHistory::class);
    }

    /**
     * Get release history for a specific eDO
     */
    public function getHistoryByEDO(ElectronicDeliveryOrder $edo): array
    {
        return $this->createQueryBuilder('erh')
            ->where('erh.edo = :edo')
            ->setParameter('edo', $edo)
            ->orderBy('erh.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get release history for a specific eDO by ID
     */
    public function getHistoryByEDOId(int $edoId): array
    {
        return $this->createQueryBuilder('erh')
            ->where('erh.edo = :edoId')
            ->setParameter('edoId', $edoId)
            ->orderBy('erh.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
