<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\ShippingLineAccessControlService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Event listener that checks if users associated with deactivated shipping lines should be logged out
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
class ShippingLineAccessListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ShippingLineAccessControlService $shippingLineAccessControl,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only process main requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        
        // Skip for public routes (login, register, etc.)
        $publicRoutes = [
            '/login',
            '/register',
            '/forgot-password',
            '/email/verify',
            '/email/resend',
            '/role-acceptance',
            '/trucker/login',
            '/trucker/register',
            '/trucker/forgot-password',
            '/trucker/verify-email',
            '/_profiler',
            '/_wdt',
            '/assets',
            '/build'
        ];

        $pathInfo = $request->getPathInfo();
        foreach ($publicRoutes as $publicRoute) {
            if (str_starts_with($pathInfo, $publicRoute)) {
                return;
            }
        }

        // Get current user
        $token = $this->tokenStorage->getToken();
        if (!$token || !$token->getUser() instanceof User) {
            return;
        }

        $user = $token->getUser();

        // Check shipping line access
        if (!$this->shippingLineAccessControl->hasAccess($user)) {
            // Clear the security token to log out the user
            $this->tokenStorage->setToken(null);
            
            // Add error message to session
            $session = $request->getSession();
            $reason = $this->shippingLineAccessControl->getAccessDenialReason($user);
            $session->getFlashBag()->add('error', $reason ?: 'Access denied.');
            
            // Redirect to login page
            $loginUrl = $this->urlGenerator->generate('app_login');
            $response = new RedirectResponse($loginUrl);
            $event->setResponse($response);
        }
    }
}