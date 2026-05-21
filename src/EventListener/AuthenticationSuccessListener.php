<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\AuthenticationIntegrationService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Event listener for handling successful authentication and role-based redirects
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
class AuthenticationSuccessListener
{
    public function __construct(
        private AuthenticationIntegrationService $authIntegrationService,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        
        if (!$user instanceof User) {
            return;
        }

        // Update user session with additional security information
        $session = $event->getRequest()->getSession();
        $this->authIntegrationService->updateUserSession($user, $session);

        // Get the appropriate dashboard route for the user's role
        $dashboardRoute = $this->authIntegrationService->getDashboardRouteForUser($user);
        
        // Create redirect response to the appropriate dashboard
        $response = new RedirectResponse($this->urlGenerator->generate($dashboardRoute));
        $event->setResponse($response);
    }
}