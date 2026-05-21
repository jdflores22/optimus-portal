<?php

namespace App\Controller;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use App\Service\EDORegenerationServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for eDO regeneration request operations
 * 
 * Handles submission of regeneration requests for expired eDOs
 * by Consignees and Brokers.
 */
#[Route('/edo/regeneration')]
class EDORegenerationController extends AbstractController
{
    public function __construct(
        private EDORegenerationServiceInterface $edoRegenerationService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Submit a regeneration request for an expired eDO
     */
    #[Route('/request', name: 'edo_regeneration_request', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function submitRequest(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            
            // Get request data
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (empty($data['edoId'])) {
                return $this->json(['error' => 'eDO ID is required'], Response::HTTP_BAD_REQUEST);
            }

            // Get eDO
            $edo = $this->entityManager->getRepository(ElectronicDeliveryOrder::class)->find($data['edoId']);
            if (!$edo) {
                return $this->json(['error' => 'eDO not found'], Response::HTTP_NOT_FOUND);
            }

            // Requirements 12.6, 12.7: Verify user has access to this eDO (Consignee or Broker)
            $this->denyAccessUnlessGranted('view', $edo);

            // Verify user has access to this eDO (Consignee or Broker)
            if (!$this->canAccessEDO($user, $edo)) {
                return $this->json(['error' => 'You do not have permission to request regeneration for this eDO'], Response::HTTP_FORBIDDEN);
            }

            // Submit regeneration request
            $regenerationRequest = $this->edoRegenerationService->submitRequest($edo, $user);

            return $this->json([
                'success' => true,
                'message' => 'Regeneration request submitted successfully',
                'data' => [
                    'requestId' => $regenerationRequest->getId(),
                    'edoNumber' => $edo->getEdoNumber(),
                    'status' => $regenerationRequest->getStatus()->value,
                    'requestedAt' => $regenerationRequest->getRequestedAt()->format('Y-m-d H:i:s')
                ]
            ], Response::HTTP_CREATED);

        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['error' => 'An error occurred while processing your request'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check if user can access the eDO
     * 
     * @param User $user
     * @param ElectronicDeliveryOrder $edo
     * @return bool
     */
    private function canAccessEDO(User $user, ElectronicDeliveryOrder $edo): bool
    {
        // Check if user is Consignee
        if ($user->hasRole('ROLE_CONSIGNEE')) {
            $manifest = $edo->getManifest();
            if ($manifest && $manifest->getConsignee() && $manifest->getConsignee()->getUser() === $user) {
                return true;
            }
        }

        // Check if user is Broker
        if ($user->hasRole('ROLE_BROKER')) {
            $manifest = $edo->getManifest();
            if ($manifest && $manifest->getBroker() === $user) {
                return true;
            }
        }

        return false;
    }
}
