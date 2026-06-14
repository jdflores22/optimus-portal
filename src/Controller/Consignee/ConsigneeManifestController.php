<?php

namespace App\Controller\Consignee;

use App\Entity\Consignee;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\WorkflowState;
use App\Service\BrokerRelationshipService;
use App\Service\ConsigneeOnboardingGuideService;
use App\Service\ManifestAuthorizationService;
use App\Service\ManifestService;
use App\Service\PaymentFeeConfigurationServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/consignee/manifests')]
#[IsGranted('ROLE_CONSIGNEE')]
class ConsigneeManifestController extends AbstractController
{
    public function __construct(
        private ManifestService $manifestService,
        private ManifestAuthorizationService $authorizationService,
        private EntityManagerInterface $entityManager,
        private BrokerRelationshipService $brokerRelationshipService,
        private \App\Service\BrokerTransferService $brokerTransferService,
        private ConsigneeOnboardingGuideService $consigneeOnboardingGuideService,
        private PaymentFeeConfigurationServiceInterface $paymentFeeConfigurationService
    ) {
    }

    #[Route('', name: 'consignee_manifest_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        // Clear entity manager to get fresh data from database
        $this->entityManager->clear();
        
        $user = $this->getUser();
        
        // Get filter parameters
        $status = $request->query->get('status');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $search = $request->query->get('search');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        // Build query - only show manifests where this consignee is declared
        $qb = $this->entityManager->getRepository(\App\Entity\Manifest::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.broker', 'b')
            ->where('m.consignee = :consignee')
            ->setParameter('consignee', $user)
            ->orderBy('m.createdAt', 'DESC');

        // Apply search filter
        if ($search) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('m.manifestNumber', ':search'),
                    $qb->expr()->like('m.blNumber', ':search'),
                    $qb->expr()->like('m.vesselName', ':search'),
                    $qb->expr()->like('b.fullName', ':search')
                )
            )->setParameter('search', '%' . $search . '%');
        }

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

        // Pagination
        $totalQuery = clone $qb;
        $total = count($totalQuery->getQuery()->getResult());
        $totalPages = ceil($total / $limit);

        $manifests = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var Consignee $user */
        $pendingGuideSteps = $this->consigneeOnboardingGuideService->getManifestListSteps($user);

        return $this->render('consignee/manifest/list.html.twig', [
            'manifests' => $manifests,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'filters' => [
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
            'workflowStates' => WorkflowState::cases(),
            'show_onboarding_guide' => count($pendingGuideSteps) > 0,
            'onboarding_guide_steps' => $pendingGuideSteps,
            'start_onboarding_guide' => count($pendingGuideSteps) > 0,
            'start_guide_after_welcome' => false,
        ]);
    }

    #[Route('/{id}', name: 'consignee_manifest_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        // Clear entity manager to get fresh data from database
        $this->entityManager->clear();
        
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            // Show payment requirement page if payment not verified
            $accessPayment = $manifest->getManifestAccessPayment();
            if (!$accessPayment || $accessPayment->getStatus()->value !== 'verified') {
                return $this->redirectToRoute('consignee_manifest_payment', ['id' => $id]);
            }
            
            throw $this->createAccessDeniedException('Access denied');
        }

        // Get available brokers for this consignee
        $availableBrokers = $this->brokerRelationshipService->getActiveBrokersForConsignee($user);
        
        // Filter out suspended brokers
        $availableBrokers = array_filter($availableBrokers, function($relationship) {
            return $relationship->getBroker()->getStatus() !== \App\Entity\Enum\AccountStatus::DENIED;
        });
        
        // Check for pending transfer request
        $pendingTransferRequest = $this->brokerTransferService->getPendingRequestForManifest($manifest);

        return $this->render('consignee/manifest/detail.html.twig', [
            'manifest' => $manifest,
            'availableBrokers' => $availableBrokers,
            'pendingTransferRequest' => $pendingTransferRequest,
        ]);
    }

    #[Route('/{id}/payment', name: 'consignee_manifest_payment', methods: ['GET'])]
    public function manifestAccessPayment(int $id): Response
    {
        $manifest = $this->manifestService->getManifestById($id);
        
        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        $user = $this->getUser();
        
        // Check if consignee is associated with this manifest
        if ($manifest->getConsignee()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Access denied');
        }

        // Check if payment already verified
        $accessPayment = $manifest->getManifestAccessPayment();
        if ($accessPayment && $accessPayment->getStatus()->value === 'verified') {
            return $this->redirectToRoute('consignee_manifest_detail', ['id' => $id]);
        }

        return $this->render('consignee/manifest/payment.html.twig', [
            'manifest' => $manifest,
            'existingPayment' => $accessPayment,
            'manifestAccessFee' => $this->paymentFeeConfigurationService->getCurrentManifestAccessFee(),
        ]);
    }

    #[Route('/{id}/documents', name: 'consignee_manifest_documents', methods: ['GET'])]
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
            'manifest_detail_route' => 'consignee_manifest_detail',
        ]);
    }
}
