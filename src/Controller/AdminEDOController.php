<?php

namespace App\Controller;

use App\Entity\ElectronicDeliveryOrder;
use App\Repository\ElectronicDeliveryOrderRepository;
use App\Service\EDOAdminServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for System Admin eDO operations
 * 
 * Requirements: 10.1-10.6, 12.5
 */
#[Route('/admin/edo')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class AdminEDOController extends AbstractController
{
    public function __construct(
        private EDOAdminServiceInterface $adminService,
        private ElectronicDeliveryOrderRepository $edoRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * List all eDOs that can be unlocked (LOCKED or EXPIRED status)
     */
    #[Route('/unlock-list', name: 'admin_edo_unlock_list', methods: ['GET'])]
    public function listUnlockableEDOs(): Response
    {
        $lockedEDOs = $this->edoRepository->findBy([
            'status' => [\App\Entity\Enum\EDOStatus::LOCKED, \App\Entity\Enum\EDOStatus::EXPIRED]
        ]);

        return $this->render('admin/edo/unlock_list.html.twig', [
            'edos' => $lockedEDOs
        ]);
    }

    /**
     * View eDO details for unlock
     */
    #[Route('/{id}/unlock-detail', name: 'admin_edo_unlock_detail', methods: ['GET'])]
    public function viewUnlockDetail(int $id): Response
    {
        $edo = $this->edoRepository->find($id);
        
        if (!$edo) {
            $this->addFlash('error', 'eDO not found');
            return $this->redirectToRoute('admin_edo_unlock_list');
        }

        return $this->render('admin/edo/unlock_detail.html.twig', [
            'edo' => $edo,
            'container' => $edo->getContainer(),
            'manifest' => $edo->getManifest()
        ]);
    }

    /**
     * Unlock eDO without payment (System Admin only)
     */
    #[Route('/{id}/unlock', name: 'admin_edo_unlock', methods: ['POST'])]
    public function unlockEDO(int $id, Request $request): Response
    {
        // Requirement 12.5: Restrict eDO unlock to System_Admin
        $edo = $this->edoRepository->find($id);
        if ($edo) {
            $this->denyAccessUnlessGranted('unlock', $edo);
        }

        try {
            $edo = $this->edoRepository->find($id);
            
            if (!$edo) {
                $this->addFlash('error', 'eDO not found');
                return $this->redirectToRoute('admin_edo_unlock_list');
            }

            // Get unlock reason from request
            $reason = $request->request->get('unlock_reason');
            
            if (empty(trim($reason))) {
                $this->addFlash('error', 'Unlock reason is required');
                return $this->redirectToRoute('admin_edo_unlock_detail', ['id' => $id]);
            }

            // Unlock eDO
            $this->adminService->unlockEDO($edo, $this->getUser(), $reason);

            $this->addFlash('success', sprintf(
                'eDO %s has been unlocked successfully',
                $edo->getEdoNumber()
            ));

            $this->logger->info('Admin unlocked eDO', [
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber(),
                'admin_id' => $this->getUser()->getId(),
                'admin_email' => $this->getUser()->getEmail(),
                'reason' => $reason
            ]);
            
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_edo_unlock_detail', ['id' => $id]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to unlock eDO', [
                'edo_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->addFlash('error', 'Failed to unlock eDO: ' . $e->getMessage());
            return $this->redirectToRoute('admin_edo_unlock_detail', ['id' => $id]);
        }

        return $this->redirectToRoute('admin_edo_unlock_list');
    }

    /**
     * API endpoint to unlock eDO (for API clients)
     */
    #[Route('/api/{id}/unlock', name: 'api_admin_edo_unlock', methods: ['POST'])]
    public function apiUnlockEDO(int $id, Request $request): JsonResponse
    {
        // Requirement 12.5: Restrict eDO unlock to System_Admin
        $edo = $this->edoRepository->find($id);
        if ($edo) {
            $this->denyAccessUnlessGranted('unlock', $edo);
        }

        try {
            $edo = $this->edoRepository->find($id);
            
            if (!$edo) {
                return $this->json([
                    'success' => false,
                    'message' => 'eDO not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Get unlock reason from JSON body
            $data = json_decode($request->getContent(), true);
            $reason = $data['reason'] ?? '';
            
            if (empty(trim($reason))) {
                return $this->json([
                    'success' => false,
                    'message' => 'Unlock reason is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Unlock eDO
            $this->adminService->unlockEDO($edo, $this->getUser(), $reason);

            $this->logger->info('Admin unlocked eDO via API', [
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber(),
                'admin_id' => $this->getUser()->getId(),
                'reason' => $reason
            ]);

            return $this->json([
                'success' => true,
                'message' => 'eDO unlocked successfully',
                'edo' => [
                    'id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber(),
                    'status' => $edo->getStatus()->value,
                    'container_number' => $edo->getContainer()->getContainerNumber()
                ]
            ]);
            
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Failed to unlock eDO via API', [
                'edo_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'success' => false,
                'message' => 'Failed to unlock eDO: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
