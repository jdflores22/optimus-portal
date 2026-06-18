<?php

namespace App\Controller\Api;

use App\Service\SessionActivityManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/session')]
class SessionController extends AbstractController
{
    public function __construct(
        private SessionActivityManager $sessionActivityManager,
    ) {
    }

    #[Route('/config', name: 'api_session_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        return new JsonResponse($this->sessionActivityManager->getClientConfig());
    }

    #[Route('/ping', name: 'api_session_ping', methods: ['POST'])]
    public function ping(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return new JsonResponse(['status' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $isPWA = $this->isPWARequest($request);
        $session = $request->getSession();
        $this->sessionActivityManager->touch($session);
        $session->set('is_pwa', $isPWA);

        if ($isPWA) {
            $session->migrate(false, 30 * 24 * 60 * 60);
        }

        return new JsonResponse([
            'status' => 'alive',
            'timestamp' => time(),
            'is_pwa' => $isPWA,
        ]);
    }

    #[Route('/activity', name: 'api_session_activity', methods: ['POST'])]
    public function updateActivity(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return new JsonResponse(['status' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $this->sessionActivityManager->touch($request->getSession());

        return new JsonResponse([
            'status' => 'updated',
            'timestamp' => time(),
        ]);
    }

    #[Route('/status', name: 'api_session_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return new JsonResponse(['status' => 'expired'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $request->getSession();
        $lastActivity = $this->sessionActivityManager->initializeIfMissing($session);
        $isPWA = (bool) $session->get('is_pwa', false);
        $inactiveTime = $this->sessionActivityManager->getInactiveSeconds($session);

        if ($isPWA) {
            return new JsonResponse([
                'status' => 'active',
                'is_pwa' => true,
                'last_activity' => $lastActivity,
                'inactive_time' => $inactiveTime,
            ]);
        }

        if ($this->sessionActivityManager->isExpired($session)) {
            return new JsonResponse([
                'status' => 'expired',
                'last_activity' => $lastActivity,
                'inactive_time' => $inactiveTime,
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'status' => 'active',
            'is_pwa' => false,
            'last_activity' => $lastActivity,
            'inactive_time' => $inactiveTime,
        ]);
    }

    private function isPWARequest(Request $request): bool
    {
        if ($request->headers->get('X-Display-Mode') === 'standalone') {
            return true;
        }

        if ($request->headers->has('X-Requested-With')
            && $request->headers->get('X-Requested-With') === 'PWA') {
            return true;
        }

        $session = $request->getSession();

        return $session->has('is_pwa') && $session->get('is_pwa');
    }
}
