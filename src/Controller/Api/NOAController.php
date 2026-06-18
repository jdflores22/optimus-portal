<?php

namespace App\Controller\Api;

use App\Entity\Manifest;
use App\Entity\NOA;
use App\Service\NOAService;
use App\Service\ManifestAuthorizationService;
use App\Service\FileStorageServiceInterface;
use App\Service\AuditService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\Enum\UserRole;
use App\Security\Voter\NOAVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
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
        private FileStorageServiceInterface $fileStorage,
        private EntityManagerInterface $entityManager,
        private ParameterBagInterface $parameterBag,
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

    #[Route('/noa/{noaNumber}/download-legacy', name: 'legacy_download', methods: ['GET'])]
    public function downloadNOA(string $noaNumber, Request $request): JsonResponse|BinaryFileResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $noaDocument = $this->noaService->getNOAByNumber($noaNumber);

            if ($noaDocument) {
                $manifest = $noaDocument->getManifest();
                if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                    return $this->errorResponse('Access denied', 403);
                }

                $fullPath = $this->resolveNoaPdfPath($noaDocument->getPdfPath());
                if (!$fullPath) {
                    return $this->errorResponse('NOA file not found', 404);
                }

                $this->auditService->logDocumentDownload($user, 'NOA', $noaDocument->getId());

                return $this->createPdfDownloadResponse($fullPath, $noaNumber);
            }

            $workflowNoa = $this->noaService->getWorkflowNOAByNumber($noaNumber);
            if (!$workflowNoa) {
                return $this->errorResponse('NOA not found', 404);
            }

            if (!$this->canDownloadWorkflowNoa($workflowNoa, $user)) {
                return $this->errorResponse('Access denied', 403);
            }

            $fullPath = $this->resolveNoaPdfPath($workflowNoa->getPdfPath());
            if (!$fullPath) {
                return $this->errorResponse('NOA file not found', 404);
            }

            $this->auditService->logDocumentDownload($user, 'NOA', $workflowNoa->getId());

            return $this->createPdfDownloadResponse($fullPath, $noaNumber);
        } catch (\Throwable $e) {
            return $this->errorResponse('Failed to download NOA: ' . $e->getMessage(), 500);
        }
    }

    private function canDownloadWorkflowNoa(NOA $noa, \App\Entity\User $user): bool
    {
        if ($this->isGranted(NOAVoter::VIEW, $noa)) {
            return true;
        }

        $manifest = $this->entityManager->getRepository(Manifest::class)
            ->findPrimaryForNoa($noa);

        return $manifest && $this->authorizationService->canViewManifest($manifest, $user);
    }

    private function resolveNoaPdfPath(?string $pdfPath): ?string
    {
        if (!$pdfPath) {
            return null;
        }

        if (file_exists($pdfPath)) {
            return $pdfPath;
        }

        if ($this->fileStorage->fileExists($pdfPath)) {
            return $this->fileStorage->getFullPath($pdfPath);
        }

        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $candidatePaths = [
            $projectDir . '/public/uploads/' . $pdfPath,
            $projectDir . '/var/share/' . $pdfPath,
        ];

        foreach ($candidatePaths as $candidatePath) {
            if (file_exists($candidatePath)) {
                return $candidatePath;
            }
        }

        return null;
    }

    private function createPdfDownloadResponse(string $fullPath, string $noaNumber): BinaryFileResponse
    {
        $response = new BinaryFileResponse($fullPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'NOA-' . $noaNumber . '.pdf'
        );

        return $response;
    }
}
