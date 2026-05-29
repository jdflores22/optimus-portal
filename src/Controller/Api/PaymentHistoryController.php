<?php

namespace App\Controller\Api;

use App\Entity\Payment;
use App\Entity\Manifest;
use App\Repository\PaymentRepository;
use App\Repository\ManifestRepository;
use App\Service\PaymentHistoryServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API Controller for Payment History
 * Provides endpoints to retrieve payment version history, chains, and statistics
 */
#[Route('/api/payment-history', name: 'api_payment_history_')]
#[IsGranted('ROLE_USER')]
class PaymentHistoryController extends BaseApiController
{
    public function __construct(
        protected \App\Service\JwtService $jwtService,
        protected \App\Service\UserService $userService,
        private PaymentHistoryServiceInterface $paymentHistoryService,
        private PaymentRepository $paymentRepository,
        private ManifestRepository $manifestRepository
    ) {
        parent::__construct($jwtService, $userService);
    }

    /**
     * Get complete payment history for a manifest
     * 
     * @param int $id Manifest ID
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/manifests/{id}/payments/history', name: 'manifest_history', methods: ['GET'])]
    public function getPaymentHistory(int $id, Request $request): JsonResponse
    {
        // Authenticate user
        $user = $this->authenticateRequest($request);
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Get manifest
        $manifest = $this->manifestRepository->find($id);
        if (!$manifest) {
            return $this->errorResponse('Manifest not found', 404);
        }

        // Authorization check: brokers can only view their own manifests, accounting sees all
        $this->denyAccessUnlessGranted('view_payment_history', $manifest);

        // Get payment type from query parameter (default: final_payment)
        $paymentType = $request->query->get('type', 'final_payment');

        // Validate payment type
        if (!in_array($paymentType, ['final_payment', 'edo_payment'])) {
            return $this->errorResponse('Invalid payment type. Must be "final_payment" or "edo_payment"', 400);
        }

        try {
            // Get payment history
            $history = $this->paymentHistoryService->getPaymentHistory($manifest, $paymentType);
            
            // Get statistics
            $statistics = $this->paymentHistoryService->getPaymentStatistics($manifest, $paymentType);

            return $this->json([
                'success' => true,
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'payment_type' => $paymentType,
                'payments' => array_map(fn($p) => $this->serializePayment($p), $history),
                'statistics' => $this->serializeStatistics($statistics)
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve payment history: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get payment chain starting from a specific payment
     * 
     * @param int $id Payment ID
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/payments/{id}/chain', name: 'payment_chain', methods: ['GET'])]
    public function getPaymentChain(int $id, Request $request): JsonResponse
    {
        // Authenticate user
        $user = $this->authenticateRequest($request);
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Get payment
        $payment = $this->paymentRepository->find($id);
        if (!$payment) {
            return $this->errorResponse('Payment not found', 404);
        }

        // Authorization check
        $this->denyAccessUnlessGranted('view', $payment);

        try {
            // Get payment chain
            $chain = $this->paymentHistoryService->getPaymentChain($payment);

            return $this->json([
                'success' => true,
                'payment_id' => $payment->getId(),
                'manifest_id' => $payment->getManifest()->getId(),
                'manifest_number' => $payment->getManifest()->getManifestNumber(),
                'chain' => array_map(fn($p) => $this->serializePayment($p), $chain),
                'chain_length' => count($chain)
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve payment chain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get previous payment version
     * 
     * @param int $id Payment ID
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/payments/{id}/previous', name: 'payment_previous', methods: ['GET'])]
    public function getPreviousPayment(int $id, Request $request): JsonResponse
    {
        // Authenticate user
        $user = $this->authenticateRequest($request);
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Get payment
        $payment = $this->paymentRepository->find($id);
        if (!$payment) {
            return $this->errorResponse('Payment not found', 404);
        }

        // Authorization check
        $this->denyAccessUnlessGranted('view', $payment);

        // Get previous payment
        $previousPayment = $payment->getPreviousPayment();
        
        if (!$previousPayment) {
            return $this->json([
                'success' => true,
                'payment_id' => $payment->getId(),
                'has_previous' => false,
                'previous_payment' => null,
                'message' => 'This is the initial version (v1)'
            ]);
        }

        return $this->json([
            'success' => true,
            'payment_id' => $payment->getId(),
            'has_previous' => true,
            'previous_payment' => $this->serializePayment($previousPayment)
        ]);
    }

    /**
     * Serialize payment entity to array
     * 
     * @param Payment $payment
     * @return array
     */
    private function serializePayment(Payment $payment): array
    {
        return [
            'id' => $payment->getId(),
            'version' => $payment->getVersion(),
            'amount' => $payment->getAmount(),
            'status' => $payment->getStatus()->value,
            'payment_type' => $payment->getPaymentType()->value,
            'submitted_by' => [
                'id' => $payment->getSubmittedBy()->getId(),
                'name' => $payment->getSubmittedBy()->getFullName(),
                'email' => $payment->getSubmittedBy()->getEmail()
            ],
            'submitted_at' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
            'validated_by' => $payment->getValidatedBy() ? [
                'id' => $payment->getValidatedBy()->getId(),
                'name' => $payment->getValidatedBy()->getFullName(),
                'email' => $payment->getValidatedBy()->getEmail()
            ] : null,
            'validated_at' => $payment->getValidatedAt()?->format('Y-m-d H:i:s'),
            'rejection_reason' => $payment->getRejectionReason(),
            'receipt_file_path' => $payment->getReceiptFilePath(),
            'official_receipt_path' => $payment->getOfficialReceiptPath(),
            'previous_payment_id' => $payment->getPreviousPayment()?->getId(),
            'is_initial_version' => $payment->isInitialVersion(),
            'is_resubmission' => $payment->isResubmission()
        ];
    }

    /**
     * Serialize payment statistics to array
     * 
     * @param array $statistics
     * @return array
     */
    private function serializeStatistics(array $statistics): array
    {
        return [
            'total_versions' => $statistics['total_versions'],
            'total_rejections' => $statistics['total_rejections'],
            'current_version' => $statistics['current_version'],
            'first_submission' => $statistics['first_submission']?->format('Y-m-d H:i:s'),
            'last_submission' => $statistics['last_submission']?->format('Y-m-d H:i:s')
        ];
    }
}
