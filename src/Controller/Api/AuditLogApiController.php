<?php

namespace App\Controller\Api;

use App\Entity\Enum\UserRole;
use App\Service\EdoAuditTrailQueryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/audit-logs', name: 'api_audit_logs_')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class AuditLogApiController extends BaseApiController
{
    public function __construct(
        protected \App\Service\JwtService $jwtService,
        protected \App\Service\UserService $userService,
        private EdoAuditTrailQueryService $edoAuditTrailQueryService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/container/{containerNumber}', name: 'by_container', methods: ['GET'])]
    public function byContainer(Request $request, string $containerNumber): JsonResponse
    {
        $authResponse = $this->requireAuthentication($request);
        if ($authResponse instanceof JsonResponse) {
            return $authResponse;
        }

        $containerNumber = trim(urldecode($containerNumber));
        if ($containerNumber === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Container number is required',
            ], 400);
        }

        $auditLogs = $this->edoAuditTrailQueryService->searchByContainerNumber($containerNumber);

        return $this->jsonResponse([
            'success' => true,
            'audit_logs' => $auditLogs,
        ]);
    }

    #[Route('/edo/{edoNumber}', name: 'by_edo', methods: ['GET'])]
    public function byEdo(Request $request, string $edoNumber): JsonResponse
    {
        $authResponse = $this->requireAuthentication($request);
        if ($authResponse instanceof JsonResponse) {
            return $authResponse;
        }

        $edoNumber = trim(urldecode($edoNumber));
        if ($edoNumber === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'eDO number is required',
            ], 400);
        }

        $auditLogs = $this->edoAuditTrailQueryService->searchByEdoNumber($edoNumber);

        return $this->jsonResponse([
            'success' => true,
            'audit_logs' => $auditLogs,
        ]);
    }
}
