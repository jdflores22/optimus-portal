<?php

namespace App\Repository;

use App\Entity\PaymentFeeConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentFeeConfiguration>
 */
class PaymentFeeConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentFeeConfiguration::class);
    }

    /**
     * Get the current fee configuration for a specific fee type
     */
    public function getCurrentFeeByType(string $feeType): ?PaymentFeeConfiguration
    {
        $query = $this->createQueryBuilder('pfc')
            ->where('pfc.feeType = :feeType')
            ->andWhere('pfc.isActive = :isActive')
            ->setParameter('feeType', $feeType)
            ->setParameter('isActive', true)
            ->setMaxResults(1)
            ->getQuery();
        
        // Disable caching
        $query->enableResultCache(false);
        
        return $query->getOneOrNullResult();
    }

    /**
     * Get fee configuration history for a specific fee type
     */
    public function getFeeHistoryByType(string $feeType): array
    {
        $query = $this->createQueryBuilder('pfc')
            ->where('pfc.feeType = :feeType')
            ->setParameter('feeType', $feeType)
            ->orderBy('pfc.configuredAt', 'DESC')
            ->getQuery();
        
        // Disable caching
        $query->enableResultCache(false);
        
        return $query->getResult();
    }
}
