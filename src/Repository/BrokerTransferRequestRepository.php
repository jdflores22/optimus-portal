<?php

namespace App\Repository;

use App\Entity\BrokerTransferRequest;
use App\Entity\User;
use App\Entity\Manifest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrokerTransferRequest>
 */
class BrokerTransferRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrokerTransferRequest::class);
    }

    /**
     * Find all pending transfer requests
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('t')
            ->select('t', 'm', 'c', 'ob', 'nb')
            ->join('t.manifest', 'm')
            ->join('t.consignee', 'c')
            ->join('t.oldBroker', 'ob')
            ->join('t.newBroker', 'nb')
            ->where('t.status = :status')
            ->setParameter('status', BrokerTransferRequest::STATUS_PENDING)
            ->orderBy('t.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find transfer requests for a consignee
     */
    public function findByConsignee(User $consignee, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t', 'm', 'ob', 'nb')
            ->join('t.manifest', 'm')
            ->join('t.oldBroker', 'ob')
            ->join('t.newBroker', 'nb')
            ->where('t.consignee = :consignee')
            ->setParameter('consignee', $consignee);

        if ($status !== null) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->orderBy('t.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find transfer requests for a manifest
     */
    public function findByManifest(Manifest $manifest): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.manifest = :manifest')
            ->setParameter('manifest', $manifest)
            ->orderBy('t.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending request for a specific manifest
     */
    public function findPendingForManifest(Manifest $manifest): ?BrokerTransferRequest
    {
        return $this->createQueryBuilder('t')
            ->where('t.manifest = :manifest')
            ->andWhere('t.status = :status')
            ->setParameter('manifest', $manifest)
            ->setParameter('status', BrokerTransferRequest::STATUS_PENDING)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find transfer requests involving a broker (old or new)
     */
    public function findByBroker(User $broker): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.oldBroker = :broker OR t.newBroker = :broker')
            ->setParameter('broker', $broker)
            ->orderBy('t.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count pending requests
     */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.status = :status')
            ->setParameter('status', BrokerTransferRequest::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find recently reviewed requests
     */
    public function findRecentlyReviewed(int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status IN (:statuses)')
            ->setParameter('statuses', [
                BrokerTransferRequest::STATUS_APPROVED,
                BrokerTransferRequest::STATUS_REJECTED
            ])
            ->orderBy('t.reviewedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find requests reviewed by a specific user
     */
    public function findReviewedBy(User $reviewer): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.reviewedBy = :reviewer')
            ->setParameter('reviewer', $reviewer)
            ->orderBy('t.reviewedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
