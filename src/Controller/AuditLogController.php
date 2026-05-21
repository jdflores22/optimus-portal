<?php

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Service\EDOAuditServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for eDO audit log queries
 * 
 * Provides endpoints for querying audit logs by container number and eDO number
 * with role-based access control.
 * 
 * Requirements: 14.8, 14.9
 */
#[Route('/api/audit-logs')]
#[IsGranted('ROLE_USER')]
class AuditLogController extends AbstractController
{
    public function __construct(
        private EDOAuditServiceInterface $auditService
    ) {
    }

    /**
     * Query audit logs by container number
     * 
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/container/{containerNumber}', name: 'audit_log_query_by_container', methods: ['GET'])]
    public function queryByContainer(string $containerNumber, Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        // Check if user has permission to view audit logs
        if (!$this->canViewAuditLogs($user)) {
            return $this->json([
                'error' => 'Access denied',
                'message' => 'You do not have permission to view audit logs'
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $auditLogs = $this->auditService->queryByContainer($containerNumber);
            
            return $this->json([
                'success' => true,
                'container_number' => $containerNumber,
                'total_records' => count($auditLogs),
                'audit_logs' => array_map(fn($log) => $this->formatAuditLog($log), $auditLogs)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Query failed',
                'message' => 'Failed to retrieve audit logs for container'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Query audit logs by eDO number
     * 
     * @param string $edoNumber
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/edo/{edoNumber}', name: 'audit_log_query_by_edo', methods: ['GET'])]
    public function queryByEDO(string $edoNumber, Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        // Check if user has permission to view audit logs
        if (!$this->canViewAuditLogs($user)) {
            return $this->json([
                'error' => 'Access denied',
                'message' => 'You do not have permission to view audit logs'
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $auditLogs = $this->auditService->queryByEDO($edoNumber);
            
            return $this->json([
                'success' => true,
                'edo_number' => $edoNumber,
                'total_records' => count($auditLogs),
                'audit_logs' => array_map(fn($log) => $this->formatAuditLog($log), $auditLogs)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Query failed',
                'message' => 'Failed to retrieve audit logs for eDO'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check if user can view audit logs
     * 
     * @param mixed $user
     * @return bool
     */
    private function canViewAuditLogs($user): bool
    {
        if (!$user) {
            return false;
        }

        // Allow System Admin, Shipping Lines Accounting, and Shipping Lines Terminal Team
        $allowedRoles = [
            UserRole::SYSTEM_ADMIN,
            UserRole::SHIPPING_LINES_ACCOUNTING,
            UserRole::SHIPPING_LINES_TERMINAL_TEAM
        ];

        return in_array($user->getRole(), $allowedRoles);
    }

    /**
     * Format audit log entry for JSON response
     * 
     * @param \App\Entity\EDOAuditLog $log
     * @return array
     */
    private function formatAuditLog($log): array
    {
        return [
            'id' => $log->getId(),
            'event_type' => $log->getEventType()->value,
            'edo_number' => $log->getEdo()->getEdoNumber(),
            'container_number' => $log->getContainer()->getContainerNumber(),
            'user' => [
                'id' => $log->getUser()->getId(),
                'email' => $log->getUser()->getEmail(),
                'full_name' => $log->getUser()->getFullName()
            ],
            'timestamp' => $log->getTimestamp()->format('Y-m-d H:i:s'),
            'details' => $log->getDetails()
        ];
    }
}
