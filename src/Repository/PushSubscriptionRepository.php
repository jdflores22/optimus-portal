<?php

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * Find the oldest push subscription for a user
     */
    public function findOldestForUser(User $user): ?PushSubscription
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.user = :user')
            ->andWhere('ps.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('ps.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active push subscriptions for a user
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.user = :user')
            ->andWhere('ps.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('ps.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active push subscriptions for a user
     */
    public function countActiveByUser(User $user): int
    {
        return $this->createQueryBuilder('ps')
            ->select('COUNT(ps.id)')
            ->where('ps.user = :user')
            ->andWhere('ps.isActive = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
