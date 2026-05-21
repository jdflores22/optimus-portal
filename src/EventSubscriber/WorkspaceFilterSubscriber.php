<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ensures brokers have an active workspace selected before accessing workspace-dependent routes
 */
class WorkspaceFilterSubscriber implements EventSubscriberInterface
{
    private const WORKSPACE_ROUTES = [
        'broker_manifest_list',
        'broker_manifest_detail',
        'broker_manifest_payment',
        'broker_manifest_upload_bl',
        'broker_manifest_submit_payment',
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
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
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
        if (!$user || $user->getRole()->value !== 'BROKER') {
            return;
        }

        // Only check workspace for specific workspace-dependent routes
        if (!in_array($route, self::WORKSPACE_ROUTES)) {
            return;
        }

        // Check if workspace is selected
        $session = $request->getSession();
        $activeWorkspaceId = $session->get('active_workspace_consignee_id');

        if (!$activeWorkspaceId) {
            $this->logger->info('Broker attempted to access workspace route without active workspace', [
                'user_id' => $user->getId(),
                'route' => $route,
                'ip' => $request->getClientIp(),
            ]);

            // Redirect to workspace selector
            $response = new RedirectResponse(
                $this->urlGenerator->generate('broker_workspace_selector')
            );
            $event->setResponse($response);
        }
    }
}
