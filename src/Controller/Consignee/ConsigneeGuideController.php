<?php

namespace App\Controller\Consignee;

use App\Entity\Consignee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/consignee')]
#[IsGranted('ROLE_CONSIGNEE')]
class ConsigneeGuideController extends AbstractController
{
    private const ALLOWED_STEPS = [
        'submit_accreditation',
        'link_brokers',
        'generate_new_code',
        'generate_referral_code_modal',
        'view_manifest_list',
        'guide_tour_success',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/guide/complete-step', name: 'consignee_guide_complete_step', methods: ['POST'])]
    public function completeStep(Request $request): JsonResponse
    {
        $step = (string) $request->request->get('step', '');

        if (!in_array($step, self::ALLOWED_STEPS, true)) {
            return $this->json(['success' => false, 'error' => 'Invalid guide step.'], 400);
        }

        /** @var Consignee $user */
        $user = $this->getUser();

        if (!$user->hasCompletedGuideStep($step)) {
            $user->completeGuideStep($step);
            $this->entityManager->flush();
        }

        return $this->json(['success' => true]);
    }

    #[Route('/guide/dismiss-success', name: 'consignee_guide_dismiss_success', methods: ['POST'])]
    public function dismissSuccess(): JsonResponse
    {
        /** @var Consignee $user */
        $user = $this->getUser();

        $changed = false;

        if (!$user->hasCompletedGuideStep('view_manifest_list')) {
            $user->completeGuideStep('view_manifest_list');
            $changed = true;
        }

        if (!$user->hasCompletedGuideStep('guide_tour_success')) {
            $user->completeGuideStep('guide_tour_success');
            $changed = true;
        }

        if ($changed) {
            $this->entityManager->flush();
        }

        return $this->json(['success' => true]);
    }
}
