<?php

namespace App\Controller\Api;

use App\Entity\ActivityLog;
use App\Entity\Enum\UserRole;
use App\Service\ActivityLogService;
use App\Service\ShippingLineService;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/activity-logs', name: 'api_activity_logs_')]
class ActivityLogApiController extends BaseApiController
{
    public function __construct(
        protected \App\Service\JwtService $jwtService,
        protected \App\Service\UserService $userService,
        private ActivityLogService $activityLogService,
        private ShippingLineService $shippingLineService,
        private ActivityLogRepository $activityLogRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

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

            // Parse dates
            $from = $fromDate ? \DateTime::createFromFormat('Y-m-d H:i:s', $fromDate . ' 00:00:00') : null;
            $to = $toDate ? \DateTime::createFromFormat('Y-m-d H:i:s', $toDate . ' 23:59:59') : null;

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($user);
            }

            // Build filters array
            $filters = [];
            if ($activityType) {
                $filters['activity_type'] = $activityType;
            }
            if ($entityType) {
                $filters['entity_type'] = $entityType;
            }
            if ($userId && $this->canAccessUser($user, (int) $userId)) {
                $filters['user_id'] = (int) $userId;
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

            // Serialize activity logs
            $result = [];
            foreach ($activityLogs as $log) {
                $result[] = $this->serializeActivityLog($log);
            }

            // Log this API access
            $this->activityLogService->logView($user, (object)[
                'type' => 'activity_logs_api_list',
                'filters' => $filters,
                'page' => $page,
                'limit' => $limit
            ]);

            return $this->jsonResponse([
                'activity_logs' => $result,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ],
                'filters_applied' => $filters
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve activity logs: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $activityLog = $this->entityManager->getRepository(ActivityLog::class)->find($id);
            
            if (!$activityLog) {
                return $this->errorResponse('Activity log not found', 404);
            }

            // Check access permissions
            if (!$this->canAccessActivityLog($user, $activityLog)) {
                return $this->errorResponse('Access denied', 403);
            }

            // Log this access
            $this->activityLogService->logView($user, $activityLog);

            return $this->jsonResponse([
                'activity_log' => $this->serializeActivityLog($activityLog, true)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve activity log: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/search', name: 'search', methods: ['POST'])]
    public function search(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data || !isset($data['search_term'])) {
                return $this->errorResponse('Search term is required', 400);
            }

            $searchTerm = trim($data['search_term']);
            if (strlen($searchTerm) < 2) {
                return $this->errorResponse('Search term must be at least 2 characters', 400);
            }

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($user);
            }

            // Build search filters
            $filters = [];
            if (isset($data['activity_type'])) {
                $filters['activity_type'] = $data['activity_type'];
            }
            if (isset($data['entity_type'])) {
                $filters['entity_type'] = $data['entity_type'];
            }
            if (isset($data['user_id']) && $this->canAccessUser($user, (int) $data['user_id'])) {
                $filters['user_id'] = (int) $data['user_id'];
            }

            // Perform search
            $results = $this->activityLogService->searchActivityLogs(
                $user,
                $searchTerm,
                $shippingLineScope,
                $filters
            );

            // Log the search activity
            $this->activityLogService->logSearch(
                $user,
                $searchTerm,
                $results,
                'activity_logs_api'
            );

            // Serialize results
            $serializedResults = [];
            foreach ($results as $log) {
                $serializedResults[] = $this->serializeActivityLog($log);
            }

            return $this->jsonResponse([
                'search_term' => $searchTerm,
                'results' => $serializedResults,
                'total_found' => count($serializedResults),
                'filters_applied' => $filters
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Search failed: ' . $e->getMessage(), 500);
        }
    }
    #[Route('/reports/summary', name: 'summary_report', methods: ['GET'])]
    public function summaryReport(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Validate date range parameters
            $fromDate = $request->query->get('from_date');
            $toDate = $request->query->get('to_date');

            if (!$fromDate || !$toDate) {
                return $this->errorResponse('from_date and to_date parameters are required', 400);
            }

            $from = \DateTime::createFromFormat('Y-m-d', $fromDate);
            $to = \DateTime::createFromFormat('Y-m-d', $toDate);

            if (!$from || !$to) {
                return $this->errorResponse('Invalid date format. Use Y-m-d format', 400);
            }

            // Ensure to date is end of day
            $to->setTime(23, 59, 59);

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($user);
            }

            // Generate summary report
            $report = $this->activityLogService->generateSummaryReport(
                $user,
                $shippingLineScope,
                $from,
                $to
            );

            // Log report generation
            $this->activityLogService->logReportGeneration($user, 'activity_summary', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide'
            ]);

            return $this->jsonResponse([
                'report_type' => 'summary',
                'date_range' => [
                    'from' => $fromDate,
                    'to' => $toDate
                ],
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide',
                'data' => $report
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate summary report: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/reports/security', name: 'security_report', methods: ['GET'])]
    public function securityReport(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Validate date range parameters
            $fromDate = $request->query->get('from_date');
            $toDate = $request->query->get('to_date');

            if (!$fromDate || !$toDate) {
                return $this->errorResponse('from_date and to_date parameters are required', 400);
            }

            $from = \DateTime::createFromFormat('Y-m-d', $fromDate);
            $to = \DateTime::createFromFormat('Y-m-d', $toDate);

            if (!$from || !$to) {
                return $this->errorResponse('Invalid date format. Use Y-m-d format', 400);
            }

            // Ensure to date is end of day
            $to->setTime(23, 59, 59);

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($user);
            }

            // Generate security report
            $report = $this->activityLogService->generateSecurityReport(
                $user,
                $shippingLineScope,
                $from,
                $to
            );

            // Log report generation
            $this->activityLogService->logReportGeneration($user, 'security_report', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide'
            ]);

            return $this->jsonResponse([
                'report_type' => 'security',
                'date_range' => [
                    'from' => $fromDate,
                    'to' => $toDate
                ],
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide',
                'data' => $report
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate security report: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/reports/user-activity', name: 'user_activity_report', methods: ['GET'])]
    public function userActivityReport(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Validate date range parameters
            $fromDate = $request->query->get('from_date');
            $toDate = $request->query->get('to_date');

            if (!$fromDate || !$toDate) {
                return $this->errorResponse('from_date and to_date parameters are required', 400);
            }

            $from = \DateTime::createFromFormat('Y-m-d', $fromDate);
            $to = \DateTime::createFromFormat('Y-m-d', $toDate);

            if (!$from || !$to) {
                return $this->errorResponse('Invalid date format. Use Y-m-d format', 400);
            }

            // Ensure to date is end of day
            $to->setTime(23, 59, 59);

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($user);
            }

            // Generate user activity report
            $report = $this->activityLogService->generateUserActivityReport(
                $user,
                $shippingLineScope,
                $from,
                $to
            );

            // Log report generation
            $this->activityLogService->logReportGeneration($user, 'user_activity_report', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide'
            ]);

            return $this->jsonResponse([
                'report_type' => 'user_activity',
                'date_range' => [
                    'from' => $fromDate,
                    'to' => $toDate
                ],
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide',
                'data' => $report
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate user activity report: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/reports/business-operations', name: 'business_operations_report', methods: ['GET'])]
    public function businessOperationsReport(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Validate date range parameters
            $fromDate = $request->query->get('from_date');
            $toDate = $request->query->get('to_date');

            if (!$fromDate || !$toDate) {
                return $this->errorResponse('from_date and to_date parameters are required', 400);
            }

            $from = \DateTime::createFromFormat('Y-m-d', $fromDate);
            $to = \DateTime::createFromFormat('Y-m-d', $toDate);

            if (!$from || !$to) {
                return $this->errorResponse('Invalid date format. Use Y-m-d format', 400);
            }

            // Ensure to date is end of day
            $to->setTime(23, 59, 59);

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($user);
            }

            // Generate business operations report
            $report = $this->activityLogService->generateBusinessOperationsReport(
                $user,
                $shippingLineScope,
                $from,
                $to
            );

            // Log report generation
            $this->activityLogService->logReportGeneration($user, 'business_operations_report', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide'
            ]);

            return $this->jsonResponse([
                'report_type' => 'business_operations',
                'date_range' => [
                    'from' => $fromDate,
                    'to' => $toDate
                ],
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide',
                'data' => $report
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate business operations report: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Get export parameters
            $format = $request->query->get('format', 'json');
            $fromDate = $request->query->get('from_date');
            $toDate = $request->query->get('to_date');
            $activityType = $request->query->get('activity_type');
            $entityType = $request->query->get('entity_type');
            $userId = $request->query->get('user_id');

            // Validate format
            if (!in_array($format, ['json', 'csv'])) {
                return $this->errorResponse('Invalid format. Supported formats: json, csv', 400);
            }

            // Parse dates
            $from = $fromDate ? \DateTime::createFromFormat('Y-m-d H:i:s', $fromDate . ' 00:00:00') : null;
            $to = $toDate ? \DateTime::createFromFormat('Y-m-d H:i:s', $toDate . ' 23:59:59') : null;

            // Get user's shipping line scope
            $shippingLineScope = null;
            if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->shippingLineService->getShippingLineScope($user);
            }

            // Build filters array
            $filters = [];
            if ($activityType) {
                $filters['activity_type'] = $activityType;
            }
            if ($entityType) {
                $filters['entity_type'] = $entityType;
            }
            if ($userId && $this->canAccessUser($user, (int) $userId)) {
                $filters['user_id'] = (int) $userId;
            }

            // Get activity logs for export (no pagination limit for export)
            $activityLogs = $this->activityLogRepository->findWithFilters(
                $shippingLineScope,
                $from,
                $to,
                $filters,
                1000, // Max export limit
                0
            );

            // Log the export activity
            $this->activityLogService->logExport($user, 'activity_logs', $filters, count($activityLogs));

            if ($format === 'csv') {
                return $this->exportToCsv($activityLogs);
            }

            // JSON export
            $result = [];
            foreach ($activityLogs as $log) {
                $result[] = $this->serializeActivityLog($log, true);
            }

            return $this->jsonResponse([
                'export_format' => 'json',
                'exported_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'filters_applied' => $filters,
                'total_records' => count($result),
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Export failed: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/activity-types', name: 'activity_types', methods: ['GET'])]
    public function getActivityTypes(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $activityTypes = ActivityLog::getAllActivityTypes();
            
            // Log this access
            $this->activityLogService->logView($user, (object)[
                'type' => 'activity_types_list_api'
            ]);

            return $this->jsonResponse([
                'activity_types' => $activityTypes
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve activity types: ' . $e->getMessage(), 500);
        }
    }

    private function serializeActivityLog(ActivityLog $log, bool $includeDetails = false): array
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

    private function canAccessActivityLog(\App\Entity\User $user, ActivityLog $log): bool
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

    private function canAccessUser(\App\Entity\User $currentUser, int $userId): bool
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
        $targetUser = $this->userService->findById($userId);
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

    private function exportToCsv(array $activityLogs): JsonResponse
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
            $csvString .= '"' . implode('","', array_map('str_replace', ['"'], ['""'], $row)) . '"' . "\n";
        }

        return new JsonResponse([
            'export_format' => 'csv',
            'exported_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'total_records' => count($activityLogs),
            'csv_data' => $csvString
        ]);
    }
}