<?php

namespace App\Controller;

use App\Entity\EDOBilling;
use App\Entity\EDOPaymentReceipt;
use App\Entity\RegenerationRequest;
use App\Repository\EDOBillingRepository;
use App\Repository\EDOPaymentReceiptRepository;
use App\Repository\RegenerationRequestRepository;
use App\Service\EDOBillingServiceInterface;
use App\Service\EDOPaymentReceiptServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for Shipping Lines Accounting eDO operations
 * 
 * Requirements: 7.1-7.8, 8.1-8.6, 9.1-9.8, 12.3, 12.4
 */
#[Route('/accounting/edo')]
#[IsGranted('ROLE_ACCOUNTING')]
class AccountingEDOController extends AbstractController
{
    public function __construct(
        private EDOBillingServiceInterface $billingService,
        private EDOPaymentReceiptServiceInterface $paymentService,
        private RegenerationRequestRepository $regenerationRequestRepository,
        private EDOBillingRepository $billingRepository,
        private EDOPaymentReceiptRepository $paymentReceiptRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * List all pending regeneration requests that need billing
     */
    #[Route('/regeneration-requests', name: 'accounting_edo_regeneration_requests', methods: ['GET'])]
    public function listRegenerationRequests(): Response
    {
        $pendingRequests = $this->regenerationRequestRepository->findByStatus(
            \App\Entity\Enum\RequestStatus::ROUTED_TO_ACCOUNTING
        );

        return $this->render('accounting/edo/regeneration_requests.html.twig', [
            'requests' => $pendingRequests
        ]);
    }

    /**
     * Generate billing for a regeneration request
     */
    #[Route('/regeneration-requests/{id}/generate-billing', name: 'accounting_edo_generate_billing', methods: ['POST'])]
    public function generateBilling(int $id): Response
    {
        // Requirement 12.3: Restrict billing generation to Shipping_Lines_Accounting
        $this->denyAccessUnlessGranted('generate', 'Billing');

        try {
            $regenerationRequest = $this->regenerationRequestRepository->find($id);
            
            if (!$regenerationRequest) {
                $this->addFlash('error', 'Regeneration request not found');
                return $this->redirectToRoute('accounting_edo_regeneration_requests');
            }

            // Check if billing already exists
            if ($regenerationRequest->getBilling() !== null) {
                $this->addFlash('error', 'Billing already generated for this request');
                return $this->redirectToRoute('accounting_edo_regeneration_requests');
            }

            // Calculate billing
            $billing = $this->billingService->calculateBilling($regenerationRequest, $this->getUser());

            // Generate billing document
            $this->billingService->generateBillingDocument($billing);

            // Send billing to parties
            $this->billingService->sendBillingToParties($billing);

            // Update regeneration request status
            $regenerationRequest->setStatus(\App\Entity\Enum\RequestStatus::BILLING_GENERATED);
            $this->entityManager->flush();

            $this->addFlash('success', 'Billing generated and sent successfully');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate billing', [
                'requestId' => $id,
                'error' => $e->getMessage()
            ]);
            $this->addFlash('error', 'Failed to generate billing: ' . $e->getMessage());
        }

