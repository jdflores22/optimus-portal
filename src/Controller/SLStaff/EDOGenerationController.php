<?php

namespace App\Controller\SLStaff;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\EDORenewalRequest;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\RenewalRequestStatus;
use App\Service\EDOService;
use App\Service\DocumentService;
use App\Service\AuditService;
use App\Service\ManifestNotificationService;
use App\Service\BatchEDOGenerationServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sl-staff/edo-generation')]
#[IsGranted('ROLE_SL_STAFF')]
class EDOGenerationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EDOService $edoService,
        private DocumentService $documentService,
        private AuditService $auditService,
        private ManifestNotificationService $notificationService,
        private BatchEDOGenerationServiceInterface $batchEDOGenerationService,
        private \App\Service\EDORenewalServiceInterface $renewalService,
        private \App\Service\ActivityLogService $activityLogService,
        private \App\Repository\EDORenewalRequestRepository $renewalRequestRepository,
        private \App\Repository\ShippingLineTerminalAllocationRepository $allocationRepository
    ) {
    }

    /**
     * List containers ready for eDO generation (payment verified)
     * Route: /sl-staff/edo-generation
     * Method: GET
     * Access: ROLE_SL_STAFF
     */
    #[Route('', name: 'sl_staff_edo_generation_list', methods: ['GET'])]
    public function edoGenerationList(): \Symfony\Component\HttpFoundation\Response
    {
        /** @var \App\Entity\StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();
        
        // Get all containers from manifests with payment verified status
        // Include PAYMENT_VERIFIED, EDO_GENERATED, and EDO_RELEASED states
        // because manifests can have partial eDO generation (some containers have eDOs, others don't)
        $qb = $this->entityManager->getRepository(\App\Entity\Container::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.noa', 'n')
            ->leftJoin('c.manifest', 'm')
            ->leftJoin('m.payments', 'p')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.consignee', 'cons')
            ->leftJoin('m.shippingLine', 'sl')
            ->leftJoin('c.containerSize', 'cs')
            ->leftJoin('c.containerType', 'ct')
            ->addSelect('n', 'm', 'p', 'b', 'cons', 'sl', 'cs', 'ct')
            ->where('m.workflowState IN (:states)')
            ->andWhere('p.paymentType = :paymentType')
            ->andWhere('p.status = :paymentStatus')
            ->setParameter('states', [
                WorkflowState::PAYMENT_VERIFIED,
                WorkflowState::EDO_GENERATED,
                WorkflowState::EDO_RELEASED
            ])
            ->setParameter('paymentType', PaymentType::FINAL_PAYMENT)
            ->setParameter('paymentStatus', PaymentStatus::VERIFIED)
            ->orderBy('p.validatedAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->addOrderBy('c.containerNumber', 'ASC');
        
        // Filter by shipping line if user has scope
        if ($shippingLine) {
            $qb->andWhere('m.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        $containers = $qb->getQuery()->getResult();
        
        // Check which containers already have eDOs
        $containersData = [];
        foreach ($containers as $container) {
            $manifest = $container->getManifest();
            
            // Check if container already has an eDO
            $existingEdo = $this->entityManager->getRepository(ElectronicDeliveryOrder::class)
                ->createQueryBuilder('e')
                ->where('e.container = :container')
                ->andWhere('e.manifest = :manifest')
                ->andWhere('e.status IN (:statuses)')
                ->setParameter('container', $container)
                ->setParameter('manifest', $manifest)
                ->setParameter('statuses', [EDOStatus::PENDING_RELEASE, EDOStatus::RELEASED])
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            
            $containersData[] = [
                'container' => $container,
                'manifest' => $manifest,
                'has_edo' => $existingEdo !== null,
                'edo' => $existingEdo,
            ];
        }
        
        // Separate containers with and without eDOs
        $containersNeedingEdo = array_values(array_filter($containersData, fn($data) => !$data['has_edo']));
        $containersWithEdo = array_values(array_filter($containersData, fn($data) => $data['has_edo']));
        $pendingGroups = $this->groupPendingContainersByManifest($containersNeedingEdo);

        return $this->render('sl_staff/edo_generation/list.html.twig', [
            'containersNeedingEdo' => $containersNeedingEdo,
            'containersWithEdo' => $containersWithEdo,
            'pendingGroups' => $pendingGroups,
            'totalContainers' => count($containersData),
        ]);
    }

    /**
     * @param list<array{container: \App\Entity\Container, manifest: Manifest, has_edo: bool, edo: ?ElectronicDeliveryOrder}> $containersNeedingEdo
     *
     * @return list<array{
     *     id: int,
     *     manifest: Manifest,
     *     broker: string,
     *     consignee: string,
     *     containers: list<array{container: \App\Entity\Container, manifest: Manifest, has_edo: bool, edo: ?ElectronicDeliveryOrder}>,
     *     total_in_manifest: int,
     *     edo_count_in_manifest: int,
     *     container_ids_csv: string,
     *     search_text: string
     * }>
     */
    private function groupPendingContainersByManifest(array $containersNeedingEdo): array
    {
        $groups = [];

        foreach ($containersNeedingEdo as $data) {
            $manifest = $data['manifest'];
            $manifestId = $manifest->getId();

            if (!isset($groups[$manifestId])) {
                $groups[$manifestId] = [
                    'id' => $manifestId,
                    'manifest' => $manifest,
                    'broker' => $manifest->getBroker()?->getFullName() ?? 'N/A',
                    'consignee' => $manifest->getConsignee()?->getBusinessName() ?? 'N/A',
                    'containers' => [],
                    'total_in_manifest' => $manifest->getContainersLinkedToManifest()->count(),
                    'edo_count_in_manifest' => $this->countManifestEdos($manifest),
                    'container_ids' => [],
                ];
            }

            $groups[$manifestId]['containers'][] = $data;
            $groups[$manifestId]['container_ids'][] = $data['container']->getId();
        }

        $result = [];
        foreach ($groups as $group) {
            $manifest = $group['manifest'];
            $containerNumbers = array_map(
                static fn(array $item): string => $item['container']->getContainerNumber(),
                $group['containers']
            );

            $group['container_ids_csv'] = implode(',', $group['container_ids']);
            $group['search_text'] = strtolower(implode(' ', array_filter([
                $group['broker'],
                $group['consignee'],
                $manifest->getManifestNumber(),
                ...$containerNumbers,
            ])));
            unset($group['container_ids']);

            $result[] = $group;
        }

        return $result;
    }

    private function countManifestEdos(Manifest $manifest): int
    {
        $count = 0;

        foreach ($manifest->getContainersLinkedToManifest() as $container) {
            foreach ($container->getEdos() as $edo) {
                if (in_array($edo->getStatus(), [EDOStatus::PENDING_RELEASE, EDOStatus::RELEASED], true)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Generate eDOs for manifest
     * Route: /sl-staff/edo-generation/generate/{manifestId}
     * Method: POST
     * Access: ROLE_SL_STAFF
     * 
     * Request body:
     * {
     *   "expirationDate": "2026-06-15",
     *   "containerIds": [1, 2, 3] // Optional: if not provided, generates for all containers
     * }
     */
    #[Route('/generate/{manifestId}', name: 'sl_staff_generate_edos', methods: ['POST'])]
    public function generateEDOs(
        int $manifestId,
        Request $request
    ): JsonResponse {
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($manifestId);
        
        if (!$manifest) {
            return $this->json([
                'success' => false,
                'message' => 'Manifest not found'
            ], 404);
        }
        
        $data = json_decode($request->getContent(), true);
        $expirationDate = $data['expirationDate'] ?? null;
        $containerIds = $data['containerIds'] ?? null; // Optional: specific container IDs
        
        if (!$expirationDate) {
            return $this->json([
                'success' => false,
                'message' => 'Expiration date is required'
            ], 400);
        }
        
        try {
            $expirationDateTime = new \DateTime($expirationDate);
            
            // Validate expiration date is at least 1 day in the future
            $tomorrow = new \DateTime('+1 day');
            $tomorrow->setTime(0, 0, 0);
            
            if ($expirationDateTime < $tomorrow) {
                return $this->json([
                    'success' => false,
                    'message' => 'Expiration date must be at least 1 day from now'
                ], 400);
            }
            
            // If specific container IDs provided, filter containers
            if ($containerIds !== null && is_array($containerIds) && count($containerIds) > 0) {
                // STRICT: Only allow ONE container at a time
                if (count($containerIds) > 1) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Only one container can be generated at a time'
                    ], 400);
                }
                
                // Get all linked containers
                $allContainers = $manifest->getContainersLinkedToManifest()->toArray();
                
                // Filter to only the single selected container
                $selectedContainers = array_filter($allContainers, function($container) use ($containerIds) {
                    return in_array($container->getId(), $containerIds);
                });
                
                if (empty($selectedContainers)) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Container not found'
                    ], 400);
                }
                
                if (count($selectedContainers) > 1) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Multiple containers matched. Only one container allowed.'
                    ], 400);
                }
                
                // Use the generateEDOsForContainers method for the single container
                $session = $this->batchEDOGenerationService->generateEDOsForContainers(
                    $selectedContainers,
                    $expirationDateTime,
                    $manifest,
                    $this->getUser(),
                    'manifest',
                    $manifest->getManifestNumber()
                );
                
                return $this->json([
                    'success' => true,
                    'message' => sprintf('Successfully generated eDO for container'),
                    'data' => [
                        'count' => $session->getCompletedContainers(),
                        'failed' => $session->getFailedContainers(),
                        'total' => $session->getTotalContainers()
                    ]
                ]);
            } else {
                // Reject: containerIds is required
                return $this->json([
                    'success' => false,
                    'message' => 'Container ID is required. Please specify which container to generate eDO for.'
                ], 400);
            }
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Failed to generate eDO: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get manifest details for eDO generation modal
     * Route: /sl-staff/edo-generation/manifest/{manifestId}
     * Method: GET
     * Access: ROLE_SL_STAFF
     */
    #[Route('/manifest/{manifestId}', name: 'sl_staff_edo_manifest_details', methods: ['GET'])]
    public function getManifestDetails(
        int $manifestId
    ): JsonResponse {
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($manifestId);
        
        if (!$manifest) {
            return $this->json([
                'success' => false,
                'message' => 'Manifest not found'
            ], 404);
        }
        
        // Validate manifest is ready for eDO generation
        try {
            $this->validateManifestForEDOGeneration($manifest);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
        
        $containers = $manifest->getContainersLinkedToManifest();
        
        return $this->json([
            'success' => true,
            'data' => [
                'manifestNumber' => $manifest->getManifestNumber(),
                'containerCount' => $containers->count(),
                'containers' => array_map(function($container) {
                    return [
                        'id' => $container->getId(),
                        'containerNumber' => $container->getContainerNumber(),
                        'size' => $container->getContainerSize()?->getName(),
                        'type' => $container->getContainerType()?->getName(),
                    ];
                }, $containers->toArray()),
                'edoFeePerContainer' => 500.00,
                'totalEdoFees' => $containers->count() * 500.00,
            ]
        ]);
    }

    /**
     * Validate manifest is ready for eDO generation
     */
    private function validateManifestForEDOGeneration(Manifest $manifest): void
    {
        // Check workflow state - allow payment_verified, edo_generated, or edo_released
        $allowedStates = [
            WorkflowState::PAYMENT_VERIFIED,
            WorkflowState::EDO_GENERATED,
            WorkflowState::EDO_RELEASED
        ];
        
        if (!in_array($manifest->getWorkflowState(), $allowedStates)) {
            throw new \Exception(sprintf(
                'Manifest workflow state must be payment_verified, edo_generated, or edo_released. Current state: %s',
                $manifest->getWorkflowState()->value
            ));
        }
        
        // Check for final payment
        $finalPayment = null;
        foreach ($manifest->getPayments() as $payment) {
            if ($payment->getPaymentType() === PaymentType::FINAL_PAYMENT) {
                $finalPayment = $payment;
                break;
            }
        }
        
        if (!$finalPayment) {
            throw new \Exception('No final payment found for manifest');
        }
        
        // Check payment status
        if ($finalPayment->getStatus() !== PaymentStatus::VERIFIED) {
            throw new \Exception('Final payment is not verified');
        }
        
        // Check for linked containers
        $containers = $manifest->getContainersLinkedToManifest();
        if ($containers->count() === 0) {
            throw new \Exception('No containers linked to manifest');
        }
        
        // Note: We allow regeneration, so we don't check for existing eDOs
        // The service layer will handle updating existing eDOs or creating new ones
    }

    // ==================== EDO RENEWAL WORKFLOW ENDPOINTS ====================

    /**
     * Display renewal requests ready for generation (page view)
     * Route: GET /sl-staff/edo-generation/renewal-requests/ready
     * Access: ROLE_SL_STAFF
     */
    #[Route('/renewal-requests/ready', name: 'sl_staff_renewal_requests_ready', methods: ['GET'])]
    public function renewalRequestsReadyPage(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('sl_staff/edo_generation/renewal_requests_ready.html.twig');
    }

    /**
     * Display eDO generation page with CY utilization (page view)
     * Route: GET /sl-staff/edo-generation/renewal-requests/{id}/generate-page
     * Access: ROLE_SL_STAFF
     */
    #[Route('/renewal-requests/{id}/generate-page', name: 'sl_staff_renewal_generate_page', methods: ['GET'])]
    public function renewalGeneratePage(int $id): \Symfony\Component\HttpFoundation\Response
    {
        $user = $this->getUser();
        
        // Get renewal request
        $renewalRequest = $this->entityManager->getRepository(EDORenewalRequest::class)->find($id);
        
        if (!$renewalRequest) {
            $this->addFlash('error', 'Renewal request not found.');
            return $this->redirectToRoute('sl_staff_renewal_requests_ready');
        }
        
        // Validate renewal request belongs to this shipping line
        $expiredEdo = $renewalRequest->getExpiredEdo();
        $userShippingLine = $user->getShippingLineScope();
        
        if ($userShippingLine && $expiredEdo->getShippingLine()->getId() !== $userShippingLine->getId()) {
            $this->addFlash('error', 'You do not have permission to access this renewal request.');
            return $this->redirectToRoute('sl_staff_renewal_requests_ready');
        }
        
        // Validate renewal request is in correct status (ready for generation)
        $validStatuses = [
            RenewalRequestStatus::READY_FOR_GENERATION,
            RenewalRequestStatus::PAYMENT_VERIFIED
        ];
        
        if (!in_array($renewalRequest->getStatus(), $validStatuses, true)) {
            $this->addFlash('error', 'This renewal request is not ready for eDO generation. Current status: ' . $renewalRequest->getStatus()->getDisplayName());
            return $this->redirectToRoute('sl_staff_renewal_requests_ready');
        }
        
        // Check if new eDO already generated
        if ($renewalRequest->getNewEdo()) {
            $this->addFlash('info', 'A new eDO has already been generated for this renewal request.');
            return $this->redirectToRoute('sl_staff_renewal_requests_ready');
        }
        
        return $this->render('sl_staff/edo_generation/renewal_generate.html.twig', [
            'renewalRequestId' => $id
        ]);
    }

    /**
     * List pending renewal requests for SL staff
     * Route: GET /sl-staff/edo-generation/renewal-requests
     * Access: ROLE_SL_STAFF
     * 
     * Requirements: 14.4, 15.2
     */
    #[Route('/renewal-requests', name: 'sl_staff_renewal_requests', methods: ['GET'])]
    public function listRenewalRequests(): JsonResponse
    {
        $user = $this->getUser();

        // Call EDORenewalService::getPendingRenewalRequests
        $renewalRequests = $this->renewalService->getPendingRenewalRequests($user);

        // Log page access via ActivityLogService
        $this->activityLogService->logActivity(
            $user,
            'renewal_requests_list_viewed',
            'EDORenewalRequest',
            null,
            null,
            null,
            [
                'request_count' => count($renewalRequests)
            ]
        );

        // Display list of pending renewal requests with payment status
        $requestsData = array_map(function (\App\Entity\EDORenewalRequest $request) {
            $expiredEdo = $request->getExpiredEdo();
            $broker = $request->getRequestedBy();
            $billing = $request->getDetentionBilling();

            return [
                'id' => $request->getId(),
                'status' => $request->getStatus()->value,
                'requestedAt' => $request->getRequestedAt()->format('Y-m-d\TH:i:s\Z'),
                'emptyContainerReturnDate' => $request->getEmptyContainerReturnDate()->format('Y-m-d\TH:i:s\Z'),
                'overdueDays' => $request->getOverdueDays(),
                'detentionChargeAmount' => $request->getDetentionChargeAmount(),
                'paymentVerified' => $request->isPaymentVerified(),
                'paymentVerifiedAt' => $request->getPaymentVerifiedAt()?->format('Y-m-d\TH:i:s\Z'),
                'expiredEdo' => [
                    'id' => $expiredEdo->getId(),
                    'edoNumber' => $expiredEdo->getEdoNumber(),
                    'containerNumber' => $expiredEdo->getContainer()?->getContainerNumber() ?? 'N/A',
                    'expiresAt' => $expiredEdo->getExpiresAt()?->format('Y-m-d\TH:i:s\Z'),
                ],
                'broker' => [
                    'id' => $broker->getId(),
                    'name' => method_exists($broker, 'getFullName') ? $broker->getFullName() : $broker->getEmail(),
                    'email' => $broker->getEmail(),
                ],
                'billing' => $billing ? [
                    'id' => $billing->getId(),
                    'totalAmount' => $billing->getTotalAmount(),
                    'detentionDays' => $billing->getDetentionDays(),
                    'detentionRate' => $billing->getDetentionRate(),
                ] : null,
                'generateUrl' => $this->generateUrl('sl_staff_generate_from_renewal', ['id' => $request->getId()]),
            ];
        }, $renewalRequests);

        return $this->json([
            'success' => true,
            'data' => [
                'renewalRequests' => $requestsData,
                'total' => count($renewalRequests),
            ],
        ]);
    }

    /**
     * Display eDO generation form for renewal request (GET)
     * Route: GET /sl-staff/edo-generation/renewal-requests/{id}/generate
     * Access: ROLE_SL_STAFF
     * 
     * Requirements: 9.1, 9.2, 9.3, 11.1, 11.2, 12.1
     */
    #[Route('/renewal-requests/{id}/generate', name: 'sl_staff_generate_from_renewal', methods: ['GET'])]
    public function generateFromRenewalForm(int $id): JsonResponse
    {
        try {
            $user = $this->getUser();

            // Fetch renewal request by ID
            $renewalRequest = $this->renewalRequestRepository->find($id);

            if (!$renewalRequest) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'REQUEST_NOT_FOUND',
                        'message' => 'Renewal request not found',
                    ],
                ], 404);
            }

            // Check GENERATE_EDO permission using voter
            try {
                $this->denyAccessUnlessGranted('generate_edo', $renewalRequest);
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'ACCESS_DENIED',
                        'message' => 'You do not have permission to generate eDO from this renewal request',
                    ],
                ], 403);
            }

            // Fetch available container yard allocations
            $shippingLine = $user->getShippingLineScope();
            if (!$shippingLine) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NO_SHIPPING_LINE',
                        'message' => 'User has no shipping line scope',
                    ],
                ], 400);
            }

        $cyAllocations = $this->allocationRepository->findByShippingLineWithRelations($shippingLine);

        // Log page access via ActivityLogService
        $this->activityLogService->logActivity(
            $user,
            'renewal_edo_generation_form_viewed',
            'EDORenewalRequest',
            $renewalRequest->getId(),
            null,
            null,
            [
                'renewal_request_id' => $renewalRequest->getId(),
                'status' => $renewalRequest->getStatus()->value
            ]
        );

        // Render eDO generation form with CY selection and preforecasting fields
        $expiredEdo = $renewalRequest->getExpiredEdo();
        $broker = $renewalRequest->getRequestedBy();

        $allocationsData = array_map(function (\App\Entity\ShippingLineTerminalAllocation $allocation) {
            $terminal = $allocation->getTerminal();
            
            // For now, use simple calculation based on capacity
            // TODO: Implement actual container counting when container tracking is fully implemented
            $capacity20ft = $allocation->getCapacity20ft();
            $capacity40ft = $allocation->getCapacity40ft();
            
            // Estimate allocation based on TEU utilization
            $currentUtilization = $allocation->getCurrentUtilizationTEU();
            $totalCapacity = $allocation->getAllocatedCapacity();
            
            // Simple estimation: assume 50/50 split between 20ft and 40ft
            $allocated20ft = 0;
            $allocated40ft = 0;
            $preForecast20ft = 0;
            $preForecast40ft = 0;
            
            if ($totalCapacity > 0) {
                $utilizationRatio = $currentUtilization / $totalCapacity;
                $allocated20ft = (int)($capacity20ft * $utilizationRatio);
                $allocated40ft = (int)($capacity40ft * $utilizationRatio);
            }
            
            return [
                'id' => $allocation->getId(),
                'terminal' => [
                    'id' => $terminal->getId(),
                    'name' => $terminal->getName(),
                    'location' => method_exists($terminal, 'getLocation') ? $terminal->getLocation() : null,
                ],
                'allocatedCapacity' => $allocation->getAllocatedCapacity(),
                'currentUtilization' => $allocation->getCurrentUtilizationTEU(),
                'availableCapacity' => $allocation->getAvailableCapacityTEU(),
                'capacity20ft' => $capacity20ft,
                'capacity40ft' => $capacity40ft,
                'allocated20ft' => $allocated20ft,
                'allocated40ft' => $allocated40ft,
                'preForecast20ft' => $preForecast20ft,
                'preForecast40ft' => $preForecast40ft,
                'available20ft' => max(0, $capacity20ft - $allocated20ft - $preForecast20ft),
                'available40ft' => max(0, $capacity40ft - $allocated40ft - $preForecast40ft),
            ];
        }, $cyAllocations);

        return $this->json([
            'success' => true,
            'data' => [
                'renewalRequest' => [
                    'id' => $renewalRequest->getId(),
                    'status' => $renewalRequest->getStatus()->value,
                    'requestedAt' => $renewalRequest->getRequestedAt()->format('Y-m-d\TH:i:s\Z'),
                    'emptyContainerReturnDate' => $renewalRequest->getEmptyContainerReturnDate()->format('Y-m-d\TH:i:s\Z'),
                    'overdueDays' => $renewalRequest->getOverdueDays(),
                    'detentionChargeAmount' => $renewalRequest->getDetentionChargeAmount(),
                    'paymentVerified' => $renewalRequest->isPaymentVerified(),
                    'additionalNotes' => $renewalRequest->getAdditionalNotes(),
                ],
                'expiredEdo' => [
                    'id' => $expiredEdo->getId(),
                    'edoNumber' => $expiredEdo->getEdoNumber(),
                    'containerNumber' => $expiredEdo->getContainer()?->getContainerNumber() ?? 'N/A',
                    'containerSize' => $expiredEdo->getContainer()?->getContainerSize()?->getName() ?? 'N/A',
                    'containerType' => $expiredEdo->getContainer()?->getContainerType()?->getName() ?? 'N/A',
                    'expiresAt' => $expiredEdo->getExpiresAt()?->format('Y-m-d\TH:i:s\Z'),
                    'manifestNumber' => $expiredEdo->getManifest()?->getManifestNumber() ?? 'N/A',
                ],
                'broker' => [
                    'id' => $broker->getId(),
                    'name' => method_exists($broker, 'getFullName') ? $broker->getFullName() : $broker->getEmail(),
                    'email' => $broker->getEmail(),
                ],
                'cyAllocations' => $allocationsData,
            ],
        ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log("Error in generateFromRenewalForm: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while loading renewal request details: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Generate new eDO from renewal request (POST)
     * Route: POST /sl-staff/edo-generation/renewal-requests/{id}/generate
     * Access: ROLE_SL_STAFF
     * 
     * Requirements: 9.2, 9.3, 10.1, 10.2, 10.3, 10.4, 11.3, 11.4, 12.2, 12.3
     */
    #[Route('/renewal-requests/{id}/generate', name: 'sl_staff_generate_from_renewal_post', methods: ['POST'])]
    public function generateFromRenewal(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();

        // Fetch renewal request by ID
        $renewalRequest = $this->renewalRequestRepository->find($id);

        if (!$renewalRequest) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_NOT_FOUND',
                    'message' => 'Renewal request not found',
                ],
            ], 404);
        }

        // Check GENERATE_EDO permission using voter
        $this->denyAccessUnlessGranted('generate_edo', $renewalRequest);

        try {
            // Validate form submission with CY allocation and preforecasting data
            $data = json_decode($request->getContent(), true);
            
            $cyAllocationId = $data['cyAllocationId'] ?? null;
            $additionalNotes = $data['additionalNotes'] ?? null;

            if (!$cyAllocationId) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'MISSING_CY_ALLOCATION',
                        'message' => 'Container yard allocation is required',
                    ],
                ], 400);
            }

            // Fetch CY allocation
            $cyAllocation = $this->allocationRepository->find($cyAllocationId);

            if (!$cyAllocation) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CY_ALLOCATION_NOT_FOUND',
                        'message' => 'Container yard allocation not found',
                    ],
                ], 404);
            }

            // Call EDORenewalService::generateNewEDO
            $newEdo = $this->renewalService->generateNewEDO(
                $renewalRequest,
                $user,
                $cyAllocation,
                $additionalNotes
            );

            // Log successful eDO generation via ActivityLogService
            $this->activityLogService->logActivity(
                $user,
                'new_edo_generated_from_renewal',
                'ElectronicDeliveryOrder',
                $newEdo->getId(),
                null,
                [
                    'edo_id' => $newEdo->getId(),
                    'edo_number' => $newEdo->getEdoNumber()
                ],
                [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'expired_edo_number' => $renewalRequest->getExpiredEdo()->getEdoNumber(),
                    'container_yard' => $cyAllocation->getTerminal()->getName()
                ]
            );

            // Display success message with new eDO reference
            return $this->json([
                'success' => true,
                'message' => 'New eDO generated successfully from renewal request',
                'data' => [
                    'newEdo' => [
                        'id' => $newEdo->getId(),
                        'edoNumber' => $newEdo->getEdoNumber(),
                        'generatedAt' => $newEdo->getGeneratedAt()->format('Y-m-d\TH:i:s\Z'),
                        'expiresAt' => $newEdo->getExpiresAt()?->format('Y-m-d\TH:i:s\Z'),
                        'cyLocation' => $newEdo->getCyLocation(),
                        'generatedByName' => $newEdo->getGeneratedByName(),
                        'additionalNotes' => $newEdo->getAdditionalNotes(),
                    ],
                    'renewalRequest' => [
                        'id' => $renewalRequest->getId(),
                        'status' => $renewalRequest->getStatus()->value,
                        'completedAt' => $renewalRequest->getCompletedAt()?->format('Y-m-d\TH:i:s\Z'),
                    ],
                ],
            ], 201);

        } catch (\RuntimeException $e) {
            // Log failed generation attempts via AuditService
            $this->auditService->logAction(
                $user,
                'edo_generation_failed',
                'EDORenewalRequest',
                $renewalRequest->getId(),
                [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'error_message' => $e->getMessage(),
                    'error_type' => 'RuntimeException'
                ]
            );

            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'GENERATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);

        } catch (\InvalidArgumentException $e) {
            // Log failed generation attempts via AuditService
            $this->auditService->logAction(
                $user,
                'edo_generation_validation_failed',
                'EDORenewalRequest',
                $renewalRequest->getId(),
                [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'error_message' => $e->getMessage(),
                    'error_type' => 'InvalidArgumentException'
                ]
            );

            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 400);

        } catch (\Exception $e) {
            // Log failed generation attempts via AuditService
            $this->auditService->logAction(
                $user,
                'edo_generation_error',
                'EDORenewalRequest',
                $renewalRequest->getId(),
                [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'error_message' => $e->getMessage(),
                    'error_type' => get_class($e)
                ]
            );

            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Failed to generate eDO: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }
}
