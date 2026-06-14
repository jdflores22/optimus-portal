<?php

namespace App\Repository;

use App\Entity\NotificationMetrics;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationMetrics>
 */
class NotificationMetricsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationMetrics::class);
    }

    /**
     * Get delivery rate for a specific notification type within a date range
     */
    public function getDeliveryRate(string $notificationType, \DateTimeInterface $startDate, \DateTimeInterface $endDate): float
    {
        $qb = $this->createQueryBuilder('nm');
        
        $total = $qb->select('COUNT(nm.id)')
            ->where('nm.notificationType = :type')
            ->andWhere('nm.sentAt BETWEEN :start AND :end')
            ->setParameter('type', $notificationType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
        
        if ($total == 0) {
            return 100.0;
        }
        
        $qb2 = $this->createQueryBuilder('nm');
        $delivered = $qb2->select('COUNT(nm.id)')
            ->where('nm.notificationType = :type')
            ->andWhere('nm.sentAt BETWEEN :start AND :end')
            ->andWhere('nm.deliveryStatus IN (:statuses)')
            ->setParameter('type', $notificationType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['delivered', 'opened'])
            ->getQuery()
            ->getSingleScalarResult();
        
        return ($delivered / $total) * 100;
    }

    /**
     * Get overall delivery rate within a date range
     */
    public function getOverallDeliveryRate(\DateTimeInterface $startDate, \DateTimeInterface $endDate): float
    {
        $qb = $this->createQueryBuilder('nm');
        
        $total = $qb->select('COUNT(nm.id)')
            ->where('nm.sentAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
        
        if ($total == 0) {
            return 100.0;
        }
        
        $qb2 = $this->createQueryBuilder('nm');
        $delivered = $qb2->select('COUNT(nm.id)')
            ->where('nm.sentAt BETWEEN :start AND :end')
            ->andWhere('nm.deliveryStatus IN (:statuses)')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['delivered', 'opened'])
            ->getQuery()
            ->getSingleScalarResult();
        
        return ($delivered / $total) * 100;
    }

    /**
     * Get open rate for a specific notification type within a date range
     */
    public function getOpenRate(string $notificationType, \DateTimeInterface $startDate, \DateTimeInterface $endDate): float
    {
        $qb = $this->createQueryBuilder('nm');
        
        $delivered = $qb->select('COUNT(nm.id)')
            ->where('nm.notificationType = :type')
            ->andWhere('nm.sentAt BETWEEN :start AND :end')
            ->andWhere('nm.deliveryStatus IN (:statuses)')
            ->setParameter('type', $notificationType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('statuses', ['delivered', 'opened'])
            ->getQuery()
            ->getSingleScalarResult();
        
        if ($delivered == 0) {
            return 0.0;
        }
        
        $qb2 = $this->createQueryBuilder('nm');
        $opened = $qb2->select('COUNT(nm.id)')
            ->where('nm.notificationType = :type')
            ->andWhere('nm.sentAt BETWEEN :start AND :end')
            ->andWhere('nm.deliveryStatus = :status')
            ->setParameter('type', $notificationType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'opened')
            ->getQuery()
            ->getSingleScalarResult();
        
        return ($opened / $delivered) * 100;
    }

    /**
     * Get failure rate for a specific notification type within a date range
     */
    public function getFailureRate(string $notificationType, \DateTimeInterface $startDate, \DateTimeInterface $endDate): float
    {
        $qb = $this->createQueryBuilder('nm');
        
        $total = $qb->select('COUNT(nm.id)')
            ->where('nm.notificationType = :type')
            ->andWhere('nm.sentAt BETWEEN :start AND :end')
            ->setParameter('type', $notificationType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
        
        if ($total == 0) {
            return 0.0;
        }
        
        $qb2 = $this->createQueryBuilder('nm');
        $failed = $qb2->select('COUNT(nm.id)')
            ->where('nm.notificationType = :type')
            ->andWhere('nm.sentAt BETWEEN :start AND :end')
            ->andWhere('nm.deliveryStatus = :status')
            ->setParameter('type', $notificationType)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('status', 'failed')
            ->getQuery()
            ->getSingleScalarResult();
        
        return ($failed / $total) * 100;
    }

    /**
     * Get metrics summary by notification type within a date range
     */
    public function getMetricsSummaryByType(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $qb = $this->createQueryBuilder('nm');
        
        $results = $qb->select('nm.notificationType as type')
            ->addSelect('COUNT(nm.id) as total')
            ->addSelect('SUM(CASE WHEN nm.deliveryStatus IN (:delivered_statuses) THEN 1 ELSE 0 END) as delivered')
            ->addSelect('SUM(CASE WHEN nm.deliveryStatus = :opened_status THEN 1 ELSE 0 END) as opened')
            ->addSelect('SUM(CASE WHEN nm.deliveryStatus = :failed_status THEN 1 ELSE 0 END) as failed')
            ->where('nm.sentAt BETWEEN :start AND :end')
            ->groupBy('nm.notificationType')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('delivered_statuses', ['delivered', 'opened'])
            ->setParameter('opened_status', 'opened')
            ->setParameter('failed_status', 'failed')
            ->getQuery()
            ->getResult();
        
        // Calculate rates
        foreach ($results as &$result) {
            $total = (int) $result['total'];
            $delivered = (int) $result['delivered'];
            $opened = (int) $result['opened'];
            $failed = (int) $result['failed'];

            $result['total'] = $total;
            $result['delivered'] = $delivered;
            $result['opened'] = $opened;
            $result['failed'] = $failed;
            $result['delivery_rate'] = $total > 0 ? ($delivered / $total) * 100 : 0;
            $result['open_rate'] = $delivered > 0 ? ($opened / $delivered) * 100 : 0;
            $result['failure_rate'] = $total > 0 ? ($failed / $total) * 100 : 0;
        }
        
        return $results;
    }

    /**
     * Get metrics trend over time (daily aggregation)
     */
    public function getMetricsTrend(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT DATE(sent_at) AS date,
                   COUNT(id) AS total,
                   SUM(CASE WHEN delivery_status IN ('delivered', 'opened') THEN 1 ELSE 0 END) AS delivered,
                   SUM(CASE WHEN delivery_status = 'opened' THEN 1 ELSE 0 END) AS opened,
                   SUM(CASE WHEN delivery_status = 'failed' THEN 1 ELSE 0 END) AS failed
            FROM notification_metrics
            WHERE sent_at BETWEEN :start AND :end
            GROUP BY DATE(sent_at)
            ORDER BY date ASC
            SQL;

        $rows = $conn->fetchAllAssociative($sql, [
            'start' => $startDate->format('Y-m-d H:i:s'),
            'end' => $endDate->format('Y-m-d H:i:s'),
        ]);

        foreach ($rows as &$row) {
            if ($row['date'] instanceof \DateTimeInterface) {
                $row['date'] = $row['date']->format('Y-m-d');
            } elseif (is_string($row['date']) && strlen($row['date']) > 10) {
                $row['date'] = substr($row['date'], 0, 10);
            }
            $row['total'] = (int) $row['total'];
            $row['delivered'] = (int) $row['delivered'];
            $row['opened'] = (int) $row['opened'];
            $row['failed'] = (int) $row['failed'];
        }

        return $rows;
    }

    /**
     * Delete metrics older than the specified date
     */
    public function deleteOlderThan(\DateTimeInterface $date): int
    {
        $qb = $this->createQueryBuilder('nm');
        
        return $qb->delete()
            ->where('nm.sentAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }

    /**
     * Get metrics for a specific user within a date range
     */
    public function getMetricsByUser(User $user, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->createQueryBuilder('nm')
            ->where('nm.user = :user')
            ->andWhere('nm.sentAt BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('nm.sentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
