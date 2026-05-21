<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Service\UserHierarchyService;
use App\Service\ActivityLogService;
use App\Service\ScopeAccessControlService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/user-hierarchy', name: 'admin_user_hierarchy_')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class UserHierarchyAdminController extends AbstractController
{
    public function __construct(
            private UserHierarchyService $userHierarchyService,
            private ActivityLogService $activityLogService,
            private ScopeAccessControlService $scopeAccessControlService,
            private EntityManagerInterface $entityManager,
            private UserPasswordHasherInterface $passwordHasher,
            private ValidatorInterface $validator
        ) {}


    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $role = $request->query->get('role');
            $shippingLineId = $request->query->get('shipping_line_id');
            $search = $request->query->get('search');
            
            $queryBuilder = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
                ->where('u.role IN (:hierarchicalRoles)')
                ->setParameter('hierarchicalRoles', [
                    UserRole::SHIPPING_LINES_ADMIN->value,
                    UserRole::SL_STAFF->value,
                    UserRole::EVALUATOR->value,
                    UserRole::ACCOUNTING->value,
                    UserRole::TERMINAL_TEAM->value
                ])
                ->orderBy('u.createdAt', 'DESC');
            
            if ($role) {
                $queryBuilder->andWhere('u.role = :role')
                    ->setParameter('role', $role);
            }
            
            if ($shippingLineId) {
                $queryBuilder->leftJoin('u.managedShippingLine', 'msl')
                    ->leftJoin('u.shippingLineAdmin', 'sla')
                    ->leftJoin('sla.managedShippingLine', 'sla_msl')
                    ->andWhere('msl.id = :shippingLineId OR sla_msl.id = :shippingLineId')
                    ->setParameter('shippingLineId', $shippingLineId);
            }
            
            if ($search) {
                $queryBuilder->andWhere('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
                    ->setParameter('search', '%' . $search . '%');
            }
            
            $totalQuery = clone $queryBuilder;
            $total = $totalQuery->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
            
            $users = $queryBuilder
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
            
            // Log the view action
            $this->activityLogService->logView($currentUser, (object)['type' => 'user_hierarchy_list']);
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'users' => array_map([$this, 'formatUserData'], $users),
                        'pagination' => [
                            'page' => $page,
                            'limit' => $limit,
                            'total' => $total,
                            'pages' => ceil($total / $limit)
                        ]
                    ]
                ]);
            }
            
            $shippingLines = $this->entityManager->getRepository(ShippingLine::class)
                ->findBy(['isActive' => true], ['brandName' => 'ASC']);
            
            return $this->render('admin/user_hierarchy/list.html.twig', [
                'users' => $users,
                'shippingLines' => $shippingLines,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ],
                'filters' => [
                    'role' => $role,
                    'shipping_line_id' => $shippingLineId,
                    'search' => $search
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'user_hierarchy_list', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load users'], 500);
            }
            
            $this->addFlash('error', 'Failed to load users: ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_dashboard');
        }
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        if ($request->isMethod('POST')) {
            try {
                $data = [
                    'email' => $request->request->get('email'),
                    'firstName' => $request->request->get('firstName'),
                    'lastName' => $request->request->get('lastName'),
                    'role' => $request->request->get('role'),
                    'password' => $request->request->get('password'),
                    'shippingLineAdminId' => $request->request->get('shippingLineAdminId'),
                    'shippingLineId' => $request->request->get('shippingLineId')
                ];
                
                // Validate input
                $errors = $this->validateUserData($data);
                if (!empty($errors)) {
                    if ($request->isXmlHttpRequest()) {
                        return $this->json(['success' => false, 'errors' => $errors], 400);
                    }
                    
                    foreach ($errors as $error) {
                        $this->addFlash('error', $error);
                    }
                    return $this->render('admin/user_hierarchy/create.html.twig', [
                        'shippingLines' => $this->getActiveShippingLines(),
                        'data' => $data
                    ]);
                }
                
                $user = $this->createUser($data, $currentUser);
                
                if ($request->isXmlHttpRequest()) {
                    return $this->json([
                        'success' => true,
                        'data' => $this->formatUserData($user),
                        'message' => 'User created successfully'
                    ]);
                }
                
                $this->addFlash('success', 'User "' . $user->getEmail() . '" created successfully');
                return $this->redirectToRoute('admin_user_hierarchy_detail', ['id' => $user->getId()]);
                
            } catch (\Exception $e) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
                }
                
                $this->addFlash('error', 'Failed to create user: ' . $e->getMessage());
            }
        }
        
        return $this->render('admin/user_hierarchy/create.html.twig', [
            'shippingLines' => $this->getActiveShippingLines()
        ]);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);
            
            if (!$user || !$this->isHierarchicalUser($user)) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'error' => 'User not found'], 404);
                }
                
                $this->addFlash('error', 'User not found');
                return $this->redirectToRoute('admin_user_hierarchy_list');
            }
            
            // Log the view action
            $this->activityLogService->logView($currentUser, $user);
            
            $hierarchyTree = [];
            $subordinates = [];
            
            if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                $hierarchyTree = $this->userHierarchyService->getHierarchyTree($user);
                $subordinates = $this->userHierarchyService->getSubordinateUsers($user)->toArray();
            }
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'user' => $this->formatUserData($user),
                        'hierarchyTree' => $hierarchyTree,
                        'subordinates' => array_map([$this, 'formatUserData'], $subordinates)
                    ]
                ]);
            }
            
            return $this->render('admin/user_hierarchy/detail.html.twig', [
                'user' => $user,
                'hierarchyTree' => $hierarchyTree,
                'subordinates' => $subordinates
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'user_hierarchy_detail', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load user details'], 500);
            }
            
            $this->addFlash('error', 'Failed to load user details: ' . $e->getMessage());
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
    }
    #[Route('/{id}/update', name: 'update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);
            
            if (!$user || !$this->isHierarchicalUser($user)) {
                return $this->json(['success' => false, 'error' => 'User not found'], 404);
            }
            
            $oldData = [
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'role' => $user->getRole()->value,
                'isActive' => $user->isActive()
            ];
            
            $data = [];
            if ($request->request->has('email')) {
                $data['email'] = $request->request->get('email');
            }
            if ($request->request->has('firstName')) {
                $data['firstName'] = $request->request->get('firstName');
            }
            if ($request->request->has('lastName')) {
                $data['lastName'] = $request->request->get('lastName');
            }
            if ($request->request->has('isActive')) {
                $data['isActive'] = $request->request->getBoolean('isActive');
            }
            
            // Validate input
            $errors = $this->validateUserUpdateData($data, $user);
            if (!empty($errors)) {
                return $this->json(['success' => false, 'errors' => $errors], 400);
            }
            
            $changes = [];
            
            if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
                $user->setEmail($data['email']);
                $changes['email'] = ['old' => $oldData['email'], 'new' => $data['email']];
            }
            
            if (isset($data['firstName']) && $data['firstName'] !== $user->getFirstName()) {
                $user->setFirstName($data['firstName']);
                $changes['firstName'] = ['old' => $oldData['firstName'], 'new' => $data['firstName']];
            }
            
            if (isset($data['lastName']) && $data['lastName'] !== $user->getLastName()) {
                $user->setLastName($data['lastName']);
                $changes['lastName'] = ['old' => $oldData['lastName'], 'new' => $data['lastName']];
            }
            
            if (isset($data['isActive']) && $data['isActive'] !== $user->isActive()) {
                $user->setIsActive($data['isActive']);
                $changes['isActive'] = ['old' => $oldData['isActive'], 'new' => $data['isActive']];
            }
            
            $this->entityManager->flush();
            
            // Log the update if there were changes
            if (!empty($changes)) {
                $this->activityLogService->logUpdate($currentUser, $user, $changes);
            }
            
            return $this->json([
                'success' => true,
                'data' => $this->formatUserData($user),
                'message' => 'User updated successfully'
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/link-to-admin', name: 'link_to_admin', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function linkToAdmin(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);
            $adminId = $request->request->get('adminId');
            
            if (!$user || !$this->isHierarchicalUser($user)) {
                return $this->json(['success' => false, 'error' => 'User not found'], 404);
            }
            
            if (!$adminId) {
                return $this->json(['success' => false, 'error' => 'Admin ID is required'], 400);
            }
            
            $admin = $this->entityManager->getRepository(User::class)->find($adminId);
            
            if (!$admin || $admin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                return $this->json(['success' => false, 'error' => 'Invalid admin user'], 404);
            }
            
            if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                return $this->json(['success' => false, 'error' => 'Cannot link admin to another admin'], 400);
            }
            
            $this->userHierarchyService->linkUserToAdmin($user, $admin, $currentUser);
            
            return $this->json([
                'success' => true,
                'message' => 'User linked to admin successfully',
                'data' => [
                    'userId' => $user->getId(),
                    'adminId' => $admin->getId(),
                    'adminEmail' => $admin->getEmail()
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/remove-from-hierarchy', name: 'remove_from_hierarchy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeFromHierarchy(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);
            
            if (!$user || !$this->isHierarchicalUser($user)) {
                return $this->json(['success' => false, 'error' => 'User not found'], 404);
            }
            
            if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN && $user->getSubordinateUsers()->count() > 0) {
                return $this->json([
                    'success' => false, 
                    'error' => 'Cannot remove admin with subordinates. Transfer or remove subordinates first.'
                ], 400);
            }
            
            $this->userHierarchyService->removeFromHierarchy($user, $currentUser);
            
            return $this->json([
                'success' => true,
                'message' => 'User removed from hierarchy successfully'
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/transfer-users', name: 'transfer_users', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transferUsers(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $fromAdmin = $this->entityManager->getRepository(User::class)->find($id);
            $toAdminId = $request->request->get('toAdminId');
            $userIds = $request->request->all('userIds');
            
            if (!$fromAdmin || $fromAdmin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                return $this->json(['success' => false, 'error' => 'Source admin not found'], 404);
            }
            
            if (!$toAdminId) {
                return $this->json(['success' => false, 'error' => 'Target admin ID is required'], 400);
            }
            
            $toAdmin = $this->entityManager->getRepository(User::class)->find($toAdminId);
            
            if (!$toAdmin || $toAdmin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                return $this->json(['success' => false, 'error' => 'Target admin not found'], 404);
            }
            
            if ($fromAdmin->getId() === $toAdmin->getId()) {
                return $this->json(['success' => false, 'error' => 'Cannot transfer to the same admin'], 400);
            }
            
            $this->userHierarchyService->transferUsers($fromAdmin, $toAdmin, $currentUser, $userIds ?: null);
            
            return $this->json([
                'success' => true,
                'message' => 'Users transferred successfully',
                'data' => [
                    'fromAdminId' => $fromAdmin->getId(),
                    'toAdminId' => $toAdmin->getId(),
                    'transferredCount' => empty($userIds) ? $fromAdmin->getSubordinateUsers()->count() : count($userIds)
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/orphaned-cleanup', name: 'orphaned_cleanup', methods: ['GET', 'POST'])]
    public function orphanedCleanup(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        if ($request->isMethod('POST')) {
            try {
                $strategy = $request->request->get('strategy');
                $newAdminId = $request->request->get('newAdminId');
                
                if (!in_array($strategy, ['deactivate', 'delete', 'reassign'])) {
                    return $this->json(['success' => false, 'error' => 'Invalid cleanup strategy'], 400);
                }
                
                if ($strategy === 'reassign' && !$newAdminId) {
                    return $this->json(['success' => false, 'error' => 'New admin ID required for reassign strategy'], 400);
                }
                
                $newAdmin = null;
                if ($newAdminId) {
                    $newAdmin = $this->entityManager->getRepository(User::class)->find($newAdminId);
                    if (!$newAdmin || $newAdmin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                        return $this->json(['success' => false, 'error' => 'Invalid new admin'], 404);
                    }
                }
                
                // Create a dummy deleted admin for the cleanup process
                $dummyAdmin = new User();
                $dummyAdmin->setEmail('deleted@system.local');
                $dummyAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
                
                $this->userHierarchyService->orphanedUserCleanup($dummyAdmin, $currentUser, $strategy, $newAdmin);
                
                return $this->json([
                    'success' => true,
                    'message' => 'Orphaned users cleanup completed successfully'
                ]);
                
            } catch (\Exception $e) {
                return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }
        
        try {
            // Get orphaned users for display
            $integrityIssues = $this->userHierarchyService->validateHierarchyIntegrity();
            $orphanedUsers = $integrityIssues['orphaned_users'] ?? [];
            
            // Log the view action
            $this->activityLogService->logView($currentUser, (object)['type' => 'orphaned_users_cleanup']);
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'orphanedUsers' => array_map([$this, 'formatUserData'], $orphanedUsers),
                        'availableAdmins' => array_map([$this, 'formatUserData'], $this->getAvailableAdmins())
                    ]
                ]);
            }
            
            return $this->render('admin/user_hierarchy/orphaned_cleanup.html.twig', [
                'orphanedUsers' => $orphanedUsers,
                'availableAdmins' => $this->getAvailableAdmins()
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'orphaned_cleanup', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load orphaned users'], 500);
            }
            
            $this->addFlash('error', 'Failed to load orphaned users: ' . $e->getMessage());
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
    }

    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    public function statistics(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $statistics = $this->userHierarchyService->getHierarchyStatistics();
            
            // Log the statistics view
            $this->activityLogService->logView($currentUser, (object)['type' => 'user_hierarchy_statistics']);
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => $statistics
                ]);
            }
            
            return $this->render('admin/user_hierarchy/statistics.html.twig', [
                'statistics' => $statistics
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'user_hierarchy_statistics', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load statistics'], 500);
            }
            
            $this->addFlash('error', 'Failed to load statistics: ' . $e->getMessage());
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
    }

    #[Route('/validate-integrity', name: 'validate_integrity', methods: ['GET'])]
    public function validateIntegrity(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $integrityIssues = $this->userHierarchyService->validateHierarchyIntegrity();
            
            // Log the integrity check
            $this->activityLogService->logSystemOperation($currentUser, 'hierarchy_integrity_check', [
                'issues_found' => count($integrityIssues['orphaned_users'] ?? []) + count($integrityIssues['invalid_links'] ?? [])
            ]);
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => $integrityIssues
                ]);
            }
            
            return $this->render('admin/user_hierarchy/integrity_check.html.twig', [
                'integrityIssues' => $integrityIssues
            ]);
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'hierarchy_integrity_check', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to validate integrity'], 500);
            }
            
            $this->addFlash('error', 'Failed to validate integrity: ' . $e->getMessage());
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $format = $request->query->get('format', 'csv');
            $role = $request->query->get('role');
            $shippingLineId = $request->query->get('shipping_line_id');
            
            $queryBuilder = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
                ->where('u.role IN (:hierarchicalRoles)')
                ->setParameter('hierarchicalRoles', [
                    UserRole::SHIPPING_LINES_ADMIN->value,
                    UserRole::SL_STAFF->value,
                    UserRole::EVALUATOR->value,
                    UserRole::ACCOUNTING->value,
                    UserRole::TERMINAL_TEAM->value
                ])
                ->orderBy('u.createdAt', 'DESC');
            
            if ($role) {
                $queryBuilder->andWhere('u.role = :role')
                    ->setParameter('role', $role);
            }
            
            if ($shippingLineId) {
                $queryBuilder->leftJoin('u.managedShippingLine', 'msl')
                    ->leftJoin('u.shippingLineAdmin', 'sla')
                    ->leftJoin('sla.managedShippingLine', 'sla_msl')
                    ->andWhere('msl.id = :shippingLineId OR sla_msl.id = :shippingLineId')
                    ->setParameter('shippingLineId', $shippingLineId);
            }
            
            $users = $queryBuilder->getQuery()->getResult();
            
            // Log the export action
            $this->activityLogService->logExport($currentUser, 'user_hierarchy', [
                'role' => $role,
                'shipping_line_id' => $shippingLineId
            ], count($users));
            
            if ($format === 'json') {
                $data = array_map([$this, 'formatUserData'], $users);
                
                $response = new JsonResponse($data);
                $response->headers->set('Content-Disposition', 'attachment; filename="user_hierarchy.json"');
                return $response;
            }
            
            // CSV Export
            $csvData = "ID,Email,First Name,Last Name,Role,Status,Shipping Line,Admin Email,Created At\n";
            foreach ($users as $user) {
                $shippingLineName = '';
                $adminEmail = '';
                
                if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN && $user->getManagedShippingLine()) {
                    $shippingLineName = $user->getManagedShippingLine()->getBrandName();
                } elseif ($user->getShippingLineAdmin()) {
                    $adminEmail = $user->getShippingLineAdmin()->getEmail();
                    if ($user->getShippingLineAdmin()->getManagedShippingLine()) {
                        $shippingLineName = $user->getShippingLineAdmin()->getManagedShippingLine()->getBrandName();
                    }
                }
                
                $csvData .= sprintf(
                    "%d,%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $user->getId(),
                    '"' . str_replace('"', '""', $user->getEmail()) . '"',
                    '"' . str_replace('"', '""', $user->getFirstName() ?? '') . '"',
                    '"' . str_replace('"', '""', $user->getLastName() ?? '') . '"',
                    $user->getRole()->value,
                    $user->isActive() ? 'Active' : 'Inactive',
                    '"' . str_replace('"', '""', $shippingLineName) . '"',
                    '"' . str_replace('"', '""', $adminEmail) . '"',
                    $user->getCreatedAt()->format('Y-m-d H:i:s')
                );
            }
            
            $response = new Response($csvData);
            $response->headers->set('Content-Type', 'text/csv');
            $response->headers->set('Content-Disposition', 'attachment; filename="user_hierarchy.csv"');
            
            return $response;
            
        } catch (\Exception $e) {
            $this->activityLogService->logAccessDenied($currentUser, 'user_hierarchy_export', $e->getMessage());
            
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Export failed'], 500);
            }
            
            $this->addFlash('error', 'Export failed: ' . $e->getMessage());
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
    }
    // Private helper methods

    private function formatUserData(User $user): array
    {
        $data = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'role' => $user->getRole()->value,
            'isActive' => $user->isActive(),
            'createdAt' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            'shippingLine' => null,
            'admin' => null,
            'subordinateCount' => 0
        ];
        
        if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN && $user->getManagedShippingLine()) {
            $data['shippingLine'] = [
                'id' => $user->getManagedShippingLine()->getId(),
                'brandName' => $user->getManagedShippingLine()->getBrandName()
            ];
            $data['subordinateCount'] = $user->getSubordinateUsers()->count();
        } elseif ($user->getShippingLineAdmin()) {
            $data['admin'] = [
                'id' => $user->getShippingLineAdmin()->getId(),
                'email' => $user->getShippingLineAdmin()->getEmail()
            ];
            
            if ($user->getShippingLineAdmin()->getManagedShippingLine()) {
                $data['shippingLine'] = [
                    'id' => $user->getShippingLineAdmin()->getManagedShippingLine()->getId(),
                    'brandName' => $user->getShippingLineAdmin()->getManagedShippingLine()->getBrandName()
                ];
            }
        }
        
        return $data;
    }

    private function isHierarchicalUser(User $user): bool
    {
        return in_array($user->getRole(), [
            UserRole::SHIPPING_LINES_ADMIN,
            UserRole::SL_STAFF,
            UserRole::EVALUATOR,
            UserRole::ACCOUNTING,
            UserRole::TERMINAL_TEAM
        ]);
    }

    private function validateUserData(array $data): array
    {
        $errors = [];
        
        if (empty($data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        } else {
            // Check if email already exists
            $existingUser = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $data['email']]);
            if ($existingUser) {
                $errors[] = 'Email already exists';
            }
        }
        
        if (empty($data['firstName'])) {
            $errors[] = 'First name is required';
        }
        
        if (empty($data['lastName'])) {
            $errors[] = 'Last name is required';
        }
        
        if (empty($data['role'])) {
            $errors[] = 'Role is required';
        } else {
            try {
                $role = UserRole::from($data['role']);
                if (!$this->isValidHierarchicalRole($role)) {
                    $errors[] = 'Invalid role for user hierarchy';
                }
            } catch (\ValueError $e) {
                $errors[] = 'Invalid role';
            }
        }
        
        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        
        // Validate hierarchy requirements
        if (!empty($data['role'])) {
            try {
                $role = UserRole::from($data['role']);
                
                if ($role === UserRole::SHIPPING_LINES_ADMIN) {
                    if (empty($data['shippingLineId'])) {
                        $errors[] = 'Shipping line is required for SHIPPING_LINES_ADMIN role';
                    } else {
                        $shippingLine = $this->entityManager->getRepository(ShippingLine::class)
                            ->find($data['shippingLineId']);
                        if (!$shippingLine || !$shippingLine->isActive()) {
                            $errors[] = 'Invalid or inactive shipping line';
                        }
                    }
                } else {
                    if (empty($data['shippingLineAdminId'])) {
                        $errors[] = 'Shipping line admin is required for subordinate roles';
                    } else {
                        $admin = $this->entityManager->getRepository(User::class)
                            ->find($data['shippingLineAdminId']);
                        if (!$admin || $admin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                            $errors[] = 'Invalid shipping line admin';
                        }
                    }
                }
            } catch (\ValueError $e) {
                // Role validation already handled above
            }
        }
        
        return $errors;
    }

    private function validateUserUpdateData(array $data, User $user): array
    {
        $errors = [];
        
        if (isset($data['email'])) {
            if (empty($data['email'])) {
                $errors[] = 'Email cannot be empty';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format';
            } elseif ($data['email'] !== $user->getEmail()) {
                // Check if email already exists
                $existingUser = $this->entityManager->getRepository(User::class)
                    ->findOneBy(['email' => $data['email']]);
                if ($existingUser && $existingUser->getId() !== $user->getId()) {
                    $errors[] = 'Email already exists';
                }
            }
        }
        
        if (isset($data['firstName']) && empty($data['firstName'])) {
            $errors[] = 'First name cannot be empty';
        }
        
        if (isset($data['lastName']) && empty($data['lastName'])) {
            $errors[] = 'Last name cannot be empty';
        }
        
        return $errors;
    }

    private function isValidHierarchicalRole(UserRole $role): bool
    {
        return in_array($role, [
            UserRole::SHIPPING_LINES_ADMIN,
            UserRole::SL_STAFF,
            UserRole::EVALUATOR,
            UserRole::ACCOUNTING,
            UserRole::TERMINAL_TEAM
        ]);
    }

    private function createUser(array $data, User $creator): User
    {
        $user = new User();
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setRole(UserRole::from($data['role']));
        $user->setIsActive(true);
        
        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);
        
        // Handle hierarchy linking
        if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)
                ->find($data['shippingLineId']);
            $user->setManagedShippingLine($shippingLine);
        } else {
            $admin = $this->entityManager->getRepository(User::class)
                ->find($data['shippingLineAdminId']);
            $user->setShippingLineAdmin($admin);
        }
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        // Log the creation
        $this->activityLogService->logCreate($creator, $user);
        
        // Link to hierarchy if needed
        if ($user->getRole() !== UserRole::SHIPPING_LINES_ADMIN && $user->getShippingLineAdmin()) {
            $this->userHierarchyService->linkUserToAdmin($user, $user->getShippingLineAdmin(), $creator);
        }
        
        return $user;
    }

    private function getActiveShippingLines(): array
    {
        return $this->entityManager->getRepository(ShippingLine::class)
            ->findBy(['isActive' => true], ['brandName' => 'ASC']);
    }

    private function getAvailableAdmins(): array
    {
        return $this->entityManager->getRepository(User::class)
            ->findBy([
                'role' => UserRole::SHIPPING_LINES_ADMIN,
                'isActive' => true
            ], ['email' => 'ASC']);
    }
}