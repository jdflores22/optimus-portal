<?php

namespace App\Repository;

use App\Entity\EDOPayment;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Manifest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EDOPayment>
 */
class EDOPaymentRepository extends ServiceEntityRepository
{
    use ShippingLineFilterTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EDOPayment::class);
    }

    /**
     * Find all pending eDO payments with eager loading
     */
    public function findPendingPayments(?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('ep')
            ->leftJoin('ep.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('m.edo', 'e')
            ->addSelect('e')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('ep.submittedBy', 's')
            ->addSelect('s')
            ->where('ep.status = :status')
            ->andWhere('e.id IS NOT NULL')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('ep.createdAt', 'ASC');

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'ep');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find eDO payments by manifest
     */
    public function findByManifest(Manifest $manifest): array
    {
        return $this->createQueryBuilder('ep')
            ->where('ep.manifest = :manifest')
            ->setParameter('manifest', $manifest)
            ->orderBy('ep.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find eDO payments by status
     */
    public function findByStatus(PaymentStatus $status, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('ep')
            ->leftJoin('ep.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('m.edo', 'e')
            ->addSelect('e')
            ->where('ep.status = :status')
            ->setParameter('status', $status)
            ->orderBy('ep.createdAt', 'DESC');

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'ep');

        return $qb->getQuery()->getResult();
    }

    /**
     * Count pending eDO payments
     */
    public function countPendingPayments(?int $shippingLineId = null): int
    {
        $qb = $this->createQueryBuilder('ep')
            ->select('COUNT(ep.id)')
            ->where('ep.status = :status')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION);

        $this->applyShippingLineFilter($qb, $shippingLineId, 'ep');

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Find eDO payment by ID with related entities
     */
    public function findWithRelations(int $id, ?int $shippingLineId = null): ?EDOPayment
    {
        $qb = $this->createQueryBuilder('ep')
            ->leftJoin('ep.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('m.edo', 'e')
            ->addSelect('e')
            ->leftJoin('ep.submittedBy', 's')
            ->addSelect('s')
            ->leftJoin('ep.validatedBy', 'v')
            ->addSelect('v')
            ->where('ep.id = :id')
            ->setParameter('id', $id);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'ep');

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find eDO payments by shipping line
     */
    public function findByShippingLine(int $shippingLineId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('ep')
            ->leftJoin('ep.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('m.edo', 'e')
            ->addSelect('e')
            ->orderBy('ep.createdAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'ep');

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
            FROM payments_edo
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
            FROM payments_edo
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
            FROM payments_edo
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
            FROM payments_edo
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
            FROM payments_edo
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
     * Find all pending payments with eager loading for per-container payment workflow
     * Orders by submission timestamp ascending
     * Includes eDO, manifest, container, and user relationships
     * 
     * @return EDOPayment[]
     */
    public function findPendingPaymentsWithRelations(): array
    {
        return $this->createQueryBuilder('ep')
            ->leftJoin('ep.edo', 'e')
            ->leftJoin('ep.manifest', 'm')
            ->leftJoin('e.container', 'c')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('ep.submittedBy', 's')
            ->addSelect('e', 'm', 'c', 'b', 's')
            ->where('ep.status = :status')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('ep.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all payments for specific eDO ordered by creation date
     * Used for payment history display
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return EDOPayment[]
     */
    public function findByEDO(ElectronicDeliveryOrder $edo): array
    {
        return $this->createQueryBuilder('ep')
            ->leftJoin('ep.submittedBy', 's')
            ->leftJoin('ep.validatedBy', 'v')
            ->addSelect('s', 'v')
            ->where('ep.edo = :edo')
            ->setParameter('edo', $edo)
            ->orderBy('ep.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
