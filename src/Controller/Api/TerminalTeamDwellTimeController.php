<?php

namespace App\Controller\Api;

use App\Entity\Container;
use App\Service\TerminalTeamDwellTimeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/terminal-team/dwell-time', name: 'api_terminal_team_dwell_time_')]
class TerminalTeamDwellTimeController extends AbstractController
{
    public function __construct(
        private TerminalTeamDwellTimeService $terminalTeamService
    ) {
    }

    /**
     * Get terminal team dashboard metrics including dwell time information
     */
    #[Route('/dashboard-metrics', name: 'dashboard_metrics', methods: ['GET'])]
    #[IsGranted('ROLE_TERMINAL_TEAM')]
    public function getDashboardMetrics(): JsonResponse
    {
        try {
            $metrics = $this->terminalTeamService->getTerminalTeamDashboardMetrics();
            
            return $this->json([
                'success' => true,
                'data' => $metrics
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to retrieve dashboard metrics',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get alert status information for a specific container
     */
    #[Route('/container/{id}/alert-status', name: 'container_alert_status', methods: ['GET'])]
    #[IsGranted('ROLE_TERMINAL_TEAM')]
    public function getContainerAlertStatus(Container $container): JsonResponse
    {
        try {
            $alertInfo = $this->terminalTeamService->getContainerAlertStatusInfo($container);
            
            return $this->json([
                'success' => true,
                'data' => $alertInfo
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to retrieve container alert status',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
