<?php

namespace App\Controller\Accounting;

use App\Entity\Billing;
use App\Entity\User;
use App\Entity\Enum\RenewalRequestStatus;
use App\Repository\BillingRepository;
use App\Repository\EDORenewalRequestRepository;
use App\Service\EDORenewalServiceInterface;
use App\Service\DetentionChargeServiceInterface;
use App\Service\BillingHistoryServiceInterface;
use App\Service\AuditService;
use App\Service\ActivityLogService;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for Accounting billing operations including detention charge payment verification
 * 
 * Requirements: 9.1, 9.2, 15.4
 */
#[Route('/accounting/billings')]
#[IsGranted('ROLE_ACCOUNTING')]
class AccountingBillingController extends AbstractController
{
    public function __construct(
        private BillingRepository $billingRepository,
        private EDORenewalRequestRepository $renewalRequestRepository,
        private EDORenewalServiceInterface $renewalService,
        private DetentionChargeServiceInterface $detentionChargeService,
        private InAppNotificationService $notificationService,
        private AuditService $auditService,
        private ActivityLogService $activityLogService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Display payment verification form for a billing record
     * 
     * @Route('/{id}/verify-payment', name: 'accounting_billing_verify_payment', methods: ['GET'])
     * Requirements: 9.1, 9.2, 15.4
     */
    #[Route('/{id}/verify-payment', name: 'accounting_billing_verify_payment', methods: ['GET'])]
    public function verifyPaymentForm(int $id, BillingHistoryServiceInterface $billingHistoryService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Fetch billing record by ID
        $billing = $this->billingRepository->find($id);

        if (!$billing) {
            $this->addFlash('error', 'Billing record not found');
            return $this->redirectToRoute('app_accounting_dashboard_new');
        }

        // Validate billing is for detention charges
        if ($billing->getBillingType() !== 'detention') {
            $this->addFlash('error', 'This billing is not for detention charges');
            return $this->redirectToRoute('app_accounting_dashboard_new');
        }

        // Get associated renewal request
        $renewalRequest = $billing->getEdoRenewalRequest();

        if (!$renewalRequest) {
            $this->addFlash('error', 'No renewal request associated with this billing');
            return $this->redirectToRoute('app_accounting_dashboard_new');
        }

        // Check if payment is already verified
        if ($renewalRequest->isPaymentVerified()) {
            $this->addFlash('warning', 'Payment has already been verified');
        }

        // Get billing history and statistics for the renewal request
        $billingHistory = $billingHistoryService->getBillingHistory($renewalRequest);
        $billingStatistics = $billingHistoryService->getBillingStatistics($renewalRequest);

        // Log page access via ActivityLogService
        $this->activityLogService->logActivity(
            $user,
            'billing_payment_verification_page_accessed',
            'Billing',
            $billing->getId(),
            null,
            null,
            [
                'billing_id' => $billing->getId(),
                'renewal_request_id' => $renewalRequest->getId(),
                'billing_type' => $billing->getBillingType(),
                'total_amount' => $billing->getTotalAmount(),
                'version' => $billing->getVersion()
            ]
        );

        return $this->render('accounting/billing/verify_payment.html.twig', [
            'billing' => $billing,
            'renewalRequest' => $renewalRequest,
            'expiredEdo' => $renewalRequest->getExpiredEdo(),
            'billingHistory' => $billingHistory,
            'billingStatistics' => $billingStatistics
        ]);
    }

    /**
     * Process payment verification for detention charges
     * 
     * @Route('/{id}/verify-payment', name: 'accounting_billing_verify_payment_post', methods: ['POST'])
     * Requirements: 9.1, 9.2, 15.4
     */
    #[Route('/{id}/verify-payment', name: 'accounting_billing_verify_payment_post', methods: ['POST'])]
    public function verifyPayment(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        $isAjax = $request->isXmlHttpRequest();

        try {
            // Fetch billing record by ID
            $billing = $this->billingRepository->find($id);

            if (!$billing) {
                if ($isAjax) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Billing record not found'
                    ], Response::HTTP_NOT_FOUND);
                }
                $this->addFlash('error', 'Billing record not found');
                return $this->redirectToRoute('app_accounting_dashboard_new');
            }

            // Validate billing is for detention charges
            if ($billing->getBillingType() !== 'detention') {
                if ($isAjax) {
                    return $this->json([
                        'success' => false,
                        'message' => 'This billing is not for detention charges'
                    ], Response::HTTP_BAD_REQUEST);
                }
                $this->addFlash('error', 'This billing is not for detention charges');
                return $this->redirectToRoute('app_accounting_dashboard_new');
            }

