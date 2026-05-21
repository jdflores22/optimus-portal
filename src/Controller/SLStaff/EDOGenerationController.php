<?php

namespace App\Controller\SLStaff;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
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
        private BatchEDOGenerationServiceInterface $batchEDOGenerationService
    ) {
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
                // Get all linked containers
                $allContainers = $manifest->getContainersLinkedToManifest()->toArray();
                
                // Filter to only selected containers
                $selectedContainers = array_filter($allContainers, function($container) use ($containerIds) {
                    return in_array($container->getId(), $containerIds);
                });
                
                if (empty($selectedContainers)) {
                    return $this->json([
                        'success' => false,
                        'message' => 'No valid containers selected'
                    ], 400);
                }
                
                // Use the generateEDOsForContainers method for specific containers
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
                    'message' => sprintf('Successfully generated %d eDOs for selected containers', $session->getCompletedContainers()),
                    'data' => [
                        'count' => $session->getCompletedContainers(),
                        'failed' => $session->getFailedContainers(),
                        'total' => $session->getTotalContainers()
                    ]
                ]);
            } else {
                // Generate for all containers (default behavior)
                $result = $this->batchEDOGenerationService->generateEDOsForManifest(
                    $manifest,
                    $expirationDateTime,
                    $this->getUser()
                );
                
                return $this->json([
                    'success' => true,
                    'message' => sprintf('Successfully generated %d eDOs', $result['count']),
                    'data' => $result
                ]);
            }
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Failed to generate eDOs: ' . $e->getMessage()
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
}
