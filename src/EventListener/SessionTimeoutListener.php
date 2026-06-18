<?php

namespace App\EventListener;

use App\Service\SessionActivityManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
class SessionTimeoutListener
{
    /** Session API routes are handled by dedicated controllers (read-only or explicit touch). */
    private const SESSION_API_PREFIXES = [
        '/api/session/',
        '/api/extend-session',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
        private SessionActivityManager $sessionActivityManager,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()
            || str_starts_with($request->getPathInfo(), '/login')
            || str_starts_with($request->getPathInfo(), '/register')
            || str_starts_with($request->getPathInfo(), '/_')
            || !$request->hasSession()) {
            return;
        }

        if ($this->isSessionApiRoute($request->getPathInfo())) {
            return;
        }

        $session = $request->getSession();
        $token = $this->tokenStorage->getToken();

        if (!$token || !$token->getUser()) {
            return;
        }

        $lastActivity = $this->sessionActivityManager->getLastActivity($session);

        if ($lastActivity === null) {
            $this->sessionActivityManager->initializeIfMissing($session);

            return;
        }

        if ($this->sessionActivityManager->isExpired($session)) {
            $this->tokenStorage->setToken(null);
            $session->invalidate();
            $session->getFlashBag()->add('warning', 'Your session has expired due to inactivity. Please log in again.');

            try {
                $loginUrl = $this->router->generate('app_login');
            } catch (\Exception $e) {
                $loginUrl = '/login';
            }

            $event->setResponse(new RedirectResponse($loginUrl));

            return;
        }

        $this->sessionActivityManager->touch($session);
    }

    private function isSessionApiRoute(string $path): bool
    {
        foreach (self::SESSION_API_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
