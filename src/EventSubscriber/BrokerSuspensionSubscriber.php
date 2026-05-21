<?php

namespace App\EventSubscriber;

use App\Entity\Broker;
use App\Entity\Enum\AccountStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class BrokerSuspensionSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_broker_dashboard',      // Dashboard where modal shows
        'broker_submit_appeal',       // Submit appeal endpoint
        'broker_appeal_status',       // Check appeal status endpoint
        'app_logout',                 // Allow logout
        '_wdt',                       // Symfony Web Debug Toolbar
        '_profiler',                  // Symfony Profiler
        '_profiler_search',
        '_profiler_search_bar',
        '_profiler_phpinfo',
        '_profiler_search_results',
        '_profiler_open_file',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only handle main requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // Skip if no route (e.g., 404)
        if (!$route) {
            return;
        }

        // Get current user
        $token = $this->tokenStorage->getToken();
        if (!$token || !$token->getUser()) {
            return;
        }

        $user = $token->getUser();

        // Only check brokers
        if (!$user instanceof Broker) {
            return;
        }

        // Check if broker is suspended (status = DENIED)
        if ($user->getStatus() !== AccountStatus::DENIED) {
            return;
        }

        // Allow access to specific routes
        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        // Allow access to routes that start with allowed prefixes
        foreach (self::ALLOWED_ROUTES as $allowedRoute) {
            if (str_starts_with($route, $allowedRoute)) {
                return;
            }
        }

        // Redirect to dashboard where suspension modal will show
        $dashboardUrl = $this->urlGenerator->generate('app_broker_dashboard');
        $event->setResponse(new RedirectResponse($dashboardUrl));
    }
}
