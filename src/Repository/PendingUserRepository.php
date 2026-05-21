<?php

namespace App\Repository;

use App\Entity\PendingUser;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingUser>
 */
class PendingUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingUser::class);
    }

    /**
     * Find a pending user by acceptance token
     */
    public function findByToken(string $token): ?PendingUser
    {
        return $this->findOneBy(['acceptanceToken' => $token]);
    }

    /**
     * Find all pending users created by a specific admin
     */
    public function findByCreatedByAdmin(User $admin): array
    {
        return $this->findBy(['createdByAdmin' => $admin], ['createdAt' => 'DESC']);
    }

    /**
     * Find all expired pending users
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.tokenExpiresAt <= :now')
            ->andWhere('p.status = :status')
            ->setParameter('now', new \DateTime())
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending users by status
     */
    public function findByStatus(string $status): array
    {
        return $this->findBy(['status' => $status], ['createdAt' => 'DESC']);
    }

    /**
     * Find pending users by email
     */
    public function findByEmail(string $email): array
    {
        return $this->findBy(['email' => $email], ['createdAt' => 'DESC']);
    }

    /**
     * Count pending users by admin
     */
    public function countByAdmin(User $admin): int
    {
        return $this->count(['createdByAdmin' => $admin, 'status' => 'pending']);
    }

    /**
     * Find all pending users that will expire within the given number of days
     */
    public function findExpiringWithinDays(int $days): array
    {
        $expirationDate = (new \DateTime())->add(new \DateInterval("P{$days}D"));
        
        return $this->createQueryBuilder('p')
            ->where('p.tokenExpiresAt <= :expirationDate')
            ->andWhere('p.tokenExpiresAt > :now')
            ->andWhere('p.status = :status')
            ->setParameter('expirationDate', $expirationDate)
            ->setParameter('now', new \DateTime())
            ->setParameter('status', 'pending')
            ->orderBy('p.tokenExpiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Remove expired pending users
     */
    public function removeExpired(): int
    {
        $expiredUsers = $this->findExpired();
        $count = count($expiredUsers);
        
        foreach ($expiredUsers as $user) {
            $user->markAsExpired();
        }
        
        $this->getEntityManager()->flush();
        
        return $count;
    }

    /**
     * Find pending users with pagination
     */
    public function findWithPagination(int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total pending users
     */
    public function countTotal(): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}