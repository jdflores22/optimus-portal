<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\DwellTimeConfiguration;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Repository\ContainerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DwellTimeMonitor implements DwellTimeMonitorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DwellTimeServiceInterface $dwellTimeService,
        private DwellTimeNotificationService $notificationService,
        private ContainerRepository $containerRepository,
        private LoggerInterface $logger,
        private ContainerReturnService $returnService
    ) {
    }

    public function processContainers(): void
    {
        $this->logger->info('Starting dwell time monitoring process');

        // Get all containers that need monitoring
        $containers = $this->getContainersForMonitoring();
        
        $processedCount = 0;
        $notificationsSent = 0;
        $automaticReturns = 0;

        foreach ($containers as $container) {
            try {
                // Update dwell time calculation
                $this->dwellTimeService->updateContainerDwellTime($container);
                
                // Check for notification thresholds
                $notifications = $this->dwellTimeService->checkNotificationThresholds($container);
                if (!empty($notifications)) {
                    $this->processNotifications($container, $notifications);
                    $notificationsSent += count($notifications);
                }
                
                // Check for automatic return using the dedicated return service
                $oldStatus = $container->getStatus();
                $returnProcessed = $this->returnService->processAutomaticReturn($container);
                if ($returnProcessed && $container->getStatus() === ContainerStatus::RETURNED && $oldStatus !== ContainerStatus::RETURNED) {
                    $automaticReturns++;
                }
                
                $processedCount++;
                
            } catch (\Exception $e) {
                $this->logger->error('Error processing container for dwell time monitoring', [
                    'container_id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Dwell time monitoring process completed', [
            'containers_processed' => $processedCount,
            'notifications_sent' => $notificationsSent,
            'automatic_returns' => $automaticReturns
        ]);
    }

    public function checkNotificationThresholds(): array
    {
        $containers = $this->getContainersForMonitoring();
        $notificationResults = [];

        foreach ($containers as $container) {
            try {
                $notifications = $this->dwellTimeService->checkNotificationThresholds($container);
                if (!empty($notifications)) {
                    $notificationResults[] = [
                        'container_id' => $container->getId(),
                        'container_number' => $container->getContainerNumber(),
                        'notifications' => $notifications
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error('Error checking notification thresholds', [
                    'container_id' => $container->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $notificationResults;
    }

    public function processAutomaticReturns(): array
    {
        $containers = $this->getContainersForMonitoring();
        $returnResults = [];

        foreach ($containers as $container) {
            try {
                $oldStatus = $container->getStatus();
                $returnProcessed = $this->returnService->processAutomaticReturn($container);
                
                if ($returnProcessed && $container->getStatus() === ContainerStatus::RETURNED && $oldStatus !== ContainerStatus::RETURNED) {
                    $returnResults[] = [
                        'container_id' => $container->getId(),
                        'container_number' => $container->getContainerNumber(),
                        'dwell_time' => $container->getCurrentDwellTime(),
                        'return_date' => new \DateTime()
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error('Error processing automatic return', [
                    'container_id' => $container->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $returnResults;
    }

    public function generateDailyReport(): array
    {
        $config = $this->getDwellTimeConfiguration();
        $containers = $this->getContainersForMonitoring();
        
        $report = [
            'date' => new \DateTime(),
            'total_containers' => count($containers),
            'containers_approaching_notification' => 0,
            'containers_approaching_return' => 0,
            'containers_paused' => 0,
            'notifications_due' => 0,
            'returns_due' => 0,
            'summary' => []
        ];

        foreach ($containers as $container) {
            $currentDwellTime = $this->dwellTimeService->calculateCurrentDwellTime($container);
            $notificationThreshold = $config->getNotificationThresholdDays();
            $returnThreshold = $config->getAutomaticReturnThresholdDays();
            
            // Count containers approaching thresholds (within 7 days)
            if ($currentDwellTime >= ($notificationThreshold - 7) && $currentDwellTime < $notificationThreshold) {
                $report['containers_approaching_notification']++;
            }
            
            if ($currentDwellTime >= ($returnThreshold - 7) && $currentDwellTime < $returnThreshold) {
                $report['containers_approaching_return']++;
            }
            
            // Count paused containers
            if ($container->getDwellTimePausedAt()) {
                $report['containers_paused']++;
            }
            
            // Count notifications and returns due
            $notifications = $this->dwellTimeService->checkNotificationThresholds($container);
            if (!empty($notifications)) {
                $report['notifications_due']++;
            }
            
            if ($currentDwellTime >= $returnThreshold && 
                $container->getStatus() !== ContainerStatus::ALERT &&
                $container->getStatus() !== ContainerStatus::RETURNED) {
                $report['returns_due']++;
            }
        }

        $report['summary'] = [
            'Active monitoring for ' . $report['total_containers'] . ' containers',
            $report['containers_approaching_notification'] . ' containers approaching 60-day notification threshold',
            $report['containers_approaching_return'] . ' containers approaching 90-day return threshold',
            $report['containers_paused'] . ' containers currently paused (alert status)',
            $report['notifications_due'] . ' notifications ready to send',
            $report['returns_due'] . ' containers ready for automatic return'
        ];

        $this->logger->info('Daily dwell time report generated', $report);

        return $report;
    }

    /**
     * Process notifications for a container
     */
    private function processNotifications(Container $container, array $notifications): void
    {
        foreach ($notifications as $notification) {
            try {
                $this->sendDwellTimeNotification($container, $notification);
                
                $this->logger->info('Dwell time notification sent', [
                    'container_id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'notification_type' => $notification['type'],
                    'dwell_time' => $notification['dwell_time']
                ]);
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to send dwell time notification', [
                    'container_id' => $container->getId(),
                    'container_number' => $container->getContainerNumber(),
                    'notification_type' => $notification['type'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Send dwell time notification to shipping line admin using enhanced notification service
     */
    private function sendDwellTimeNotification(Container $container, array $notification): void
    {
        // Use the enhanced notification service with multi-channel delivery and retry logic
        $this->notificationService->sendDwellTimeWarning(
            $container,
            $notification['days_remaining']
        );
    }

    /**
     * Get containers that need dwell time monitoring
     */
    private function getContainersForMonitoring(): array
    {
        return $this->containerRepository->createQueryBuilder('c')
            ->where('c.terminalArrivalDate IS NOT NULL')
            ->andWhere('c.status != :returned')
            ->setParameter('returned', ContainerStatus::RETURNED)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get dwell time configuration from database
     */
    private function getDwellTimeConfiguration(): DwellTimeConfiguration
    {
        $config = $this->entityManager->getRepository(DwellTimeConfiguration::class)->findOneBy([]);
        
        if (!$config) {
            // Create default configuration if none exists
            $config = new DwellTimeConfiguration();
            $this->entityManager->persist($config);
            $this->entityManager->flush();
        }
        
        return $config;
    }
}