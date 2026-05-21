<?php

namespace App\Controller;

use App\Entity\Container;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\PreAdviceRequest;
use App\Entity\Terminal;
use App\Entity\Trucker;
use App\Service\ContainerSearchService;
use App\Service\PreAdviceService;
use App\Service\TerminalService;
use App\Service\PhotoVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Response;

#[Route('/api/v1/pre-advice')]
#[IsGranted('ROLE_TRUCKER')]
class PreAdviceAPIController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContainerSearchService $containerSearchService,
        private TerminalService $terminalService,
        private PreAdviceService $preAdviceService,
        private PhotoVerificationService $photoVerificationService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Search for container by container number
     */
    #[Route('/container/search', name: 'api_container_search', methods: ['POST'])]
    public function searchContainer(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data || !isset($data['container_number'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container number is required',
                    'code' => 'MISSING_CONTAINER_NUMBER'
                ], 400);
            }

            $containerNumber = trim($data['container_number']);
            
            if (empty($containerNumber)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container number cannot be empty',
                    'code' => 'EMPTY_CONTAINER_NUMBER'
                ], 400);
            }

            // Validate container number format
            if (!$this->containerSearchService->validateContainerNumberFormat($containerNumber)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid container number format. Expected format: 4 letters + 7 digits (e.g., ABCD1234567)',
                    'code' => 'INVALID_FORMAT'
                ], 400);
            }

            // Search for container
            $containerDetails = $this->containerSearchService->getContainerDetails($containerNumber);
            
            if (!$containerDetails) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container not found in the system',
                    'code' => 'CONTAINER_NOT_FOUND'
                ], 404);
            }

            // Check if container is available for return
            if (!$containerDetails['isAvailableForReturn']) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container is not available for return',
                    'code' => 'CONTAINER_NOT_AVAILABLE',
                    'data' => [
                        'container' => $containerDetails,
                        'current_status' => $containerDetails['status']
                    ]
                ], 400);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'container' => $containerDetails
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Container search API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Get compatible terminals for a container
     */
    #[Route('/container/{containerId}/terminals', name: 'api_container_terminals', methods: ['GET'])]
    public function getCompatibleTerminals(int $containerId): JsonResponse
    {
        try {
            $container = $this->entityManager->getRepository(Container::class)->find($containerId);
            
            if (!$container) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container not found',
                    'code' => 'CONTAINER_NOT_FOUND'
                ], 404);
            }

            // Validate container availability
            if (!$this->containerSearchService->validateContainerAvailability($container)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container is not available for return',
                    'code' => 'CONTAINER_NOT_AVAILABLE'
                ], 400);
            }

            // Find compatible terminals
            $compatibleTerminals = $this->terminalService->findCompatibleTerminals($container);

            $terminalData = [];
            foreach ($compatibleTerminals as $terminal) {
                $terminalDetails = $this->terminalService->getTerminalDetails($terminal);
                
                // Add available slots information
                $today = new \DateTime();
                $nextWeek = new \DateTime('+7 days');
                $availableSlots = $this->terminalService->getAvailableSlots($terminal, $today, $nextWeek);
                
                $terminalDetails['available_slots_count'] = count($availableSlots);
                $terminalDetails['has_availability'] = count($availableSlots) > 0;
                
                $terminalData[] = $terminalDetails;
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'container_id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'compatible_terminals' => $terminalData,
                    'total_terminals' => count($terminalData)
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Compatible terminals API error', [
                'container_id' => $containerId,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Get available slots for a terminal
     */
    #[Route('/terminal/{terminalId}/slots', name: 'api_terminal_slots', methods: ['GET'])]
    public function getTerminalSlots(int $terminalId, Request $request): JsonResponse
    {
        try {
            $terminal = $this->entityManager->getRepository(Terminal::class)->find($terminalId);
            
            if (!$terminal) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Terminal not found',
                    'code' => 'TERMINAL_NOT_FOUND'
                ], 404);
            }

            if (!$terminal->isActive()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Terminal is not active',
                    'code' => 'TERMINAL_INACTIVE'
                ], 400);
            }

            // Parse date range from query parameters
            $startDate = $request->query->get('start_date');
            $endDate = $request->query->get('end_date');

            try {
                $startDateTime = $startDate ? new \DateTime($startDate) : new \DateTime();
                $endDateTime = $endDate ? new \DateTime($endDate) : new \DateTime('+30 days');
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid date format. Use YYYY-MM-DD format',
                    'code' => 'INVALID_DATE_FORMAT'
                ], 400);
            }

            // Get available slots
            $availableSlots = $this->terminalService->getAvailableSlots($terminal, $startDateTime, $endDateTime);

            $slotsData = [];
            foreach ($availableSlots as $slot) {
                $slotsData[] = [
                    'id' => $slot->getId(),
                    'date' => $slot->getDate()->format('Y-m-d'),
                    'capacity' => $slot->getCapacity(),
                    'assigned_count' => $slot->getAssignedCount(),
                    'available_count' => $slot->getCapacity() - $slot->getAssignedCount(),
                    'status' => $slot->getStatus()->value
                ];
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'terminal_id' => $terminal->getId(),
                    'terminal_name' => $terminal->getName(),
                    'terminal_type' => $terminal->getType()->value,
                    'available_slots' => $slotsData,
                    'total_slots' => count($slotsData),
                    'date_range' => [
                        'start' => $startDateTime->format('Y-m-d'),
                        'end' => $endDateTime->format('Y-m-d')
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Terminal slots API error', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Upload geotag photo for pre-advice
     */
    #[Route('/photo/upload', name: 'api_photo_upload', methods: ['POST'])]
    public function uploadPhoto(Request $request): JsonResponse
    {
        try {
            $uploadedFile = $request->files->get('photo');
            
            if (!$uploadedFile) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Photo file is required',
                    'code' => 'MISSING_PHOTO'
                ], 400);
            }

            if (!$uploadedFile->isValid()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid photo file',
                    'code' => 'INVALID_PHOTO'
                ], 400);
            }

            // Process the geotag photo
            $photo = $this->photoVerificationService->processGeotagPhoto($uploadedFile);

            // Get photo verification details
            $photoDetails = $this->photoVerificationService->getPhotoVerificationDetails($photo);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'photo' => $photoDetails,
                    'message' => 'Photo uploaded and processed successfully'
                ]
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ], 400);

        } catch (\Exception $e) {
            $this->logger->error('Photo upload API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to process photo upload',
                'code' => 'UPLOAD_ERROR'
            ], 500);
        }
    }

    /**
     * Validate geotag photo
     */
    #[Route('/photo/{photoId}/validate', name: 'api_photo_validate', methods: ['GET'])]
    public function validatePhoto(int $photoId): JsonResponse
    {
        try {
            $photo = $this->entityManager->getRepository(\App\Entity\GeotagPhoto::class)->find($photoId);
            
            if (!$photo) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Photo not found',
                    'code' => 'PHOTO_NOT_FOUND'
                ], 404);
            }

            // Validate photo
            $isValid = $this->photoVerificationService->validateGeotagPhoto($photo);
            $photoDetails = $this->photoVerificationService->getPhotoVerificationDetails($photo);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'photo' => $photoDetails,
                    'is_valid' => $isValid,
                    'validation_status' => $isValid ? 'valid' : 'invalid'
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Photo validation API error', [
                'photo_id' => $photoId,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Submit pre-advice request
     */
    #[Route('/submit', name: 'api_pre_advice_submit', methods: ['POST'])]
    public function submitPreAdvice(Request $request): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid JSON data',
                    'code' => 'INVALID_JSON'
                ], 400);
            }

            // Validate required fields
            $requiredFields = ['container_id', 'terminal_id', 'photo_ids', 'payment_reference'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => "Field '{$field}' is required",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }

            // Find container
            $container = $this->entityManager->getRepository(Container::class)->find($data['container_id']);
            if (!$container) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container not found',
                    'code' => 'CONTAINER_NOT_FOUND'
                ], 404);
            }

            // Find terminal
            $terminal = $this->entityManager->getRepository(Terminal::class)->find($data['terminal_id']);
            if (!$terminal) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Terminal not found',
                    'code' => 'TERMINAL_NOT_FOUND'
                ], 404);
            }

            // Validate container availability
            if (!$this->containerSearchService->validateContainerAvailability($container)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Container is not available for return',
                    'code' => 'CONTAINER_NOT_AVAILABLE'
                ], 400);
            }

            // Validate terminal compatibility
            if (!$this->terminalService->canAcceptContainer($terminal, $container)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Selected terminal cannot accept this container type',
                    'code' => 'TERMINAL_INCOMPATIBLE'
                ], 400);
            }

            // Find and validate photos
            $photoIds = is_array($data['photo_ids']) ? $data['photo_ids'] : [$data['photo_ids']];
            $photos = [];
            
            foreach ($photoIds as $photoId) {
                $photo = $this->entityManager->getRepository(\App\Entity\GeotagPhoto::class)->find($photoId);
                if (!$photo) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => "Photo with ID {$photoId} not found",
                        'code' => 'PHOTO_NOT_FOUND'
                    ], 404);
                }
                
                if (!$this->photoVerificationService->validateGeotagPhoto($photo)) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => "Photo with ID {$photoId} is not valid",
                        'code' => 'INVALID_PHOTO'
                    ], 400);
                }
                
                $photos[] = $photo;
            }

            // Submit pre-advice
            $preAdvice = $this->preAdviceService->submitPreAdvice(
                $trucker,
                $container,
                $terminal,
                $photos,
                $data['payment_reference']
            );

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'pre_advice_id' => $preAdvice->getId(),
                    'reference_number' => $preAdvice->getId(),
                    'status' => $preAdvice->getStatus()->value,
                    'container_number' => $container->getContainerNumber(),
                    'terminal_name' => $terminal->getName(),
                    'submitted_at' => $preAdvice->getCreatedAt()->format('Y-m-d H:i:s'),
                    'message' => 'Pre-advice request submitted successfully'
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Pre-advice submission API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to submit pre-advice request',
                'code' => 'SUBMISSION_ERROR'
            ], 500);
        }
    }

    /**
     * Get pre-advice request status
     */
    #[Route('/{preAdviceId}/status', name: 'api_pre_advice_status', methods: ['GET'])]
    public function getPreAdviceStatus(int $preAdviceId): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $preAdvice = $this->entityManager->getRepository(PreAdviceRequest::class)->find($preAdviceId);
            
            if (!$preAdvice) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Pre-advice request not found',
                    'code' => 'PRE_ADVICE_NOT_FOUND'
                ], 404);
            }

            // Ensure trucker can only view their own requests
            if ($preAdvice->getTrucker() !== $trucker) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Access denied',
                    'code' => 'ACCESS_DENIED'
                ], 403);
            }

            $workflowStatus = $this->preAdviceService->getWorkflowStatus($preAdvice);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'pre_advice_id' => $preAdvice->getId(),
                    'status' => $preAdvice->getStatus()->value,
                    'container_number' => $preAdvice->getContainer()->getContainerNumber(),
                    'terminal_name' => $preAdvice->getSelectedTerminal()->getName(),
                    'submitted_at' => $preAdvice->getCreatedAt()->format('Y-m-d H:i:s'),
                    'verified_at' => $preAdvice->getVerifiedAt()?->format('Y-m-d H:i:s'),
                    'edo_number' => $preAdvice->getEdoNumber(),
                    'qr_code' => $preAdvice->getQrCode(),
                    'rejection_reason' => $preAdvice->getRejectionReason(),
                    'workflow_status' => $workflowStatus
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Pre-advice status API error', [
                'pre_advice_id' => $preAdviceId,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Get trucker's pre-advice requests
     */
    #[Route('/list', name: 'api_pre_advice_list', methods: ['GET'])]
    public function getPreAdviceList(Request $request): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $status = $request->query->get('status', 'all');
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(50, max(1, (int) $request->query->get('limit', 20))); // Max 50 items per page

            $qb = $this->entityManager->getRepository(PreAdviceRequest::class)
                ->createQueryBuilder('p')
                ->leftJoin('p.container', 'c')
                ->leftJoin('p.selectedTerminal', 't')
                ->addSelect('c', 't')
                ->where('p.trucker = :trucker')
                ->setParameter('trucker', $trucker);

            // Filter by status
            if ($status !== 'all') {
                $statusEnum = PreAdviceStatus::tryFrom($status);
                if ($statusEnum) {
                    $qb->andWhere('p.status = :status')
                       ->setParameter('status', $statusEnum);
                }
            }

            $qb->orderBy('p.createdAt', 'DESC')
               ->setFirstResult(($page - 1) * $limit)
               ->setMaxResults($limit);

            $requests = $qb->getQuery()->getResult();

            // Get total count for pagination
            $totalQb = clone $qb;
            $totalQb->select('COUNT(p.id)')
                ->setFirstResult(0)
                ->setMaxResults(null);
            $totalCount = $totalQb->getQuery()->getSingleScalarResult();

            $requestsData = [];
            foreach ($requests as $request) {
                $requestsData[] = [
                    'id' => $request->getId(),
                    'status' => $request->getStatus()->value,
                    'container_number' => $request->getContainer()->getContainerNumber(),
                    'terminal_name' => $request->getSelectedTerminal()->getName(),
                    'terminal_type' => $request->getSelectedTerminal()->getType()->value,
                    'submitted_at' => $request->getCreatedAt()->format('Y-m-d H:i:s'),
                    'verified_at' => $request->getVerifiedAt()?->format('Y-m-d H:i:s'),
                    'edo_number' => $request->getEdoNumber(),
                    'has_qr_code' => !empty($request->getQrCode()),
                    'rejection_reason' => $request->getRejectionReason()
                ];
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'requests' => $requestsData,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => ceil($totalCount / $limit),
                        'total_count' => $totalCount,
                        'per_page' => $limit
                    ],
                    'filters' => [
                        'status' => $status
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Pre-advice list API error', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Download EDO for pre-advice request
     */
    #[Route('/{preAdviceId}/edo', name: 'api_pre_advice_edo', methods: ['GET'])]
    public function downloadEDO(int $preAdviceId): Response
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $preAdvice = $this->entityManager->getRepository(PreAdviceRequest::class)->find($preAdviceId);
            
            if (!$preAdvice) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Pre-advice request not found',
                    'code' => 'PRE_ADVICE_NOT_FOUND'
                ], 404);
            }

            // Ensure trucker can only download their own EDOs
            if ($preAdvice->getTrucker() !== $trucker) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Access denied',
                    'code' => 'ACCESS_DENIED'
                ], 403);
            }

            // Ensure EDO exists
            if (!$preAdvice->getEdoNumber()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'EDO has not been generated for this pre-advice request',
                    'code' => 'EDO_NOT_GENERATED'
                ], 400);
            }

            // Generate EDO content (simplified for now)
            $edoContent = $this->generateEDOContent($preAdvice);

            return new Response(
                $edoContent,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => sprintf(
                        'attachment; filename="EDO_%s_%s.pdf"',
                        $preAdvice->getEdoNumber(),
                        $preAdvice->getContainer()->getContainerNumber()
                    )
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error('EDO download API error', [
                'pre_advice_id' => $preAdviceId,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Get QR code for pre-advice request
     */
    #[Route('/{preAdviceId}/qr-code', name: 'api_pre_advice_qr_code', methods: ['GET'])]
    public function getQRCode(int $preAdviceId): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $preAdvice = $this->entityManager->getRepository(PreAdviceRequest::class)->find($preAdviceId);
            
            if (!$preAdvice) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Pre-advice request not found',
                    'code' => 'PRE_ADVICE_NOT_FOUND'
                ], 404);
            }

            // Ensure trucker can only access their own QR codes
            if ($preAdvice->getTrucker() !== $trucker) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Access denied',
                    'code' => 'ACCESS_DENIED'
                ], 403);
            }

            // Ensure QR code exists
            if (!$preAdvice->getQrCode()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'QR code has not been generated for this pre-advice request',
                    'code' => 'QR_CODE_NOT_GENERATED'
                ], 400);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'pre_advice_id' => $preAdvice->getId(),
                    'qr_code' => $preAdvice->getQrCode(),
                    'edo_number' => $preAdvice->getEdoNumber(),
                    'container_number' => $preAdvice->getContainer()->getContainerNumber(),
                    'terminal_name' => $preAdvice->getSelectedTerminal()->getName(),
                    'generated_at' => $preAdvice->getVerifiedAt()?->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('QR code API error', [
                'pre_advice_id' => $preAdviceId,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Generate EDO content (simplified implementation)
     */
    private function generateEDOContent(PreAdviceRequest $preAdviceRequest): string
    {
        // For now, return HTML content as PDF placeholder
        // In production, you would use a PDF library like TCPDF or wkhtmltopdf
        $html = $this->renderView('trucker/edo_template.html.twig', [
            'pre_advice' => $preAdviceRequest,
            'generated_at' => new \DateTime(),
        ]);

        return $html;
    }
}