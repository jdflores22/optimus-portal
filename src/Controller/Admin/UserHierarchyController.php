<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\PendingUser;
use App\Entity\ActivityLog;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\InAppNotificationService;
use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/user-hierarchy')]
class UserHierarchyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InAppNotificationService $notificationService,
        private PendingUserService $pendingUserService,
        private EmailNotificationService $emailNotificationService,
        private ActivityLogService $activityLogService
    ) {
    }

    #[Route('', name: 'admin_user_hierarchy_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $currentUser = $this->getUser();
        
        // Get filters from request
        $filters = [
            'role' => $request->query->get('role', ''),
            'shipping_line_id' => $request->query->get('shipping_line_id', ''),
            'search' => $request->query->get('search', '')
        ];

        // Build query for users
        $queryBuilder = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->leftJoin('u.managedShippingLine', 'msl')
            ->leftJoin('u.shippingLineAdmin', 'sla')
            ->leftJoin('sla.managedShippingLine', 'sla_msl');

        // Apply role-based access control
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            // Shipping line admins can only see users in their scope
            $queryBuilder->andWhere('(u.id = :current_user_id OR u.shippingLineAdmin = :current_user)')
                ->setParameter('current_user_id', $currentUser->getId())
                ->setParameter('current_user', $currentUser);
        }
        // System admins can see all users (no additional restriction)

        // Apply filters
        if (!empty($filters['role'])) {
            $queryBuilder->andWhere('u.role = :role')
                ->setParameter('role', $filters['role']);
        }

        if (!empty($filters['shipping_line_id'])) {
            $queryBuilder->andWhere('(msl.id = :shipping_line_id OR sla_msl.id = :shipping_line_id)')
                ->setParameter('shipping_line_id', $filters['shipping_line_id']);
        }

        if (!empty($filters['search'])) {
            $queryBuilder->andWhere('(u.email LIKE :search OR (u INSTANCE OF App\\Entity\\StaffUser AND CONCAT(u.firstName, \' \', u.lastName) LIKE :search))')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        // Only show hierarchical users (exclude CONSIGNEE, BROKER, TRUCKER)
        $queryBuilder->andWhere('u.role IN (:hierarchical_roles)')
            ->setParameter('hierarchical_roles', [
                UserRole::SYSTEM_ADMIN,
                UserRole::SHIPPING_LINES_ADMIN,
                UserRole::SL_STAFF,
                UserRole::EVALUATOR,
                UserRole::ACCOUNTING,
                UserRole::TERMINAL_TEAM
            ]);

        $queryBuilder->orderBy('u.createdAt', 'DESC');

        $users = $queryBuilder->getQuery()->getResult();

        // Get all shipping lines for filter dropdown (filtered by user role)
        $shippingLinesQuery = $this->entityManager->getRepository(ShippingLine::class)->createQueryBuilder('sl');
        
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            // Shipping line admins can only see their own shipping line
            $shippingLinesQuery->where('sl.id = :managed_shipping_line_id')
                ->setParameter('managed_shipping_line_id', $currentUser->getManagedShippingLine()?->getId());
        }
        
        $shippingLines = $shippingLinesQuery->getQuery()->getResult();

        $pagination = [
            'page' => 1,
            'pages' => 1,
            'limit' => 20,
            'total' => count($users)
        ];

        return $this->render('admin/user_hierarchy/list.html.twig', [
            'users' => $users,
            'shippingLines' => $shippingLines,
            'filters' => $filters,
            'pagination' => $pagination
        ]);
    }

    #[Route('/statistics', name: 'admin_user_hierarchy_statistics', methods: ['GET'])]
    public function statistics(): Response
    {
        $currentUser = $this->getUser();
        
        // Access control: Only system admins can view statistics
        if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
            $this->addFlash('error', 'Access denied. Only system administrators can view statistics.');
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
        
        // Get real statistics from database
        $totalUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role IN (:hierarchical_roles)')
            ->setParameter('hierarchical_roles', [
                UserRole::SYSTEM_ADMIN,
                UserRole::SHIPPING_LINES_ADMIN,
                UserRole::SL_STAFF,
                UserRole::EVALUATOR,
                UserRole::ACCOUNTING,
                UserRole::TERMINAL_TEAM
            ])
            ->getQuery()
            ->getSingleScalarResult();

        $adminUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->setParameter('role', UserRole::SHIPPING_LINES_ADMIN)
            ->getQuery()
            ->getSingleScalarResult();

        $statistics = [
            'totalUsers' => $totalUsers,
            'adminUsers' => $adminUsers,
            'subordinateUsers' => $totalUsers - $adminUsers
        ];

        return $this->render('admin/user_hierarchy/statistics.html.twig', [
            'statistics' => $statistics
        ]);
    }

    #[Route('/validate-integrity', name: 'admin_user_hierarchy_validate_integrity', methods: ['GET'])]
    public function validateIntegrity(): Response
    {
        $currentUser = $this->getUser();
        
        // Access control: Only system admins can validate integrity
        if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
            $this->addFlash('error', 'Access denied. Only system administrators can validate integrity.');
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
        
        // Check for hierarchy integrity issues
        $issues = [];

        // Check for admins without shipping lines
        $adminsWithoutShippingLines = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.managedShippingLine IS NULL')
            ->setParameter('role', UserRole::SHIPPING_LINES_ADMIN)
            ->getQuery()
            ->getResult();

        if (!empty($adminsWithoutShippingLines)) {
            $issues[] = [
                'type' => 'warning',
                'message' => count($adminsWithoutShippingLines) . ' shipping line admin(s) without assigned shipping lines',
                'users' => $adminsWithoutShippingLines
            ];
        }

        // Check for subordinates without admins
        $subordinatesWithoutAdmins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role IN (:subordinate_roles)')
            ->andWhere('u.shippingLineAdmin IS NULL')
            ->setParameter('subordinate_roles', [
                UserRole::SL_STAFF,
                UserRole::EVALUATOR,
                UserRole::ACCOUNTING,
                UserRole::TERMINAL_TEAM
            ])
            ->getQuery()
            ->getResult();

        if (!empty($subordinatesWithoutAdmins)) {
            $issues[] = [
                'type' => 'error',
                'message' => count($subordinatesWithoutAdmins) . ' subordinate user(s) without assigned admin',
                'users' => $subordinatesWithoutAdmins
            ];
        }

        return $this->render('admin/user_hierarchy/validate_integrity.html.twig', [
            'issues' => $issues
        ]);
    }

    #[Route('/export', name: 'admin_user_hierarchy_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $format = $request->query->get('format', 'csv');
        
        // Get all hierarchical users
        $users = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->leftJoin('u.managedShippingLine', 'msl')
            ->leftJoin('u.shippingLineAdmin', 'sla')
            ->where('u.role IN (:hierarchical_roles)')
            ->setParameter('hierarchical_roles', [
                UserRole::SYSTEM_ADMIN,
                UserRole::SHIPPING_LINES_ADMIN,
                UserRole::SL_STAFF,
                UserRole::EVALUATOR,
                UserRole::ACCOUNTING,
                UserRole::TERMINAL_TEAM
            ])
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        if ($format === 'csv') {
            $response = new Response();
            $response->headers->set('Content-Type', 'text/csv');
            $response->headers->set('Content-Disposition', 'attachment; filename="user_hierarchy_' . date('Y-m-d') . '.csv"');

            $csv = "ID,Email,First Name,Last Name,Role,Shipping Line,Admin,Status,Created At\n";
            
            foreach ($users as $user) {
                $shippingLine = '';
                if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN && $user->getManagedShippingLine()) {
                    $shippingLine = $user->getManagedShippingLine()->getBrandName();
                } elseif ($user->getShippingLineAdmin() && $user->getShippingLineAdmin()->getManagedShippingLine()) {
                    $shippingLine = $user->getShippingLineAdmin()->getManagedShippingLine()->getBrandName();
                }

                $admin = $user->getShippingLineAdmin() ? $user->getShippingLineAdmin()->getEmail() : '';
                
                $csv .= sprintf(
                    "%d,%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $user->getId(),
                    $user->getEmail(),
                    $user instanceof \App\Entity\StaffUser ? $user->getFirstName() : '',
                    $user instanceof \App\Entity\StaffUser ? $user->getLastName() : '',
                    $user->getRole()->value,
                    $shippingLine,
                    $admin,
                    $user->getStatus()->value,
                    $user->getCreatedAt()->format('Y-m-d H:i:s')
                );
            }

            $response->setContent($csv);
            return $response;
        }

        return new Response('Unsupported format', 400);
    }

    #[Route('/validate-email', name: 'admin_user_hierarchy_validate_email', methods: ['POST'])]
    public function validateEmail(Request $request): JsonResponse
    {
        $email = $request->request->get('email');
        
        if (empty($email)) {
            return new JsonResponse(['valid' => false, 'message' => 'Email is required']);
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['valid' => false, 'message' => 'Invalid email format']);
        }

        // Check if email already exists in users table
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        
        if ($existingUser) {
            return new JsonResponse(['valid' => false, 'message' => 'This email address is already in use']);
        }

        // Check if email already exists in pending_users table
        $existingPendingUser = $this->entityManager->getRepository(PendingUser::class)->findOneBy(['email' => $email]);
        
        if ($existingPendingUser) {
            return new JsonResponse(['valid' => false, 'message' => 'This email address already has a pending invitation']);
        }

        return new JsonResponse(['valid' => true, 'message' => 'Email is available']);
    }

    #[Route('/create', name: 'admin_user_hierarchy_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $currentUser = $this->getUser();
        $preselectedAdminId = $request->query->get('admin_id');
        
        if ($request->isMethod('POST')) {
            try {
                // Validate CSRF token
                $submittedToken = $request->request->get('_token');
                if (!$this->isCsrfTokenValid('user_create', $submittedToken)) {
                    $this->addFlash('error', 'Invalid security token. Please try again.');
                    return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                }

                // Get form data
                $email = $request->request->get('email');
                $password = $request->request->get('password');
                $firstName = $request->request->get('firstName');
                $lastName = $request->request->get('lastName');
                $role = $request->request->get('role');
                $shippingLineId = $request->request->get('shippingLineId');
                $shippingLineAdminId = $request->request->get('shippingLineAdminId');
                $skipEmailNotification = $request->request->get('skipEmailNotification') === '1';

                // Validate required fields
                if (empty($email) || empty($firstName) || empty($lastName) || empty($role)) {
                    $this->addFlash('error', 'All required fields must be filled.');
                    return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                }

                // Password is only required for direct user creation (system admin bypass)
                if ($skipEmailNotification && empty($password)) {
                    $this->addFlash('error', 'Password is required when skipping email notification.');
                    return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                }

                // Check if user already exists
                $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existingUser) {
                    $this->addFlash('error', 'A user with this email already exists.');
                    return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                }

                // Validate role-specific requirements and access control
                $roleEnum = UserRole::from($role);
                
                // Access control: Shipping line admins can only create subordinates, not other admins
                if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                    if ($roleEnum === UserRole::SHIPPING_LINES_ADMIN) {
                        $this->addFlash('error', 'You cannot create other shipping line admins.');
                        return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                    }
                    if ($roleEnum === UserRole::SYSTEM_ADMIN) {
                        $this->addFlash('error', 'You cannot create system administrators.');
                        return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                    }
                    // Force the shipping line admin to be the current user
                    $shippingLineAdminId = $currentUser->getId();
                    
                    // Shipping line admins cannot skip email notification
                    if ($skipEmailNotification) {
                        $this->addFlash('error', 'You cannot skip email notification. Only system administrators can create users directly.');
                        return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                    }
                }
                
                if ($roleEnum === UserRole::SHIPPING_LINES_ADMIN && empty($shippingLineId)) {
                    $this->addFlash('error', 'Shipping line is required for Shipping Lines Admin role.');
                    return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                }

                if (in_array($roleEnum, [UserRole::SL_STAFF, UserRole::EVALUATOR, UserRole::ACCOUNTING, UserRole::TERMINAL_TEAM]) && empty($shippingLineAdminId)) {
                    $this->addFlash('error', 'Shipping line admin is required for subordinate roles.');
                    return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                }

                // Get shipping line and admin entities for relationships
                $shippingLine = null;
                $shippingLineAdmin = null;
                
                if ($shippingLineId) {
                    $shippingLine = $this->entityManager->getRepository(\App\Entity\ShippingLine::class)->find($shippingLineId);
                }
                
                if ($shippingLineAdminId) {
                    $admin = $this->entityManager->getRepository(User::class)->find($shippingLineAdminId);
                    if ($admin && $admin->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                        // Additional access control: Shipping line admins can only assign themselves as admin
                        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN && $admin->getId() !== $currentUser->getId()) {
                            $this->addFlash('error', 'You can only create users under your own management.');
                            return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
                        }
                        $shippingLineAdmin = $admin;
                    }
                }

                // Check if system admin wants to skip email notification (direct user creation)
                if ($currentUser->getRole() === UserRole::SYSTEM_ADMIN && $skipEmailNotification) {
                    // Create user directly (backward compatibility for system admins)
                    $user = new \App\Entity\StaffUser();
                    $user->setEmail($email);
                    $user->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
                    $user->setFirstName($firstName);
                    $user->setLastName($lastName);
                    $user->setRole($roleEnum);
                    $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
                    $user->setDepartment($this->getDepartmentByRole($roleEnum));

                    // Set hierarchy relationships
                    if ($roleEnum === UserRole::SHIPPING_LINES_ADMIN && $shippingLine) {
                        $user->setManagedShippingLine($shippingLine);
                    } elseif ($shippingLineAdmin) {
                        $user->setShippingLineAdmin($shippingLineAdmin);
                    }

                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    // Log user creation activity
                    $this->activityLogService->logUserCreation($currentUser, $user);

                    // Create notification for the new user
                    $this->notificationService->createSuccessNotification(
                        $user,
                        'Welcome to OPTIMUS!',
                        'Your account has been created successfully. You can now access all features available to your role.',
                        null,
                        null
                    );

                    // Create notification for the admin (if different from current user)
                    if ($user->getShippingLineAdmin() && $user->getShippingLineAdmin()->getId() !== $currentUser->getId()) {
                        $this->notificationService->createInfoNotification(
                            $user->getShippingLineAdmin(),
                            'New Team Member Added',
                            "A new {$user->getRole()->value} user ({$user->getEmail()}) has been added to your team.",
                            $this->generateUrl('admin_user_hierarchy_detail', ['id' => $user->getId()]),
                            'View User'
                        );
                    }

                    $this->addFlash('success', 'User created successfully.');
                    return $this->redirectToRoute('admin_user_hierarchy_detail', ['id' => $user->getId()]);
                } else {
                    // Create pending user and send email notification (new workflow)
                    try {
                        $pendingUser = $this->pendingUserService->createPendingUser(
                            $email,
                            $firstName,
                            $lastName,
                            $roleEnum,
                            $currentUser,
                            $shippingLine,
                            $shippingLineAdmin
                        );

                        // Send role acceptance email
                        $this->emailNotificationService->sendRoleAcceptanceEmail($pendingUser);

                        // Log pending user invitation activity
                        $this->activityLogService->logActivity(
                            $currentUser,
                            ActivityLog::TYPE_USER_INVITATION_SENT,
                            'PendingUser',
                            $pendingUser->getId(),
                            null,
                            [
                                'email' => $pendingUser->getEmail(),
                                'role' => $pendingUser->getRole()->value,
                                'firstName' => $pendingUser->getFirstName(),
                                'lastName' => $pendingUser->getLastName()
                            ]
                        );

                        $this->addFlash('success', 'Role invitation sent successfully. The user will receive an email to accept their role.');
                        return $this->redirectToRoute('admin_user_hierarchy_list');

                    } catch (\Exception $emailException) {
                        // If email sending fails, log the error and show warning but don't fail the entire process
                        $this->addFlash('warning', 'User invitation created but email delivery failed: ' . $emailException->getMessage() . '. You can resend the invitation from the pending users list.');
                        return $this->redirectToRoute('admin_user_hierarchy_list');
                    }
                }

            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to create user: ' . $e->getMessage());
                return $this->redirectToRoute('admin_user_hierarchy_create', $preselectedAdminId ? ['admin_id' => $preselectedAdminId] : []);
            }
        }

        // Get shipping lines with their admins for the form (filtered by user role)
        $shippingLinesQuery = $this->entityManager->getRepository(\App\Entity\ShippingLine::class)
            ->createQueryBuilder('sl')
            ->leftJoin('sl.shippingLineAdmins', 'admins')
            ->addSelect('admins');
            
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            // Shipping line admins can only see their own shipping line
            $shippingLinesQuery->where('sl.id = :managed_shipping_line_id')
                ->setParameter('managed_shipping_line_id', $currentUser->getManagedShippingLine()?->getId());
        }
        
        $shippingLines = $shippingLinesQuery->getQuery()->getResult();

        return $this->render('admin/user_hierarchy/create.html.twig', [
            'shippingLines' => $shippingLines,
            'preselectedAdminId' => $preselectedAdminId,
            'currentUser' => $currentUser
        ]);
    }

    private function getDepartmentByRole(UserRole $role): string
    {
        return match($role) {
            UserRole::SHIPPING_LINES_ADMIN => 'Administration',
            UserRole::SL_STAFF => 'Operations',
            UserRole::EVALUATOR => 'Evaluation',
            UserRole::ACCOUNTING => 'Finance',
            UserRole::TERMINAL_TEAM => 'Terminal Operations',
            default => 'General'
        };
    }

    #[Route('/{id}/edit', name: 'admin_user_hierarchy_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $currentUser = $this->getUser();
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            $this->addFlash('error', 'User not found or has been deleted.');
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }

        // Access control: Shipping line admins can only edit users in their scope
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $canAccess = ($user->getId() === $currentUser->getId()) || 
                        ($user->getShippingLineAdmin() && $user->getShippingLineAdmin()->getId() === $currentUser->getId());
            
            if (!$canAccess) {
                $this->addFlash('error', 'You do not have permission to edit this user. You can only edit users in your team.');
                return $this->redirectToRoute('admin_user_hierarchy_list');
            }
        }

        if ($request->isMethod('POST')) {
            try {
                // Update user fields
                if ($user instanceof \App\Entity\StaffUser) {
                    $user->setFirstName($request->request->get('firstName'));
                    $user->setLastName($request->request->get('lastName'));
                }
                
                // Update email (basic validation - in production you'd want more robust validation)
                $newEmail = $request->request->get('email');
                if ($newEmail !== $user->getEmail()) {
                    // Check if email already exists
                    $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $newEmail]);
                    if ($existingUser && $existingUser->getId() !== $user->getId()) {
                        $this->addFlash('error', 'Email address is already in use.');
                        return $this->render('admin/user_hierarchy/edit.html.twig', [
                            'user' => $user,
                            'currentUser' => $currentUser,
                        ]);
                    }
                    $user->setEmail($newEmail);
                }

                // Update role (only shipping line admins can change subordinate roles)
                $newRole = $request->request->get('role');
                if ($newRole && $currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN && $user->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                    // Validate that the new role is a valid subordinate role
                    $allowedRoles = ['SL_STAFF', 'EVALUATOR', 'ACCOUNTING', 'TERMINAL_TEAM'];
                    if (in_array($newRole, $allowedRoles)) {
                        $user->setRole(UserRole::from($newRole));
                    }
                }

                // Update status
                // BROKER SUSPENSION RESTRICTION: Only SYSTEM_ADMIN can suspend/activate brokers
                if ($user->getRole() === UserRole::BROKER) {
                    if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                        $this->addFlash('error', 'Only System Administrators can suspend or activate broker accounts. Please contact a System Administrator to request this action.');
                        return $this->redirectToRoute('admin_user_hierarchy_detail', ['id' => $user->getId()]);
                    }
                }
                
                $isActive = $request->request->get('isActive') === '1';
                if ($isActive) {
                    $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
                } else {
                    $user->setStatus(\App\Entity\Enum\AccountStatus::LOCKED);
                }

                $this->entityManager->flush();

                // Create notification for the user about the update
                if ($user->getId() !== $currentUser->getId()) {
                    $this->notificationService->createInfoNotification(
                        $user,
                        'Profile Updated',
                        'Your profile information has been updated by an administrator.',
                        null,
                        null
                    );
                }

                $this->addFlash('success', 'User updated successfully.');
                return $this->redirectToRoute('admin_user_hierarchy_detail', ['id' => $user->getId()]);

            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to update user: ' . $e->getMessage());
            }
        }

        return $this->render('admin/user_hierarchy/edit.html.twig', [
            'user' => $user,
            'currentUser' => $currentUser,
        ]);
    }

    #[Route('/{id}', name: 'admin_user_hierarchy_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $currentUser = $this->getUser();
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            $this->addFlash('error', 'User not found or has been deleted.');
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }

        // Access control: Shipping line admins can only view users in their scope
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $canAccess = ($user->getId() === $currentUser->getId()) || 
                        ($user->getShippingLineAdmin() && $user->getShippingLineAdmin()->getId() === $currentUser->getId());
            
            if (!$canAccess) {
                $this->addFlash('error', 'You do not have permission to view this user. You can only view users in your team.');
                return $this->redirectToRoute('admin_user_hierarchy_list');
            }
        }

        // Get subordinates for admin users
        $subordinates = [];
        if ($user->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $subordinates = $user->getSubordinateUsers()->toArray();
        }

        return $this->render('admin/user_hierarchy/detail.html.twig', [
            'user' => $user,
            'subordinates' => $subordinates,
            'currentUser' => $currentUser
        ]);
    }

    #[Route('/{id}/ajax', name: 'admin_user_hierarchy_detail_ajax', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detailAjax(int $id): JsonResponse
    {
        $currentUser = $this->getUser();
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'User not found or has been deleted'], 404);
        }

        // Access control: Shipping line admins can only view users in their scope
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $canAccess = ($user->getId() === $currentUser->getId()) || 
                        ($user->getShippingLineAdmin() && $user->getShippingLineAdmin()->getId() === $currentUser->getId());
            
            if (!$canAccess) {
                return new JsonResponse(['success' => false, 'error' => 'You do not have permission to view this user'], 403);
            }
        }

        $userData = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user instanceof \App\Entity\StaffUser ? $user->getFirstName() : '',
            'lastName' => $user instanceof \App\Entity\StaffUser ? $user->getLastName() : '',
            'isActive' => $user->isActive()
        ];

        return new JsonResponse([
            'success' => true,
            'data' => ['user' => $userData]
        ]);
    }

    #[Route('/{id}/update', name: 'admin_user_hierarchy_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $currentUser = $this->getUser();
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'User not found or has been deleted'], 404);
        }

        // Access control: Shipping line admins can only update users in their scope
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $canAccess = ($user->getId() === $currentUser->getId()) || 
                        ($user->getShippingLineAdmin() && $user->getShippingLineAdmin()->getId() === $currentUser->getId());
            
            if (!$canAccess) {
                return new JsonResponse(['success' => false, 'error' => 'You do not have permission to edit this user'], 403);
            }
        }

        try {
            // Update user fields if they exist
            if ($user instanceof \App\Entity\StaffUser && $request->request->has('firstName')) {
                $user->setFirstName($request->request->get('firstName'));
            }
            
            if ($user instanceof \App\Entity\StaffUser && $request->request->has('lastName')) {
                $user->setLastName($request->request->get('lastName'));
            }

            // Handle status updates
            if ($request->request->has('isActive')) {
                $isActive = $request->request->get('isActive') === '1';
                $oldStatus = $user->getStatus();
                
                // BROKER SUSPENSION RESTRICTION: Only SYSTEM_ADMIN can suspend/activate brokers
                if ($user->getRole() === UserRole::BROKER) {
                    if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
                        return new JsonResponse([
                            'success' => false, 
                            'error' => 'Only System Administrators can suspend or activate broker accounts. Please contact a System Administrator to request this action.'
                        ], 403);
                    }
                }
                
                if ($isActive) {
                    $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
                    
                    // Log user activation activity
                    if ($oldStatus !== \App\Entity\Enum\AccountStatus::APPROVED) {
                        $this->activityLogService->logUserActivation($currentUser, $user);
                        
                        // Create notification for the user about activation
                        $this->notificationService->createInfoNotification(
                            $user,
                            'Account Activated',
                            'Your account has been activated by an administrator.',
                            null,
                            null
                        );
                    }
                } else {
                    $user->setStatus(\App\Entity\Enum\AccountStatus::LOCKED);
                    
                    // Log user suspension activity
                    if ($oldStatus !== \App\Entity\Enum\AccountStatus::LOCKED) {
                        $this->activityLogService->logUserSuspension($currentUser, $user);
                        
                        // Create notification for the user about deactivation
                        $this->notificationService->createWarningNotification(
                            $user,
                            'Account Deactivated',
                            'Your account has been deactivated by an administrator. Please contact support if you believe this is an error.',
                            null,
                            null
                        );
                    }
                }
            }

            $this->entityManager->flush();

            // Create notification for the user about the update (if not updating themselves)
            if ($user->getId() !== $currentUser->getId()) {
                $this->notificationService->createInfoNotification(
                    $user,
                    'Profile Updated',
                    'Your profile information has been updated by an administrator.',
                    null,
                    null
                );
            }

            return new JsonResponse(['success' => true, 'message' => 'User updated successfully']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/remove-from-hierarchy', name: 'admin_user_hierarchy_remove_from_hierarchy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeFromHierarchy(int $id): JsonResponse
    {
        $currentUser = $this->getUser();
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'User not found or has been deleted'], 404);
        }

        // Access control: Shipping line admins can only remove users in their scope
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $canAccess = ($user->getShippingLineAdmin() && $user->getShippingLineAdmin()->getId() === $currentUser->getId());
            
            if (!$canAccess) {
                return new JsonResponse(['success' => false, 'error' => 'You do not have permission to remove this user'], 403);
            }
        }

        try {
            // Remove from hierarchy by clearing admin relationship
            if ($user->getShippingLineAdmin()) {
                $oldAdmin = $user->getShippingLineAdmin();
                $user->setShippingLineAdmin(null);
                
                // Log hierarchy change activity
                $this->activityLogService->logHierarchyChange($currentUser, $user, $oldAdmin, null);
                
                // Create notification for the user about hierarchy removal
                $this->notificationService->createWarningNotification(
                    $user,
                    'Removed from Hierarchy',
                    'You have been removed from the user hierarchy by an administrator.',
                    null,
                    null
                );
                
                $this->entityManager->flush();
            }

            return new JsonResponse(['success' => true, 'message' => 'User removed from hierarchy']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/pending-invitations', name: 'admin_user_hierarchy_pending_invitations', methods: ['GET'])]
    public function pendingInvitations(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
            $status = $request->query->get('status');
            $search = $request->query->get('search');
            
            $queryBuilder = $this->entityManager->getRepository(PendingUser::class)->createQueryBuilder('pu')
                ->leftJoin('pu.createdByAdmin', 'admin')
                ->leftJoin('pu.shippingLine', 'sl')
                ->leftJoin('pu.shippingLineAdmin', 'sla')
                ->orderBy('pu.createdAt', 'DESC');
            
            // Only filter by active statuses if no specific status is requested
            if (!$status) {
                // Default: only show pending and temporarily disabled (actionable invitations)
                $queryBuilder->where('pu.status IN (:activeStatuses)')
                    ->setParameter('activeStatuses', ['pending', 'temporarily_disabled']);
            }
            
            // Apply access control based on user role
            if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                // Shipping line admins can only see their own pending invitations
                $queryBuilder->andWhere('pu.createdByAdmin = :currentUser OR pu.shippingLineAdmin = :currentUser')
                    ->setParameter('currentUser', $currentUser);
            }
            // System admins can see all pending invitations (no additional filter needed)
            
            if ($status) {
                $queryBuilder->andWhere('pu.status = :status')
                    ->setParameter('status', $status);
            }
            
            if ($search) {
                $queryBuilder->andWhere('pu.email LIKE :search OR pu.firstName LIKE :search OR pu.lastName LIKE :search')
                    ->setParameter('search', '%' . $search . '%');
            }
            
            $totalQuery = clone $queryBuilder;
            $total = $totalQuery->select('COUNT(pu.id)')->getQuery()->getSingleScalarResult();
            
            $pendingUsers = $queryBuilder
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
            
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'pendingUsers' => array_map([$this, 'formatPendingUserData'], $pendingUsers),
                        'pagination' => [
                            'page' => $page,
                            'limit' => $limit,
                            'total' => $total,
                            'pages' => ceil($total / $limit)
                        ]
                    ]
                ]);
            }
            
            return $this->render('admin/user_hierarchy/pending_invitations.html.twig', [
                'pendingUsers' => $pendingUsers,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ],
                'filters' => [
                    'status' => $status,
                    'search' => $search
                ]
            ]);
            
        } catch (\Exception $e) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Failed to load pending invitations'], 500);
            }
            
            $this->addFlash('error', 'Failed to load pending invitations: ' . $e->getMessage());
            return $this->redirectToRoute('admin_user_hierarchy_list');
        }
    }

    #[Route('/pending-invitations/{id}/resend', name: 'admin_user_hierarchy_resend_invitation', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendInvitation(int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $pendingUser = $this->entityManager->getRepository(PendingUser::class)->find($id);
            
            if (!$pendingUser) {
                return $this->json(['success' => false, 'error' => 'Pending invitation not found'], 404);
            }
            
            // Check access control
            if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                if ($pendingUser->getCreatedByAdmin()->getId() !== $currentUser->getId() && 
                    (!$pendingUser->getShippingLineAdmin() || $pendingUser->getShippingLineAdmin()->getId() !== $currentUser->getId())) {
                    return $this->json(['success' => false, 'error' => 'Access denied'], 403);
                }
            }
            
            // Check if invitation can be resent
            if ($pendingUser->getStatus() === 'accepted') {
                return $this->json(['success' => false, 'error' => 'Cannot resend invitation for accepted user'], 400);
            }
            
            if ($pendingUser->getStatus() === 'declined') {
                return $this->json(['success' => false, 'error' => 'Cannot resend invitation for declined user'], 400);
            }
            
            $this->pendingUserService->resendInvitation($pendingUser);
            
            return $this->json([
                'success' => true,
                'message' => 'Invitation resent successfully to ' . $pendingUser->getEmail()
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Failed to resend invitation: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/pending-invitations/{id}/cancel', name: 'admin_user_hierarchy_cancel_invitation', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelInvitation(int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        try {
            $pendingUser = $this->entityManager->getRepository(PendingUser::class)->find($id);
            
            if (!$pendingUser) {
                return $this->json(['success' => false, 'error' => 'Pending invitation not found'], 404);
            }
            
            // Check access control
            if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                if ($pendingUser->getCreatedByAdmin()->getId() !== $currentUser->getId() && 
                    (!$pendingUser->getShippingLineAdmin() || $pendingUser->getShippingLineAdmin()->getId() !== $currentUser->getId())) {
                    return $this->json(['success' => false, 'error' => 'Access denied'], 403);
                }
            }
            
            // Check if invitation can be cancelled
            if ($pendingUser->getStatus() === 'accepted') {
                return $this->json(['success' => false, 'error' => 'Cannot cancel invitation for accepted user'], 400);
            }
            
            // Mark as declined and remove from database
            $email = $pendingUser->getEmail();
            $this->entityManager->remove($pendingUser);
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'message' => 'Invitation cancelled successfully for ' . $email
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Failed to cancel invitation: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/unlock', name: 'admin_user_hierarchy_unlock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unlockAccount(int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        // Access control: Only system admins can unlock accounts
        if ($currentUser->getRole() !== UserRole::SYSTEM_ADMIN) {
            return $this->json(['success' => false, 'error' => 'Access denied. Only system administrators can unlock accounts.'], 403);
        }
        
        try {
            $user = $this->entityManager->getRepository(User::class)->find($id);
            
            if (!$user) {
                return $this->json(['success' => false, 'error' => 'User not found'], 404);
            }
            
            // Check if user is actually locked
            if (!$user->isLocked() && $user->getFailedLoginAttempts() === 0) {
                return $this->json(['success' => false, 'error' => 'User account is not locked'], 400);
            }
            
            // Reset failed login attempts
            $user->resetFailedLoginAttempts();
            
            // Clear temporary lock
            $user->setLockedUntil(null);
            
            // If status is LOCKED, change to APPROVED
            if ($user->getStatus() === AccountStatus::LOCKED) {
                $user->setStatus(AccountStatus::APPROVED);
            }
            
            $this->entityManager->flush();
            
            // Log account unlock activity
            $this->activityLogService->logActivity(
                $currentUser,
                ActivityLog::TYPE_USER_UNLOCKED,
                'User',
                $user->getId(),
                null,
                [
                    'email' => $user->getEmail(),
                    'unlocked_by' => $currentUser->getEmail()
                ]
            );
            
            // Create notification for the unlocked user
            $this->notificationService->createSuccessNotification(
                $user,
                'Account Unlocked',
                'Your account has been unlocked by a system administrator. You can now log in again.',
                null,
                null
            );
            
            return $this->json([
                'success' => true,
                'message' => 'Account unlocked successfully'
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Failed to unlock account: ' . $e->getMessage()], 500);
        }
    }

    private function formatPendingUserData(PendingUser $pendingUser): array
    {
        return [
            'id' => $pendingUser->getId(),
            'email' => $pendingUser->getEmail(),
            'firstName' => $pendingUser->getFirstName(),
            'lastName' => $pendingUser->getLastName(),
            'fullName' => $pendingUser->getFullName(),
            'role' => $pendingUser->getRole(),
            'status' => $pendingUser->getStatus(),
            'tokenExpiresAt' => $pendingUser->getTokenExpiresAt(),
            'createdAt' => $pendingUser->getCreatedAt(),
            'isExpired' => $pendingUser->isExpired(),
            'canBeProcessed' => $pendingUser->canBeProcessed(),
            'createdByAdmin' => [
                'id' => $pendingUser->getCreatedByAdmin()->getId(),
                'email' => $pendingUser->getCreatedByAdmin()->getEmail(),
                'fullName' => $pendingUser->getCreatedByAdmin()->getFirstName() . ' ' . $pendingUser->getCreatedByAdmin()->getLastName()
            ],
            'shippingLine' => $pendingUser->getShippingLine() ? [
                'id' => $pendingUser->getShippingLine()->getId(),
                'brandName' => $pendingUser->getShippingLine()->getBrandName()
            ] : null,
            'shippingLineAdmin' => $pendingUser->getShippingLineAdmin() ? [
                'id' => $pendingUser->getShippingLineAdmin()->getId(),
                'email' => $pendingUser->getShippingLineAdmin()->getEmail(),
                'fullName' => $pendingUser->getShippingLineAdmin()->getFirstName() . ' ' . $pendingUser->getShippingLineAdmin()->getLastName()
            ] : null
        ];
    }
}