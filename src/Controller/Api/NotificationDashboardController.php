<?php

namespace App\Controller\Api;

use App\Entity\Enum\UserRole;
use App\Service\NotificationMonitoringService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API Controller for notification dashboard and monitoring
 * Implements Requirements 8.1, 8.2, 8.3, 8.4
 */
#[Route('/api/notifications', name: 'api_notifications_')]
class NotificationDashboardController extends BaseApiController
{
    public function __construct(
        protected \App\Service\JwtService $jwtService,
        protected \App\Service\UserService $userService,
        private NotificationMonitoringService $monitoringService
    ) {
        parent::__construct($jwtService, $userService);
    }

    /**
     * Get notification dashboard overview
     */
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function getDashboard(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only admins can access dashboard
        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            // Get date range from query parameters
            $fromDate = null;
            $toDate = null;

            if ($request->query->has('from_date')) {
                $fromDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('from_date'));
                if (!$fromDate) {
                    return $this->errorResponse('Invalid from_date format. Use Y-m-d', 400);
                }
            }

            if ($request->query->has('to_date')) {
                $toDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('to_date'));
                if (!$toDate) {
                    return $this->errorResponse('Invalid to_date format. Use Y-m-d', 400);
                }
                $toDate->setTime(23, 59, 59);
            }

            // Get statistics
            $statistics = $this->monitoringService->getDeliveryStatistics($fromDate, $toDate);

            // Get recent pending and failed notifications
            $pendingNotifications = $this->monitoringService->getPendingNotifications(10);
            $failedNotifications = $this->monitoringService->getFailedNotifications(10);

            return $this->jsonResponse([
                'statistics' => $statistics,
                'recent_pending' => $pendingNotifications,
                'recent_failed' => $failedNotifications,
                'date_range' => [
                    'from' => $fromDate?->format('Y-m-d'),
                    'to' => $toDate?->format('Y-m-d')
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve dashboard data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get delivery statistics
     */
    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    public function getStatistics(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $fromDate = null;
            $toDate = null;

            if ($request->query->has('from_date')) {
                $fromDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('from_date'));
                if (!$fromDate) {
                    return $this->errorResponse('Invalid from_date format. Use Y-m-d', 400);
                }
            }

            if ($request->query->has('to_date')) {
                $toDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('to_date'));
                if (!$toDate) {
                    return $this->errorResponse('Invalid to_date format. Use Y-m-d', 400);
                }
                $toDate->setTime(23, 59, 59);
            }

            $statistics = $this->monitoringService->getDeliveryStatistics($fromDate, $toDate);

            return $this->jsonResponse($statistics);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get pending notifications
     */
    #[Route('/pending', name: 'pending', methods: ['GET'])]
    public function getPendingNotifications(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            $notifications = $this->monitoringService->getPendingNotifications($limit, $offset);
            $total = $this->monitoringService->countNotifications(['delivery_status' => 'pending']);

            return $this->jsonResponse([
                'notifications' => $notifications,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve pending notifications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get delivered notifications
     */
    #[Route('/delivered', name: 'delivered', methods: ['GET'])]
    public function getDeliveredNotifications(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            $notifications = $this->monitoringService->getDeliveredNotifications($limit, $offset);
            $total = $this->monitoringService->countNotifications(['delivery_status' => 'delivered']);

            return $this->jsonResponse([
                'notifications' => $notifications,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve delivered notifications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get failed notifications
     */
    #[Route('/failed', name: 'failed', methods: ['GET'])]
    public function getFailedNotifications(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            $notifications = $this->monitoringService->getFailedNotifications($limit, $offset);
            $total = $this->monitoringService->countNotifications(['delivery_status' => 'failed']);

            return $this->jsonResponse([
                'notifications' => $notifications,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve failed notifications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Search notifications by container number
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function searchNotifications(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $containerNumber = $request->query->get('container_number');
            
            if (!$containerNumber) {
                return $this->errorResponse('container_number parameter is required', 400);
            }

            $limit = min(100, max(10, (int) $request->query->get('limit', 50)));
            $notifications = $this->monitoringService->searchByContainerNumber($containerNumber, $limit);

            return $this->jsonResponse([
                'notifications' => $notifications,
                'search_term' => $containerNumber,
                'total_results' => count($notifications)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to search notifications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Filter notifications with advanced criteria
     */
    #[Route('/filter', name: 'filter', methods: ['GET'])]
    public function filterNotifications(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            // Build criteria from query parameters
            $criteria = [];

            // Pagination
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $criteria['limit'] = $limit;
            $criteria['offset'] = ($page - 1) * $limit;

            // Filters
            if ($request->query->has('delivery_status')) {
                $status = $request->query->get('delivery_status');
                if (!in_array($status, ['pending', 'delivered', 'failed', 'retrying'])) {
                    return $this->errorResponse('Invalid delivery_status. Use: pending, delivered, failed, retrying', 400);
                }
                $criteria['delivery_status'] = $status;
            }

            if ($request->query->has('notification_type')) {
                $criteria['notification_type'] = $request->query->get('notification_type');
            }

            if ($request->query->has('channel')) {
                $channel = $request->query->get('channel');
                if (!in_array($channel, ['email', 'sms', 'in_app'])) {
                    return $this->errorResponse('Invalid channel. Use: email, sms, in_app', 400);
                }
                $criteria['channel'] = $channel;
            }

            if ($request->query->has('container_number')) {
                $criteria['container_number'] = $request->query->get('container_number');
            }

            // Date range
            if ($request->query->has('from_date')) {
                $fromDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('from_date'));
                if (!$fromDate) {
                    return $this->errorResponse('Invalid from_date format. Use Y-m-d', 400);
                }
                $criteria['from_date'] = $fromDate;
            }

            if ($request->query->has('to_date')) {
                $toDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('to_date'));
                if (!$toDate) {
                    return $this->errorResponse('Invalid to_date format. Use Y-m-d', 400);
                }
                $toDate->setTime(23, 59, 59);
                $criteria['to_date'] = $toDate;
            }

            // Sorting
            if ($request->query->has('sort_by')) {
                $sortBy = $request->query->get('sort_by');
                if (!in_array($sortBy, ['createdAt', 'deliveredAt', 'lastAttemptAt', 'attemptCount'])) {
                    return $this->errorResponse('Invalid sort_by field', 400);
                }
                $criteria['sort_by'] = $sortBy;
            }

            if ($request->query->has('sort_order')) {
                $sortOrder = strtoupper($request->query->get('sort_order'));
                if (!in_array($sortOrder, ['ASC', 'DESC'])) {
                    return $this->errorResponse('Invalid sort_order. Use ASC or DESC', 400);
                }
                $criteria['sort_order'] = $sortOrder;
            }

            $notifications = $this->monitoringService->filterNotifications($criteria);
            $total = $this->monitoringService->countNotifications($criteria);

            return $this->jsonResponse([
                'notifications' => $notifications,
                'filters' => $criteria,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to filter notifications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get failed deliveries that need alerting
     */
    #[Route('/alerts', name: 'alerts', methods: ['GET'])]
    public function getFailedDeliveryAlerts(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINES_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $thresholdMinutes = (int) $request->query->get('threshold_minutes', 30);
            $alerts = $this->monitoringService->getFailedDeliveriesForAlerting($thresholdMinutes);

            return $this->jsonResponse([
                'alerts' => $alerts,
                'threshold_minutes' => $thresholdMinutes,
                'total_alerts' => count($alerts)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve alerts: ' . $e->getMessage(), 500);
        }
    }
}
