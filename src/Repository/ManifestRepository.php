<?php

namespace App\Repository;

use App\Entity\Manifest;
use App\Entity\Enum\WorkflowState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Manifest>
 */
class ManifestRepository extends ServiceEntityRepository
{
    use ShippingLineFilterTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Manifest::class);
    }

    /**
     * Find manifest by ID with all related entities eagerly loaded
     */
    public function findWithRelations(int $id, ?int $shippingLineId = null): ?Manifest
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('m.createdBy', 'cb')
            ->addSelect('cb')
            ->leftJoin('m.payments', 'p')
            ->addSelect('p')
            ->leftJoin('p.submittedBy', 'ps')
            ->addSelect('ps')
            ->leftJoin('p.validatedBy', 'pv')
            ->addSelect('pv')
            ->leftJoin('m.billing', 'bill')
            ->addSelect('bill')
            ->leftJoin('m.noaDocument', 'noa')
            ->addSelect('noa')
            ->leftJoin('m.noa', 'noaEntity')
            ->addSelect('noaEntity')
            ->leftJoin('m.edos', 'edos')
            ->addSelect('edos')
            ->where('m.id = :id')
            ->setParameter('id', $id);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'm');

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find manifest by ID with minimal relations for BL upload page
     * Optimized query that only loads consignee and broker (10x faster than findWithRelations)
     */
    public function findForBLUpload(int $id, ?int $shippingLineId = null): ?Manifest
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->where('m.id = :id')
            ->setParameter('id', $id);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'm');

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find manifests by consignee with eager loading
     */
    public function findByConsigneeWithRelations(int $consigneeId, ?WorkflowState $state = null, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('m.payments', 'p')
            ->addSelect('p')
            ->leftJoin('m.billing', 'bill')
            ->addSelect('bill')
            ->where('m.consignee = :consigneeId')
            ->setParameter('consigneeId', $consigneeId)
            ->orderBy('m.createdAt', 'DESC');

        if ($state !== null) {
            $qb->andWhere('m.workflowState = :state')
               ->setParameter('state', $state);
        }

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'm');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find manifests by broker with eager loading
     */
    public function findByBrokerWithRelations(int $brokerId, ?WorkflowState $state = null, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('m.payments', 'p')
            ->addSelect('p')
            ->leftJoin('m.billing', 'bill')
            ->addSelect('bill')
            ->where('m.broker = :brokerId')
            ->setParameter('brokerId', $brokerId)
            ->orderBy('m.createdAt', 'DESC');

        if ($state !== null) {
            $qb->andWhere('m.workflowState = :state')
               ->setParameter('state', $state);
        }

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'm');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find manifests by workflow state with eager loading
     */
    public function findByStateWithRelations(WorkflowState $state, int $limit = 50, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('m.createdBy', 'cb')
            ->addSelect('cb')
            ->leftJoin('m.payments', 'p')
            ->addSelect('p')
            ->where('m.workflowState = :state')
            ->setParameter('state', $state)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'm');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find all manifests with pagination and eager loading
     */
    public function findAllWithRelations(int $page = 1, int $limit = 20, ?int $shippingLineId = null): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->leftJoin('m.createdBy', 'cb')
            ->addSelect('cb')
            ->orderBy('m.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'm');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find manifests by shipping line
     */
    public function findByShippingLine(int $shippingLineId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'm');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find active manifests for broker (not archived)
     */
    public function findActiveBrokerManifests(int $brokerId, int $consigneeId): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->where('m.broker = :brokerId')
            ->andWhere('m.consignee = :consigneeId')
            ->andWhere('m.archivedForBroker = false')
            ->andWhere('m.workflowState NOT IN (:completedStates)')
            ->setParameter('brokerId', $brokerId)
            ->setParameter('consigneeId', $consigneeId)
            ->setParameter('completedStates', [
                WorkflowState::EDO_RELEASED->value,
            ])
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find completed manifests for broker
     */
    public function findCompletedBrokerManifests(int $brokerId, int $consigneeId): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->where('m.broker = :brokerId')
            ->andWhere('m.consignee = :consigneeId')
            ->andWhere('m.workflowState IN (:completedStates)')
            ->setParameter('brokerId', $brokerId)
            ->setParameter('consigneeId', $consigneeId)
            ->setParameter('completedStates', [
                WorkflowState::EDO_RELEASED->value
            ])
            ->orderBy('m.completedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find manifests with inactive broker for a consignee
     */
    public function findConsigneeManifestsWithInactiveBroker(int $consigneeId): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.broker', 'b')
            ->addSelect('b')
            ->where('m.consignee = :consigneeId')
            ->andWhere('b.isActive = false')
            ->andWhere('m.workflowState NOT IN (:completedStates)')
            ->setParameter('consigneeId', $consigneeId)
            ->setParameter('completedStates', [
                WorkflowState::EDO_RELEASED->value,
            ])
            ->orderBy('m.brokerInactiveSince', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active manifests for a deactivated broker
     */
    public function findActiveManifestsForBroker(int $brokerId): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->addSelect('c')
            ->where('m.broker = :brokerId')
            ->andWhere('m.workflowState NOT IN (:completedStates)')
            ->setParameter('brokerId', $brokerId)
            ->setParameter('completedStates', [
                WorkflowState::EDO_RELEASED->value,
            ])
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count manifests by broker and consignee
     */
    public function countByBrokerAndConsignee(int $brokerId, int $consigneeId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.broker = :brokerId')
            ->andWhere('m.consignee = :consigneeId')
            ->setParameter('brokerId', $brokerId)
            ->setParameter('consigneeId', $consigneeId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
