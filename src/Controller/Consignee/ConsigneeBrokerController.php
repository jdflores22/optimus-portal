<?php

namespace App\Controller\Consignee;

use App\Service\BrokerRelationshipService;
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
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'consignee_brokers', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get all broker relationships for this consignee
        $relationships = $this->brokerRelationshipService->getActiveBrokersForConsignee($user);
        
        // Separate active and suspended brokers
        $activeBrokers = [];
        $suspendedBrokers = [];
        
        foreach ($relationships as $relationship) {
            $broker = $relationship->getBroker();
            
            // Count manifests assigned to this broker
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
                'manifestCount' => $manifestCount
            ];
            
            if ($broker->getStatus() === \App\Entity\Enum\AccountStatus::DENIED) {
                $suspendedBrokers[] = $brokerData;
            } else {
                $activeBrokers[] = $brokerData;
            }
        }
        
        return $this->render('consignee/brokers/index.html.twig', [
            'activeBrokers' => $activeBrokers,
            'suspendedBrokers' => $suspendedBrokers,
            'totalBrokers' => count($relationships),
            'activeCount' => count($activeBrokers),
            'suspendedCount' => count($suspendedBrokers)
        ]);
    }
}
