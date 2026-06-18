<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use App\Entity\Broker;
use App\Service\WorkspaceService;

/**
 * Ensures brokers have an active workspace selected before accessing workspace-dependent routes
 */
class WorkspaceFilterSubscriber implements EventSubscriberInterface
{
    private const WORKSPACE_ROUTE_PREFIXES = [
        'broker_manifest_',
        'broker_edo_',
        'broker_detention_',
        'broker_billing_',
    ];

    private const EXCLUDED_ROUTES = [
        'broker_workspace_selector',
        'broker_switch_workspace',
        'broker_clear_workspace',
        'broker_current_workspace',
        'app_broker_dashboard',
        'app_logout',
        'profile_settings',
        'account_settings',
        'app_shipping_line_select',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
        private WorkspaceService $workspaceService,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // Skip if not a named route
        if (!$route) {
            return;
        }

        // Skip excluded routes (workspace selector, logout, etc.)
        if (in_array($route, self::EXCLUDED_ROUTES)) {
            return;
        }

        // Skip non-broker users
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }
        
        $user = $token->getUser();
        if (!$user instanceof Broker) {
            return;
        }

        // Only check workspace for workspace-dependent broker routes
        if (!$this->requiresWorkspace($route)) {
            return;
        }

        if (!$this->workspaceService->needsWorkspaceSelection($user)) {
            return;
        }

        $this->logger->info('Broker attempted to access workspace route without active workspace', [
            'user_id' => $user->getId(),
            'route' => $route,
            'ip' => $request->getClientIp(),
        ]);

        $request->getSession()->getFlashBag()->add('error', 'Please select a workspace first');

        $selectorUrl = $this->urlGenerator->generate('broker_workspace_selector');
        $event->setController(static fn () => new RedirectResponse($selectorUrl));
    }

    private function requiresWorkspace(string $route): bool
    {
        foreach (self::WORKSPACE_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
