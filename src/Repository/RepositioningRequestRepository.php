<?php

namespace App\Repository;

use App\Entity\Enum\RepositioningRequestStatus;
use App\Entity\RepositioningRequest;
use App\Entity\ShippingLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RepositioningRequest>
 */
class RepositioningRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RepositioningRequest::class);
    }

    /**
     * @return RepositioningRequest[]
     */
    public function findForShippingLine(ShippingLine $shippingLine, ?RepositioningRequestStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.sourceTerminal', 'src')->addSelect('src')
            ->leftJoin('r.destinationTerminal', 'dst')->addSelect('dst')
            ->leftJoin('r.requestedBy', 'rb')->addSelect('rb')
            ->where('r.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine)
            ->orderBy('r.requestedAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('r.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countPendingForShippingLine(ShippingLine $shippingLine): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.shippingLine = :shippingLine')
            ->andWhere('r.status = :status')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('status', RepositioningRequestStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAllPending(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :status')
            ->setParameter('status', RepositioningRequestStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getNextSequenceNumber(): int
    {
        $year = (new \DateTime())->format('Y');
        $prefix = 'RRP-' . $year . '-';

        $last = $this->createQueryBuilder('r')
            ->select('r.requestNumber')
            ->where('r.requestNumber LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('r.requestNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$last) {
            return 1;
        }

        $parts = explode('-', $last['requestNumber']);

        return ((int) end($parts)) + 1;
    }
}
