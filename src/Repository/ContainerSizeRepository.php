<?php

namespace App\Repository;

use App\Entity\ContainerSize;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContainerSize>
 */
class ContainerSizeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContainerSize::class);
    }

    /**
     * Find all active container sizes ordered by name
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('cs')
            ->where('cs.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('cs.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find container size by code
     */
    public function findByCode(string $code): ?ContainerSize
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Find all container sizes ordered alphabetically by name
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('cs')
            ->orderBy('cs.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
