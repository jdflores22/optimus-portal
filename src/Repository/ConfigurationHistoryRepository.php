<?php

namespace App\Repository;

use App\Entity\ConfigurationHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfigurationHistory>
 */
class ConfigurationHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfigurationHistory::class);
    }

    /**
     * Find history for a specific configuration key
     */
    public function findByConfigKey(string $key, int $limit = 50): array
    {
        return $this->createQueryBuilder('ch')
            ->where('ch.configKey = :key')
            ->setParameter('key', $key)
            ->orderBy('ch.changedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent configuration changes
     */
    public function findRecentChanges(int $limit = 100): array
    {
        return $this->createQueryBuilder('ch')
            ->orderBy('ch.changedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
