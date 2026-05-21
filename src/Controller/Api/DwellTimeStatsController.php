<?php

namespace App\Controller\Api;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DwellTimeStatsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/shipping-admin/api/dwell-time/stats', name: 'api_dwell_time_stats', methods: ['GET'])]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function getDwellTimeStats(): JsonResponse
    {
        // Get all containers with dwell time tracking
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(Container::class, 'c')
            ->where('c.terminalArrivalDate IS NOT NULL');
        
        $containers = $qb->getQuery()->getResult();
        
        // Calculate statistics
        $count60to89 = 0;
        $count90Plus = 0;
        $alertCount = 0;
        $pausedCount = 0;
        $totalDwellTime = 0;
        $totalMonitored = count($containers);
        
        foreach ($containers as $container) {
            $dwellTime = $container->getCurrentDwellTime() ?? 0;
            $totalDwellTime += $dwellTime;
            
            // Count by threshold
            if ($dwellTime >= 60 && $dwellTime < 90) {
                $count60to89++;
            } elseif ($dwellTime >= 90) {
                $count90Plus++;
            }
            
            // Count alert status
            if ($container->getStatus() === ContainerStatus::ALERT) {
                $alertCount++;
            }
            
            // Count paused containers
            if ($container->getDwellTimePausedAt() !== null) {
                $pausedCount++;
            }
        }
        
        // Calculate average
        $avgDwellTime = $totalMonitored > 0 ? round($totalDwellTime / $totalMonitored, 1) : 0;
        
        return $this->json([
            'count_60_to_89_days' => $count60to89,
            'count_90_plus_days' => $count90Plus,
            'alert_status_count' => $alertCount,
            'paused_count' => $pausedCount,
            'total_monitored' => $totalMonitored,
            'avg_dwell_time' => $avgDwellTime,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);
    }
}
