<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
class SessionTimeoutListener
{
    private const SESSION_TIMEOUT = 1800; // 30 minutes in seconds
    private const LAST_ACTIVITY_KEY = '_last_activity';
    
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        
        // Skip for non-main requests, login routes, and public routes
        if (!$event->isMainRequest() || 
            str_starts_with($request->getPathInfo(), '/login') ||
            str_starts_with($request->getPathInfo(), '/register') ||
            str_starts_with($request->getPathInfo(), '/_') ||
            !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $token = $this->tokenStorage->getToken();

        // Only check timeout for authenticated users
        if (!$token || !$token->getUser()) {
            return;
        }

        $now = time();
        $lastActivity = $session->get(self::LAST_ACTIVITY_KEY);

        if ($lastActivity === null) {
            // First request, set last activity
            $session->set(self::LAST_ACTIVITY_KEY, $now);
            return;
        }

        // Check if session has expired
        if (($now - $lastActivity) > self::SESSION_TIMEOUT) {
            // Session expired, clear authentication and redirect to login
            $this->tokenStorage->setToken(null);
            $session->invalidate();
            
            // Add flash message about session expiration
            $session->getFlashBag()->add('warning', 'Your session has expired due to inactivity. Please log in again.');
            
            try {
                $loginUrl = $this->router->generate('app_login');
            } catch (\Exception $e) {
                // Fallback to /login if route generation fails (e.g., in tests)
                $loginUrl = '/login';
            }
            
            $response = new RedirectResponse($loginUrl);
            $event->setResponse($response);
            return;
        }

        // Update last activity time
        $session->set(self::LAST_ACTIVITY_KEY, $now);
    }
}