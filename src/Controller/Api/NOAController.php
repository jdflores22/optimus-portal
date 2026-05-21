<?php

namespace App\Controller\Api;

use App\Service\NOAService;
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

#[Route('/api', name: 'api_noa_')]
class NOAController extends BaseApiController
{
    public function __construct(
        private NOAService $noaService,
        private ManifestAuthorizationService $authorizationService,
        private AuditService $auditService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/manifests/{id}/noa', name: 'generate', methods: ['POST'])]
    public function generateNOA(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SL_STAFF can generate NOA
        $roleCheck = $this->requireRole($user, [UserRole::SL_STAFF->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        // Validate required fields
        $validation = $this->validateRequiredFields($data, ['arrivalDate', 'vesselInfo']);
        if ($validation) {
            return $validation;
        }

        // Validate vesselInfo is an array
        if (!is_array($data['vesselInfo'])) {
            return $this->errorResponse('vesselInfo must be an object');
        }

        // Validate vesselInfo required fields
        if (!isset($data['vesselInfo']['name']) || trim($data['vesselInfo']['name']) === '') {
            return $this->errorResponse('vesselInfo.name is required');
        }

        try {
            $noa = $this->noaService->generateNOA($id, $data, $user);

            return $this->jsonResponse([
                'noaId' => $noa->getId(),
                'noaNumber' => $noa->getNoaNumber(),
                'manifestId' => $noa->getManifest()->getId(),
                'pdfUrl' => $noa->getPdfPath(),
                'generatedAt' => $noa->getCreatedAt()->format('Y-m-d H:i:s')
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate NOA: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/noa/{noaNumber}/download', name: 'download', methods: ['GET'])]
    public function downloadNOA(string $noaNumber, Request $request): JsonResponse|BinaryFileResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Find NOA by number
            $noa = $this->noaService->getNOAByNumber($noaNumber);
            
            if (!$noa) {
                return $this->errorResponse('NOA not found', 404);
            }

            // Check authorization
            $manifest = $noa->getManifest();
            if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                return $this->errorResponse('Access denied', 403);
            }

            // Log document download
            $this->auditService->logDocumentDownload($user, 'NOA', $noa->getId());

            // Serve the PDF file
            $pdfPath = $noa->getPdfPath();
            
            if (!file_exists($pdfPath)) {
                return $this->errorResponse('NOA file not found', 404);
            }

            $response = new BinaryFileResponse($pdfPath);
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'NOA-' . $noaNumber . '.pdf'
            );

            return $response;

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to download NOA: ' . $e->getMessage(), 500);
        }
    }
}
