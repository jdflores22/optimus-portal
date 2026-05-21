<?php

namespace App\Controller\Admin;

use App\Entity\Enum\UserRole;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Entity\User;
use App\Service\ShippingLineService;
use App\Service\ActivityLogService;
use App\Service\InAppNotificationService;
use App\Service\ShippingLineDeactivationNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/admin/shipping-lines')]
class ShippingLineController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShippingLineService $shippingLineService,
        private ActivityLogService $activityLogService,
        private InAppNotificationService $notificationService,
        private ShippingLineDeactivationNotificationService $deactivationNotificationService
    ) {
    }

    #[Route('', name: 'admin_shipping_lines_list', methods: ['GET'])]
    public function list(): Response
    {
        // Get real shipping lines from database
        $shippingLines = $this->entityManager->getRepository(ShippingLine::class)->findAll();

        return $this->render('admin/shipping_lines/list.html.twig', [
            'shippingLines' => $shippingLines
        ]);
    }

    #[Route('/statistics', name: 'admin_shipping_lines_statistics', methods: ['GET'])]
    public function statistics(): Response
    {
        $currentUser = $this->getUser();
        
        // Check if user is SYSTEM_ADMIN
        if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
            $this->addFlash('error', 'Access denied. This page is only available to System Administrators.');
            return $this->redirectToRoute('admin_shipping_lines_list');
        }
        
        // Get real statistics
        $statistics = $this->shippingLineService->getStatistics();
        
        // Get all shipping lines for display
        $allShippingLines = $this->entityManager->getRepository(ShippingLine::class)->findAll();
        
        // Get shipping lines without admins
        $shippingLinesWithoutAdmins = $this->entityManager->getRepository(ShippingLine::class)->findWithoutAdmins();
        
        // Count total admins and unassigned admins
        $totalAdmins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->setParameter('role', UserRole::SHIPPING_LINES_ADMIN)
            ->getQuery()
            ->getSingleScalarResult();
            
        $unassignedAdmins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->andWhere('u.managedShippingLine IS NULL')
            ->setParameter('role', UserRole::SHIPPING_LINES_ADMIN)
            ->getQuery()
            ->getSingleScalarResult();

        // Enhanced statistics
        $enhancedStatistics = [
            'totalShippingLines' => $statistics['total'] ?? 0,
            'activeShippingLines' => $statistics['active'] ?? 0,
            'totalAdmins' => $totalAdmins,
            'unassignedAdmins' => $unassignedAdmins,
            'shippingLinesWithoutAdmins' => $shippingLinesWithoutAdmins,
            'allShippingLines' => $allShippingLines
        ];

        return $this->render('admin/shipping_lines/statistics.html.twig', [
            'statistics' => $enhancedStatistics
        ]);
    }

    #[Route('/export', name: 'admin_shipping_lines_export', methods: ['GET'])]
    public function export(): Response
    {
        return new Response('<h1>Export Shipping Lines</h1><p>Export functionality - to be implemented</p>');
    }

    #[Route('/create', name: 'admin_shipping_lines_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            try {
                // Handle file upload
                $logoPath = null;
                $logoFile = $request->files->get('logo');
                if ($logoFile) {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/shipping-lines/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = $logoFile->getClientOriginalExtension();
                    $fileName = 'logo_' . uniqid() . '.' . $fileExtension;
                    $logoFile->move($uploadDir, $fileName);
                    $logoPath = '/uploads/shipping-lines/' . $fileName;
                }

                // Prepare data for shipping line creation
                $data = [
                    'brandName' => $request->request->get('brandName'),
                    'portalConfig' => [
                        'primaryColor' => $request->request->get('primaryColor'),
                        'logoUrl' => $logoPath,
                        'contactEmail' => $request->request->get('contactEmail'),
                        'contactPhone' => $request->request->get('contactPhone'),
                        'website' => $request->request->get('website'),
                        'headquarters' => $request->request->get('headquarters'),
                        'description' => $request->request->get('description'),
                        'isActive' => (bool) $request->request->get('isActive', true)
                    ]
                ];

                // Create shipping line using the service
                $shippingLine = $this->shippingLineService->createShippingLine($data, $this->getUser());
                
                $this->addFlash('success', 'Shipping line created successfully!');
                return $this->redirectToRoute('admin_shipping_lines_detail', ['id' => $shippingLine->getId()]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Error creating shipping line: ' . $e->getMessage());
            }
        }

        return $this->render('admin/shipping_lines/create.html.twig');
    }

    #[Route('/{id}/edit', name: 'admin_shipping_lines_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
        
        if (!$shippingLine) {
            throw $this->createNotFoundException('Shipping line not found');
        }

        if ($request->isMethod('POST')) {
            try {
                // Handle file upload
                $logoPath = $shippingLine->getPortalConfigValue('logoUrl'); // Keep existing logo by default
                $logoFile = $request->files->get('logo');
                if ($logoFile) {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/shipping-lines/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = $logoFile->getClientOriginalExtension();
                    $fileName = 'logo_' . uniqid() . '.' . $fileExtension;
                    $logoFile->move($uploadDir, $fileName);
                    $logoPath = '/uploads/shipping-lines/' . $fileName;
                }

                // Update shipping line data
                $shippingLine->setBrandName($request->request->get('brandName'));
                $shippingLine->setIsActive((bool) $request->request->get('isActive'));
                
                // Update portal configuration
                $portalConfig = [
                    'primaryColor' => $request->request->get('primaryColor'),
                    'logoUrl' => $logoPath,
                    'contactEmail' => $request->request->get('contactEmail'),
                    'contactPhone' => $request->request->get('contactPhone'),
                    'website' => $request->request->get('website'),
                    'headquarters' => $request->request->get('headquarters'),
                    'description' => $request->request->get('description')
                ];
                
                $this->shippingLineService->updatePortalConfig($shippingLine, $portalConfig, $this->getUser());
                
                $this->addFlash('success', 'Shipping line updated successfully!');
                return $this->redirectToRoute('admin_shipping_lines_detail', ['id' => $id]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Error updating shipping line: ' . $e->getMessage());
            }
        }

        // Prepare data for the form
        $portalConfig = $shippingLine->getPortalConfig() ?? [];
        $formData = (object) [
            'id' => $shippingLine->getId(),
            'brandName' => $shippingLine->getBrandName(),
            'isActive' => $shippingLine->isActive(),
            'createdAt' => $shippingLine->getCreatedAt(),
            'primaryColor' => $portalConfig['primaryColor'] ?? '#3B82F6',
            'logoUrl' => $portalConfig['logoUrl'] ?? null,
            'contactEmail' => $portalConfig['contactEmail'] ?? '',
            'contactPhone' => $portalConfig['contactPhone'] ?? '',
            'description' => $portalConfig['description'] ?? '',
            'website' => $portalConfig['website'] ?? '',
            'headquarters' => $portalConfig['headquarters'] ?? ''
        ];

        return $this->render('admin/shipping_lines/edit.html.twig', [
            'shippingLine' => $formData
        ]);
    }

    #[Route('/{id}', name: 'admin_shipping_lines_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $currentUser = $this->getUser();
        
        // Check if user is SYSTEM_ADMIN for user hierarchy display
        $canViewUserHierarchy = $currentUser->getRole() === UserRole::SYSTEM_ADMIN;
        
        $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
        
        if (!$shippingLine) {
            throw $this->createNotFoundException('Shipping line not found');
        }

        // Get portal configuration
        $portalConfig = $shippingLine->getPortalConfig() ?? [];
        
        // Build shipping line object for the template
        $shippingLineData = (object) [
            'id' => $shippingLine->getId(),
            'brandName' => $shippingLine->getBrandName(),
            'isActive' => $shippingLine->isActive(),
            'createdAt' => $shippingLine->getCreatedAt(),
            'shippingLineAdmins' => $shippingLine->getShippingLineAdmins()->toArray(),
            'portalConfig' => (object) [
                'primaryColor' => $portalConfig['primaryColor'] ?? '#3B82F6',
                'logoUrl' => $portalConfig['logoUrl'] ?? null,
                'contactEmail' => $portalConfig['contactEmail'] ?? '',
                'contactPhone' => $portalConfig['contactPhone'] ?? ''
            ]
        ];

        // Calculate statistics
        $admins = $shippingLine->getShippingLineAdmins();
        $allUsers = $shippingLine->getScopedUsers();
        $statistics = (object) [
            'totalAdmins' => $admins->count(),
            'activeAdmins' => $admins->filter(fn($admin) => $admin->isActive())->count(),
            'totalUsers' => count($allUsers),
            'createdAt' => $shippingLine->getCreatedAt(),
            'lastUpdated' => $shippingLine->getUpdatedAt()
        ];

        // Organize user hierarchy for SYSTEM_ADMIN users
        $userHierarchy = [];
        if ($canViewUserHierarchy) {
            foreach ($shippingLine->getShippingLineAdmins() as $admin) {
                $subordinates = $admin->getSubordinateUsers()->toArray();
                $userHierarchy[] = [
                    'admin' => $admin,
                    'subordinates' => $subordinates
                ];
            }
        }

        return $this->render('admin/shipping_lines/detail.html.twig', [
            'shippingLine' => $shippingLineData,
            'statistics' => $statistics,
            'canViewUserHierarchy' => $canViewUserHierarchy,
            'userHierarchy' => $userHierarchy
        ]);
    }

    #[Route('/{id}/activate', name: 'admin_shipping_lines_activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(int $id): JsonResponse
    {
        try {
            $currentUser = $this->getUser();
            
            // Check if user is SYSTEM_ADMIN
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                return new JsonResponse(['success' => false, 'error' => 'Access denied. Only System Administrators can activate shipping lines.'], 403);
            }
            
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'error' => 'Shipping line not found'], 404);
            }
            
            if ($shippingLine->isActive()) {
                return new JsonResponse(['success' => false, 'error' => 'Shipping line is already active'], 400);
            }
            
            // Activate the shipping line
            $shippingLine->setIsActive(true);
            
            // Clear deactivation flag for affected users
            $this->deactivationNotificationService->clearDeactivationFlag($shippingLine);
            
            // Log shipping line activation activity
            $this->activityLogService->logShippingLineActivation($currentUser, $shippingLine);
            
            // Notify all administrators of this shipping line
            foreach ($shippingLine->getShippingLineAdmins() as $admin) {
                $this->notificationService->createSuccessNotification(
                    $admin,
                    'Shipping Line Activated',
                    sprintf('Your shipping line "%s" has been activated by a System Administrator. You and your team now have full access to the system.', $shippingLine->getBrandName()),
                    null,
                    null
                );
            }
            
            $this->entityManager->flush();
            
            return new JsonResponse(['success' => true, 'message' => 'Shipping line activated successfully']);
            
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/deactivate', name: 'admin_shipping_lines_deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deactivate(int $id): JsonResponse
    {
        try {
            $currentUser = $this->getUser();
            
            // Check if user is SYSTEM_ADMIN
            if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                return new JsonResponse(['success' => false, 'error' => 'Access denied. Only System Administrators can deactivate shipping lines.'], 403);
            }
            
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'error' => 'Shipping line not found'], 404);
            }
            
            if (!$shippingLine->isActive()) {
                return new JsonResponse(['success' => false, 'error' => 'Shipping line is already inactive'], 400);
            }
            
            // Get count of affected users before deactivation
            $affectedUsersCount = count($shippingLine->getScopedUsers());
            
            // Deactivate the shipping line
            $shippingLine->setIsActive(false);
            
            // Mark shipping line as deactivated for affected users (for modal notification)
            $this->deactivationNotificationService->markShippingLineAsDeactivated($shippingLine);
            
            // Log shipping line deactivation activity
            $this->activityLogService->logShippingLineDeactivation($currentUser, $shippingLine);
            
            // Notify all administrators of this shipping line
            foreach ($shippingLine->getShippingLineAdmins() as $admin) {
                $this->notificationService->createWarningNotification(
                    $admin,
                    'Shipping Line Deactivated',
                    sprintf('Your shipping line "%s" has been deactivated by a System Administrator. You and your team will be logged out and access will be restricted until reactivation.', $shippingLine->getBrandName()),
                    null,
                    null
                );
            }
            
            $this->entityManager->flush();
            
            $message = sprintf(
                'Shipping line "%s" deactivated successfully. %d user(s) will be logged out and denied access.',
                $shippingLine->getBrandName(),
                $affectedUsersCount
            );
            
            return new JsonResponse(['success' => true, 'message' => $message]);
            
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/update', name: 'admin_shipping_lines_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id): JsonResponse
    {
        return new JsonResponse(['success' => true, 'message' => 'Shipping line updated successfully']);
    }

    #[Route('/{id}/assign-admin', name: 'admin_shipping_lines_assign_admin', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assignAdmin(int $id, Request $request): JsonResponse
    {
        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'message' => 'Shipping line not found'], 404);
            }

            $adminId = $request->request->get('adminId');
            if (!$adminId) {
                return new JsonResponse(['success' => false, 'message' => 'Admin ID is required'], 400);
            }

            $admin = $this->entityManager->getRepository(User::class)->find($adminId);
            if (!$admin) {
                return new JsonResponse(['success' => false, 'message' => 'Admin user not found'], 404);
            }

            // Validate that the user has SHIPPING_LINES_ADMIN role
            if ($admin->getRole() !== \App\Entity\Enum\UserRole::SHIPPING_LINES_ADMIN) {
                return new JsonResponse(['success' => false, 'message' => 'User must have SHIPPING_LINES_ADMIN role'], 400);
            }

            // Check if admin is already managing another shipping line
            if ($admin->getManagedShippingLine() !== null) {
                return new JsonResponse(['success' => false, 'message' => 'Admin is already managing another shipping line'], 400);
            }

            // Assign the admin using the service
            $this->shippingLineService->assignAdmin($shippingLine, $admin, $this->getUser());

            return new JsonResponse([
                'success' => true, 
                'message' => 'Admin assigned successfully',
                'admin' => [
                    'id' => $admin->getId(),
                    'email' => $admin->getEmail(),
                    'isActive' => $admin->getStatus() === \App\Entity\Enum\AccountStatus::APPROVED
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/available-admins', name: 'admin_shipping_lines_available_admins', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAvailableAdmins(int $id): JsonResponse
    {
        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'message' => 'Shipping line not found'], 404);
            }

            // Get all SHIPPING_LINES_ADMIN users who are not already managing a shipping line
            $availableAdmins = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.managedShippingLine IS NULL')
                ->andWhere('u.status = :status')
                ->setParameter('role', \App\Entity\Enum\UserRole::SHIPPING_LINES_ADMIN)
                ->setParameter('status', \App\Entity\Enum\AccountStatus::APPROVED)
                ->getQuery()
                ->getResult();

            $adminData = [];
            foreach ($availableAdmins as $admin) {
                $adminData[] = [
                    'id' => $admin->getId(),
                    'email' => $admin->getEmail(),
                    'name' => method_exists($admin, 'getFirstName') ? 
                        $admin->getFirstName() . ' ' . $admin->getLastName() : 
                        $admin->getEmail(),
                    'createdAt' => $admin->getCreatedAt()->format('Y-m-d H:i:s')
                ];
            }

            return new JsonResponse([
                'success' => true,
                'admins' => $adminData
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/remove-admin/{adminId}', name: 'admin_shipping_lines_remove_admin', methods: ['POST'], requirements: ['id' => '\d+', 'adminId' => '\d+'])]
    public function removeAdmin(int $id, int $adminId): JsonResponse
    {
        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            
            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'message' => 'Shipping line not found'], 404);
            }

            $admin = $this->entityManager->getRepository(User::class)->find($adminId);
            if (!$admin) {
                return new JsonResponse(['success' => false, 'message' => 'Admin user not found'], 404);
            }

            // Check if admin is actually managing this shipping line
            if ($admin->getManagedShippingLine() !== $shippingLine) {
                return new JsonResponse(['success' => false, 'message' => 'Admin is not managing this shipping line'], 400);
            }

            // Remove the admin assignment
            $admin->setManagedShippingLine(null);
            $shippingLine->removeShippingLineAdmin($admin);
            
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true, 
                'message' => 'Admin removed successfully'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/container-yards', name: 'admin_shipping_lines_container_yards', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getContainerYards(int $id): JsonResponse
    {
        try {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);

            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'message' => 'Shipping line not found'], 404);
            }

            $allTerminals = $this->entityManager->getRepository(Terminal::class)->findAll();
            $admins = $shippingLine->getShippingLineAdmins();

            $allocations = [];
            foreach ($admins as $admin) {
                $adminAllocations = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
                    ->findBy([
                        'shippingLine' => $shippingLine,
                        'staffUser' => $admin
                    ]);

                foreach ($adminAllocations as $allocation) {
                    $allocations[$allocation->getTerminal()->getId()] = [
                        'id' => $allocation->getId(),
                        'terminalId' => $allocation->getTerminal()->getId(),
                        'terminalName' => $allocation->getTerminal()->getName(),
                        'allocatedTEUs' => $allocation->getAllocatedCapacity(),
                        'capacity20ft' => $allocation->getCapacity20ft(),
                        'capacity40ft' => $allocation->getCapacity40ft()
                    ];
                }
            }

            $terminals = [];
            foreach ($allTerminals as $terminal) {
                $terminals[] = [
                    'id' => $terminal->getId(),
                    'name' => $terminal->getName(),
                    'type' => $terminal->getType()->value,
                    'location' => $terminal->getLocation(),
                    'dailyCapacity' => $terminal->getDailyCapacity(),
                    'isActive' => $terminal->isActive(),
                    'allocation' => $allocations[$terminal->getId()] ?? null
                ];
            }

            return new JsonResponse(['success' => true, 'terminals' => $terminals]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/container-yards/allocate', name: 'admin_shipping_lines_allocate_yard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function allocateContainerYard(int $id, Request $request): JsonResponse
    {
        try {
            $currentUser = $this->getUser();
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'message' => 'Shipping line not found'], 404);
            }

            $terminalId = $request->request->get('terminalId');
            $allocatedTEUs = (int) $request->request->get('allocatedTEUs');
            $capacity20ft = (int) $request->request->get('capacity20ft', 0);
            $capacity40ft = (int) $request->request->get('capacity40ft', 0);

            if (!$terminalId || $allocatedTEUs <= 0) {
                return new JsonResponse(['success' => false, 'message' => 'Invalid terminal or TEU allocation'], 400);
            }

            $terminal = $this->entityManager->getRepository(Terminal::class)->find($terminalId);
            if (!$terminal) {
                return new JsonResponse(['success' => false, 'message' => 'Terminal not found'], 404);
            }

            $admins = $shippingLine->getShippingLineAdmins();
            if ($admins->isEmpty()) {
                return new JsonResponse(['success' => false, 'message' => 'No administrators assigned'], 400);
            }

            $admin = $admins->first();
            $allocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
                ->findOneBy([
                    'shippingLine' => $shippingLine,
                    'staffUser' => $admin,
                    'terminal' => $terminal
                ]);

            $isUpdate = $allocation !== null;
            $oldCapacity = $isUpdate ? $allocation->getAllocatedCapacity() : null;

            if ($allocation) {
                $allocation->setAllocatedCapacity($allocatedTEUs);
                $allocation->setCapacity20ft($capacity20ft);
                $allocation->setCapacity40ft($capacity40ft);
                $allocation->setUpdatedAt(new \DateTime());
            } else {
                $allocation = new ShippingLineTerminalAllocation();
                $allocation->setStaffUser($admin);
                $allocation->setShippingLine($shippingLine);
                $allocation->setTerminal($terminal);
                $allocation->setAllocatedCapacity($allocatedTEUs);
                $allocation->setCapacity20ft($capacity20ft);
                $allocation->setCapacity40ft($capacity40ft);
                $this->entityManager->persist($allocation);
            }

            $this->entityManager->flush();

            // Log activity
            if ($isUpdate) {
                $this->activityLogService->logActivity(
                    $currentUser,
                    'container_yard_allocation_updated',
                    'ShippingLineTerminalAllocation',
                    $allocation->getId(),
                    [
                        'shipping_line' => $shippingLine->getBrandName(),
                        'terminal' => $terminal->getName(),
                        'old_capacity' => $oldCapacity,
                        'new_capacity' => $allocatedTEUs,
                        'capacity_20ft' => $capacity20ft,
                        'capacity_40ft' => $capacity40ft
                    ]
                );

                // Notify shipping line admins
                foreach ($shippingLine->getShippingLineAdmins() as $slAdmin) {
                    $this->notificationService->createInfoNotification(
                        $slAdmin,
                        'Container Yard Allocation Updated',
                        sprintf(
                            'Container yard "%s" allocation updated: %d TEUs (%d x 20ft, %d x 40ft).',
                            $terminal->getName(),
                            $allocatedTEUs,
                            $capacity20ft,
                            $capacity40ft
                        ),
                        null,
                        null
                    );
                }
            } else {
                $this->activityLogService->logActivity(
                    $currentUser,
                    'container_yard_allocation_created',
                    'ShippingLineTerminalAllocation',
                    $allocation->getId(),
                    [
                        'shipping_line' => $shippingLine->getBrandName(),
                        'terminal' => $terminal->getName(),
                        'allocated_capacity' => $allocatedTEUs,
                        'capacity_20ft' => $capacity20ft,
                        'capacity_40ft' => $capacity40ft
                    ]
                );

                // Notify shipping line admins
                foreach ($shippingLine->getShippingLineAdmins() as $slAdmin) {
                    $this->notificationService->createSuccessNotification(
                        $slAdmin,
                        'Container Yard Allocated',
                        sprintf(
                            'Container yard "%s" allocated: %d TEUs (%d x 20ft, %d x 40ft).',
                            $terminal->getName(),
                            $allocatedTEUs,
                            $capacity20ft,
                            $capacity40ft
                        ),
                        null,
                        null
                    );
                }
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Container yard allocated successfully',
                'allocation' => [
                    'id' => $allocation->getId(),
                    'terminalId' => $terminal->getId(),
                    'terminalName' => $terminal->getName(),
                    'allocatedTEUs' => $allocation->getAllocatedCapacity(),
                    'capacity20ft' => $allocation->getCapacity20ft(),
                    'capacity40ft' => $allocation->getCapacity40ft()
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/container-yards/{allocationId}/remove', name: 'admin_shipping_lines_remove_yard', methods: ['POST'], requirements: ['id' => '\d+', 'allocationId' => '\d+'])]
    public function removeContainerYard(int $id, int $allocationId): JsonResponse
    {
        try {
            $currentUser = $this->getUser();
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->find($id);
            if (!$shippingLine) {
                return new JsonResponse(['success' => false, 'message' => 'Shipping line not found'], 404);
            }

            $allocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)->find($allocationId);
            if (!$allocation) {
                return new JsonResponse(['success' => false, 'message' => 'Allocation not found'], 404);
            }

            $terminal = $allocation->getTerminal();
            $allocatedCapacity = $allocation->getAllocatedCapacity();

            // Log activity before removing
            $this->activityLogService->logActivity(
                $currentUser,
                'container_yard_allocation_removed',
                'ShippingLineTerminalAllocation',
                $allocation->getId(),
                [
                    'shipping_line' => $shippingLine->getBrandName(),
                    'terminal' => $terminal->getName(),
                    'allocated_capacity' => $allocatedCapacity
                ]
            );

            // Notify shipping line admins
            foreach ($shippingLine->getShippingLineAdmins() as $admin) {
                $this->notificationService->createWarningNotification(
                    $admin,
                    'Container Yard Allocation Removed',
                    sprintf(
                        'Container yard "%s" allocation (%d TEUs) has been removed.',
                        $terminal->getName(),
                        $allocatedCapacity
                    ),
                    null,
                    null
                );
            }

            $this->entityManager->remove($allocation);
            $this->entityManager->flush();

            return new JsonResponse(['success' => true, 'message' => 'Container yard allocation removed successfully']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

}