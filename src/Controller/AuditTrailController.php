<?php

namespace App\Controller;

use App\Service\AuditService;
use App\Service\AuditLogExportService;
use App\Service\ManifestService;
use App\Service\ManifestAuthorizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/audit')]
#[IsGranted('ROLE_USER')]
class AuditTrailController extends AbstractController
{
    public function __construct(
        private AuditService $auditService,
        private AuditLogExportService $exportService,
        private ManifestService $manifestService,
        private ManifestAuthorizationService $authorizationService
    ) {
    }

    #[Route('/manifest/{id}', name: 'audit_trail_manifest', methods: ['GET'])]
    public function manifestAuditTrail(int $id, Request $request): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        
        // Check if user can view this manifest
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Get filter parameters
        $actionType = $request->query->get('action_type');
        $userId = $request->query->get('user_id');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        // Build search criteria
        $criteria = [
            'entityType' => 'Manifest',
            'entityId' => $id,
        ];

        if ($actionType) {
            $criteria['action'] = $actionType;
        }

        if ($userId) {
            $criteria['userId'] = (int) $userId;
        }

        if ($dateFrom) {
            $criteria['startDate'] = new \DateTime($dateFrom);
        }

        if ($dateTo) {
            $criteria['endDate'] = new \DateTime($dateTo . ' 23:59:59');
        }

        // Get audit logs - if no filters, use the enhanced method that includes eDO history
        if (!$actionType && !$userId && !$dateFrom && !$dateTo) {
            $auditLogs = $this->auditService->getManifestAuditTrailWithEDO($id);
        } else {
            // For filtered results, search both Manifest and EDO logs
            $manifestLogs = $this->auditService->searchLogs($criteria);
            
            // Also search for EDO logs related to this manifest
            $edoCriteria = $criteria;
            $edoCriteria['entityType'] = 'ElectronicDeliveryOrder';
            unset($edoCriteria['entityId']); // We'll filter by manifest_id in changes
            
            $edoLogs = $this->auditService->searchLogs($edoCriteria);
            
            // Filter EDO logs to only those related to this manifest
            $edoLogs = array_filter($edoLogs, function($log) use ($id) {
                $changes = $log->getChanges();
                return isset($changes['manifest_id']) && $changes['manifest_id'] == $id;
            });
            
            // Combine and sort
            $auditLogs = array_merge($manifestLogs, $edoLogs);
            usort($auditLogs, function($a, $b) {
                return $b->getTimestamp() <=> $a->getTimestamp();
            });
        }

        // Get unique action types for filter dropdown
        // Use the enhanced method that includes eDO release history (Requirement 12.5)
        $allLogs = $this->auditService->getManifestAuditTrailWithEDO($id);
        $actionTypes = array_unique(array_map(fn($log) => $log->getAction(), $allLogs));
        sort($actionTypes);

        // Get unique users for filter dropdown
        $users = [];
        $seenUserIds = [];
        foreach ($allLogs as $log) {
            $logUser = $log->getUser();
            if (!in_array($logUser->getId(), $seenUserIds)) {
                $users[] = $logUser;
                $seenUserIds[] = $logUser->getId();
            }
        }

        return $this->render('audit/manifest_trail.html.twig', [
            'manifest' => $manifest,
            'auditLogs' => $auditLogs,
            'actionTypes' => $actionTypes,
            'users' => $users,
            'filters' => [
                'action_type' => $actionType,
                'user_id' => $userId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    #[Route('/edo', name: 'audit_trail_edo_search', methods: ['GET'])]
    #[IsGranted('ROLE_SYSTEM_ADMIN')]
    public function edoAuditSearch(): Response
    {
        return $this->render('audit/edo_trail.html.twig');
    }

    #[Route('/export/manifest/{id}', name: 'audit_trail_export', methods: ['GET'])]
    #[IsGranted('ROLE_SYSTEM_ADMIN')]
    public function exportManifestAuditTrail(int $id, Request $request): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $format = $request->query->get('format', 'csv');
        
        // Get all audit logs for this manifest including eDO release history (Requirement 12.5)
        $auditLogs = $this->auditService->getManifestAuditTrailWithEDO($id);

        if ($format === 'csv') {
            $filepath = $this->exportService->exportToCSV($manifest, $auditLogs);
            return $this->createFileResponse($filepath, 'text/csv');
        } elseif ($format === 'json') {
            $filepath = $this->exportService->exportToJSON($manifest, $auditLogs);
            return $this->createFileResponse($filepath, 'application/json');
        } elseif ($format === 'pdf') {
            $filepath = $this->exportService->exportToPDF($manifest, $auditLogs);
            return $this->createFileResponse($filepath, 'application/pdf');
        }

        throw $this->createNotFoundException('Invalid export format');
    }

    private function createFileResponse(string $filepath, string $contentType): BinaryFileResponse
    {
        $response = new BinaryFileResponse($filepath);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . basename($filepath) . '"');
        
        return $response;
    }
}
