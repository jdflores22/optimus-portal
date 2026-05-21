<?php

namespace App\Repository;

use App\Entity\EDOPaymentReceipt;
use App\Entity\Enum\EDOPaymentReceiptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EDOPaymentReceipt>
 */
class EDOPaymentReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EDOPaymentReceipt::class);
    }

    /**
     * Find payment by billing
     */
    public function findByBilling(int $billingId): ?EDOPaymentReceipt
    {
        return $this->findOneBy(['billing' => $billingId]);
    }

    /**
     * Find payments by status
     */
    public function findByStatus(EDOPaymentReceiptStatus $status): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', $status->value)
            ->orderBy('p.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending payments (submitted but not confirmed/rejected)
     */
    public function findPendingPayments(): array
    {
        return $this->findByStatus(EDOPaymentReceiptStatus::SUBMITTED);
    }
}