            // Get associated renewal request
            $renewalRequest = $billing->getEdoRenewalRequest();

            if (!$renewalRequest) {
                if ($isAjax) {
                    return $this->json([
                        'success' => false,
                        'message' => 'No renewal request associated with this billing'
                    ], Response::HTTP_BAD_REQUEST);
                }
                $this->addFlash('error', 'No renewal request associated with this billing');
                return $this->redirectToRoute('app_accounting_dashboard_new');
            }

            // Check if payment is already verified
            if ($renewalRequest->isPaymentVerified()) {
                if ($isAjax) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Payment has already been verified'
                    ], Response::HTTP_BAD_REQUEST);
                }
                $this->addFlash('warning', 'Payment has already been verified');
                return $this->redirectToRoute('accounting_billing_verify_payment', ['id' => $id]);
            }

            // Verify CSRF token
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('verify-payment-' . $id, $submittedToken)) {
                if ($isAjax) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Invalid security token'
                    ], Response::HTTP_FORBIDDEN);
                }
                $this->addFlash('error', 'Invalid security token');
                return $this->redirectToRoute('accounting_billing_verify_payment', ['id' => $id]);
            }

            // Call EDORenewalService::markPaymentVerified
            $this->renewalService->markPaymentVerified($renewalRequest, $user);

            // Send notification to SL staff that payment is verified and eDO can be generated
            $this->sendPaymentVerifiedNotification($renewalRequest);

            // Log payment verification via AuditLogService (already done in service)
            // Additional audit log for the billing record
            $this->auditService->logAction(
                $user,
                'billing_payment_verified',
                'Billing',
                $billing->getId(),
                [
                    'billing_id' => $billing->getId(),
                    'billing_type' => $billing->getBillingType(),
                    'total_amount' => $billing->getTotalAmount(),
                    'renewal_request_id' => $renewalRequest->getId(),
                    'verified_at' => (new \DateTime())->format('Y-m-d H:i:s')
                ]
            );

            // Log user activity via ActivityLogService
            $this->activityLogService->logActivity(
                $user,
                'billing_payment_verified',
                'Billing',
                $billing->getId(),
                ['payment_verified' => false],
                ['payment_verified' => true],
                [
                    'billing_id' => $billing->getId(),
                    'renewal_request_id' => $renewalRequest->getId(),
                    'amount' => $billing->getTotalAmount(),
                    'detention_days' => $billing->getDetentionDays()
                ]
            );

            $this->logger->info('Payment verified for detention billing', [
                'billing_id' => $billing->getId(),
                'renewal_request_id' => $renewalRequest->getId(),
                'verified_by' => $user->getEmail(),
                'amount' => $billing->getTotalAmount()
            ]);

            if ($isAjax) {
                return $this->json([
                    'success' => true,
                    'message' => 'Payment verified successfully. SL staff have been notified and can now generate the new eDO.',
                    'redirectUrl' => $this->generateUrl('accounting_billing_detention_pending')
                ]);
            }

            $this->addFlash('success', 'Payment verified successfully. SL staff have been notified and can now generate the new eDO.');
            return $this->redirectToRoute('accounting_billing_verify_payment', ['id' => $id]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to verify payment', [
                'billing_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($isAjax) {
                return $this->json([
                    'success' => false,
                    'message' => 'Failed to verify payment: ' . $e->getMessage()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $this->addFlash('error', 'Failed to verify payment: ' . $e->getMessage());
            return $this->redirectToRoute('accounting_billing_verify_payment', ['id' => $id]);
        }
    }

    /**
     * Send notification to SL staff that payment is verified
     * 
     * @param \App\Entity\EDORenewalRequest $renewalRequest
     * @return void
     */
    private function sendPaymentVerifiedNotification($renewalRequest): void
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

            // Find all SL staff users for this shipping line
            $slStaffUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.shippingLineScope = :shippingLine')
                ->andWhere('u.role = :role')
                ->andWhere('u.status = :status')
                ->setParameter('shippingLine', $shippingLine)
                ->setParameter('role', 'SL_STAFF')
                ->setParameter('status', 'ACTIVE')
                ->getQuery()
                ->getResult();

            foreach ($slStaffUsers as $slStaff) {
                $this->notificationService->createNotification(
                    $slStaff,
                    'Detention Payment Verified',
                    sprintf(
                        'Detention payment for eDO %s has been verified. You can now generate the new eDO.',
                        $expiredEdo->getEdoNumber()
                    ),
                    'success',
                    [
                        'renewal_request_id' => $renewalRequest->getId(),
                        'expired_edo_id' => $expiredEdo->getId()
                    ]
                );
            }

            $this->logger->info('Payment verified notifications sent to SL staff', [
                'renewal_request_id' => $renewalRequest->getId(),
                'recipient_count' => count($slStaffUsers)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send payment verified notification', [
                'renewal_request_id' => $renewalRequest->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate billing for a renewal request
     * 
     * @Route('/renewal-requests/{id}/generate-billing', name: 'accounting_generate_detention_billing', methods: ['POST'])
     */
    #[Route('/renewal-requests/{id}/generate-billing', name: 'accounting_generate_detention_billing', methods: ['POST'])]
    public function generateDetentionBilling(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            // Fetch renewal request
            $renewalRequest = $this->renewalRequestRepository->find($id);

            if (!$renewalRequest) {
                return $this->json([
                    'success' => false,
                    'message' => 'Renewal request not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Verify renewal request is in PENDING_REVIEW status
            if ($renewalRequest->getStatus() !== RenewalRequestStatus::PENDING_REVIEW) {
                return $this->json([
                    'success' => false,
                    'message' => 'Renewal request is not in pending review status'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if billing already exists
            if ($renewalRequest->getDetentionBilling() !== null) {
                return $this->json([
                    'success' => false,
                    'message' => 'Billing already generated for this renewal request'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Verify CSRF token
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('generate-billing-' . $id, $submittedToken)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid security token'
                ], Response::HTTP_FORBIDDEN);
            }

            // Generate billing
            $billing = $this->detentionChargeService->generateDetentionBilling($renewalRequest);

            // Link billing to renewal request
            $renewalRequest->setDetentionBilling($billing);
            
            // Update status to AWAITING_PAYMENT
            $renewalRequest->setStatus(RenewalRequestStatus::AWAITING_PAYMENT);
            
            $this->entityManager->flush();

            // Send notification to broker
            $this->sendBillingGeneratedNotification($renewalRequest, $billing);

            // Log billing generation
            $this->auditService->logAction(
                $user,
                'detention_billing_generated_by_accounting',
                'Billing',
                $billing->getId(),
                [
                    'billing_id' => $billing->getId(),
                    'renewal_request_id' => $renewalRequest->getId(),
                    'generated_by' => $user->getEmail(),
                    'amount' => $billing->getTotalAmount()
                ]
            );

            $this->activityLogService->logActivity(
                $user,
                'detention_billing_generated',
                'Billing',
                $billing->getId(),
                null,
                [
                    'billing_id' => $billing->getId(),
                    'amount' => $billing->getTotalAmount()
                ],
                [
                    'renewal_request_id' => $renewalRequest->getId()
                ]
            );

            $this->logger->info('Detention billing generated by accounting', [
                'billing_id' => $billing->getId(),
                'renewal_request_id' => $renewalRequest->getId(),
                'generated_by' => $user->getEmail()
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Billing generated successfully. Broker has been notified.',
                'data' => [
                    'billing_id' => $billing->getId(),
                    'amount' => $billing->getTotalAmount()
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate detention billing', [
                'renewal_request_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return $this->json([
                'success' => false,
                'message' => 'Failed to generate billing: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send notification to broker that billing has been generated
     */
    private function sendBillingGeneratedNotification(EDORenewalRequest $renewalRequest, Billing $billing): void
    {
        try {
            $broker = $renewalRequest->getRequestedBy();
            $expiredEdo = $renewalRequest->getExpiredEdo();

            $this->notificationService->createNotification(
                $broker,
                'Detention Billing Generated',
                sprintf(
                    'Billing for eDO renewal request (eDO %s) has been generated. Amount: ₱%s. Please proceed with payment.',
                    $expiredEdo->getEdoNumber(),
                    number_format($billing->getTotalAmount(), 2)
                ),
                'warning',
                [
                    'renewal_request_id' => $renewalRequest->getId(),
                    'billing_id' => $billing->getId()
                ]
            );

            $this->logger->info('Billing generated notification sent to broker', [
                'renewal_request_id' => $renewalRequest->getId(),
                'billing_id' => $billing->getId(),
                'broker_email' => $broker->getEmail()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send billing generated notification', [
                'renewal_request_id' => $renewalRequest->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * List all renewal requests pending review (PENDING_REVIEW status)
     * These are requests that need billing to be generated by accounting
     * 
     * @Route('/renewal-requests-pending', name: 'accounting_renewal_requests_pending', methods: ['GET'])
     */
    #[Route('/renewal-requests-pending', name: 'accounting_renewal_requests_pending', methods: ['GET'])]
    public function listRenewalRequestsPending(
        DetentionChargeServiceInterface $detentionService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Get shipping line scope for the accounting user
        $shippingLine = $user->getShippingLineScope();

        if (!$shippingLine) {
            $this->addFlash('error', 'No shipping line scope assigned to your account');
            return $this->redirectToRoute('app_accounting_dashboard_new');
        }

        // Get all renewal requests with PENDING_REVIEW status for this shipping line
        $pendingRequests = $this->renewalRequestRepository->createQueryBuilder('r')
            ->leftJoin('r.expiredEdo', 'e')
            ->leftJoin('e.shippingLine', 's')
            ->where('r.status = :status')
            ->andWhere('s.id = :shippingLineId')
            ->setParameter('status', \App\Entity\Enum\RenewalRequestStatus::PENDING_REVIEW)
            ->setParameter('shippingLineId', $shippingLine->getId())
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Recalculate overdue days and detention charges for each request
        $requestsWithCurrentCharges = [];
        foreach ($pendingRequests as $request) {
            $expiredEdo = $request->getExpiredEdo();
            $returnDate = $request->getEmptyContainerReturnDate();
            $expirationDate = $expiredEdo->getExpiresAt();
            
            // Calculate overdue days from expiration to the RETURN DATE (not current date)
            // This matches what the broker saw when they submitted the request
            if ($returnDate && $expirationDate && $returnDate > $expirationDate) {
                $interval = $returnDate->diff($expirationDate);
                $currentOverdueDays = $interval->days;
            } else {
                $currentOverdueDays = 0;
            }
            
            // Calculate detention charge based on return date
            $currentDetentionCharge = $detentionService->calculateDetentionCharge($currentOverdueDays, $expiredEdo);
            
            $requestsWithCurrentCharges[] = [
                'request' => $request,
                'currentOverdueDays' => $currentOverdueDays,
                'currentDetentionCharge' => $currentDetentionCharge,
            ];
        }

        // Log page access
        $this->activityLogService->logActivity(
            $user,
            'renewal_requests_pending_list_accessed',
            null,
            null,
            null,
            null,
            [
                'pending_count' => count($pendingRequests)
            ]
        );

        return $this->render('accounting/billing/renewal_requests_pending.html.twig', [
            'renewalRequestsData' => $requestsWithCurrentCharges
        ]);
    }

    /**
     * List all detention billings pending payment verification
     * 
     * @Route('/detention-pending', name: 'accounting_billing_detention_pending', methods: ['GET'])
     */
    #[Route('/detention-pending', name: 'accounting_billing_detention_pending', methods: ['GET'])]
    public function listDetentionBillingsPending(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Get all detention billings where payment is not yet verified
        $pendingBillings = $this->billingRepository->createQueryBuilder('b')
            ->leftJoin('b.edoRenewalRequest', 'r')
            ->where('b.billingType = :type')
            ->andWhere('r.paymentVerified = :verified')
            ->setParameter('type', 'detention')
            ->setParameter('verified', false)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Log page access
        $this->activityLogService->logActivity(
            $user,
            'detention_billings_list_accessed',
            null,
            null,
            null,
            null,
            [
                'pending_count' => count($pendingBillings)
            ]
        );

        return $this->render('accounting/billing/detention_pending.html.twig', [
            'billings' => $pendingBillings
        ]);
    }

    /**
     * View detention payment receipt
     * Route: GET /accounting/billings/{id}/receipt
     * Access: ROLE_ACCOUNTING
     */
    #[Route('/{id}/receipt', name: 'accounting_billing_view_receipt', methods: ['GET'])]
    public function viewDetentionReceipt(
        int $id,
        Request $request
    ): Response {
        $billing = $this->billingRepository->find($id);

        if (!$billing) {
            throw $this->createNotFoundException('Billing not found');
        }

        // Verify this is a detention billing
        if ($billing->getBillingType() !== 'detention') {
            throw $this->createNotFoundException('Invalid billing type');
        }

        $receiptPath = $billing->getReceiptFilePath();
        if (!$receiptPath) {
            throw $this->createNotFoundException('Receipt not found');
        }

        // Build full path
        $projectDir = $this->getParameter('kernel.project_dir');
        $fullPath = $projectDir . '/storage/' . ltrim($receiptPath, '/');

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
}
