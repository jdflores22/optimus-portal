<?php

namespace App\Repository;

use App\Entity\EDOAuditLog;
use App\Entity\Enum\AuditEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EDOAuditLog>
 */
class EDOAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EDOAuditLog::class);
    }

    /**
     * Query audit logs by container number
     * Uses eager loading to prevent N+1 query problems
     */
    public function queryByContainer(string $containerNumber): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.container', 'c')
            ->leftJoin('a.edo', 'e')
            ->leftJoin('a.user', 'u')
            ->addSelect('c', 'e', 'u')
            ->where('c.containerNumber = :containerNumber')
            ->setParameter('containerNumber', $containerNumber)
            ->orderBy('a.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Query audit logs by eDO number
     * Uses eager loading to prevent N+1 query problems
     */
    public function queryByEDO(string $edoNumber): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.edo', 'e')
            ->leftJoin('a.container', 'c')
            ->leftJoin('a.user', 'u')
            ->addSelect('e', 'c', 'u')
            ->where('e.edoNumber = :edoNumber')
            ->setParameter('edoNumber', $edoNumber)
            ->orderBy('a.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs by event type
     * Uses eager loading to prevent N+1 query problems
     */
    public function findByEventType(AuditEventType $eventType): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.edo', 'e')
            ->leftJoin('a.container', 'c')
            ->leftJoin('a.user', 'u')
            ->addSelect('e', 'c', 'u')
            ->where('a.eventType = :eventType')
            ->setParameter('eventType', $eventType->value)
            ->orderBy('a.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent audit logs
     * Uses eager loading to prevent N+1 query problems
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.edo', 'e')
            ->leftJoin('a.container', 'c')
            ->leftJoin('a.user', 'u')
            ->addSelect('e', 'c', 'u')
            ->orderBy('a.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Query audit logs by batch session ID
     * Uses eager loading to prevent N+1 query problems
     */
    public function queryByBatchSession(string $sessionId): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.edo', 'e')
            ->leftJoin('a.container', 'c')
            ->leftJoin('a.user', 'u')
            ->addSelect('e', 'c', 'u')
            ->where('a.batchSessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->orderBy('a.timestamp', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
