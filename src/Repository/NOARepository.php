<?php

namespace App\Repository;

use App\Entity\NOA;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NOA>
 */
class NOARepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NOA::class);
    }

    /**
     * Get the next sequence number for NOA number generation
     */
    public function getNextSequenceNumber(): int
    {
        $today = (new \DateTime())->format('Y-m-d');
        $tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');

        $qb = $this->createQueryBuilder('n');
        $count = $qb->select('COUNT(n.id)')
            ->where('n.createdAt >= :today')
            ->andWhere('n.createdAt < :tomorrow')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->getQuery()
            ->getSingleScalarResult();

        return (int)$count + 1;
    }

    /**
     * Find NOA by BL number
     */
    public function findByBlNumber(string $blNumber): ?NOA
    {
        return $this->findOneBy(['blNumber' => $blNumber]);
    }

    /**
     * Find NOAs by consignee
     */
    public function findByConsignee(int $consigneeId): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.consignee = :consigneeId')
            ->setParameter('consigneeId', $consigneeId)
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
