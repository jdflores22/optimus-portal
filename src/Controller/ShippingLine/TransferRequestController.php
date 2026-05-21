<?php

namespace App\Controller\ShippingLine;

use App\Entity\BrokerTransferRequest;
use App\Service\BrokerTransferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/sl-staff/transfer-requests')]
#[IsGranted('ROLE_SL_STAFF')]
class TransferRequestController extends AbstractController
{
    public function __construct(
        private BrokerTransferService $brokerTransferService,
        private CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }

    #[Route('', name: 'sl_staff_transfer_requests', methods: ['GET'])]
    public function index(): Response
    {
        $pendingRequests = $this->brokerTransferService->getPendingTransferRequests();
        $recentlyReviewed = $this->brokerTransferService->getRecentlyReviewedRequests(10);
        
        return $this->render('shipping_line/transfer_requests/index.html.twig', [
            'pendingRequests' => $pendingRequests,
            'recentlyReviewed' => $recentlyReviewed
        ]);
    }

    #[Route('/{id}', name: 'sl_staff_transfer_request_detail', methods: ['GET'])]
    public function detail(BrokerTransferRequest $transferRequest): Response
    {
        // Get transfer history for this manifest
        $manifest = $transferRequest->getManifest();
        $transferHistory = $this->brokerTransferService->getTransferRequestsForManifest($manifest);
        
        return $this->render('shipping_line/transfer_requests/detail.html.twig', [
            'transferRequest' => $transferRequest,
            'transferHistory' => $transferHistory
        ]);
    }

    #[Route('/{id}/approve', name: 'sl_staff_approve_transfer', methods: ['POST'])]
    public function approve(BrokerTransferRequest $transferRequest, Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('approve_transfer', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('sl_staff_transfer_requests');
        }

        $user = $this->getUser();
        
        try {
            $this->brokerTransferService->approveTransfer($transferRequest, $user);
            
            $this->addFlash('success', sprintf(
                'Transfer request approved successfully. Manifest #%s has been transferred to %s.',
                $transferRequest->getManifest()->getManifestNumber(),
                $transferRequest->getNewBroker()->getEmail()
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to approve transfer request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('sl_staff_transfer_requests');
    }

    #[Route('/{id}/reject', name: 'sl_staff_reject_transfer', methods: ['POST'])]
    public function reject(BrokerTransferRequest $transferRequest, Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('reject_transfer', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('sl_staff_transfer_requests');
        }

        $user = $this->getUser();
        $notes = trim($request->request->get('notes', ''));
        
        if (empty($notes)) {
            $this->addFlash('error', 'Please provide a reason for rejecting the transfer request.');
            return $this->redirectToRoute('sl_staff_transfer_request_detail', ['id' => $transferRequest->getId()]);
        }
        
        try {
            $this->brokerTransferService->rejectTransfer($transferRequest, $user, $notes);
            
            $this->addFlash('success', 'Transfer request rejected successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to reject transfer request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('sl_staff_transfer_requests');
    }

    #[Route('/pending/count', name: 'sl_staff_transfer_requests_count', methods: ['GET'])]
    public function pendingCount(): Response
    {
        $count = $this->brokerTransferService->countPendingRequests();
        
        return $this->json([
            'count' => $count
        ]);
    }
}
