<?php

namespace App\Controller\Consignee;

use App\Entity\Consignee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/consignee')]
#[IsGranted('ROLE_CONSIGNEE')]
class ConsigneeWelcomeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/welcome-modal/dismiss', name: 'consignee_welcome_modal_dismiss', methods: ['POST'])]
    public function dismissWelcomeModal(): JsonResponse
    {
        /** @var Consignee $user */
        $user = $this->getUser();

        if (!$user->hasSeenWelcomeModal()) {
            $user->setWelcomeModalDismissedAt(new \DateTime());
            $this->entityManager->flush();
        }

        return $this->json(['success' => true]);
    }
}
