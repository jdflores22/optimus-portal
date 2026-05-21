<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationRepository $notificationRepository,
        private InAppNotificationService $inAppNotificationService
    ) {
    }

    #[Route('', name: 'notifications_page', methods: ['GET'])]
    public function page(): Response
    {
        return $this->render('notifications/index.html.twig');
    }

    #[Route('/api', name: 'notifications_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        $notifications = $this->notificationRepository->getRecentNotifications($user, 20);
        $unreadCount = $this->notificationRepository->getUnreadCount($user);

        $notificationData = [];
        foreach ($notifications as $notification) {
            $notificationData[] = [
                'id' => $notification->getId(),
                'title' => $notification->getTitle(),
                'message' => $notification->getMessage(),
                'type' => $notification->getType(),
                'isRead' => $notification->isRead(),
                'actionUrl' => $notification->getActionUrl(),
                'actionText' => $notification->getActionText(),
                'timeAgo' => $notification->getTimeAgo(),
                'createdAt' => $notification->getCreatedAt()->format('Y-m-d H:i:s')
            ];
        }

        return new JsonResponse([
            'success' => true,
            'notifications' => $notificationData,
            'unreadCount' => $unreadCount
        ]);
    }

    #[Route('/api/paginated', name: 'notifications_paginated', methods: ['GET'])]
    public function paginated(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'User not authenticated'], 401);
        }
        
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(10, (int) $request->query->get('limit', 20)));
        $filter = $request->query->get('filter', 'all'); // all, unread, read

        try {
            $queryBuilder = $this->notificationRepository->createQueryBuilder('n')
                ->where('n.user = :user')
                ->setParameter('user', $user)
                ->orderBy('n.createdAt', 'DESC');

            // Apply filter
            if ($filter === 'unread') {
                $queryBuilder->andWhere('n.isRead = false');
            } elseif ($filter === 'read') {
                $queryBuilder->andWhere('n.isRead = true');
            }

            // Get total count
            $totalQuery = clone $queryBuilder;
            $total = $totalQuery->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();

            // Get paginated results
            $offset = ($page - 1) * $limit;
            $notifications = $queryBuilder
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();

            $notificationData = [];
            foreach ($notifications as $notification) {
                $notificationData[] = [
                    'id' => $notification->getId(),
                    'title' => $notification->getTitle(),
                    'message' => $notification->getMessage(),
                    'type' => $notification->getType(),
                    'isRead' => $notification->isRead(),
                    'actionUrl' => $notification->getActionUrl(),
                    'actionText' => $notification->getActionText(),
                    'timeAgo' => $notification->getTimeAgo(),
                    'createdAt' => $notification->getCreatedAt()->format('Y-m-d H:i:s'),
                    'readAt' => $notification->getReadAt() ? $notification->getReadAt()->format('Y-m-d H:i:s') : null
                ];
            }

            return new JsonResponse([
                'success' => true,
                'notifications' => $notificationData,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false, 
                'error' => 'Database error: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}/read', name: 'notification_mark_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markAsRead(int $id): JsonResponse
    {
        $user = $this->getUser();
        $notification = $this->notificationRepository->findOneBy(['id' => $id, 'user' => $user]);

        if (!$notification) {
            return new JsonResponse(['success' => false, 'error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/mark-all-read', name: 'notifications_mark_all_read', methods: ['POST'])]
    public function markAllAsRead(): JsonResponse
    {
        $user = $this->getUser();
        $count = $this->notificationRepository->markAllAsRead($user);

        return new JsonResponse([
            'success' => true,
            'message' => "Marked {$count} notifications as read"
        ]);
    }

    #[Route('/unread-count', name: 'notifications_unread_count', methods: ['GET'])]
    public function getUnreadCount(): JsonResponse
    {
        $user = $this->getUser();
        $count = $this->notificationRepository->getUnreadCount($user);

        return new JsonResponse([
            'success' => true,
            'count' => $count
        ]);
    }

    #[Route('/delete-read', name: 'notifications_delete_read', methods: ['POST'])]
    public function deleteReadNotifications(): JsonResponse
    {
        $user = $this->getUser();
        
        $deletedCount = $this->entityManager->createQueryBuilder()
            ->delete(Notification::class, 'n')
            ->where('n.user = :user')
            ->andWhere('n.isRead = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        return new JsonResponse([
            'success' => true,
            'message' => "Deleted {$deletedCount} read notifications"
        ]);
    }

    #[Route('/{id}/delete', name: 'notification_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteNotification(int $id): JsonResponse
    {
        $user = $this->getUser();
        $notification = $this->notificationRepository->findOneBy(['id' => $id, 'user' => $user]);

        if (!$notification) {
            return new JsonResponse(['success' => false, 'error' => 'Notification not found'], 404);
        }

        $this->entityManager->remove($notification);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * Sync read status from PWA
     * Ensures read status changes are reflected across all user devices
     */
    #[Route('/sync-read-status', name: 'notifications_sync_read_status', methods: ['POST'])]
    public function syncReadStatus(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        if (!isset($data['notificationId']) || !isset($data['isRead'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing required fields: notificationId, isRead'
            ], 400);
        }

        $success = $this->inAppNotificationService->syncReadStatus(
            $user,
            (int) $data['notificationId'],
            (bool) $data['isRead']
        );

        if (!$success) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Notification not found'
            ], 404);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Sync multiple notification read statuses at once
     * Used for bulk synchronization from PWA
     */
    #[Route('/sync-bulk-read-status', name: 'notifications_sync_bulk_read_status', methods: ['POST'])]
    public function syncBulkReadStatus(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        if (!isset($data['notifications']) || !is_array($data['notifications'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing required field: notifications (array)'
            ], 400);
        }

        $syncedCount = $this->inAppNotificationService->syncBulkReadStatus(
            $user,
            $data['notifications']
        );

        return new JsonResponse([
            'success' => true,
            'syncedCount' => $syncedCount
        ]);
    }

    /**
     * Track when a push notification is opened
     * Called from service worker when user taps notification
     */
    #[Route('/api/metrics/{metricsId}/opened', name: 'notification_metrics_opened', methods: ['POST'], requirements: ['metricsId' => '\d+'])]
    public function markMetricsAsOpened(int $metricsId, \App\Service\PushNotificationService $pushService): JsonResponse
    {
        try {
            $pushService->markNotificationAsOpened($metricsId);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Notification marked as opened'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to mark notification as opened'
            ], 500);
        }
    }
}