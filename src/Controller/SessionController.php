<?php

namespace App\Controller;

use App\Service\SessionActivityManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SessionController extends AbstractController
{
    public function __construct(
        private SessionActivityManager $sessionActivityManager,
    ) {
    }

    #[Route('/api/extend-session', name: 'api_extend_session', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function extendSession(Request $request): JsonResponse
    {
        // Check if request is AJAX
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $session = $request->getSession();

        $this->sessionActivityManager->touch($session);

        return new JsonResponse([
            'success' => true,
            'message' => 'Session extended successfully'
        ]);
    }
}