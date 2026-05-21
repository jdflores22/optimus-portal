<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\NotificationDeliveryLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for monitoring notification delivery status and generating statistics
 * Implements Requirements 8.1, 8.2, 8.3, 8.4
 */
class NotificationMonitoringService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Log a notification delivery attempt
     */
    public function logNotificationAttempt(
        Container $container,
        User $recipient,
        string $notificationType,
        string $channel,
        bool $success,
        ?string $errorMessage = null
    ): NotificationDeliveryLog {
        $log = new NotificationDeliveryLog();
        $log->setContainer($container);
        $log->setRecipient($recipient);
        $log->setNotificationType($notificationType);
        $log->setChannel($channel);
        $log->incrementAttemptCount();

        if ($success) {
            $log->markAsDelivered();
        } else {
            $log->markAsFailed($errorMessage ?? 'Unknown error');
        }

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->logger->info('Notification delivery logged', [
            'container_id' => $container->getId(),
            'notification_type' => $notificationType,
            'channel' => $channel,
            'success' => $success
        ]);

        return $log;
    }

    /**
     * Get notification delivery statistics
     */
    public function getDeliveryStatistics(?\DateTime $fromDate = null, ?\DateTime $toDate = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl');

        if ($fromDate) {
            $qb->andWhere('ndl.createdAt >= :fromDate')
               ->setParameter('fromDate', $fromDate);
        }

        if ($toDate) {
            $qb->andWhere('ndl.createdAt <= :toDate')
               ->setParameter('toDate', $toDate);
        }

        $logs = $qb->getQuery()->getResult();

        $stats = [
            'total_notifications' => count($logs),
            'delivered' => 0,
            'failed' => 0,
            'pending' => 0,
            'retrying' => 0,
            'by_channel' => [
                'email' => ['total' => 0, 'delivered' => 0, 'failed' => 0],
                'sms' => ['total' => 0, 'delivered' => 0, 'failed' => 0],
                'in_app' => ['total' => 0, 'delivered' => 0, 'failed' => 0]
            ],
            'by_type' => [],
            'success_rate' => 0,
            'average_attempts' => 0
        ];

        $totalAttempts = 0;

        foreach ($logs as $log) {
            $status = $log->getDeliveryStatus();
            $channel = $log->getChannel();
            $type = $log->getNotificationType();

            // Count by status
            $stats[$status] = ($stats[$status] ?? 0) + 1;

            // Count by channel
            if (isset($stats['by_channel'][$channel])) {
                $stats['by_channel'][$channel]['total']++;
                if ($status === 'delivered') {
                    $stats['by_channel'][$channel]['delivered']++;
                } elseif ($status === 'failed') {
                    $stats['by_channel'][$channel]['failed']++;
                }
            }

            // Count by type
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = ['total' => 0, 'delivered' => 0, 'failed' => 0];
            }
            $stats['by_type'][$type]['total']++;
            if ($status === 'delivered') {
                $stats['by_type'][$type]['delivered']++;
            } elseif ($status === 'failed') {
                $stats['by_type'][$type]['failed']++;
            }

            $totalAttempts += $log->getAttemptCount();
        }

        // Calculate success rate
        if ($stats['total_notifications'] > 0) {
            $stats['success_rate'] = round(($stats['delivered'] / $stats['total_notifications']) * 100, 2);
            $stats['average_attempts'] = round($totalAttempts / $stats['total_notifications'], 2);
        }

        return $stats;
    }

    /**
     * Get pending notifications
     */
    public function getPendingNotifications(int $limit = 50, int $offset = 0): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->where('ndl.deliveryStatus IN (:statuses)')
            ->setParameter('statuses', ['pending', 'retrying'])
            ->orderBy('ndl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $logs = $qb->getQuery()->getResult();

        return array_map(function(NotificationDeliveryLog $log) {
            return $this->formatNotificationLog($log);
        }, $logs);
    }

    /**
     * Get delivered notifications
     */
    public function getDeliveredNotifications(int $limit = 50, int $offset = 0): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->where('ndl.deliveryStatus = :status')
            ->setParameter('status', 'delivered')
            ->orderBy('ndl.deliveredAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $logs = $qb->getQuery()->getResult();

        return array_map(function(NotificationDeliveryLog $log) {
            return $this->formatNotificationLog($log);
        }, $logs);
    }

    /**
     * Get failed notifications
     */
    public function getFailedNotifications(int $limit = 50, int $offset = 0): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->where('ndl.deliveryStatus = :status')
            ->setParameter('status', 'failed')
            ->orderBy('ndl.lastAttemptAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $logs = $qb->getQuery()->getResult();

        return array_map(function(NotificationDeliveryLog $log) {
            return $this->formatNotificationLog($log);
        }, $logs);
    }

    /**
     * Search notifications by container number
     */
    public function searchByContainerNumber(string $containerNumber, int $limit = 50): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->join('ndl.container', 'c')
            ->where('c.containerNumber LIKE :containerNumber')
            ->setParameter('containerNumber', '%' . $containerNumber . '%')
            ->orderBy('ndl.createdAt', 'DESC')
            ->setMaxResults($limit);

        $logs = $qb->getQuery()->getResult();

        return array_map(function(NotificationDeliveryLog $log) {
            return $this->formatNotificationLog($log);
        }, $logs);
    }

    /**
     * Filter notifications by criteria
     */
    public function filterNotifications(array $criteria): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->join('ndl.container', 'c');

        // Apply filters
        if (isset($criteria['delivery_status'])) {
            $qb->andWhere('ndl.deliveryStatus = :status')
               ->setParameter('status', $criteria['delivery_status']);
        }

        if (isset($criteria['notification_type'])) {
            $qb->andWhere('ndl.notificationType = :type')
               ->setParameter('type', $criteria['notification_type']);
        }

        if (isset($criteria['channel'])) {
            $qb->andWhere('ndl.channel = :channel')
               ->setParameter('channel', $criteria['channel']);
        }

        if (isset($criteria['container_number'])) {
            $qb->andWhere('c.containerNumber LIKE :containerNumber')
               ->setParameter('containerNumber', '%' . $criteria['container_number'] . '%');
        }

        if (isset($criteria['from_date'])) {
            $qb->andWhere('ndl.createdAt >= :fromDate')
               ->setParameter('fromDate', $criteria['from_date']);
        }

        if (isset($criteria['to_date'])) {
            $qb->andWhere('ndl.createdAt <= :toDate')
               ->setParameter('toDate', $criteria['to_date']);
        }

        // Pagination
        $limit = $criteria['limit'] ?? 50;
        $offset = $criteria['offset'] ?? 0;
        $qb->setMaxResults($limit)->setFirstResult($offset);

        // Sorting
        $sortBy = $criteria['sort_by'] ?? 'createdAt';
        $sortOrder = $criteria['sort_order'] ?? 'DESC';
        $qb->orderBy('ndl.' . $sortBy, $sortOrder);

        $logs = $qb->getQuery()->getResult();

        return array_map(function(NotificationDeliveryLog $log) {
            return $this->formatNotificationLog($log);
        }, $logs);
    }

    /**
     * Count notifications by criteria
     */
    public function countNotifications(array $criteria = []): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(ndl.id)')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->join('ndl.container', 'c');

        // Apply same filters as filterNotifications
        if (isset($criteria['delivery_status'])) {
            $qb->andWhere('ndl.deliveryStatus = :status')
               ->setParameter('status', $criteria['delivery_status']);
        }

        if (isset($criteria['notification_type'])) {
            $qb->andWhere('ndl.notificationType = :type')
               ->setParameter('type', $criteria['notification_type']);
        }

        if (isset($criteria['channel'])) {
            $qb->andWhere('ndl.channel = :channel')
               ->setParameter('channel', $criteria['channel']);
        }

        if (isset($criteria['container_number'])) {
            $qb->andWhere('c.containerNumber LIKE :containerNumber')
               ->setParameter('containerNumber', '%' . $criteria['container_number'] . '%');
        }

        if (isset($criteria['from_date'])) {
            $qb->andWhere('ndl.createdAt >= :fromDate')
               ->setParameter('fromDate', $criteria['from_date']);
        }

        if (isset($criteria['to_date'])) {
            $qb->andWhere('ndl.createdAt <= :toDate')
               ->setParameter('toDate', $criteria['to_date']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get notifications for a specific container
     */
    public function getContainerNotifications(Container $container): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->where('ndl.container = :container')
            ->setParameter('container', $container)
            ->orderBy('ndl.createdAt', 'DESC');

        $logs = $qb->getQuery()->getResult();

        return array_map(function(NotificationDeliveryLog $log) {
            return $this->formatNotificationLog($log);
        }, $logs);
    }

    /**
     * Check for failed deliveries that need alerting
     */
    public function getFailedDeliveriesForAlerting(int $thresholdMinutes = 30): array
    {
        $thresholdDate = new \DateTime();
        $thresholdDate->modify("-{$thresholdMinutes} minutes");

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ndl')
            ->from(NotificationDeliveryLog::class, 'ndl')
            ->where('ndl.deliveryStatus = :status')
            ->andWhere('ndl.lastAttemptAt <= :threshold')
            ->setParameter('status', 'failed')
            ->setParameter('threshold', $thresholdDate)
            ->orderBy('ndl.lastAttemptAt', 'ASC');

        $logs = $qb->getQuery()->getResult();

        return array_map(function(NotificationDeliveryLog $log) {
            return $this->formatNotificationLog($log);
        }, $logs);
    }

    /**
     * Format notification log for API response
     */
    private function formatNotificationLog(NotificationDeliveryLog $log): array
    {
        return [
            'id' => $log->getId(),
            'container' => [
                'id' => $log->getContainer()->getId(),
                'container_number' => $log->getContainer()->getContainerNumber()
            ],
            'notification_type' => $log->getNotificationType(),
            'delivery_status' => $log->getDeliveryStatus(),
            'channel' => $log->getChannel(),
            'recipient' => [
                'id' => $log->getRecipient()->getId(),
                'email' => $log->getRecipient()->getEmail()
            ],
            'created_at' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
            'delivered_at' => $log->getDeliveredAt()?->format('Y-m-d H:i:s'),
            'attempt_count' => $log->getAttemptCount(),
            'last_attempt_at' => $log->getLastAttemptAt()?->format('Y-m-d H:i:s'),
            'error_message' => $log->getErrorMessage(),
            'metadata' => $log->getMetadata()
        ];
    }
}
