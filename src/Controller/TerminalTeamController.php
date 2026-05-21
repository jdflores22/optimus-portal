<?php

namespace App\Controller;

use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\PreAdviceRequest;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Service\AuditService;
use App\Service\CacheService;
use App\Service\PreAdviceService;
use App\Service\SlotManagementService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/terminal-team')]
#[IsGranted('ROLE_TERMINAL_TEAM')]
class TerminalTeamController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PreAdviceService $preAdviceService,
        private TerminalService $terminalService,
        private SlotManagementService $slotManagementService,
        private AuditService $auditService,
        private CacheService $cacheService
    ) {
    }

    #[Route('/', name: 'app_terminal_team_dashboard')]
    public function dashboard(): Response
    {
        // Debug: Check user info
        $user = $this->getUser();
        file_put_contents('var/log/terminal_team_debug.log', date('Y-m-d H:i:s') . " - Dashboard accessed\n", FILE_APPEND);
        file_put_contents('var/log/terminal_team_debug.log', "User: " . $user->getEmail() . "\n", FILE_APPEND);
        file_put_contents('var/log/terminal_team_debug.log', "User type: " . get_class($user) . "\n", FILE_APPEND);
        file_put_contents('var/log/terminal_team_debug.log', "User roles: " . implode(', ', $user->getRoles()) . "\n", FILE_APPEND);
        file_put_contents('var/log/terminal_team_debug.log', "Has ROLE_TERMINAL_TEAM: " . (in_array('ROLE_TERMINAL_TEAM', $user->getRoles()) ? 'YES' : 'NO') . "\n", FILE_APPEND);
        
        $metrics = $this->calculateDashboardMetrics();
        $recentActivity = $this->getRecentActivity();
        $dwellTimeContainers = $this->getDwellTimeContainers();
        $terminalStats = $this->getContainerYardAllocations();
        $pendingRequests = $this->getPendingPreAdviceRequests();
        
        return $this->render('terminal_team/dashboard.html.twig', [
            'metrics' => $metrics,
            'recent_activity' => $recentActivity,
            'dwell_time_containers' => $dwellTimeContainers,
            'terminal_stats' => $terminalStats,
            'pending_requests' => $pendingRequests,
        ]);
    }

    #[Route('/dwell-time-monitoring', name: 'app_terminal_team_dwell_time_monitoring')]
    public function dwellTimeMonitoring(): Response
    {
        $dwellTimeContainers = $this->getDwellTimeContainers();
        
        return $this->render('terminal_team/dwell_time_monitoring.html.twig', [
            'dwell_time_containers' => $dwellTimeContainers,
        ]);
    }

    #[Route('/pre-advice-requests', name: 'app_terminal_team_pre_advice_requests')]
    public function preAdviceRequests(Request $request): Response
    {
        $status = $request->query->get('status', 'pending');
        $terminalType = $request->query->get('terminal_type');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        
        // Get the current user's shipping line scope
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // If no shipping line is associated, return empty results
        if ($shippingLine === null) {
            return $this->render('terminal_team/pre_advice_requests.html.twig', [
                'requests' => [],
                'current_status' => $status,
                'current_terminal_type' => $terminalType,
                'current_page' => $page,
                'total_pages' => 0,
                'total_count' => 0,
                'terminal_types' => TerminalType::cases(),
                'statuses' => PreAdviceStatus::cases(),
                'no_shipping_line' => true,
            ]);
        }
        
        $qb = $this->entityManager->getRepository(PreAdviceRequest::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.container', 'c')
            ->leftJoin('p.selectedTerminal', 't')
            ->leftJoin('p.trucker', 'tr')
            ->addSelect('c', 't', 'tr')
            ->andWhere('p.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine);
            
        // Filter by status
        if ($status !== 'all') {
            $statusEnum = PreAdviceStatus::tryFrom($status);
            if ($statusEnum) {
                $qb->andWhere('p.status = :status')
                   ->setParameter('status', $statusEnum);
            }
        }
        
        // Filter by terminal type
        if ($terminalType && $terminalType !== 'all') {
            $terminalTypeEnum = TerminalType::tryFrom($terminalType);
            if ($terminalTypeEnum) {
                $qb->andWhere('t.type = :terminalType')
                   ->setParameter('terminalType', $terminalTypeEnum);
            }
        }
        
        $qb->orderBy('p.createdAt', 'DESC')
           ->setFirstResult(($page - 1) * $limit)
           ->setMaxResults($limit);
           
        $requests = $qb->getQuery()->getResult();
        
        // Get total count for pagination
        $totalQb = clone $qb;
        $totalQb->select('COUNT(p.id)')
            ->setFirstResult(0)
            ->setMaxResults(null);
        $totalCount = $totalQb->getQuery()->getSingleScalarResult();
        
        $totalPages = ceil($totalCount / $limit);
        
        return $this->render('terminal_team/pre_advice_requests.html.twig', [
            'requests' => $requests,
            'current_status' => $status,
            'current_terminal_type' => $terminalType,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
            'terminal_types' => TerminalType::cases(),
            'statuses' => PreAdviceStatus::cases(),
            'no_shipping_line' => false,
        ]);
    }

    #[Route('/pre-advice/{id}', name: 'app_terminal_team_pre_advice_detail')]
    public function preAdviceDetail(PreAdviceRequest $preAdviceRequest): Response
    {
        // Debug: Write to a file to confirm controller is reached
        file_put_contents('var/log/pre_advice_debug.log', date('Y-m-d H:i:s') . " - Controller reached\n", FILE_APPEND);
        
        // Debug: Log the current user and pre-advice details
        $user = $this->getUser();
        file_put_contents('var/log/pre_advice_debug.log', "User: " . $user->getEmail() . " (ID: " . $user->getId() . ")\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "User type: " . get_class($user) . "\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "User roles: " . implode(', ', $user->getRoles()) . "\n", FILE_APPEND);
        
        if (method_exists($user, 'getShippingLineScope')) {
            $scope = $user->getShippingLineScope();
            file_put_contents('var/log/pre_advice_debug.log', "User shipping line scope: " . ($scope ? $scope->getId() . ' - ' . $scope->getBrandName() : 'NULL') . "\n", FILE_APPEND);
        }
        
        file_put_contents('var/log/pre_advice_debug.log', "Pre-advice ID: " . $preAdviceRequest->getId() . "\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "Pre-advice shipping line: " . ($preAdviceRequest->getShippingLine() ? $preAdviceRequest->getShippingLine()->getId() . ' - ' . $preAdviceRequest->getShippingLine()->getBrandName() : 'NULL') . "\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "Pre-advice status: " . $preAdviceRequest->getStatus()->value . "\n", FILE_APPEND);
        
        file_put_contents('var/log/pre_advice_debug.log', "About to check access...\n", FILE_APPEND);
        
        // Check if user can view this pre-advice request
        $this->denyAccessUnlessGranted('view', $preAdviceRequest);
        
        file_put_contents('var/log/pre_advice_debug.log', "Access granted!\n", FILE_APPEND);
        
        // Get available slots for the selected terminal if request is pending
        $availableSlots = [];
        if ($preAdviceRequest->getStatus() === PreAdviceStatus::PENDING) {
            $availableSlots = $this->slotManagementService->getAvailableSlots(
                $preAdviceRequest->getSelectedTerminal(),
                new \DateTime(),
                new \DateTime('+30 days')
            );
        }
        
        return $this->render('terminal_team/pre_advice_detail.html.twig', [
            'pre_advice' => $preAdviceRequest,
            'available_slots' => $availableSlots,
        ]);
    }

    #[Route('/pre-advice/{id}/verify', name: 'app_terminal_team_pre_advice_verify', methods: ['POST'])]
    public function verifyPreAdvice(PreAdviceRequest $preAdviceRequest, Request $request): Response
    {
        // Check if user can verify this pre-advice request
        $this->denyAccessUnlessGranted('verify', $preAdviceRequest);
        
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('verify_pre_advice_' . $preAdviceRequest->getId(), $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        // Ensure request is in pending status
        if ($preAdviceRequest->getStatus() !== PreAdviceStatus::PENDING) {
            $this->addFlash('error', 'This pre-advice request cannot be verified in its current status.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        $slotId = $request->request->get('slot_id');
        $verificationNotes = $request->request->get('verification_notes', '');
        
        if (!$slotId) {
            $this->addFlash('error', 'Please select a time slot for the container return.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        try {
            // Find the selected slot
            $slot = $this->entityManager->getRepository(TerminalSlot::class)->find($slotId);
            if (!$slot) {
                $this->addFlash('error', 'Selected time slot not found.');
                return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
            }
            
            // Verify the slot belongs to the selected terminal
            if ($slot->getTerminal()->getId() !== $preAdviceRequest->getSelectedTerminal()->getId()) {
                $this->addFlash('error', 'Selected slot does not belong to the requested terminal.');
                return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
            }
            
            // Check slot availability
            if (!$this->slotManagementService->isSlotAvailable($slot)) {
                $this->addFlash('error', 'Selected time slot is no longer available.');
                return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
            }
            
            // Assign the specific slot first
            $slotAssigned = $this->slotManagementService->assignSlot(
                $preAdviceRequest->getSelectedTerminal(),
                $slot->getDate(),
                $preAdviceRequest
            );
            
            if (!$slotAssigned) {
                $this->addFlash('error', 'Failed to assign the selected time slot.');
                return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
            }
            
            // Verify the pre-advice request (without automatic slot assignment since we already assigned it)
            $this->preAdviceService->verifyPreAdvice(
                $preAdviceRequest,
                $this->getUser(),
                null // Pass null since we already assigned the slot
            );
            
            // Log the verification action
            $this->auditService->logAction(
                $this->getUser(),
                'pre_advice_verified',
                'PreAdviceRequest',
                $preAdviceRequest->getId(),
                [
                    'container_number' => $preAdviceRequest->getContainer()->getContainerNumber(),
                    'terminal' => $preAdviceRequest->getSelectedTerminal()->getName(),
                    'assigned_slot' => $preAdviceRequest->getAssignedSlot()?->getDate()->format('Y-m-d'),
                    'verification_notes' => $verificationNotes
                ]
            );
            
            $this->addFlash('success', 'Pre-advice request has been verified successfully. EDO and QR code have been generated.');
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to verify pre-advice request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
    }

    #[Route('/pre-advice/{id}/reject', name: 'app_terminal_team_pre_advice_reject', methods: ['POST'])]
    public function rejectPreAdvice(PreAdviceRequest $preAdviceRequest, Request $request): Response
    {
        // Check if user can reject this pre-advice request
        $this->denyAccessUnlessGranted('verify', $preAdviceRequest);
        
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reject_pre_advice_' . $preAdviceRequest->getId(), $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        // Ensure request is in pending status
        if ($preAdviceRequest->getStatus() !== PreAdviceStatus::PENDING) {
            $this->addFlash('error', 'This pre-advice request cannot be rejected in its current status.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        $rejectionReason = trim($request->request->get('rejection_reason', ''));
        
        if (empty($rejectionReason)) {
            $this->addFlash('error', 'Please provide a reason for rejecting this pre-advice request.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        try {
            // Reject the pre-advice request
            $this->preAdviceService->rejectPreAdvice(
                $preAdviceRequest,
                $this->getUser(),
                $rejectionReason
            );
            
            // Log the rejection action
            $this->auditService->logAction(
                $this->getUser(),
                'pre_advice_rejected',
                'PreAdviceRequest',
                $preAdviceRequest->getId(),
                [
                    'container_number' => $preAdviceRequest->getContainer()->getContainerNumber(),
                    'terminal' => $preAdviceRequest->getSelectedTerminal()->getName(),
                    'rejection_reason' => $rejectionReason
                ]
            );
            
            $this->addFlash('success', 'Pre-advice request has been rejected. The trucker has been notified.');
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to reject pre-advice request: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
    }

    #[Route('/pre-advice/{id}/photo/{photoId}/verify', name: 'app_terminal_team_photo_verify', methods: ['POST'])]
    public function verifyPhoto(PreAdviceRequest $preAdviceRequest, int $photoId, Request $request): Response
    {
        // Check if user can verify photos for this pre-advice request
        $this->denyAccessUnlessGranted('verify', $preAdviceRequest);
        
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('verify_photo_' . $preAdviceRequest->getId() . '_' . $photoId, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        $photo = null;
        foreach ($preAdviceRequest->getGeotagPhotos() as $geotagPhoto) {
            if ($geotagPhoto->getId() === $photoId) {
                $photo = $geotagPhoto;
                break;
            }
        }
        
        if (!$photo) {
            $this->addFlash('error', 'Photo not found.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        $isVerified = $request->request->get('is_verified') === '1';
        $verificationNotes = trim($request->request->get('verification_notes', ''));
        
        try {
            // Update photo verification status
            $photo->setIsVerified($isVerified);
            $photo->setVerificationNotes($verificationNotes);
            
            $this->entityManager->flush();
            
            // Log the photo verification action
            $this->auditService->logAction(
                $this->getUser(),
                'photo_verified',
                'GeotagPhoto',
                $photo->getId(),
                [
                    'pre_advice_id' => $preAdviceRequest->getId(),
                    'is_verified' => $isVerified,
                    'verification_notes' => $verificationNotes
                ]
            );
            
            $message = $isVerified ? 'Photo has been verified successfully.' : 'Photo has been flagged for review.';
            $this->addFlash('success', $message);
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update photo verification: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
    }

    /**
     * Calculate dashboard metrics for Terminal Team
     */
    private function calculateDashboardMetrics(): array
    {
        // Try to get cached metrics first
        $cachedMetrics = $this->cacheService->getDashboardMetrics('TERMINAL_TEAM');
        if (!empty($cachedMetrics)) {
            return $cachedMetrics;
        }

        // Get the current user's shipping line scope
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();

        // Get terminals allocated to this shipping line
        $allocatedTerminalIds = [];
        if ($shippingLine !== null) {
            $allocations = $this->entityManager->getRepository(\App\Entity\ShippingLineTerminalAllocation::class)
                ->createQueryBuilder('a')
                ->select('IDENTITY(a.terminal)')
                ->where('a.shippingLine = :shippingLine')
                ->setParameter('shippingLine', $shippingLine)
                ->getQuery()
                ->getSingleColumnResult();
            
            $allocatedTerminalIds = array_map('intval', $allocations);
        }

        $metrics = [];
        
        // Get metrics for each terminal type
        foreach (TerminalType::cases() as $terminalType) {
            // Only get terminals that are allocated to this shipping line
            $terminals = [];
            if (!empty($allocatedTerminalIds)) {
                $terminals = $this->entityManager->getRepository(Terminal::class)
                    ->createQueryBuilder('t')
                    ->where('t.type = :terminalType')
                    ->andWhere('t.isActive = :isActive')
                    ->andWhere('t.id IN (:allocatedTerminalIds)')
                    ->setParameter('terminalType', $terminalType)
                    ->setParameter('isActive', true)
                    ->setParameter('allocatedTerminalIds', $allocatedTerminalIds)
                    ->getQuery()
                    ->getResult();
            }
                
            $terminalIds = array_map(fn($t) => $t->getId(), $terminals);
            
            if (empty($terminalIds)) {
                $metrics[$terminalType->value] = [
                    'pending_requests' => 0,
                    'verified_requests' => 0,
                    'available_slots' => 0,
                    'total_capacity' => 0,
                ];
                continue;
            }
            
            // Pending requests count (filtered by shipping line)
            $qb = $this->entityManager->getRepository(PreAdviceRequest::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.selectedTerminal IN (:terminalIds)')
                ->andWhere('p.status = :status')
                ->setParameter('terminalIds', $terminalIds)
                ->setParameter('status', PreAdviceStatus::PENDING);
            
            if ($shippingLine !== null) {
                $qb->andWhere('p.shippingLine = :shippingLine')
                   ->setParameter('shippingLine', $shippingLine);
            }
            
            $pendingCount = $qb->getQuery()->getSingleScalarResult();
                
            // Verified requests count (today, filtered by shipping line)
            $today = new \DateTime('today');
            $qb = $this->entityManager->getRepository(PreAdviceRequest::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.selectedTerminal IN (:terminalIds)')
                ->andWhere('p.status = :status')
                ->andWhere('p.verifiedAt >= :today')
                ->setParameter('terminalIds', $terminalIds)
                ->setParameter('status', PreAdviceStatus::VERIFIED)
                ->setParameter('today', $today);
            
            if ($shippingLine !== null) {
                $qb->andWhere('p.shippingLine = :shippingLine')
                   ->setParameter('shippingLine', $shippingLine);
            }
            
            $verifiedCount = $qb->getQuery()->getSingleScalarResult();
                
            // Available slots count (next 7 days) - only for allocated terminals
            $nextWeek = new \DateTime('+7 days');
            $availableSlots = $this->entityManager->getRepository(TerminalSlot::class)
                ->createQueryBuilder('ts')
                ->select('SUM(ts.capacity - ts.assignedCount)')
                ->where('ts.terminal IN (:terminalIds)')
                ->andWhere('ts.date BETWEEN :today AND :nextWeek')
                ->andWhere('ts.status = :status')
                ->setParameter('terminalIds', $terminalIds)
                ->setParameter('today', $today)
                ->setParameter('nextWeek', $nextWeek)
                ->setParameter('status', \App\Entity\Enum\SlotStatus::AVAILABLE)
                ->getQuery()
                ->getSingleScalarResult() ?? 0;
                
            // Total daily capacity (using terminal's daily capacity as fallback)
            $totalCapacity = array_sum(array_map(fn($t) => $t->getDailyCapacity(), $terminals));
            
            $metrics[$terminalType->value] = [
                'pending_requests' => $pendingCount,
                'verified_requests' => $verifiedCount,
                'available_slots' => $availableSlots,
                'total_capacity' => $totalCapacity,
            ];
        }
        
        // Calculate total TEU capacity ALLOCATED to this shipping line only
        $totalTEUCapacity = 0;
        if ($shippingLine !== null) {
            $allocations = $this->entityManager->getRepository(\App\Entity\ShippingLineTerminalAllocation::class)
                ->createQueryBuilder('a')
                ->where('a.shippingLine = :shippingLine')
                ->setParameter('shippingLine', $shippingLine)
                ->getQuery()
                ->getResult();
            
            foreach ($allocations as $allocation) {
                // Calculate TEU from ALLOCATED capacity: 20ft = 1 TEU, 40ft = 2 TEU
                $totalTEUCapacity += $allocation->getCapacity20ft() + ($allocation->getCapacity40ft() * 2);
            }
        }
        
        // Overall metrics
        $metrics['overall'] = [
            'total_pending' => array_sum(array_column($metrics, 'pending_requests')),
            'total_verified_today' => array_sum(array_column($metrics, 'verified_requests')),
            'total_available_slots' => array_sum(array_column($metrics, 'available_slots')),
            'total_capacity' => $totalTEUCapacity, // Use TEU capacity ALLOCATED to this shipping line
        ];
        
        // Cache the calculated metrics for 5 minutes
        $this->cacheService->cacheDashboardMetrics('TERMINAL_TEAM', $metrics, 300);

        return $metrics;
    }

    /**
     * Get recent activity for the dashboard
     */
    private function getRecentActivity(): array
    {
        // Get recent pre-advice requests (last 24 hours)
        $yesterday = new \DateTime('-24 hours');
        
        $recentRequests = $this->entityManager->getRepository(PreAdviceRequest::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.container', 'c')
            ->leftJoin('p.selectedTerminal', 't')
            ->leftJoin('p.trucker', 'tr')
            ->addSelect('c', 't', 'tr')
            ->where('p.createdAt >= :yesterday OR p.verifiedAt >= :yesterday')
            ->setParameter('yesterday', $yesterday)
            ->orderBy('CASE WHEN p.verifiedAt IS NOT NULL THEN p.verifiedAt ELSE p.createdAt END', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
            
        return $recentRequests;
    }

    #[Route('/pre-advice/{id}/edo', name: 'app_terminal_team_edo_download')]
    public function downloadEDO(PreAdviceRequest $preAdviceRequest): Response
    {
        // Check if user can access this pre-advice request
        $this->denyAccessUnlessGranted('view', $preAdviceRequest);
        
        // Ensure EDO exists
        if (!$preAdviceRequest->getEdoNumber()) {
            $this->addFlash('error', 'EDO has not been generated for this pre-advice request.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        // Generate EDO PDF
        $edoContent = $this->generateEDOContent($preAdviceRequest);
        
        // Log the EDO download action
        $this->auditService->logAction(
            $this->getUser(),
            'edo_downloaded',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'edo_number' => $preAdviceRequest->getEdoNumber(),
                'container_number' => $preAdviceRequest->getContainer()->getContainerNumber()
            ]
        );
        
        return new Response(
            $edoContent,
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf(
                    'attachment; filename="EDO_%s_%s.pdf"',
                    $preAdviceRequest->getEdoNumber(),
                    $preAdviceRequest->getContainer()->getContainerNumber()
                )
            ]
        );
    }

    #[Route('/pre-advice/{id}/qr-code', name: 'app_terminal_team_qr_code_download')]
    public function downloadQRCode(PreAdviceRequest $preAdviceRequest): Response
    {
        // Check if user can access this pre-advice request
        $this->denyAccessUnlessGranted('view', $preAdviceRequest);
        
        // Ensure QR code exists
        if (!$preAdviceRequest->getQrCode()) {
            $this->addFlash('error', 'QR code has not been generated for this pre-advice request.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        // Generate QR code image
        $qrCodeImage = $this->generateQRCodeImage($preAdviceRequest);
        
        // Log the QR code download action
        $this->auditService->logAction(
            $this->getUser(),
            'qr_code_downloaded',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'edo_number' => $preAdviceRequest->getEdoNumber(),
                'container_number' => $preAdviceRequest->getContainer()->getContainerNumber()
            ]
        );
        
        return new Response(
            $qrCodeImage,
            200,
            [
                'Content-Type' => 'image/png',
                'Content-Disposition' => sprintf(
                    'attachment; filename="QR_Code_%s_%s.png"',
                    $preAdviceRequest->getEdoNumber(),
                    $preAdviceRequest->getContainer()->getContainerNumber()
                )
            ]
        );
    }

    #[Route('/pre-advice/{id}/print-package', name: 'app_terminal_team_print_package')]
    public function downloadPrintPackage(PreAdviceRequest $preAdviceRequest): Response
    {
        // Check if user can access this pre-advice request
        $this->denyAccessUnlessGranted('view', $preAdviceRequest);
        
        // Ensure EDO and QR code exist
        if (!$preAdviceRequest->getEdoNumber() || !$preAdviceRequest->getQrCode()) {
            $this->addFlash('error', 'EDO and QR code must be generated before downloading the print package.');
            return $this->redirectToRoute('app_terminal_team_pre_advice_detail', ['id' => $preAdviceRequest->getId()]);
        }
        
        // Generate combined print package PDF
        $printPackageContent = $this->generatePrintPackage($preAdviceRequest);
        
        // Log the print package download action
        $this->auditService->logAction(
            $this->getUser(),
            'print_package_downloaded',
            'PreAdviceRequest',
            $preAdviceRequest->getId(),
            [
                'edo_number' => $preAdviceRequest->getEdoNumber(),
                'container_number' => $preAdviceRequest->getContainer()->getContainerNumber()
            ]
        );
        
        return new Response(
            $printPackageContent,
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf(
                    'attachment; filename="Print_Package_%s_%s.pdf"',
                    $preAdviceRequest->getEdoNumber(),
                    $preAdviceRequest->getContainer()->getContainerNumber()
                )
            ]
        );
    }

    /**
     * Generate EDO PDF content
     */
    private function generateEDOContent(PreAdviceRequest $preAdviceRequest): string
    {
        // Create a simple EDO document
        $html = $this->renderView('terminal_team/edo_template.html.twig', [
            'pre_advice' => $preAdviceRequest,
            'generated_at' => new \DateTime(),
        ]);
        
        // For now, return HTML content as PDF placeholder
        // In production, you would use a PDF library like TCPDF or wkhtmltopdf
        return $html;
    }

    /**
     * Generate QR code image
     */
    private function generateQRCodeImage(PreAdviceRequest $preAdviceRequest): string
    {
        // Use the QRCodeService to generate the QR code image
        $qrCodeService = new \App\Service\QRCodeService();
        
        return $qrCodeService->generateQRCodeImage(
            $preAdviceRequest->getQrCode(),
            256 // Size in pixels
        );
    }

    /**
     * Generate combined print package PDF
     */
    private function generatePrintPackage(PreAdviceRequest $preAdviceRequest): string
    {
        // Create a combined document with EDO and QR code
        $html = $this->renderView('terminal_team/print_package_template.html.twig', [
            'pre_advice' => $preAdviceRequest,
            'qr_code_data' => base64_encode($this->generateQRCodeImage($preAdviceRequest)),
            'generated_at' => new \DateTime(),
        ]);
        
        // For now, return HTML content as PDF placeholder
        // In production, you would use a PDF library like TCPDF or wkhtmltopdf
        return $html;
    }
    
    /**
     * Get containers with dwell time >= 60 days for Terminal Team dashboard
     */
    private function getDwellTimeContainers(): array
    {
        // Get the current user's shipping line scope
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // If no shipping line is associated, return empty data structure
        if ($shippingLine === null) {
            return [
                'containers_60_to_89' => [],
                'containers_90_plus' => [],
                'stats' => [
                    'total' => 0,
                    'count_60_to_89' => 0,
                    'count_90_plus' => 0,
                ],
                'no_shipping_line' => true,
            ];
        }
        
        // Get containers with dwell time >= 60 days filtered by shipping line
        $containers = $this->entityManager->getRepository(\App\Entity\Container::class)
            ->createQueryBuilder('c')
            ->where('c.currentDwellTime >= 60')
            ->andWhere('c.terminalArrivalDate IS NOT NULL')
            ->andWhere('c.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine)
            ->orderBy('c.currentDwellTime', 'DESC')
            ->getQuery()
            ->getResult();
        
        $dwellTimeData = [
            'containers_60_to_89' => [],
            'containers_90_plus' => [],
            'stats' => [
                'total' => count($containers),
                'count_60_to_89' => 0,
                'count_90_plus' => 0,
            ],
            'no_shipping_line' => false,
        ];
        
        foreach ($containers as $container) {
            $dwellTime = $container->getCurrentDwellTime();
            $containerData = [
                'id' => $container->getId(),
                'container_number' => $container->getContainerNumber(),
                'size_type' => $container->getContainerSize()->getCode() . ' ' . $container->getContainerType()->getCode(),
                'dwell_time' => $dwellTime,
                'location' => $container->getCurrentLocation() ?? 'Unknown',
                'status' => $container->getStatus()->value,
                'is_paused' => $container->getDwellTimePausedAt() !== null,
                'total_paused_days' => $container->getTotalPausedDays(),
                'terminal_arrival_date' => $container->getTerminalArrivalDate(),
            ];
            
            if ($dwellTime >= 60 && $dwellTime < 90) {
                $dwellTimeData['containers_60_to_89'][] = $containerData;
                $dwellTimeData['stats']['count_60_to_89']++;
            } elseif ($dwellTime >= 90) {
                $dwellTimeData['containers_90_plus'][] = $containerData;
                $dwellTimeData['stats']['count_90_plus']++;
            }
        }
        
        return $dwellTimeData;
    }

    /**
     * Get pending pre-advice requests for dashboard
     */
    private function getPendingPreAdviceRequests(): array
    {
        // Get the current user's shipping line scope
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // If no shipping line is associated, return empty array
        if ($shippingLine === null) {
            return [];
        }
        
        // Get pending pre-advice requests (last 10)
        $pendingRequests = $this->entityManager->getRepository(PreAdviceRequest::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.container', 'c')
            ->leftJoin('p.selectedTerminal', 't')
            ->leftJoin('p.trucker', 'tr')
            ->addSelect('c', 't', 'tr')
            ->where('p.shippingLine = :shippingLine')
            ->andWhere('p.status = :status')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('status', PreAdviceStatus::PENDING)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
            
        return $pendingRequests;
    }

    /**
     * Get Container Yard Allocations for Terminal Team (same as Shipping Admin)
     */
    private function getContainerYardAllocations(): array
    {
        /** @var \App\Entity\StaffUser $currentUser */
        $currentUser = $this->getUser();
        
        // Terminal team uses the same shipping line scope as their admin
        $shippingLine = $currentUser->getShippingLineScope();
        
        // If no shipping line is associated, return empty array
        if ($shippingLine === null) {
            return [];
        }
        
        // Get allocated terminals for this shipping line with terminal and region/city data
        $allocatedTerminals = $this->entityManager->getRepository(\App\Entity\ShippingLineTerminalAllocation::class)
            ->createQueryBuilder('a')
            ->join('a.terminal', 't')
            ->leftJoin('t.region', 'r')
            ->leftJoin('t.city', 'c')
            ->addSelect('t', 'r', 'c')
            ->where('a.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine)
            ->orderBy('r.name', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        // Get terminal statistics
        $terminalStats = [];

        foreach ($allocatedTerminals as $allocation) {
            $terminal = $allocation->getTerminal();
            
            // Get container counts by size and allocation status for this terminal
            $containers20ftAllocated = $this->entityManager->getRepository(\App\Entity\Container::class)
                ->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->join('c.containerSize', 'cs')
                ->where('c.cyAllocation = :allocation')
                ->andWhere('c.status = :atTerminalStatus')
                ->andWhere('c.allocationStatus = :allocatedStatus')
                ->andWhere('cs.code LIKE :size20')
                ->setParameter('allocation', $allocation)
                ->setParameter('atTerminalStatus', \App\Entity\Enum\ContainerStatus::AT_TERMINAL)
                ->setParameter('allocatedStatus', \App\Entity\Enum\AllocationStatus::ALLOCATED)
                ->setParameter('size20', '20%')
                ->getQuery()
                ->getSingleScalarResult();

            $containers40ftAllocated = $this->entityManager->getRepository(\App\Entity\Container::class)
                ->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->join('c.containerSize', 'cs')
                ->where('c.cyAllocation = :allocation')
                ->andWhere('c.status = :atTerminalStatus')
                ->andWhere('c.allocationStatus = :allocatedStatus')
                ->andWhere('cs.code LIKE :size40')
                ->setParameter('allocation', $allocation)
                ->setParameter('atTerminalStatus', \App\Entity\Enum\ContainerStatus::AT_TERMINAL)
                ->setParameter('allocatedStatus', \App\Entity\Enum\AllocationStatus::ALLOCATED)
                ->setParameter('size40', '40%')
                ->getQuery()
                ->getSingleScalarResult();

            // Get pre-forecast containers
            $containers20ftPreForecast = $this->entityManager->getRepository(\App\Entity\Container::class)
                ->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->join('c.containerSize', 'cs')
                ->leftJoin('c.cyAllocation', 'ca')
                ->leftJoin('ca.terminal', 't')
                ->where('c.shippingLine = :shippingLine')
                ->andWhere('c.allocationStatus = :preForecastStatus')
                ->andWhere('(c.cyAllocation = :allocation OR (t.name = :terminalName OR c.currentLocation LIKE :terminalName))')
                ->andWhere('cs.code LIKE :size20')
                ->setParameter('shippingLine', $shippingLine)
                ->setParameter('allocation', $allocation)
                ->setParameter('terminalName', '%' . $terminal->getName() . '%')
                ->setParameter('preForecastStatus', \App\Entity\Enum\AllocationStatus::PRE_FORECAST)
                ->setParameter('size20', '20%')
                ->getQuery()
                ->getSingleScalarResult();

            $containers40ftPreForecast = $this->entityManager->getRepository(\App\Entity\Container::class)
                ->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->join('c.containerSize', 'cs')
                ->leftJoin('c.cyAllocation', 'ca')
                ->leftJoin('ca.terminal', 't')
                ->where('c.shippingLine = :shippingLine')
                ->andWhere('c.allocationStatus = :preForecastStatus')
                ->andWhere('(c.cyAllocation = :allocation OR (t.name = :terminalName OR c.currentLocation LIKE :terminalName))')
                ->andWhere('cs.code LIKE :size40')
                ->setParameter('shippingLine', $shippingLine)
                ->setParameter('allocation', $allocation)
                ->setParameter('terminalName', '%' . $terminal->getName() . '%')
                ->setParameter('preForecastStatus', \App\Entity\Enum\AllocationStatus::PRE_FORECAST)
                ->setParameter('size40', '40%')
                ->getQuery()
                ->getSingleScalarResult();

            // Calculate available capacity
            $available20ft = $allocation->getCapacity20ft() - $containers20ftAllocated - $containers20ftPreForecast;
            $available40ft = $allocation->getCapacity40ft() - $containers40ftAllocated - $containers40ftPreForecast;

            // Calculate utilization percentages
            $utilization20ft = $allocation->getCapacity20ft() > 0 
                ? min(100, round((($containers20ftAllocated + $containers20ftPreForecast) / $allocation->getCapacity20ft()) * 100, 0))
                : 0;
            
            $utilization40ft = $allocation->getCapacity40ft() > 0 
                ? min(100, round((($containers40ftAllocated + $containers40ftPreForecast) / $allocation->getCapacity40ft()) * 100, 0))
                : 0;

            $terminalStats[] = [
                'terminal' => $terminal,
                'allocation' => $allocation,
                'capacity_20ft' => $allocation->getCapacity20ft(),
                'capacity_40ft' => $allocation->getCapacity40ft(),
                'allocated_20ft' => $containers20ftAllocated,
                'allocated_40ft' => $containers40ftAllocated,
                'pre_forecast_20ft' => $containers20ftPreForecast,
                'pre_forecast_40ft' => $containers40ftPreForecast,
                'available_20ft' => max(0, $available20ft),
                'available_40ft' => max(0, $available40ft),
                'utilization_20ft' => $utilization20ft,
                'utilization_40ft' => $utilization40ft,
            ];
        }

        return $terminalStats;
    }
}