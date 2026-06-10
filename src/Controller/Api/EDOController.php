<?php

namespace App\Controller\Api;

use App\Service\EDOService;
use App\Service\ManifestAuthorizationService;
use App\Service\AuditService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\Enum\UserRole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_edo_')]
class EDOController extends BaseApiController
{
    public function __construct(
        private EDOService $edoService,
        private ManifestAuthorizationService $authorizationService,
        private AuditService $auditService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/manifests/{id}/edo', name: 'get', methods: ['GET'])]
    public function getEDODetails(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // SL_STAFF, Broker, and Consignee can view EDO
        $roleCheck = $this->requireRole($user, [
            UserRole::SL_STAFF->value,
            UserRole::BROKER->value,
            UserRole::CONSIGNEE->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $edo = $this->edoService->getEDOByManifest($id);
            
            if (!$edo) {
                return $this->errorResponse('EDO not found', 404);
            }

            // Check authorization for broker and consignee
            $manifest = $edo->getManifest();
            if (in_array($user->getRole()->value, [UserRole::BROKER->value, UserRole::CONSIGNEE->value])) {
                if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                    return $this->errorResponse('Access denied', 403);
                }
            }

            return $this->jsonResponse([
                'edoId' => $edo->getId(),
                'edoNumber' => $edo->getEdoNumber(),
                'manifestId' => $manifest->getId(),
                'manifestNumber' => $manifest->getManifestNumber(),
                'blNumber' => $manifest->getBlNumber(),
                'consignee' => $manifest->getConsignee() ? [
                    'id' => $manifest->getConsignee()->getId(),
                    'businessName' => $manifest->getConsignee()->getBusinessName()
                ] : null,
                'pdfUrl' => $edo->getPdfPath(),
                'digitalSignature' => $edo->getDigitalSignature(),
                'generatedAt' => $edo->getGeneratedAt()->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve EDO details: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/edos/{edoNumber}/download', name: 'download', methods: ['GET'])]
    public function downloadEDO(string $edoNumber, Request $request): JsonResponse|BinaryFileResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Broker and Consignee can download EDO
        $roleCheck = $this->requireRole($user, [
            UserRole::BROKER->value,
            UserRole::CONSIGNEE->value,
            UserRole::SL_STAFF->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            // Find EDO by number
            $edo = $this->edoService->getEDOByNumber($edoNumber);
            
            if (!$edo) {
                return $this->errorResponse('EDO not found', 404);
            }

            // Check authorization
            $manifest = $edo->getManifest();
            
            // SL_STAFF can always download
            if ($user->getRole()->value !== UserRole::SL_STAFF->value) {
                // Broker and Consignee must be authorized
                if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                    // Log unauthorized access attempt
                    $this->auditService->logAction(
                        $user,
                        'unauthorized_access_attempt',
                        'EDO',
                        $edo->getId(),
                        ['edo_number' => $edoNumber, 'reason' => 'not_authorized_for_manifest']
                    );
                    return $this->errorResponse('Access denied', 403);
                }
                
                // CRITICAL SECURITY CHECK: Verify EDO payment is verified before allowing download
                $edoPayment = $edo->getEdoPayment();
                if (!$edoPayment) {
                    // Log payment bypass attempt
                    $this->auditService->logAction(
                        $user,
                        'payment_bypass_attempt',
                        'EDO',
                        $edo->getId(),
                        ['edo_number' => $edoNumber, 'reason' => 'no_payment_submitted']
                    );
                    return $this->errorResponse('Payment required to access eDO', 402);
                }
                
                if ($edoPayment->getStatus()->value !== 'verified') {
                    // Log payment verification bypass attempt
                    $this->auditService->logAction(
                        $user,
                        'payment_bypass_attempt',
                        'EDO',
                        $edo->getId(),
                        [
                            'edo_number' => $edoNumber,
                            'payment_status' => $edoPayment->getStatus()->value,
                            'payment_id' => $edoPayment->getId(),
                            'reason' => 'payment_not_verified'
                        ]
                    );
                    return $this->errorResponse('Payment must be verified before downloading eDO. Current status: ' . $edoPayment->getStatus()->value, 402);
                }
            }

            // Log document download
            $this->auditService->logDocumentDownload($user, 'EDO', $edo->getId());

            // Serve the PDF file
            $pdfPath = $edo->getPdfPath();
            
            // Construct full path - pdfPath is relative to public/uploads
            $fullPath = $this->getParameter('kernel.project_dir') . '/public/uploads/' . $pdfPath;
            
            if (!file_exists($fullPath)) {
                return $this->errorResponse('EDO file not found', 404);
            }

            $response = new BinaryFileResponse($fullPath);
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'EDO-' . $edoNumber . '.pdf'
            );

            return $response;

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to download EDO: ' . $e->getMessage(), 500);
        }
    }
}
