<?php

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Manifest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    use ShippingLineFilterTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * Find all pending payments with eager loading
     */
    public function findPendingPayments(?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('p.submittedBy', 's')
            ->addSelect('s')
            ->where('p.status = :status')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('p.createdAt', 'ASC');

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'p');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find payments by manifest
     */
    public function findByManifest(Manifest $manifest): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.manifest = :manifest')
            ->setParameter('manifest', $manifest)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find payments by status
     */
    public function findByStatus(PaymentStatus $status, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->addSelect('m')
            ->where('p.status = :status')
            ->setParameter('status', $status)
            ->orderBy('p.createdAt', 'DESC');

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'p');

        return $qb->getQuery()->getResult();
    }

    /**
     * Count pending payments
     */
    public function countPendingPayments(?int $shippingLineId = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :status')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION);

        $this->applyShippingLineFilter($qb, $shippingLineId, 'p');

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Find payment by ID with related entities
     */
    public function findWithRelations(int $id, ?int $shippingLineId = null): ?Payment
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('p.submittedBy', 's')
            ->addSelect('s')
            ->leftJoin('p.validatedBy', 'v')
            ->addSelect('v')
            ->where('p.id = :id')
            ->setParameter('id', $id);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'p');

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find payments by shipping line
     */
    public function findByShippingLine(int $shippingLineId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->addSelect('m')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'p');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get comprehensive payment statistics for dashboard
     */
    public function getPaymentStatistics(?int $shippingLineId = null): array
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $shippingLineCondition = $shippingLineId !== null 
            ? 'AND shipping_line_id = :shippingLineId' 
            : '';
        
        // Total fees collected (verified payments only)
        $totalFeesQuery = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_fees
            FROM payments
            WHERE status = :verified_status
            {$shippingLineCondition}
        ");
        $totalFeesQuery->bindValue('verified_status', PaymentStatus::VERIFIED->value);
        if ($shippingLineId !== null) {
            $totalFeesQuery->bindValue('shippingLineId', $shippingLineId);
        }
        $totalFees = $totalFeesQuery->executeQuery()->fetchOne();
        
        // Daily fees (today's verified payments)
        $dailyFeesQuery = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as daily_fees
            FROM payments
            WHERE status = :verified_status
            AND DATE(validated_at) = CURDATE()
            {$shippingLineCondition}
        ");
        $dailyFeesQuery->bindValue('verified_status', PaymentStatus::VERIFIED->value);
        if ($shippingLineId !== null) {
            $dailyFeesQuery->bindValue('shippingLineId', $shippingLineId);
        }
        $dailyFees = $dailyFeesQuery->executeQuery()->fetchOne();
        
        // Awaiting payment (pending_validation + rejected)
        $awaitingPaymentQuery = $conn->prepare("
            SELECT COUNT(*) as awaiting_count, COALESCE(SUM(amount), 0) as awaiting_amount
            FROM payments
            WHERE status IN (:pending_status, :rejected_status)
            {$shippingLineCondition}
        ");
        $awaitingPaymentQuery->bindValue('pending_status', PaymentStatus::PENDING_VALIDATION->value);
        $awaitingPaymentQuery->bindValue('rejected_status', PaymentStatus::REJECTED->value);
        if ($shippingLineId !== null) {
            $awaitingPaymentQuery->bindValue('shippingLineId', $shippingLineId);
        }
        $awaitingResult = $awaitingPaymentQuery->executeQuery()->fetchAssociative();
        
        // Completed payments (verified)
        $completedPaymentsQuery = $conn->prepare("
            SELECT COUNT(*) as completed_count, COALESCE(SUM(amount), 0) as completed_amount
            FROM payments
            WHERE status = :verified_status
            {$shippingLineCondition}
        ");
        $completedPaymentsQuery->bindValue('verified_status', PaymentStatus::VERIFIED->value);
        if ($shippingLineId !== null) {
            $completedPaymentsQuery->bindValue('shippingLineId', $shippingLineId);
        }
        $completedResult = $completedPaymentsQuery->executeQuery()->fetchAssociative();
        
        // Total payments (all statuses)
        $totalPaymentsQuery = $conn->prepare("
            SELECT COUNT(*) as total_count, COALESCE(SUM(amount), 0) as total_amount
            FROM payments
            {$shippingLineCondition}
        ");
        if ($shippingLineId !== null) {
            $totalPaymentsQuery->bindValue('shippingLineId', $shippingLineId);
        }
        $totalResult = $totalPaymentsQuery->executeQuery()->fetchAssociative();
        
        return [
            'total_fees' => (float) $totalFees,
            'daily_fees' => (float) $dailyFees,
            'awaiting_payment_count' => (int) $awaitingResult['awaiting_count'],
            'awaiting_payment_amount' => (float) $awaitingResult['awaiting_amount'],
            'completed_payment_count' => (int) $completedResult['completed_count'],
            'completed_payment_amount' => (float) $completedResult['completed_amount'],
            'total_payment_count' => (int) $totalResult['total_count'],
            'total_payment_amount' => (float) $totalResult['total_amount']
        ];
    }

    /**
     * Find all payment versions for a manifest ordered by version
     * Includes eager loading of related entities for optimal performance
     */
    public function findAllVersionsByManifest(Manifest $manifest, string $paymentType): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.submittedBy', 'sb')
            ->leftJoin('p.validatedBy', 'vb')
            ->leftJoin('p.previousPayment', 'pp')
            ->addSelect('sb', 'vb', 'pp')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->setParameter('manifest', $manifest)
            ->setParameter('type', $paymentType)
            ->orderBy('p.version', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the latest payment version for a manifest
     */
    public function findLatestVersion(Manifest $manifest, string $paymentType): ?Payment
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.submittedBy', 'sb')
            ->leftJoin('p.validatedBy', 'vb')
            ->leftJoin('p.previousPayment', 'pp')
            ->addSelect('sb', 'vb', 'pp')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->setParameter('manifest', $manifest)
            ->setParameter('type', $paymentType)
            ->orderBy('p.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get complete payment chain (follow previousPayment links)
     * Returns array of payments from v1 to the latest version
     */
    public function getPaymentChain(Payment $payment): array
    {
        $chain = [];
        $current = $payment;

        // Walk backwards to find the root (version 1)
        while ($current->getPreviousPayment() !== null) {
            $current = $current->getPreviousPayment();
        }

        // Build forward chain starting from root
        $chain[] = $current;

        // Find all subsequent versions
        while ($next = $this->findNextVersion($current)) {
            $chain[] = $next;
            $current = $next;
        }

        return $chain;
    }

    /**
     * Find the next version after a given payment
     */
    private function findNextVersion(Payment $payment): ?Payment
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.submittedBy', 'sb')
            ->leftJoin('p.validatedBy', 'vb')
            ->addSelect('sb', 'vb')
            ->where('p.previousPayment = :payment')
            ->setParameter('payment', $payment)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get next version number for a manifest
     * Returns 1 if no payments exist, otherwise returns max(version) + 1
     */
    public function getNextVersionNumber(Manifest $manifest, string $paymentType): int
    {
        $result = $this->createQueryBuilder('p')
            ->select('MAX(p.version)')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->setParameter('manifest', $manifest)
            ->setParameter('type', $paymentType)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (int) $result + 1 : 1;
    }

    /**
     * Count payment versions for a manifest
     */
    public function countVersions(Manifest $manifest, string $paymentType): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->setParameter('manifest', $manifest)
            ->setParameter('type', $paymentType)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
