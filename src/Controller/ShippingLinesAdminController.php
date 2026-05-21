<?php

namespace App\Controller;

use App\Entity\AccreditationSubmission;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\StoredFile;
use App\Service\AccreditationWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class ShippingLinesAdminController extends AbstractController
{
    public function __construct(
        private AccreditationWorkflowService $accreditationService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/dashboard', name: 'app_admin_dashboard')]
    public function dashboard(): Response
    {
        // Get comprehensive system statistics for SYSTEM_ADMIN
        $stats = [
            // User Statistics
            'total_users' => $this->entityManager->getRepository(\App\Entity\User::class)
                ->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->getQuery()
                ->getSingleScalarResult(),
            'active_users' => $this->entityManager->getRepository(\App\Entity\User::class)
                ->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->where('u.status = :status')
                ->setParameter('status', \App\Entity\Enum\AccountStatus::APPROVED)
                ->getQuery()
                ->getSingleScalarResult(),
            'locked_users' => $this->entityManager->getRepository(\App\Entity\User::class)
                ->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->where('u.status = :status')
                ->setParameter('status', \App\Entity\Enum\AccountStatus::LOCKED)
                ->getQuery()
                ->getSingleScalarResult(),
            'pending_users' => $this->entityManager->getRepository(\App\Entity\User::class)
                ->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->where('u.status = :status')
                ->setParameter('status', \App\Entity\Enum\AccountStatus::PENDING)
                ->getQuery()
                ->getSingleScalarResult(),
            
            // Accreditation Statistics
            'total_accreditations' => $this->entityManager->getRepository(AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->getQuery()
                ->getSingleScalarResult(),
            'pending_accreditations' => $this->entityManager->getRepository(AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.status = :status')
                ->setParameter('status', AccreditationStatus::PENDING)
                ->getQuery()
                ->getSingleScalarResult(),
            'approved_accreditations' => $this->entityManager->getRepository(AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.status = :status')
                ->setParameter('status', AccreditationStatus::APPROVED)
                ->getQuery()
                ->getSingleScalarResult(),
            'denied_accreditations' => $this->entityManager->getRepository(AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.status = :status')
                ->setParameter('status', AccreditationStatus::DENIED)
                ->getQuery()
                ->getSingleScalarResult(),
            
            // Shipment Statistics
            'total_shipments' => $this->entityManager->getRepository(\App\Entity\ShipmentRecord::class)
                ->createQueryBuilder('s')
                ->select('COUNT(s.id)')
                ->getQuery()
                ->getSingleScalarResult(),
            'shipments_today' => $this->entityManager->getRepository(\App\Entity\ShipmentRecord::class)
                ->createQueryBuilder('s')
                ->select('COUNT(s.id)')
                ->where('s.createdAt >= :today')
                ->setParameter('today', new \DateTime('today'))
                ->getQuery()
                ->getSingleScalarResult(),
            'shipments_this_week' => $this->entityManager->getRepository(\App\Entity\ShipmentRecord::class)
                ->createQueryBuilder('s')
                ->select('COUNT(s.id)')
                ->where('s.createdAt >= :thisWeek')
                ->setParameter('thisWeek', new \DateTime('-7 days'))
                ->getQuery()
                ->getSingleScalarResult(),
            
            // Payment Statistics
            'total_payments' => $this->entityManager->getRepository(\App\Entity\PaymentVerification::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->getQuery()
                ->getSingleScalarResult(),
            'pending_payments' => $this->entityManager->getRepository(\App\Entity\PaymentVerification::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.status = :status')
                ->setParameter('status', \App\Entity\Enum\PaymentStatus::PENDING_VALIDATION)
                ->getQuery()
                ->getSingleScalarResult(),
            'verified_payments' => $this->entityManager->getRepository(\App\Entity\PaymentVerification::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.status = :status')
                ->setParameter('status', \App\Entity\Enum\PaymentStatus::VERIFIED)
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        // Get user distribution by role
        $usersByRole = $this->entityManager->getRepository(\App\Entity\User::class)
            ->createQueryBuilder('u')
            ->select('u.role, COUNT(u.id) as count')
            ->groupBy('u.role')
            ->getQuery()
            ->getResult();

        // Get recent system activity (last 5 users registered)
        $recentUsers = $this->entityManager->getRepository(\App\Entity\User::class)
            ->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        // Get recent accreditation submissions
        $recentAccreditations = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->orderBy('a.submittedAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Get system health indicators
        $systemHealth = [
            'database_connection' => true, // We're already connected if we got here
            'total_files' => $this->entityManager->getRepository(StoredFile::class)
                ->createQueryBuilder('f')
                ->select('COUNT(f.id)')
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        // Get applications that need admin attention (for the original functionality)
        $pendingApplications = $this->accreditationService->getPendingSubmissions();
        $applicationsForApproval = $this->accreditationService->getSubmissionsForFinalApproval();
        $allApplications = array_merge($pendingApplications, $applicationsForApproval);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'usersByRole' => $usersByRole,
            'recentUsers' => $recentUsers,
            'recentAccreditations' => $recentAccreditations,
            'systemHealth' => $systemHealth,
            'applications' => $allApplications, // Keep for backward compatibility
        ]);
    }

    #[Route('/approved-accreditations', name: 'app_admin_approved_accreditations')]
    public function approvedAccreditations(): Response
    {
        // Get all approved accreditations
        $approvedAccreditations = $this->accreditationService->getApprovedAccreditations();
        
        // Separate by user role
        $brokers = [];
        $consignees = [];
        
        foreach ($approvedAccreditations as $accreditation) {
            if ($accreditation->getApplicant()->getRole()->value === 'BROKER') {
                $brokers[] = $accreditation;
            } elseif ($accreditation->getApplicant()->getRole()->value === 'CONSIGNEE') {
                $consignees[] = $accreditation;
            }
        }

        return $this->render('admin/approved_accreditations.html.twig', [
            'brokers' => $brokers,
            'consignees' => $consignees,
            'totalApproved' => count($approvedAccreditations),
        ]);
    }

    #[Route('/application/{id}', name: 'app_admin_application_detail')]
    public function applicationDetail(int $id): Response
    {
        $submission = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->find($id);

        if (!$submission) {
            throw $this->createNotFoundException('Application not found');
        }

        // Admin can view all applications for review purposes:
        // 1. Pending applications (for initial review)
        // 2. Evaluator-approved applications (for final approval)
        // 3. Fully approved applications (for historical review/audit)
        // 4. Denied applications (for review/audit)
        
        $allowedStatuses = [
            AccreditationStatus::PENDING,
            AccreditationStatus::APPROVED,
            AccreditationStatus::DENIED,
            AccreditationStatus::REJECTED,
            AccreditationStatus::COMPLIANCE_REQUIRED
        ];

        if (!in_array($submission->getStatus(), $allowedStatuses, true)) {
            throw $this->createAccessDeniedException('This application is not available for review');
        }

        return $this->render('admin/application_detail.html.twig', [
            'submission' => $submission,
        ]);
    }

    #[Route('/application/{id}/approve', name: 'app_admin_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        // Validate CSRF token
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_approve', $submittedToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_admin_application_detail', ['id' => $id]);
        }

        $decision = $request->request->get('decision');
        $reason = $request->request->get('reason');

        if (!in_array($decision, ['approve', 'deny'], true)) {
            $this->addFlash('error', 'Invalid decision');
            return $this->redirectToRoute('app_admin_application_detail', ['id' => $id]);
        }

        // Validate reason is provided for denials
        if ($decision === 'deny' && empty(trim($reason))) {
            $this->addFlash('error', 'Reason is required for denials');
            return $this->redirectToRoute('app_admin_application_detail', ['id' => $id]);
        }

        try {
            $this->accreditationService->finalApproval(
                $id,
                $this->getUser(),
                $decision === 'approve',
                $reason
            );

            $message = $decision === 'approve' 
                ? 'Application approved successfully' 
                : 'Application denied';
            $this->addFlash('success', $message);
            
            return $this->redirectToRoute('app_admin_dashboard');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_admin_application_detail', ['id' => $id]);
        }
    }

    #[Route('/file/{fileId}', name: 'app_admin_file_download', methods: ['GET'])]
    public function downloadFile(string $fileId): Response
    {
        $user = $this->getUser();

        try {
            // Debug logging
            error_log('Admin file download attempt: fileId=' . $fileId . ', userId=' . $user->getId() . ', userRole=' . $user->getRole()->value);
            
            // Get the stored file first to check if it exists
            $storedFile = $this->entityManager->getRepository(\App\Entity\StoredFile::class)
                ->findOneBy(['fileId' => $fileId]);
            
            if (!$storedFile) {
                error_log('File not found in database: fileId=' . $fileId);
                throw $this->createNotFoundException('File not found');
            }

            error_log('File found in database: ' . $storedFile->getOriginalName() . ', category=' . $storedFile->getCategory());

            // Check if admin can access this file (accreditation files)
            if ($storedFile->getCategory() !== 'accreditation') {
                error_log('Admin access denied - not an accreditation file: category=' . $storedFile->getCategory());
                throw $this->createAccessDeniedException('Access denied to this file type');
            }

            // Check if file exists on filesystem
            if (!file_exists($storedFile->getEncryptedPath())) {
                error_log('File not found on filesystem: ' . $storedFile->getEncryptedPath());
                throw $this->createNotFoundException('File not found on filesystem');
            }

            // Read file content directly
            $content = file_get_contents($storedFile->getEncryptedPath());
            
            if ($content === false) {
                error_log('Failed to read file: ' . $storedFile->getEncryptedPath());
                throw $this->createNotFoundException('Failed to read file');
            }

            error_log('File read successfully: size=' . strlen($content) . ', mimeType=' . $storedFile->getMimeType());

            // Create response with proper headers
            $response = new Response($content);
            $response->headers->set('Content-Type', $storedFile->getMimeType());
            $response->headers->set('Content-Length', (string) $storedFile->getSize());
            
            // Set filename for download
            $disposition = 'inline; filename="' . $storedFile->getOriginalName() . '"';
            $response->headers->set('Content-Disposition', $disposition);
            
            // Add cache headers for better performance
            $response->headers->set('Cache-Control', 'public, max-age=3600');
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + 3600));
            
            return $response;
            
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('Admin file download error: ' . $e->getMessage() . ' for fileId: ' . $fileId . ', userId: ' . $user->getId());
            throw $this->createNotFoundException('File not found: ' . $e->getMessage());
        }
    }

    #[Route('/dashboard/users', name: 'app_admin_users')]
    public function userManagement(Request $request): Response
    {
        $roleFilter = $request->query->get('role', 'all');
        
        $userRepository = $this->entityManager->getRepository(\App\Entity\User::class);
        
        if ($roleFilter === 'all') {
            $users = $userRepository->findAll();
        } else {
            $users = $userRepository->findBy(['role' => $roleFilter]);
        }
        
        // Group users by role
        $usersByRole = [];
        foreach ($users as $user) {
            $role = $user->getRole()->value;
            if (!isset($usersByRole[$role])) {
                $usersByRole[$role] = [];
            }
            $usersByRole[$role][] = $user;
        }
        
        // Get all available roles
        $allRoles = \App\Entity\Enum\UserRole::cases();
        
        return $this->render('admin/user_management.html.twig', [
            'usersByRole' => $usersByRole,
            'allRoles' => $allRoles,
            'currentFilter' => $roleFilter,
        ]);
    }

    #[Route('/users/{id}/unlock', name: 'app_admin_unlock_user', methods: ['POST'])]
    public function unlockUser(int $id, Request $request): Response
    {
        // Validate CSRF token
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('unlock_user_' . $id, $submittedToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_admin_users');
        }

        $user = $this->entityManager->getRepository(\App\Entity\User::class)->find($id);
        
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_admin_users');
        }

        // Check if user is actually locked
        if ($user->getStatus() !== \App\Entity\Enum\AccountStatus::LOCKED) {
            $this->addFlash('error', 'User account is not locked.');
            return $this->redirectToRoute('app_admin_users');
        }

        try {
            // Unlock the user account
            $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
            $user->setFailedLoginAttempts(0);
            $user->setLockedUntil(null);
            
            $this->entityManager->flush();
            
            $this->addFlash('success', 'User account has been successfully unlocked: ' . $user->getEmail());
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to unlock user account. Please try again.');
        }

        return $this->redirectToRoute('app_admin_users');
    }
}
