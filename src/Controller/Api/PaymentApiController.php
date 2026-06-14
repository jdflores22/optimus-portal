<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legacy shipment payment API — retired in favor of manifest final payment endpoints.
 *
 * @see PaymentController (Api) POST /api/manifests/{id}/payments/final
 */
#[Route('/api/payments', name: 'api_payments_legacy_')]
class PaymentApiController extends BaseApiController
{
    private const DEPRECATED_MESSAGE = 'Legacy shipment payment API is no longer supported. Use POST /api/manifests/{id}/payments/final for manifest final payments.';

    public function __construct(
        \App\Service\JwtService $jwtService,
        \App\Service\UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/status/{id}', name: 'status', methods: ['GET'])]
    public function getPaymentStatus(int $id, Request $request): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/submit', name: 'submit', methods: ['POST'])]
    public function submitPaymentProof(Request $request): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/verify/{id}', name: 'verify', methods: ['POST'])]
    public function verifyPayment(int $id, Request $request): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/pending', name: 'pending', methods: ['GET'])]
    public function getPendingPayments(Request $request): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    private function deprecatedResponse(): JsonResponse
    {
        return $this->json([
            'success' => false,
            'error' => self::DEPRECATED_MESSAGE,
            'migration' => [
                'submit' => 'POST /api/manifests/{manifestId}/payments/final',
                'validate' => 'POST /api/payments/{id}/validate-final',
            ],
        ], 410);
    }
}
