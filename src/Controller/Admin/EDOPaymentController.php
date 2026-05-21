<?php

namespace App\Controller\Admin;

use App\Service\EDOPaymentServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/edo-payments')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class EDOPaymentController extends AbstractController
{
    public function __construct(
        private EDOPaymentServiceInterface $edoPaymentService,
        private \App\Service\AuditService $auditService,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        private string $projectDir
    ) {
    }

    /**
     * Display pending eDO payments awaiting validation
     * 
     * GET /admin/edo-payments
     */
    #[Route('/', name: 'admin_edo_payments_index', methods: ['GET'])]
    public function index(): Response
    {
        $pendingPayments = $this->edoPaymentService->getPendingEDOAccessPayments();

        return $this->render('admin/edo_payment/index.html.twig', [
            'pending_payments' => $pendingPayments,
        ]);
    }

    /**
     * Validate (approve or reject) an eDO payment
     * 
     * POST /admin/edo-payments/{id}/validate
     */
    #[Route('/{id}/validate', name: 'admin_edo_payments_validate', methods: ['POST'])]
    public function validate(int $id, Request $request): JsonResponse
    {
        try {
            $admin = $this->getUser();
            
            if (!$admin) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Get validation data from request
            $data = json_decode($request->getContent(), true);
            $approved = ($data['approved'] ?? false) === true;
            $reason = $data['reason'] ?? null;

            // Validate rejection reason if rejecting
            if (!$approved && empty(trim($reason))) {
                return $this->json([
                    'success' => false,
                    'message' => 'Rejection reason is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate the eDO payment
            $this->edoPaymentService->validateEDOAccessPayment(
                $id,
                $approved,
                $reason,
                $admin
            );

            return $this->json([
                'success' => true,
                'message' => $approved
                    ? 'eDO payment approved and eDO released successfully.'
                    : 'eDO payment rejected successfully.'
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while validating the eDO payment'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get payment details for validation modal
     * 
     * GET /admin/edo-payments/{id}/details
     */
    #[Route('/{id}/details', name: 'admin_edo_payment_details', methods: ['GET'])]
    public function details(int $id): JsonResponse
    {
        try {
            $payment = $this->edoPaymentService->getEDOPaymentById($id);
            
            if (!$payment) {
                return $this->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $submittedBy = $payment->getSubmittedBy();
            
            return $this->json([
                'success' => true,
                'data' => [
                    'id' => $payment->getId(),
                    'amount' => $payment->getAmount(),
                    'status' => $payment->getStatus()->value,
                    'submittedBy' => $submittedBy ? ($submittedBy->getFullName() ?? $submittedBy->getEmail()) : 'Unknown',
                    'createdAt' => $payment->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                    'receiptPath' => $payment->getReceiptFilePath(),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error loading payment details: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * View/download eDO payment receipt
     * 
     * GET /admin/edo-payments/{id}/receipt
     */
    #[Route('/{id}/receipt', name: 'admin_edo_payment_receipt', methods: ['GET'])]
    public function viewReceipt(int $id, Request $request): Response
    {
        try {
            $payment = $this->edoPaymentService->getEDOPaymentById($id);
            
            if (!$payment) {
                throw $this->createNotFoundException('Payment not found');
            }

            $filePath = $payment->getReceiptFilePath();
            if (!$filePath) {
                throw $this->createNotFoundException('Receipt file not found for this payment');
            }

            // Build full path: storage/{filePath}
            $fullPath = $this->projectDir . '/storage/' . ltrim($filePath, '/');
            
            if (!file_exists($fullPath)) {
                error_log("Receipt file not found: " . $fullPath);
                throw $this->createNotFoundException('Receipt file does not exist: ' . basename($fullPath));
            }

            // Log download
            $this->auditService->logDocumentDownload($this->getUser(), 'EDOPaymentReceipt', $payment->getId());

            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($fullPath);
            
            // Set proper Content-Type
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $contentType = match($extension) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream'
            };

            $response->headers->set('Content-Type', $contentType);
            
            // Check if inline viewing is requested
            $inline = $request->query->get('inline', 'true') === 'true';
            
            if ($inline) {
                $response->setContentDisposition(
                    \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                    'receipt-' . $payment->getId() . '.' . $extension
                );
            } else {
                $response->setContentDisposition(
                    \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    'receipt-' . $payment->getId() . '.' . $extension
                );
            }

            return $response;
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            // Re-throw NotFoundHttpException as-is
            throw $e;
        } catch (\Exception $e) {
            error_log("Receipt error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $this->createNotFoundException('Error loading receipt: ' . $e->getMessage());
        }
    }

    /**
     * View/download official receipt
     * 
     * GET /admin/edo-payments/{id}/official-receipt
     */
    #[Route('/{id}/official-receipt', name: 'admin_edo_payment_official_receipt', methods: ['GET'])]
    public function viewOfficialReceipt(int $id, Request $request): Response
    {
        try {
            $payment = $this->edoPaymentService->getEDOPaymentById($id);
            
            if (!$payment) {
                throw $this->createNotFoundException('Payment not found');
            }

            $filePath = $payment->getOfficialReceiptPath();
            if (!$filePath) {
                throw $this->createNotFoundException('Official receipt not found for this payment');
            }

            // Build full path: {projectDir}{filePath}
            // Path is like: /storage/official-receipts/2026/05/edo-86-payment-62.pdf
            $fullPath = $this->projectDir . $filePath;
            
            if (!file_exists($fullPath)) {
                error_log("Official receipt file not found: " . $fullPath);
                throw $this->createNotFoundException('Official receipt file does not exist');
            }

            // Log download
            $this->auditService->logDocumentDownload($this->getUser(), 'EDOOfficialReceipt', $payment->getId());

            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($fullPath);
            
            // Set proper Content-Type
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $contentType = match($extension) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream'
            };

            $response->headers->set('Content-Type', $contentType);
            
            // Check if inline viewing is requested
            $inline = $request->query->get('inline', 'true') === 'true';
            
            if ($inline) {
                $response->setContentDisposition(
                    \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                    'official-receipt-' . $payment->getId() . '.' . $extension
                );
            } else {
                $response->setContentDisposition(
                    \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    'official-receipt-' . $payment->getId() . '.' . $extension
                );
            }

            return $response;
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            error_log("Official receipt error: " . $e->getMessage());
            throw $this->createNotFoundException('Error loading official receipt: ' . $e->getMessage());
        }
    }
}
