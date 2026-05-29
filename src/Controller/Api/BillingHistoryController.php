<?php

namespace App\Controller\Api;

use App\Entity\Billing;
use App\Entity\EDORenewalRequest;
use App\Repository\BillingRepository;
use App\Repository\EDORenewalRequestRepository;
use App\Service\BillingHistoryServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API Controller for Billing History
 * Provides endpoints to retrieve billing version history, chains, and statistics
 */
#[Route('/api/billing-history', name: 'api_billing_history_')]
#[IsGranted('ROLE_USER')]
class BillingHistoryController extends BaseApiController
{
    public function __construct(
        protected \App\Service\JwtService $jwtService,
        protected \App\Service\UserService $userService,
        private BillingHistoryServiceInterface $billingHistoryService,
        private BillingRepository $billingRepository,
        private EDORenewalRequestRepository $renewalRequestRepository
    ) {
        parent::__construct($jwtService, $userService);
    }

    /**
     * Get complete billing history for a renewal request
     * 
     * @param int $id Renewal Request ID
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/renewal-requests/{id}/billings/history', name: 'renewal_request_history', methods: ['GET'])]
    public function getBillingHistory(int $id, Request $request): JsonResponse
    {
        // Authenticate user
        $user = $this->authenticateRequest($request);
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Get renewal request
        $renewalRequest = $this->renewalRequestRepository->find($id);
        if (!$renewalRequest) {
            return $this->errorResponse('Renewal request not found', 404);
        }

        // Authorization check: accounting can view all, brokers can only view their own
        if (!in_array($user->getRole()->value, ['ACCOUNTING', 'SYSTEM_ADMIN'])) {
            if ($renewalRequest->getRequestedBy()->getId() !== $user->getId()) {
                return $this->errorResponse('Access denied', 403);
            }
        }

        try {
            // Get billing history
            $history = $this->billingHistoryService->getBillingHistory($renewalRequest);
            
            // Get statistics
            $statistics = $this->billingHistoryService->getBillingStatistics($renewalRequest);

            return $this->json([
                'success' => true,
                'renewal_request_id' => $renewalRequest->getId(),
                'expired_edo_number' => $renewalRequest->getExpiredEdo()->getEdoNumber(),
                'billings' => array_map(fn($b) => $this->serializeBilling($b), $history),
                'statistics' => $this->serializeStatistics($statistics)
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve billing history: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get billing chain starting from a specific billing
     * 
     * @param int $id Billing ID
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/billings/{id}/chain', name: 'billing_chain', methods: ['GET'])]
    public function getBillingChain(int $id, Request $request): JsonResponse
    {
        // Authenticate user
        $user = $this->authenticateRequest($request);
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Get billing
        $billing = $this->billingRepository->find($id);
        if (!$billing) {
            return $this->errorResponse('Billing not found', 404);
        }

        // Authorization check
        if (!in_array($user->getRole()->value, ['ACCOUNTING', 'SYSTEM_ADMIN'])) {
            $renewalRequest = $billing->getEdoRenewalRequest();
            if (!$renewalRequest || $renewalRequest->getRequestedBy()->getId() !== $user->getId()) {
                return $this->errorResponse('Access denied', 403);
            }
        }

        try {
            // Get billing chain
            $chain = $this->billingHistoryService->getBillingChain($billing);

            return $this->json([
                'success' => true,
                'billing_id' => $billing->getId(),
                'renewal_request_id' => $billing->getEdoRenewalRequest()?->getId(),
                'chain' => array_map(fn($b) => $this->serializeBilling($b), $chain),
                'chain_length' => count($chain)
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve billing chain: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get previous billing version
     * 
     * @param int $id Billing ID
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/billings/{id}/previous', name: 'billing_previous', methods: ['GET'])]
    public function getPreviousBilling(int $id, Request $request): JsonResponse
    {
        // Authenticate user
        $user = $this->authenticateRequest($request);
        if (!$user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Get billing
        $billing = $this->billingRepository->find($id);
        if (!$billing) {
            return $this->errorResponse('Billing not found', 404);
        }

        // Authorization check
        if (!in_array($user->getRole()->value, ['ACCOUNTING', 'SYSTEM_ADMIN'])) {
            $renewalRequest = $billing->getEdoRenewalRequest();
            if (!$renewalRequest || $renewalRequest->getRequestedBy()->getId() !== $user->getId()) {
                return $this->errorResponse('Access denied', 403);
            }
        }

        // Get previous billing
        $previousBilling = $billing->getPreviousBilling();
        
        if (!$previousBilling) {
            return $this->json([
                'success' => true,
                'billing_id' => $billing->getId(),
                'has_previous' => false,
                'previous_billing' => null,
                'message' => 'This is the initial version (v1)'
            ]);
        }

        return $this->json([
            'success' => true,
            'billing_id' => $billing->getId(),
            'has_previous' => true,
            'previous_billing' => $this->serializeBilling($previousBilling)
        ]);
    }

    /**
     * Serialize billing entity to array
     * 
     * @param Billing $billing
     * @return array
     */
    private function serializeBilling(Billing $billing): array
    {
        return [
            'id' => $billing->getId(),
            'version' => $billing->getVersion(),
            'billing_type' => $billing->getBillingType(),
            'total_amount' => $billing->getTotalAmount(),
            'original_currency' => $billing->getOriginalCurrency(),
            'detention_days' => $billing->getDetentionDays(),
            'detention_rate' => $billing->getDetentionRate(),
            'generated_by' => [
                'id' => $billing->getGeneratedBy()->getId(),
                'name' => $billing->getGeneratedBy()->getFullName(),
                'email' => $billing->getGeneratedBy()->getEmail()
            ],
            'created_at' => $billing->getCreatedAt()->format('Y-m-d H:i:s'),
            'payment_submitted_by' => $billing->getPaymentSubmittedBy() ? [
                'id' => $billing->getPaymentSubmittedBy()->getId(),
                'name' => $billing->getPaymentSubmittedBy()->getFullName(),
                'email' => $billing->getPaymentSubmittedBy()->getEmail()
            ] : null,
            'payment_submitted_at' => $billing->getPaymentSubmittedAt()?->format('Y-m-d H:i:s'),
            'receipt_file_path' => $billing->getReceiptFilePath(),
            'pdf_path' => $billing->getPdfPath(),
            'previous_billing_id' => $billing->getPreviousBilling()?->getId(),
            'is_initial_version' => $billing->isInitialVersion(),
            'is_resubmission' => $billing->isResubmission()
        ];
    }

    /**
     * Serialize billing statistics to array
     * 
     * @param array $statistics
     * @return array
     */
    private function serializeStatistics(array $statistics): array
    {
        return [
            'total_versions' => $statistics['total_versions'],
            'current_version' => $statistics['current_version'],
            'first_submission' => $statistics['first_submission']?->format('Y-m-d H:i:s'),
            'last_submission' => $statistics['last_submission']?->format('Y-m-d H:i:s')
        ];
    }
}
