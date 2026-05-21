<?php

namespace App\Controller\Admin;

use App\Entity\Enum\AccountStatus;
use App\Entity\Notification;
use App\Entity\SuspensionAppeal;
use App\Entity\User;
use App\Service\ActivityLogService;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/appeals')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class AppealsManagementController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogService $activityLogService,
        private AuditService $auditService
    ) {
    }

    #[Route('', name: 'admin_appeals_list', methods: ['GET'])]
    public function index(): Response
    {
        // Get all appeals with user information
        $appeals = $this->entityManager->getRepository(SuspensionAppeal::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->leftJoin('a.reviewedBy', 'r')
            ->addSelect('u', 'r')
            ->orderBy('a.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Count by status
        $pendingCount = 0;
        $approvedCount = 0;
        $rejectedCount = 0;

        foreach ($appeals as $appeal) {
            switch ($appeal->getStatus()) {
                case 'pending':
                    $pendingCount++;
                    break;
                case 'approved':
                    $approvedCount++;
                    break;
                case 'rejected':
                    $rejectedCount++;
                    break;
            }
        }

        return $this->render('admin/appeals/index.html.twig', [
            'appeals' => $appeals,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'rejectedCount' => $rejectedCount,
        ]);
    }

    #[Route('/{id}', name: 'admin_appeals_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        $appeal = $this->entityManager->getRepository(SuspensionAppeal::class)->find($id);

        if (!$appeal) {
            throw $this->createNotFoundException('Appeal not found');
        }

        // Parse deactivation reason if it's JSON (from activation)
        $deactivationReason = $appeal->getUser()->getDeactivationReason();
        $originalSuspensionReason = $deactivationReason;
        
        if ($deactivationReason && str_starts_with(trim($deactivationReason), '{')) {
            $activationData = json_decode($deactivationReason, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($activationData['previous_suspension_reason'])) {
                $originalSuspensionReason = $activationData['previous_suspension_reason'];
            }
        }

        return $this->render('admin/appeals/detail.html.twig', [
            'appeal' => $appeal,
            'originalSuspensionReason' => $originalSuspensionReason,
        ]);
    }

    #[Route('/{id}/review', name: 'admin_appeals_review', methods: ['POST'])]
    public function review(int $id, Request $request): JsonResponse
    {
        $appeal = $this->entityManager->getRepository(SuspensionAppeal::class)->find($id);

        if (!$appeal) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Appeal not found'
            ], 404);
        }

        if ($appeal->getStatus() !== 'pending') {
            return new JsonResponse([
                'success' => false,
                'message' => 'This appeal has already been reviewed'
            ], 400);
        }

        $action = $request->request->get('action'); // 'approve' or 'reject'
        $reviewNotes = $request->request->get('review_notes');

        if (!in_array($action, ['approve', 'reject'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid action'
            ], 400);
        }

        if (empty($reviewNotes)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Review notes are required'
            ], 400);
        }

        $user = $appeal->getUser();
        $admin = $this->getUser();

        // Update appeal
        $appeal->setStatus($action === 'approve' ? 'approved' : 'rejected');
        $appeal->setReviewedAt(new \DateTime());
        $appeal->setReviewedBy($admin);
        $appeal->setReviewNotes($reviewNotes);

        // If approved, reactivate the user account
        if ($action === 'approve') {
            $user->setStatus(AccountStatus::APPROVED);
            
            // Store activation info
            $activationInfo = [
                'activated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'activated_by' => $admin->getEmail(),
                'activation_method' => 'appeal_approved',
                'appeal_id' => $appeal->getId(),
                'activation_remarks' => 'Account reactivated via approved appeal',
                'previous_suspension_reason' => $user->getDeactivationReason(),
            ];
            
            $user->setDeactivationReason(json_encode($activationInfo));
            $user->setDeactivatedAt(new \DateTime());
            $user->setDeactivatedBy($admin);
            $user->setSuspensionAttachments(null);
        }

        $this->entityManager->flush();

        // Create notification for the broker
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setTitle($action === 'approve' ? 'Appeal Approved' : 'Appeal Rejected');
        $notification->setMessage(
            $action === 'approve' 
                ? 'Your suspension appeal has been approved. Your account has been reactivated.' 
                : 'Your suspension appeal has been rejected. Review notes: ' . substr($reviewNotes, 0, 100) . (strlen($reviewNotes) > 100 ? '...' : '')
        );
        $notification->setType($action === 'approve' ? 'success' : 'error');
        
        if ($action === 'approve') {
            $notification->setActionUrl('/broker/dashboard');
            $notification->setActionText('Go to Dashboard');
        }
        
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        // Log to Activity Log
        $activityContext = [
            'appeal_id' => $appeal->getId(),
            'broker_email' => $user->getEmail(),
            'broker_id' => $user->getId(),
            'action' => $action,
            'review_notes' => $reviewNotes,
            'reviewed_by' => $admin->getEmail(),
        ];

        $this->activityLogService->logActivity(
            $admin,
            'suspension_appeal_' . $action . 'd',
            'SuspensionAppeal',
            $appeal->getId(),
            ['status' => 'pending'],
            ['status' => $appeal->getStatus()],
            $activityContext
        );

        // Log to Audit Log
        $this->auditService->logAction(
            $admin,
            'suspension_appeal_' . $action . 'd',
            'SuspensionAppeal',
            $appeal->getId(),
            [
                'appeal_id' => $appeal->getId(),
                'broker_email' => $user->getEmail(),
                'broker_role' => $user->getRole()->value,
                'action' => $action,
                'review_notes' => $reviewNotes,
                'account_reactivated' => $action === 'approve',
            ]
        );

        return new JsonResponse([
            'success' => true,
            'message' => 'Appeal ' . $action . 'd successfully',
            'action' => $action
        ]);
    }
}
