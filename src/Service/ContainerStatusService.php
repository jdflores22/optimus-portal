<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ContainerStatusService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DwellTimeServiceInterface $dwellTimeService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Change container status with proper dwell time integration
     */
    public function changeStatus(
        Container $container, 
        ContainerStatus $newStatus, 
        ?User $triggeredBy = null,
        ?string $reason = null
    ): void {
        $oldStatus = $container->getStatus();
        
        // Don't process if status is the same
        if ($oldStatus === $newStatus) {
            try {
                $containerId = $container->getId();
            } catch (\Error $e) {
                $containerId = 'uninitialized';
            }
            
            $this->logger->info('Container status change skipped - same status', [
                'container_id' => $containerId,
                'container_number' => $container->getContainerNumber(),
                'status' => $newStatus->value
            ]);
            return;
        }

        // Update container status
        $container->setStatus($newStatus);
        $container->setUpdatedAt(new \DateTime());

        // Handle dwell time implications through DwellTimeService
        $this->dwellTimeService->handleStatusChange($container, $oldStatus, $newStatus, $triggeredBy);

        // Persist changes
        $this->entityManager->flush();

        try {
            $containerId = $container->getId();
        } catch (\Error $e) {
            $containerId = 'uninitialized';
        }

        $this->logger->info('Container status changed successfully', [
            'container_id' => $containerId,
            'container_number' => $container->getContainerNumber(),
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
            'triggered_by' => $triggeredBy?->getId(),
            'reason' => $reason
        ]);
    }

    /**
     * Batch status change for multiple containers
     */
    public function batchChangeStatus(
        array $containers, 
        ContainerStatus $newStatus, 
        ?User $triggeredBy = null,
        ?string $reason = null
    ): array {
        $results = [];
        
        foreach ($containers as $container) {
            try {
                $this->changeStatus($container, $newStatus, $triggeredBy, $reason);
                $results[] = [
                    'container_number' => $container->getContainerNumber(),
                    'success' => true,
                    'old_status' => $container->getStatus()->value,
                    'new_status' => $newStatus->value
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'container_number' => $container->getContainerNumber(),
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                
                try {
                    $containerId = $container->getId();
                } catch (\Error $idError) {
                    $containerId = 'uninitialized';
                }
                
                $this->logger->error('Failed to change container status in batch', [
                    'container_id' => $containerId,
                    'container_number' => $container->getContainerNumber(),
                    'target_status' => $newStatus->value,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }

    /**
     * Get containers by status with optional filtering
     */
    public function getContainersByStatus(
        ContainerStatus $status,
        ?int $limit = null,
        ?int $offset = null
    ): array {
        $queryBuilder = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', $status)
            ->orderBy('c.updatedAt', 'DESC');

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        if ($offset !== null) {
            $queryBuilder->setFirstResult($offset);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get status change statistics
     */
    public function getStatusChangeStatistics(\DateTime $fromDate, ?\DateTime $toDate = null): array
    {
        $toDate = $toDate ?? new \DateTime();
        
        // Get status changes from dwell time events
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('dte.metadata')
            ->from('App\Entity\DwellTimeEvent', 'dte')
            ->where('dte.eventType = :statusChangeType')
            ->andWhere('dte.eventDate >= :fromDate')
            ->andWhere('dte.eventDate <= :toDate')
            ->setParameter('statusChangeType', 'status_change')
            ->setParameter('fromDate', $fromDate)
            ->setParameter('toDate', $toDate);

        $events = $queryBuilder->getQuery()->getResult();
        
        $statistics = [
            'total_changes' => count($events),
            'alert_activations' => 0,
            'alert_deactivations' => 0,
            'status_distribution' => []
        ];

        foreach ($events as $event) {
            $metadata = $event['metadata'] ?? [];
            $oldStatus = $metadata['old_status'] ?? null;
            $newStatus = $metadata['new_status'] ?? null;

            if ($newStatus === ContainerStatus::ALERT->value) {
                $statistics['alert_activations']++;
            }
            
            if ($oldStatus === ContainerStatus::ALERT->value) {
                $statistics['alert_deactivations']++;
            }

            if ($newStatus) {
                $statistics['status_distribution'][$newStatus] = 
                    ($statistics['status_distribution'][$newStatus] ?? 0) + 1;
            }
        }

        return $statistics;
    }

    /**
     * Validate status transition
     */
    public function isValidStatusTransition(ContainerStatus $fromStatus, ContainerStatus $toStatus): bool
    {
        // Define valid status transitions
        $validTransitions = [
            ContainerStatus::AVAILABLE_FOR_RETURN->value => [
                ContainerStatus::PA_APPROVED->value,
                ContainerStatus::ALERT->value,
                ContainerStatus::MAINTENANCE->value
            ],
            ContainerStatus::PA_APPROVED->value => [
                ContainerStatus::IN_TRANSIT->value,
                ContainerStatus::ALERT->value,
                ContainerStatus::AVAILABLE_FOR_RETURN->value
            ],
            ContainerStatus::IN_TRANSIT->value => [
                ContainerStatus::AT_TERMINAL->value,
                ContainerStatus::ALERT->value
            ],
            ContainerStatus::AT_TERMINAL->value => [
                ContainerStatus::RETURNED->value,
                ContainerStatus::ALERT->value,
                ContainerStatus::AVAILABLE_FOR_RETURN->value
            ],
            ContainerStatus::RETURNED->value => [
                ContainerStatus::AVAILABLE_FOR_RETURN->value,
                ContainerStatus::MAINTENANCE->value
            ],
            ContainerStatus::MAINTENANCE->value => [
                ContainerStatus::AVAILABLE_FOR_RETURN->value,
                ContainerStatus::ALERT->value
            ],
            ContainerStatus::ALERT->value => [
                // ALERT can transition to any other status
                ContainerStatus::AVAILABLE_FOR_RETURN->value,
                ContainerStatus::PA_APPROVED->value,
                ContainerStatus::IN_TRANSIT->value,
                ContainerStatus::AT_TERMINAL->value,
                ContainerStatus::RETURNED->value,
                ContainerStatus::MAINTENANCE->value
            ]
        ];

        $allowedTransitions = $validTransitions[$fromStatus->value] ?? [];
        return in_array($toStatus->value, $allowedTransitions);
    }
}