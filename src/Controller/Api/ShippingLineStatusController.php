<?php

namespace App\Controller\Api;

use App\Service\ShippingLineAccessControlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API Controller for checking shipping line status
 */
class ShippingLineStatusController extends AbstractController
{
    public function __construct(
        private ShippingLineAccessControlService $shippingLineAccessControl
    ) {
    }

    /**
     * Check if the current user's shipping line is still active
     */
    #[Route('/check-shipping-line-status', name: 'check_shipping_line_status', methods: ['GET'])]
    public function checkShippingLineStatus(): JsonResponse
    {
        try {
            $user = $this->getUser();
            
            if (!$user) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'User not authenticated',
                    'debug' => 'No user found in session'
                ], 401);
            }

            // Add debug information
            $debugInfo = [
                'user_id' => $user->getId(),
                'user_role' => $user->getRole()->value,
                'user_email' => $user->getEmail()
            ];

            // Check if user has access
            $hasAccess = $this->shippingLineAccessControl->hasAccess($user);
            
            if ($hasAccess) {
                return new JsonResponse([
                    'success' => true,
                    'active' => true,
                    'message' => 'Shipping line is active',
                    'debug' => $debugInfo
                ]);
            }

            // Get shipping line information for deactivated case
            $shippingLine = $this->shippingLineAccessControl->getUserShippingLine($user);
            $shippingLineName = $shippingLine ? $shippingLine->getBrandName() : null;
            $reason = $this->shippingLineAccessControl->getAccessDenialReason($user);

            return new JsonResponse([
                'success' => false,
                'active' => false,
                'deactivated' => true,
                'shippingLineName' => $shippingLineName,
                'reason' => $reason,
                'message' => 'Shipping line has been deactivated',
                'debug' => $debugInfo
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error',
                'debug' => $e->getMessage()
            ], 500);
        }
    }
}