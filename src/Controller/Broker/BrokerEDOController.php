<?php

namespace App\Controller\Broker;

use App\Entity\EDOPayment;
use App\Entity\ElectronicDeliveryOrder;
use App\Exception\EDOPaymentException;
use App\Exception\FileUploadException;
use App\Repository\ElectronicDeliveryOrderRepository;
use App\Service\AuditService;
use App\Service\EDOAuditServiceInterface;
use App\Service\EDOPaymentServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/broker/edos')]
#[IsGranted('ROLE_BROKER')]
class BrokerEDOController extends AbstractController
{
    public function __construct(
        private EDOPaymentServiceInterface $paymentService,
        private ElectronicDeliveryOrderRepository $edoRepository,
        private EDOAuditServiceInterface $auditService,
        private EntityManagerInterface $entityManager,
        private AuditService $generalAuditService,
        private string $projectDir
    ) {
    }

    /**
     * Display broker eDO list page
     * Route: GET /broker/edos/page
     * Access: ROLE_BROKER
     * 
     * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 8.1
     */
    #[Route('/page', name: 'broker_edo_list_page', methods: ['GET'])]
    public function listPage(Request $request): Response
    {
        $user = $this->getUser();
        
        // Get query parameters
        $status = $request->query->get('status');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        // Get broker's eDOs with optional status filter
        $allEdos = $this->paymentService->getBrokerEDOs($user, $status);

        // Implement pagination
        $total = count($allEdos);
        $totalPages = ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        $edos = array_slice($allEdos, $offset, $limit);

        return $this->render('broker/edo/list.html.twig', [
            'edos' => $edos,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'filters' => [
                'status' => $status,
            ],
        ]);
    }

    /**
     * Display broker eDO detail page
     * Route: GET /broker/edos/{id}/page
     * Access: ROLE_BROKER
     * 
     * Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 20.5
     */
    #[Route('/{id}/page', name: 'broker_edo_detail_page', methods: ['GET'])]
    public function detailPage(int $id): Response
    {
        return $this->render('broker/edo/detail.html.twig', [
            'edoId' => $id,
        ]);
    }

