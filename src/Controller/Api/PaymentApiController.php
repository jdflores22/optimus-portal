<?php

namespace App\Controller\Api;

use App\Service\PaymentService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\Enum\UserRole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/payments', name: 'api_payments_')]
class PaymentApiController extends BaseApiController
{
    public function __construct(
        private PaymentService $paymentService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }

    #[Route('/status/{id}', name: 'status', methods: ['GET'])]
    public function getPaymentStatus(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $payment = $this->paymentService->getPaymentById($id);
            
            if (!$payment) {
                return $this->errorResponse('Payment not found', 404);
            }

            // Check if user can access this payment
            $canAccess = false;
            
            // Broker who submitted the payment
            if ($payment->getBroker()->getId() === $user->getId()) {
                $canAccess = true;
            }
            
            // Staff members can access all payments
            if (in_array($user->getRole()->value, [
                UserRole::ACCOUNTING->value,
                UserRole::SL_STAFF->value,
                UserRole::SHIPPING_LINES_ADMIN->value,
                UserRole::SYSTEM_ADMIN->value
            ])) {
                $canAccess = true;
            }

            if (!$canAccess) {
                return $this->errorResponse('Access denied', 403);
            }

            return $this->jsonResponse([
                'id' => $payment->getId(),
                'status' => $payment->getStatus()->value,
                'verified_at' => $payment->getVerifiedAt()?->format('Y-m-d H:i:s'),
                'verified_by' => $payment->getVerifiedBy() ? [
                    'id' => $payment->getVerifiedBy()->getId(),
                    'email' => $payment->getVerifiedBy()->getEmail()
                ] : null,
                'shipment' => [
                    'id' => $payment->getShipment()->getId(),
                    'manifest_number' => $payment->getShipment()->getManifestNumber()
                ],
                'broker' => [
                    'id' => $payment->getBroker()->getId(),
                    'email' => $payment->getBroker()->getEmail(),
                    'business_name' => $payment->getBroker()->getFullName()
                ],
                'edo' => $payment->getEdo() ? [
                    'edo_number' => $payment->getEdo()->getEdoNumber(),
                    'generated_at' => $payment->getEdo()->getGeneratedAt()->format('Y-m-d H:i:s'),
                    'pdf_available' => !empty($payment->getEdo()->getPdfPath())
                ] : null
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve payment status', 500);
        }
    }

    #[Route('/submit', name: 'submit', methods: ['POST'])]
    public function submitPaymentProof(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only brokers can submit payment proof
        $roleCheck = $this->requireRole($user, [UserRole::BROKER->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        if (!$data || !isset($data['shipment_id'])) {
            return $this->errorResponse('Shipment ID is required');
        }

        try {
            $payment = $this->paymentService->submitPaymentProof(
                $data['shipment_id'],
                $user,
                $data['proof_file_path'] ?? null
            );

            return $this->jsonResponse([
                'success' => true,
                'payment_id' => $payment->getId(),
                'status' => $payment->getStatus()->value,
                'shipment_id' => $payment->getShipment()->getId()
            ], 201);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to submit payment proof: ' . $e->getMessage(), 400);
        }
    }

    #[Route('/verify/{id}', name: 'verify', methods: ['POST'])]
    public function verifyPayment(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only accounting staff can verify payments
        $roleCheck = $this->requireRole($user, [
            UserRole::ACCOUNTING->value,
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::SYSTEM_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        $approve = $data['approve'] ?? true;

        try {
            if ($approve) {
                $payment = $this->paymentService->verifyPayment($id, $user);
                $edo = $this->paymentService->generateEDO($payment);
                
                return $this->jsonResponse([
                    'success' => true,
                    'payment_id' => $payment->getId(),
                    'status' => $payment->getStatus()->value,
                    'edo' => [
                        'edo_number' => $edo->getEdoNumber(),
                        'generated_at' => $edo->getGeneratedAt()->format('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                $payment = $this->paymentService->rejectPayment($id, $user);
                
                return $this->jsonResponse([
                    'success' => true,
                    'payment_id' => $payment->getId(),
                    'status' => $payment->getStatus()->value
                ]);
            }

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to verify payment: ' . $e->getMessage(), 400);
        }
    }

    #[Route('/pending', name: 'pending', methods: ['GET'])]
    public function getPendingPayments(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only accounting staff can view pending payments
        $roleCheck = $this->requireRole($user, [
            UserRole::ACCOUNTING->value,
            UserRole::SHIPPING_LINES_ADMIN->value,
            UserRole::SYSTEM_ADMIN->value
        ]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $payments = $this->paymentService->getPendingPayments();

            $result = array_map(function($payment) {
                return [
                    'id' => $payment->getId(),
                    'status' => $payment->getStatus()->value,
                    'shipment' => [
                        'id' => $payment->getShipment()->getId(),
                        'manifest_number' => $payment->getShipment()->getManifestNumber()
                    ],
                    'broker' => [
                        'id' => $payment->getBroker()->getId(),
                        'email' => $payment->getBroker()->getEmail(),
                        'business_name' => $payment->getBroker()->getFullName()
                    ],
                    'submitted_at' => $payment->getShipment()->getCreatedAt()->format('Y-m-d H:i:s')
                ];
            }, $payments);

            return $this->jsonResponse([
                'payments' => $result,
                'total' => count($result)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve pending payments', 500);
        }
    }
}