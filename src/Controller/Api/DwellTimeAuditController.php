<?php

namespace App\Controller\Api;

use App\Entity\Container;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\Enum\UserRole;
use App\Service\DwellTimeAuditService;
use App\Service\ShippingLineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/dwell-time-audit', name: 'api_dwell_time_audit_')]
class DwellTimeAuditController extends BaseApiController
{
    public function __construct(
        protected \App\Service\JwtService $jwtService,
        protected \App\Service\UserService $userService,
        private DwellTimeAuditService $auditService,
        private ShippingLineService $shippingLineService,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/container/{id}/history', name: 'container_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getContainerHistory(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $container = $this->entityManager->getRepository(Container::class)->find($id);
            
            if (!$container) {
                return $this->errorResponse('Container not found', 404);
            }

            // Check access permissions
            if (!$this->canAccessContainer($user, $container)) {
                return $this->errorResponse('Access denied', 403);
            }

            // Get optional filters from query parameters
            $filters = [];
            if ($request->query->has('event_type')) {
                $eventType = $request->query->get('event_type');
                try {
                    $filters['event_type'] = DwellTimeEventType::from($eventType);
                } catch (\ValueError $e) {
                    return $this->errorResponse('Invalid event type', 400);
                }
            }

            if ($request->query->has('from_date')) {
                $fromDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('from_date'));
                if (!$fromDate) {
                    return $this->errorResponse('Invalid from_date format. Use Y-m-d', 400);
                }
                $filters['from_date'] = $fromDate;
            }

            if ($request->query->has('to_date')) {
                $toDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('to_date'));
                if (!$toDate) {
                    return $this->errorResponse('Invalid to_date format. Use Y-m-d', 400);
                }
                $toDate->setTime(23, 59, 59);
                $filters['to_date'] = $toDate;
            }

            $auditTrail = $this->auditService->getAuditTrail($container, $filters);