    /**
     * Display list of all eDOs for broker's manifests (API endpoint)
     * Route: GET /broker/edos
     * Access: ROLE_BROKER
     * 
     * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 8.1
     */
    #[Route('', name: 'broker_edo_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        // Get query parameters
        $status = $request->query->get('status');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));

        // Get broker's eDOs with optional status filter
        $edos = $this->paymentService->getBrokerEDOs($user, $status);

        // Implement pagination
        $total = count($edos);
        $totalPages = ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        $paginatedEdos = array_slice($edos, $offset, $limit);

        // Format response data
        $edoData = array_map(function (ElectronicDeliveryOrder $edo) {
            $currentPayment = $edo->getCurrentPayment();
            $rejectionReason = null;

            // Get rejection reason from most recent rejected payment
            if ($currentPayment && $currentPayment->getStatus()->value === 'rejected') {
                $rejectionReason = $currentPayment->getRejectionReason();
            }

            return [
                'id' => $edo->getId(),
                'edoNumber' => $edo->getEdoNumber(),
                'containerNumber' => $edo->getContainer()?->getContainerNumber() ?? 'N/A',
                'manifestId' => $edo->getManifest()->getId(),
                'manifestNumber' => $edo->getManifest()->getManifestNumber() ?? 'N/A',
                'status' => $edo->getStatus()->value,
                'feeAmount' => $edo->getFeeAmount(),
                'generatedAt' => $edo->getGeneratedAt()->format('Y-m-d\TH:i:s\Z'),
                'currentPayment' => $currentPayment ? [
                    'id' => $currentPayment->getId(),
                    'submittedAt' => $currentPayment->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                    'status' => $currentPayment->getStatus()->value,
                ] : null,
                'rejectionReason' => $rejectionReason,
            ];
        }, $paginatedEdos);

        return $this->json([
            'success' => true,
            'data' => [
                'edos' => $edoData,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => $totalPages,
                    'totalItems' => $total,
                    'itemsPerPage' => $limit,
                ],
            ],
        ]);
    }

    /**
     * Display eDO detail with payment history
     * Route: GET /broker/edos/{id}
     * Access: ROLE_BROKER (own manifests only)
     * 
     * Requirements: 18.1, 18.2, 18.3, 18.4, 15.5
     */
    #[Route('/{id}', name: 'broker_edo_detail', methods: ['GET'])]
    public function detail(int $id): JsonResponse
    {
        $edo = $this->edoRepository->findOneWithRelations($id);

        if (!$edo) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'EDO_NOT_FOUND',
                    'message' => 'eDO not found',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify broker owns the manifest associated with eDO
        $user = $this->getUser();
        if ($edo->getManifest()->getBroker()?->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED_ACCESS',
                    'message' => 'You do not have permission to view this eDO',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        // Get payment history
        $paymentHistory = $this->paymentService->getPaymentHistory($edo);

        // Format payment history
        $paymentHistoryData = array_map(function ($payment) {
            $data = [
                'id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'submittedAt' => $payment->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                'submittedBy' => $payment->getSubmittedBy()?->getFullName() ?? 'Unknown',
                'status' => $payment->getStatus()->value,
                'receiptPath' => $payment->getReceiptFilePath(),
            ];

            if ($payment->getStatus()->value === 'rejected') {
                $data['rejectionReason'] = $payment->getRejectionReason();
                $data['rejectedBy'] = $payment->getValidatedBy()?->getFullName() ?? 'Unknown';
                $data['rejectedAt'] = $payment->getValidatedAt()?->format('Y-m-d\TH:i:s\Z');
            }

            if ($payment->getStatus()->value === 'approved' || $payment->getStatus()->value === 'verified') {
                $data['approvedBy'] = $payment->getValidatedBy()?->getFullName() ?? 'Unknown';
                $data['approvedAt'] = $payment->getValidatedAt()?->format('Y-m-d\TH:i:s\Z');
                $data['officialReceiptPath'] = $payment->getOfficialReceiptPath();
            }

            return $data;
        }, $paymentHistory);

        return $this->json([
            'success' => true,
            'data' => [
                'edo' => [
                    'id' => $edo->getId(),
                    'edoNumber' => $edo->getEdoNumber(),
                    'containerNumber' => $edo->getContainer()?->getContainerNumber() ?? 'N/A',
                    'manifestNumber' => $edo->getManifest()->getManifestNumber() ?? 'N/A',
                    'manifestId' => $edo->getManifest()->getId(),
                    'status' => $edo->getStatus()->value,
                    'feeAmount' => $edo->getFeeAmount(),
                    'generatedAt' => $edo->getGeneratedAt()->format('Y-m-d\TH:i:s\Z'),
                    'releasedAt' => $edo->getReleasedAt()?->format('Y-m-d\TH:i:s\Z'),
                    'releasedBy' => $edo->getReleasedBy()?->getFullName() ?? null,
                ],
                'paymentHistory' => $paymentHistoryData,
            ],
        ]);
    }

    /**
     * Submit payment for specific eDO
     * Route: POST /broker/edos/{id}/payment
     * Access: ROLE_BROKER (own manifests only)
     * 
     * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 15.1, 15.5
     */
    #[Route('/{id}/payment', name: 'broker_edo_submit_payment', methods: ['POST'])]
    public function submitPayment(int $id, Request $request): JsonResponse
    {
        try {
            // Step 1: Get eDO
            $edo = $this->edoRepository->findOneWithRelations($id);
            if (!$edo) {
                return $this->json(['success' => false, 'error' => ['message' => 'eDO not found']], 404);
            }

            // Step 2: Verify broker owns the manifest
            $user = $this->getUser();
            if ($edo->getManifest()->getBroker()?->getId() !== $user->getId()) {
                return $this->json(['success' => false, 'error' => ['message' => 'Unauthorized']], 403);
            }

            // Step 3: Get uploaded file
            $receiptFile = $request->files->get('receiptFile');
            if (!$receiptFile) {
                return $this->json(['success' => false, 'error' => ['message' => 'No file uploaded']], 400);
            }

            // Step 4: Submit payment
            $payment = $this->paymentService->submitPayment($edo, $receiptFile, $user);

            // Step 5: Return success
            return $this->json([
                'success' => true,
                'message' => 'Payment submitted successfully',
                'data' => [
                    'paymentId' => $payment->getId(),
                    'edoId' => $edo->getId(),
                    'edoNumber' => $edo->getEdoNumber(),
                    'amount' => $payment->getAmount(),
                    'submittedAt' => $payment->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                ],
            ], 201);
            
        } catch (\Exception $e) {
            // Return detailed error for debugging
            return $this->json([
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ],
            ], 500);
        }
    }

    /**
     * Download released eDO PDF
     * Route: GET /broker/edos/{id}/download
     * Access: ROLE_BROKER (own manifests only)
     * 
     * Requirements: 9.1, 9.2, 9.3, 9.5, 14.4, 15.5
     */
    #[Route('/{id}/download', name: 'broker_edo_download', methods: ['GET'])]
    public function downloadEDO(int $id): Response
    {
        $edo = $this->edoRepository->findOneWithRelations($id);

        if (!$edo) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'EDO_NOT_FOUND',
                    'message' => 'eDO not found',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify broker owns the manifest associated with eDO
        $user = $this->getUser();
        if ($edo->getManifest()->getBroker()?->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED_ACCESS',
                    'message' => 'You do not have permission to download this eDO',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        // Verify eDO status is RELEASED
        if ($edo->getStatus()->value !== 'released') {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'EDO_NOT_RELEASED',
                    'message' => 'eDO must be released before it can be downloaded',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        // Retrieve eDO PDF file path
        $pdfPath = $edo->getPdfPath();

        if (!$pdfPath) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'PDF_NOT_FOUND',
                    'message' => 'eDO PDF file not found',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // Try multiple possible locations for the file
        $possiblePaths = [
            $this->getParameter('kernel.project_dir') . '/var/share/' . $pdfPath,
            $this->getParameter('kernel.project_dir') . '/public/uploads/' . $pdfPath,
            $this->getParameter('kernel.project_dir') . '/' . $pdfPath,
        ];

        $fullPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $fullPath = $path;
                break;
            }
        }

        if (!$fullPath) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'PDF_NOT_FOUND',
                    'message' => 'eDO PDF file not found on server',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // Log eDO download action
        $this->generalAuditService->logAction(
            $user,
            'edo_downloaded',
            'ElectronicDeliveryOrder',
            $edo->getId(),
            [
                'edo_number' => $edo->getEdoNumber(),
                'container_number' => $edo->getContainer()?->getContainerNumber(),
                'manifest_id' => $edo->getManifest()->getId(),
                'download_timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ]
        );

        // Return file as download
        $response = new BinaryFileResponse($fullPath);
        $response->setContentDisposition(
            'attachment',
            sprintf('EDO_%s.pdf', $edo->getEdoNumber())
        );

        return $response;
    }

    /**
     * View payment receipt file
     * Requirement 3.3, 3.4
     */
    #[Route('/{id}/receipt/{paymentId}', name: 'broker_edo_view_receipt', methods: ['GET'])]
    public function viewReceipt(int $id, int $paymentId): Response
    {
        $user = $this->getUser();

        // Get eDO
        $edo = $this->entityManager->getRepository(ElectronicDeliveryOrder::class)->find($id);
        if (!$edo) {
            return new JsonResponse([
                'success' => false,
                'error' => ['message' => 'eDO not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify broker owns this eDO
        if ($edo->getManifest()->getBroker()->getId() !== $user->getId()) {
            return new JsonResponse([
                'success' => false,
                'error' => ['message' => 'Access denied'],
            ], Response::HTTP_FORBIDDEN);
        }

        // Get payment
        $payment = $this->entityManager->getRepository(EDOPayment::class)->find($paymentId);
        if (!$payment || $payment->getEdo()->getId() !== $edo->getId()) {
            return new JsonResponse([
                'success' => false,
                'error' => ['message' => 'Payment not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        // Get receipt file path
        $receiptPath = $payment->getReceiptFilePath();
        if (!$receiptPath) {
            return new JsonResponse([
                'success' => false,
                'error' => ['message' => 'Receipt file not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        // Build full path - receipts are stored in storage/payment-receipts/
        $fullPath = $this->projectDir . '/storage/' . ltrim($receiptPath, '/');

        if (!file_exists($fullPath)) {
            return new JsonResponse([
                'success' => false,
                'error' => ['message' => 'Receipt file not found on server'],
            ], Response::HTTP_NOT_FOUND);
        }

        // Determine content type
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $contentType = match($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream'
        };

        // Return file for inline viewing
        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition('inline', basename($fullPath));
        
        // Allow iframe embedding from same origin
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }

    /**
     * View/download official receipt for broker's eDO payment
     * Route: GET /broker/edos/{edoId}/official-receipt
     * Access: ROLE_BROKER (own eDOs only)
     */
    #[Route('/{edoId}/official-receipt', name: 'broker_edo_official_receipt', methods: ['GET'])]
    public function viewOfficialReceipt(int $edoId, Request $request): Response
    {
        $edo = $this->edoRepository->findOneWithRelations($edoId);

        if (!$edo) {
            throw $this->createNotFoundException('eDO not found');
        }

        // Verify broker owns the manifest associated with eDO
        $user = $this->getUser();
        if ($edo->getManifest()->getBroker()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You do not have permission to view this receipt');
        }

        // Get the payment for this eDO
        $payment = $edo->getCurrentPayment();
        
        if (!$payment) {
            throw $this->createNotFoundException('No payment found for this eDO');
        }

        $filePath = $payment->getOfficialReceiptPath();
        if (!$filePath) {
            throw $this->createNotFoundException('Official receipt not available yet');
        }

        // Build full path
        $fullPath = $this->projectDir . $filePath;
        
        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('Official receipt file does not exist');
        }

        // Log download
        $this->generalAuditService->logDocumentDownload($user, 'EDOOfficialReceipt', $payment->getId());

        $response = new BinaryFileResponse($fullPath);
        
        // Set proper Content-Type
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $contentType = match($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream'
        };

        $response->headers->set('Content-Type', $contentType);
        
        // Inline viewing by default
        $inline = $request->query->get('inline', 'true') === 'true';
        
        if ($inline) {
            $response->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                'official-receipt-' . $edo->getEdoNumber() . '.' . $extension
            );
        } else {
            $response->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'official-receipt-' . $edo->getEdoNumber() . '.' . $extension
            );
        }

        return $response;
    }
}

