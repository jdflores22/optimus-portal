<?php

namespace App\Controller;

use App\Entity\AccreditationSubmission;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\UserRole;
use App\Entity\PaymentVerification;
use App\Entity\PreAdviceRequest;
use App\Entity\ShipmentRecord;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\StaffUser;
use App\Entity\TerminalSlot;
use App\Service\AccreditationWorkflowService;
use App\Service\BrokerRelationshipService;
use App\Service\ConsigneeOnboardingGuideService;
use App\Service\SlStaffDashboardLocationService;
use App\Service\WorkspaceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RoleDashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccreditationWorkflowService $accreditationService,
        private WorkspaceService $workspaceService,
        private BrokerRelationshipService $brokerRelationshipService,
        private ConsigneeOnboardingGuideService $consigneeOnboardingGuideService,
        private SlStaffDashboardLocationService $slStaffDashboardLocationService,
    ) {
    }

    #[Route('/consignee/dashboard', name: 'app_consignee_dashboard')]
    #[IsGranted('ROLE_CONSIGNEE')]
    public function consigneeDashboard(Request $request): Response
    {
        /** @var Consignee $user */
        $user = $this->getUser();
        
        // Get shipping line filter from request
        $shippingLineId = $request->query->get('shipping_line');
        
        // Get ALL accreditation submissions for this user (multi-tenant)
        $accreditationSubmissions = $this->entityManager->getRepository(AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.shippingLine', 'sl')
            ->addSelect('sl')
            ->where('a.applicant = :user')
            ->setParameter('user', $user)
            ->orderBy('sl.brandName', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Get approved brokers for this consignee (NEW SYSTEM)
        $approvedBrokers = $this->brokerRelationshipService->getActiveBrokersForConsignee($user);
        
        // Check which brokers are suspended
        $suspendedBrokers = [];
        foreach ($approvedBrokers as $relationship) {
            $broker = $relationship->getBroker();
            if ($broker->getStatus() === \App\Entity\Enum\AccountStatus::DENIED) {
                $suspendedBrokers[] = $broker;
            }
        }
        
        // Get linked broker information (OLD SYSTEM - for backward compatibility)
        $linkedBroker = $user->getLinkedBroker();
        
        // Get recent shipments if broker is linked (OLD SYSTEM)
        $recentShipments = [];
        if ($linkedBroker) {
            $recentShipments = $this->entityManager->getRepository(ShipmentRecord::class)
                ->createQueryBuilder('s')
                ->join('s.authorizedBrokers', 'b')
                ->where('b.id = :brokerId')
                ->setParameter('brokerId', $linkedBroker->getId())
                ->orderBy('s.createdAt', 'DESC')
                ->setMaxResults(5)
                ->getQuery()
                ->getResult();
        }
        
        // Get recent manifests (NEW SYSTEM)
        $manifestQb = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.shippingLine', 'sl')
            ->addSelect('sl')
            ->where('m.consignee = :consignee')
            ->setParameter('consignee', $user);
        
        // Apply shipping line filter if provided
        if ($shippingLineId) {
            $manifestQb->andWhere('m.shippingLine = :shippingLineId')
                ->setParameter('shippingLineId', $shippingLineId);
        }
        
        $recentManifests = $manifestQb->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
        
        // Check which manifests have suspended brokers
        $manifestsWithSuspendedBrokers = [];
        foreach ($recentManifests as $manifest) {
            $broker = $manifest->getBroker();
            if ($broker && $broker->getStatus() === \App\Entity\Enum\AccountStatus::DENIED) {
                $manifestsWithSuspendedBrokers[] = $manifest;
            }
        }
        
        // Calculate manifests needing payment (EDO payment)
        $manifestsNeedingPayment = [];
        foreach ($recentManifests as $manifest) {
            $edo = $manifest->getEdo();
            if ($edo && !$edo->getEdoPayment()) {
                $manifestsNeedingPayment[] = $manifest;
            }
        }
        
        // Get all shipping lines for filter dropdown
        $allShippingLines = $this->entityManager->getRepository(ShippingLine::class)->findAll();
        
        // Count accreditation statuses
        $approvedCount = 0;
        $pendingCount = 0;
        $needsActionCount = 0;
        foreach ($accreditationSubmissions as $submission) {
            if ($submission->getStatus() === AccreditationStatus::APPROVED) {
                $approvedCount++;
            } elseif ($submission->getStatus() === AccreditationStatus::PENDING) {
                $pendingCount++;
            } else {
                $needsActionCount++;
            }
        }
        
        $showWelcomeModal = !$user->hasSeenWelcomeModal();
        $session = $request->getSession();
        if ($session->get('show_consignee_welcome', false)) {
            $showWelcomeModal = true;
            $session->remove('show_consignee_welcome');
        }

        $pendingGuideSteps = $this->consigneeOnboardingGuideService->getDashboardSteps(
            $user,
            count($accreditationSubmissions),
            count($approvedBrokers)
        );

        $showGuideSuccessModal = $this->consigneeOnboardingGuideService->shouldShowGuideSuccessModal($user);

        $response = $this->render('dashboard/consignee.html.twig', [
            'user' => $user,
            'show_welcome_modal' => $showWelcomeModal,
            'show_onboarding_guide' => count($pendingGuideSteps) > 0,
            'onboarding_guide_steps' => $pendingGuideSteps,
            'start_onboarding_guide' => count($pendingGuideSteps) > 0,
            'start_guide_after_welcome' => $showWelcomeModal,
            'show_guide_success_modal' => $showGuideSuccessModal && !$showWelcomeModal,
            'accreditationSubmissions' => $accreditationSubmissions,
            'approvedCount' => $approvedCount,
            'pendingCount' => $pendingCount,
            'needsActionCount' => $needsActionCount,
            'linkedBroker' => $linkedBroker, // OLD SYSTEM - deprecated
            'approvedBrokers' => $approvedBrokers, // NEW SYSTEM
            'suspendedBrokers' => $suspendedBrokers,
            'manifestsWithSuspendedBrokers' => $manifestsWithSuspendedBrokers,
            'recentShipments' => $recentShipments,
            'recentManifests' => $recentManifests,
            'manifestsNeedingPayment' => $manifestsNeedingPayment,
            'allShippingLines' => $allShippingLines,
            'selectedShippingLineId' => $shippingLineId,
        ]);

        // Prevent browser caching
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    #[Route('/broker/dashboard', name: 'app_broker_dashboard')]
    #[IsGranted('ROLE_BROKER')]
    public function brokerDashboard(Request $request): Response
    {
        /** @var Broker $user */
        $user = $this->getUser();
        
        // WORKSPACE INTEGRATION: Check if broker needs to select workspace
        $workspaces = $this->workspaceService->getAvailableWorkspaces($user);
        
        // If no workspaces, show message
        if (empty($workspaces)) {
            return $this->render('dashboard/broker.html.twig', [
                'user' => $user,
                'noWorkspaces' => true,
                'message' => 'You are not linked to any consignees yet. Please contact a consignee to get a referral code or ask to be linked.',
            ]);
        }
        
        // If multiple workspaces and no active workspace, redirect to selector
        $activeWorkspaceId = $this->workspaceService->getActiveWorkspace();
        if (count($workspaces) > 1 && !$activeWorkspaceId) {
            return $this->redirectToRoute('broker_workspace_selector');
        }
        
        // If single workspace and no active workspace, auto-select it
        if (count($workspaces) === 1 && !$activeWorkspaceId) {
            $this->workspaceService->setActiveWorkspace($workspaces[0]['id'], $user);
            $activeWorkspaceId = $workspaces[0]['id'];
        }
        
        // Get active workspace details
        $activeWorkspace = null;
        $activeConsignee = null;
        foreach ($workspaces as $workspace) {
            if ($workspace['id'] === $activeWorkspaceId) {
                $activeWorkspace = $workspace;
                $activeConsignee = $workspace['consignee'];
                break;
            }
        }
        
        // If active workspace is invalid, redirect to selector
        if (!$activeWorkspace) {
            $this->workspaceService->clearActiveWorkspace();
            return $this->redirectToRoute('broker_workspace_selector');
        }
        
        // Get shipping line filter from request
        $shippingLineId = $request->query->get('shipping_line');
        
        // Get accreditation status
        $accreditation = $this->accreditationService->getSubmissionForUser($user);
        
        // Check if broker needs to submit accreditation
        $needsAccreditation = !$accreditation || $accreditation->getStatus() !== AccreditationStatus::APPROVED;
        
        // WORKSPACE FILTERED: Get accreditation submissions for ACTIVE consignee only
        $accreditationSubmissions = [];
        $consigneesWithShippingLines = [];
        
        if ($activeConsignee) {
            $consigneesWithShippingLines[$activeConsignee->getId()] = [
                'consignee' => $activeConsignee,
                'accreditations' => []
            ];
            
            $accreditationSubmissions = $this->entityManager->getRepository(AccreditationSubmission::class)
                ->createQueryBuilder('a')
                ->leftJoin('a.shippingLine', 'sl')
                ->addSelect('sl')
                ->where('a.applicant = :consignee')
                ->setParameter('consignee', $activeConsignee)
                ->orderBy('sl.brandName', 'ASC')
                ->getQuery()
                ->getResult();
            
            // Group accreditations by consignee
            foreach ($accreditationSubmissions as $submission) {
                $consigneesWithShippingLines[$activeConsignee->getId()]['accreditations'][] = $submission;
            }
        }
        
        // WORKSPACE FILTERED: Get broker's manifests for ACTIVE workspace only
        $qb = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.shippingLine', 'sl')
            ->leftJoin('m.noa', 'noa')
            ->leftJoin('noa.containers', 'containers')
            ->addSelect('sl', 'noa', 'containers')
            ->where('b.id = :brokerId')
            ->andWhere('m.consignee = :consignee')
            ->setParameter('brokerId', $user->getId())
            ->setParameter('consignee', $activeConsignee);
        
        // Apply shipping line filter if provided
        if ($shippingLineId) {
            $qb->andWhere('m.shippingLine = :shippingLineId')
               ->setParameter('shippingLineId', $shippingLineId);
        }
        
        $manifests = $qb->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        
        // Calculate eDO counts for each manifest
        $manifestEdoCounts = [];
        $manifestsWithEdoData = [];
        foreach ($manifests as $manifest) {
            $containers = $manifest->getContainersLinkedToManifest();
            $totalContainers = $containers->count();
            $edoCount = 0;
            $edosPaid = 0;
            $edosNeedingPaymentCount = 0;
            
            foreach ($containers as $container) {
                // Count eDOs with status: PENDING_RELEASE or RELEASED
                $edoQuery = $this->entityManager->createQuery(
                    'SELECT e FROM App\Entity\ElectronicDeliveryOrder e 
                     WHERE e.container = :container 
                     AND e.status IN (:statuses)
                     ORDER BY e.id DESC'
                )
                ->setParameter('container', $container)
                ->setParameter('statuses', [
                    \App\Entity\Enum\EDOStatus::PENDING_RELEASE,
                    \App\Entity\Enum\EDOStatus::RELEASED
                ])
                ->setMaxResults(1);
                
                try {
                    $edo = $edoQuery->getOneOrNullResult();
                    if ($edo) {
                        $edoCount++;
                        
                        // Check if this eDO has verified payment (status = RELEASED means payment verified)
                        if ($edo->getStatus()->value === 'released') {
                            $edosPaid++;
                        } else {
                            // Check if payment exists but not verified yet
                            $payments = $edo->getPayments();
                            if ($payments->isEmpty()) {
                                $edosNeedingPaymentCount++;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Skip this container
                }
            }
            
            $manifestEdoCounts[$manifest->getId()] = [
                'total' => $totalContainers,
                'edos' => $edoCount
            ];
            
            // Prepare data structure like manifest list
            $manifestsWithEdoData[] = [
                'manifest' => $manifest,
                'total_containers' => $totalContainers,
                'containers_with_edo' => $edoCount,
                'all_edos_generated' => $edoCount == $totalContainers && $totalContainers > 0,
                'edos_paid' => $edosPaid,
                'all_edos_paid' => $edosPaid == $edoCount && $edoCount > 0,
                'edos_needing_payment' => $edosNeedingPaymentCount,
                'edo_progress' => $totalContainers > 0 ? round(($edoCount / $totalContainers) * 100) : 0,
            ];
        }
        
        // Query eDOs directly for payment status categorization
        $edoQb = $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
            ->createQueryBuilder('edo')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('edo.container', 'c')
            ->leftJoin('m.shippingLine', 'sl')
            ->leftJoin('edo.payments', 'p')
            ->addSelect('m', 'c', 'sl')
            ->where('b.id = :brokerId')
            ->andWhere('m.consignee = :consignee')
            ->andWhere('edo.status IN (:statuses)')
            ->setParameter('brokerId', $user->getId())
            ->setParameter('consignee', $activeConsignee)
            ->setParameter('statuses', ['pending_release', 'active']);
        
        // Apply shipping line filter if provided
        if ($shippingLineId) {
            $edoQb->andWhere('m.shippingLine = :shippingLineId')
                  ->setParameter('shippingLineId', $shippingLineId);
        }
        
        $edos = $edoQb->orderBy('edo.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        // Categorize eDOs by payment status
        $edosNeedingPayment = [];
        $edosWithPendingPayment = [];
        $edosWithVerifiedPayment = [];
        
        foreach ($edos as $edo) {
            $payments = $edo->getPayments();
            
            if ($payments->isEmpty()) {
                // No payment submitted yet
                $edosNeedingPayment[] = $edo;
            } else {
                // Check the latest payment status
                $latestPayment = $payments->first();
                if ($latestPayment->getStatus()->value === 'pending_validation') {
                    $edosWithPendingPayment[] = $edo;
                } elseif ($latestPayment->getStatus()->value === 'verified') {
                    $edosWithVerifiedPayment[] = $edo;
                }
            }
        }
        
        // WORKSPACE FILTERED: Get recent shipments for ACTIVE workspace only
        $recentShipments = $this->entityManager->getRepository(ShipmentRecord::class)
            ->createQueryBuilder('s')
            ->join('s.authorizedBrokers', 'b')
            ->where('b.id = :brokerId')
            ->andWhere('s.consignee = :consignee')
            ->setParameter('brokerId', $user->getId())
            ->setParameter('consignee', $activeConsignee)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // WORKSPACE FILTERED: Get claimable shipments for ACTIVE workspace only
        $claimableShipments = [];
        if ($activeConsignee) {
            $claimableShipments = $this->entityManager->getRepository(ShipmentRecord::class)
                ->createQueryBuilder('s')
                ->where('s.consignee = :consignee')
                ->andWhere(':broker NOT MEMBER OF s.authorizedBrokers')
                ->setParameter('consignee', $activeConsignee)
                ->setParameter('broker', $user)
                ->orderBy('s.createdAt', 'DESC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        }
            
        // WORKSPACE FILTERED: Get pending final payments for ACTIVE workspace only
        $paymentQb = $this->entityManager->getRepository(PaymentVerification::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.shipment', 'ps')
            ->where('p.broker = :broker')
            ->andWhere('p.status = :status')
            ->andWhere('ps.consignee = :consignee')
            ->setParameter('broker', $user)
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->setParameter('consignee', $activeConsignee);
        
        // Apply shipping line filter if provided
        if ($shippingLineId) {
            $paymentQb->andWhere('ps.shippingLine = :shippingLineId')
                ->setParameter('shippingLineId', $shippingLineId);
        }
        
        $pendingPayments = $paymentQb->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        // Get all shipping lines for filter dropdown
        $allShippingLines = $this->entityManager->getRepository(ShippingLine::class)->findAll();
        
        $response = $this->render('dashboard/broker.html.twig', [
            'user' => $user,
            'accreditation' => $accreditation,
            'needsAccreditation' => $needsAccreditation,
            'linkedConsignees' => [$activeConsignee], // Only show active consignee
            'consigneesWithShippingLines' => $consigneesWithShippingLines,
            'accreditationSubmissions' => $accreditationSubmissions,
            'manifests' => $manifests,
            'manifestEdoCounts' => $manifestEdoCounts,
            'manifestsWithEdoData' => $manifestsWithEdoData,
            'edosNeedingPayment' => $edosNeedingPayment,
            'edosWithPendingPayment' => $edosWithPendingPayment,
            'edosWithVerifiedPayment' => $edosWithVerifiedPayment,
            'recentShipments' => $recentShipments,
            'claimableShipments' => $claimableShipments,
            'pendingPayments' => $pendingPayments,
            'allShippingLines' => $allShippingLines,
            'selectedShippingLineId' => $shippingLineId,
            // WORKSPACE DATA
            'activeWorkspace' => $activeWorkspace,
            'availableWorkspaces' => $workspaces,
            'workspaceCount' => count($workspaces),
        ]);

        // Prevent browser caching
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    #[Route('/sl-staff/dashboard', name: 'app_sl_staff_dashboard')]
    #[IsGranted('ROLE_SL_STAFF')]
    public function slStaffDashboard(): Response
    {
        // Get manifests ready for eDO generation
        $manifestsReadyForEDO = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.consignee', 'c')
            ->leftJoin('m.shippingLine', 'sl')
            ->leftJoin('m.payments', 'p')
            ->leftJoin('m.noa', 'n')
            ->leftJoin('n.containers', 'containers')
            ->leftJoin('containers.containerSize', 'containerSize')
            ->leftJoin('containers.containerType', 'containerType')
            ->addSelect('b', 'c', 'sl', 'n', 'containers', 'containerSize', 'containerType')
            ->where('m.workflowState = :state')
            ->andWhere('p.paymentType = :paymentType')
            ->andWhere('p.status = :paymentStatus')
            ->andWhere('containers.manifest = m')
            ->setParameter('state', \App\Entity\Enum\WorkflowState::PAYMENT_VERIFIED)
            ->setParameter('paymentType', \App\Entity\Enum\PaymentType::FINAL_PAYMENT)
            ->setParameter('paymentStatus', \App\Entity\Enum\PaymentStatus::VERIFIED)
            ->groupBy('m.id')
            ->having('COUNT(containers.id) > 0')
            ->orderBy('p.validatedAt', 'ASC')
            ->getQuery()
            ->getResult();

        // Get recent eDOs generated (last 20)
        $recentEDOs = $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
            ->createQueryBuilder('e')
            ->leftJoin('e.manifest', 'm')
            ->leftJoin('e.container', 'c')
            ->leftJoin('e.payments', 'ep')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.consignee', 'cons')
            ->addSelect('m', 'c', 'ep', 'b', 'cons')
            ->orderBy('e.generatedAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        
        // Port/Terminal (laden inbound) and CY (empty return) — separated by terminal type
        /** @var StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();

        $portLocationsData = [];
        $cyLocationsData = [];

        if ($shippingLine) {
            $portLocationsData = $this->slStaffDashboardLocationService->buildPortTerminalLocations($shippingLine);
            $cyLocationsData = $this->slStaffDashboardLocationService->buildCyEmptyReturnLocations($shippingLine);
        }

        // Get recent NOAs from the workflow (not old shipments)
        $recentShipments = $this->entityManager->getRepository(\App\Entity\NOA::class)
            ->createQueryBuilder('n')
            ->leftJoin('n.containers', 'c')
            ->addSelect('c')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
            
        // Calculate basic statistics for all NOAs in the system
        $stats = [
            'my_shipments_total' => $this->entityManager->getRepository(\App\Entity\NOA::class)
                ->createQueryBuilder('n')
                ->select('COUNT(n.id)')
                ->getQuery()
                ->getSingleScalarResult(),
            'my_shipments_today' => $this->entityManager->getRepository(\App\Entity\NOA::class)
                ->createQueryBuilder('n')
                ->select('COUNT(n.id)')
                ->where('n.createdAt >= :today')
                ->setParameter('today', new \DateTime('today'))
                ->getQuery()
                ->getSingleScalarResult(),
            'my_shipments_this_week' => $this->entityManager->getRepository(\App\Entity\NOA::class)
                ->createQueryBuilder('n')
                ->select('COUNT(n.id)')
                ->where('n.createdAt >= :thisWeek')
                ->setParameter('thisWeek', new \DateTime('-7 days'))
                ->getQuery()
                ->getSingleScalarResult(),
            'my_shipments_this_month' => $this->entityManager->getRepository(\App\Entity\NOA::class)
                ->createQueryBuilder('n')
                ->select('COUNT(n.id)')
                ->where('n.createdAt >= :thisMonth')
                ->setParameter('thisMonth', new \DateTime('-30 days'))
                ->getQuery()
                ->getSingleScalarResult(),
            'pending_edo_count' => count($manifestsReadyForEDO),
            'total_edos_generated' => $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
                ->createQueryBuilder('e')
                ->select('COUNT(e.id)')
                ->getQuery()
                ->getSingleScalarResult(),
            'edos_pending_payment' => $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
                ->createQueryBuilder('e')
                ->select('COUNT(e.id)')
                ->where('e.status = :status')
                ->setParameter('status', \App\Entity\Enum\EDOStatus::PENDING_RELEASE)
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        // Get all NOA creation trends for the last 30 days
        $myShipmentTrends = [];
        $startDate = new \DateTime('-30 days');
        $endDate = new \DateTime();
        
        $myShipments = $this->entityManager->getRepository(\App\Entity\NOA::class)
            ->createQueryBuilder('n')
            ->select('n.createdAt')
            ->where('n.createdAt >= :startDate')
            ->andWhere('n.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getResult();

        // Group by date in PHP
        $shipmentsByDate = [];
        foreach ($myShipments as $shipment) {
            $date = $shipment['createdAt']->format('Y-m-d');
            if (!isset($shipmentsByDate[$date])) {
                $shipmentsByDate[$date] = 0;
            }
            $shipmentsByDate[$date]++;
        }

        foreach ($shipmentsByDate as $date => $count) {
            $myShipmentTrends[] = ['date' => $date, 'count' => $count];
        }

        // Format data for charts
        $chartData = [
            'myShipmentTrends' => $this->formatTrendData($myShipmentTrends),
        ];
        
        $response = $this->render('dashboard/sl_staff.html.twig', [
            'recentShipments' => $recentShipments,
            'manifestsReadyForEDO' => $manifestsReadyForEDO,
            'recentEDOs' => $recentEDOs,
            'cyLocations' => $cyLocationsData,
            'portLocations' => $portLocationsData,
            'stats' => $stats,
            'chartData' => $chartData,
        ]);

        // Prevent browser caching
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    #[Route('/shipping-admin/dashboard', name: 'app_shipping_admin_dashboard')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function shippingAdminDashboard(): Response
    {
        /** @var StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();
        
        // Get allocated terminals for this shipping lines admin with terminal and region/city data
        $allocatedTerminals = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->createQueryBuilder('a')
            ->join('a.terminal', 't')
            ->leftJoin('t.region', 'r')
            ->leftJoin('t.city', 'c')
            ->addSelect('t', 'r', 'c')
            ->where('a.staffUser = :user')
            ->setParameter('user', $currentUser)
            ->orderBy('r.name', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        // Get comprehensive shipping line statistics
        // Total NOAs for this shipping line (through Manifest)
        $totalNOAs = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.noa)')
            ->where('m.shippingLine = :shippingLine')
            ->andWhere('m.noa IS NOT NULL')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        // Total Manifests for this shipping line
        $totalManifests = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        // Total EDOs for this shipping line
        $totalEDOs = $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
            ->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.manifest', 'm')
            ->where('m.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        // Total Brokers associated with this shipping line (through manifests)
        $totalBrokers = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.broker)')
            ->where('m.shippingLine = :shippingLine')
            ->andWhere('m.broker IS NOT NULL')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        // Total Consignees associated with this shipping line (through manifests)
        $totalConsignees = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.consignee)')
            ->where('m.shippingLine = :shippingLine')
            ->andWhere('m.consignee IS NOT NULL')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        // Total Containers for this shipping line
        $totalContainersAll = $this->entityManager->getRepository(\App\Entity\Container::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.shippingLine = :shippingLine')
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        // Get terminal statistics
        $terminalStats = [];
        $totalContainers = 0;
        $totalCapacity = 0;
        $totalAllocatedCapacity = 0;

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

            // Get pre-forecast containers (not yet at terminal)
            // Query by shipping line and terminal name match instead of cyAllocation
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
                ->setParameter('shippingLine', $currentUser->getShippingLineScope())
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
                ->setParameter('shippingLine', $currentUser->getShippingLineScope())
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

            // Calculate total TEU
            $currentUtilization = ($containers20ftAllocated * 1) + ($containers40ftAllocated * 2);
            $allocatedCapacityTEU = $allocation->getCapacity20ft() + ($allocation->getCapacity40ft() * 2);
            $containerCount = $containers20ftAllocated + $containers40ftAllocated;

            $terminalStats[] = [
                'terminal' => $terminal,
                'allocation' => $allocation,
                'containerCount' => $containerCount,
                'currentUtilization' => $currentUtilization,
                'allocatedCapacity' => $allocatedCapacityTEU,
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
                'utilizationPercentage' => $allocatedCapacityTEU > 0 
                    ? min(100, round(($currentUtilization / $allocatedCapacityTEU) * 100, 0))
                    : 0
            ];

            $totalContainers += $containerCount;
            $totalCapacity += $terminal->getDailyCapacity();
            $totalAllocatedCapacity += $allocatedCapacityTEU;
        }

        // Calculate overall statistics
        $totalPreForecast = 0;
        foreach ($terminalStats as $stat) {
            $totalPreForecast += $stat['pre_forecast_20ft'] + $stat['pre_forecast_40ft'];
        }

        $stats = [
            'total_terminals' => count($allocatedTerminals),
            'total_containers' => $totalContainers,
            'total_capacity' => $totalCapacity,
            'allocated_capacity' => $totalAllocatedCapacity,
            'total_pre_forecast' => $totalPreForecast,
            'utilization_percentage' => $totalAllocatedCapacity > 0 
                ? min(100, round(($totalContainers / $totalAllocatedCapacity) * 100, 0))
                : 0,
            'active_terminals' => count(array_filter($allocatedTerminals, function($allocation) {
                return $allocation->getTerminal()->isActive();
            })),
            // Add comprehensive shipping line stats
            'total_noas' => $totalNOAs,
            'total_manifests' => $totalManifests,
            'total_edos' => $totalEDOs,
            'total_brokers' => $totalBrokers,
            'total_consignees' => $totalConsignees,
            'total_containers_all' => $totalContainersAll,
        ];

        // Get recent activity for allocated terminals
        $terminalIds = array_map(function($allocation) {
            return $allocation->getTerminal()->getId();
        }, $allocatedTerminals);

        $recentActivity = [];
        if (!empty($terminalIds)) {
            $recentActivity = $this->entityManager->getRepository(PreAdviceRequest::class)
                ->createQueryBuilder('p')
                ->join('p.selectedTerminal', 't')
                ->where('t.id IN (:terminalIds)')
                ->setParameter('terminalIds', $terminalIds)
                ->orderBy('p.createdAt', 'DESC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        }

        // Get terminal utilization trends (last 30 days)
        $utilizationTrends = [];
        if (!empty($terminalIds)) {
            $startDate = new \DateTime('-30 days');
            $endDate = new \DateTime();
            
            $utilizationData = $this->entityManager->getRepository(TerminalSlot::class)
                ->createQueryBuilder('ts')
                ->select('ts.date, SUM(ts.assignedCount) as total_occupancy')
                ->where('ts.terminal IN (:terminalIds)')
                ->andWhere('ts.date >= :startDate')
                ->andWhere('ts.date <= :endDate')
                ->setParameter('terminalIds', $terminalIds)
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate)
                ->groupBy('ts.date')
                ->orderBy('ts.date', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($utilizationData as $data) {
                $utilizationTrends[] = [
                    'date' => $data['date']->format('Y-m-d'),
                    'occupancy' => (int)$data['total_occupancy']
                ];
            }
        }

        // Format data for charts
        $chartData = [
            'terminalUtilization' => $this->formatTerminalUtilizationData($terminalStats),
            'utilizationTrends' => $this->formatUtilizationTrends($utilizationTrends),
            'terminalTypes' => $this->formatTerminalTypeDistribution($allocatedTerminals),
            'consigneeImportTrends' => $this->getConsigneeImportTrends($shippingLine),
        ];

        $portLocationsData = [];
        $cyLocationsData = [];
        if ($shippingLine) {
            $portLocationsData = $this->slStaffDashboardLocationService->buildPortTerminalLocations($shippingLine);
            $cyLocationsData = $this->slStaffDashboardLocationService->buildCyEmptyReturnLocations($shippingLine);
        }
        
        $response = $this->render('dashboard/shipping_admin.html.twig', [
            'allocatedTerminals' => $allocatedTerminals,
            'terminalStats' => $terminalStats,
            'recentActivity' => $recentActivity,
            'stats' => $stats,
            'chartData' => $chartData,
            'portLocations' => $portLocationsData,
            'cyLocations' => $cyLocationsData,
        ]);

        // Prevent browser caching
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    #[Route('/shipping-admin/consignees', name: 'app_shipping_admin_consignees')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function consigneeManagement(): Response
    {
        /** @var StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();

        if (!$shippingLine) {
            throw $this->createAccessDeniedException('No shipping line assigned to your account.');
        }

        // Consignees accredited (registered) to this shipping line only
        $consignees = $this->entityManager->getRepository(Consignee::class)
            ->createQueryBuilder('c')
            ->innerJoin(AccreditationSubmission::class, 'a', 'WITH', 'a.applicant = c')
            ->where('a.shippingLine = :shippingLine')
            ->andWhere('a.status = :approved')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('approved', AccreditationStatus::APPROVED)
            ->groupBy('c.id')
            ->orderBy('c.businessName', 'ASC')
            ->getQuery()
            ->getResult();

        $consigneeStats = [];
        foreach ($consignees as $consignee) {
            $consigneeStats[$consignee->getId()] = $this->buildConsigneeStatsForShippingLine($consignee, $shippingLine);
        }

        return $this->render('shipping_admin/consignees.html.twig', [
            'consignees' => $consignees,
            'consigneeStats' => $consigneeStats,
            'shippingLine' => $shippingLine,
        ]);
    }

    #[Route('/shipping-admin/consignees/{id}', name: 'app_shipping_admin_consignee_detail')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function consigneeDetail(int $id): Response
    {
        /** @var StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();

        if (!$shippingLine) {
            throw $this->createAccessDeniedException('No shipping line assigned to your account.');
        }

        $consignee = $this->entityManager->getRepository(Consignee::class)->find($id);

        if (!$consignee) {
            throw $this->createNotFoundException('Consignee not found');
        }

        if (!$this->isConsigneeRegisteredToShippingLine($consignee, $shippingLine)) {
            throw $this->createAccessDeniedException('This consignee is not registered to your shipping line.');
        }

        $noas = $this->entityManager->getRepository(\App\Entity\NOA::class)
            ->createQueryBuilder('n')
            ->innerJoin(\App\Entity\Manifest::class, 'm', 'WITH', 'm.noa = n')
            ->where('n.consignee = :consignee')
            ->andWhere('m.shippingLine = :shippingLine')
            ->setParameter('consignee', $consignee)
            ->setParameter('shippingLine', $shippingLine)
            ->groupBy('n.id')
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $manifests = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->findBy(
                ['consignee' => $consignee, 'shippingLine' => $shippingLine],
                ['createdAt' => 'DESC']
            );

        $stats = [
            'total_noas' => count($noas),
            'total_manifests' => count($manifests),
            'total_containers' => (int) $this->entityManager->getRepository(\App\Entity\Container::class)
                ->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->join('c.manifest', 'm')
                ->where('m.consignee = :consignee')
                ->andWhere('m.shippingLine = :shippingLine')
                ->setParameter('consignee', $consignee)
                ->setParameter('shippingLine', $shippingLine)
                ->getQuery()
                ->getSingleScalarResult(),
            'total_edos' => (int) $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
                ->createQueryBuilder('e')
                ->select('COUNT(e.id)')
                ->join('e.manifest', 'm')
                ->where('m.consignee = :consignee')
                ->andWhere('m.shippingLine = :shippingLine')
                ->setParameter('consignee', $consignee)
                ->setParameter('shippingLine', $shippingLine)
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        return $this->render('shipping_admin/consignee_detail.html.twig', [
            'consignee' => $consignee,
            'linkedBrokers' => $this->getLinkedBrokersForConsignee($consignee, $shippingLine),
            'noas' => $noas,
            'manifests' => $manifests,
            'stats' => $stats,
            'shippingLine' => $shippingLine,
        ]);
    }

    #[Route('/shipping-admin/brokers', name: 'app_shipping_admin_brokers')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function brokerManagement(): Response
    {
        /** @var StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();

        if (!$shippingLine) {
            throw $this->createAccessDeniedException('No shipping line assigned to your account.');
        }

        // Brokers accredited (registered) to this shipping line only
        $brokers = $this->entityManager->getRepository(Broker::class)
            ->createQueryBuilder('b')
            ->innerJoin(AccreditationSubmission::class, 'a', 'WITH', 'a.applicant = b')
            ->where('a.shippingLine = :shippingLine')
            ->andWhere('a.status = :approved')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('approved', AccreditationStatus::APPROVED)
            ->orderBy('b.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        $brokerStats = [];
        foreach ($brokers as $broker) {
            $brokerStats[$broker->getId()] = $this->buildBrokerStatsForShippingLine($broker, $shippingLine);
        }

        return $this->render('shipping_admin/brokers.html.twig', [
            'brokers' => $brokers,
            'brokerStats' => $brokerStats,
            'shippingLine' => $shippingLine,
        ]);
    }

    #[Route('/shipping-admin/brokers/{id}', name: 'app_shipping_admin_broker_detail')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function brokerDetail(int $id): Response
    {
        /** @var StaffUser $currentUser */
        $currentUser = $this->getUser();
        $shippingLine = $currentUser->getShippingLineScope();

        if (!$shippingLine) {
            throw $this->createAccessDeniedException('No shipping line assigned to your account.');
        }

        $broker = $this->entityManager->getRepository(Broker::class)->find($id);

        if (!$broker) {
            throw $this->createNotFoundException('Broker not found');
        }

        if (!$this->isBrokerRegisteredToShippingLine($broker, $shippingLine)) {
            throw $this->createAccessDeniedException('This broker is not registered to your shipping line.');
        }

        $linkedConsignees = $this->getLinkedConsigneesForBroker($broker);

        $manifests = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->findBy(
                ['broker' => $broker, 'shippingLine' => $shippingLine],
                ['createdAt' => 'DESC']
            );

        $edos = $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
            ->createQueryBuilder('e')
            ->join('e.manifest', 'm')
            ->where('m.broker = :broker')
            ->andWhere('m.shippingLine = :shippingLine')
            ->setParameter('broker', $broker)
            ->setParameter('shippingLine', $shippingLine)
            ->orderBy('e.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $stats = [
            'total_consignees' => count($linkedConsignees),
            'total_manifests' => count($manifests),
            'total_edos' => count($edos),
            'total_containers' => $this->entityManager->getRepository(\App\Entity\Container::class)
                ->createQueryBuilder('c')
                ->select('COUNT(c.id)')
                ->join('c.manifest', 'm')
                ->where('m.broker = :broker')
                ->andWhere('m.shippingLine = :shippingLine')
                ->setParameter('broker', $broker)
                ->setParameter('shippingLine', $shippingLine)
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        return $this->render('shipping_admin/broker_detail.html.twig', [
            'broker' => $broker,
            'linkedConsignees' => $linkedConsignees,
            'manifests' => $manifests,
            'edos' => $edos,
            'stats' => $stats,
            'shippingLine' => $shippingLine,
        ]);
    }

    /**
     * @return Broker[]
     */
    private function getLinkedBrokersForConsignee(Consignee $consignee, ?ShippingLine $shippingLine = null): array
    {
        $brokersById = [];

        foreach ($this->brokerRelationshipService->getActiveBrokersForConsignee($consignee) as $relationship) {
            $broker = $relationship->getBroker();
            if ($broker instanceof Broker) {
                $brokersById[$broker->getId()] = $broker;
            }
        }

        $legacyBroker = $consignee->getLinkedBroker();
        if ($legacyBroker instanceof Broker) {
            $brokersById[$legacyBroker->getId()] = $legacyBroker;
        }

        $brokers = array_values($brokersById);

        if ($shippingLine !== null) {
            $brokers = array_values(array_filter(
                $brokers,
                fn (Broker $broker): bool => $this->isBrokerRegisteredToShippingLine($broker, $shippingLine)
            ));
        }

        usort($brokers, static fn (Broker $a, Broker $b): int => strcasecmp(
            $a->getFullName() ?? '',
            $b->getFullName() ?? ''
        ));

        return $brokers;
    }

    /**
     * @return Consignee[]
     */
    private function getLinkedConsigneesForBroker(Broker $broker): array
    {
        $consigneesById = [];

        foreach ($this->brokerRelationshipService->getActiveConsigneesForBroker($broker) as $relationship) {
            $consignee = $relationship->getConsignee();
            if ($consignee instanceof Consignee) {
                $consigneesById[$consignee->getId()] = $consignee;
            }
        }

        foreach ($broker->getLinkedConsignees() as $consignee) {
            $consigneesById[$consignee->getId()] = $consignee;
        }

        $legacyLinked = $this->entityManager->getRepository(Consignee::class)
            ->findBy(['linkedBroker' => $broker]);
        foreach ($legacyLinked as $consignee) {
            $consigneesById[$consignee->getId()] = $consignee;
        }

        $consignees = array_values($consigneesById);
        usort($consignees, static fn (Consignee $a, Consignee $b): int => strcasecmp(
            $a->getBusinessName() ?? '',
            $b->getBusinessName() ?? ''
        ));

        return $consignees;
    }

    private function buildBrokerStatsForShippingLine(Broker $broker, ShippingLine $shippingLine): array
    {
        $linkedConsignees = $this->getLinkedConsigneesForBroker($broker);

        $manifestCount = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->count(['broker' => $broker, 'shippingLine' => $shippingLine]);

        $edoCount = $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class)
            ->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.manifest', 'm')
            ->where('m.broker = :broker')
            ->andWhere('m.shippingLine = :shippingLine')
            ->setParameter('broker', $broker)
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'linked_consignees' => $linkedConsignees,
            'consignee_count' => count($linkedConsignees),
            'manifest_count' => $manifestCount,
            'edo_count' => (int) $edoCount,
        ];
    }

    private function isBrokerRegisteredToShippingLine(Broker $broker, ShippingLine $shippingLine): bool
    {
        $count = (int) $this->entityManager->getRepository(AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.applicant = :broker')
            ->andWhere('a.shippingLine = :shippingLine')
            ->andWhere('a.status = :approved')
            ->setParameter('broker', $broker)
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('approved', AccreditationStatus::APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function isConsigneeRegisteredToShippingLine(Consignee $consignee, ShippingLine $shippingLine): bool
    {
        $count = (int) $this->entityManager->getRepository(AccreditationSubmission::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.applicant = :consignee')
            ->andWhere('a.shippingLine = :shippingLine')
            ->andWhere('a.status = :approved')
            ->setParameter('consignee', $consignee)
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('approved', AccreditationStatus::APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @return array{linked_brokers: Broker[], broker_count: int, noa_count: int, manifest_count: int, container_count: int}
     */
    private function buildConsigneeStatsForShippingLine(Consignee $consignee, ShippingLine $shippingLine): array
    {
        $linkedBrokers = $this->getLinkedBrokersForConsignee($consignee, $shippingLine);

        $noaCount = (int) $this->entityManager->getRepository(\App\Entity\NOA::class)
            ->createQueryBuilder('n')
            ->select('COUNT(DISTINCT n.id)')
            ->innerJoin(\App\Entity\Manifest::class, 'm', 'WITH', 'm.noa = n')
            ->where('n.consignee = :consignee')
            ->andWhere('m.shippingLine = :shippingLine')
            ->setParameter('consignee', $consignee)
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        $manifestCount = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->count(['consignee' => $consignee, 'shippingLine' => $shippingLine]);

        $containerCount = (int) $this->entityManager->getRepository(\App\Entity\Container::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.manifest', 'm')
            ->where('m.consignee = :consignee')
            ->andWhere('m.shippingLine = :shippingLine')
            ->setParameter('consignee', $consignee)
            ->setParameter('shippingLine', $shippingLine)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'linked_brokers' => $linkedBrokers,
            'broker_count' => count($linkedBrokers),
            'noa_count' => $noaCount,
            'manifest_count' => $manifestCount,
            'container_count' => $containerCount,
        ];
    }

    private function formatTerminalUtilizationData(array $terminalStats): array
    {
        $labels = [];
        $series = [];
        
        foreach ($terminalStats as $stat) {
            $labels[] = $stat['terminal']->getName();
            $series[] = $stat['utilizationPercentage'];
        }
        
        return [
            'labels' => $labels,
            'series' => $series
        ];
    }

    private function formatUtilizationTrends(array $utilizationTrends): array
    {
        $data = [];
        foreach ($utilizationTrends as $trend) {
            $data[] = [
                'x' => $trend['date'],
                'y' => $trend['occupancy']
            ];
        }
        return $data;
    }

    private function formatTerminalTypeDistribution(array $allocatedTerminals): array
    {
        $typeCount = [];
        foreach ($allocatedTerminals as $allocation) {
            $type = $allocation->getTerminal()->getType()->value;
            if (!isset($typeCount[$type])) {
                $typeCount[$type] = 0;
            }
            $typeCount[$type]++;
        }
        
        return [
            'labels' => array_keys($typeCount),
            'series' => array_values($typeCount)
        ];
    }

    private function getConsigneeImportTrends(ShippingLine $shippingLine): array
    {
        // Get import trends for the last 12 weeks
        $startDate = new \DateTime('-12 weeks');
        $endDate = new \DateTime();
        
        // Get top 5 consignees by container count
        $topConsignees = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('c.id, c.businessName, COUNT(DISTINCT m.id) as manifestCount')
            ->join('m.consignee', 'c')
            ->where('m.shippingLine = :shippingLine')
            ->andWhere('m.createdAt >= :startDate')
            ->andWhere('m.createdAt <= :endDate')
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('c.id, c.businessName')
            ->orderBy('manifestCount', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        if (empty($topConsignees)) {
            return [
                'categories' => [],
                'series' => []
            ];
        }

        // Generate week labels
        $categories = [];
        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $categories[] = $currentDate->format('M d');
            $currentDate->modify('+1 week');
        }

        // Get weekly data for each consignee
        $series = [];
        foreach ($topConsignees as $consignee) {
            $weeklyData = [];
            $currentDate = clone $startDate;
            
            while ($currentDate <= $endDate) {
                $weekStart = clone $currentDate;
                $weekEnd = (clone $currentDate)->modify('+1 week');
                
                // Count containers for this consignee in this week (using direct manifest relationship)
                $count = $this->entityManager->getRepository(\App\Entity\Container::class)
                    ->createQueryBuilder('c')
                    ->select('COUNT(c.id)')
                    ->join('c.manifest', 'm')
                    ->where('m.consignee = :consigneeId')
                    ->andWhere('m.shippingLine = :shippingLine')
                    ->andWhere('m.createdAt >= :weekStart')
                    ->andWhere('m.createdAt < :weekEnd')
                    ->setParameter('consigneeId', $consignee['id'])
                    ->setParameter('shippingLine', $shippingLine)
                    ->setParameter('weekStart', $weekStart)
                    ->setParameter('weekEnd', $weekEnd)
                    ->getQuery()
                    ->getSingleScalarResult();
                
                $weeklyData[] = (int)$count;
                $currentDate->modify('+1 week');
            }
            
            $series[] = [
                'name' => $consignee['businessName'],
                'data' => $weeklyData
            ];
        }

        return [
            'categories' => $categories,
            'series' => $series
        ];
    }

    private function formatPaymentStatusDistribution(array $data): array
    {
        $formatted = [
            'labels' => [],
            'series' => []
        ];
        
        if (empty($data)) {
            $formatted['labels'] = ['No Data'];
            $formatted['series'] = [0];
            return $formatted;
        }
        
        foreach ($data as $item) {
            $formatted['labels'][] = ucfirst(strtolower($item['status']->value));
            $formatted['series'][] = (int)$item['count'];
        }
        
        return $formatted;
    }

    private function formatTopStaff(array $data): array
    {
        $formatted = [
            'categories' => [],
            'series' => []
        ];
        
        if (empty($data)) {
            $formatted['categories'] = ['No Data'];
            $formatted['series'] = [0];
            return $formatted;
        }
        
        foreach ($data as $item) {
            $formatted['categories'][] = $item['staff_email'];
            $formatted['series'][] = (int)$item['shipment_count'];
        }
        
        return $formatted;
    }

    #[Route('/accounting/dashboard', name: 'app_accounting_dashboard_new')]
    #[IsGranted('ROLE_ACCOUNTING')]
    public function accountingDashboard(): Response
    {
        // Get manifests pending billing generation (bl_uploaded state)
        $pendingBillingManifests = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.consignee', 'c')
            ->leftJoin('m.shippingLine', 'sl')
            ->leftJoin('m.billing', 'bill')
            ->addSelect('b', 'c', 'sl')
            ->where('m.workflowState = :state')
            ->andWhere('bill.id IS NULL')
            ->setParameter('state', \App\Entity\Enum\WorkflowState::BL_UPLOADED)
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        // Get manifests with billing generated (billing_generated state)
        $billingGeneratedManifests = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.consignee', 'c')
            ->leftJoin('m.shippingLine', 'sl')
            ->leftJoin('m.billing', 'bill')
            ->addSelect('b', 'c', 'sl', 'bill')
            ->where('m.workflowState = :state')
            ->andWhere('bill.id IS NOT NULL')
            ->setParameter('state', \App\Entity\Enum\WorkflowState::BILLING_GENERATED)
            ->orderBy('bill.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        
        // Pending final manifest payments (shipping-line accounting workflow)
        $pendingFinalPayments = $this->entityManager->getRepository(\App\Entity\Payment::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.consignee', 'c')
            ->leftJoin('p.shippingLine', 'sl')
            ->addSelect('m', 'b', 'c', 'sl')
            ->where('p.paymentType = :paymentType')
            ->andWhere('p.status = :status')
            ->setParameter('paymentType', \App\Entity\Enum\PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Recently validated final payments by this accountant
        $recentlyValidatedFinalPayments = $this->entityManager->getRepository(\App\Entity\Payment::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('p.shippingLine', 'sl')
            ->addSelect('m', 'b', 'sl')
            ->where('p.paymentType = :paymentType')
            ->andWhere('p.status = :status')
            ->andWhere('p.validatedBy = :user')
            ->setParameter('paymentType', \App\Entity\Enum\PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::VERIFIED)
            ->setParameter('user', $this->getUser())
            ->orderBy('p.validatedAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $paymentRepo = $this->entityManager->getRepository(\App\Entity\Payment::class);
        $finalPaymentType = \App\Entity\Enum\PaymentType::FINAL_PAYMENT;

        $stats = [
            'billing_pending_count' => count($pendingBillingManifests),
            'billing_generated_count' => count($billingGeneratedManifests),
            'final_payment_pending_count' => count($pendingFinalPayments),
            'final_payment_verified_today' => (int) $paymentRepo->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.paymentType = :paymentType')
                ->andWhere('p.validatedBy = :user')
                ->andWhere('p.validatedAt >= :today')
                ->setParameter('paymentType', $finalPaymentType)
                ->setParameter('user', $this->getUser())
                ->setParameter('today', new \DateTime('today'))
                ->getQuery()
                ->getSingleScalarResult(),
            'final_payment_total_verified' => (int) $paymentRepo->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.paymentType = :paymentType')
                ->andWhere('p.status = :status')
                ->setParameter('paymentType', $finalPaymentType)
                ->setParameter('status', PaymentStatus::VERIFIED)
                ->getQuery()
                ->getSingleScalarResult(),
            'final_payment_rejected_count' => (int) $paymentRepo->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.paymentType = :paymentType')
                ->andWhere('p.status = :status')
                ->setParameter('paymentType', $finalPaymentType)
                ->setParameter('status', PaymentStatus::REJECTED)
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        $response = $this->render('dashboard/accounting.html.twig', [
            'pendingBillingManifests' => $pendingBillingManifests,
            'billingGeneratedManifests' => $billingGeneratedManifests,
            'pendingFinalPayments' => $pendingFinalPayments,
            'recentlyValidatedFinalPayments' => $recentlyValidatedFinalPayments,
            'stats' => $stats,
        ]);

        // Prevent browser caching
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    /**
     * Count containers by size (TEU value) for a given allocation and status
     */
    private function countContainersBySize(
        \App\Entity\ShippingLineTerminalAllocation $allocation,
        \App\Entity\Enum\AllocationStatus $status,
        float $teuValue,
        \App\Entity\ShippingLine $shippingLine
    ): int {
        return $this->entityManager->getRepository(\App\Entity\Container::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->leftJoin('c.containerSize', 'cs')
            ->where('c.cyAllocation = :allocation')
            ->andWhere('c.allocationStatus = :status')
            ->andWhere('c.shippingLine = :shippingLine')
            ->andWhere('cs.teuValue = :teuValue')
            ->setParameter('allocation', $allocation)
            ->setParameter('status', $status)
            ->setParameter('shippingLine', $shippingLine)
            ->setParameter('teuValue', $teuValue)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function formatTrendData(array $data): array
    {
        if (empty($data)) {
            return [];
        }
        
        $formatted = [];
        foreach ($data as $item) {
            $formatted[] = [
                'x' => $item['date'],
                'y' => (int)$item['count']
            ];
        }
        return $formatted;
    }

    private function formatStatusDistribution(array $data): array
    {
        $formatted = [
            'labels' => [],
            'series' => []
        ];
        
        if (empty($data)) {
            // Provide default empty data
            $formatted['labels'] = ['No Data'];
            $formatted['series'] = [0];
            return $formatted;
        }
        
        foreach ($data as $item) {
            $formatted['labels'][] = ucfirst(strtolower($item['status']->value));
            $formatted['series'][] = (int)$item['count'];
        }
        
        return $formatted;
    }

    private function formatTopBrokers(array $data): array
    {
        $formatted = [
            'categories' => [],
            'series' => []
        ];
        
        if (empty($data)) {
            // Provide default empty data
            $formatted['categories'] = ['No Data'];
            $formatted['series'] = [0];
            return $formatted;
        }
        
        foreach ($data as $item) {
            $formatted['categories'][] = $item['broker_name'];
            $formatted['series'][] = (int)$item['payment_count'];
        }
        
        return $formatted;
    }

    private function formatMonthlyPerformance(array $data): array
    {
        if (empty($data)) {
            return [];
        }
        
        $formatted = [];
        foreach ($data as $item) {
            $monthName = date('M Y', mktime(0, 0, 0, $item['month'], 1, $item['year']));
            $formatted[] = [
                'x' => $monthName,
                'y' => (int)$item['count']
            ];
        }
        return $formatted;
    }
}