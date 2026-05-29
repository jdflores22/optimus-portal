<?php

namespace App\Repository;

use App\Entity\Billing;
use App\Entity\EDORenewalRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Billing>
 */
class BillingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Billing::class);
    }

    /**
     * Find billing by renewal request
     */
    public function findByRenewalRequest(EDORenewalRequest $renewalRequest): ?Billing
    {
        return $this->findOneBy(['edoRenewalRequest' => $renewalRequest]);
    }

    /**
     * Find detention billings awaiting payment
     */
    public function findDetentionBillingsAwaitingPayment(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.billingType = :type')
            ->andWhere('b.edoRenewalRequest IS NOT NULL')
            ->setParameter('type', 'detention')
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all detention billings
     */
    public function findDetentionBillings(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.billingType = :type')
            ->setParameter('type', 'detention')
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find billings by manifest
     */
    public function findByManifest(int $manifestId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.manifest = :manifestId')
            ->setParameter('manifestId', $manifestId)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find detention billings for a specific broker
     * 
     * @param \App\Entity\User $broker
     * @return array<Billing>
     */
    public function findDetentionBillingsByBroker(\App\Entity\User $broker): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin('b.edoRenewalRequest', 'r')
            ->where('b.billingType = :type')
            ->andWhere('r.requestedBy = :broker')
            ->setParameter('type', 'detention')
            ->setParameter('broker', $broker)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all billing versions for a renewal request ordered by version
     * 
     * @param EDORenewalRequest $renewalRequest
     * @return array<Billing>
     */
    public function findAllVersionsByRenewalRequest(EDORenewalRequest $renewalRequest): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.paymentSubmittedBy', 'psb')
            ->leftJoin('b.generatedBy', 'gb')
            ->leftJoin('b.previousBilling', 'pb')
            ->addSelect('psb', 'gb', 'pb')
            ->where('b.edoRenewalRequest = :renewalRequest')
            ->setParameter('renewalRequest', $renewalRequest)
            ->orderBy('b.version', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get billing chain (follow previousBilling links)
     * Walks backwards to find root (v1) then builds forward chain
     * 
     * @param Billing $billing
     * @return array<Billing>
     */
    public function getBillingChain(Billing $billing): array
    {
        $chain = [];
        $current = $billing;
        
        // Walk backwards to find the root (version 1)
        while ($current->getPreviousBilling() !== null) {
            $current = $current->getPreviousBilling();
        }
        
        // Now build forward chain starting from root
        $chain[] = $current;
        
        // Find all subsequent versions
        while ($next = $this->findNextVersion($current)) {
            $chain[] = $next;
            $current = $next;
        }
        
        return $chain;
    }

    /**
     * Find the next version after a given billing
     * 
     * @param Billing $billing
     * @return Billing|null
     */
    private function findNextVersion(Billing $billing): ?Billing
    {
        return $this->findOneBy(['previousBilling' => $billing]);
    }

    /**
     * Get the latest billing version for a renewal request
     * 
     * @param EDORenewalRequest $renewalRequest
     * @return Billing|null
     */
    public function findLatestVersion(EDORenewalRequest $renewalRequest): ?Billing
    {
        return $this->createQueryBuilder('b')
            ->where('b.edoRenewalRequest = :renewalRequest')
            ->setParameter('renewalRequest', $renewalRequest)
            ->orderBy('b.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get next version number for a renewal request
     * 
     * @param EDORenewalRequest $renewalRequest
     * @return int
     */
    public function getNextVersionNumber(EDORenewalRequest $renewalRequest): int
    {
        $latestVersion = $this->findLatestVersion($renewalRequest);
        
        if ($latestVersion === null) {
            return 1;
        }
        
        return $latestVersion->getVersion() + 1;
    }

    /**
     * Count billing versions for a renewal request
     * 
     * @param EDORenewalRequest $renewalRequest
     * @return int
     */
    public function countVersions(EDORenewalRequest $renewalRequest): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.edoRenewalRequest = :renewalRequest')
            ->setParameter('renewalRequest', $renewalRequest)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
