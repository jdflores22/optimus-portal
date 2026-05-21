<?php

namespace App\Controller\Api;

use App\Service\ManifestService;
use App\Service\ManifestAuthorizationService;
use App\Service\FileStorageServiceInterface;
use App\Service\AuditService;
use App\Service\ActivityLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_bl_')]
class BLController extends AbstractController
{
    public function __construct(
        private ManifestService $manifestService,
        private ManifestAuthorizationService $authorizationService,
        private FileStorageServiceInterface $fileStorage,
        private AuditService $auditService,
        private ActivityLogService $activityLogService
    ) {
    }

    #[Route('/bl/{blNumber}/download', name: 'download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadBL(string $blNumber): JsonResponse|BinaryFileResponse
    {
        $user = $this->getUser();

        try {
            // Find manifest by BL number
            $manifest = $this->manifestService->getManifestByBlNumber($blNumber);
            
            if (!$manifest) {
                return $this->json(['error' => 'BL not found'], 404);
            }

            // Check authorization
            if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                return $this->json(['error' => 'Access denied'], 403);
            }

            $filePath = $manifest->getBlFilePath();
            
            if (!$filePath) {
                return $this->json(['error' => 'BL file not found'], 404);
            }

            // Strip /uploads prefix if present (for backward compatibility)
            if (str_starts_with($filePath, '/uploads/')) {
                $filePath = substr($filePath, 8); // Remove '/uploads'
            }

            if (!$this->fileStorage->fileExists($filePath)) {
                return $this->json(['error' => 'BL file not found on storage'], 404);
            }

            // Log document download
            $this->auditService->logDocumentDownload($user, 'BL', $manifest->getId());
            
            // Log to activity log for notifications
            $this->activityLogService->logManifestDocumentDownload($user, $manifest, 'bl');

            // Serve the file
            $fullPath = $this->fileStorage->getFullPath($filePath);
            
            $response = new BinaryFileResponse($fullPath);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'BL-' . $blNumber . '.pdf'
            );

            return $response;

        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to download BL: ' . $e->getMessage()], 500);
        }
    }
}
