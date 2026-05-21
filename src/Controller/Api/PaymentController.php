<?php

namespace App\Controller\Api;

use App\Service\PaymentService;
use App\Service\EDOPaymentServiceInterface;
use App\Service\ManifestAuthorizationService;
use App\Service\EDOService;
use App\Service\JwtService;
use App\Service\UserService;
use App\Service\FileStorageServiceInterface;
use App\Service\AuditService;
use App\Entity\Enum\UserRole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[Route('/api', name: 'api_payments_')]
class PaymentController extends BaseApiController
{
    public function __construct(
        private PaymentService $paymentService,
        private EDOPaymentServiceInterface $edoPaymentService,
        private ManifestAuthorizationService $authorizationService,
        private EDOService $edoService,
        private RateLimiterFactory $paymentSubmissionLimiter,
        private FileStorageServiceInterface $fileStorage,
        private AuditService $auditService,
        JwtService $jwtService,
        UserService $userService
    ) {
        parent::__construct($jwtService, $userService);
    }



    #[Route('/manifests/{id}/payments/final', name: 'submit_final', methods: ['POST'])]
    public function submitFinalPayment(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Apply rate limiting
        $limiter = $this->paymentSubmissionLimiter->create($user->getId());
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->errorResponse('Too many payment submission requests. Please try again later.', 429);
        }

