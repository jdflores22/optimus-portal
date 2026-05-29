<?php

namespace App\Controller\Api;

use App\Service\BillingService;
use App\Service\ManifestAuthorizationService;
use App\Service\AuditService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\Enum\UserRole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_billing_')]
class BillingController extends BaseApiController
{
    public function __construct(
        private BillingService $billingService,
        private ManifestAuthorizationService $authorizationService,
        private AuditService $auditService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/manifests/{id}/billing', name: 'generate', methods: ['POST'])]
    public function generateBilling(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only ACCOUNTING can generate billing
        $roleCheck = $this->requireRole($user, [UserRole::ACCOUNTING->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        // Validate required fields
        $validation = $this->validateRequiredFields($data, ['freightCharges', 'thcCharges']);
        if ($validation) {
            return $validation;
        }

        // Validate freight charges
        $freightValidation = $this->validateNumeric($data['freightCharges'], 'freightCharges', 0);
        if ($freightValidation) {
            return $freightValidation;
        }

        // Validate THC charges
        $thcValidation = $this->validateNumeric($data['thcCharges'], 'thcCharges', 0);
        if ($thcValidation) {
            return $thcValidation;
        }

        // Validate additional charges if provided
        if (isset($data['additionalCharges'])) {
            if (!is_array($data['additionalCharges'])) {
                return $this->errorResponse('additionalCharges must be an array');
            }

            foreach ($data['additionalCharges'] as $index => $charge) {
                if (!isset($charge['description']) || !isset($charge['amount'])) {
                    return $this->errorResponse("additionalCharges[{$index}] must have description and amount");
                }

                $chargeValidation = $this->validateNumeric($charge['amount'], "additionalCharges[{$index}].amount", 0);
                if ($chargeValidation) {
                    return $chargeValidation;
                }
            }
        }

        try {
            $chargeData = [
                'freightCharges' => (float) $data['freightCharges'],
                'thcCharges' => (float) $data['thcCharges'],
                'additionalCharges' => $data['additionalCharges'] ?? null,
                'currency' => $data['currency'] ?? 'PHP',
                'exchangeRate' => isset($data['exchangeRate']) ? (float) $data['exchangeRate'] : null
            ];

            // If currency is USD, calculate USD amounts from PHP amounts
            if ($chargeData['currency'] === 'USD' && $chargeData['exchangeRate']) {
                // The user enters PHP amounts, so we need to convert TO USD
                $chargeData['freightChargesUsd'] = $chargeData['freightCharges'] / $chargeData['exchangeRate'];
                $chargeData['thcChargesUsd'] = $chargeData['thcCharges'] / $chargeData['exchangeRate'];
                
                // Calculate USD total for additional charges
                $additionalUsd = 0;
                if ($chargeData['additionalCharges']) {
                    foreach ($chargeData['additionalCharges'] as $charge) {
                        $additionalUsd += $charge['amount'] / $chargeData['exchangeRate'];
                    }
                }
                $chargeData['totalAmountUsd'] = $chargeData['freightChargesUsd'] + $chargeData['thcChargesUsd'] + $additionalUsd;
                
                // Keep the PHP amounts as entered (no conversion needed)
                // freightCharges and thcCharges remain as PHP values
            }

            $billing = $this->billingService->generateBilling($id, $chargeData, $user);

            $response = [
                'billingId' => $billing->getId(),
                'manifestId' => $billing->getManifest()->getId(),
                'freightCharges' => $billing->getFreightCharges(),
                'thcCharges' => $billing->getThcCharges(),
                'additionalCharges' => $billing->getAdditionalCharges(),
                'totalAmount' => $billing->getTotalAmount(),
                'pdfUrl' => $billing->getPdfPath(),
                'generatedAt' => $billing->getCreatedAt()->format('Y-m-d H:i:s'),
                'originalCurrency' => $billing->getOriginalCurrency()
            ];

            // Include USD amounts if available
            if ($billing->getOriginalCurrency() === 'USD') {
                $response['exchangeRate'] = $billing->getExchangeRate();
                $response['freightChargesUsd'] = $billing->getFreightChargesUsd();
                $response['thcChargesUsd'] = $billing->getThcChargesUsd();
                $response['totalAmountUsd'] = $billing->getTotalAmountUsd();
            }

            return $this->jsonResponse($response, 201);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            // Log the full error for debugging
            error_log('Billing generation error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return $this->errorResponse('Failed to generate billing: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/manifests/{id}/billing', name: 'get', methods: ['GET'])]
    public function getBillingDetails(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SL_STAFF and Broker (if authorized) can view billing
        $roleCheck = $this->requireRole($user, [
            UserRole::SL_STAFF->value,
            UserRole::BROKER->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $billing = $this->billingService->getBillingByManifest($id);
            
            if (!$billing) {
                return $this->errorResponse('Billing not found', 404);
            }

            // Check authorization for broker
            if ($user->getRole()->value === UserRole::BROKER->value) {
                $manifest = $billing->getManifest();
                if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                    return $this->errorResponse('Access denied', 403);
                }
            }

            return $this->jsonResponse([
                'billingId' => $billing->getId(),
                'manifestId' => $billing->getManifest()->getId(),
                'freightCharges' => $billing->getFreightCharges(),
                'thcCharges' => $billing->getThcCharges(),
                'additionalCharges' => $billing->getAdditionalCharges(),
                'totalAmount' => $billing->getTotalAmount(),
                'pdfUrl' => $billing->getPdfPath()
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve billing details: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/billing/{billingId}/download', name: 'download', methods: ['GET'])]
    public function downloadBilling(int $billingId, Request $request): JsonResponse|BinaryFileResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            // Find billing by ID
            $billing = $this->billingService->getBillingById($billingId);
            
            if (!$billing) {
                return $this->errorResponse('Billing not found', 404);
            }

            // Check authorization
            $manifest = $billing->getManifest();
            
            // SL_STAFF and ACCOUNTING can always download
            if (!in_array($user->getRole()->value, [UserRole::SL_STAFF->value, UserRole::ACCOUNTING->value])) {
                // Broker/Consignee must be authorized
                if (!$this->authorizationService->canViewManifest($manifest, $user)) {
                    return $this->errorResponse('Access denied', 403);
                }
            }

            // Get PDF path
            $pdfPath = $billing->getPdfPath();
            
            // If PDF doesn't exist or path is null, try to generate it
            if (!$pdfPath || !file_exists($pdfPath)) {
                try {
                    // Regenerate the billing PDF
                    $billing = $this->billingService->regenerateBillingPdf($billingId);
                    $pdfPath = $billing->getPdfPath();
                    
                    if (!$pdfPath || !file_exists($pdfPath)) {
                        return $this->errorResponse('Unable to generate billing PDF', 500);
                    }
                } catch (\Exception $e) {
                    return $this->errorResponse('Failed to generate billing PDF: ' . $e->getMessage(), 500);
                }
            }

            // Log document download
            $this->auditService->logDocumentDownload($user, 'Billing', $billing->getId());

            $response = new BinaryFileResponse($pdfPath);
            
            // Check if inline viewing is requested (for iframe)
            $inline = $request->query->get('inline', 'true');
            if ($inline === 'true') {
                $response->setContentDisposition(
                    ResponseHeaderBag::DISPOSITION_INLINE,
                    'Billing-' . $billing->getId() . '.pdf'
                );
                // Allow iframe embedding from same origin
                $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
                $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
            } else {
                $response->setContentDisposition(
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    'Billing-' . $billing->getId() . '.pdf'
                );
            }

            return $response;

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to download billing: ' . $e->getMessage(), 500);
        }
    }
}
