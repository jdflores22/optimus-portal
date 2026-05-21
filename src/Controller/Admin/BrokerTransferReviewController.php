<?php

namespace App\Controller\Admin;

use App\Service\ActivityLogService;
use App\Service\AuditService;
use App\Service\BrokerTransferService;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/admin/broker-transfer-requests')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class BrokerTransferReviewController extends AbstractController
{
    public function __construct(
        private BrokerTransferService $brokerTransferService,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ActivityLogService $activityLogService,
        private AuditService $auditService,
        private InAppNotificationService $inAppNotificationService
    ) {
    }

    #[Route('', name: 'admin_broker_transfer_requests', methods: ['GET'])]
    public function index(): Response
    {
        // Get all transfer requests grouped by status
        $pendingRequests = $this->brokerTransferService->getPendingTransferRequests();
        $approvedRequests = $this->brokerTransferService->getApprovedTransferRequests();
        $rejectedRequests = $this->brokerTransferService->getRejectedTransferRequests();
        
        return $this->render('admin/broker_transfer/index.html.twig', [
            'pendingRequests' => $pendingRequests,
            'approvedRequests' => $approvedRequests,
            'rejectedRequests' => $rejectedRequests,
        ]);
    }

    #[Route('/{id}', name: 'admin_broker_transfer_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        $transferRequest = $this->entityManager->getRepository(\App\Entity\BrokerTransferRequest::class)->find($id);
        
        if (!$transferRequest) {
            throw $this->createNotFoundException('Transfer request not found');
        }
        
        return $this->render('admin/broker_transfer/detail.html.twig', [
            'transferRequest' => $transferRequest,
        ]);
    }

    #[Route('/{id}/approve', name: 'admin_broker_transfer_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('approve_transfer', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('admin_broker_transfer_requests');
        }

        $transferRequest = $this->entityManager->getRepository(\App\Entity\BrokerTransferRequest::class)->find($id);
        
        if (!$transferRequest) {
            $this->addFlash('error', 'Transfer request not found.');
            return $this->redirectToRoute('admin_broker_transfer_requests');
        }
        
        if (!$transferRequest->isPending()) {
            $this->addFlash('error', 'This transfer request has already been processed.');
            return $this->redirectToRoute('admin_broker_transfer_detail', ['id' => $id]);
        }
        
        try {
            $reviewer = $this->getUser();
            
            // Approve the transfer
            $this->brokerTransferService->approveTransfer($transferRequest, $reviewer);
            
            // Log to activity log
            $this->activityLogService->logActivity(
                $reviewer,
                'broker_transfer_approved',
                'BrokerTransferRequest',
                $transferRequest->getId(),
                [
                    'manifest_id' => $transferRequest->getManifest()->getId(),
                    'manifest_number' => $transferRequest->getManifest()->getManifestNumber(),
                    'consignee_id' => $transferRequest->getConsignee()->getId(),
                    'old_broker_id' => $transferRequest->getOldBroker()->getId(),
                    'new_broker_id' => $transferRequest->getNewBroker()->getId(),
                    'transfer_letter' => $transferRequest->getTransferLetter()
                ]
            );
            
            // Log to audit log
            $this->auditService->logAction(
                $reviewer,
                'broker_transfer_approved',
                'BrokerTransferRequest',
                $transferRequest->getId(),
                [
                    'manifest_id' => $transferRequest->getManifest()->getId(),
                    'old_broker' => $transferRequest->getOldBroker()->getEmail(),
                    'new_broker' => $transferRequest->getNewBroker()->getEmail()
                ]
            );
            
            // Notify consignee
            $this->inAppNotificationService->createNotification(
                $transferRequest->getConsignee(),
                'Broker Transfer Approved',
                sprintf(
                    'Your broker transfer request for manifest #%s has been approved. The manifest is now assigned to %s.',
                    $transferRequest->getManifest()->getManifestNumber(),
                    $transferRequest->getNewBroker()->getFullName()
                ),
                'broker_transfer_approved',
                ['manifest_id' => $transferRequest->getManifest()->getId()]
            );
            
            // Notify new broker
            $this->inAppNotificationService->createNotification(
                $transferRequest->getNewBroker(),
                'New Manifest Assigned',
                sprintf(
                    'You have been assigned to manifest #%s. Please review and process the manifest.',
                    $transferRequest->getManifest()->getManifestNumber()
                ),
                'manifest_broker_assigned',
                ['manifest_id' => $transferRequest->getManifest()->getId()]
            );
            
            // Notify old broker
            $this->inAppNotificationService->createNotification(
                $transferRequest->getOldBroker(),
                'Manifest Transferred',
                sprintf(
                    'Manifest #%s has been transferred to another broker. You no longer have access to this manifest.',
                    $transferRequest->getManifest()->getManifestNumber()
                ),
                'manifest_broker_transferred',
                ['manifest_id' => $transferRequest->getManifest()->getId()]
            );
            
            $this->addFlash('success', 'Transfer request approved successfully. All parties have been notified.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to approve transfer request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_broker_transfer_requests');
    }

    #[Route('/{id}/reject', name: 'admin_broker_transfer_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('reject_transfer', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('admin_broker_transfer_requests');
        }

        $transferRequest = $this->entityManager->getRepository(\App\Entity\BrokerTransferRequest::class)->find($id);
        
        if (!$transferRequest) {
            $this->addFlash('error', 'Transfer request not found.');
            return $this->redirectToRoute('admin_broker_transfer_requests');
        }
        
        if (!$transferRequest->isPending()) {
            $this->addFlash('error', 'This transfer request has already been processed.');
            return $this->redirectToRoute('admin_broker_transfer_detail', ['id' => $id]);
        }
        
        $rejectionReason = trim($request->request->get('rejection_reason', ''));
        
        if (empty($rejectionReason)) {
            $this->addFlash('error', 'Please provide a reason for rejection.');
            return $this->redirectToRoute('admin_broker_transfer_detail', ['id' => $id]);
        }
        
        try {
            $reviewer = $this->getUser();
            
            // Reject the transfer
            $this->brokerTransferService->rejectTransfer($transferRequest, $reviewer, $rejectionReason);
            
            // Log to activity log
            $this->activityLogService->logActivity(
                $reviewer,
                'broker_transfer_rejected',
                'BrokerTransferRequest',
                $transferRequest->getId(),
                [
                    'manifest_id' => $transferRequest->getManifest()->getId(),
                    'manifest_number' => $transferRequest->getManifest()->getManifestNumber(),
                    'consignee_id' => $transferRequest->getConsignee()->getId(),
                    'old_broker_id' => $transferRequest->getOldBroker()->getId(),
                    'new_broker_id' => $transferRequest->getNewBroker()->getId(),
                    'rejection_reason' => $rejectionReason
                ]
            );
            
            // Log to audit log
            $this->auditService->logAction(
                $reviewer,
                'broker_transfer_rejected',
                'BrokerTransferRequest',
                $transferRequest->getId(),
                [
                    'manifest_id' => $transferRequest->getManifest()->getId(),
                    'rejection_reason' => $rejectionReason
                ]
            );
            
            // Notify consignee
            $this->inAppNotificationService->createNotification(
                $transferRequest->getConsignee(),
                'Broker Transfer Rejected',
                sprintf(
                    'Your broker transfer request for manifest #%s has been rejected. Reason: %s',
                    $transferRequest->getManifest()->getManifestNumber(),
                    $rejectionReason
                ),
                'broker_transfer_rejected',
                ['manifest_id' => $transferRequest->getManifest()->getId()]
            );
            
            $this->addFlash('success', 'Transfer request rejected. Consignee has been notified.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to reject transfer request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_broker_transfer_requests');
    }
}
