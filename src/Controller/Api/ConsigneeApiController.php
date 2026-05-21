<?php

namespace App\Controller\Api;

use App\Entity\Consignee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/consignees', name: 'api_consignees_')]
#[IsGranted('ROLE_SL_STAFF')]
class ConsigneeApiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $consignees = $this->entityManager->getRepository(Consignee::class)
            ->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', 'approved')
            ->orderBy('c.businessName', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($consignees as $consignee) {
            $result[] = [
                'id' => $consignee->getId(),
                'businessName' => $consignee->getBusinessName(),
                'fullName' => $consignee->getFullName(),
                'email' => $consignee->getEmail(),
                'status' => $consignee->getStatus()->value
            ];
        }

        return new JsonResponse(['consignees' => $result]);
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 2) {
            return new JsonResponse(['consignees' => []]);
        }

        $qb = $this->entityManager->createQueryBuilder();
        $consignees = $qb
            ->select('c')
            ->from(Consignee::class, 'c')
            ->where($qb->expr()->orX(
                $qb->expr()->like('LOWER(c.businessName)', ':query'),
                $qb->expr()->like('LOWER(c.email)', ':query')
            ))
            ->setParameter('query', '%' . strtolower($query) . '%')
            ->orderBy('c.businessName', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($consignees as $consignee) {
            $linkedBroker = null;
            if ($consignee->getLinkedBroker()) {
                $linkedBroker = [
                    'id' => $consignee->getLinkedBroker()->getId(),
                    'fullName' => $consignee->getLinkedBroker()->getFullName(),
                    'status' => $consignee->getLinkedBroker()->getStatus()->value
                ];
            }

            $result[] = [
                'id' => $consignee->getId(),
                'businessName' => $consignee->getBusinessName(),
                'email' => $consignee->getEmail(),
                'status' => $consignee->getStatus()->value,
                'linkedBroker' => $linkedBroker
            ];
        }

        return new JsonResponse(['consignees' => $result]);
    }
}