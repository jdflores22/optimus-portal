<?php

namespace App\Repository;

use App\Entity\SystemConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemConfiguration>
 */
class SystemConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemConfiguration::class);
    }

    /**
     * Find active configuration by key
     */
    public function findActiveByKey(string $key): ?SystemConfiguration
    {
        return $this->createQueryBuilder('sc')
            ->where('sc.configKey = :key')
            ->andWhere('sc.isActive = :active')
            ->setParameter('key', $key)
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active configurations
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('sc')
            ->where('sc.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('sc.configKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find configurations by key pattern
     */
    public function findByKeyPattern(string $pattern): array
    {
        return $this->createQueryBuilder('sc')
            ->where('sc.configKey LIKE :pattern')
            ->andWhere('sc.isActive = :active')
            ->setParameter('pattern', $pattern)
            ->setParameter('active', true)
            ->orderBy('sc.configKey', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
