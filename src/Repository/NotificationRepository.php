<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    use ShippingLineFilterTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Get unread notifications count for a user
     */
    public function getUnreadCount(User $user, ?int $shippingLineId = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user);

        $this->applyShippingLineFilter($qb, $shippingLineId, 'n');

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get recent notifications for a user with pagination
     */
    public function getRecentNotifications(User $user, int $limit = 10, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'n');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get paginated notifications for a user
     */
    public function getPaginatedNotifications(User $user, int $page = 1, int $limit = 20, ?int $shippingLineId = null): array
    {
        $offset = ($page - 1) * $limit;
        
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'n');

        $notifications = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->setParameter('user', $user);

        $this->applyShippingLineFilter($countQb, $shippingLineId, 'n');

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'notifications' => $notifications,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ];
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnreadNotifications(User $user, int $limit = 10, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'n');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find notifications by shipping line
     */
    public function findByShippingLine(int $shippingLineId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('n')
            ->leftJoin('n.user', 'u')
            ->addSelect('u')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilterWithEagerLoad($qb, $shippingLineId, 'n');

        return $qb->getQuery()->getResult();
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', 'true')
            ->set('n.readAt', ':now')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }

    /**
     * Delete old read notifications (older than 30 days)
     */
    public function deleteOldNotifications(): int
    {
        $thirtyDaysAgo = new \DateTime('-30 days');
        
        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.isRead = true')
            ->andWhere('n.readAt < :date')
            ->setParameter('date', $thirtyDaysAgo)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete notifications older than 90 days (retention policy)
     * Per Requirement 8.6: PWA SHALL retain notification history for 90 days
     */
    public function deleteNotificationsOlderThan90Days(): int
    {
        $ninetyDaysAgo = new \DateTime('-90 days');
        
        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.createdAt < :date')
            ->setParameter('date', $ninetyDaysAgo)
            ->getQuery()
            ->execute();
    }
}