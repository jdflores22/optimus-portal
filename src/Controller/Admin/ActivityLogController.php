<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\ActivityLog;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/activity-logs')]
class ActivityLogController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }
    #[Route('', name: 'admin_activity_logs_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $userId = $request->query->get('user_id');
        
        // Access control: Check if current user can view activity logs for the requested user
        if ($userId) {
            $targetUser = $this->entityManager->getRepository(User::class)->find($userId);
            
            if (!$targetUser) {
                $this->addFlash('error', 'User not found.');
                return $this->redirectToRoute('admin_user_hierarchy_list');
            }
            
            // Apply hierarchy-based access control
            if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
                // Shipping line admins can only view activity logs for users in their hierarchy
                $canAccess = ($targetUser->getId() === $currentUser->getId()) || 
                            ($targetUser->getShippingLineAdmin() && $targetUser->getShippingLineAdmin()->getId() === $currentUser->getId());
                
                if (!$canAccess) {
                    $this->addFlash('error', 'You do not have permission to view activity logs for this user. You can only view logs for users in your team.');
                    return $this->redirectToRoute('admin_user_hierarchy_list');
                }
            }
            // SYSTEM_ADMIN users have access to all activity logs (no additional restrictions)
        }

        $filters = [
            'from_date' => $request->query->get('from_date', ''),
            'to_date' => $request->query->get('to_date', ''),
            'activity_type' => $request->query->get('activity_type', ''),
            'entity_type' => $request->query->get('entity_type', ''),
            'user_id' => $userId,
            'search' => $request->query->get('search', '')
        ];

        // Sample activity logs data (in a real implementation, this would come from a database)
        // For now, let's fetch real activity logs from the database
        $queryBuilder = $this->entityManager->getRepository(ActivityLog::class)->createQueryBuilder('al')
            ->leftJoin('al.user', 'u')
            ->leftJoin('al.shippingLine', 'sl')
            ->addSelect('u', 'sl')
            ->orderBy('al.createdAt', 'DESC');

        // Apply access control based on current user's role
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            // Shipping line admins can only see activity logs for users in their hierarchy
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    'al.user = :currentUser',
                    'u.shippingLineAdmin = :currentUser'
                )
            )
            ->setParameter('currentUser', $currentUser);
        }
        // SYSTEM_ADMIN users can see all activity logs (no additional filtering)

        // Apply user filter if specified
        if ($userId && $targetUser) {
            $queryBuilder->andWhere('al.user = :targetUser')
                ->setParameter('targetUser', $targetUser);
        }

        // Apply other filters
        if (!empty($filters['activity_type'])) {
            $queryBuilder->andWhere('al.activityType = :activityType')
                ->setParameter('activityType', $filters['activity_type']);
        }

        if (!empty($filters['entity_type'])) {
            $queryBuilder->andWhere('al.entityType = :entityType')
                ->setParameter('entityType', $filters['entity_type']);
        }

        if (!empty($filters['from_date'])) {
            $queryBuilder->andWhere('al.createdAt >= :fromDate')
                ->setParameter('fromDate', new \DateTime($filters['from_date']));
        }

        if (!empty($filters['to_date'])) {
            $queryBuilder->andWhere('al.createdAt <= :toDate')
                ->setParameter('toDate', new \DateTime($filters['to_date'] . ' 23:59:59'));
        }

        if (!empty($filters['search'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    'al.activityType LIKE :search',
                    'al.entityType LIKE :search',
                    'u.email LIKE :search'
                )
            )
            ->setParameter('search', '%' . $filters['search'] . '%');
        }

        // Pagination
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        $totalResults = (clone $queryBuilder)->select('COUNT(al.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalResults / $limit));
        
        // Ensure page is within valid range
        $page = min($page, $totalPages);
        
        $activityLogs = $queryBuilder
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Get distinct activity types and entity types from the database for filter dropdowns
        $activityTypes = $this->entityManager->getRepository(ActivityLog::class)
            ->createQueryBuilder('al')
            ->select('DISTINCT al.activityType')
            ->getQuery()
            ->getResult();
        $activityTypes = array_column($activityTypes, 'activityType');

        $entityTypes = $this->entityManager->getRepository(ActivityLog::class)
            ->createQueryBuilder('al')
            ->select('DISTINCT al.entityType')
            ->getQuery()
            ->getResult();
        $entityTypes = array_column($entityTypes, 'entityType');
        
        // Get available users based on current user's access level
        $availableUsers = $this->getAccessibleUsers($currentUser);
        
        // Determine shipping line scope for shipping line admins
        $shippingLineScope = null;
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN && $currentUser->getManagedShippingLine()) {
            $shippingLineScope = $currentUser->getManagedShippingLine();
        }

        $pagination = [
            'page' => $page,
            'pages' => $totalPages,
            'limit' => $limit,
            'total' => $totalResults
        ];

        return $this->render('admin/activity_logs/list.html.twig', [
            'activityLogs' => $activityLogs,
            'filters' => $filters,
            'activityTypes' => $activityTypes,
            'entityTypes' => $entityTypes,
            'availableUsers' => $availableUsers,
            'pagination' => $pagination,
            'currentUser' => $currentUser,
            'targetUser' => $userId ? $targetUser : null,
            'shippingLineScope' => $shippingLineScope
        ]);
    }

    #[Route('/{id}', name: 'admin_activity_logs_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        
        // Fetch the actual activity log from the database
        $activityLog = $this->entityManager->getRepository(ActivityLog::class)->find($id);
        
        if (!$activityLog) {
            $this->addFlash('error', 'Activity log not found.');
            return $this->redirectToRoute('admin_activity_logs_list');
        }

        // Access control: Check if current user can view this activity log
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            // Get the user associated with this activity log
            $targetUser = $activityLog->getUser();
            
            if ($targetUser) {
                // Shipping line admins can only view activity logs for users in their hierarchy
                $canAccess = ($targetUser->getId() === $currentUser->getId()) || 
                            ($targetUser->getShippingLineAdmin() && $targetUser->getShippingLineAdmin()->getId() === $currentUser->getId());
                
                if (!$canAccess) {
                    $this->addFlash('error', 'You do not have permission to view this activity log.');
                    return $this->redirectToRoute('admin_activity_logs_list');
                }
            }
        }
        // SYSTEM_ADMIN users have access to all activity logs (no additional restrictions)

        return $this->render('admin/activity_logs/detail.html.twig', [
            'activityLog' => $activityLog,
            'isSystemAdmin' => $currentUser->getRole() === UserRole::SYSTEM_ADMIN,
            'currentUser' => $currentUser
        ]);
    }

    #[Route('/search', name: 'admin_activity_logs_search', methods: ['POST'])]
    public function search(Request $request): Response
    {
        return $this->forward('App\Controller\Admin\ActivityLogAdminController::search', [
            'request' => $request,
        ]);
    }

    #[Route('/reports', name: 'admin_activity_logs_reports', methods: ['GET'])]
    public function reports(Request $request): Response
    {
        return $this->forward('App\Controller\Admin\ActivityLogAdminController::reports', [
            'request' => $request,
        ]);
    }

    #[Route('/export', name: 'admin_activity_logs_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        return $this->forward('App\Controller\Admin\ActivityLogAdminController::export', [
            'request' => $request,
        ]);
    }

    /**
     * Get users that the current user can access based on hierarchy
     */
    private function getAccessibleUsers(User $currentUser): array
    {
        $queryBuilder = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->select('u.id, u.email')
            ->where('u.role IN (:hierarchical_roles)')
            ->setParameter('hierarchical_roles', [
                UserRole::SYSTEM_ADMIN,
                UserRole::SHIPPING_LINES_ADMIN,
                UserRole::SL_STAFF,
                UserRole::EVALUATOR,
                UserRole::ACCOUNTING,
                UserRole::TERMINAL_TEAM
            ])
            ->orderBy('u.email', 'ASC');

        // Apply access control based on current user's role
        if ($currentUser->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            // Shipping line admins can only see users in their hierarchy
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    'u.id = :currentUserId',
                    'u.shippingLineAdmin = :currentUser'
                )
            )
            ->setParameter('currentUserId', $currentUser->getId())
            ->setParameter('currentUser', $currentUser);
        }
        // SYSTEM_ADMIN users can see all users (no additional filtering)

        $users = $queryBuilder->getQuery()->getResult();
        
        // Convert to objects for template compatibility and get full names
        return array_map(function($user) {
            // Get the full user entity to access firstName/lastName if it's a StaffUser
            $fullUser = $this->entityManager->getRepository(User::class)->find($user['id']);
            $fullName = '';
            
            if ($fullUser instanceof \App\Entity\StaffUser) {
                $fullName = $fullUser->getFullName();
            } else {
                // For non-staff users, use email as display name
                $fullName = $user['email'];
            }
            
            return (object) [
                'id' => $user['id'],
                'email' => $user['email'],
                'fullName' => $fullName
            ];
        }, $users);
    }
}