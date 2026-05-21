<?php

namespace App\Controller;

use App\Service\ContainerDataInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DwellTimeMonitoringViewController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContainerDataInterface $containerDataService
    ) {
    }

    #[Route('/shipping-admin/dwell-time-monitoring', name: 'app_dwell_time_monitoring')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function dwellTimeMonitoring(): Response
    {
        // Get all containers with dwell time data
        $allContainers = $this->containerDataService->getFormattedContainerData();
        
        // Filter to only show containers with 50+ days dwell time
        $containers = array_filter($allContainers, function($container) {
            return ($container['dwellTime'] ?? 0) >= 50;
        });
        
        // Calculate statistics
        $stats = [
            'total' => count($containers),
            'count_50_to_59' => 0,
            'count_60_to_89' => 0,
            'count_90_plus' => 0,
            'alert_status' => 0,
            'paused' => 0,
            'avg_dwell_time' => 0
        ];
        
        $totalDwellTime = 0;
        foreach ($containers as $container) {
            $dwellTime = $container['dwellTime'] ?? 0;
            $totalDwellTime += $dwellTime;
            
            if ($dwellTime >= 50 && $dwellTime < 60) {
                $stats['count_50_to_59']++;
            } elseif ($dwellTime >= 60 && $dwellTime < 90) {
                $stats['count_60_to_89']++;
            } elseif ($dwellTime >= 90) {
                $stats['count_90_plus']++;
            }
            
            if ($container['status'] === 'Hold') {
                $stats['alert_status']++;
            }
            
            if (($container['totalPausedDays'] ?? 0) > 0) {
                $stats['paused']++;
            }
        }
        
        $stats['avg_dwell_time'] = $stats['total'] > 0 ? round($totalDwellTime / $stats['total'], 1) : 0;
        
        return $this->render('dwell_time_monitoring/index.html.twig', [
            'containers' => $containers,
            'stats' => $stats
        ]);
    }
}
