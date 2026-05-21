<?php

namespace App\Controller\Admin;

use App\Entity\ElectronicDeliveryOrder;
use App\Service\EDOReleaseServiceInterface;
use App\Service\NotificationServiceInterface;
use App\Repository\ElectronicDeliveryOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/edo-release')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class EDOReleaseController extends AbstractController
{
    public function __construct(
        private EDOReleaseServiceInterface $edoReleaseService,
        private NotificationServiceInterface $notificationService,
        private ElectronicDeliveryOrderRepository $edoRepository,
        private \App\Service\EDOPaymentServiceInterface $edoPaymentService,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Display eDO release queue with pending eDOs and EDO payment validation
     * 
     * GET /admin/edo-release/queue
     * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 11.1, 14.4
     */
    #[Route('/queue', name: 'admin_edo_release_queue', methods: ['GET'])]
    public function queue(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $activeTab = $request->query->get('tab', 'all'); // Get active tab from query parameter

        // For "verified" tab, query from payments_edo table directly
        if ($activeTab === 'verified') {
            $verifiedPayments = $this->edoPaymentService->getVerifiedEDOPayments();
            
            // Get EDOs for these verified payments (EDOPayment has direct relationship with EDO)
            $edoIds = array_map(fn($payment) => $payment->getEdo()?->getId(), $verifiedPayments);
            $edoIds = array_filter($edoIds); // Remove nulls
            
            if (!empty($edoIds)) {
                $qb = $this->edoRepository->createQueryBuilder('edo')
                    ->leftJoin('edo.payments', 'p')
                    ->addSelect('p')
                    ->leftJoin('edo.manifest', 'm')
                    ->addSelect('m')
                    ->leftJoin('m.consignee', 'c')
                    ->addSelect('c')
                    ->leftJoin('m.broker', 'b')
                    ->addSelect('b')
                    ->where('edo.id IN (:edoIds)')
                    ->setParameter('edoIds', $edoIds)
                    ->orderBy('edo.generatedAt', 'DESC');
                
                $allEdos = $qb->getQuery()->getResult();
            } else {
                $allEdos = [];
            }
        } else {
            // For other tabs, get pending EDOs (both PENDING_RELEASE and PENDING_VALIDATION)
            $qb = $this->edoRepository->createQueryBuilder('edo')
                ->leftJoin('edo.payments', 'p')
                ->addSelect('p')
                ->leftJoin('edo.manifest', 'm')
                ->addSelect('m')
                ->leftJoin('m.consignee', 'c')
                ->addSelect('c')
                ->leftJoin('m.broker', 'b')
                ->addSelect('b')
                ->where('edo.status IN (:statuses)')
                ->setParameter('statuses', [
                    \App\Entity\Enum\EDOStatus::PENDING_RELEASE,
                    \App\Entity\Enum\EDOStatus::PENDING_VALIDATION
                ])
                ->orderBy('edo.generatedAt', 'DESC');

            $allEdos = $qb->getQuery()->getResult();
            
            // Filter by tab
            $allEdos = $this->filterEdosByTab($allEdos, $activeTab);
        }
        
        // Get pending EDO payments for validation
        $pendingEdoPayments = $this->edoPaymentService->getPendingEDOAccessPayments();

        // Calculate accurate statistics
        $stats = $this->calculateQueueStatistics();
        
        // Apply pagination
        $total = count($allEdos);
        $offset = ($page - 1) * $perPage;
        $paginatedEdos = array_slice($allEdos, $offset, $perPage);

        return $this->render('admin/edo_release/queue.html.twig', [
            'edos' => $paginatedEdos,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
            'pendingEdoPayments' => $pendingEdoPayments,
            'stats' => $stats,
            'activeTab' => $activeTab,
        ]);
    }

    /**
     * Release an eDO
     * 
     * POST /admin/edo-release/{id}/release
     * Requirements: 4.1, 4.2, 4.3, 4.4, 11.2
     */
    #[Route('/{id}/release', name: 'admin_edo_release_action', methods: ['POST'])]
    public function release(int $id): JsonResponse
    {
        try {
            $admin = $this->getUser();
            
            if (!$admin) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Release the eDO
            $this->edoReleaseService->releaseEDO($id, $admin);

            // Get the updated eDO for notification
            $edo = $this->edoRepository->find($id);
            
            if (!$edo) {
                return $this->json([
                    'success' => false,
                    'message' => 'eDO not found after release'
                ], Response::HTTP_NOT_FOUND);
            }

            // Trigger notifications to Broker and Consignee
            $this->notificationService->notifyEDOReleased($edo);

            return $this->json([
                'success' => true,
                'message' => 'eDO released successfully',
                'data' => [
                    'id' => $edo->getId(),
                    'edoNumber' => $edo->getEdoNumber(),
                    'status' => $edo->getStatus()->value,
                    'releasedAt' => $edo->getReleasedAt()?->format('Y-m-d H:i:s'),
                    'releasedBy' => $edo->getReleasedBy()?->getEmail(),
                ]
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while releasing the eDO'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reject an eDO release
     * 
     * POST /admin/edo-release/{id}/reject
     * Requirements: 5.1, 5.2, 5.3, 5.4, 11.2
     */
    #[Route('/{id}/reject', name: 'admin_edo_reject_action', methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        try {
            $admin = $this->getUser();
            
            if (!$admin) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Get rejection reason from request
            $data = json_decode($request->getContent(), true);
            $reason = $data['reason'] ?? '';

            if (empty(trim($reason))) {
                return $this->json([
                    'success' => false,
                    'message' => 'Rejection reason is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Reject the eDO
            $this->edoReleaseService->rejectEDO($id, $reason, $admin);

            // Get the updated eDO for notification
            $edo = $this->edoRepository->find($id);
            
            if (!$edo) {
                return $this->json([
                    'success' => false,
                    'message' => 'eDO not found after rejection'
                ], Response::HTTP_NOT_FOUND);
            }

            // Trigger notifications to ACCOUNTING and Broker
            $this->notificationService->notifyEDORejected($edo, $reason);

            return $this->json([
                'success' => true,
                'message' => 'eDO rejected successfully',
                'data' => [
                    'id' => $edo->getId(),
                    'edoNumber' => $edo->getEdoNumber(),
                    'status' => $edo->getStatus()->value,
                    'rejectionReason' => $edo->getRejectionReason(),
                ]
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while rejecting the eDO'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get eDO release history
     * 
     * GET /admin/edo-release/{id}/history
     * Requirements: 12.5
     */
    #[Route('/{id}/history', name: 'admin_edo_release_history', methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        try {
            $history = $this->edoReleaseService->getEDOReleaseHistory($id);

            $historyData = array_map(function ($entry) {
                return [
                    'id' => $entry->getId(),
                    'fromStatus' => $entry->getFromStatus()->value,
                    'toStatus' => $entry->getToStatus()->value,
                    'actor' => [
                        'id' => $entry->getActor()->getId(),
                        'email' => $entry->getActor()->getEmail(),
                        'role' => $entry->getActor()->getRole()->value,
                    ],
                    'rejectionReason' => $entry->getRejectionReason(),
                    'createdAt' => $entry->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }, $history);

            return $this->json([
                'success' => true,
                'data' => $historyData
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while retrieving eDO history'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get eDO details including payment information
     * 
     * GET /admin/edo-release/{id}/details
     */
    #[Route('/{id}/details', name: 'admin_edo_release_details', methods: ['GET'])]
    public function details(int $id): JsonResponse
    {
        try {
            // Validate ID
            if ($id <= 0) {
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid eDO ID provided'
                ], Response::HTTP_BAD_REQUEST);
            }
            
            $edo = $this->entityManager->getRepository(ElectronicDeliveryOrder::class)->find($id);
            
            if (!$edo) {
                return $this->json([
                    'success' => false,
                    'message' => 'eDO not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $manifest = $edo->getManifest();
            $consignee = $manifest ? $manifest->getConsignee() : null;
            $broker = $manifest ? $manifest->getBroker() : null;
            
            $edoData = [
                'id' => $edo->getId(),
                'edoNumber' => $edo->getEdoNumber(),
                'status' => $edo->getStatus()->value,
                'generatedAt' => $edo->getGeneratedAt()->format('Y-m-d H:i:s'),
                'releasedAt' => $edo->getReleasedAt() ? $edo->getReleasedAt()->format('Y-m-d H:i:s') : null,
                'manifest' => [
                    'manifestNumber' => $manifest ? $manifest->getManifestNumber() : 'N/A',
                    'consignee' => $consignee 
                        ? ($consignee->getBusinessName() ?? $consignee->getFullName() ?? 'Unknown')
                        : 'Not declared',
                    'broker' => $broker 
                        ? ($broker->getFullName() ?? $broker->getEmail() ?? 'Unknown')
                        : 'None',
                ],
                'payment' => null
            ];

            // Add payment details if exists (use getCurrentPayment() for per-container payments)
            $payment = $edo->getCurrentPayment();
            
            // Fallback: If no container-specific payment, get manifest payment
            if (!$payment && $edo->getManifest()) {
                $manifestPayments = $edo->getManifest()->getPayments();
                if ($manifestPayments && $manifestPayments->count() > 0) {
                    // Get the most recent payment (last in collection)
                    $paymentsArray = $manifestPayments->toArray();
                    $payment = end($paymentsArray);
                }
            }
            
            if ($payment) {
                $submittedBy = $payment->getSubmittedBy();
                $validatedBy = $payment->getValidatedBy();
                
                $edoData['payment'] = [
                    'id' => $payment->getId(),
                    'amount' => $payment->getAmount(),
                    'status' => $payment->getStatus()->value,
                    'submittedBy' => $submittedBy ? ($submittedBy->getFullName() ?? $submittedBy->getEmail()) : 'Unknown',
                    'createdAt' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
                    'validatedAt' => $payment->getValidatedAt() ? $payment->getValidatedAt()->format('Y-m-d H:i:s') : null,
                    'validatedBy' => $validatedBy ? ($validatedBy->getFullName() ?? $validatedBy->getEmail()) : null,
                    'rejectionReason' => $payment->getRejectionReason(),
                    'hasReceipt' => $payment->getReceiptFilePath() !== null,
                    'receiptPath' => $payment->getReceiptFilePath(),
                    'officialReceiptPath' => $payment->getOfficialReceiptPath()
                ];
            }

            return $this->json([
                'success' => true,
                'data' => $edoData
            ]);
        } catch (\Exception $e) {
            // Log the actual error
            error_log('EDO Details Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while retrieving eDO details: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Calculate accurate queue statistics across all pending EDOs
     */
    private function calculateQueueStatistics(): array
    {
        // Get all pending EDOs (both PENDING_RELEASE and PENDING_VALIDATION) with their payment status
        $qb = $this->edoRepository->createQueryBuilder('edo')
            ->leftJoin('edo.payments', 'p')
            ->addSelect('p')
            ->where('edo.status IN (:statuses)')
            ->setParameter('statuses', [
                \App\Entity\Enum\EDOStatus::PENDING_RELEASE,
                \App\Entity\Enum\EDOStatus::PENDING_VALIDATION
            ]);

        $allPendingEdos = $qb->getQuery()->getResult();

        $readyToRelease = 0;
        $awaitingPayment = 0;
        $pendingValidation = 0;

        foreach ($allPendingEdos as $edo) {
            // Use getCurrentPayment() instead of getEdoPayment()
            $payment = $edo->getCurrentPayment();
            
            if (!$payment) {
                // No payment submitted yet
                $awaitingPayment++;
            } elseif ($payment->getStatus()->value === 'verified') {
                // Payment verified, ready to release
                $readyToRelease++;
            } elseif ($payment->getStatus()->value === 'pending_validation') {
                // Payment submitted, waiting for validation
                $pendingValidation++;
            } elseif ($payment->getStatus()->value === 'rejected') {
                // Payment rejected, awaiting resubmission
                $awaitingPayment++;
            }
        }
        
        // Get total verified payments count (including already released EDOs) using the service
        $allVerifiedPayments = $this->edoPaymentService->getVerifiedEDOPayments();
        $totalVerified = count($allVerifiedPayments);

        return [
            'total' => count($allPendingEdos),
            'readyToRelease' => $totalVerified, // Show all verified payments
            'awaitingPayment' => $awaitingPayment,
            'pendingValidation' => $pendingValidation,
        ];
    }

    /**
     * Filter EDOs based on active tab
     */
    private function filterEdosByTab(array $edos, string $tab): array
    {
        if ($tab === 'all') {
            return $edos;
        }

        return array_filter($edos, function ($edo) use ($tab) {
            // Use getCurrentPayment() instead of getEdoPayment() to get the latest payment
            $payment = $edo->getCurrentPayment();

            switch ($tab) {
                case 'pending':
                    // Pending validation: has payment with pending_validation status
                    return $payment && $payment->getStatus()->value === 'pending_validation';
                
                case 'verified':
                    // Verified payments: has payment with verified status
                    return $payment && $payment->getStatus()->value === 'verified';
                
                case 'awaiting':
                    // Awaiting payment: no payment OR rejected payment
                    return !$payment || $payment->getStatus()->value === 'rejected';
                
                default:
                    return true;
            }
        });
    }
}
