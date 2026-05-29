<?php

namespace App\Repository;

use App\Entity\EDOVersion;
use App\Entity\ElectronicDeliveryOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EDOVersion>
 */
class EDOVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EDOVersion::class);
    }

    /**
     * Get all versions for an eDO, ordered by version number descending
     *
     * @param ElectronicDeliveryOrder $edo
     * @return EDOVersion[]
     */
    public function findByEdo(ElectronicDeliveryOrder $edo): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.edo = :edo')
            ->setParameter('edo', $edo)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the current version for an eDO
     *
     * @param ElectronicDeliveryOrder $edo
     * @return EDOVersion|null
     */
    public function findCurrentVersion(ElectronicDeliveryOrder $edo): ?EDOVersion
    {
        return $this->createQueryBuilder('v')
            ->where('v.edo = :edo')
            ->andWhere('v.isCurrent = :current')
            ->setParameter('edo', $edo)
            ->setParameter('current', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get a specific version by version number
     *
     * @param ElectronicDeliveryOrder $edo
     * @param int $versionNumber
     * @return EDOVersion|null
     */
    public function findVersion(ElectronicDeliveryOrder $edo, int $versionNumber): ?EDOVersion
    {
        return $this->createQueryBuilder('v')
            ->where('v.edo = :edo')
            ->andWhere('v.versionNumber = :version')
            ->setParameter('edo', $edo)
            ->setParameter('version', $versionNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Mark all versions as not current for an eDO
     *
     * @param ElectronicDeliveryOrder $edo
     * @return int Number of versions updated
     */
    public function markAllAsNotCurrent(ElectronicDeliveryOrder $edo): int
    {
        return $this->createQueryBuilder('v')
            ->update()
            ->set('v.isCurrent', ':current')
            ->where('v.edo = :edo')
            ->setParameter('current', false)
            ->setParameter('edo', $edo)
            ->getQuery()
            ->execute();
    }

    /**
     * Get version history count for an eDO
     *
     * @param ElectronicDeliveryOrder $edo
     * @return int
     */
    public function countVersions(ElectronicDeliveryOrder $edo): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.edo = :edo')
            ->setParameter('edo', $edo)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
