<?php

namespace App\Controller\Api;

use App\Service\ManifestService;
use App\Service\ManifestAuthorizationService;
use App\Service\FileService;
use App\Service\FileStorageServiceInterface;
use App\Service\ActivityLogService;
use App\Service\AuditService;
use App\Service\ManifestNotificationService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\WorkflowState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/manifests', name: 'api_manifests_')]
class ManifestController extends BaseApiController
{
    public function __construct(
        private ManifestService $manifestService,
        private ManifestAuthorizationService $authorizationService,
        private FileService $fileService,
        private FileStorageServiceInterface $fileStorage,
        private ActivityLogService $activityLogService,
        private AuditService $auditService,
        private ManifestNotificationService $notificationService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function uploadManifest(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SL_STAFF can upload manifests
        $roleCheck = $this->requireRole($user, [UserRole::SL_STAFF->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        // Check if this is a multipart/form-data request (file upload)
        $isFileUpload = $request->files->count() > 0;
        
        if ($isFileUpload) {
            // Handle file upload with form data
            $manifestNumber = $request->request->get('manifestNumber');
            $consigneeId = $request->request->get('consigneeId');
            $vesselName = $request->request->get('vesselName');
            $voyageNumber = $request->request->get('voyageNumber');
            $arrivalDate = $request->request->get('arrivalDate');
            $manifestFile = $request->files->get('manifestFile');
            
            // Validate required fields
            if (!$manifestNumber) {
                return $this->errorResponse('Manifest number is required');
            }
            
            if (!$consigneeId) {
                return $this->errorResponse('Consignee is required');
            }
            
            // Validate manifest number format
            if (!preg_match('/^[A-Z0-9\-]+$/', $manifestNumber)) {
                return $this->errorResponse('Manifest number must contain only uppercase letters, numbers, and hyphens');
            }
            
            // Validate file if provided
            if ($manifestFile) {
                $fileValidation = $this->validateFileUpload(
                    $manifestFile,
                    ['application/pdf', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'],
                    10485760 // 10MB
                );
                if ($fileValidation) {
                    return $fileValidation;
                }
            }
            
            try {
                $manifestData = [
                    'manifestNumber' => $manifestNumber,
                    'vesselName' => $vesselName ?: null,
                    'voyageNumber' => $voyageNumber ?: null,
                    'arrivalDate' => $arrivalDate ?: null
                ];

                // Create manifest
                $manifest = $this->manifestService->uploadManifest($manifestData, $user);
                
                // Upload file if provided
                if ($manifestFile) {
                    $storedFile = $this->fileService->uploadFile($manifestFile, 'manifest', $user);
                    $relativePath = $this->getRelativePath($storedFile->getEncryptedPath());
                    $manifest->setManifestFilePath($relativePath);
                }
                
                // Declare consignee
                $this->manifestService->declareConsignee($manifest->getId(), (int)$consigneeId, $user);
                
                // Refresh manifest to get updated data
                $manifest = $this->manifestService->getManifestById($manifest->getId());

                return $this->jsonResponse([
                    'id' => $manifest->getId(),
                    'manifestNumber' => $manifest->getManifestNumber(),
                    'workflowState' => $manifest->getWorkflowState()->value,
                    'consignee' => [
                        'id' => $manifest->getConsignee()->getId(),
                        'businessName' => $manifest->getConsignee()->getBusinessName()
                    ],
                    'linkedBroker' => $manifest->getBroker() ? [
                        'id' => $manifest->getBroker()->getId(),
                        'fullName' => $manifest->getBroker()->getFullName()
                    ] : null,
                    'createdAt' => $manifest->getCreatedAt()->format('Y-m-d H:i:s')
                ], 201);

            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($e->getMessage(), 400);
            } catch (\Exception $e) {
                return $this->errorResponse('Failed to upload manifest: ' . $e->getMessage(), 500);
            }
        } else {
            // Handle JSON request (legacy support)
            $data = json_decode($request->getContent(), true);
            
            // Validate required fields
            $validation = $this->validateRequiredFields($data, ['manifestNumber']);
            if ($validation) {
                return $validation;
            }

            // Validate manifest number format (alphanumeric with hyphens)
            if (!preg_match('/^[A-Z0-9\-]+$/', $data['manifestNumber'])) {
                return $this->errorResponse('Manifest number must contain only uppercase letters, numbers, and hyphens');
            }

            try {
                $manifestData = [
                    'manifestNumber' => $data['manifestNumber'],
                    'vesselName' => $data['vesselName'] ?? null,
                    'voyageNumber' => $data['voyageNumber'] ?? null,
                    'arrivalDate' => $data['arrivalDate'] ?? null
                ];

                $manifest = $this->manifestService->uploadManifest($manifestData, $user);

                return $this->jsonResponse([
                    'id' => $manifest->getId(),
                    'manifestNumber' => $manifest->getManifestNumber(),
                    'workflowState' => $manifest->getWorkflowState()->value,
                    'createdAt' => $manifest->getCreatedAt()->format('Y-m-d H:i:s')
                ], 201);

            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($e->getMessage(), 400);
            } catch (\Exception $e) {
                return $this->errorResponse('Failed to upload manifest: ' . $e->getMessage(), 500);
            }
        }
    }

    #[Route('/with-edo', name: 'create_with_edo', methods: ['POST'])]
    public function createManifestWithEDO(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only BROKER can create manifests with eDO generation
        $roleCheck = $this->requireRole($user, [UserRole::BROKER->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        // Requirement 12.2: Restrict Manifest creation to Broker role
        $this->denyAccessUnlessGranted('create', 'Manifest');

        // Check if this is a multipart/form-data request (file upload)
        $isFileUpload = $request->files->count() > 0;
        
        if ($isFileUpload) {
            // Handle file upload with form data
            $noaId = $request->request->get('noaId');
            $blNumber = $request->request->get('blNumber');
            $blFile = $request->files->get('blFile');
            $manifestNumber = $request->request->get('manifestNumber');
            
            // Validate required fields
            if (!$noaId) {
                return $this->errorResponse('NOA ID is required');
            }
            
            if (!$blNumber) {
                return $this->errorResponse('BL number is required');
            }
            
            if (!$blFile) {
                return $this->errorResponse('BL file is required');
            }
            
            // Validate BL number format
            if (!preg_match('/^[A-Z0-9\-]+$/', $blNumber)) {
                return $this->errorResponse('BL number must contain only uppercase letters, numbers, and hyphens');
            }
            
            // Validate file
            $fileValidation = $this->validateFileUpload(
                $blFile,
                ['application/pdf', 'image/jpeg', 'image/png'],
                10485760 // 10MB
            );
            if ($fileValidation) {
                return $fileValidation;
            }
            
            try {
                // Upload BL file
                $storedFile = $this->fileService->uploadFile($blFile, 'bl', $user);
                $relativePath = $this->getRelativePath($storedFile->getEncryptedPath());
                
                $manifestData = [
                    'noaId' => (int)$noaId,
                    'blNumber' => $blNumber,
                    'blFilePath' => $relativePath,
                    'manifestNumber' => $manifestNumber
                ];

                // Create manifest with eDO generation
                $manifest = $this->manifestService->createManifestWithEDO($manifestData, $user);

                return $this->jsonResponse([
                    'id' => $manifest->getId(),
                    'manifestNumber' => $manifest->getManifestNumber(),
                    'workflowState' => $manifest->getWorkflowState()->value,
                    'blNumber' => $manifest->getBlNumber(),
                    'consignee' => [
                        'id' => $manifest->getConsignee()->getId(),
                        'businessName' => $manifest->getConsignee()->getBusinessName()
                    ],
                    'noa' => [
                        'id' => $manifest->getNoa()->getId(),
                        'noaNumber' => $manifest->getNoa()->getNoaNumber()
                    ],
                    'createdAt' => $manifest->getCreatedAt()->format('Y-m-d H:i:s')
                ], 201);

            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($e->getMessage(), 400);
            } catch (\RuntimeException $e) {
                return $this->errorResponse($e->getMessage(), 500);
            } catch (\Exception $e) {
                return $this->errorResponse('Failed to create manifest with eDO: ' . $e->getMessage(), 500);
            }
        } else {
            return $this->errorResponse('BL file upload is required', 400);
        }
    }

    #[Route('/{id}/declare-consignee', name: 'declare_consignee', methods: ['POST'])]
    public function declareConsignee(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SL_STAFF can declare consignee
        $roleCheck = $this->requireRole($user, [UserRole::SL_STAFF->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        // Validate required fields
        $validation = $this->validateRequiredFields($data, ['consigneeId']);
        if ($validation) {
            return $validation;
        }

        // Validate consigneeId is numeric
        $numValidation = $this->validateNumeric($data['consigneeId'], 'consigneeId', 1);
        if ($numValidation) {
            return $numValidation;
        }

        try {
            $this->manifestService->declareConsignee($id, $data['consigneeId'], $user);
            
            $manifest = $this->manifestService->getManifestById($id);
            
            return $this->jsonResponse([
                'manifestId' => $manifest->getId(),
                'consignee' => [
                    'id' => $manifest->getConsignee()->getId(),
                    'businessName' => $manifest->getConsignee()->getBusinessName()
                ],
                'linkedBroker' => $manifest->getBroker() ? [
                    'id' => $manifest->getBroker()->getId(),
                    'fullName' => $manifest->getBroker()->getFullName()
                ] : null
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to declare consignee: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'])]
    public function getManifestDetail(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $manifest = $this->manifestService->getManifestById($id);
            
            if (!$manifest) {
                return $this->errorResponse('Manifest not found', 404);
            }

            // Check authorization
            if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                return $this->errorResponse('Access denied', 403);
            }

            return $this->jsonResponse([
                'id' => $manifest->getId(),
                'manifestNumber' => $manifest->getManifestNumber(),
                'workflowState' => $manifest->getWorkflowState()->value,
                'consignee' => $manifest->getConsignee() ? [
                    'id' => $manifest->getConsignee()->getId(),
                    'businessName' => $manifest->getConsignee()->getBusinessName()
                ] : null,
                'broker' => $manifest->getBroker() ? [
                    'id' => $manifest->getBroker()->getId(),
                    'fullName' => $manifest->getBroker()->getFullName()
                ] : null,
                'vesselName' => $manifest->getVesselName(),
                'voyageNumber' => $manifest->getVoyageNumber(),
                'arrivalDate' => $manifest->getArrivalDate()?->format('Y-m-d H:i:s'),
                'blNumber' => $manifest->getBlNumber(),
                'blFilePath' => $manifest->getBlFilePath(),
                'createdAt' => $manifest->getCreatedAt()->format('Y-m-d H:i:s'),
                'payments' => array_map(function($payment) {
                    return [
                        'id' => $payment->getId(),
                        'paymentType' => $payment->getPaymentType()->value,
                        'amount' => $payment->getAmount(),
                        'status' => $payment->getStatus()->value,
                        'submittedBy' => $payment->getSubmittedBy()->getFullName(),
                        'createdAt' => $payment->getCreatedAt()->format('Y-m-d H:i:s')
                    ];
                }, $manifest->getPayments()->toArray()),
                'billing' => $manifest->getBilling() ? [
                    'id' => $manifest->getBilling()->getId(),
                    'totalAmount' => $manifest->getBilling()->getTotalAmount(),
                    'pdfPath' => $manifest->getBilling()->getPdfPath()
                ] : null,
                'noa' => $manifest->getNoaDocument() ? [
                    'id' => $manifest->getNoaDocument()->getId(),
                    'noaNumber' => $manifest->getNoaDocument()->getNoaNumber(),
                    'pdfPath' => $manifest->getNoaDocument()->getPdfPath()
                ] : null,
                'edo' => $manifest->getEdo() ? [
                    'id' => $manifest->getEdo()->getId(),
                    'edoNumber' => $manifest->getEdo()->getEdoNumber(),
                    'pdfPath' => $manifest->getEdo()->getPdfPath()
                ] : null
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve manifest details: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}/bl', name: 'upload_bl', methods: ['POST'])]
    public function uploadBL(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $manifest = $this->manifestService->getManifestById($id);
            
            if (!$manifest) {
                return $this->errorResponse('Manifest not found', 404);
            }

            // Check authorization
            if (!$this->authorizationService->canUploadBL($manifest, $user)) {
                return $this->errorResponse('Access denied', 403);
            }

            // Validate workflow state - allow both NOA_GENERATED and BL_GENERATED
            if (!in_array($manifest->getWorkflowState(), [WorkflowState::NOA_GENERATED, WorkflowState::BL_GENERATED])) {
                return $this->errorResponse('Manifest must be in noa_generated or bl_generated state to upload BL', 400);
            }

            $blFile = $request->files->get('blFile');
            $blNumber = $request->request->get('blNumber');

            // Validate file upload
            $fileValidation = $this->validateFileUpload(
                $blFile,
                ['application/pdf', 'image/jpeg', 'image/png'],
                10485760 // 10MB
            );
            if ($fileValidation) {
                return $fileValidation;
            }

            // Validate BL number
            if (!$blNumber || trim($blNumber) === '') {
                return $this->errorResponse('BL number is required');
            }

            // Validate BL number format
            if (!preg_match('/^[A-Z0-9\-]+$/', $blNumber)) {
                return $this->errorResponse('BL number must contain only uppercase letters, numbers, and hyphens');
            }

            // Upload file using FileService
            $storedFile = $this->fileService->uploadFile($blFile, 'bl', $user);

            // Convert absolute path to relative path for web access
            $relativePath = $this->getRelativePath($storedFile->getEncryptedPath());

            // Update manifest with BL information and transition state
            $manifest->setBlNumber($blNumber);
            $manifest->setBlFilePath($relativePath);
            
            // Transition state using service
            $this->manifestService->transitionState($manifest, WorkflowState::BL_UPLOADED, $user);

            // Log BL upload to audit log
            $this->auditService->logAction(
                $user,
                'bl_upload',
                'Manifest',
                $manifest->getId(),
                [
                    'bl_number' => $blNumber,
                    'manifest_id' => $manifest->getId(),
                    'manifest_number' => $manifest->getManifestNumber(),
                    'filename' => $blFile->getClientOriginalName()
                ]
            );

            // Log BL upload to activity log
            $this->activityLogService->logBLUpload($user, $manifest, $blFile->getClientOriginalName());

            // Notify SL_STAFF about BL upload
            $this->notificationService->notifyBLUploaded($manifest, $user);

            return $this->jsonResponse([
                'manifestId' => $manifest->getId(),
                'blNumber' => $manifest->getBlNumber(),
                'blFilePath' => $manifest->getBlFilePath(),
                'workflowState' => $manifest->getWorkflowState()->value
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to upload BL: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/{id}/bl/download', name: 'download_bl', methods: ['GET'])]
    public function downloadBL(int $id, Request $request): JsonResponse|BinaryFileResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $manifest = $this->manifestService->getManifestById($id);
            
            if (!$manifest) {
                return $this->errorResponse('Manifest not found', 404);
            }

            // Check authorization
            if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                return $this->errorResponse('Access denied', 403);
            }

            $filePath = $manifest->getBlFilePath();
            $blNumber = $manifest->getBlNumber();

            if (!$filePath || !$blNumber) {
                return $this->errorResponse('BL not uploaded yet', 404);
            }

            if (!$this->fileStorage->fileExists($filePath)) {
                // Log the file path for debugging
                error_log("BL file not found. Path: " . $filePath);
                error_log("Full path would be: " . $this->fileStorage->getFullPath($filePath));
                return $this->errorResponse('BL file not found', 404);
            }

            // Log download
            $this->auditService->logDocumentDownload($user, 'BL', $manifest->getId());
            
            // Log to activity log for notifications
            $this->activityLogService->logManifestDocumentDownload($user, $manifest, 'bl');

            // Serve file
            $fullPath = $this->fileStorage->getFullPath($filePath);
            $response = new BinaryFileResponse($fullPath);
            
            $response->headers->set('Content-Type', 'application/pdf');
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'BL-' . $blNumber . '.pdf'
            );

            return $response;

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to download BL: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Convert absolute file path to relative web-accessible path
     */
    private function getRelativePath(string $absolutePath): string
    {
        // Get the project root directory
        $projectRoot = dirname(__DIR__, 3); // Go up 3 levels from Controller/Api
        
        // Remove the project root and public directory from the path
        $relativePath = str_replace($projectRoot, '', $absolutePath);
        $relativePath = str_replace('\\', '/', $relativePath); // Convert Windows backslashes to forward slashes
        $relativePath = str_replace('/public', '', $relativePath); // Remove /public prefix
        
        // Ensure the path starts with /
        if (!str_starts_with($relativePath, '/')) {
            $relativePath = '/' . $relativePath;
        }
        
        return $relativePath;
    }
}
