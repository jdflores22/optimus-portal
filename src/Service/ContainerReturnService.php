<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for orchestrating the complete container return process
 * Handles automatic returns, status updates, notifications, and terminal integration
 */
class ContainerReturnService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DwellTimeServiceInterface $dwellTimeService,
        private DwellTimeNotificationService $notificationService,
        private TerminalTeamDwellTimeService $terminalTeamService,
        private DwellTimeAuditService $auditService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Process automatic return for a container that has reached 90-day threshold
     * Orchestrates the complete return workflow including status updates and notifications
     */
    public function processAutomaticReturn(Container $container, ?User $triggeredBy = null): bool
    {
        // Validate container is eligible for automatic return
        if (!$this->isEligibleForAutomaticReturn($container)) {
            $this->logger->info('Container not eligible for automatic return', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber(),
                'status' => $container->getStatus()->value,
                'dwell_time' => $container->getCurrentDwellTime()
            ]);
            return false;
        }

        $this->logger->info('Starting automatic return process', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'dwell_time' => $container->getCurrentDwellTime()
        ]);

        try {
            // Begin transaction for atomic operation
            $this->entityManager->beginTransaction();

            // Step 1: Update container status to RETURNED
            $oldStatus = $container->getStatus();
            $container->setStatus(ContainerStatus::RETURNED);
            $container->setLastDwellTimeCalculation(new \DateTime());

            // Step 2: Create audit event for the return
            $this->createReturnAuditEvent($container, $triggeredBy);

            // Step 3: Persist changes
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('Container status updated to RETURNED', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber(),
                'old_status' => $oldStatus->value,
                'new_status' => ContainerStatus::RETURNED->value
            ]);

            // Step 4: Send notifications (after successful commit)
            $this->sendReturnNotifications($container);

            // Step 5: Notify terminal team
            $this->notifyTerminalTeam($container);

            $this->logger->info('Automatic return process completed successfully', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);

            return true;

        } catch (\Exception $e) {
            // Rollback transaction on error
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to process automatic return', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }

    /**
     * Update container status during return process
     * Ensures proper status transitions and notifications
     */
    public function updateContainerStatusForReturn(Container $container, ?User $triggeredBy = null): void
    {
        $oldStatus = $container->getStatus();

        // Only update if not already returned
        if ($oldStatus === ContainerStatus::RETURNED) {
            $this->logger->info('Container already in RETURNED status', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $this->logger->info('Updating container status for return', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'old_status' => $oldStatus->value
        ]);

        // Update status
        $container->setStatus(ContainerStatus::RETURNED);
        $container->setLastDwellTimeCalculation(new \DateTime());

        // Create status change event
        $event = new DwellTimeEvent();
        $event->setContainer($container)
            ->setEventType(DwellTimeEventType::STATUS_CHANGE)
            ->setEventDate(new \DateTime())
            ->setDwellTimeAtEvent($container->getCurrentDwellTime())
            ->setReason("Status changed to RETURNED during return process")
            ->setTriggeredBy($triggeredBy)
            ->setMetadata([
                'old_status' => $oldStatus->value,
                'new_status' => ContainerStatus::RETURNED->value,
                'return_reason' => 'automatic_return'
            ]);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->logger->info('Container status updated successfully', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'new_status' => ContainerStatus::RETURNED->value
        ]);
    }

    /**
     * Check if container is eligible for automatic return
     */
    private function isEligibleForAutomaticReturn(Container $container): bool
    {
        // Container must not be in ALERT status
        if ($container->getStatus() === ContainerStatus::ALERT) {
            return false;
        }

        // Container must not already be RETURNED
        if ($container->getStatus() === ContainerStatus::RETURNED) {
            return false;
        }

        // Container must have terminal arrival date
        if (!$container->getTerminalArrivalDate()) {
            return false;
        }

        // Container must have reached 90-day threshold
        $currentDwellTime = $this->dwellTimeService->calculateCurrentDwellTime($container);
        $config = $this->getDwellTimeConfiguration();
        
        return $currentDwellTime >= $config->getAutomaticReturnThresholdDays();
    }

    /**
     * Create audit event for automatic return
     */
    private function createReturnAuditEvent(Container $container, ?User $triggeredBy): void
    {
        $currentDwellTime = $container->getCurrentDwellTime();
        $config = $this->getDwellTimeConfiguration();

        $event = new DwellTimeEvent();
        $event->setContainer($container)
            ->setEventType(DwellTimeEventType::AUTOMATIC_RETURN)
            ->setEventDate(new \DateTime())
            ->setDwellTimeAtEvent($currentDwellTime)
            ->setReason("Automatic return at {$config->getAutomaticReturnThresholdDays()} days dwell time")
            ->setTriggeredBy($triggeredBy)
            ->setMetadata([
                'return_threshold' => $config->getAutomaticReturnThresholdDays(),
                'actual_dwell_time' => $currentDwellTime,
                'terminal_arrival_date' => $container->getTerminalArrivalDate()?->format('Y-m-d'),
                'return_date' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

        $this->entityManager->persist($event);

        $this->logger->info('Return audit event created', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'event_type' => DwellTimeEventType::AUTOMATIC_RETURN->value,
            'dwell_time' => $currentDwellTime
        ]);
    }

    /**
     * Send notifications for automatic return
     */
    private function sendReturnNotifications(Container $container): void
    {
        try {
            // Send notification to shipping line admin
            $this->notificationService->sendAutomaticReturnNotification($container);

            $this->logger->info('Return notifications sent successfully', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);

        } catch (\Exception $e) {
            // Log error but don't fail the return process
            $this->logger->error('Failed to send return notifications', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify terminal team about container return
     */
    private function notifyTerminalTeam(Container $container): void
    {
        try {
            // Notify terminal team about the automatic return
            $this->terminalTeamService->notifyTerminalTeamAutomaticReturn($container);

            $this->logger->info('Terminal team notified about return', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);

        } catch (\Exception $e) {
            // Log error but don't fail the return process
            $this->logger->error('Failed to notify terminal team', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get dwell time configuration from database
     */
    private function getDwellTimeConfiguration(): \App\Entity\DwellTimeConfiguration
    {
        $config = $this->entityManager->getRepository(\App\Entity\DwellTimeConfiguration::class)->findOneBy([]);
        
        if (!$config) {
            // Create default configuration if none exists
            $config = new \App\Entity\DwellTimeConfiguration();
            $this->entityManager->persist($config);
            $this->entityManager->flush();
        }
        
        return $config;
    }

    /**
     * Get return process status for a container
     */
    public function getReturnProcessStatus(Container $container): array
    {
        $currentDwellTime = $this->dwellTimeService->calculateCurrentDwellTime($container);
        $config = $this->getDwellTimeConfiguration();
        $isEligible = $this->isEligibleForAutomaticReturn($container);

        return [
            'container_number' => $container->getContainerNumber(),
            'current_status' => $container->getStatus()->value,
            'current_dwell_time' => $currentDwellTime,
            'return_threshold' => $config->getAutomaticReturnThresholdDays(),
            'is_eligible_for_return' => $isEligible,
            'is_returned' => $container->getStatus() === ContainerStatus::RETURNED,
            'is_paused' => $container->getDwellTimePausedAt() !== null,
            'automatic_return_date' => $container->getAutomaticReturnDate()?->format('Y-m-d'),
            'days_until_return' => max(0, $config->getAutomaticReturnThresholdDays() - $currentDwellTime)
        ];
    }
}
