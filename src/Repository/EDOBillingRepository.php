<?php

namespace App\Repository;

use App\Entity\EDOBilling;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EDOBilling>
 */
class EDOBillingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EDOBilling::class);
    }

    /**
     * Find billing by regeneration request
     */
    public function findByRegenerationRequest(int $requestId): ?EDOBilling
    {
        return $this->findOneBy(['regenerationRequest' => $requestId]);
    }

    /**
     * Find unpaid billings
     */
    public function findUnpaidBillings(): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.payment', 'p')
            ->where('p.id IS NULL')
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
