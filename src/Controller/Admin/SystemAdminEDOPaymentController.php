<?php

namespace App\Controller\Admin;

use App\Exception\EDOPaymentException;
use App\Repository\EDOPaymentRepository;
use App\Service\EDOPaymentServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * System Admin eDO Payment Controller
 * 
 * Handles system admin operations for validating eDO payments:
 * - View pending payments dashboard
 * - Preview payment receipts
 * - Approve payments
 * - Reject payments with reason
 * 
 * Requirements: 15.2
 */
#[Route('/admin/edo-payments')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class SystemAdminEDOPaymentController extends AbstractController
{
    public function __construct(
        private EDOPaymentServiceInterface $paymentService,
        private EDOPaymentRepository $paymentRepository,
        private string $projectDir
    ) {
    }

    /**
     * Display pending eDO payments dashboard
     * Route: GET /admin/edo-payments
     * Access: ROLE_SYSTEM_ADMIN
     * 
     * Query parameters:
     * - page (optional, default 1): Page number for pagination
     * - limit (optional, default 20): Items per page
     * 
     * Returns JSON response with:
     * - Payment list with eDO details
     * - Pagination metadata
     * - Pending payment count
     * 
     * Requirements: 4.1, 4.2, 4.6, 4.7, 15.6
     */
    #[Route('', name: 'system_admin_edo_payment_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        // Get query parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));

        // Get all pending payments
        $pendingPayments = $this->paymentService->getPendingPayments();

        // Calculate pagination
        $total = count($pendingPayments);
        $totalPages = ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        $paginatedPayments = array_slice($pendingPayments, $offset, $limit);

        // Format payment data
        $paymentData = array_map(function ($payment) {
            $edo = $payment->getEdo();
            $manifest = $payment->getManifest();
            $broker = $manifest->getBroker();

            return [
                'id' => $payment->getId(),
                'edoNumber' => $edo?->getEdoNumber() ?? 'N/A',
                'containerNumber' => $edo?->getContainer()?->getContainerNumber() ?? 'N/A',
                'manifestNumber' => $manifest->getManifestNumber() ?? 'N/A',
                'brokerName' => $broker?->getFullName() ?? 'Unknown',
                'brokerCompany' => $broker?->getCompanyName() ?? 'N/A',
                'amount' => $payment->getAmount(),
                'submittedAt' => $payment->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
            ];
        }, $paginatedPayments);

        // Get pending count
        $pendingCount = $this->paymentRepository->countPendingPayments();

        return $this->json([
            'success' => true,
            'data' => [
                'payments' => $paymentData,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => $totalPages,
                    'totalItems' => $total,
                    'itemsPerPage' => $limit,
                ],
                'pendingCount' => $pendingCount,
            ],
        ]);
    }

    /**
     * Preview payment receipt
     * Route: GET /admin/edo-payments/{id}/receipt
     * Access: ROLE_SYSTEM_ADMIN
     * 
     * Serves the actual receipt file for viewing
     * 
     * Requirements: 5.1, 5.2, 5.3, 5.6
     */
    #[Route('/{id}/receipt', name: 'system_admin_edo_payment_receipt', methods: ['GET'])]
    public function viewReceipt(int $id): Response
    {
        // Find payment
        $payment = $this->paymentRepository->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Payment not found');
        }

        $receiptPath = $payment->getReceiptFilePath();
        
        if (!$receiptPath) {
            throw $this->createNotFoundException('Receipt file not found');
        }

        // Try multiple possible locations
        $possiblePaths = [
            $this->getParameter('kernel.project_dir') . '/storage/payment-receipts/' . basename($receiptPath),
            $this->getParameter('kernel.project_dir') . '/var/share/' . $receiptPath,
            $this->getParameter('kernel.project_dir') . '/public' . $receiptPath,
            $this->getParameter('kernel.project_dir') . $receiptPath,
        ];

        $filePath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $filePath = $path;
                break;
            }
        }

        if (!$filePath || !file_exists($filePath)) {
            throw $this->createNotFoundException('Receipt file not found on disk');
        }

        // Determine MIME type
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        // Create response with file content
        $response = new Response(file_get_contents($filePath));
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Disposition', 'inline; filename="receipt-' . $payment->getId() . '.' . $extension . '"');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }

    /**
     * Get payment receipt metadata
     * Route: GET /admin/edo-payments/{id}/receipt/info
     * Access: ROLE_SYSTEM_ADMIN
     * 
     * Returns JSON response with:
     * - Payment details
     * - Receipt file information (path, URL, type, size)
     * 
     * Requirements: 5.1, 5.2, 5.3, 5.6
     */
    #[Route('/{id}/receipt/info', name: 'system_admin_edo_payment_receipt_info', methods: ['GET'])]
    public function getReceiptInfo(int $id): JsonResponse
    {
        // Implementation here
    }

    /**
     * View official receipt (system-generated)
     * Route: GET /admin/edo-payments/{id}/official-receipt
     * Access: ROLE_SYSTEM_ADMIN
     * 
     * Serves the official receipt PDF generated after payment approval
     */
    #[Route('/{id}/official-receipt', name: 'system_admin_edo_payment_official_receipt', methods: ['GET'])]
    public function viewOfficialReceipt(int $id): Response
    {
        // Find payment
        $payment = $this->paymentRepository->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Payment not found');
        }

        $officialReceiptPath = $payment->getOfficialReceiptPath();
        
        if (!$officialReceiptPath) {
            throw $this->createNotFoundException('Official receipt not found. Payment may not be approved yet.');
        }

        // Build full file path
        $filePath = $this->getParameter('kernel.project_dir') . $officialReceiptPath;

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Official receipt file not found on disk: ' . $officialReceiptPath);
        }

        // Create response with PDF content
        $response = new Response(file_get_contents($filePath));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="official-receipt-' . $payment->getId() . '.pdf"');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }
    public function previewReceipt(int $id): JsonResponse
    {
        // Find payment with relations
        $payment = $this->paymentRepository->findWithRelations($id);

        if (!$payment) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'PAYMENT_NOT_FOUND',
                    'message' => 'Payment not found',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $edo = $payment->getEdo();
        $receiptPath = $payment->getReceiptFilePath();

        // Determine file type from extension
        $extension = strtolower(pathinfo($receiptPath, PATHINFO_EXTENSION));
        $fileType = match ($extension) {
            'pdf' => 'pdf',
            'jpg', 'jpeg', 'png' => 'image',
            default => 'unknown',
        };

        // Try to get file size
        $fileSize = null;
        $possiblePaths = [
            $this->getParameter('kernel.project_dir') . '/storage/payment-receipts/' . $receiptPath,
            $this->getParameter('kernel.project_dir') . '/var/share/' . $receiptPath,
            $this->getParameter('kernel.project_dir') . '/public/uploads/' . $receiptPath,
            $this->getParameter('kernel.project_dir') . '/' . $receiptPath,
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $fileSize = filesize($path);
                break;
            }
        }

        return $this->json([
            'success' => true,
            'data' => [
                'payment' => [
                    'id' => $payment->getId(),
                    'amount' => $payment->getAmount(),
                    'submittedAt' => $payment->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                    'status' => $payment->getStatus()->value,
                ],
                'edo' => [
                    'edoNumber' => $edo?->getEdoNumber() ?? 'N/A',
                    'containerNumber' => $edo?->getContainer()?->getContainerNumber() ?? 'N/A',
                ],
                'receipt' => [
                    'path' => $receiptPath,
                    'url' => '/admin/edo-payments/' . $id . '/receipt/download',
                    'type' => $fileType,
                    'size' => $fileSize,
                ],
            ],
        ]);
    }

    /**
     * Approve eDO payment
     * Route: POST /admin/edo-payments/{id}/approve
     * Access: ROLE_SYSTEM_ADMIN
     * 
     * Approves the payment and releases the associated eDO.
     * Generates official receipt and sends notification to broker.
     * 
     * Returns JSON response with:
     * - Success message
     * - Approval details
     * 
     * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 15.2
     */
    #[Route('/{id}/approve', name: 'system_admin_edo_payment_approve', methods: ['POST'])]
    public function approvePayment(int $id): JsonResponse
    {
        // Find payment with relations
        $payment = $this->paymentRepository->findWithRelations($id);

        if (!$payment) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'PAYMENT_NOT_FOUND',
                    'message' => 'Payment not found',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify payment status is PENDING_VALIDATION
        if ($payment->getStatus()->value !== 'pending_validation') {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_PAYMENT_STATUS',
                    'message' => 'Payment must be in pending validation status to be approved',
                ],
            ], Response::HTTP_CONFLICT);
        }

        try {
            // Get current user
            $user = $this->getUser();

            // Approve payment
            $this->paymentService->approvePayment($payment, $user);

            return $this->json([
                'success' => true,
                'message' => 'Payment approved and eDO released',
                'data' => [
                    'paymentId' => $payment->getId(),
                    'edoNumber' => $payment->getEdo()?->getEdoNumber() ?? 'N/A',
                    'approvedBy' => $user->getFullName(),
                    'approvedAt' => (new \DateTime())->format('Y-m-d\TH:i:s\Z'),
                ],
            ]);
        } catch (EDOPaymentException $e) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while approving the payment',
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reject eDO payment with reason
     * Route: POST /admin/edo-payments/{id}/reject
     * Access: ROLE_SYSTEM_ADMIN
     * 
     * Rejects the payment with a reason and reverts eDO status to PENDING_RELEASE.
     * Sends notification to broker with rejection reason.
     * 
     * Request body:
     * - rejectionReason (required, minimum 10 characters)
     * 
     * Returns JSON response with:
     * - Success message
     * - Rejection details
     * 
     * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 15.2
     */
    #[Route('/{id}/reject', name: 'system_admin_edo_payment_reject', methods: ['POST'])]
    public function rejectPayment(int $id, Request $request): JsonResponse
    {
        // Find payment with relations
        $payment = $this->paymentRepository->findWithRelations($id);

        if (!$payment) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'PAYMENT_NOT_FOUND',
                    'message' => 'Payment not found',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify payment status is PENDING_VALIDATION
        if ($payment->getStatus()->value !== 'pending_validation') {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_PAYMENT_STATUS',
                    'message' => 'Payment must be in pending validation status to be rejected',
                ],
            ], Response::HTTP_CONFLICT);
        }

        // Get rejection reason from request body
        $data = json_decode($request->getContent(), true);
        $rejectionReason = $data['rejectionReason'] ?? '';

        // Validate rejection reason (minimum 10 characters)
        if (strlen(trim($rejectionReason)) < 10) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_REJECTION_REASON',
                    'message' => 'Rejection reason must be at least 10 characters',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Get current user
            $user = $this->getUser();

            // Reject payment
            $this->paymentService->rejectPayment($payment, $rejectionReason, $user);

            return $this->json([
                'success' => true,
                'message' => 'Payment rejected and broker notified',
                'data' => [
                    'paymentId' => $payment->getId(),
                    'edoNumber' => $payment->getEdo()?->getEdoNumber() ?? 'N/A',
                    'rejectedBy' => $user->getFullName(),
                    'rejectedAt' => (new \DateTime())->format('Y-m-d\TH:i:s\Z'),
                    'rejectionReason' => $rejectionReason,
                ],
            ]);
        } catch (EDOPaymentException $e) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ],
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An error occurred while rejecting the payment',
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
