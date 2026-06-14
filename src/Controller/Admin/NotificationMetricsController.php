<?php

namespace App\Controller\Admin;

use App\Repository\NotificationMetricsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/notification-metrics')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class NotificationMetricsController extends AbstractController
{
    public function __construct(
        private NotificationMetricsRepository $metricsRepository
    ) {
    }

    #[Route('', name: 'admin_notification_metrics', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        // Get date range from request or default to last 30 days
        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify('-30 days');
        
        if ($request->query->has('start_date')) {
            $startDate = new \DateTime($request->query->get('start_date'));
        }
        
        if ($request->query->has('end_date')) {
            $endDate = new \DateTime($request->query->get('end_date'));
        }
        
        // Get metrics summary by type
        $metricsByType = $this->metricsRepository->getMetricsSummaryByType($startDate, $endDate);
        
        // Get metrics trend over time
        $metricsTrend = $this->metricsRepository->getMetricsTrend($startDate, $endDate);
        
        // Get overall delivery rate
        $overallDeliveryRate = $this->metricsRepository->getOverallDeliveryRate($startDate, $endDate);
        
        // Calculate overall statistics
        $totalSent = array_sum(array_column($metricsByType, 'total'));
        $totalDelivered = array_sum(array_column($metricsByType, 'delivered'));
        $totalOpened = array_sum(array_column($metricsByType, 'opened'));
        $totalFailed = array_sum(array_column($metricsByType, 'failed'));
        
        $overallOpenRate = $totalDelivered > 0 ? ($totalOpened / $totalDelivered) * 100 : 0;
        $overallFailureRate = $totalSent > 0 ? ($totalFailed / $totalSent) * 100 : 0;
        
        return $this->render('admin/notification_metrics/dashboard.html.twig', [
            'metricsByType' => $metricsByType,
            'metricsTrend' => $metricsTrend,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'overallStats' => [
                'totalSent' => $totalSent,
                'totalDelivered' => $totalDelivered,
                'totalOpened' => $totalOpened,
                'totalFailed' => $totalFailed,
                'deliveryRate' => $overallDeliveryRate,
                'openRate' => $overallOpenRate,
                'failureRate' => $overallFailureRate
            ]
        ]);
    }
}
