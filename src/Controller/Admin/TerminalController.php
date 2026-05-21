<?php

namespace App\Controller\Admin;

use App\Entity\Terminal;
use App\Entity\Enum\TerminalType;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/terminals')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class TerminalController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogService $activityLogService
    ) {}

    #[Route('', name: 'admin_terminals_index', methods: ['GET'])]
    public function index(): Response
    {
        $terminals = $this->entityManager
            ->getRepository(Terminal::class)
            ->findBy([], ['name' => 'ASC']);

        // Calculate statistics
        $totalTerminals = count($terminals);
        $activeTerminals = 0;
        $inactiveTerminals = 0;
        $totalCapacity = 0;

        foreach ($terminals as $terminal) {
            if ($terminal->isActive()) {
                $activeTerminals++;
            } else {
                $inactiveTerminals++;
            }
            $totalCapacity += $terminal->getDailyCapacity();
        }

        $statistics = [
            'total' => $totalTerminals,
            'active' => $activeTerminals,
            'inactive' => $inactiveTerminals,
            'totalCapacity' => $totalCapacity
        ];

        $regions = $this->entityManager
            ->getRepository(\App\Entity\Region::class)
            ->findAllOrdered();

        return $this->render('admin/terminals/index.html.twig', [
            'terminals' => $terminals,
            'terminalTypes' => TerminalType::cases(),
            'statistics' => $statistics,
            'regions' => $regions
        ]);
    }

    #[Route('/new', name: 'admin_terminals_new', methods: ['GET'])]
    public function new(): Response
    {
        $regions = $this->entityManager
            ->getRepository(\App\Entity\Region::class)
            ->findAllOrdered();

        return $this->render('admin/terminals/new.html.twig', [
            'terminalTypes' => TerminalType::cases(),
            'regions' => $regions
        ]);
    }

    #[Route('/store', name: 'admin_terminals_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        try {
            $name = $request->request->get('name');
            $type = $request->request->get('type');
            $location = $request->request->get('location');
            $regionId = $request->request->get('region_id');
            $cityId = $request->request->get('city_id');
            $dailyCapacity = $request->request->get('dailyCapacity');

            // Validation
            if (empty($name) || empty($type) || empty($location) || empty($dailyCapacity)) {
                $this->addFlash('error', 'All required fields must be filled');
                return $this->redirectToRoute('admin_terminals_new');
            }

            if (!is_numeric($dailyCapacity) || $dailyCapacity <= 0) {
                $this->addFlash('error', 'Daily capacity must be a positive number');
                return $this->redirectToRoute('admin_terminals_new');
            }

            // Check if terminal type is valid
            $terminalType = TerminalType::tryFrom($type);
            if (!$terminalType) {
                $this->addFlash('error', 'Invalid terminal type');
                return $this->redirectToRoute('admin_terminals_new');
            }

            // Get region and city entities
            $region = null;
            $city = null;
            
            if ($regionId) {
                $region = $this->entityManager->getRepository(\App\Entity\Region::class)->find($regionId);
            }
            
            if ($cityId) {
                $city = $this->entityManager->getRepository(\App\Entity\City::class)->find($cityId);
            }

            // Create terminal
            $terminal = new Terminal();
            $terminal->setName($name);
            $terminal->setType($terminalType);
            $terminal->setLocation($location);
            $terminal->setRegion($region);
            $terminal->setCity($city);
            $terminal->setDailyCapacity((int)$dailyCapacity);
            $terminal->setIsActive(false);

            $this->entityManager->persist($terminal);
            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                'terminal_created',
                'Terminal',
                $terminal->getId(),
                null,
                [
                    'name' => $terminal->getName(),
                    'type' => $terminal->getType()->value,
                    'location' => $terminal->getLocation(),
                    'region' => $region ? $region->getName() : null,
                    'city' => $city ? $city->getName() : null,
                    'daily_capacity' => $terminal->getDailyCapacity()
                ]
            );

            $this->addFlash('success', 'Terminal created successfully');
            return $this->redirectToRoute('admin_terminals_index');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create terminal: ' . $e->getMessage());
            return $this->redirectToRoute('admin_terminals_new');
        }
    }

    #[Route('/create', name: 'admin_terminals_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $name = $request->request->get('name');
            $type = $request->request->get('type');
            $location = $request->request->get('location');
            $dailyCapacity = $request->request->get('dailyCapacity');

            // Validation
            if (empty($name) || empty($type) || empty($location) || empty($dailyCapacity)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'All fields are required'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!is_numeric($dailyCapacity) || $dailyCapacity <= 0) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Daily capacity must be a positive number'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if terminal type is valid
            $terminalType = TerminalType::tryFrom($type);
            if (!$terminalType) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid terminal type'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create terminal
            $terminal = new Terminal();
            $terminal->setName($name);
            $terminal->setType($terminalType);
            $terminal->setLocation($location);
            $terminal->setDailyCapacity((int)$dailyCapacity);
            $terminal->setIsActive(true);

            $this->entityManager->persist($terminal);
            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                'terminal_created',
                'Terminal',
                $terminal->getId(),
                null,
                [
                    'name' => $terminal->getName(),
                    'type' => $terminal->getType()->value,
                    'location' => $terminal->getLocation(),
                    'daily_capacity' => $terminal->getDailyCapacity()
                ]
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Terminal created successfully',
                'terminal' => [
                    'id' => $terminal->getId(),
                    'name' => $terminal->getName(),
                    'type' => $terminal->getType()->value,
                    'location' => $terminal->getLocation(),
                    'dailyCapacity' => $terminal->getDailyCapacity(),
                    'isActive' => $terminal->isActive()
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to create terminal: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/edit', name: 'admin_terminals_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $terminal = $this->entityManager
            ->getRepository(Terminal::class)
            ->find($id);

        if (!$terminal) {
            $this->addFlash('error', 'Terminal not found');
            return $this->redirectToRoute('admin_terminals_index');
        }

        $regions = $this->entityManager
            ->getRepository(\App\Entity\Region::class)
            ->findAllOrdered();

        return $this->render('admin/terminals/edit.html.twig', [
            'terminal' => $terminal,
            'terminalTypes' => TerminalType::cases(),
            'regions' => $regions
        ]);
    }

    #[Route('/{id}/view', name: 'admin_terminals_view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id): Response
    {
        $terminal = $this->entityManager
            ->getRepository(Terminal::class)
            ->find($id);

        if (!$terminal) {
            $this->addFlash('error', 'Terminal not found');
            return $this->redirectToRoute('admin_terminals_index');
        }

        // Get all allocations for this terminal
        $allocations = $this->entityManager
            ->createQueryBuilder()
            ->select('a', 'u', 's')
            ->from('App\Entity\ShippingLineTerminalAllocation', 'a')
            ->join('a.staffUser', 'u')
            ->leftJoin('u.managedShippingLine', 's')
            ->where('a.terminal = :terminal')
            ->setParameter('terminal', $terminal)
            ->orderBy('s.brandName', 'ASC')
            ->getQuery()
            ->getResult();

        // Calculate total allocated and available capacity
        $totalAllocated = 0;
        foreach ($allocations as $allocation) {
            $totalAllocated += $allocation->getAllocatedCapacity();
        }
        
        $availableCapacity = $terminal->getDailyCapacity() - $totalAllocated;
        $utilizationPercentage = $terminal->getDailyCapacity() > 0 
            ? ($totalAllocated / $terminal->getDailyCapacity()) * 100 
            : 0;

        return $this->render('admin/terminals/view.html.twig', [
            'terminal' => $terminal,
            'allocations' => $allocations,
            'totalAllocated' => $totalAllocated,
            'availableCapacity' => $availableCapacity,
            'utilizationPercentage' => $utilizationPercentage
        ]);
    }

    #[Route('/{id}/update', name: 'admin_terminals_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        try {
            $terminal = $this->entityManager
                ->getRepository(Terminal::class)
                ->find($id);

            if (!$terminal) {
                $this->addFlash('error', 'Terminal not found');
                return $this->redirectToRoute('admin_terminals_index');
            }

            $name = $request->request->get('name');
            $type = $request->request->get('type');
            $location = $request->request->get('location');
            $regionId = $request->request->get('region_id');
            $cityId = $request->request->get('city_id');
            $dailyCapacity = $request->request->get('dailyCapacity');

            // Validation
            if (empty($name) || empty($type) || empty($location) || empty($dailyCapacity)) {
                $this->addFlash('error', 'All required fields must be filled');
                return $this->redirectToRoute('admin_terminals_edit', ['id' => $id]);
            }

            if (!is_numeric($dailyCapacity) || $dailyCapacity <= 0) {
                $this->addFlash('error', 'Daily capacity must be a positive number');
                return $this->redirectToRoute('admin_terminals_edit', ['id' => $id]);
            }

            // Check if terminal type is valid
            $terminalType = TerminalType::tryFrom($type);
            if (!$terminalType) {
                $this->addFlash('error', 'Invalid terminal type');
                return $this->redirectToRoute('admin_terminals_edit', ['id' => $id]);
            }

            // Get region and city entities
            $region = null;
            $city = null;
            
            if ($regionId) {
                $region = $this->entityManager->getRepository(\App\Entity\Region::class)->find($regionId);
            }
            
            if ($cityId) {
                $city = $this->entityManager->getRepository(\App\Entity\City::class)->find($cityId);
            }

            // Store old values for logging
            $oldValues = [
                'name' => $terminal->getName(),
                'type' => $terminal->getType()->value,
                'location' => $terminal->getLocation(),
                'region' => $terminal->getRegion() ? $terminal->getRegion()->getName() : null,
                'city' => $terminal->getCity() ? $terminal->getCity()->getName() : null,
                'daily_capacity' => $terminal->getDailyCapacity()
            ];

            // Update terminal
            $terminal->setName($name);
            $terminal->setType($terminalType);
            $terminal->setLocation($location);
            $terminal->setRegion($region);
            $terminal->setCity($city);
            $terminal->setDailyCapacity((int)$dailyCapacity);
            $terminal->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                'terminal_updated',
                'Terminal',
                $terminal->getId(),
                $oldValues,
                [
                    'name' => $terminal->getName(),
                    'type' => $terminal->getType()->value,
                    'location' => $terminal->getLocation(),
                    'region' => $region ? $region->getName() : null,
                    'city' => $city ? $city->getName() : null,
                    'daily_capacity' => $terminal->getDailyCapacity()
                ]
            );

            $this->addFlash('success', 'Terminal updated successfully');
            return $this->redirectToRoute('admin_terminals_index');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update terminal: ' . $e->getMessage());
            return $this->redirectToRoute('admin_terminals_edit', ['id' => $id]);
        }
    }

    #[Route('/{id}/toggle-status', name: 'admin_terminals_toggle_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $terminal = $this->entityManager
                ->getRepository(Terminal::class)
                ->find($id);

            if (!$terminal) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Terminal not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $oldStatus = $terminal->isActive();
            $terminal->setIsActive(!$terminal->isActive());
            $terminal->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                'terminal_status_changed',
                'Terminal',
                $terminal->getId(),
                ['is_active' => $oldStatus],
                ['is_active' => $terminal->isActive()],
                ['terminal_name' => $terminal->getName()]
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Terminal status updated successfully',
                'isActive' => $terminal->isActive()
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to update terminal status: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/delete', name: 'admin_terminals_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $terminal = $this->entityManager
                ->getRepository(Terminal::class)
                ->find($id);

            if (!$terminal) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Terminal not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $terminalName = $terminal->getName();
            $terminalData = [
                'name' => $terminal->getName(),
                'type' => $terminal->getType()->value,
                'location' => $terminal->getLocation(),
                'daily_capacity' => $terminal->getDailyCapacity()
            ];

            // Check if terminal has any allocations or pre-advice requests
            if ($terminal->getPreAdviceRequests()->count() > 0) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Cannot delete terminal with existing pre-advice requests. Please deactivate instead.'
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->entityManager->remove($terminal);
            $this->entityManager->flush();

            // Log activity
            $this->activityLogService->logActivity(
                $this->getUser(),
                'terminal_deleted',
                'Terminal',
                null,
                $terminalData,
                null,
                ['terminal_name' => $terminalName]
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Terminal deleted successfully'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to delete terminal: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
