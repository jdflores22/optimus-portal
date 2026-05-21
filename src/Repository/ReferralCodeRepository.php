<?php

namespace App\Repository;

use App\Entity\ReferralCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferralCode>
 */
class ReferralCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferralCode::class);
    }

    /**
     * Find active referral codes for a consignee
     */
    public function findActiveByConsignee(User $consignee): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.consignee = :consignee')
            ->andWhere('r.isActive = true')
            ->setParameter('consignee', $consignee)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a valid referral code by code string
     */
    public function findValidCode(string $code): ?ReferralCode
    {
        return $this->createQueryBuilder('r')
            ->where('r.code = :code')
            ->andWhere('r.isActive = true')
            ->andWhere('r.expiresAt IS NULL OR r.expiresAt > :now')
            ->setParameter('code', $code)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find codes with usage statistics
     */
    public function findWithUsageStats(User $consignee): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'COUNT(cbr.id) as usageCount')
            ->leftJoin('App\Entity\ConsigneeBrokerRelationship', 'cbr', 'WITH', 'cbr.referralCode = r.id')
            ->where('r.consignee = :consignee')
            ->setParameter('consignee', $consignee)
            ->groupBy('r.id')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find expired codes that are still active
     */
    public function findExpiredActiveCodes(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isActive = true')
            ->andWhere('r.expiresAt IS NOT NULL')
            ->andWhere('r.expiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    /**
     * Find codes that have reached max uses but are still active
     */
    public function findMaxUsedActiveCodes(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isActive = true')
            ->andWhere('r.maxUses IS NOT NULL')
            ->andWhere('r.currentUses >= r.maxUses')
            ->getQuery()
            ->getResult();
    }
}
