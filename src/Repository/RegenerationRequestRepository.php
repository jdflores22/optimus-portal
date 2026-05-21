<?php

namespace App\Repository;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\RegenerationRequest;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for RegenerationRequest entity
 * 
 * @extends ServiceEntityRepository<RegenerationRequest>
 */
class RegenerationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegenerationRequest::class);
    }

    /**
     * Find all regeneration requests for a specific eDO
     * Uses eager loading to prevent N+1 query problems
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return RegenerationRequest[]
     */
    public function findByEDO(ElectronicDeliveryOrder $edo): array
    {
        return $this->createQueryBuilder('rr')
            ->leftJoin('rr.edo', 'e')
            ->leftJoin('rr.requester', 'u')
            ->leftJoin('rr.billing', 'b')
            ->addSelect('e', 'u', 'b')
            ->where('rr.edo = :edo')
            ->setParameter('edo', $edo)
            ->orderBy('rr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all pending regeneration requests (submitted or routed to accounting)
     * Uses eager loading to prevent N+1 query problems
     * 
     * @return RegenerationRequest[]
     */
    public function findPendingRequests(): array
    {
        return $this->createQueryBuilder('rr')
            ->leftJoin('rr.edo', 'e')
            ->leftJoin('rr.requester', 'u')
            ->leftJoin('rr.billing', 'b')
            ->leftJoin('e.container', 'c')
            ->addSelect('e', 'u', 'b', 'c')
            ->where('rr.status IN (:statuses)')
            ->setParameter('statuses', [
                RequestStatus::SUBMITTED->value,
                RequestStatus::ROUTED_TO_ACCOUNTING->value,
                RequestStatus::BILLING_GENERATED->value,
                RequestStatus::PAYMENT_SUBMITTED->value
            ])
            ->orderBy('rr.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find regeneration requests by status
     * Uses eager loading to prevent N+1 query problems
     * 
     * @param RequestStatus $status
     * @return RegenerationRequest[]
     */
    public function findByStatus(RequestStatus $status): array
    {
        return $this->createQueryBuilder('rr')
            ->leftJoin('rr.edo', 'e')
            ->leftJoin('rr.requester', 'u')
            ->leftJoin('rr.billing', 'b')
            ->leftJoin('e.container', 'c')
            ->addSelect('e', 'u', 'b', 'c')
            ->where('rr.status = :status')
            ->setParameter('status', $status->value)
            ->orderBy('rr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Save a regeneration request
     * 
     * @param RegenerationRequest $request
     * @param bool $flush
     * @return void
     */
    public function save(RegenerationRequest $request, bool $flush = true): void
    {
        $this->getEntityManager()->persist($request);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