        return $this->redirectToRoute('accounting_edo_regeneration_requests');
    }

    /**
     * List all pending payment receipts
     */
    #[Route('/payment-receipts', name: 'accounting_edo_payment_receipts', methods: ['GET'])]
    public function listPaymentReceipts(): Response
    {
        $pendingPayments = $this->paymentReceiptRepository->findByStatus(
            \App\Entity\Enum\EDOPaymentReceiptStatus::SUBMITTED
        );

        return $this->render('accounting/edo/payment_receipts.html.twig', [
            'payments' => $pendingPayments
        ]);
    }

    /**
     * View payment receipt details
     */
    #[Route('/payment-receipts/{id}', name: 'accounting_edo_payment_receipt_detail', methods: ['GET'])]
    public function viewPaymentReceipt(int $id): Response
    {
        $payment = $this->paymentReceiptRepository->find($id);
        
        if (!$payment) {
            $this->addFlash('error', 'Payment receipt not found');
            return $this->redirectToRoute('accounting_edo_payment_receipts');
        }

        return $this->render('accounting/edo/payment_receipt_detail.html.twig', [
            'payment' => $payment,
            'billing' => $payment->getBilling(),
            'edo' => $payment->getBilling()->getRegenerationRequest()->getEdo()
        ]);
    }

    /**
     * Confirm payment and trigger eDO regeneration
     */
    #[Route('/payment-receipts/{id}/confirm', name: 'accounting_edo_confirm_payment', methods: ['POST'])]
    public function confirmPayment(int $id): Response
    {
        // Requirement 12.4: Restrict payment confirmation to Shipping_Lines_Accounting
        $payment = $this->paymentReceiptRepository->find($id);
        if ($payment) {
            $this->denyAccessUnlessGranted('confirm', $payment);
        }

        try {
            $payment = $this->paymentReceiptRepository->find($id);
            
            if (!$payment) {
                $this->addFlash('error', 'Payment receipt not found');
                return $this->redirectToRoute('accounting_edo_payment_receipts');
            }

            // Confirm payment and regenerate eDO
            $newEdo = $this->paymentService->confirmPayment($payment, $this->getUser());

            $this->addFlash('success', sprintf(
                'Payment confirmed. New eDO %s has been generated.',
                $newEdo->getEdoNumber()
            ));
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to confirm payment', [
                'paymentId' => $id,
                'error' => $e->getMessage()
            ]);
            $this->addFlash('error', 'Failed to confirm payment: ' . $e->getMessage());
        }

        return $this->redirectToRoute('accounting_edo_payment_receipts');
    }

    /**
     * Reject payment with reason
     */
    #[Route('/payment-receipts/{id}/reject', name: 'accounting_edo_reject_payment', methods: ['POST'])]
    public function rejectPayment(int $id, Request $request): Response
    {
        // Requirement 12.4: Restrict payment rejection to Shipping_Lines_Accounting
        $payment = $this->paymentReceiptRepository->find($id);
        if ($payment) {
            $this->denyAccessUnlessGranted('reject', $payment);
        }

        try {
            $payment = $this->paymentReceiptRepository->find($id);
            
            if (!$payment) {
                $this->addFlash('error', 'Payment receipt not found');
                return $this->redirectToRoute('accounting_edo_payment_receipts');
            }

            $reason = $request->request->get('rejection_reason');
            
            if (empty($reason)) {
                $this->addFlash('error', 'Rejection reason is required');
                return $this->redirectToRoute('accounting_edo_payment_receipt_detail', ['id' => $id]);
            }

            // Reject payment
            $this->paymentService->rejectPayment($payment, $this->getUser(), $reason);

            $this->addFlash('success', 'Payment rejected successfully');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to reject payment', [
                'paymentId' => $id,
                'error' => $e->getMessage()
            ]);
            $this->addFlash('error', 'Failed to reject payment: ' . $e->getMessage());
        }

        return $this->redirectToRoute('accounting_edo_payment_receipts');
    }

    /**
     * API endpoint to submit payment receipt (for Consignee/Broker)
     */
    #[Route('/api/payment-receipts/submit', name: 'api_accounting_edo_submit_payment', methods: ['POST'])]
    #[IsGranted('ROLE_BROKER')]
    public function submitPaymentReceipt(Request $request): JsonResponse
    {
        try {
            $billingId = $request->request->get('billing_id');
            $receiptFile = $request->files->get('receipt_file');

            if (!$billingId || !$receiptFile) {
                return $this->json([
                    'success' => false,
                    'message' => 'Billing ID and receipt file are required'
                ], Response::HTTP_BAD_REQUEST);
            }

            $billing = $this->billingRepository->find($billingId);
            
            if (!$billing) {
                return $this->json([
                    'success' => false,
                    'message' => 'Billing not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Submit payment receipt
            $payment = $this->paymentService->submitPaymentReceipt(
                $billing,
                $receiptFile,
                $this->getUser()
            );

            return $this->json([
                'success' => true,
                'message' => 'Payment receipt submitted successfully',
                'payment_id' => $payment->getId()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to submit payment receipt', [
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
