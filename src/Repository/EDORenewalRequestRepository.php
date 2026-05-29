<?php

namespace App\Repository;

use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\RenewalRequestStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EDORenewalRequest>
 */
class EDORenewalRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EDORenewalRequest::class);
    }

    /**
     * Find pending renewal requests for a shipping line
     * 
     * Only returns requests that are ready for eDO generation:
     * - PAYMENT_VERIFIED: Payment has been verified by accounting
     * - READY_FOR_GENERATION: No detention charges or payment already verified
     * 
     * Does NOT include:
     * - PENDING_REVIEW: Waiting for accounting to generate billing
     * - AWAITING_PAYMENT: Waiting for broker to pay
     * - PAYMENT_SUBMITTED: Waiting for accounting to verify payment
     * 
     * @param \App\Entity\ShippingLine $shippingLine The shipping line
     * @return array<EDORenewalRequest>
     */
    public function findPendingRequestsForShippingLine(\App\Entity\ShippingLine $shippingLine): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.expiredEdo', 'e')
            ->join('e.shippingLine', 's')
            ->where('s.id = :shippingLineId')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.newEdo IS NULL') // Only requests without a generated eDO
            ->setParameter('shippingLineId', $shippingLine->getId())
            ->setParameter('statuses', [
                RenewalRequestStatus::PAYMENT_VERIFIED,
                RenewalRequestStatus::READY_FOR_GENERATION
            ])
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find renewal requests by expired eDO
     * 
     * @param ElectronicDeliveryOrder $expiredEdo The expired eDO
     * @return array<EDORenewalRequest>
     */
    public function findByExpiredEdo(ElectronicDeliveryOrder $expiredEdo): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.expiredEdo = :expiredEdo')
            ->setParameter('expiredEdo', $expiredEdo)
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find renewal requests by status
     * 
     * @param RenewalRequestStatus $status The renewal request status
     * @return array<EDORenewalRequest>
     */
    public function findByStatus(RenewalRequestStatus $status): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', $status)
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find renewal requests by broker
     * 
     * @param User $broker The broker user
     * @return array<EDORenewalRequest>
     */
    public function findByBroker(User $broker): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.requestedBy = :broker')
            ->setParameter('broker', $broker)
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
