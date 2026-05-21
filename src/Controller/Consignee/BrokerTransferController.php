<?php

namespace App\Controller\Consignee;

use App\Repository\ManifestRepository;
use App\Service\ActivityLogService;
use App\Service\AuditService;
use App\Service\BrokerRelationshipService;
use App\Service\BrokerTransferService;
use App\Service\InAppNotificationService;
use App\Service\ManifestAuthorizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/consignee/broker-transfer')]
#[IsGranted('ROLE_CONSIGNEE')]
class BrokerTransferController extends AbstractController
{
    public function __construct(
        private BrokerTransferService $brokerTransferService,
        private BrokerRelationshipService $brokerRelationshipService,
        private ManifestRepository $manifestRepo,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RateLimiterFactory $brokerTransferRequestLimiter,
        private ActivityLogService $activityLogService,
        private AuditService $auditService,
        private ManifestAuthorizationService $manifestAuthorizationService,
        private InAppNotificationService $inAppNotificationService
    ) {
    }

    #[Route('/request', name: 'consignee_request_broker_transfer', methods: ['POST'])]
    public function requestTransfer(Request $request): Response
    {
        // Rate limiting
        $limiter = $this->brokerTransferRequestLimiter->create($this->getUser()->getId());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Too many broker assignment/transfer requests. You can make up to 20 requests per hour. Please try again later.');
            return $this->redirectToRoute('consignee_manifest_list');
        }

        // Validate CSRF token
        $csrfToken = new CsrfToken('request_broker_transfer', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('consignee_manifest_list');
        }

        $user = $this->getUser();
        
        $manifestId = $request->request->get('manifest_id');
        $newBrokerId = $request->request->get('new_broker_id');
        $reason = trim($request->request->get('reason', ''));
        
        // Validation
        if (empty($manifestId) || empty($newBrokerId)) {
            $this->addFlash('error', 'Manifest and new broker are required.');
            return $this->redirectToRoute('consignee_manifest_list');
        }
        
        if (empty($reason)) {
            $this->addFlash('error', 'Please provide a reason for the transfer request.');
            return $this->redirectToRoute('consignee_manifest_list');
        }
        
        $manifest = $this->manifestRepo->find($manifestId);
        $newBroker = $this->entityManager->getRepository(\App\Entity\User::class)->find($newBrokerId);
        
        if (!$manifest) {
            $this->addFlash('error', 'Manifest not found.');
            return $this->redirectToRoute('consignee_manifest_list');
        }
        
        if (!$newBroker) {
            $this->addFlash('error', 'Broker not found.');
            return $this->redirectToRoute('consignee_manifest_list');
        }
        
        // Check if new broker is suspended
        if ($newBroker->getStatus() === \App\Entity\Enum\AccountStatus::DENIED) {
            $this->addFlash('error', 'The selected broker is currently suspended and cannot be assigned to manifests.');
            return $this->redirectToRoute('consignee_manifest_detail', ['id' => $manifestId]);
        }
        
        // Verify ownership
        if ($manifest->getConsignee() !== $user) {
            throw $this->createAccessDeniedException('You do not have permission to transfer this manifest.');
        }
        
        // Verify relationship with new broker
        if (!$this->brokerRelationshipService->hasActiveRelationship($user, $newBroker)) {
            $this->addFlash('error', 'Selected broker is not linked to your account. Please use a referral code to link them first.');
            return $this->redirectToRoute('consignee_manifest_detail', ['id' => $manifestId]);
        }
        
        // Check if same broker
        if ($manifest->getBroker() && $manifest->getBroker()->getId() === $newBroker->getId()) {
            $this->addFlash('error', 'The selected broker is already assigned to this manifest.');
            return $this->redirectToRoute('consignee_manifest_detail', ['id' => $manifestId]);
        }
        
        // Handle file upload for transfer letter
        $transferLetterPath = null;
        $uploadedFile = $request->files->get('transfer_letter');
        
        if ($uploadedFile) {
            // Validate file
            $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                $this->addFlash('error', 'Invalid file type. Allowed types: PDF, DOC, DOCX, JPG, PNG');
                return $this->redirectToRoute('consignee_manifest_detail', ['id' => $manifestId]);
            }
            
            // Check file size (max 5MB)
            if ($uploadedFile->getSize() > 5 * 1024 * 1024) {
                $this->addFlash('error', 'File size must not exceed 5MB.');
                return $this->redirectToRoute('consignee_manifest_detail', ['id' => $manifestId]);
            }
            
            // Generate unique filename
            $filename = sprintf(
                'transfer_letter_%s_%s_%s.%s',
                $manifest->getId(),
                $user->getId(),
                uniqid(),
                $fileExtension
            );
            
