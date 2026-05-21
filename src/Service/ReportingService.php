<?php

namespace App\Service;

use App\Repository\PreAdviceRequestRepository;
use App\Repository\TerminalRepository;
use App\Repository\TerminalSlotRepository;
use App\Entity\Enum\TerminalType;

class ReportingService
{
    public function __construct(
        private PreAdviceRequestRepository $preAdviceRequestRepository,
        private TerminalRepository $terminalRepository,
        private TerminalSlotRepository $terminalSlotRepository,
        private ?TerminalTeamDwellTimeService $terminalTeamDwellTimeService = null
    ) {}

    /**
     * Generate comprehensive FREE-ADVICE statistics
     */
    public function generatePreAdviceStatistics(\DateTime $startDate, \DateTime $endDate): array
    {
        $statistics = $this->preAdviceRequestRepository->getStatistics($startDate, $endDate);
        $approvalRates = $this->preAdviceRequestRepository->getApprovalRates($startDate, $endDate);
        $processingTimes = $this->preAdviceRequestRepository->getProcessingTimeStats($startDate, $endDate);

        // Calculate approval rate percentage
        $totalProcessed = (int) $approvalRates['total_processed'];
        $approvedCount = (int) $approvalRates['approved_count'];
        $approvalRate = $totalProcessed > 0 ? round(($approvedCount / $totalProcessed) * 100, 2) : 0;

        return [
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ],
            'summary' => [
                'total_requests' => (int) $statistics['total_requests'],
                'pending_requests' => (int) $statistics['pending_requests'],
                'verified_requests' => (int) $statistics['verified_requests'],
                'rejected_requests' => (int) $statistics['rejected_requests'],
                'completed_requests' => (int) $statistics['completed_requests']
            ],
            'approval_metrics' => [
                'total_processed' => $totalProcessed,
                'approved_count' => $approvedCount,
                'rejected_count' => (int) $approvalRates['rejected_count'],
                'approval_rate_percentage' => $approvalRate
            ],
            'processing_times' => [
                'average_hours' => $processingTimes['avg_processing_hours'] ? round((float) $processingTimes['avg_processing_hours'], 2) : null,
                'minimum_hours' => $processingTimes['min_processing_hours'] ? (float) $processingTimes['min_processing_hours'] : null,
                'maximum_hours' => $processingTimes['max_processing_hours'] ? (float) $processingTimes['max_processing_hours'] : null
            ]
        ];
    }

    /**
     * Generate terminal utilization report
     */
    public function generateTerminalUtilizationReport(\DateTime $startDate, \DateTime $endDate): array
    {
        $utilization = $this->preAdviceRequestRepository->getTerminalUtilization($startDate, $endDate);
        $terminalTypeStats = $this->preAdviceRequestRepository->getRequestsByTerminalType($startDate, $endDate);

        // Get terminal capacity information
        $terminals = $this->terminalRepository->findBy(['isActive' => true]);
        $terminalCapacities = [];
        foreach ($terminals as $terminal) {
            $terminalCapacities[$terminal->getName()] = $terminal->getDailyCapacity();
        }

        // Process utilization data
        $utilizationData = [];
        foreach ($utilization as $item) {
            $terminalName = $item['terminal_name'];
            $dailyCapacity = $terminalCapacities[$terminalName] ?? 0;
            $utilizationRate = $dailyCapacity > 0 ? round(((int) $item['completed_count'] / $dailyCapacity) * 100, 2) : 0;

            $utilizationData[] = [
                'terminal_name' => $terminalName,
                'terminal_type' => $item['terminal_type']->value,
                'total_requests' => (int) $item['request_count'],
                'completed_requests' => (int) $item['completed_count'],
                'daily_capacity' => $dailyCapacity,
                'utilization_rate_percentage' => $utilizationRate
            ];
        }

        // Process terminal type statistics
        $typeStats = [];
        foreach ($terminalTypeStats as $item) {
            $totalRequests = (int) $item['request_count'];
            $verifiedCount = (int) $item['verified_count'];
            $approvalRate = $totalRequests > 0 ? round(($verifiedCount / $totalRequests) * 100, 2) : 0;

            $typeStats[] = [
                'terminal_type' => $item['terminal_type']->value,
                'total_requests' => $totalRequests,
                'verified_requests' => $verifiedCount,
                'rejected_requests' => (int) $item['rejected_count'],
                'approval_rate_percentage' => $approvalRate
            ];
        }

        return [
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ],
            'terminal_utilization' => $utilizationData,
            'terminal_type_statistics' => $typeStats
        ];
    }

    /**
     * Generate approval rate analytics and trends
     */
    public function generateApprovalRateAnalytics(\DateTime $startDate, \DateTime $endDate): array
    {
        $dailyTrends = $this->preAdviceRequestRepository->getDailyTrends($startDate, $endDate);
        $overallRates = $this->preAdviceRequestRepository->getApprovalRates($startDate, $endDate);

        // Process daily trends
        $trendsData = [];
        foreach ($dailyTrends as $day) {
            $totalRequests = (int) $day['total_requests'];
            $verifiedRequests = (int) $day['verified_requests'];
            $rejectedRequests = (int) $day['rejected_requests'];
            $processedRequests = $verifiedRequests + $rejectedRequests;
            
            $approvalRate = $processedRequests > 0 ? round(($verifiedRequests / $processedRequests) * 100, 2) : 0;

            $trendsData[] = [
                'date' => $day['date'],
                'total_requests' => $totalRequests,
                'verified_requests' => $verifiedRequests,
                'rejected_requests' => $rejectedRequests,
                'approval_rate_percentage' => $approvalRate
            ];
        }

        // Calculate overall metrics
        $totalProcessed = (int) $overallRates['total_processed'];
        $approvedCount = (int) $overallRates['approved_count'];
        $overallApprovalRate = $totalProcessed > 0 ? round(($approvedCount / $totalProcessed) * 100, 2) : 0;

        return [
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ],
            'overall_metrics' => [
                'total_processed' => $totalProcessed,
                'approved_count' => $approvedCount,
                'rejected_count' => (int) $overallRates['rejected_count'],
                'approval_rate_percentage' => $overallApprovalRate
            ],
            'daily_trends' => $trendsData
        ];
    }

    /**
     * Generate comprehensive dashboard metrics
     */
    public function generateDashboardMetrics(\DateTime $startDate, \DateTime $endDate): array
    {
        $statistics = $this->generatePreAdviceStatistics($startDate, $endDate);
        $utilization = $this->generateTerminalUtilizationReport($startDate, $endDate);
        $approvalAnalytics = $this->generateApprovalRateAnalytics($startDate, $endDate);

        $dashboardData = [
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ],
            'pre_advice_statistics' => $statistics,
            'terminal_utilization' => $utilization,
            'approval_analytics' => $approvalAnalytics
        ];

        // Add dwell time metrics if terminal team service is available
        if ($this->terminalTeamDwellTimeService) {
            $dashboardData['dwell_time_metrics'] = $this->terminalTeamDwellTimeService->getTerminalTeamDashboardMetrics();
        }

        return $dashboardData;
    }

    /**
     * Get quick metrics for dashboard widgets
     */
    public function getQuickMetrics(): array
    {
        $today = new \DateTime('today');
        $weekAgo = new \DateTime('-7 days');
        $monthAgo = new \DateTime('-30 days');

        $todayStats = $this->preAdviceRequestRepository->getStatistics($today, new \DateTime('tomorrow'));
        $weekStats = $this->preAdviceRequestRepository->getStatistics($weekAgo, new \DateTime());
        $monthStats = $this->preAdviceRequestRepository->getStatistics($monthAgo, new \DateTime());

        return [
            'today' => [
                'total_requests' => (int) $todayStats['total_requests'],
                'pending_requests' => (int) $todayStats['pending_requests'],
                'verified_requests' => (int) $todayStats['verified_requests']
            ],
            'last_7_days' => [
                'total_requests' => (int) $weekStats['total_requests'],
                'pending_requests' => (int) $weekStats['pending_requests'],
                'verified_requests' => (int) $weekStats['verified_requests']
            ],
            'last_30_days' => [
                'total_requests' => (int) $monthStats['total_requests'],
                'pending_requests' => (int) $monthStats['pending_requests'],
                'verified_requests' => (int) $monthStats['verified_requests']
            ]
        ];
    }
}