            return $this->jsonResponse([
                'container' => [
                    'id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'current_dwell_time' => $container->getCurrentDwellTime(),
                    'status' => $container->getStatus()->value
                ],
                'audit_trail' => $auditTrail,
                'total_events' => count($auditTrail)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve container history: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/events', name: 'events_list', methods: ['GET'])]
    public function listEvents(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Pagination parameters
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            // Build criteria from query parameters
            $criteria = [
                'limit' => $limit,
                'offset' => $offset
            ];

            // Container filter
            if ($request->query->has('container_id')) {
                $containerId = (int) $request->query->get('container_id');
                $container = $this->entityManager->getRepository(Container::class)->find($containerId);
                
                if (!$container) {
                    return $this->errorResponse('Container not found', 404);
                }

                if (!$this->canAccessContainer($user, $container)) {
                    return $this->errorResponse('Access denied', 403);
                }

                $criteria['container_id'] = $containerId;
            }

            // Container number search
            if ($request->query->has('container_number')) {
                $criteria['container_number'] = $request->query->get('container_number');
            }

            // Event type filter
            if ($request->query->has('event_type')) {
                $eventTypes = explode(',', $request->query->get('event_type'));
                $validEventTypes = [];
                
                foreach ($eventTypes as $eventType) {
                    try {
                        $validEventTypes[] = DwellTimeEventType::from(trim($eventType));
                    } catch (\ValueError $e) {
                        return $this->errorResponse('Invalid event type: ' . $eventType, 400);
                    }
                }
                
                $criteria['event_type'] = count($validEventTypes) === 1 ? $validEventTypes[0] : $validEventTypes;
            }

            // Date range filters
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
                $criteria['sort_by'] = $request->query->get('sort_by');
            }

            if ($request->query->has('sort_order')) {
                $sortOrder = strtoupper($request->query->get('sort_order'));
                if (!in_array($sortOrder, ['ASC', 'DESC'])) {
                    return $this->errorResponse('Invalid sort_order. Use ASC or DESC', 400);
                }
                $criteria['sort_order'] = $sortOrder;
            }

            // Get events and total count
            $events = $this->auditService->queryEvents($criteria);
            $total = $this->auditService->countEvents($criteria);

            return $this->jsonResponse([
                'events' => $events,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve events: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/container/{id}/pause-resume-history', name: 'pause_resume_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getPauseResumeHistory(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $container = $this->entityManager->getRepository(Container::class)->find($id);
            
            if (!$container) {
                return $this->errorResponse('Container not found', 404);
            }

            if (!$this->canAccessContainer($user, $container)) {
                return $this->errorResponse('Access denied', 403);
            }

            $history = $this->auditService->getPauseResumeHistory($container);

            // Calculate total pause duration
            $totalPauseDays = array_reduce($history, function($carry, $item) {
                return $carry + $item['duration_days'];
            }, 0);

            return $this->jsonResponse([
                'container' => [
                    'id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'is_currently_paused' => $container->getDwellTimePausedAt() !== null
                ],
                'pause_resume_history' => $history,
                'total_pause_cycles' => count($history),
                'total_pause_days' => $totalPauseDays
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve pause/resume history: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/container/{id}/notification-history', name: 'notification_history', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getNotificationHistory(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $container = $this->entityManager->getRepository(Container::class)->find($id);
            
            if (!$container) {
                return $this->errorResponse('Container not found', 404);
            }

            if (!$this->canAccessContainer($user, $container)) {
                return $this->errorResponse('Access denied', 403);
            }

            $notifications = $this->auditService->getNotificationHistory($container);

            return $this->jsonResponse([
                'container' => [
                    'id' => $container->getId(),
                    'container_number' => $container->getContainerNumber()
                ],
                'notifications' => $notifications,
                'total_notifications' => count($notifications)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve notification history: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/reports/summary', name: 'summary_report', methods: ['GET'])]
    public function generateSummaryReport(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only admins can generate reports
        $roleCheck = $this->requireRole($user, [
            UserRole::SYSTEM_ADMIN->value,
            UserRole::SHIPPING_LINE_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            // Validate required date parameters
            $fromDateStr = $request->query->get('from_date');
            $toDateStr = $request->query->get('to_date');

            if (!$fromDateStr || !$toDateStr) {
                return $this->errorResponse('from_date and to_date parameters are required', 400);
            }

            $fromDate = \DateTime::createFromFormat('Y-m-d', $fromDateStr);
            $toDate = \DateTime::createFromFormat('Y-m-d', $toDateStr);

            if (!$fromDate || !$toDate) {
                return $this->errorResponse('Invalid date format. Use Y-m-d', 400);
            }

            $toDate->setTime(23, 59, 59);

            // Optional filters
            $filters = [];
            if ($request->query->has('event_type')) {
                $eventTypes = explode(',', $request->query->get('event_type'));
                $validEventTypes = [];
                
                foreach ($eventTypes as $eventType) {
                    try {
                        $validEventTypes[] = DwellTimeEventType::from(trim($eventType));
                    } catch (\ValueError $e) {
                        return $this->errorResponse('Invalid event type: ' . $eventType, 400);
                    }
                }
                
                $filters['event_type'] = count($validEventTypes) === 1 ? $validEventTypes[0] : $validEventTypes;
            }

            $report = $this->auditService->generateReport($fromDate, $toDate, $filters);

            return $this->jsonResponse([
                'report_type' => 'dwell_time_summary',
                'generated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'generated_by' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail()
                ],
                'report' => $report
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate report: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/event-types', name: 'event_types', methods: ['GET'])]
    public function getEventTypes(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eventTypes = [];
        foreach (DwellTimeEventType::cases() as $type) {
            $eventTypes[] = [
                'value' => $type->value,
                'name' => $type->name
            ];
        }

        return $this->jsonResponse([
            'event_types' => $eventTypes
        ]);
    }

    /**
     * Check if user can access a specific container
     */
    private function canAccessContainer(\App\Entity\User $user, Container $container): bool
    {
        // System admins can access all containers
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // For now, allow all authenticated users to access containers
        // In a real system, you would check if the container belongs to the user's organization
        return true;
    }
}
