<?php

namespace App\Repository;

use App\Entity\Trucker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trucker>
 */
class TruckerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trucker::class);
    }

    /**
     * Find trucker by email
     */
    public function findByEmail(string $email): ?Trucker
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Find trucker by API token hash
     */
    public function findByApiTokenHash(string $tokenHash): ?Trucker
    {
        return $this->findOneBy(['apiTokenHash' => $tokenHash]);
    }

    /**
     * Find truckers with expired API tokens
     */
    public function findWithExpiredTokens(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.apiTokenExpiresAt IS NOT NULL')
            ->andWhere('t.apiTokenExpiresAt < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    /**
     * Find truckers with valid API tokens
     */
    public function findWithValidTokens(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.apiTokenHash IS NOT NULL')
            ->andWhere('(t.apiTokenExpiresAt IS NULL OR t.apiTokenExpiresAt > :now)')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active truckers (with recent activity)
     */
    public function findActiveTruckers(\DateTime $since = null): array
    {
        if (!$since) {
            $since = new \DateTime('-30 days');
        }

        return $this->createQueryBuilder('t')
            ->where('t.lastActivityAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('t.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count truckers by registration date range
     */
    public function countByDateRange(\DateTime $startDate, \DateTime $endDate): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.createdAt >= :startDate')
            ->andWhere('t.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find truckers with pre-advice requests
     */
    public function findWithPreAdviceRequests(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.preAdviceRequests', 'p')
            ->where('p.id IS NOT NULL')
            ->groupBy('t.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search truckers by various criteria
     */
    public function search(array $criteria = []): array
    {
        $qb = $this->createQueryBuilder('t');

        if (isset($criteria['email'])) {
            $qb->andWhere('t.email LIKE :email')
               ->setParameter('email', '%' . $criteria['email'] . '%');
        }

        if (isset($criteria['firstName'])) {
            $qb->andWhere('t.firstName LIKE :firstName')
               ->setParameter('firstName', '%' . $criteria['firstName'] . '%');
        }

        if (isset($criteria['lastName'])) {
            $qb->andWhere('t.lastName LIKE :lastName')
               ->setParameter('lastName', '%' . $criteria['lastName'] . '%');
        }

        if (isset($criteria['companyName'])) {
            $qb->andWhere('t.companyName LIKE :companyName')
               ->setParameter('companyName', '%' . $criteria['companyName'] . '%');
        }

        if (isset($criteria['licenseNumber'])) {
            $qb->andWhere('t.licenseNumber = :licenseNumber')
               ->setParameter('licenseNumber', $criteria['licenseNumber']);
        }

        if (isset($criteria['truckPlateNumber'])) {
            $qb->andWhere('t.truckPlateNumber = :truckPlateNumber')
               ->setParameter('truckPlateNumber', $criteria['truckPlateNumber']);
        }

        if (isset($criteria['hasApiToken'])) {
            if ($criteria['hasApiToken']) {
                $qb->andWhere('t.apiTokenHash IS NOT NULL');
            } else {
                $qb->andWhere('t.apiTokenHash IS NULL');
            }
        }

        if (isset($criteria['isActive'])) {
            if ($criteria['isActive']) {
                $qb->andWhere('t.isActive = true');
            } else {
                $qb->andWhere('t.isActive = false');
            }
        }

        return $qb->orderBy('t.createdAt', 'DESC')
                  ->getQuery()
                  ->getResult();
    }
}