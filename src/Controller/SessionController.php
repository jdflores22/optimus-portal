<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SessionController extends AbstractController
{
    #[Route('/api/extend-session', name: 'api_extend_session', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function extendSession(Request $request): JsonResponse
    {
        // Check if request is AJAX
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $session = $request->getSession();
        
        // Update last activity time
        $session->set('_last_activity', time());
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Session extended successfully'
        ]);
    }
}