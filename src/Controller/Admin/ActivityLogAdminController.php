<?php

namespace App\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Service\ActivityLogService;
use App\Service\ShippingLineService;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/api/activity-logs', name: 'admin_api_activity_logs_')]
class ActivityLogAdminController extends AbstractController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private ShippingLineService $shippingLineService,
        private ActivityLogRepository $activityLogRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            // Get query parameters for filtering
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $offset = ($page - 1) * $limit;

            // Date range filters
            $fromDate = $request->query->get('from_date');
            $toDate = $request->query->get('to_date');
            $activityType = $request->query->get('activity_type');
            $entityType = $request->query->get('entity_type');
            $userId = $request->query->get('user_id');
            $search = $request->query->get('search');

            // Parse dates
            $from = $fromDate ? \DateTime::createFromFormat('Y-m-d', $fromDate) : null;
            $to = $toDate ? \DateTime::createFromFormat('Y-m-d', $toDate) : null;
            
            if ($to) {
                $to->setTime(23, 59, 59);
            }

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($currentUser);
            }

            // Build filters array
            $filters = [];
            if ($activityType) {
                $filters['activity_type'] = $activityType;
            }
            if ($entityType) {
                $filters['entity_type'] = $entityType;
            }
            // Get target user if user_id is provided
            $targetUser = null;
            if ($userId && $this->canAccessUser($currentUser, (int) $userId)) {
                $targetUser = $this->entityManager->getRepository(User::class)->find((int) $userId);
                $filters['user_id'] = (int) $userId;
            }
            if ($search) {
                $filters['search'] = $search;
            }

            // Get activity logs with scope restrictions
            $activityLogs = $this->activityLogRepository->findWithFilters(
                $shippingLineScope,
                $from,
                $to,
                $filters,
                $limit,
                $offset
            );

            $total = $this->activityLogRepository->countWithFilters(
                $shippingLineScope,
                $from,
                $to,
                $filters
            );

            // Log this access
            $this->activityLogService->logView($currentUser, (object)[
                'type' => 'activity_logs_list',
                'filters' => $filters,
                'page' => $page,
                'limit' => $limit
            ]);

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'activityLogs' => array_map([$this, 'formatActivityLogData'], $activityLogs),
                        'pagination' => [
                            'page' => $page,
                            'limit' => $limit,
                            'total' => $total,
                            'pages' => ceil($total / $limit)
                        ],
                        'filtersApplied' => $filters
                    ]
                ]);
            }

            // Get available activity types and entity types for filters
            $activityTypes = ActivityLog::getAllActivityTypes();
            $entityTypes = $this->activityLogRepository->getDistinctEntityTypes($shippingLineScope);
            
            // Get available users for filter (scoped)
            $availableUsers = [];
            if ($currentUser->getRole() === UserRole::SYSTEM_ADMIN) {
                $users = $this->entityManager->getRepository(User::class)
                    ->findBy([], ['email' => 'ASC'], 50);
            } elseif ($shippingLineScope) {
                $users = $shippingLineScope->getScopedUsers();
            } else {
                $users = [];
            }
            
            // Format users for template compatibility
            foreach ($users as $user) {
                $fullName = '';
                if ($user instanceof \App\Entity\StaffUser) {
                    $fullName = $user->getFullName();
                } else {
                    $fullName = $user->getEmail();
                }
                
                $availableUsers[] = (object) [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'fullName' => $fullName
                ];
            }

            return $this->render('admin/activity_logs/list.html.twig', [
                'activityLogs' => $activityLogs,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ],
                'filters' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'activity_type' => $activityType,
                    'entity_type' => $entityType,
                    'user_id' => $userId,
                    'search' => $search
                ],
                'activityTypes' => $activityTypes,
                'entityTypes' => $entityTypes,
                'availableUsers' => $availableUsers,
                'currentUser' => $currentUser,
                'shippingLineScope' => $shippingLineScope,
                'targetUser' => $targetUser
            ]);

        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_logs_list', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load activity logs'], 500);
            }
            
            $this->addFlash('error', 'Failed to load activity logs: ' . $e->getMessage());
            return $this->redirectToRoute('app_dashboard');
        }
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function detail(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $activityLog = $this->entityManager->getRepository(ActivityLog::class)->find($id);
            
            if (!$activityLog) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'error' => 'Activity log not found'], 404);
                }
                
                $this->addFlash('error', 'Activity log not found');
                return $this->redirectToRoute('admin_activity_logs_list');
            }

            // Check access permissions
            if (!$this->canAccessActivityLog($currentUser, $activityLog)) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'error' => 'Access denied'], 403);
                }
                
                $this->addFlash('error', 'Access denied');
                return $this->redirectToRoute('admin_activity_logs_list');
            }

            // Log this access
            $this->activityLogService->logView($currentUser, $activityLog);

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => $this->formatActivityLogData($activityLog, true)
                ]);
            }

            return $this->render('admin/activity_logs/detail.html.twig', [
                'activityLog' => $activityLog,
                'currentUser' => $currentUser
            ]);

        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_log_detail', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load activity log details'], 500);
            }
            
            $this->addFlash('error', 'Failed to load activity log details: ' . $e->getMessage());
            return $this->redirectToRoute('admin_activity_logs_list');
        }
    }

    #[Route('/search', name: 'search', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function search(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $searchTerm = trim($request->request->get('search_term', ''));
            
            if (strlen($searchTerm) < 2) {
                return $this->json(['success' => false, 'error' => 'Search term must be at least 2 characters'], 400);
            }

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($currentUser);
            }

            // Build search filters
            $filters = [];
            if ($request->request->get('activity_type')) {
                $filters['activity_type'] = $request->request->get('activity_type');
            }
            if ($request->request->get('entity_type')) {
                $filters['entity_type'] = $request->request->get('entity_type');
            }
            if ($request->request->get('user_id') && $this->canAccessUser($currentUser, (int) $request->request->get('user_id'))) {
                $filters['user_id'] = (int) $request->request->get('user_id');
            }

            // Perform search
            $results = $this->activityLogService->searchActivityLogs(
                $currentUser,
                $searchTerm,
                $shippingLineScope,
                $filters
            );

            // Serialize results
            $serializedResults = array_map([$this, 'formatActivityLogData'], $results);

            return $this->json([
                'success' => true,
                'data' => [
                    'searchTerm' => $searchTerm,
                    'results' => $serializedResults,
                    'totalFound' => count($serializedResults),
                    'filtersApplied' => $filters
                ]
            ]);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Search failed: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/reports', name: 'reports', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function reports(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            // Get query parameters
            $reportType = $request->query->get('type', 'summary');
            $fromDate = $request->query->get('from');
            $toDate = $request->query->get('to');

            // Validate report type
            $validReportTypes = ['summary', 'security', 'user_activity', 'business_operations'];
            if (!in_array($reportType, $validReportTypes)) {
                $reportType = 'summary';
            }

            // Parse and validate dates
            $from = null;
            $to = null;
            
            if ($fromDate) {
                $from = \DateTime::createFromFormat('Y-m-d', $fromDate);
                if (!$from) {
                    $this->addFlash('error', 'Invalid from date format');
                    $from = null;
                }
            }
            
            if ($toDate) {
                $to = \DateTime::createFromFormat('Y-m-d', $toDate);
                if (!$to) {
                    $this->addFlash('error', 'Invalid to date format');
                    $to = null;
                } else {
                    $to->setTime(23, 59, 59); // End of day
                }
            }

            // Set default date range if not provided (last 30 days)
            if (!$from && !$to) {
                $to = new \DateTime();
                $from = (clone $to)->modify('-30 days');
            } elseif (!$from && $to) {
                $from = (clone $to)->modify('-30 days');
            } elseif ($from && !$to) {
                $to = new \DateTime();
            }

            // Get user's shipping line scope
            $shippingLineScope = null;
            $isSystemAdmin = $currentUser->getRole() === UserRole::SYSTEM_ADMIN;
            
            if (!$isSystemAdmin) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($currentUser);
            }

            // Generate report data based on type
            $reportData = [];
            switch ($reportType) {
                case 'summary':
                    $reportData = $this->activityLogRepository->generateSummaryReport($shippingLineScope, $from, $to);
                    break;
                case 'security':
                    $reportData = $this->activityLogRepository->generateSecurityReport($shippingLineScope, $from, $to);
                    break;
                case 'user_activity':
                    $reportData = $this->activityLogRepository->generateUserActivityReport($shippingLineScope, $from, $to);
                    break;
                case 'business_operations':
                    $reportData = $this->activityLogRepository->generateBusinessOperationsReport($shippingLineScope, $from, $to);
                    break;
            }

            // Log this reports access
            $this->activityLogService->logView($currentUser, (object)[
                'type' => 'activity_logs_reports',
                'report_type' => $reportType,
                'date_range' => [
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d')
                ]
            ]);

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'reportType' => $reportType,
                        'reportData' => $reportData,
                        'dateRange' => [
                            'from' => $from->format('Y-m-d'),
                            'to' => $to->format('Y-m-d')
                        ],
                        'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide',
                        'isSystemAdmin' => $isSystemAdmin
                    ]
                ]);
            }

            return $this->render('admin/activity_logs/reports.html.twig', [
                'reportType' => $reportType,
                'reportData' => $reportData,
                'dateRange' => [
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d')
                ],
                'currentUser' => $currentUser,
                'shippingLineScope' => $shippingLineScope,
                'isSystemAdmin' => $isSystemAdmin
            ]);

        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_logs_reports', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to generate report'], 500);
            }
            
            $this->addFlash('error', 'Failed to generate report: ' . $e->getMessage());
            return $this->redirectToRoute('admin_activity_logs_list');
        }
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function export(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            // Get export parameters
            $format = $request->query->get('format', 'csv');
            $fromDate = $request->query->get('from');
            $toDate = $request->query->get('to');
            $activityType = $request->query->get('activity_type');
            $entityType = $request->query->get('entity_type');
            $userId = $request->query->get('user_id');

            // Validate format
            if (!in_array($format, ['json', 'csv'])) {
                return $this->json(['success' => false, 'error' => 'Invalid format. Supported formats: json, csv'], 400);
            }

            // Parse dates
            $from = $fromDate ? \DateTime::createFromFormat('Y-m-d', $fromDate) : null;
            $to = $toDate ? \DateTime::createFromFormat('Y-m-d', $toDate) : null;
            
            if ($to) {
                $to->setTime(23, 59, 59);
            }

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($currentUser);
            }

            // Build filters array
            $filters = [];
            if ($activityType) {
                $filters['activity_type'] = $activityType;
            }
            if ($entityType) {
                $filters['entity_type'] = $entityType;
            }
            if ($userId && $this->canAccessUser($currentUser, (int) $userId)) {
                $filters['user_id'] = (int) $userId;
            }

            // Get activity logs for export (limited to 1000 records)
            $activityLogs = $this->activityLogRepository->findWithFilters(
                $shippingLineScope,
                $from,
                $to,
                $filters,
                1000,
                0
            );

            // Log the export activity
            $this->activityLogService->logExport($currentUser, 'activity_logs', $filters, count($activityLogs));

            if ($format === 'csv') {
                return $this->exportToCsv($activityLogs);
            }

            // JSON export
            $result = array_map(function($log) {
                return $this->formatActivityLogData($log, true);
            }, $activityLogs);

            $response = new JsonResponse([
                'export_format' => 'json',
                'exported_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'filters_applied' => $filters,
                'total_records' => count($result),
                'data' => $result
            ]);
            
            $response->headers->set('Content-Disposition', 'attachment; filename="activity_logs.json"');
            return $response;

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Export failed: ' . $e->getMessage()], 500);
        }
    }

    // Private helper methods

    private function formatActivityLogData(ActivityLog $log, bool $includeDetails = false): array
    {
        $data = [
            'id' => $log->getId(),
            'user' => [
                'id' => $log->getUser()->getId(),
                'email' => $log->getUser()->getEmail(),
                'role' => $log->getUser()->getRole()->value
            ],
            'activity_type' => $log->getActivityType(),
            'entity_type' => $log->getEntityType(),
            'entity_id' => $log->getEntityId(),
            'ip_address' => $log->getIpAddress(),
            'created_at' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
            'activity_description' => $log->getActivityDescription()
        ];

        if ($log->getShippingLine()) {
            $data['shipping_line'] = [
                'id' => $log->getShippingLine()->getId(),
                'brand_name' => $log->getShippingLine()->getBrandName()
            ];
        }

        if ($includeDetails) {
            $data['old_values'] = $log->getOldValues();
            $data['new_values'] = $log->getNewValues();
            $data['user_agent'] = $log->getUserAgent();
            $data['session_id'] = $log->getSessionId();
            $data['additional_context'] = $log->getAdditionalContext();
            $data['is_security_activity'] = $log->isSecurityActivity();
            $data['is_business_activity'] = $log->isBusinessActivity();
        }

        return $data;
    }

    private function canAccessActivityLog(User $user, ActivityLog $log): bool
    {
        // SYSTEM_ADMIN can access all activity logs
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // Users can only access logs within their shipping line scope
        $userScope = $this->shippingLineService->getShippingLineScope($user);
        $logScope = $log->getShippingLine();

        // If user has no scope, they can only see their own logs
        if (!$userScope) {
            return $log->getUser()->getId() === $user->getId();
        }

        // If log has no scope, only SYSTEM_ADMIN can access (already checked above)
        if (!$logScope) {
            return false;
        }

        return $userScope->getId() === $logScope->getId();
    }

    private function canAccessUser(User $currentUser, int $userId): bool
    {
        // SYSTEM_ADMIN can access all users
        if ($currentUser->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // Users can always access their own data
        if ($currentUser->getId() === $userId) {
            return true;
        }

        // Get the target user
        $targetUser = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$targetUser) {
            return false;
        }

        // Check if both users are in the same shipping line scope
        $currentUserScope = $this->shippingLineService->getShippingLineScope($currentUser);
        $targetUserScope = $this->shippingLineService->getShippingLineScope($targetUser);

        if (!$currentUserScope || !$targetUserScope) {
            return false;
        }

        return $currentUserScope->getId() === $targetUserScope->getId();
    }

    private function exportToCsv(array $activityLogs): Response
    {
        $csvData = [];
        $csvData[] = [
            'ID',
            'User Email',
            'User Role',
            'Activity Type',
            'Entity Type',
            'Entity ID',
            'IP Address',
            'Shipping Line',
            'Created At',
            'Description'
        ];

        foreach ($activityLogs as $log) {
            $csvData[] = [
                $log->getId(),
                $log->getUser()->getEmail(),
                $log->getUser()->getRole()->value,
                $log->getActivityType(),
                $log->getEntityType() ?? '',
                $log->getEntityId() ?? '',
                $log->getIpAddress(),
                $log->getShippingLine() ? $log->getShippingLine()->getBrandName() : '',
                $log->getCreatedAt()->format('Y-m-d H:i:s'),
                $log->getActivityDescription()
            ];
        }

        // Convert to CSV string
        $csvString = '';
        foreach ($csvData as $row) {
            $csvString .= '"' . implode('","', array_map(function($field) {
                return str_replace('"', '""', $field);
            }, $row)) . '"' . "\n";
        }

        $response = new Response($csvString);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="activity_logs.csv"');
        
        return $response;
    }
}