<?php

namespace App\Controller\Consignee;

use App\Service\BrokerRelationshipService;
use App\Service\ConsigneeOnboardingGuideService;
use App\Service\ReferralCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/consignee/brokers')]
#[IsGranted('ROLE_CONSIGNEE')]
class ConsigneeBrokerController extends AbstractController
{
    public function __construct(
        private BrokerRelationshipService $brokerRelationshipService,
        private ReferralCodeService $referralCodeService,
        private ConsigneeOnboardingGuideService $consigneeOnboardingGuideService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'consignee_brokers', methods: ['GET'])]
    public function index(): Response
    {
        /** @var \App\Entity\Consignee $user */
        $user = $this->getUser();

        $this->brokerRelationshipService->syncLegacyLinkedBrokerFromRelationships($user);

        $relationships = $this->brokerRelationshipService->getActiveBrokersForConsignee($user);

        $activeBrokers = [];
        $suspendedBrokers = [];

        foreach ($relationships as $relationship) {
            $broker = $relationship->getBroker();

            $manifestCount = $this->entityManager->getRepository(\App\Entity\Manifest::class)
                ->createQueryBuilder('m')
                ->select('COUNT(m.id)')
                ->where('m.consignee = :consignee')
                ->andWhere('m.broker = :broker')
                ->setParameter('consignee', $user)
                ->setParameter('broker', $broker)
                ->getQuery()
                ->getSingleScalarResult();

            $brokerData = [
                'relationship' => $relationship,
                'broker' => $broker,
                'manifestCount' => $manifestCount,
            ];

            if ($broker->getStatus() === \App\Entity\Enum\AccountStatus::DENIED) {
                $suspendedBrokers[] = $brokerData;
            } else {
                $activeBrokers[] = $brokerData;
            }
        }

        $codesWithStats = $this->referralCodeService->getCodesWithStats($user);
        $codeStats = [];

        foreach ($codesWithStats as $result) {
            $code = is_array($result) ? $result[0] : $result;
            $relationshipsForCode = $this->brokerRelationshipService->getRelationshipsByReferralCode($code->getId());
            $codeStats[] = [
                'code' => $code,
                'broker_count' => count($relationshipsForCode),
                'relationships' => $relationshipsForCode,
            ];
        }

        $pendingGuideSteps = $this->consigneeOnboardingGuideService->getReferralCodeSteps($user);

        return $this->render('consignee/brokers/index.html.twig', [
            'activeBrokers' => $activeBrokers,
            'suspendedBrokers' => $suspendedBrokers,
            'totalBrokers' => count($relationships),
            'activeCount' => count($activeBrokers),
            'suspendedCount' => count($suspendedBrokers),
            'codeStats' => $codeStats,
            'show_onboarding_guide' => count($pendingGuideSteps) > 0,
            'onboarding_guide_steps' => $pendingGuideSteps,
            'start_onboarding_guide' => count($pendingGuideSteps) > 0,
            'start_guide_after_welcome' => false,
        ]);
    }
}
