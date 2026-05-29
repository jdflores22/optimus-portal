<?php

namespace App\Controller\Broker;

use App\Service\ManifestService;
use App\Service\ManifestAuthorizationService;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\WorkflowState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/broker/manifests')]
#[IsGranted('ROLE_BROKER')]
class BrokerManifestController extends AbstractController
{
    public function __construct(
        private ManifestService $manifestService,
        private ManifestAuthorizationService $authorizationService,
        private EntityManagerInterface $entityManager,
        private \App\Service\WorkspaceService $workspaceService
    ) {
    }

    #[Route('', name: 'broker_manifest_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        // Clear entity manager to get fresh data from database
        $this->entityManager->clear();
        
        $user = $this->getUser();
        
        // WORKSPACE FILTER: Get active workspace
        $activeWorkspaceId = $this->workspaceService->getActiveWorkspace();
        if (!$activeWorkspaceId) {
            $this->addFlash('error', 'Please select a workspace first');
            return $this->redirectToRoute('broker_workspace_selector');
        }
        
        // Get rejected payments count for alert banner
        $rejectedPaymentsCount = $this->entityManager->getRepository(\App\Entity\Payment::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.submittedBy = :broker')
            ->andWhere('p.status = :status')
            ->andWhere('p.paymentType = :type')
            ->setParameter('broker', $user)
            ->setParameter('status', \App\Entity\Enum\PaymentStatus::REJECTED)
            ->setParameter('type', \App\Entity\Enum\PaymentType::FINAL_PAYMENT)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Get filter parameters
        $tab = $request->query->get('tab', 'active'); // active, completed, all
        $status = $request->query->get('status');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        // Build query - only show manifests where this broker is explicitly assigned
        // AND the manifest's consignee matches the active workspace
        $qb = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.consignee', 'c')
            ->where('m.broker = :broker')
            ->andWhere('m.consignee = :consignee')
            ->setParameter('broker', $user)
            ->setParameter('consignee', $activeWorkspaceId)
            ->orderBy('m.createdAt', 'DESC');

        // Apply tab filtering
        if ($tab === 'active') {
            // Show only active (not archived) manifests
            $qb->andWhere('m.archivedForBroker = false');
        } elseif ($tab === 'completed') {
            // Show only completed/archived manifests
            $qb->andWhere('m.archivedForBroker = true');
        }
        // 'all' tab shows everything (no additional filter)

        // Apply filters
        if ($status) {
            $qb->andWhere('m.workflowState = :status')
               ->setParameter('status', $status);
        }

        if ($dateFrom) {
            $qb->andWhere('m.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo) {
            $qb->andWhere('m.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        // Count manifests for each tab (filtered by workspace)
        $activeCount = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.broker = :broker')
            ->andWhere('m.consignee = :consignee')
            ->andWhere('m.archivedForBroker = false')
            ->setParameter('broker', $user)
            ->setParameter('consignee', $activeWorkspaceId)
            ->getQuery()
            ->getSingleScalarResult();

        $completedCount = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.broker = :broker')
            ->andWhere('m.consignee = :consignee')
            ->andWhere('m.archivedForBroker = true')
            ->setParameter('broker', $user)
            ->setParameter('consignee', $activeWorkspaceId)
            ->getQuery()
            ->getSingleScalarResult();

        $allCount = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.broker = :broker')
            ->andWhere('m.consignee = :consignee')
            ->setParameter('broker', $user)
            ->setParameter('consignee', $activeWorkspaceId)
            ->getQuery()
            ->getSingleScalarResult();

        // Pagination
        $totalQuery = clone $qb;
        $total = count($totalQuery->getQuery()->getResult());
        $totalPages = ceil($total / $limit);

        $manifests = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Add eDO generation counts for each manifest
        $manifestsWithEdoCounts = [];
        foreach ($manifests as $manifest) {
            $totalContainers = 0;
            $containersWithEdo = 0;
            $edosPaid = 0; // Count of eDOs with verified payment
            
            // Get containers from NOA
            if ($manifest->getNoa() && $manifest->getNoa()->getContainers()) {
                $totalContainers = $manifest->getNoa()->getContainers()->count();
                
                foreach ($manifest->getNoa()->getContainers() as $container) {
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
                            $containersWithEdo++;
                            
                            // Check if this eDO has verified payment (status = RELEASED means payment verified)
                            if ($edo->getStatus()->value === 'released') {
                                $edosPaid++;
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip this container
                    }
                }
            }
            
            $manifestsWithEdoCounts[] = [
                'manifest' => $manifest,
                'total_containers' => $totalContainers,
                'containers_with_edo' => $containersWithEdo,
                'edos_paid' => $edosPaid,
                'edo_progress' => $totalContainers > 0 ? round(($containersWithEdo / $totalContainers) * 100) : 0,
                'all_edos_generated' => $containersWithEdo == $totalContainers && $totalContainers > 0,
                'all_edos_paid' => $edosPaid == $containersWithEdo && $containersWithEdo > 0,
            ];
        }

        return $this->render('broker/manifest/list.html.twig', [
            'manifests' => $manifestsWithEdoCounts,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'currentTab' => $tab,
            'tabCounts' => [
                'active' => $activeCount,
                'completed' => $completedCount,
                'all' => $allCount
            ],
            'filters' => [
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'workflowStates' => WorkflowState::cases(),
            'activeCount' => $activeCount,
            'completedCount' => $completedCount,
            'rejectedPaymentsCount' => $rejectedPaymentsCount,
        ]);
    }

    #[Route('/{id}', name: 'broker_manifest_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        
        // CRITICAL: Check if broker is assigned to this manifest
        if ($manifest->getBroker()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You are not assigned to this manifest');
        }
        
        // Additional authorization check
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            // Show payment requirement page if payment not verified
            $accessPayment = $manifest->getManifestAccessPayment();
            if (!$accessPayment || $accessPayment->getStatus()->value !== 'verified') {
                return $this->redirectToRoute('broker_manifest_payment', ['id' => $id]);
            }
            
            throw $this->createAccessDeniedException('Access denied');
        }

        // Fetch eDO payment status for each container
        $containerPaymentStatus = [];
        if ($manifest->getNoa() && $manifest->getNoa()->getContainers()) {
            foreach ($manifest->getNoa()->getContainers() as $container) {
                try {
                    $containerPaymentStatus[$container->getId()] = $this->getContainerEDOPaymentStatus($container);
                } catch (\Exception $e) {
                    error_log("Error getting container EDO payment status for container " . $container->getId() . ": " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    // Set default status on error
                    $containerPaymentStatus[$container->getId()] = [
                        'has_edo' => false,
                        'edo_number' => null,
                        'edo_id' => null,
                        'edo_status' => null,
                        'pdf_path' => null,
                        'container_number' => $container->getContainerNumber(),
                        'has_payment' => false,
                        'payment_id' => null,
                        'payment_status' => null,
                        'payment_amount' => null,
                        'payment_verified_at' => null,
                        'official_receipt_path' => null,
                    ];
                }
            }
        }

        // Fetch audit logs for this manifest and all related entities (comprehensive)
        $auditLogs = [];
        
        // 1. Get Manifest audit logs
        $manifestLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
            ->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', 'Manifest')
            ->setParameter('entityId', $manifest->getId())
            ->getQuery()
            ->getResult();
        $auditLogs = array_merge($auditLogs, $manifestLogs);
        
        // 2. Get NOA audit logs if NOA exists
        if ($manifest->getNoa()) {
            $noaLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                ->createQueryBuilder('a')
                ->where('a.entityType = :entityType')
                ->andWhere('a.entityId = :entityId')
                ->setParameter('entityType', 'NOA')
                ->setParameter('entityId', $manifest->getNoa()->getId())
                ->getQuery()
                ->getResult();
            $auditLogs = array_merge($auditLogs, $noaLogs);
            
            // 3. Get Container audit logs
            $containers = $manifest->getNoa()->getContainers();
            if ($containers && count($containers) > 0) {
                foreach ($containers as $container) {
                    $containerLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                        ->createQueryBuilder('a')
                        ->where('a.entityType = :entityType')
                        ->andWhere('a.entityId = :entityId')
                        ->setParameter('entityType', 'Container')
                        ->setParameter('entityId', $container->getId())
                        ->getQuery()
                        ->getResult();
                    $auditLogs = array_merge($auditLogs, $containerLogs);
                }
            }
        }
        
        // 4. Get Payment audit logs
        $payments = $manifest->getPayments();
        if ($payments && count($payments) > 0) {
            foreach ($payments as $payment) {
                $paymentLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                    ->createQueryBuilder('a')
                    ->where('a.entityType = :entityType')
                    ->andWhere('a.entityId = :entityId')
                    ->setParameter('entityType', 'Payment')
                    ->setParameter('entityId', $payment->getId())
                    ->getQuery()
                    ->getResult();
                $auditLogs = array_merge($auditLogs, $paymentLogs);
            }
        }
        
        // 5. Get EDO audit logs
        $edos = $manifest->getEdos();
        if ($edos && count($edos) > 0) {
            foreach ($edos as $edo) {
                $edoLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                    ->createQueryBuilder('a')
                    ->where('a.entityType = :entityType')
                    ->andWhere('a.entityId = :entityId')
                    ->setParameter('entityType', 'ElectronicDeliveryOrder')
                    ->setParameter('entityId', $edo->getId())
                    ->getQuery()
                    ->getResult();
                $auditLogs = array_merge($auditLogs, $edoLogs);
            }
        }
        
        // 6. Get EDO Payment audit logs
        $edoPayments = $manifest->getEdoPayments();
        if ($edoPayments && count($edoPayments) > 0) {
            foreach ($edoPayments as $edoPayment) {
                $edoPaymentLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                    ->createQueryBuilder('a')
                    ->where('a.entityType = :entityType')
                    ->andWhere('a.entityId = :entityId')
                    ->setParameter('entityType', 'EDOPayment')
                    ->setParameter('entityId', $edoPayment->getId())
                    ->getQuery()
                    ->getResult();
                $auditLogs = array_merge($auditLogs, $edoPaymentLogs);
            }
        }
        
        // 7. Get Billing audit logs if billing exists
        if ($manifest->getBilling()) {
            $billingLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                ->createQueryBuilder('a')
                ->where('a.entityType = :entityType')
                ->andWhere('a.entityId = :entityId')
                ->setParameter('entityType', 'Billing')
                ->setParameter('entityId', $manifest->getBilling()->getId())
                ->getQuery()
                ->getResult();
            $auditLogs = array_merge($auditLogs, $billingLogs);
        }
        
        // Sort all logs by timestamp descending (newest first)
        usort($auditLogs, function($a, $b) {
            return $b->getTimestamp() <=> $a->getTimestamp();
        });

        // Also fetch activity logs for backward compatibility
        $activityLogs = $this->entityManager->getRepository(\App\Entity\ActivityLog::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', 'Manifest')
            ->setParameter('entityId', $manifest->getId())
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('broker/manifest/detail.html.twig', [
            'manifest' => $manifest,
            'containerPaymentStatus' => $containerPaymentStatus,
            'activityLogs' => $activityLogs,
            'auditLogs' => $auditLogs,
        ]);
    }

    /**
     * Get eDO payment status for a container
     */
    private function getContainerEDOPaymentStatus($container): array
    {
        $status = [
            'has_edo' => false,
            'edo_number' => null,
            'edo_id' => null,
            'edo_status' => null,
            'pdf_path' => null,
            'container_number' => $container->getContainerNumber(),
            'has_payment' => false,
            'payment_id' => null,
            'payment_status' => null,
            'payment_amount' => null,
            'payment_verified_at' => null,
            'official_receipt_path' => null,
        ];

        // Query eDO directly from database
        $edoQuery = $this->entityManager->createQuery(
            'SELECT e FROM App\Entity\ElectronicDeliveryOrder e 
             WHERE e.container = :container 
             AND (e.status = :active OR e.status = :pending OR e.status = :released)
             ORDER BY e.id DESC'
        )
        ->setParameter('container', $container)
        ->setParameter('active', \App\Entity\Enum\EDOStatus::ACTIVE)
        ->setParameter('pending', \App\Entity\Enum\EDOStatus::PENDING_RELEASE)
        ->setParameter('released', \App\Entity\Enum\EDOStatus::RELEASED)
        ->setMaxResults(1);

        try {
            $edo = $edoQuery->getOneOrNullResult();
            
            if ($edo) {
                $status['has_edo'] = true;
                $status['edo_number'] = $edo->getEdoNumber();
                $status['edo_id'] = $edo->getId();
                $status['edo_status'] = $edo->getStatus()->value;
                $status['pdf_path'] = $edo->getPdfPath();

                // Check for payment (get most recent)
                $paymentQuery = $this->entityManager->createQuery(
                    'SELECT p FROM App\Entity\EDOPayment p 
                     WHERE p.edo = :edo 
                     ORDER BY p.id DESC'
                )
                ->setParameter('edo', $edo)
                ->setMaxResults(1);

                try {
                    $payment = $paymentQuery->getOneOrNullResult();
                    
                    if ($payment) {
                        $status['has_payment'] = true;
                        $status['payment_id'] = $payment->getId();
                        $status['payment_status'] = $payment->getStatus()->value;
                        $status['payment_amount'] = $payment->getAmount();
                        $status['payment_verified_at'] = $payment->getValidatedAt();
                        $status['official_receipt_path'] = $payment->getOfficialReceiptPath();
                    }
                } catch (\Exception $e) {
                    // Payment not found
                }
            }
        } catch (\Exception $e) {
            // eDO not found
        }

        return $status;
    }

    #[Route('/{id}/payment', name: 'broker_manifest_payment', methods: ['GET'])]
    public function manifestAccessPayment(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        
        // Check if broker is associated with this manifest
        if ($manifest->getBroker()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Check if payment already verified
        $accessPayment = $manifest->getManifestAccessPayment();
        if ($accessPayment && $accessPayment->getStatus()->value === 'verified') {
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Check for rejected payment
        $rejectedPayment = null;
        if ($accessPayment && $accessPayment->getStatus() === \App\Entity\Enum\PaymentStatus::REJECTED) {
            $rejectedPayment = $accessPayment;
        }

        return $this->render('broker/manifest/payment.html.twig', [
            'manifest' => $manifest,
            'existingPayment' => $accessPayment,
            'rejectedPayment' => $rejectedPayment,
        ]);
    }

    #[Route('/{id}/upload-bl', name: 'broker_manifest_upload_bl', methods: ['GET'])]
    public function uploadBL(int $id): Response
    {
        // Use optimized query that only loads needed relations (10x faster)
        $this->entityManager->clear();
        $manifest = $this->manifestService->getManifestForBLUpload($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Check if BL already uploaded by checking workflow state and file path
        // In bl_generated state, shipping line has created the manifest with BL number
        // but broker still needs to upload their BL file copy
        if ($manifest->getWorkflowState()->value === WorkflowState::BL_UPLOADED->value || 
            ($manifest->getBlFilePath() && $manifest->getWorkflowState()->value !== WorkflowState::BL_GENERATED->value)) {
            $this->addFlash('info', 'BL has already been uploaded for this manifest');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Check workflow state - allow both NOA_GENERATED and BL_GENERATED
        if (!in_array($manifest->getWorkflowState(), [WorkflowState::NOA_GENERATED, WorkflowState::BL_GENERATED])) {
            $this->addFlash('error', 'BL can only be uploaded when NOA has been generated or BL has been generated by shipping line');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        return $this->render('broker/manifest/upload_bl.html.twig', [
            'manifest' => $manifest,
        ]);
    }

    #[Route('/{id}/final-payment', name: 'broker_manifest_final_payment', methods: ['GET'])]
    public function finalPayment(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Check workflow state
        if ($manifest->getWorkflowState() !== WorkflowState::BILLING_GENERATED) {
            $this->addFlash('error', 'Final payment can only be submitted after billing is generated');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Get billing details
        $billing = $manifest->getBilling();
        if (!$billing) {
            $this->addFlash('error', 'Billing not found');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Check for rejected payment
        $rejectedPayment = $this->entityManager->getRepository(\App\Entity\Payment::class)
            ->createQueryBuilder('p')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->andWhere('p.submittedBy = :user')
            ->setParameter('manifest', $manifest)
            ->setParameter('type', \App\Entity\Enum\PaymentType::FINAL_PAYMENT)
            ->setParameter('status', \App\Entity\Enum\PaymentStatus::REJECTED)
            ->setParameter('user', $user)
            ->orderBy('p.validatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->render('broker/manifest/final_payment.html.twig', [
            'manifest' => $manifest,
            'billing' => $billing,
            'rejectedPayment' => $rejectedPayment,
        ]);
    }

    #[Route('/{id}/documents', name: 'broker_manifest_documents', methods: ['GET'])]
    public function documents(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        return $this->render('broker/manifest/documents.html.twig', [
            'manifest' => $manifest,
        ]);
    }

    #[Route('/{id}/edo-payment', name: 'broker_manifest_edo_payment', methods: ['GET'])]
    public function edoAccessPayment(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Get all eDOs for this manifest
        $edos = $manifest->getEdos();

        // Render the eDO payment page with list of eDOs
        return $this->render('broker/manifest/edo_payment.html.twig', [
            'manifest' => $manifest,
            'edos' => $edos,
        ]);
    }

    #[Route('/{id}/{edoNumber}/edo-payment', name: 'broker_manifest_edo_payment_specific', methods: ['GET'])]
    public function edoPaymentSpecific(int $id, string $edoNumber): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        
        // Check if broker is assigned to this manifest
        if ($manifest->getBroker()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You are not assigned to this manifest');
        }
        
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Find the eDO by eDO number
        $edoRepository = $this->entityManager->getRepository(\App\Entity\ElectronicDeliveryOrder::class);
        $edo = $edoRepository->findOneBy(['edoNumber' => $edoNumber]);
        
        if (!$edo) {
            $this->addFlash('error', 'eDO not found');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Verify the eDO belongs to this manifest
        if ($edo->getManifest()->getId() !== $manifest->getId()) {
            $this->addFlash('error', 'eDO does not belong to this manifest');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Check if payment already submitted (pending validation or approved)
        $currentPayment = $edo->getCurrentPayment();
        if ($currentPayment && $currentPayment->getStatus()->value === 'pending_validation') {
            // Redirect to eDO detail page instead of payment page
            return $this->redirectToRoute('broker_edo_detail_page', ['id' => $edo->getId()]);
        }
        
        // If payment was approved/released, also redirect to detail page
        if ($edo->getStatus()->value === 'released') {
            return $this->redirectToRoute('broker_edo_detail_page', ['id' => $edo->getId()]);
        }

        // Get the most recent payment to check for rejection
        $rejectionReason = null;
        $currentPayment = $edo->getCurrentPayment();
        if ($currentPayment && $currentPayment->getStatus()->value === 'rejected') {
            $rejectionReason = $currentPayment->getRejectionReason();
        }

        // Get payment fee configuration (QR code and amount)
        $paymentFeeConfigRepo = $this->entityManager->getRepository(\App\Entity\PaymentFeeConfiguration::class);
        $edoFeeConfig = $paymentFeeConfigRepo->getCurrentFeeByType('edo');
        $qrCodePath = $edoFeeConfig?->getQrCodePath();
        $configuredFeeAmount = $edoFeeConfig?->getAmount() ?? 500.00; // Default to 500 if not configured
        
        // If EDO QR code is not set, fall back to manifest_access QR code
        if (!$qrCodePath) {
            $manifestFeeConfig = $paymentFeeConfigRepo->getCurrentFeeByType('manifest_access');
            $qrCodePath = $manifestFeeConfig?->getQrCodePath();
        }

        // Render the payment page
        return $this->render('broker/edo/payment.html.twig', [
            'edoId' => $edo->getId(),
            'edoNumber' => $edo->getEdoNumber(),
            'containerNumber' => $edo->getContainer()?->getContainerNumber() ?? 'N/A',
            'manifestNumber' => $manifest->getManifestNumber() ?? 'N/A',
            'feeAmount' => $configuredFeeAmount, // Use configured amount from admin panel
            'edoStatus' => $edo->getStatus()->value,
            'rejectionReason' => $rejectionReason,
            'qrCodePath' => $qrCodePath,
            'manifest' => $manifest,
            'edo' => $edo,
            'existingPayment' => $currentPayment,
        ]);
    }

    #[Route('/{id}/noa/download', name: 'broker_manifest_noa_download', methods: ['GET'])]
    public function downloadNoa(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        
        // Verify broker is assigned to this manifest
        if ($manifest->getBroker()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You are not assigned to this manifest');
        }

        // Get the NOA
        $noa = $manifest->getNoa();
        
        if (!$noa) {
            $this->addFlash('error', 'NOA not found for this manifest');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        $pdfPath = $noa->getPdfPath();
        
        if (!$pdfPath) {
            $this->addFlash('error', 'NOA PDF not available');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Try multiple possible locations for the file
        $possiblePaths = [
            $this->getParameter('kernel.project_dir') . '/var/share/' . $pdfPath,
            $this->getParameter('kernel.project_dir') . '/public/uploads/' . $pdfPath,
        ];
        
        $fullPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $fullPath = $path;
                break;
            }
        }
        
        if (!$fullPath) {
            $this->addFlash('error', 'NOA PDF file not found on server');
            return $this->redirectToRoute('broker_manifest_detail', ['id' => $id]);
        }

        // Return file as download
        return $this->file($fullPath, 'NOA_' . $noa->getNoaNumber() . '.pdf');
    }

    #[Route('/{id}/add-container', name: 'broker_manifest_add_container', methods: ['POST'])]
    public function addContainer(
        int $id,
        Request $request
    ): Response {
        $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)->find($id);
        
        if (!$manifest) {
            return $this->json(['success' => false, 'message' => 'Manifest not found'], 404);
        }
        
        // Check if broker owns this manifest
        if ($manifest->getBroker() !== $this->getUser()) {
            return $this->json(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $data = json_decode($request->getContent(), true);
        $containerId = $data['containerId'] ?? null;
        
        if (!$containerId) {
            return $this->json(['success' => false, 'message' => 'Container ID required'], 400);
        }
        
        $container = $this->entityManager->getRepository(\App\Entity\Container::class)->find($containerId);
        
        if (!$container) {
            return $this->json(['success' => false, 'message' => 'Container not found'], 404);
        }
        
        // Verify container belongs to manifest's NOA
        if (!$manifest->getNoa() || $container->getNoa() !== $manifest->getNoa()) {
            return $this->json(['success' => false, 'message' => 'Container does not belong to this manifest\'s NOA'], 400);
        }
        
        // Check if container is already linked to another manifest
        if ($container->getManifest() && $container->getManifest() !== $manifest) {
            return $this->json(['success' => false, 'message' => 'Container already linked to another manifest'], 400);
        }
        
        // Link container to manifest
        $container->setManifest($manifest);
        $this->entityManager->flush();
        
        return $this->json([
            'success' => true,
            'message' => 'Container added to manifest successfully'
        ]);
    }
}
