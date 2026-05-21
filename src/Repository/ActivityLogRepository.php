<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use App\Entity\ShippingLine;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * Find activity logs for a specific user
     */
    public function findByUser(User $user, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('al')
            ->where('al.user = :user')
            ->setParameter('user', $user)
            ->orderBy('al.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find activity logs within a shipping line scope
     */
    public function findByShippingLineScope(?ShippingLine $shippingLine, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('al')
            ->orderBy('al.createdAt', 'DESC');

        if ($shippingLine !== null) {
            $qb->where('al.shippingLine = :shippingLine')
              ->setParameter('shippingLine', $shippingLine);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find activity logs by activity type
     */
    public function findByActivityType(string $activityType, ?ShippingLine $scope = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('al')
            ->where('al.activityType = :activityType')
            ->setParameter('activityType', $activityType)
            ->orderBy('al.createdAt', 'DESC');

        if ($scope !== null) {
            $qb->andWhere('al.shippingLine = :scope')
               ->setParameter('scope', $scope);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find activity logs within date range
     */
    public function findByDateRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?ShippingLine $scope = null,
        ?string $activityType = null
    ): array {
        $qb = $this->createQueryBuilder('al')
            ->where('al.createdAt >= :from')
            ->andWhere('al.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('al.createdAt', 'DESC');

        if ($scope !== null) {
            $qb->andWhere('al.shippingLine = :scope')
               ->setParameter('scope', $scope);
        }

        if ($activityType !== null) {
            $qb->andWhere('al.activityType = :activityType')
               ->setParameter('activityType', $activityType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find security-related activity logs
     */
    public function findSecurityActivities(?ShippingLine $scope = null, ?int $limit = null): array
    {
        $securityTypes = [
            ActivityLog::TYPE_LOGIN,
            ActivityLog::TYPE_LOGOUT,
            ActivityLog::TYPE_FAILED_LOGIN,
            ActivityLog::TYPE_ACCESS_DENIED,
            ActivityLog::TYPE_SUSPICIOUS_ACTIVITY,
            ActivityLog::TYPE_PRIVILEGE_ESCALATION_ATTEMPT,
        ];

        $qb = $this->createQueryBuilder('al')
            ->where('al.activityType IN (:securityTypes)')
            ->setParameter('securityTypes', $securityTypes)
            ->orderBy('al.createdAt', 'DESC');

        if ($scope !== null) {
            $qb->andWhere('al.shippingLine = :scope')
               ->setParameter('scope', $scope);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find failed login attempts for a specific IP
     */
    public function findFailedLoginsByIp(string $ipAddress, \DateTimeInterface $since): array
    {
        return $this->createQueryBuilder('al')
            ->where('al.activityType = :activityType')
            ->andWhere('al.ipAddress = :ipAddress')
            ->andWhere('al.createdAt >= :since')
            ->setParameter('activityType', ActivityLog::TYPE_FAILED_LOGIN)
            ->setParameter('ipAddress', $ipAddress)
            ->setParameter('since', $since)
            ->orderBy('al.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get activity statistics for a shipping line
     */
    public function getActivityStatistics(?ShippingLine $shippingLine = null): array
    {
        $qb = $this->createQueryBuilder('al')
            ->select('al.activityType, COUNT(al.id) as count')
            ->groupBy('al.activityType')
            ->orderBy('count', 'DESC');

        if ($shippingLine !== null) {
            $qb->where('al.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }

        $results = $qb->getQuery()->getResult();
        
        $statistics = [];
        foreach ($results as $result) {
            $statistics[$result['activityType']] = (int) $result['count'];
        }

        return $statistics;
    }

    /**
     * Get user activity summary
     */
    public function getUserActivitySummary(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('al')
            ->select('al.activityType, COUNT(al.id) as count, MAX(al.createdAt) as lastActivity')
            ->where('al.user = :user')
            ->andWhere('al.createdAt >= :from')
            ->andWhere('al.createdAt <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('al.activityType')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search activity logs with filters
     */
    public function searchWithFilters(array $filters): array
    {
        $qb = $this->createQueryBuilder('al')
            ->orderBy('al.createdAt', 'DESC');

        if (isset($filters['user_id'])) {
            $qb->andWhere('al.user = :userId')
               ->setParameter('userId', $filters['user_id']);
        }

        if (isset($filters['shipping_line_id'])) {
            $qb->andWhere('al.shippingLine = :shippingLineId')
               ->setParameter('shippingLineId', $filters['shipping_line_id']);
        }

        if (isset($filters['activity_type'])) {
            $qb->andWhere('al.activityType = :activityType')
               ->setParameter('activityType', $filters['activity_type']);
        }

        if (isset($filters['entity_type'])) {
            $qb->andWhere('al.entityType = :entityType')
               ->setParameter('entityType', $filters['entity_type']);
        }

        if (isset($filters['from_date'])) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $filters['to_date']);
        }

        if (isset($filters['ip_address'])) {
            $qb->andWhere('al.ipAddress = :ipAddress')
               ->setParameter('ipAddress', $filters['ip_address']);
        }

        if (isset($filters['limit'])) {
            $qb->setMaxResults($filters['limit']);
        }

        return $qb->getQuery()->getResult();
    }

    public function save(ActivityLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ActivityLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find activity logs with comprehensive filtering support for API
     */
    public function findWithFilters(
        ?ShippingLine $shippingLineScope = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        array $filters = [],
        int $limit = 20,
        int $offset = 0
    ): array {
        $qb = $this->createQueryBuilder('al')
            ->leftJoin('al.user', 'u')
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Apply shipping line scope restriction
        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        // Apply date range filters
        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        // Apply additional filters
        if (isset($filters['activity_type'])) {
            $qb->andWhere('al.activityType = :activityType')
               ->setParameter('activityType', $filters['activity_type']);
        }

        if (isset($filters['entity_type'])) {
            $qb->andWhere('al.entityType = :entityType')
               ->setParameter('entityType', $filters['entity_type']);
        }

        if (isset($filters['user_id'])) {
            $qb->andWhere('al.user = :userId')
               ->setParameter('userId', $filters['user_id']);
        }

        if (isset($filters['entity_id'])) {
            $qb->andWhere('al.entityId = :entityId')
               ->setParameter('entityId', $filters['entity_id']);
        }

        if (isset($filters['ip_address'])) {
            $qb->andWhere('al.ipAddress = :ipAddress')
               ->setParameter('ipAddress', $filters['ip_address']);
        }

        if (isset($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $qb->andWhere('(al.activityDescription LIKE :searchTerm OR u.email LIKE :searchTerm OR al.entityType LIKE :searchTerm)')
               ->setParameter('searchTerm', $searchTerm);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count activity logs with comprehensive filtering support for API
     */
    public function countWithFilters(
        ?ShippingLine $shippingLineScope = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        array $filters = []
    ): int {
        $qb = $this->createQueryBuilder('al')
            ->select('COUNT(al.id)')
            ->leftJoin('al.user', 'u');

        // Apply shipping line scope restriction
        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        // Apply date range filters
        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        // Apply additional filters
        if (isset($filters['activity_type'])) {
            $qb->andWhere('al.activityType = :activityType')
               ->setParameter('activityType', $filters['activity_type']);
        }

        if (isset($filters['entity_type'])) {
            $qb->andWhere('al.entityType = :entityType')
               ->setParameter('entityType', $filters['entity_type']);
        }

        if (isset($filters['user_id'])) {
            $qb->andWhere('al.user = :userId')
               ->setParameter('userId', $filters['user_id']);
        }

        if (isset($filters['entity_id'])) {
            $qb->andWhere('al.entityId = :entityId')
               ->setParameter('entityId', $filters['entity_id']);
        }

        if (isset($filters['ip_address'])) {
            $qb->andWhere('al.ipAddress = :ipAddress')
               ->setParameter('ipAddress', $filters['ip_address']);
        }

        if (isset($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $qb->andWhere('(al.activityDescription LIKE :searchTerm OR u.email LIKE :searchTerm OR al.entityType LIKE :searchTerm)')
               ->setParameter('searchTerm', $searchTerm);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get distinct entity types for filtering
     */
    public function getDistinctEntityTypes(?ShippingLine $shippingLineScope = null): array
    {
        $qb = $this->createQueryBuilder('al')
            ->select('DISTINCT al.entityType')
            ->where('al.entityType IS NOT NULL')
            ->orderBy('al.entityType', 'ASC');

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        $results = $qb->getQuery()->getResult();
        return array_column($results, 'entityType');
    }

    /**
     * Generate summary report data
     */
    public function generateSummaryReport(
        ?ShippingLine $shippingLineScope = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        // Total activities count
        $totalActivities = $this->countWithFilters($shippingLineScope, $from, $to);

        // Unique users count
        $qb = $this->createQueryBuilder('al')
            ->select('COUNT(DISTINCT al.user)')
            ->where('1=1');

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        $uniqueUsers = (int) $qb->getQuery()->getSingleScalarResult();

        // Activity breakdown by type
        $qb = $this->createQueryBuilder('al')
            ->select('al.activityType, COUNT(al.id) as count')
            ->groupBy('al.activityType')
            ->orderBy('count', 'DESC');

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        $activityBreakdown = $qb->getQuery()->getResult();

        return [
            'totalActivities' => $totalActivities,
            'uniqueUsers' => $uniqueUsers,
            'activityBreakdown' => $activityBreakdown
        ];
    }

    /**
     * Generate security report data
     */
    public function generateSecurityReport(
        ?ShippingLine $shippingLineScope = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        $securityTypes = [
            ActivityLog::TYPE_LOGIN,
            ActivityLog::TYPE_LOGOUT,
            ActivityLog::TYPE_FAILED_LOGIN,
            ActivityLog::TYPE_ACCESS_DENIED,
            ActivityLog::TYPE_SUSPICIOUS_ACTIVITY,
            ActivityLog::TYPE_PRIVILEGE_ESCALATION_ATTEMPT,
        ];

        // Security events count by type
        $qb = $this->createQueryBuilder('al')
            ->select('al.activityType, COUNT(al.id) as count')
            ->where('al.activityType IN (:securityTypes)')
            ->setParameter('securityTypes', $securityTypes)
            ->groupBy('al.activityType')
            ->orderBy('count', 'DESC');

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        $securityCounts = $qb->getQuery()->getResult();

        // Total security events
        $totalSecurityEvents = array_sum(array_column($securityCounts, 'count'));

        // Recent security events
        $qb = $this->createQueryBuilder('al')
            ->where('al.activityType IN (:securityTypes)')
            ->setParameter('securityTypes', $securityTypes)
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults(50);

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        $securityEvents = $qb->getQuery()->getResult();

        return [
            'totalSecurityEvents' => $totalSecurityEvents,
            'securityCounts' => $securityCounts,
            'securityEvents' => $securityEvents
        ];
    }

    /**
     * Generate user activity report data
     */
    public function generateUserActivityReport(
        ?ShippingLine $shippingLineScope = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        $qb = $this->createQueryBuilder('al')
            ->select('u.id, u.email, u.role, COUNT(al.id) as activityCount')
            ->join('al.user', 'u')
            ->groupBy('u.id, u.email, u.role')
            ->orderBy('activityCount', 'DESC')
            ->setMaxResults(100);

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        $userActivities = $qb->getQuery()->getResult();

        return [
            'userActivities' => $userActivities
        ];
    }

    /**
     * Generate business operations report data
     */
    public function generateBusinessOperationsReport(
        ?ShippingLine $shippingLineScope = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        $businessTypes = [
            ActivityLog::TYPE_PRE_ADVICE_CREATION,
            ActivityLog::TYPE_PAYMENT_PROCESSING,
            ActivityLog::TYPE_DOCUMENT_UPLOAD,
            ActivityLog::TYPE_STATUS_CHANGE,
            ActivityLog::TYPE_CONTAINER_ASSIGNMENT,
            ActivityLog::TYPE_TERMINAL_ALLOCATION,
            ActivityLog::TYPE_CONTAINER_MOVEMENT,
            ActivityLog::TYPE_YARD_ASSIGNMENT,
            ActivityLog::TYPE_PORT_TERMINAL_ASSIGNMENT,
        ];

        // Business operations count by type
        $qb = $this->createQueryBuilder('al')
            ->select('al.activityType, COUNT(al.id) as count')
            ->where('al.activityType IN (:businessTypes)')
            ->setParameter('businessTypes', $businessTypes)
            ->groupBy('al.activityType')
            ->orderBy('count', 'DESC');

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        $businessCounts = $qb->getQuery()->getResult();

        // Total business events
        $totalBusinessEvents = array_sum(array_column($businessCounts, 'count'));

        // Recent business events
        $qb = $this->createQueryBuilder('al')
            ->where('al.activityType IN (:businessTypes)')
            ->setParameter('businessTypes', $businessTypes)
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults(50);

        if ($shippingLineScope !== null) {
            $qb->andWhere('al.shippingLine = :shippingLineScope')
               ->setParameter('shippingLineScope', $shippingLineScope);
        }

        if ($from !== null) {
            $qb->andWhere('al.createdAt >= :fromDate')
               ->setParameter('fromDate', $from);
        }

        if ($to !== null) {
            $qb->andWhere('al.createdAt <= :toDate')
               ->setParameter('toDate', $to);
        }

        $businessEvents = $qb->getQuery()->getResult();

        return [
            'totalBusinessEvents' => $totalBusinessEvents,
            'businessCounts' => $businessCounts,
            'businessEvents' => $businessEvents
        ];
    }
}