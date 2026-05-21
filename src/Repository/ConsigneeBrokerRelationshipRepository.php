<?php

namespace App\Repository;

use App\Entity\ConsigneeBrokerRelationship;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConsigneeBrokerRelationship>
 */
class ConsigneeBrokerRelationshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsigneeBrokerRelationship::class);
    }

    /**
     * Find active brokers for a consignee
     */
    public function findActiveBrokersForConsignee(User $consignee): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'b')
            ->join('r.broker', 'b')
            ->where('r.consignee = :consignee')
            ->andWhere('r.status = :status')
            ->andWhere('b.isActive = true')
            ->setParameter('consignee', $consignee)
            ->setParameter('status', ConsigneeBrokerRelationship::STATUS_ACTIVE)
            ->orderBy('b.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active consignees for a broker
     */
    public function findActiveConsigneesForBroker(User $broker): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'c')
            ->join('r.consignee', 'c')
            ->where('r.broker = :broker')
            ->andWhere('r.status = :status')
            ->setParameter('broker', $broker)
            ->setParameter('status', ConsigneeBrokerRelationship::STATUS_ACTIVE)
            ->orderBy('c.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if active relationship exists
     */
    public function hasActiveRelationship(User $consignee, User $broker): bool
    {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.consignee = :consignee')
            ->andWhere('r.broker = :broker')
            ->andWhere('r.status = :status')
            ->setParameter('consignee', $consignee)
            ->setParameter('broker', $broker)
            ->setParameter('status', ConsigneeBrokerRelationship::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Count manifests per broker for a consignee
     */
    public function countManifestsPerBroker(User $consignee): array
    {
        return $this->createQueryBuilder('r')
            ->select('b.id as brokerId', 'b.email as brokerEmail', 'COUNT(m.id) as manifestCount')
            ->join('r.broker', 'b')
            ->leftJoin('App\Entity\Manifest', 'm', 'WITH', 'm.broker = b.id AND m.consignee = :consignee')
            ->where('r.consignee = :consignee')
            ->andWhere('r.status = :status')
            ->setParameter('consignee', $consignee)
            ->setParameter('status', ConsigneeBrokerRelationship::STATUS_ACTIVE)
            ->groupBy('b.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all relationships for a broker (any status)
     */
    public function findAllForBroker(User $broker): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'c')
            ->join('r.consignee', 'c')
            ->where('r.broker = :broker')
            ->setParameter('broker', $broker)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all relationships for a consignee (any status)
     */
    public function findAllForConsignee(User $consignee): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'b')
            ->join('r.broker', 'b')
            ->where('r.consignee = :consignee')
            ->setParameter('consignee', $consignee)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find relationships by referral code
     */
    public function findByReferralCode(int $referralCodeId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.referralCode = :codeId')
            ->setParameter('codeId', $referralCodeId)
            ->getQuery()
            ->getResult();
    }
}