        // Only Broker can submit final payment
        $roleCheck = $this->requireRole($user, [UserRole::BROKER->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $receipt = $request->files->get('receipt');
            $amount = $request->request->get('amount');

            // Validate file upload
            $fileValidation = $this->validateFileUpload(
                $receipt,
                ['application/pdf', 'image/jpeg', 'image/png'],
                5242880 // 5MB
            );
            if ($fileValidation) {
                return $fileValidation;
            }

            // Validate amount
            if (!$amount) {
                return $this->errorResponse('Amount is required');
            }

            $numValidation = $this->validateNumeric($amount, 'amount', 0.01);
            if ($numValidation) {
                return $numValidation;
            }

            $payment = $this->paymentService->submitFinalPayment($id, (float) $amount, $receipt, $user);

            return $this->jsonResponse([
                'paymentId' => $payment->getId(),
                'manifestId' => $payment->getManifest()->getId(),
                'paymentType' => $payment->getPaymentType()->value,
                'amount' => $payment->getAmount(),
                'status' => $payment->getStatus()->value
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to submit payment: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/payments/final/pending', name: 'pending_final', methods: ['GET'])]
    public function getPendingFinalPayments(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only ACCOUNTING can view pending final payments
        $roleCheck = $this->requireRole($user, [UserRole::ACCOUNTING->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $payments = $this->paymentService->getPendingFinalPayments();

            $result = array_map(function($payment) {
                $billing = $payment->getManifest()->getBilling();
                return [
                    'id' => $payment->getId(),
                    'manifestNumber' => $payment->getManifest()->getManifestNumber(),
                    'billingAmount' => $billing ? $billing->getTotalAmount() : 0,
                    'submittedAmount' => $payment->getAmount(),
                    'submittedBy' => $payment->getSubmittedBy()->getFullName(),
                    'submittedAt' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                    'receiptUrl' => $payment->getReceiptFilePath(),
                    'billingUrl' => $billing ? $billing->getPdfPath() : null
                ];
            }, $payments);

            return $this->jsonResponse(['payments' => $result]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve pending payments: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/payments/{id}/validate-final', name: 'validate_final', methods: ['POST'])]
    public function validateFinalPayment(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only ACCOUNTING can validate final payments
        $roleCheck = $this->requireRole($user, [UserRole::ACCOUNTING->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['approved'])) {
            return $this->errorResponse('Approved status is required');
        }

        $approved = (bool) $data['approved'];
        $reason = $data['reason'] ?? null;

        if (!$approved && !$reason) {
            return $this->errorResponse('Rejection reason is required when rejecting payment');
        }

        try {
            // Get the payment before validation
            $payment = $this->paymentService->getPaymentById($id);
            
            if (!$payment) {
                return $this->errorResponse('Payment not found', 404);
            }

            // Validate the payment (generates official receipt if approved, NOT eDO)
            $this->paymentService->validateFinalPayment($id, $approved, $reason, $user);

            // Get fresh payment data after validation
            $payment = $this->paymentService->getPaymentById($id);
            $manifest = $payment->getManifest();

            $response = [
                'success' => true,
                'paymentId' => $id,
                'status' => $approved ? 'verified' : 'rejected',
                'validatedBy' => $user->getFullName(),
                'validatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                'manifestState' => $manifest->getWorkflowState()->value,
            ];

            // If approved, include official receipt information
            if ($approved) {
                $response['officialReceiptGenerated'] = $payment->getOfficialReceiptPath() !== null;
                $response['officialReceiptPath'] = $payment->getOfficialReceiptPath();
                $response['message'] = 'Payment approved successfully. Official receipt has been generated.';
                $response['nextStep'] = 'SL_STAFF will now generate the eDO with expiration date.';
            } else {
                $response['message'] = 'Payment rejected. Reason: ' . $reason;
                $response['rejectionReason'] = $reason;
            }

            return $this->jsonResponse($response);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to validate payment: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/payments/{id}/receipt/download', name: 'download_receipt', methods: ['GET'])]
    public function downloadReceipt(int $id, Request $request): JsonResponse|BinaryFileResponse
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

            // Authorization check - only validators and submitter can download
            $userRole = $user->getRole()->value;
            $isSubmitter = $payment->getSubmittedBy()->getId() === $user->getId();
            $isValidator = in_array($userRole, [UserRole::SYSTEM_ADMIN->value, UserRole::ACCOUNTING->value, UserRole::SL_STAFF->value]);

            if (!$isSubmitter && !$isValidator) {
                return $this->errorResponse('Access denied', 403);
            }

            $filePath = $payment->getReceiptFilePath();
            if (!$filePath) {
                return $this->errorResponse('Receipt file path not set for this payment', 404);
            }
            
            // Fix: Remove /uploads/ prefix if it exists (legacy data issue)
            // The LocalStorageAdapter already adds /public/uploads/ as the root
            $filePath = ltrim($filePath, '/');
            if (str_starts_with($filePath, 'uploads/')) {
                $filePath = substr($filePath, 8); // Remove 'uploads/' prefix
            }
            
            if (!$this->fileStorage->fileExists($filePath)) {
                return $this->errorResponse('Receipt file not found at path: ' . $filePath, 404);
            }

            // Log download
            $this->auditService->logDocumentDownload($user, 'PaymentReceipt', $payment->getId());

            // Check if inline viewing is requested (for iframe)
            $inline = $request->query->get('inline', 'false') === 'true';

            // Serve file
            $fullPath = $this->fileStorage->getFullPath($filePath);
            
            if (!file_exists($fullPath)) {
                return $this->errorResponse('Physical file not found at: ' . $fullPath, 404);
            }
            
            $response = new BinaryFileResponse($fullPath);
            
            // Set proper Content-Type based on file extension
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $contentType = match($extension) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream'
            };

            $response->headers->set('Content-Type', $contentType);
            
            $filename = 'receipt-' . $payment->getId() . '.' . $extension;
            
            // Set Content-Disposition based on inline parameter
            if ($inline) {
                $response->setContentDisposition(
                    ResponseHeaderBag::DISPOSITION_INLINE,
                    $filename
                );
                // Allow iframe embedding from same origin
                $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
                $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
            } else {
                $response->setContentDisposition(
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    $filename
                );
            }

            return $response;

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to download receipt: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/manifests/{id}/payments/edo-access', name: 'submit_edo_access', methods: ['POST'])]
    public function submitEDOAccessPayment(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Apply rate limiting
        $limiter = $this->paymentSubmissionLimiter->create($user->getId());
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->errorResponse('Too many payment submission requests. Please try again later.', 429);
        }

        // Only Broker can submit EDO access payment
        $roleCheck = $this->requireRole($user, [UserRole::BROKER->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $receipt = $request->files->get('receipt');

            // Validate file upload
            $fileValidation = $this->validateFileUpload(
                $receipt,
                ['application/pdf', 'image/jpeg', 'image/png'],
                5242880 // 5MB
            );
            if ($fileValidation) {
                return $fileValidation;
            }

            $edoPayment = $this->edoPaymentService->submitEDOAccessPayment($id, $receipt, $user);

            return $this->jsonResponse([
                'paymentId' => $edoPayment->getId(),
                'manifestId' => $edoPayment->getManifest()->getId(),
                'amount' => $edoPayment->getAmount(),
                'status' => $edoPayment->getStatus()->value
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to submit payment: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/payments/edo-access/pending', name: 'pending_edo_access', methods: ['GET'])]
    public function getPendingEDOAccessPayments(Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SYSTEM_ADMIN can view pending EDO access payments
        $roleCheck = $this->requireRole($user, [UserRole::SYSTEM_ADMIN->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        try {
            $edoPayments = $this->edoPaymentService->getPendingEDOAccessPayments();

            $result = array_map(function($edoPayment) {
                $edo = $edoPayment->getManifest()->getEdo();
                return [
                    'id' => $edoPayment->getId(),
                    'manifestNumber' => $edoPayment->getManifest()->getManifestNumber(),
                    'edoNumber' => $edo ? $edo->getEdoNumber() : null,
                    'amount' => $edoPayment->getAmount(),
                    'submittedBy' => $edoPayment->getSubmittedBy()->getFullName(),
                    'submittedAt' => $edoPayment->getCreatedAt()->format('Y-m-d H:i:s'),
                    'receiptUrl' => $edoPayment->getReceiptFilePath()
                ];
            }, $edoPayments);

            return $this->jsonResponse(['payments' => $result]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve pending payments: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/payments/{id}/validate-edo-access', name: 'validate_edo_access', methods: ['POST'])]
    public function validateEDOAccessPayment(int $id, Request $request): JsonResponse
    {
        $user = $this->requireAuthentication($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Only SYSTEM_ADMIN can validate EDO access payments
        $roleCheck = $this->requireRole($user, [UserRole::SYSTEM_ADMIN->value]);
        if ($roleCheck) {
            return $roleCheck;
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['approved'])) {
            return $this->errorResponse('Approved status is required');
        }

        $approved = (bool) $data['approved'];
        $reason = $data['reason'] ?? null;

        if (!$approved && !$reason) {
            return $this->errorResponse('Rejection reason is required when rejecting payment');
        }

        try {
            $this->edoPaymentService->validateEDOAccessPayment($id, $approved, $reason, $user);

            return $this->jsonResponse([
                'success' => true,
                'message' => $approved ? 'Payment approved and eDO released successfully' : 'Payment rejected successfully',
                'paymentId' => $id,
                'status' => $approved ? 'verified' : 'rejected',
                'validatedBy' => $user->getFullName(),
                'validatedAt' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to validate payment: ' . $e->getMessage(), 500);
        }
    }
}
