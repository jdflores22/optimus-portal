<?php

namespace App\Repository;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectronicDeliveryOrder>
 */
class ElectronicDeliveryOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectronicDeliveryOrder::class);
    }

    /**
     * Get pending eDO releases with pagination
     * Uses eager loading to prevent N+1 query problems
     */
    public function getPendingReleases(int $page = 1, int $perPage = 20): array
    {
        $qb = $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('edo.edoPayment', 'p')
            ->leftJoin('edo.releasedBy', 'rb')
            ->leftJoin('m.broker', 'broker')
            ->leftJoin('m.consignee', 'consignee')
            ->addSelect('m', 'c', 'p', 'rb', 'broker', 'consignee')
            ->where('edo.status = :status')
            ->setParameter('status', EDOStatus::PENDING_RELEASE)
            ->orderBy('edo.generatedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    /**
     * Count pending eDO releases
     */
    public function countPendingReleases(): int
    {
        return $this->createQueryBuilder('edo')
            ->select('COUNT(edo.id)')
            ->where('edo.status = :status')
            ->setParameter('status', EDOStatus::PENDING_RELEASE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find eDO by ID with related entities
     * Uses eager loading to prevent N+1 query problems
     */
    public function findWithRelations(int $id): ?ElectronicDeliveryOrder
    {
        return $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('edo.edoPayment', 'p')
            ->leftJoin('edo.releasedBy', 'rb')
            ->leftJoin('m.broker', 'broker')
            ->leftJoin('m.consignee', 'consignee')
            ->addSelect('m', 'c', 'p', 'rb', 'broker', 'consignee')
            ->where('edo.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find expired eDOs using database-level date comparison
     * Uses composite index on (status, expires_at) for optimal performance
     * 
     * @param int $limit Maximum number of results
     * @param int $offset Starting offset for pagination
     * @return ElectronicDeliveryOrder[]
     */
    public function findExpiredEDOs(int $limit = 100, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('edo')
            ->where('edo.status = :status')
            ->andWhere('edo.expiresAt IS NOT NULL')
            ->andWhere('edo.expiresAt < :now')
            ->setParameter('status', EDOStatus::RELEASED)
            ->setParameter('now', new \DateTime('now', new \DateTimeZone('UTC')))
            ->orderBy('edo.expiresAt', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Count total expired eDOs
     * 
     * @return int
     */
    public function countExpiredEDOs(): int
    {
        return (int) $this->createQueryBuilder('edo')
            ->select('COUNT(edo.id)')
            ->where('edo.status = :status')
            ->andWhere('edo.expiresAt IS NOT NULL')
            ->andWhere('edo.expiresAt < :now')
            ->setParameter('status', EDOStatus::RELEASED)
            ->setParameter('now', new \DateTime('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find eDOs by container with eager loading
     * Optimized for list views to prevent N+1 queries
     * 
     * @param int $containerId
     * @return ElectronicDeliveryOrder[]
     */
    public function findByContainerWithRelations(int $containerId): array
    {
        return $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('edo.releasedBy', 'rb')
            ->leftJoin('m.broker', 'broker')
            ->leftJoin('m.consignee', 'consignee')
            ->addSelect('m', 'c', 'rb', 'broker', 'consignee')
            ->where('edo.container = :containerId')
            ->setParameter('containerId', $containerId)
            ->orderBy('edo.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find eDOs by status with eager loading
     * Optimized for dashboard and list views
     * 
     * @param EDOStatus $status
     * @param int $limit
     * @param int $offset
     * @return ElectronicDeliveryOrder[]
     */
    public function findByStatusWithRelations(EDOStatus $status, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('edo.releasedBy', 'rb')
            ->leftJoin('m.broker', 'broker')
            ->leftJoin('m.consignee', 'consignee')
            ->addSelect('m', 'c', 'rb', 'broker', 'consignee')
            ->where('edo.status = :status')
            ->setParameter('status', $status)
            ->orderBy('edo.generatedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find eDO by number with eager loading
     * 
     * @param string $edoNumber
     * @return ElectronicDeliveryOrder|null
     */
    public function findByNumberWithRelations(string $edoNumber): ?ElectronicDeliveryOrder
    {
        return $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('edo.releasedBy', 'rb')
            ->leftJoin('m.broker', 'broker')
            ->leftJoin('m.consignee', 'consignee')
            ->addSelect('m', 'c', 'rb', 'broker', 'consignee')
            ->where('edo.edoNumber = :edoNumber')
            ->setParameter('edoNumber', $edoNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all eDOs for broker's manifests with optional status filter
     * Uses eager loading for manifest, container, and payment relationships
     * Prevents N+1 queries for list views
     * 
     * @param mixed $broker Broker user entity
     * @param EDOStatus|null $status Optional status filter
     * @return ElectronicDeliveryOrder[]
     */
    public function findByBrokerWithPayments($broker, ?EDOStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('edo.payments', 'p')
            ->leftJoin('edo.releasedBy', 'rb')
            ->leftJoin('m.broker', 'broker')
            ->leftJoin('m.consignee', 'consignee')
            ->addSelect('m', 'c', 'p', 'rb', 'broker', 'consignee')
            ->where('m.broker = :broker')
            ->setParameter('broker', $broker)
            ->orderBy('edo.generatedAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('edo.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find eDO by ID with all related entities loaded
     * Uses eager loading to prevent N+1 queries
     * Includes manifest, container, payments, and user relationships
     * 
     * @param int $id
     * @return ElectronicDeliveryOrder|null
     */
    public function findOneWithRelations(int $id): ?ElectronicDeliveryOrder
    {
        return $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('edo.payments', 'p')
            ->leftJoin('edo.releasedBy', 'rb')
            ->leftJoin('m.broker', 'broker')
            ->leftJoin('m.consignee', 'consignee')
            ->leftJoin('p.submittedBy', 'ps')
            ->leftJoin('p.validatedBy', 'pv')
            ->addSelect('m', 'c', 'p', 'rb', 'broker', 'consignee', 'ps', 'pv')
            ->where('edo.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find expired eDOs with assigned terminals
     * Returns eDOs that are past their expiration date and have a terminal assigned
     * Used for renewal workflow eligibility checks
     * 
     * @return ElectronicDeliveryOrder[]
     */
    public function findExpiredEDOsWithTerminal(): array
    {
        return $this->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('c.terminal', 't')
            ->addSelect('m', 'c', 't')
            ->where('edo.expiresAt IS NOT NULL')
            ->andWhere('edo.expiresAt < :now')
            ->andWhere('t.id IS NOT NULL')
            ->setParameter('now', new \DateTime('now', new \DateTimeZone('UTC')))
            ->orderBy('edo.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
