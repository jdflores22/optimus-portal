<?php

namespace App\Controller\Broker;

use App\Entity\Billing;
use App\Entity\EDOPayment;
use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use App\Exception\EDOPaymentException;
use App\Exception\FileUploadException;
use App\Repository\BillingRepository;
use App\Repository\EDORenewalRequestRepository;
use App\Repository\ElectronicDeliveryOrderRepository;
use App\Service\ActivityLogService;
use App\Service\AuditService;
use App\Service\DetentionChargeServiceInterface;
use App\Service\EDOPaymentServiceInterface;
use App\Service\EDORenewalServiceInterface;
use App\Service\FileUploadService;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/broker/edos')]
#[IsGranted('ROLE_BROKER')]
class BrokerEDOController extends AbstractController
{
    public function __construct(
        private EDOPaymentServiceInterface $paymentService,
        private ElectronicDeliveryOrderRepository $edoRepository,
        private EntityManagerInterface $entityManager,
        private AuditService $generalAuditService,
        private LoggerInterface $logger,
        private string $projectDir,
        private InAppNotificationService $notificationService
    ) {
    }

    // ==================== EDO RENEWAL WORKFLOW ENDPOINTS ====================

    /**
     * Display renewal request form for expired eDO (GET) and handle submission (POST)
     * Route: GET/POST /broker/edos/{id}/request-renewal
     * Access: ROLE_BROKER (own manifests only)
     * 
     * Requirements: 1.1, 3.1, 5.1, 5.2, 13.1, 13.2, 14.1, 14.2 (GET)
     * Requirements: 3.1, 3.2, 3.3, 7.1, 7.2, 8.1, 8.2, 13.3, 14.3, 14.4, 14.5 (POST)
     */
    #[Route('/{id}/request-renewal', name: 'broker_edo_request_renewal', methods: ['GET', 'POST'])]
    public function requestRenewal(
        int $id,
        Request $request,
        EDORenewalServiceInterface $renewalService,
        DetentionChargeServiceInterface $detentionService,
        ActivityLogService $activityLogService,
        EDORenewalRequestRepository $renewalRequestRepository
    ): Response {
        $edo = $this->edoRepository->findOneWithRelations($id);

        if (!$edo) {
            if ($request->isMethod('POST')) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'EDO_NOT_FOUND',
                        'message' => 'eDO not found',
                    ],
                ], Response::HTTP_NOT_FOUND);
            }
            throw $this->createNotFoundException('eDO not found');
        }

        // Verify broker owns the manifest associated with eDO
        $user = $this->getUser();
        if ($edo->getManifest()->getBroker()?->getId() !== $user->getId()) {
            if ($request->isMethod('POST')) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'UNAUTHORIZED_ACCESS',
                        'message' => 'You do not have permission to request renewal for this eDO',
                    ],
                ], Response::HTTP_FORBIDDEN);
            }
            throw $this->createAccessDeniedException('You do not have permission to request renewal for this eDO');
        }

        // Check if there's already a renewal request for this eDO
        $existingRequests = $renewalRequestRepository->findByExpiredEdo($edo);
        $existingRequest = !empty($existingRequests) ? end($existingRequests) : null;

        // Validate eDO is eligible for renewal
        if (!$renewalService->isEligibleForRenewal($edo)) {
            if ($request->isMethod('POST')) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_ELIGIBLE',
                        'message' => 'eDO is not eligible for renewal. It must be expired and have a terminal assigned.',
                    ],
                ], Response::HTTP_BAD_REQUEST);
            }
            
            $this->addFlash('error', 'eDO is not eligible for renewal. It must be expired and have a terminal assigned.');
            return $this->redirectToRoute('broker_edo_detail_page', ['id' => $id]);
        }

        // Handle GET request - display form
        if ($request->isMethod('GET')) {
            // Calculate potential detention charges for display
            $overdueDays = $detentionService->calculateOverdueDays($edo);
            $detentionCharge = $detentionService->calculateDetentionCharge($overdueDays, $edo);

            // Log page access via ActivityLogService
            $activityLogService->logActivity(
                $user,
                'edo_renewal_form_viewed',
                'ElectronicDeliveryOrder',
                $edo->getId(),
                null,
                null,
                [
                    'edo_number' => $edo->getEdoNumber(),
                    'overdue_days' => $overdueDays,
                    'detention_charge' => $detentionCharge
                ]
            );

            return $this->render('broker/edo/request_renewal.html.twig', [
                'edo' => $edo,
                'overdueDays' => $overdueDays,
                'detentionCharge' => $detentionCharge,
                'officeHoursNote' => 'Requests are restricted to office hours only (Monday-Friday, 8:00 AM - 5:00 PM)',
                'existingRequest' => $existingRequest,
            ]);
        }

        // Handle POST request - process form submission
        try {
            // Get form data
            $returnDateString = $request->request->get('return_date');
            $notes = $request->request->get('notes');

            if (!$returnDateString) {
                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'MISSING_RETURN_DATE',
                        'message' => 'Return date is required',
                    ],
                ], Response::HTTP_BAD_REQUEST);
            }

            // Parse return date
            $returnDate = new \DateTime($returnDateString);

            // Validate request date
            if (!$renewalService->validateRequestDate($returnDate)) {
                // Log failed validation attempt via AuditService
                $this->generalAuditService->logAction(
                    $user,
                    'edo_renewal_validation_failed',
                    'ElectronicDeliveryOrder',
                    $edo->getId(),
                    [
                        'edo_id' => $edo->getId(),
                        'edo_number' => $edo->getEdoNumber(),
                        'requested_return_date' => $returnDate->format('Y-m-d H:i:s'),
                        'validation_error' => 'Invalid return date - must be in future and within office hours'
                    ]
                );

                return $this->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_RETURN_DATE',
                        'message' => 'Invalid return date. Date must be in the future.',
                    ],
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create renewal request
            $renewalRequest = $renewalService->createRenewalRequest($edo, $user, $returnDate, $notes);

            // Send notification to accounting staff if detention charges apply
            if ($detentionService->requiresDetentionCharges($renewalRequest)) {
                $this->sendRenewalRequestNotificationToAccounting($renewalRequest);
            }

            // Note: Billing will be generated by accounting staff if detention charges apply
            // The status will be PENDING_REVIEW if charges apply, or READY_FOR_GENERATION if no charges

            // Log successful request submission via ActivityLogService
            $activityLogService->logActivity(
                $user,
                'edo_renewal_request_submitted',
                'EDORenewalRequest',
                $renewalRequest->getId(),
                null,
                [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'status' => $renewalRequest->getStatus()->value
                ],
                [
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber(),
                    'return_date' => $returnDate->format('Y-m-d'),
                    'overdue_days' => $renewalRequest->getOverdueDays(),
                    'detention_charge' => $renewalRequest->getDetentionChargeAmount()
                ]
            );

            // Display success message with request status
            $message = 'Renewal request submitted successfully.';
            if ($detentionService->requiresDetentionCharges($renewalRequest)) {
                $message .= ' The accounting team will review and generate billing for detention charges.';
            } else {
                $message .= ' Your request is ready for processing by the shipping line staff.';
            }

            return $this->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'renewalRequestId' => $renewalRequest->getId(),
                    'status' => $renewalRequest->getStatus()->value,
                    'overdueDays' => $renewalRequest->getOverdueDays(),
                    'detentionCharge' => $renewalRequest->getDetentionChargeAmount(),
                    'requiresPayment' => $detentionService->requiresDetentionCharges($renewalRequest),
                    'redirectUrl' => $this->generateUrl('broker_edo_renewal_status', ['id' => $renewalRequest->getId()]),
                ],
            ], Response::HTTP_CREATED);

        } catch (\InvalidArgumentException $e) {
            // Log failed validation attempt via AuditService
            $this->generalAuditService->logAction(
                $user,
                'edo_renewal_validation_failed',
                'ElectronicDeliveryOrder',
                $edo->getId(),
                [
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber(),
                    'error_message' => $e->getMessage()
                ]
            );

            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            // Log error
            $this->generalAuditService->logAction(
                $user,
                'edo_renewal_request_failed',
                'ElectronicDeliveryOrder',
                $edo->getId(),
                [
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber(),
                    'error_message' => $e->getMessage()
                ]
            );

            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_FAILED',
                    'message' => 'Failed to create renewal request: ' . $e->getMessage(),
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * View renewal request status
     * Route: GET /broker/edos/renewal-requests/{id}
     * Access: ROLE_BROKER (own requests only)
     * 
     * Requirements: 15.2, 15.3, 15.4, 15.5
     */
    #[Route('/renewal-requests/{id}', name: 'broker_edo_renewal_status', methods: ['GET'])]
    public function viewRenewalStatus(
        int $id,
        EDORenewalRequestRepository $renewalRequestRepository,
        ActivityLogService $activityLogService
    ): Response {
        $renewalRequest = $renewalRequestRepository->find($id);

        if (!$renewalRequest) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_NOT_FOUND',
                    'message' => 'Renewal request not found',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // Check VIEW permission using voter
        $this->denyAccessUnlessGranted('view', $renewalRequest);

        $user = $this->getUser();

        // Log page access via ActivityLogService
        $activityLogService->logActivity(
            $user,
            'edo_renewal_status_viewed',
            'EDORenewalRequest',
            $renewalRequest->getId(),
            null,
            null,
            [
                'renewal_request_id' => $renewalRequest->getId(),
                'status' => $renewalRequest->getStatus()->value
            ]
        );

        // Display request details including status, charges, and payment information
        $expiredEdo = $renewalRequest->getExpiredEdo();
        $newEdo = $renewalRequest->getNewEdo();
        $billing = $renewalRequest->getDetentionBilling();

        return $this->json([
            'success' => true,
            'data' => [
                'renewalRequest' => [
                    'id' => $renewalRequest->getId(),
                    'status' => $renewalRequest->getStatus()->value,
                    'requestedAt' => $renewalRequest->getRequestedAt()->format('Y-m-d\TH:i:s\Z'),
                    'emptyContainerReturnDate' => $renewalRequest->getEmptyContainerReturnDate()->format('Y-m-d\TH:i:s\Z'),
                    'overdueDays' => $renewalRequest->getOverdueDays(),
                    'detentionChargeAmount' => $renewalRequest->getDetentionChargeAmount(),
                    'paymentVerified' => $renewalRequest->isPaymentVerified(),
                    'paymentVerifiedAt' => $renewalRequest->getPaymentVerifiedAt()?->format('Y-m-d\TH:i:s\Z'),
                    'additionalNotes' => $renewalRequest->getAdditionalNotes(),
                    'completedAt' => $renewalRequest->getCompletedAt()?->format('Y-m-d\TH:i:s\Z'),
                ],
                'expiredEdo' => [
                    'id' => $expiredEdo->getId(),
                    'edoNumber' => $expiredEdo->getEdoNumber(),
                    'containerNumber' => $expiredEdo->getContainer()?->getContainerNumber() ?? 'N/A',
                    'expiresAt' => $expiredEdo->getExpiresAt()?->format('Y-m-d\TH:i:s\Z'),
                ],
                'newEdo' => $newEdo ? [
                    'id' => $newEdo->getId(),
                    'edoNumber' => $newEdo->getEdoNumber(),
                    'generatedAt' => $newEdo->getGeneratedAt()->format('Y-m-d\TH:i:s\Z'),
                    'expiresAt' => $newEdo->getExpiresAt()?->format('Y-m-d\TH:i:s\Z'),
                    'cyLocation' => $newEdo->getCyLocation(),
                ] : null,
                'billing' => $billing ? [
                    'id' => $billing->getId(),
                    'totalAmount' => $billing->getTotalAmount(),
                    'detentionDays' => $billing->getDetentionDays(),
                    'detentionRate' => $billing->getDetentionRate(),
                    'status' => method_exists($billing, 'getStatus') ? $billing->getStatus() : 'pending',
                ] : null,
            ],
        ]);
    }

    /**
     * List all renewal requests for the broker
     * Route: GET /broker/edos/renewal-requests
     * Access: ROLE_BROKER
     * 
     * Requirements: 15.2
     */
    #[Route('/renewal-requests', name: 'broker_edo_renewal_list', methods: ['GET'])]
    public function listRenewalRequests(
        EDORenewalRequestRepository $renewalRequestRepository,
        ActivityLogService $activityLogService
    ): Response {
        $user = $this->getUser();

        // Fetch all renewal requests for current broker
        $renewalRequests = $renewalRequestRepository->findByBroker($user);

        // Log page access via ActivityLogService
        $activityLogService->logActivity(
            $user,
            'edo_renewal_list_viewed',
            'EDORenewalRequest',
            null,
            null,
            null,
            [
                'request_count' => count($renewalRequests)
            ]
        );

        // Display list with status, dates, and charges
        $requestsData = array_map(function (EDORenewalRequest $request) {
            $expiredEdo = $request->getExpiredEdo();
            $newEdo = $request->getNewEdo();

            return [
                'id' => $request->getId(),
                'status' => $request->getStatus()->value,
                'requestedAt' => $request->getRequestedAt()->format('Y-m-d\TH:i:s\Z'),
                'emptyContainerReturnDate' => $request->getEmptyContainerReturnDate()->format('Y-m-d\TH:i:s\Z'),
                'overdueDays' => $request->getOverdueDays(),
                'detentionChargeAmount' => $request->getDetentionChargeAmount(),
                'paymentVerified' => $request->isPaymentVerified(),
                'completedAt' => $request->getCompletedAt()?->format('Y-m-d\TH:i:s\Z'),
                'expiredEdoNumber' => $expiredEdo->getEdoNumber(),
                'expiredEdoId' => $expiredEdo->getId(),
                'newEdoNumber' => $newEdo?->getEdoNumber(),
                'newEdoId' => $newEdo?->getId(),
                'detailUrl' => $this->generateUrl('broker_edo_renewal_status', ['id' => $request->getId()]),
            ];
        }, $renewalRequests);

        return $this->json([
            'success' => true,
            'data' => [
                'renewalRequests' => $requestsData,
                'total' => count($renewalRequests),
            ],
        ]);
    }

    /**
     * Calculate detention charges for a given return date (AJAX endpoint)
     * Route: POST /broker/edos/{id}/calculate-detention
     * Access: ROLE_BROKER (own manifests only)
     * 
     * Requirements: 13.1, 13.2, 13.3
     */
    #[Route('/{id}/calculate-detention', name: 'broker_edo_calculate_detention', methods: ['POST'])]
    public function calculateDetention(
        int $id,
        Request $request,
        DetentionChargeServiceInterface $detentionService
    ): JsonResponse {
        $edo = $this->edoRepository->findOneWithRelations($id);

        if (!$edo) {
            return $this->json([
                'success' => false,
                'message' => 'eDO not found',
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify broker owns the manifest associated with eDO
        $user = $this->getUser();
        if ($edo->getManifest()->getBroker()?->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'You do not have permission to access this eDO',
            ], Response::HTTP_FORBIDDEN);
        }

        // Get return date from request body
        $data = json_decode($request->getContent(), true);
        $returnDateString = $data['return_date'] ?? null;

        if (!$returnDateString) {
            return $this->json([
                'success' => false,
                'message' => 'Return date is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Parse return date
            $returnDate = new \DateTime($returnDateString);
            $expirationDate = $edo->getExpiresAt();

            if (!$expirationDate) {
                return $this->json([
                    'success' => false,
                    'message' => 'eDO has no expiration date set',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Calculate overdue days from expiration date to return date
            // If return date is before or equal to expiration, no charges
            if ($returnDate <= $expirationDate) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'overdue_days' => 0,
                        'detention_rate' => 0,
                        'total_charge' => 0,
                    ],
                ]);
            }

            // Calculate days between expiration and return date
            $interval = $returnDate->diff($expirationDate);
            $overdueDays = $interval->days;

            // Calculate detention charge
            $detentionCharge = $detentionService->calculateDetentionCharge($overdueDays, $edo);

            // Get detention rate for display
            $detentionRate = $overdueDays > 0 ? ($detentionCharge / $overdueDays) : 0;

            return $this->json([
                'success' => true,
                'data' => [
                    'overdue_days' => $overdueDays,
                    'detention_rate' => $detentionRate,
                    'total_charge' => $detentionCharge,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Failed to calculate detention charges: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ==================== DETENTION BILLING ENDPOINTS ====================

    /**
     * List detention billings for broker (page view)
     * Route: GET /broker/billings/detention
     * Access: ROLE_BROKER
     */
    #[Route('/billings/detention', name: 'broker_detention_billings_page', methods: ['GET'])]
    public function detentionBillingsPage(
        BillingRepository $billingRepository
    ): Response {
        $user = $this->getUser();
        
        // Get all detention billings for this broker's renewal requests
        $billings = $billingRepository->findDetentionBillingsByBroker($user);

        return $this->render('broker/billing/detention_billings.html.twig', [
            'billings' => $billings,
        ]);
    }

    /**
     * Upload payment receipt for detention billing
     * Route: POST /broker/billings/{id}/upload-receipt
     * Access: ROLE_BROKER
     */
    #[Route('/billings/{id}/upload-receipt', name: 'broker_billing_upload_receipt', methods: ['POST'])]
    public function uploadDetentionReceipt(
        int $id,
        Request $request,
        BillingRepository $billingRepository,
        FileUploadService $fileUploadService,
        ActivityLogService $activityLogService
    ): JsonResponse {
        $billing = $billingRepository->find($id);

        if (!$billing) {
            return $this->json([
                'success' => false,
                'message' => 'Billing not found',
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify this is a detention billing
        if ($billing->getBillingType() !== 'detention') {
            return $this->json([
                'success' => false,
                'message' => 'Invalid billing type',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify broker owns this billing
        $user = $this->getUser();
        $renewalRequest = $billing->getEdoRenewalRequest();
        if (!$renewalRequest || $renewalRequest->getRequestedBy()->getId() !== $user->getId()) {
            return $this->json([
                'success' => false,
                'message' => 'You do not have permission to upload receipt for this billing',
            ], Response::HTTP_FORBIDDEN);
        }

        // Check if receipt already uploaded
        if ($billing->getReceiptFilePath()) {
            return $this->json([
                'success' => false,
                'message' => 'Payment receipt has already been uploaded for this billing',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get uploaded file
        $receiptFile = $request->files->get('receiptFile');
        if (!$receiptFile) {
            return $this->json([
                'success' => false,
                'message' => 'No file uploaded',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Upload file
            $receiptPath = $fileUploadService->storeDetentionPaymentReceipt(
                $receiptFile,
                $billing->getId(),
                $user->getId()
            );

            // Update billing with receipt path
            $billing->setReceiptFilePath($receiptPath);
            $billing->setPaymentSubmittedAt(new \DateTime());
            $billing->setPaymentSubmittedBy($user);

            // Update renewal request status to PAYMENT_SUBMITTED
            $renewalRequest->setStatus(\App\Entity\Enum\RenewalRequestStatus::PAYMENT_SUBMITTED);

            $this->entityManager->flush();

            // Log activity
            $activityLogService->logActivity(
                $user,
                'detention_payment_receipt_uploaded',
                'Billing',
                $billing->getId(),
                null,
                null,
                [
                    'billing_id' => $billing->getId(),
                    'renewal_request_id' => $renewalRequest->getId(),
                    'amount' => $billing->getTotalAmount()
                ]
            );

            // Notify accounting staff
            $this->sendPaymentReceiptNotificationToAccounting($billing, $renewalRequest);

            return $this->json([
                'success' => true,
                'message' => 'Payment receipt uploaded successfully. Accounting will verify your payment.',
                'data' => [
                    'billingId' => $billing->getId(),
                    'receiptPath' => $receiptPath,
                    'submittedAt' => $billing->getPaymentSubmittedAt()->format('Y-m-d\TH:i:s\Z'),
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to upload detention payment receipt', [
                'billing_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'message' => 'Failed to upload receipt: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * View detention payment receipt
     * Route: GET /broker/billings/{id}/receipt
     * Access: ROLE_BROKER
     */
    #[Route('/billings/{id}/receipt', name: 'broker_billing_view_receipt', methods: ['GET'])]
    public function viewDetentionReceipt(
        int $id,
        Request $request,
        BillingRepository $billingRepository
    ): Response {
        $billing = $billingRepository->find($id);

        if (!$billing) {
            throw $this->createNotFoundException('Billing not found');
        }

        // Verify broker owns this billing
        $user = $this->getUser();
        $renewalRequest = $billing->getEdoRenewalRequest();
        if (!$renewalRequest || $renewalRequest->getRequestedBy()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You do not have permission to view this receipt');
        }

        $receiptPath = $billing->getReceiptFilePath();
        if (!$receiptPath) {
            throw $this->createNotFoundException('Receipt not found');
        }

        // Build full path
        $fullPath = $this->projectDir . '/storage/' . ltrim($receiptPath, '/');

        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('Receipt file not found on server');
        }

        // Determine content type
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $contentType = match($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream'
        };

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $contentType);

        // Check if inline or download
        $inline = $request->query->get('inline', 'true') === 'true';
        if ($inline) {
            $response->setContentDisposition('inline', basename($fullPath));
        } else {
            $response->setContentDisposition('attachment', basename($fullPath));
        }

        return $response;
    }

    /**
     * Send notification to accounting staff about payment receipt upload
     */
    private function sendPaymentReceiptNotificationToAccounting(Billing $billing, EDORenewalRequest $renewalRequest): void
    {
        try {
            $expiredEdo = $renewalRequest->getExpiredEdo();
            $shippingLine = $expiredEdo->getShippingLine();

            if (!$shippingLine) {
                return;
            }

            // Find all accounting users for this shipping line
            $accountingUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.shippingLineScope = :shippingLine')
                ->andWhere('u.role = :role')
                ->andWhere('u.status = :status')
                ->setParameter('shippingLine', $shippingLine)
                ->setParameter('role', 'ACCOUNTING')
                ->setParameter('status', 'ACTIVE')
                ->getQuery()
                ->getResult();

            foreach ($accountingUsers as $accountingUser) {
                $this->notificationService->createNotification(
                    $accountingUser,
                    'Detention Payment Receipt Uploaded',
                    sprintf(
                        'Broker %s has uploaded payment receipt for detention billing #%d (₱%s). Please verify the payment.',
                        $renewalRequest->getRequestedBy()->getEmail(),
                        $billing->getId(),
                        number_format($billing->getTotalAmount(), 2)
                    ),
                    'info',
                    [
                        'billing_id' => $billing->getId(),
                        'renewal_request_id' => $renewalRequest->getId()
                    ]
                );
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to send payment receipt notification', [
                'billing_id' => $billing->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    // ==================== EXISTING EDO ENDPOINTS ====================

    /**
     * Display broker eDO list page
     * Route: GET /broker/edos/page
     * Access: ROLE_BROKER
     * 
     * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 8.1
     */
    #[Route('/page', name: 'broker_edo_list_page', methods: ['GET'])]
    public function listPage(
        Request $request,
        EDORenewalRequestRepository $renewalRequestRepository
    ): Response {
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

        // For each expired eDO, check if there's a pending renewal request
        $renewalRequests = [];
        $renewedToEdos = [];
        foreach ($edos as $edo) {
            if ($edo->getStatus()->value === 'expired') {
                $requests = $renewalRequestRepository->findByExpiredEdo($edo);
                if (!empty($requests)) {
                    // Get the most recent request
                    $renewalRequests[$edo->getId()] = end($requests);
                    
                    // Check if renewal is completed
                    foreach ($requests as $request) {
                        if ($request->getStatus()->value === 'completed' && $request->getNewEdo()) {
                            $renewedToEdos[$edo->getId()] = $request->getNewEdo();
                            break;
                        }
                    }
                }
            }
        }

        return $this->render('broker/edo/list.html.twig', [
            'edos' => $edos,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'filters' => [
                'status' => $status,
            ],
            'renewalRequests' => $renewalRequests,
            'renewedToEdos' => $renewedToEdos,
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
    public function list(Request $request, EDORenewalRequestRepository $renewalRequestRepository): JsonResponse
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
        $edoData = array_map(function (ElectronicDeliveryOrder $edo) use ($renewalRequestRepository) {
            $currentPayment = $edo->getCurrentPayment();
            $rejectionReason = null;

            // Get rejection reason from most recent rejected payment
            if ($currentPayment && $currentPayment->getStatus()->value === 'rejected') {
                $rejectionReason = $currentPayment->getRejectionReason();
            }

            // Check if this expired eDO has been renewed
            $renewedToEdo = null;
            if ($edo->getStatus()->value === 'expired') {
                $renewalRequests = $renewalRequestRepository->findByExpiredEdo($edo);
                if (!empty($renewalRequests)) {
                    $completedRequest = null;
                    foreach ($renewalRequests as $request) {
                        if ($request->getStatus()->value === 'completed' && $request->getNewEdo()) {
                            $completedRequest = $request;
                            break;
                        }
                    }
                    if ($completedRequest) {
                        $newEdo = $completedRequest->getNewEdo();
                        $renewedToEdo = [
                            'id' => $newEdo->getId(),
                            'edoNumber' => $newEdo->getEdoNumber(),
                        ];
                    }
                }
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
                'expiresAt' => $edo->getExpiresAt()?->format('Y-m-d\TH:i:s\Z'),
                'isRenewal' => $edo->getPreviousVersion() !== null,
                'previousEdoNumber' => $edo->getPreviousVersion()?->getEdoNumber(),
                'previousEdoId' => $edo->getPreviousVersion()?->getId(),
                'renewedToEdo' => $renewedToEdo,
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
    public function detail(
        int $id,
        EDORenewalRequestRepository $renewalRequestRepository,
        ActivityLogService $activityLogService
    ): JsonResponse {
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

        // Check if there's a renewal request for this expired eDO
        $renewalRequest = null;
        $detentionBilling = null;
        $newEdo = null;
        if ($edo->getStatus()->value === 'expired') {
            $renewalRequests = $renewalRequestRepository->findByExpiredEdo($edo);
            if (!empty($renewalRequests)) {
                $renewalRequest = end($renewalRequests); // Get most recent
                $detentionBilling = $renewalRequest->getDetentionBilling();
                $newEdo = $renewalRequest->getNewEdo();
            }
        }
        
        // Check if this eDO is a renewal of another eDO
        $isRenewal = $edo->getPreviousVersion() !== null;
        $renewalPaymentInfo = null;
        
        // If this is a renewed eDO, get the detention payment from the renewal request
        if ($isRenewal) {
            $previousEdo = $edo->getPreviousVersion();
            $renewalRequests = $renewalRequestRepository->findByExpiredEdo($previousEdo);
            foreach ($renewalRequests as $request) {
                if ($request->getNewEdo() && $request->getNewEdo()->getId() === $edo->getId()) {
                    $renewalRequest = $request;
                    $detentionBilling = $request->getDetentionBilling();
                    
                    // Create payment info for the detention charges
                    if ($detentionBilling && $detentionBilling->getPaymentSubmittedAt()) {
                        $renewalPaymentInfo = [
                            'id' => 'detention_' . $detentionBilling->getId(), // Fake ID for template
                            'type' => 'detention_payment',
                            'amount' => $detentionBilling->getTotalAmount(),
                            'submittedAt' => $detentionBilling->getPaymentSubmittedAt()->format('Y-m-d\TH:i:s\Z'),
                            'submittedBy' => $user->getFullName() ?? $user->getEmail(),
                            'status' => 'verified',
                            'description' => 'Detention charges payment for renewal',
                            'detentionDays' => $detentionBilling->getDetentionDays(),
                            'detentionRate' => $detentionBilling->getDetentionRate(),
                        ];
                    }
                    break;
                }
            }
        }

        // Get payment history
        $paymentHistory = $this->paymentService->getPaymentHistory($edo);

        // Get activity logs for this eDO
        $activityLogs = $activityLogService->getEntityActivityLogs('ElectronicDeliveryOrder', $edo->getId());

        // Format activity logs
        $activityLogsData = array_map(function ($log) {
            return [
                'id' => $log->getId(),
                'activityType' => $log->getActivityType(),
                'description' => $log->getActivityDescription(),
                'user' => $log->getUser() ? ($log->getUser()->getFullName() ?? $log->getUser()->getEmail()) : 'System',
                'createdAt' => $log->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                'metadata' => $log->getAdditionalContext(),
            ];
        }, $activityLogs);

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
        
        // If this is a renewed eDO, prepend the detention payment to payment history
        if ($renewalPaymentInfo) {
            array_unshift($paymentHistoryData, $renewalPaymentInfo);
        }

        $responseData = [
            'edo' => [
                'id' => $edo->getId(),
                'edoNumber' => $edo->getEdoNumber(),
                'containerNumber' => $edo->getContainer()?->getContainerNumber() ?? 'N/A',
                'manifestNumber' => $edo->getManifest()->getManifestNumber() ?? 'N/A',
                'manifestId' => $edo->getManifest()->getId(),
                'status' => $edo->getStatus()->value,
                'feeAmount' => $edo->getFeeAmount(),
                'generatedAt' => $edo->getGeneratedAt()->format('Y-m-d\TH:i:s\Z'),
                'expiresAt' => $edo->getExpiresAt()?->format('Y-m-d\TH:i:s\Z'),
                'cyLocation' => $edo->getCyLocation(),
                'releasedAt' => $edo->getReleasedAt()?->format('Y-m-d\TH:i:s\Z'),
                'releasedBy' => $edo->getReleasedBy()?->getFullName() ?? null,
                'generatedByName' => $edo->getGeneratedByName(),
                'additionalNotes' => $edo->getAdditionalNotes(),
                'previousEdoId' => $edo->getPreviousVersion()?->getId(),
                'previousEdoNumber' => $edo->getPreviousVersion()?->getEdoNumber(),
                'isRenewal' => $isRenewal,
                'hasRenewal' => $newEdo !== null,
                'newEdoId' => $newEdo?->getId(),
                'newEdoNumber' => $newEdo?->getEdoNumber(),
                'newEdoStatus' => $newEdo?->getStatus()->value,
            ],
            'paymentHistory' => $paymentHistoryData,
            'activityLogs' => $activityLogsData,
        ];

        // Add renewal request information if exists
        if ($renewalRequest) {
            $responseData['renewalRequest'] = [
                'id' => $renewalRequest->getId(),
                'status' => $renewalRequest->getStatus()->value,
                'requestedAt' => $renewalRequest->getRequestedAt()->format('Y-m-d\TH:i:s\Z'),
                'detentionChargeAmount' => $renewalRequest->getDetentionChargeAmount(),
                'overdueDays' => $renewalRequest->getOverdueDays(),
            ];

            if ($detentionBilling) {
                $responseData['detentionBilling'] = [
                    'id' => $detentionBilling->getId(),
                    'totalAmount' => $detentionBilling->getTotalAmount(),
                    'hasReceipt' => $detentionBilling->getReceiptFilePath() !== null,
                    'receiptUploaded' => $detentionBilling->getPaymentSubmittedAt() !== null,
                ];
            }
        }

        return $this->json([
            'success' => true,
            'data' => $responseData,
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
            if ($validationError = $this->validateEdoPaymentReceiptFile($receiptFile)) {
                return $this->json(['success' => false, 'error' => ['message' => $validationError]], 400);
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
    public function downloadEDO(int $id, Request $request): Response
    {
        $edo = $this->edoRepository->findOneWithRelations($id);

        if (!$edo) {
            return $this->edoDownloadErrorResponse($request, null, 'eDO not found', Response::HTTP_NOT_FOUND);
        }

        $manifestId = $edo->getManifest()->getId();

        // Verify broker owns the manifest associated with eDO
        $user = $this->getUser();
        if ($edo->getManifest()->getBroker()?->getId() !== $user->getId()) {
            return $this->edoDownloadErrorResponse(
                $request,
                $manifestId,
                'You do not have permission to download this eDO',
                Response::HTTP_FORBIDDEN
            );
        }

        // Verify eDO status is RELEASED or EXPIRED (expired eDOs were previously released)
        if ($edo->getStatus()->value !== 'released' && $edo->getStatus()->value !== 'expired') {
            return $this->edoDownloadErrorResponse(
                $request,
                $manifestId,
                'eDO must be released before it can be downloaded',
                Response::HTTP_BAD_REQUEST
            );
        }

        // Retrieve eDO PDF file path
        $pdfPath = $edo->getPdfPath();

        if (!$pdfPath) {
            return $this->edoDownloadErrorResponse(
                $request,
                $manifestId,
                'eDO PDF file not found',
                Response::HTTP_NOT_FOUND
            );
        }

        // Normalize path separators for Windows
        $pdfPath = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        // Try multiple possible locations for the file
        $possiblePaths = [
            $pdfPath, // Try the path as-is (might be absolute for old records)
            $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $pdfPath, // New location
            $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . ltrim($pdfPath, DIRECTORY_SEPARATOR), // Try as relative path
            $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'share' . DIRECTORY_SEPARATOR . $pdfPath, // Old location
            $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . 'edo' . DIRECTORY_SEPARATOR . basename($pdfPath), // Legacy location
        ];

        $fullPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $fullPath = $path;
                break;
            }
        }

        if (!$fullPath) {
            return $this->edoDownloadErrorResponse(
                $request,
                $manifestId,
                'eDO PDF file not found on server',
                Response::HTTP_NOT_FOUND
            );
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

    /**
     * Send notification to accounting staff about new renewal request
     * 
     * @param EDORenewalRequest $renewalRequest
     * @return void
     */
    private function sendRenewalRequestNotificationToAccounting(EDORenewalRequest $renewalRequest): void
    {
        try {
            $expiredEdo = $renewalRequest->getExpiredEdo();
            $shippingLine = $expiredEdo->getShippingLine();

            if (!$shippingLine) {
                $this->logger->warning('Cannot send notification: No shipping line associated with eDO', [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'expired_edo_id' => $expiredEdo->getId()
                ]);
                return;
            }

            // Find all accounting users for this shipping line
            $accountingUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.shippingLineScope = :shippingLine')
                ->andWhere('u.role = :role')
                ->andWhere('u.status = :status')
                ->setParameter('shippingLine', $shippingLine)
                ->setParameter('role', 'ACCOUNTING')
                ->setParameter('status', 'ACTIVE')
                ->getQuery()
                ->getResult();

            foreach ($accountingUsers as $accountingUser) {
                $this->notificationService->createNotification(
                    $accountingUser,
                    'New eDO Renewal Request',
                    sprintf(
                        'Broker %s has requested renewal for expired eDO %s. Detention charges: ₱%s. Please review and generate billing.',
                        $renewalRequest->getRequestedBy()->getEmail(),
                        $expiredEdo->getEdoNumber(),
                        number_format($renewalRequest->getDetentionChargeAmount(), 2)
                    ),
                    'warning',
                    [
                        'renewal_request_id' => $renewalRequest->getId(),
                        'expired_edo_id' => $expiredEdo->getId()
                    ]
                );
            }

            $this->logger->info('Renewal request notifications sent to accounting staff', [
                'renewal_request_id' => $renewalRequest->getId(),
                'recipient_count' => count($accountingUsers)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send renewal request notification to accounting', [
                'renewal_request_id' => $renewalRequest->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    private function edoDownloadErrorResponse(
        Request $request,
        ?int $manifestId,
        string $message,
        int $statusCode
    ): Response {
        if ($request->getPreferredFormat() === 'json') {
            return $this->json([
                'success' => false,
                'error' => [
                    'message' => $message,
                ],
            ], $statusCode);
        }

        $this->addFlash('error', $message);

        if ($manifestId !== null) {
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $manifestId]);
        }

        return $this->redirectToRoute('broker_manifest_list');
    }

    private function validateEdoPaymentReceiptFile(?UploadedFile $file): ?string
    {
        if ($file === null || !$file->isValid()) {
            return 'Payment receipt file is required. Please upload a PDF, JPG, or PNG.';
        }

        if ($file->getSize() <= 0) {
            return 'Payment receipt file is empty. Please upload a valid file.';
        }

        $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array((string) $file->getMimeType(), $allowedMimeTypes, true)) {
            return 'Invalid file type. Please upload a PDF, JPG, or PNG.';
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return 'File size must be less than 5MB.';
        }

        return null;
    }
}