            // Create upload directory if it doesn't exist
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/transfer_letters';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Move uploaded file
            try {
                $uploadedFile->move($uploadDir, $filename);
                $transferLetterPath = '/uploads/transfer_letters/' . $filename;
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to upload transfer letter: ' . $e->getMessage());
                return $this->redirectToRoute('consignee_manifest_detail', ['id' => $manifestId]);
            }
        }
        
        try {
            // If manifest has no broker yet, this is initial assignment - assign directly
            if (!$manifest->getBroker()) {
                $manifest->setBroker($newBroker);
                $this->entityManager->flush();
                
                // Invalidate authorization cache for this manifest
                $this->manifestAuthorizationService->invalidateManifestCache($manifest);
                
                // Send notification to broker
                try {
                    $this->inAppNotificationService->createNotification(
                        $newBroker,
                        'New Manifest Assigned',
                        sprintf(
                            'You have been assigned to manifest #%s by %s. Please review and process the manifest.',
                            $manifest->getManifestNumber(),
                            $user->getBusinessName() ?? $user->getEmail()
                        ),
                        'manifest_broker_assigned',
                        ['manifest_id' => $manifest->getId()]
                    );
                } catch (\Exception $e) {
                    // Log error but don't fail the assignment
                    error_log('Failed to send broker assignment notification: ' . $e->getMessage());
                }
                
                // Log to activity log
                $this->activityLogService->logActivity(
                    $user,
                    'manifest_broker_assigned',
                    'Manifest',
                    $manifest->getId(),
                    [
                        'manifest_number' => $manifest->getManifestNumber(),
                        'broker_id' => $newBroker->getId(),
                        'broker_email' => $newBroker->getEmail(),
                        'broker_name' => $newBroker->getFullName(),
                        'reason' => $reason,
                        'assignment_type' => 'initial',
                        'transfer_letter' => $transferLetterPath
                    ]
                );
                
                // Log to audit log
                $this->auditService->logAction(
                    $user,
                    'broker_assigned',
                    'Manifest',
                    $manifest->getId(),
                    [
                        'broker_id' => $newBroker->getId(),
                        'broker_email' => $newBroker->getEmail(),
                        'reason' => $reason,
                        'transfer_letter' => $transferLetterPath
                    ]
                );
                
                $this->addFlash('success', 'Broker assigned successfully to manifest #' . $manifest->getManifestNumber());
            } else {
                // If manifest already has a broker, create transfer request for SYSTEM_ADMIN approval
                $transferRequest = $this->brokerTransferService->createTransferRequest(
                    $manifest,
                    $user,
                    $newBroker,
                    $reason
                );
                
                // Set transfer letter path if uploaded
                if ($transferLetterPath) {
                    $transferRequest->setTransferLetter($transferLetterPath);
                    $this->entityManager->flush();
                }
                
                // Log to activity log
                $this->activityLogService->logActivity(
                    $user,
                    'broker_transfer_requested',
                    'BrokerTransferRequest',
                    $transferRequest->getId(),
                    [
                        'manifest_id' => $manifest->getId(),
                        'manifest_number' => $manifest->getManifestNumber(),
                        'old_broker_id' => $manifest->getBroker()->getId(),
                        'old_broker_email' => $manifest->getBroker()->getEmail(),
                        'old_broker_name' => $manifest->getBroker()->getFullName(),
                        'new_broker_id' => $newBroker->getId(),
                        'new_broker_email' => $newBroker->getEmail(),
                        'new_broker_name' => $newBroker->getFullName(),
                        'reason' => $reason,
                        'transfer_letter' => $transferLetterPath,
                        'status' => 'pending'
                    ]
                );
                
                // Log to audit log
                $this->auditService->logAction(
                    $user,
                    'broker_transfer_requested',
                    'BrokerTransferRequest',
                    $transferRequest->getId(),
                    [
                        'manifest_id' => $manifest->getId(),
                        'manifest_number' => $manifest->getManifestNumber(),
                        'old_broker' => $manifest->getBroker()->getEmail(),
                        'new_broker' => $newBroker->getEmail(),
                        'reason' => $reason,
                        'transfer_letter' => $transferLetterPath
                    ]
                );
                
                $this->addFlash('success', 'Transfer request submitted successfully. Awaiting approval from system administrator.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to process request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('consignee_manifest_detail', ['id' => $manifestId]);
    }

    #[Route('/requests', name: 'consignee_transfer_requests', methods: ['GET'])]
    public function listRequests(): Response
    {
        $user = $this->getUser();
        
        // Get all transfer requests for this consignee
        $pendingRequests = $this->brokerTransferService->getTransferRequestsForConsignee($user, 'pending');
        $approvedRequests = $this->brokerTransferService->getTransferRequestsForConsignee($user, 'approved');
        $rejectedRequests = $this->brokerTransferService->getTransferRequestsForConsignee($user, 'rejected');
        
        return $this->render('consignee/broker_transfer/requests.html.twig', [
            'pendingRequests' => $pendingRequests,
            'approvedRequests' => $approvedRequests,
            'rejectedRequests' => $rejectedRequests
        ]);
    }

    #[Route('/request/{id}/cancel', name: 'consignee_cancel_transfer_request', methods: ['POST'])]
    public function cancelRequest(int $id, Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('cancel_transfer_request', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('consignee_transfer_requests');
        }

        $user = $this->getUser();
        
        $transferRequest = $this->brokerTransferService->getPendingTransferRequests();
        $transferRequest = array_filter($transferRequest, fn($req) => $req->getId() === $id);
        $transferRequest = reset($transferRequest);
        
        if (!$transferRequest) {
            $this->addFlash('error', 'Transfer request not found or already processed.');
            return $this->redirectToRoute('consignee_transfer_requests');
        }
        
        try {
            $this->brokerTransferService->cancelTransferRequest($transferRequest, $user);
            $this->addFlash('success', 'Transfer request cancelled successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to cancel transfer request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('consignee_transfer_requests');
    }
}
