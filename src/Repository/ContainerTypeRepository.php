<?php

namespace App\Repository;

use App\Entity\ContainerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContainerType>
 */
class ContainerTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContainerType::class);
    }

    /**
     * Find all active container types ordered by name
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('ct')
            ->where('ct.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('ct.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find container type by code
     */
    public function findByCode(string $code): ?ContainerType
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Find all container types ordered alphabetically by name
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('ct')
            ->orderBy('ct.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
