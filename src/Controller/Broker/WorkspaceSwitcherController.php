<?php

namespace App\Controller\Broker;

use App\Service\WorkspaceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/broker/workspace')]
#[IsGranted('ROLE_BROKER')]
class WorkspaceSwitcherController extends AbstractController
{
    public function __construct(
        private WorkspaceService $workspaceService,
        private RateLimiterFactory $workspaceSwitchingLimiter
    ) {
    }

    #[Route('/selector', name: 'broker_workspace_selector', methods: ['GET'])]
    public function selector(): Response
    {
        $user = $this->getUser();
        
        // Prevent suspended brokers from accessing workspace selector
        if ($user->getStatus()->value === 'DENIED') {
            return $this->redirectToRoute('app_broker_dashboard');
        }
        
        $workspaces = $this->workspaceService->getAvailableWorkspaces($user);
        
        // If no workspaces available, show message
        if (empty($workspaces)) {
            return $this->render('broker/workspace_selector.html.twig', [
                'workspaces' => [],
                'message' => 'You are not linked to any consignees yet. Please contact a consignee to get a referral code.'
            ]);
        }
        
        // If only one workspace, auto-select it and redirect to dashboard
        if (count($workspaces) === 1) {
            $this->workspaceService->setActiveWorkspace($workspaces[0]['id'], $user);
            $this->addFlash('info', 'Workspace selected: ' . $workspaces[0]['name']);
            return $this->redirectToRoute('app_broker_dashboard');
        }
        
        // Multiple workspaces - show selector
        return $this->render('broker/workspace_selector.html.twig', [
            'workspaces' => $workspaces,
            'message' => null
        ]);
    }

    #[Route('/switch/{consigneeId}', name: 'broker_switch_workspace', methods: ['GET', 'POST'])]
    public function switchWorkspace(int $consigneeId): Response
    {
        $user = $this->getUser();
        
        // Prevent suspended brokers from switching workspaces
        if ($user->getStatus()->value === 'DENIED') {
            $this->addFlash('error', 'Your account is suspended. You cannot switch workspaces.');
            return $this->redirectToRoute('app_broker_dashboard');
        }
        
        // Rate limiting
        $limiter = $this->workspaceSwitchingLimiter->create($this->getUser()->getId());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Too many workspace switches. Please slow down.');
            return $this->redirectToRoute('broker_workspace_selector');
        }

        $user = $this->getUser();
        
        // Validate access to this workspace
        if (!$this->workspaceService->validateWorkspaceAccess($user, $consigneeId)) {
            $this->addFlash('error', 'You do not have access to this workspace.');
            return $this->redirectToRoute('broker_workspace_selector');
        }
        
        // Set active workspace
        $this->workspaceService->setActiveWorkspace($consigneeId, $user);
        
        // Get workspace name for flash message
        $workspaces = $this->workspaceService->getAvailableWorkspaces($user);
        $workspaceName = null;
        foreach ($workspaces as $workspace) {
            if ($workspace['id'] === $consigneeId) {
                $workspaceName = $workspace['name'];
                break;
            }
        }
        
        $this->addFlash('success', 'Switched to workspace: ' . ($workspaceName ?? 'Unknown'));
        
        return $this->redirectToRoute('app_broker_dashboard');
    }

    #[Route('/clear', name: 'broker_clear_workspace', methods: ['POST'])]
    public function clearWorkspace(): Response
    {
        $this->workspaceService->clearActiveWorkspace();
        $this->addFlash('info', 'Workspace cleared. Please select a workspace to continue.');
        
        return $this->redirectToRoute('broker_workspace_selector');
    }

    #[Route('/current', name: 'broker_current_workspace', methods: ['GET'])]
    public function currentWorkspace(): Response
    {
        $user = $this->getUser();
        
        // Prevent suspended brokers from accessing current workspace
        if ($user->getStatus()->value === 'DENIED') {
            return $this->json([
                'active' => false,
                'message' => 'Your account is suspended'
            ], Response::HTTP_FORBIDDEN);
        }
        
        $activeWorkspaceId = $this->workspaceService->getActiveWorkspace();
        
        if (!$activeWorkspaceId) {
            return $this->json([
                'active' => false,
                'message' => 'No active workspace'
            ]);
        }
        
        // Get workspace details
        $workspaces = $this->workspaceService->getAvailableWorkspaces($user);
        $activeWorkspace = null;
        
        foreach ($workspaces as $workspace) {
            if ($workspace['id'] === $activeWorkspaceId) {
                $activeWorkspace = $workspace;
                break;
            }
        }
        
        if (!$activeWorkspace) {
            // Active workspace is no longer valid, clear it
            $this->workspaceService->clearActiveWorkspace();
            return $this->json([
                'active' => false,
                'message' => 'Active workspace is no longer valid'
            ]);
        }
        
        return $this->json([
            'active' => true,
            'workspace' => [
                'id' => $activeWorkspace['id'],
                'name' => $activeWorkspace['name']
            ]
        ]);
    }
    
    #[Route('/list', name: 'broker_workspace_list', methods: ['GET'])]
    public function listWorkspaces(): Response
    {
        $user = $this->getUser();
        
        // Prevent suspended brokers from accessing workspace list
        if ($user->getStatus()->value === 'DENIED') {
            return $this->json([
                'success' => false,
                'message' => 'Your account is suspended. Please contact support.'
            ], Response::HTTP_FORBIDDEN);
        }
        
        $workspaces = $this->workspaceService->getAvailableWorkspaces($user);
        $activeWorkspaceId = $this->workspaceService->getActiveWorkspace();
        
        // Format workspaces for JSON response
        $formattedWorkspaces = array_map(function($workspace) {
            return [
                'consigneeId' => $workspace['id'],
                'businessName' => $workspace['name'],
                'email' => $workspace['email'],
                'activeManifestCount' => $workspace['manifestCount'] ?? 0,
                'source' => $workspace['source'] ?? 'unknown'
            ];
        }, $workspaces);
        
        return $this->json([
            'success' => true,
            'workspaces' => $formattedWorkspaces,
            'activeWorkspaceId' => $activeWorkspaceId,
            'debug' => [
                'broker_id' => $user->getId(),
                'broker_email' => $user->getEmail(),
                'broker_class' => get_class($user),
                'has_getLinkedConsignees' => method_exists($user, 'getLinkedConsignees'),
                'linkedConsignees_count' => method_exists($user, 'getLinkedConsignees') ? $user->getLinkedConsignees()->count() : 'N/A'
            ]
        ]);
    }
}
