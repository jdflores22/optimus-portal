<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\ActivityLogService;
use App\Service\ScopeAccessControlService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/activity-logs', name: 'activity_logs_')]
class ActivityLogController extends AbstractController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private ScopeAccessControlService $scopeAccessControlService,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function list(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            // Determine scope based on user role
            $shippingLineScope = null;
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->scopeAccessControlService->getShippingLineScope($currentUser);
                if (!$shippingLineScope) {
                    throw new \Exception('User does not have access to any shipping line scope');
                }
            }
            
            // Get filter parameters
            $filters = [
                'from' => $request->query->get('from'),
                'to' => $request->query->get('to'),
                'activityType' => $request->query->get('activityType'),
                'entityType' => $request->query->get('entityType'),
                'userId' => $request->query->get('userId'),
                'page' => max(1, (int)$request->query->get('page', 1)),
                'limit' => min(100, max(10, (int)$request->query->get('limit', 25)))
            ];
            
            // Get activity logs with scope filtering
            $activityLogs = $this->activityLogService->getActivityHistory(
                $currentUser,
                $shippingLineScope,
                $filters['from'] ? new \DateTime($filters['from']) : null,
                $filters['to'] ? new \DateTime($filters['to']) : null,
                $filters
            );
            
            // Log the view action
            $this->activityLogService->logView($currentUser, (object)[
                'type' => 'activity_logs_list',
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide'
            ]);
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => array_map(function(ActivityLog $log) {
                        return [
                            'id' => $log->getId(),
                            'activityType' => $log->getActivityType(),
                            'activityDescription' => $log->getActivityDescription(),
                            'user' => [
                                'id' => $log->getUser()->getId(),
                                'email' => $log->getUser()->getEmail(),
                                'role' => $log->getUser()->getRole()->value
                            ],
                            'shippingLine' => $log->getShippingLine() ? [
                                'id' => $log->getShippingLine()->getId(),
                                'brandName' => $log->getShippingLine()->getBrandName()
                            ] : null,
                            'entityType' => $log->getEntityType(),
                            'entityId' => $log->getEntityId(),
                            'ipAddress' => $log->getIpAddress(),
                            'createdAt' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
                            'isSecurityActivity' => $log->isSecurityActivity(),
                            'isBusinessActivity' => $log->isBusinessActivity()
                        ];
                    }, $activityLogs['logs']),
                    'pagination' => $activityLogs['pagination'],
                    'filters' => $filters,
                    'scope' => $shippingLineScope ? [
                        'id' => $shippingLineScope->getId(),
                        'brandName' => $shippingLineScope->getBrandName()
                    ] : null
                ]);
            }
            
            // Get available filter options
            $filterOptions = [
                'activityTypes' => ActivityLog::getAllActivityTypes(),
                'entityTypes' => $this->getAvailableEntityTypes($shippingLineScope),
                'users' => $this->getAvailableUsers($shippingLineScope)
            ];
            
            return $this->render('admin/activity_logs/list.html.twig', [
                'activityLogs' => $activityLogs['logs'],
                'pagination' => $activityLogs['pagination'],
                'filters' => $filters,
                'filterOptions' => $filterOptions,
                'shippingLineScope' => $shippingLineScope,
                'isSystemAdmin' => $currentUser->getRole() === UserRole::SYSTEM_ADMIN
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_logs_list', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load activity logs'], 500);
            }
            
            $this->addFlash('error', 'Failed to load activity logs: ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_dashboard');
        }
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
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
                return $this->redirectToRoute('activity_logs_list');
            }
            
            // Check scope access
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $userScope = $this->scopeAccessControlService->getShippingLineScope($currentUser);
                if (!$activityLog->isInShippingLineScope($userScope)) {
                    throw new \Exception('Access denied: Activity log outside user scope');
                }
            }
            
            // Log the view action
            $this->activityLogService->logView($currentUser, $activityLog);
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'id' => $activityLog->getId(),
                        'activityType' => $activityLog->getActivityType(),
                        'activityDescription' => $activityLog->getActivityDescription(),
                        'user' => [
                            'id' => $activityLog->getUser()->getId(),
                            'email' => $activityLog->getUser()->getEmail(),
                            'role' => $activityLog->getUser()->getRole()->value
                        ],
                        'shippingLine' => $activityLog->getShippingLine() ? [
                            'id' => $activityLog->getShippingLine()->getId(),
                            'brandName' => $activityLog->getShippingLine()->getBrandName()
                        ] : null,
                        'entityType' => $activityLog->getEntityType(),
                        'entityId' => $activityLog->getEntityId(),
                        'oldValues' => $activityLog->getOldValues(),
                        'newValues' => $activityLog->getNewValues(),
                        'ipAddress' => $activityLog->getIpAddress(),
                        'userAgent' => $activityLog->getUserAgent(),
                        'sessionId' => $activityLog->getSessionId(),
                        'additionalContext' => $activityLog->getAdditionalContext(),
                        'createdAt' => $activityLog->getCreatedAt()->format('Y-m-d H:i:s'),
                        'isSecurityActivity' => $activityLog->isSecurityActivity(),
                        'isBusinessActivity' => $activityLog->isBusinessActivity()
                    ]
                ]);
            }
            
            return $this->render('admin/activity_logs/detail.html.twig', [
                'activityLog' => $activityLog,
                'isSystemAdmin' => $currentUser->getRole() === UserRole::SYSTEM_ADMIN
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_log_detail', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load activity log details'], 500);
            }
            
            $this->addFlash('error', 'Failed to load activity log details: ' . $e->getMessage());
            return $this->redirectToRoute('activity_logs_list');
        }
    }

    #[Route('/reports', name: 'reports', methods: ['GET'])]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function reports(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            // Determine scope based on user role
            $shippingLineScope = null;
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->scopeAccessControlService->getShippingLineScope($currentUser);
                if (!$shippingLineScope) {
                    throw new \Exception('User does not have access to any shipping line scope');
                }
            }
            
            // Get report parameters
            $reportType = $request->query->get('type', 'summary');
            $from = $request->query->get('from', (new \DateTime('-30 days'))->format('Y-m-d'));
            $to = $request->query->get('to', (new \DateTime())->format('Y-m-d'));
            
            $reportData = [];
            
            switch ($reportType) {
                case 'summary':
                    $reportData = $this->generateSummaryReport($currentUser, $shippingLineScope, $from, $to);
                    break;
                case 'security':
                    $reportData = $this->generateSecurityReport($currentUser, $shippingLineScope, $from, $to);
                    break;
                case 'user_activity':
                    $reportData = $this->generateUserActivityReport($currentUser, $shippingLineScope, $from, $to);
                    break;
                case 'business_operations':
                    $reportData = $this->generateBusinessOperationsReport($currentUser, $shippingLineScope, $from, $to);
                    break;
                default:
                    throw new \Exception('Invalid report type');
            }
            
            // Log the report generation
            $this->activityLogService->logReportGeneration($currentUser, 'activity_log_' . $reportType, [
                'from' => $from,
                'to' => $to,
                'scope' => $shippingLineScope ? $shippingLineScope->getBrandName() : 'system_wide'
            ]);
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => $reportData,
                    'reportType' => $reportType,
                    'dateRange' => ['from' => $from, 'to' => $to],
                    'scope' => $shippingLineScope ? [
                        'id' => $shippingLineScope->getId(),
                        'brandName' => $shippingLineScope->getBrandName()
                    ] : null
                ]);
            }
            
            return $this->render('admin/activity_logs/reports.html.twig', [
                'reportData' => $reportData,
                'reportType' => $reportType,
                'dateRange' => ['from' => $from, 'to' => $to],
                'shippingLineScope' => $shippingLineScope,
                'isSystemAdmin' => $currentUser->getRole() === UserRole::SYSTEM_ADMIN
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_logs_reports', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to generate report'], 500);
            }
            
            $this->addFlash('error', 'Failed to generate report: ' . $e->getMessage());
            return $this->redirectToRoute('activity_logs_list');
        }
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function export(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            // Determine scope based on user role
            $shippingLineScope = null;
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->scopeAccessControlService->getShippingLineScope($currentUser);
                if (!$shippingLineScope) {
                    throw new \Exception('User does not have access to any shipping line scope');
                }
            }
            
            $format = $request->query->get('format', 'csv');
            $filters = [
                'from' => $request->query->get('from'),
                'to' => $request->query->get('to'),
                'activityType' => $request->query->get('activityType'),
                'entityType' => $request->query->get('entityType'),
                'userId' => $request->query->get('userId')
            ];
            
            // Get activity logs for export (no pagination limit)
            $activityLogs = $this->activityLogService->getActivityHistory(
                $currentUser,
                $shippingLineScope,
                $filters['from'] ? new \DateTime($filters['from']) : null,
                $filters['to'] ? new \DateTime($filters['to']) : null,
                array_merge($filters, ['limit' => null]) // No limit for export
            );
            
            // Log the export action
            $this->activityLogService->logExport($currentUser, 'activity_logs', $filters, count($activityLogs['logs']));
            
            if ($format === 'json') {
                $data = array_map(function(ActivityLog $log) {
                    return [
                        'id' => $log->getId(),
                        'activityType' => $log->getActivityType(),
                        'activityDescription' => $log->getActivityDescription(),
                        'userEmail' => $log->getUser()->getEmail(),
                        'userRole' => $log->getUser()->getRole()->value,
                        'shippingLineBrandName' => $log->getShippingLine() ? $log->getShippingLine()->getBrandName() : null,
                        'entityType' => $log->getEntityType(),
                        'entityId' => $log->getEntityId(),
                        'oldValues' => $log->getOldValues(),
                        'newValues' => $log->getNewValues(),
                        'ipAddress' => $log->getIpAddress(),
                        'userAgent' => $log->getUserAgent(),
                        'sessionId' => $log->getSessionId(),
                        'additionalContext' => $log->getAdditionalContext(),
                        'createdAt' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
                        'isSecurityActivity' => $log->isSecurityActivity(),
                        'isBusinessActivity' => $log->isBusinessActivity()
                    ];
                }, $activityLogs['logs']);
                
                $response = new JsonResponse($data);
                $filename = sprintf(
                    'activity_logs_%s_%s.json',
                    $shippingLineScope ? $shippingLineScope->getBrandName() : 'system',
                    (new \DateTime())->format('Y-m-d_H-i-s')
                );
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
                return $response;
            }
            
            // CSV Export
            $csvData = "ID,Activity Type,Description,User Email,User Role,Shipping Line,Entity Type,Entity ID,IP Address,Created At,Security Activity,Business Activity\n";
            foreach ($activityLogs['logs'] as $log) {
                $csvData .= sprintf(
                    "%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $log->getId(),
                    '"' . str_replace('"', '""', $log->getActivityType()) . '"',
                    '"' . str_replace('"', '""', $log->getActivityDescription()) . '"',
                    '"' . str_replace('"', '""', $log->getUser()->getEmail()) . '"',
                    '"' . str_replace('"', '""', $log->getUser()->getRole()->value) . '"',
                    $log->getShippingLine() ? '"' . str_replace('"', '""', $log->getShippingLine()->getBrandName()) . '"' : '',
                    $log->getEntityType() ? '"' . str_replace('"', '""', $log->getEntityType()) . '"' : '',
                    $log->getEntityId() ?? '',
                    '"' . str_replace('"', '""', $log->getIpAddress()) . '"',
                    $log->getCreatedAt()->format('Y-m-d H:i:s'),
                    $log->isSecurityActivity() ? 'Yes' : 'No',
                    $log->isBusinessActivity() ? 'Yes' : 'No'
                );
            }
            
            $response = new Response($csvData);
            $response->headers->set('Content-Type', 'text/csv');
            $filename = sprintf(
                'activity_logs_%s_%s.csv',
                $shippingLineScope ? $shippingLineScope->getBrandName() : 'system',
                (new \DateTime())->format('Y-m-d_H-i-s')
            );
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
            
            return $response;
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_logs_export', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Export failed'], 500);
            }
            
            $this->addFlash('error', 'Export failed: ' . $e->getMessage());
            return $this->redirectToRoute('activity_logs_list');
        }
    }

    #[Route('/search', name: 'search', methods: ['POST'])]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function search(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            // Determine scope based on user role
            $shippingLineScope = null;
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                $shippingLineScope = $this->scopeAccessControlService->getShippingLineScope($currentUser);
                if (!$shippingLineScope) {
                    throw new \Exception('User does not have access to any shipping line scope');
                }
            }
            
            $searchTerm = $request->request->get('searchTerm', '');
            $filters = [
                'activityType' => $request->request->get('activityType'),
                'entityType' => $request->request->get('entityType'),
                'userId' => $request->request->get('userId'),
                'from' => $request->request->get('from'),
                'to' => $request->request->get('to'),
                'searchTerm' => $searchTerm
            ];
            
            // Perform search with scope filtering
            $searchResults = $this->activityLogService->searchActivityLogs(
                $currentUser,
                $searchTerm,
                $shippingLineScope,
                $filters
            );
            
            // Log the search action
            $this->activityLogService->logSearch($currentUser, $searchTerm, $searchResults, 'activity_logs');
            
            return $this->json([
                'success' => true,
                'data' => array_map(function(ActivityLog $log) {
                    return [
                        'id' => $log->getId(),
                        'activityType' => $log->getActivityType(),
                        'activityDescription' => $log->getActivityDescription(),
                        'user' => [
                            'id' => $log->getUser()->getId(),
                            'email' => $log->getUser()->getEmail(),
                            'role' => $log->getUser()->getRole()->value
                        ],
                        'shippingLine' => $log->getShippingLine() ? [
                            'id' => $log->getShippingLine()->getId(),
                            'brandName' => $log->getShippingLine()->getBrandName()
                        ] : null,
                        'entityType' => $log->getEntityType(),
                        'entityId' => $log->getEntityId(),
                        'ipAddress' => $log->getIpAddress(),
                        'createdAt' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
                        'isSecurityActivity' => $log->isSecurityActivity(),
                        'isBusinessActivity' => $log->isBusinessActivity()
                    ];
                }, $searchResults),
                'searchTerm' => $searchTerm,
                'resultCount' => count($searchResults),
                'scope' => $shippingLineScope ? [
                    'id' => $shippingLineScope->getId(),
                    'brandName' => $shippingLineScope->getBrandName()
                ] : null
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'activity_logs_search', $e->getMessage());
            
            return $this->json(['success' => false, 'error' => 'Search failed: ' . $e->getMessage()], 500);
        }
    }

    // Private helper methods

    private function getAvailableEntityTypes(?ShippingLine $shippingLineScope): array
    {
        // Get distinct entity types from activity logs within scope
        $qb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT al.entityType')
            ->from(ActivityLog::class, 'al')
            ->where('al.entityType IS NOT NULL');
        
        if ($shippingLineScope) {
            $qb->andWhere('al.shippingLine = :shippingLine OR al.shippingLine IS NULL')
               ->setParameter('shippingLine', $shippingLineScope);
        }
        
        $result = $qb->getQuery()->getResult();
        return array_column($result, 'entityType');
    }

    private function getAvailableUsers(?ShippingLine $shippingLineScope): array
    {
        // Get users within scope
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u.id, u.email, u.role')
            ->from(User::class, 'u')
            ->orderBy('u.email', 'ASC');
        
        if ($shippingLineScope) {
            $qb->where('u.managedShippingLine = :shippingLine OR u.shippingLineAdmin IN (
                SELECT sa.id FROM App\Entity\User sa WHERE sa.managedShippingLine = :shippingLine
            )')
            ->setParameter('shippingLine', $shippingLineScope);
        }
        
        return $qb->getQuery()->getResult();
    }

    private function generateSummaryReport(User $currentUser, ?ShippingLine $shippingLineScope, string $from, string $to): array
    {
        return $this->activityLogService->generateSummaryReport(
            $currentUser,
            $shippingLineScope,
            new \DateTime($from),
            new \DateTime($to)
        );
    }

    private function generateSecurityReport(User $currentUser, ?ShippingLine $shippingLineScope, string $from, string $to): array
    {
        return $this->activityLogService->generateSecurityReport(
            $currentUser,
            $shippingLineScope,
            new \DateTime($from),
            new \DateTime($to)
        );
    }

    private function generateUserActivityReport(User $currentUser, ?ShippingLine $shippingLineScope, string $from, string $to): array
    {
        return $this->activityLogService->generateUserActivityReport(
            $currentUser,
            $shippingLineScope,
            new \DateTime($from),
            new \DateTime($to)
        );
    }

    private function generateBusinessOperationsReport(User $currentUser, ?ShippingLine $shippingLineScope, string $from, string $to): array
    {
        return $this->activityLogService->generateBusinessOperationsReport(
            $currentUser,
            $shippingLineScope,
            new \DateTime($from),
            new \DateTime($to)
        );
    }
}