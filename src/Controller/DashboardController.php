<?php

namespace App\Controller;

use App\Entity\AccreditationSubmission;
use App\Entity\AuditLog;
use App\Entity\ConsigneeBrokerRelationship;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Manifest;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\ActivityLogService;
use App\Service\AuditService;
use App\Service\CacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

#[Route('/admin/dashboard')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class DashboardController extends AbstractController
{
    private string $configPath;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditService $auditService,
        private CacheService $cacheService,
        private ActivityLogService $activityLogService,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ) {
        $this->configPath = $projectDir . '/config/packages/rate_limiter.yaml';
    }

    #[Route('/', name: 'app_admin_dashboard')]
    public function index(): Response
    {
        $metrics = $this->calculateMetrics();
        $rateLimiters = $this->loadRateLimiters();
        
        return $this->render('admin/system_dashboard.html.twig', [
            'metrics' => $metrics,
            'limiters' => $rateLimiters,
        ]);
    }

    #[Route('/users', name: 'app_admin_users')]
    public function userManagement(Request $request): Response
    {
        $roleFilter = $request->query->get('role');
        
        $qb = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u');
            
        if ($roleFilter && $roleFilter !== 'all') {
            $qb->andWhere('u.role = :role')
               ->setParameter('role', UserRole::from($roleFilter));
        }
        
        $qb->orderBy('u.createdAt', 'DESC');
        
        $users = $qb->getQuery()->getResult();
        
        // Group users by role for display
        $usersByRole = [];
        foreach ($users as $user) {
            $role = $user->getRole()->value;
            if (!isset($usersByRole[$role])) {
                $usersByRole[$role] = [];
            }
            $usersByRole[$role][] = $user;
        }
        
        return $this->render('admin/user_management.html.twig', [
            'usersByRole' => $usersByRole,
            'allRoles' => UserRole::cases(),
            'currentFilter' => $roleFilter,
        ]);
    }

    #[Route('/audit-logs', name: 'app_admin_audit_logs')]
    public function auditLogs(Request $request): Response
    {
        $criteria = [];
        
        // Build search criteria from request parameters
        if ($startDate = $request->query->get('start_date')) {
            try {
                $criteria['startDate'] = new \DateTime($startDate);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }
        
        if ($endDate = $request->query->get('end_date')) {
            try {
                $criteria['endDate'] = new \DateTime($endDate . ' 23:59:59');
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }
        
        if ($userId = $request->query->get('user_id')) {
            $criteria['userId'] = (int) $userId;
        }
        
        if ($action = $request->query->get('action')) {
            $criteria['action'] = $action;
        }
        
        if ($entityType = $request->query->get('entity_type')) {
            $criteria['entityType'] = $entityType;
        }
        
        // Limit results to prevent performance issues
        $logs = array_slice($this->auditService->searchLogs($criteria), 0, 100);
        
        // Get all users for the filter dropdown
        $users = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
            
        // Get distinct actions and entity types for filters
        $distinctActions = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->select('DISTINCT a.action')
            ->orderBy('a.action', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
            
        $distinctEntityTypes = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->select('DISTINCT a.entityType')
            ->orderBy('a.entityType', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
        
        return $this->render('admin/audit_logs.html.twig', [
            'logs' => $logs,
            'users' => $users,
            'actions' => $distinctActions,
            'entityTypes' => $distinctEntityTypes,
            'filters' => $request->query->all(),
        ]);
    }

    /**
     * Calculate system-wide metrics for the dashboard
     */
    private function calculateMetrics(): array
    {
        // Try to get cached metrics first
        $cachedMetrics = $this->cacheService->getDashboardMetrics('SYSTEM_ADMIN');
        if (!empty($cachedMetrics)) {
            return $cachedMetrics;
        }

        // Accreditation metrics
        $accreditationRepo = $this->entityManager->getRepository(AccreditationSubmission::class);
        
        $pendingCount = $accreditationRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', AccreditationStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
            
        $approvedCount = $accreditationRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', AccreditationStatus::APPROVED)
            ->getQuery()
            ->getSingleScalarResult();
            
        $deniedCount = $accreditationRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status IN (:statuses)')
            ->setParameter('statuses', [AccreditationStatus::DENIED, AccreditationStatus::REJECTED])
            ->getQuery()
            ->getSingleScalarResult();
            
        $complianceRequiredCount = $accreditationRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', AccreditationStatus::COMPLIANCE_REQUIRED)
            ->getQuery()
            ->getSingleScalarResult();

        // User metrics by role
        $userRepo = $this->entityManager->getRepository(User::class);
        $userCounts = [];
        
        foreach (UserRole::cases() as $role) {
            $count = $userRepo->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->where('u.role = :role')
                ->setParameter('role', $role)
                ->getQuery()
                ->getSingleScalarResult();
            $userCounts[$role->value] = $count;
        }
        
        // Recent activity (last 7 days)
        $sevenDaysAgo = new \DateTime('-7 days');
        $recentSubmissions = $accreditationRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.submittedAt >= :date')
            ->setParameter('date', $sevenDaysAgo)
            ->getQuery()
            ->getSingleScalarResult();
            
        $recentAuditLogs = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.timestamp >= :date')
            ->setParameter('date', $sevenDaysAgo)
            ->getQuery()
            ->getSingleScalarResult();

        $metrics = [
            'accreditation' => [
                'pending' => $pendingCount,
                'approved' => $approvedCount,
                'denied' => $deniedCount,
                'compliance_required' => $complianceRequiredCount,
                'total' => $pendingCount + $approvedCount + $deniedCount + $complianceRequiredCount,
            ],
            'users' => [
                'by_role' => $userCounts,
                'total' => array_sum($userCounts),
            ],
            'recent_activity' => [
                'submissions' => $recentSubmissions,
                'audit_logs' => $recentAuditLogs,
            ],
        ];

        // Cache the calculated metrics
        $this->cacheService->cacheDashboardMetrics('SYSTEM_ADMIN', $metrics);

        return $metrics;
    }

    private function loadRateLimiters(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }

        $config = Yaml::parseFile($this->configPath);
        return $config['framework']['rate_limiter'] ?? [];
    }

    #[Route('/update-rate-limiter', name: 'app_admin_update_rate_limiter', methods: ['POST'])]
    public function updateRateLimiter(Request $request): Response
    {
        $limiterName = $request->request->get('limiter_name');
        $limit = (int) $request->request->get('limit');
        $interval = $request->request->get('interval');

        if (empty($limiterName) || $limit <= 0 || empty($interval)) {
            $this->addFlash('error', 'Invalid input. Please check all fields.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        try {
            $config = Yaml::parseFile($this->configPath);
            
            if (!isset($config['framework']['rate_limiter'][$limiterName])) {
                $this->addFlash('error', 'Rate limiter not found.');
                return $this->redirectToRoute('app_admin_dashboard');
            }

            // Update the configuration
            $config['framework']['rate_limiter'][$limiterName]['limit'] = $limit;
            $config['framework']['rate_limiter'][$limiterName]['interval'] = $interval;

            // Save the configuration
            $yaml = Yaml::dump($config, 4, 2);
            file_put_contents($this->configPath, $yaml);

            $this->addFlash('success', "Rate limiter '{$limiterName}' updated successfully. Run 'php bin/console cache:clear' to apply changes.");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update configuration: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/users/{id}/toggle-status', name: 'app_admin_toggle_user_status', methods: ['POST'])]
    public function toggleUserStatus(int $id, Request $request, #[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $user = $this->entityManager->getRepository(User::class)->find($id);
        
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'User not found'], 404);
        }

        // Prevent suspension of SYSTEM_ADMIN users
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return $this->json([
                'success' => false,
                'message' => 'System Administrator accounts cannot be suspended for security reasons.'
            ], 403);
        }

        // Check if user is a broker - only SYSTEM_ADMIN can suspend/activate brokers
        if ($user->getRole() === UserRole::BROKER) {
            if (!$this->isGranted('ROLE_SYSTEM_ADMIN')) {
                return $this->json([
                    'success' => false, 
                    'message' => 'Only System Administrators can suspend or activate broker accounts. Please coordinate with a System Administrator.'
                ], 403);
            }
        }

        try {
            $currentStatus = $user->getStatus();
            $newStatus = null;
            $action = '';

            // Toggle between APPROVED and DENIED
            if ($currentStatus->value === 'APPROVED') {
                // Suspending user - require reason
                $reason = $request->request->get('reason');
                if (empty($reason)) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Suspension reason is required'
                    ], 400);
                }

                $newStatus = AccountStatus::DENIED;
                $action = 'suspended';
                
                // Set deactivation details
                $user->setDeactivationReason($reason);
                $user->setDeactivatedAt(new \DateTime());
                $user->setDeactivatedBy($this->getUser());
                
                // Handle file uploads
                $uploadedFiles = $request->files->get('attachments', []);
                if (!empty($uploadedFiles)) {
                    $attachmentPaths = [];
                    $uploadDir = $projectDir . '/public/uploads/suspension_attachments';
                    
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    foreach ($uploadedFiles as $file) {
                        if ($file && $file->isValid()) {
                            $filename = uniqid() . '_' . $file->getClientOriginalName();
                            $file->move($uploadDir, $filename);
                            $attachmentPaths[] = '/uploads/suspension_attachments/' . $filename;
                        }
                    }
                    
                    if (!empty($attachmentPaths)) {
                        $user->setSuspensionAttachments($attachmentPaths);
                    }
                }
                
                // If suspending a broker, notify all their consignees
                if ($user->getRole() === UserRole::BROKER) {
                    try {
                        $this->notifyConsigneesOfBrokerSuspension($user);
                    } catch (\Exception $e) {
                        // Log the error but don't fail the suspension
                        error_log('Failed to notify consignees of broker suspension: ' . $e->getMessage());
                    }
                }
            } else {
                // Activating user - require remarks
                $remarks = $request->request->get('remarks');
                if (empty($remarks)) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Activation remarks are required'
                    ], 400);
                }
                
                $newStatus = AccountStatus::APPROVED;
                $action = 'activated';
                
                // Store activation details (reuse deactivation fields for activation info)
                $activationInfo = [
                    'activated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                    'activated_by' => $this->getUser()->getEmail(),
                    'activation_remarks' => $remarks,
                    'previous_suspension_reason' => $user->getDeactivationReason(),
                ];
                
                // Handle activation attachments
                $uploadedFiles = $request->files->get('attachments', []);
                if (!empty($uploadedFiles)) {
                    $attachmentPaths = [];
                    $uploadDir = $projectDir . '/public/uploads/activation_attachments';
                    
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    foreach ($uploadedFiles as $file) {
                        if ($file && $file->isValid()) {
                            $filename = uniqid() . '_' . $file->getClientOriginalName();
                            $file->move($uploadDir, $filename);
                            $attachmentPaths[] = '/uploads/activation_attachments/' . $filename;
                        }
                    }
                    
                    if (!empty($attachmentPaths)) {
                        $activationInfo['activation_attachments'] = $attachmentPaths;
                    }
                }
                
                // Store activation info in deactivation_reason as JSON for audit trail
                $user->setDeactivationReason(json_encode($activationInfo));
                $user->setDeactivatedAt(new \DateTime()); // Store activation timestamp
                $user->setDeactivatedBy($this->getUser()); // Store who activated
                $user->setSuspensionAttachments(null); // Clear suspension attachments
            }

            $user->setStatus($newStatus);
            $this->entityManager->flush();

            // Prepare detailed context for activity log
            $activityContext = [
                'user_email' => $user->getEmail(),
                'user_role' => $user->getRole()->value,
                'previous_status' => $currentStatus->value,
                'new_status' => $newStatus->value,
            ];

            if ($action === 'suspended') {
                $activityContext['suspension_reason'] = $user->getDeactivationReason();
                $activityContext['suspension_attachments_count'] = $user->getSuspensionAttachments() ? count($user->getSuspensionAttachments()) : 0;
                if ($user->getSuspensionAttachments()) {
                    $activityContext['suspension_attachments'] = $user->getSuspensionAttachments();
                }
                
                // Log to Activity Log with enhanced context
                $this->activityLogService->logActivity(
                    $this->getUser(),
                    'user_suspension',
                    'User',
                    $user->getId(),
                    ['status' => $currentStatus->value],
                    ['status' => $newStatus->value],
                    $activityContext
                );
            } else {
                $activationInfo = json_decode($user->getDeactivationReason(), true);
                $activityContext['activation_remarks'] = $activationInfo['activation_remarks'] ?? '';
                $activityContext['activation_attachments_count'] = isset($activationInfo['activation_attachments']) ? count($activationInfo['activation_attachments']) : 0;
                if (isset($activationInfo['activation_attachments'])) {
                    $activityContext['activation_attachments'] = $activationInfo['activation_attachments'];
                }
                $activityContext['previous_suspension_reason'] = $activationInfo['previous_suspension_reason'] ?? '';
                
                // Log to Activity Log with enhanced context
                $this->activityLogService->logActivity(
                    $this->getUser(),
                    'user_activation',
                    'User',
                    $user->getId(),
                    ['status' => $currentStatus->value],
                    ['status' => $newStatus->value],
                    $activityContext
                );
            }

            // Log to Audit Log with detailed information
            $logDetails = [
                'previous_status' => $currentStatus->value,
                'new_status' => $newStatus->value,
                'user_email' => $user->getEmail(),
                'user_role' => $user->getRole()->value,
                'action_type' => $action,
            ];

            if ($action === 'suspended') {
                $logDetails['suspension_reason'] = $user->getDeactivationReason();
                $logDetails['suspension_attachments_count'] = $user->getSuspensionAttachments() ? count($user->getSuspensionAttachments()) : 0;
                if ($user->getSuspensionAttachments()) {
                    $logDetails['suspension_attachments'] = $user->getSuspensionAttachments();
                }
            } else {
                $activationInfo = json_decode($user->getDeactivationReason(), true);
                $logDetails['activation_remarks'] = $activationInfo['activation_remarks'] ?? '';
                $logDetails['activation_attachments_count'] = isset($activationInfo['activation_attachments']) ? count($activationInfo['activation_attachments']) : 0;
                if (isset($activationInfo['activation_attachments'])) {
                    $logDetails['activation_attachments'] = $activationInfo['activation_attachments'];
                }
                $logDetails['previous_suspension_reason'] = $activationInfo['previous_suspension_reason'] ?? '';
            }

            $this->auditService->logAction(
                $this->getUser(),
                $action === 'suspended' ? 'user_suspended' : 'user_activated',
                'User',
                $user->getId(),
                $logDetails
            );

            return $this->json([
                'success' => true,
                'message' => "User {$action} successfully",
                'newStatus' => $newStatus->value,
                'action' => $action
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Failed to update user status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Notify all consignees when their broker is suspended
     */
    private function notifyConsigneesOfBrokerSuspension(User $broker): void
    {
        // Get all active consignees for this broker using the relationship service
        $relationships = $this->entityManager->getRepository(ConsigneeBrokerRelationship::class)
            ->createQueryBuilder('r')
            ->join('r.consignee', 'c')
            ->where('r.broker = :broker')
            ->andWhere('r.status = :status')
            ->andWhere('c.status = :accountStatus')
            ->setParameter('broker', $broker)
            ->setParameter('status', ConsigneeBrokerRelationship::STATUS_ACTIVE)
            ->setParameter('accountStatus', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();

        if (empty($relationships)) {
            return; // No consignees to notify
        }

        foreach ($relationships as $relationship) {
            $consignee = $relationship->getConsignee();
            
            // Check if this consignee has any manifests assigned to this broker
            $manifestCount = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->createQueryBuilder('m')
                ->select('COUNT(m.id)')
                ->where('m.consignee = :consignee')
                ->andWhere('m.broker = :broker')
                ->andWhere('m.workflowState != :completedState')
                ->setParameter('consignee', $consignee)
                ->setParameter('broker', $broker)
                ->setParameter('completedState', \App\Entity\Enum\WorkflowState::EDO_RELEASED)
                ->getQuery()
                ->getSingleScalarResult();
            
            // Create notification
            $notification = new Notification();
            $notification->setUser($consignee);
            $notification->setTitle('Broker Account Suspended');
            
            if ($manifestCount > 0) {
                $notification->setMessage(
                    "Broker {$broker->getEmail()} has been suspended by the system administrator. " .
                    "You have {$manifestCount} active manifest(s) assigned to this broker. " .
                    "Please assign a new broker to these manifests or add a new broker to your account."
                );
            } else {
                $notification->setMessage(
                    "Broker {$broker->getEmail()} has been suspended by the system administrator. " .
                    "This broker is in your approved broker list but has no active manifests."
                );
            }
            
            $notification->setType('warning');
            $notification->setActionUrl('/consignee/dashboard');
            $notification->setActionText('View Dashboard');
            
            $this->entityManager->persist($notification);
        }
        
        // Flush notifications separately to avoid interfering with main transaction
        $this->entityManager->flush();
    }
}
