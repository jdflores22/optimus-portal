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

    #[Route('/dashboard', name: 'app_admin_legacy_dashboard_redirect')]
    public function dashboard(): Response
    {
        return $this->redirectToRoute('app_admin_dashboard');
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

    #[Route('/dashboard/users', name: 'app_admin_legacy_users_redirect')]
    public function userManagement(Request $request): Response
    {
        return $this->redirectToRoute('app_admin_users', $request->query->all());
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
