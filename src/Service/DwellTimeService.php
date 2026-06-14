<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\DwellTimeConfiguration;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DwellTimeService implements DwellTimeServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DwellTimeCalculatorInterface $calculator,
        private LoggerInterface $logger,
        private ?TerminalTeamDwellTimeService $terminalTeamService = null
    ) {
    }

    public function calculateCurrentDwellTime(Container $container): int
    {
        if (!$container->getTerminalArrivalDate()) {
            return 0;
        }

        $pausePeriods = $this->getPausePeriodsFromEvents($container);
        
        return $this->calculator->calculateDwellTime(
            $container->getTerminalArrivalDate(),
            $pausePeriods
        );
    }

    public function pauseDwellTime(Container $container, string $reason, ?User $triggeredBy = null): void
    {
        // Don't pause if already paused
        if ($container->getDwellTimePausedAt()) {
            $this->logger->warning('Attempted to pause already paused container', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $currentDwellTime = $this->calculateCurrentDwellTime($container);
        $pauseDate = new \DateTime();

        // Update container fields
        $container->setDwellTimePausedAt($pauseDate);
        $container->setCurrentDwellTime($currentDwellTime);
        $container->setLastDwellTimeCalculation($pauseDate);

        // Create audit event
        $event = new DwellTimeEvent();
        $event->setContainer($container)
            ->setEventType(DwellTimeEventType::PAUSE)
            ->setEventDate($pauseDate)
            ->setDwellTimeAtEvent($currentDwellTime)
            ->setReason($reason)
            ->setTriggeredBy($triggeredBy);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->logger->info('Dwell time paused for container', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'dwell_time_at_pause' => $currentDwellTime,
            'reason' => $reason,
            'triggered_by' => $triggeredBy?->getId()
        ]);
    }

    public function resumeDwellTime(Container $container, ?User $triggeredBy = null): void
    {
        // Don't resume if not paused
        if (!$container->getDwellTimePausedAt()) {
            $this->logger->warning('Attempted to resume non-paused container', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $resumeDate = new \DateTime();
        $pauseStart = $container->getDwellTimePausedAt();
        
        // Calculate pause duration
        $pauseDuration = $pauseStart->diff($resumeDate)->days;
        $newTotalPausedDays = $container->getTotalPausedDays() + $pauseDuration;

        // Update container fields
        $container->setDwellTimePausedAt(null);
        $container->setTotalPausedDays($newTotalPausedDays);
        $container->setLastDwellTimeCalculation($resumeDate);

        // Recalculate notification and return dates
        $this->updateContainerDwellTime($container);

        // Create audit event
        $event = new DwellTimeEvent();
        $event->setContainer($container)
            ->setEventType(DwellTimeEventType::RESUME)
            ->setEventDate($resumeDate)
            ->setDwellTimeAtEvent($this->calculateCurrentDwellTime($container))
            ->setReason("Resumed after {$pauseDuration} days pause")
            ->setTriggeredBy($triggeredBy)
            ->setMetadata([
                'pause_duration_days' => $pauseDuration,
                'total_paused_days' => $newTotalPausedDays
            ]);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->logger->info('Dwell time resumed for container', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'pause_duration_days' => $pauseDuration,
            'total_paused_days' => $newTotalPausedDays,
            'triggered_by' => $triggeredBy?->getId()
        ]);
    }

    public function checkNotificationThresholds(Container $container): array
    {
        $config = $this->getDwellTimeConfiguration();
        $notifications = [];

        if (!$config->isEnableNotifications() || !$container->getTerminalArrivalDate()) {
            return $notifications;
        }

        $currentDwellTime = $this->calculateCurrentDwellTime($container);
        $notificationThreshold = $config->getNotificationThresholdDays();
        $returnThreshold = $config->getAutomaticReturnThresholdDays();

        // Check 60-day notification threshold
        if ($currentDwellTime >= $notificationThreshold && 
            $container->getStatus() !== ContainerStatus::ALERT) {
            
            // Check if notification already sent
            if (!$this->hasNotificationBeenSent($container, DwellTimeEventType::NOTIFICATION_60_DAY)) {
                $notifications[] = [
                    'type' => 'notification_60_day',
                    'dwell_time' => $currentDwellTime,
                    'days_remaining' => max(0, $returnThreshold - $currentDwellTime)
                ];
            }
        }

        return $notifications;
    }

    public function processAutomaticReturn(Container $container): void
    {
        $config = $this->getDwellTimeConfiguration();

        if (!$config->isEnableAutomaticReturns()) {
            return;
        }

        $currentDwellTime = $this->calculateCurrentDwellTime($container);
        $returnThreshold = $config->getAutomaticReturnThresholdDays();

        if ($currentDwellTime >= $returnThreshold && 
            $container->getStatus() !== ContainerStatus::ALERT &&
            $container->getStatus() !== ContainerStatus::RETURNED) {
            
            // Update container status
            $container->setStatus(ContainerStatus::RETURNED);
            $container->setLastDwellTimeCalculation(new \DateTime());

            // Create audit event
            $event = new DwellTimeEvent();
            $event->setContainer($container)
                ->setEventType(DwellTimeEventType::AUTOMATIC_RETURN)
                ->setEventDate(new \DateTime())
                ->setDwellTimeAtEvent($currentDwellTime)
                ->setReason("Automatic return at {$returnThreshold} days dwell time")
                ->setMetadata([
                    'return_threshold' => $returnThreshold,
                    'actual_dwell_time' => $currentDwellTime
                ]);

            $this->entityManager->persist($event);
            $this->entityManager->flush();

            $this->logger->info('Container automatically returned', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber(),
                'dwell_time' => $currentDwellTime,
                'threshold' => $returnThreshold
            ]);
        }
    }

    public function getDwellTimeHistory(Container $container): array
    {
        $events = $container->getDwellTimeEvents()->toArray();
        
        // Sort by event date
        usort($events, fn($a, $b) => $a->getEventDate() <=> $b->getEventDate());
        
        $history = [];
        foreach ($events as $event) {
            $history[] = [
                'event_type' => $event->getEventType()->value,
                'event_date' => $event->getEventDate(),
                'dwell_time_at_event' => $event->getDwellTimeAtEvent(),
                'reason' => $event->getReason(),
                'triggered_by' => $event->getTriggeredBy()?->getId(),
                'metadata' => $event->getMetadata()
            ];
        }
        
        return $history;
    }

    public function updateContainerDwellTime(Container $container): void
    {
        if (!$container->getTerminalArrivalDate()) {
            return;
        }

        $currentDwellTime = $this->calculateCurrentDwellTime($container);
        $container->setCurrentDwellTime($currentDwellTime);
        $container->setLastDwellTimeCalculation(new \DateTime());

        // Update notification and return dates only if not paused
        if (!$container->getDwellTimePausedAt()) {
            $nextNotificationDate = $this->calculator->calculateNextNotificationDate($container);
            $returnDate = $this->calculator->calculateReturnDate($container);
            
            $container->setNextNotificationDate($nextNotificationDate);
            $container->setAutomaticReturnDate($returnDate);
        }

        $this->entityManager->flush();
    }

    /**
     * Handle container status change for dwell time management
     */
    public function handleStatusChange(Container $container, ContainerStatus $oldStatus, ContainerStatus $newStatus, ?User $triggeredBy = null): void
    {
        // Pause dwell time when changing to ALERT status
        if ($newStatus === ContainerStatus::ALERT && $oldStatus !== ContainerStatus::ALERT) {
            $this->pauseDwellTime($container, "Status changed to ALERT", $triggeredBy);
            
            // Notify terminal team about alert status activation
            if ($this->terminalTeamService) {
                $this->terminalTeamService->notifyTerminalTeamAlertStatusChange($container, true, "Status changed to ALERT");
            }
        }
        
        // Resume dwell time when changing from ALERT status
        if ($oldStatus === ContainerStatus::ALERT && $newStatus !== ContainerStatus::ALERT) {
            $this->resumeDwellTime($container, $triggeredBy);
            
            // Notify terminal team about alert status cleared
            if ($this->terminalTeamService) {
                $this->terminalTeamService->notifyTerminalTeamAlertStatusChange($container, false);
            }
        }

        // Start dwell clock on first discharge at port/terminal (CAO 8-2019)
        if ($newStatus === ContainerStatus::AT_TERMINAL && $container->getTerminalArrivalDate() === null) {
            $arrivalDate = new \DateTime();
            $container->setTerminalArrivalDate($arrivalDate);
            $this->updateContainerDwellTime($container);

            $arrivalEvent = new DwellTimeEvent();
            $arrivalEvent->setContainer($container)
                ->setEventType(DwellTimeEventType::ARRIVAL)
                ->setEventDate($arrivalDate)
                ->setDwellTimeAtEvent(0)
                ->setReason('Container discharged at port/terminal — dwell time started')
                ->setTriggeredBy($triggeredBy);
            $this->entityManager->persist($arrivalEvent);
        }

        // Create status change event
        $event = new DwellTimeEvent();
        $event->setContainer($container)
            ->setEventType(DwellTimeEventType::STATUS_CHANGE)
            ->setEventDate(new \DateTime())
            ->setDwellTimeAtEvent($this->calculateCurrentDwellTime($container))
            ->setReason("Status changed from {$oldStatus->value} to {$newStatus->value}")
            ->setTriggeredBy($triggeredBy)
            ->setMetadata([
                'old_status' => $oldStatus->value,
                'new_status' => $newStatus->value
            ]);

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    /**
     * Get pause periods from container's dwell time events
     */
    private function getPausePeriodsFromEvents(Container $container): array
    {
        $pausePeriods = [];
        $events = $container->getDwellTimeEvents()->toArray();
        
        // Sort events by date
        usort($events, fn($a, $b) => $a->getEventDate() <=> $b->getEventDate());
        
        $pauseStart = null;
        
        foreach ($events as $event) {
            if ($event->getEventType() === DwellTimeEventType::PAUSE) {
                $pauseStart = $event->getEventDate();
            } elseif ($event->getEventType() === DwellTimeEventType::RESUME && $pauseStart) {
                $pausePeriods[] = [
                    'start' => $pauseStart,
                    'end' => $event->getEventDate()
                ];
                $pauseStart = null;
            }
        }
        
        // If container is currently paused
        if ($pauseStart && $container->getDwellTimePausedAt()) {
            $pausePeriods[] = [
                'start' => $pauseStart,
                'end' => new \DateTime()
            ];
        }
        
        return $pausePeriods;
    }

    /**
     * Check if a specific notification has already been sent
     */
    private function hasNotificationBeenSent(Container $container, DwellTimeEventType $notificationType): bool
    {
        foreach ($container->getDwellTimeEvents() as $event) {
            if ($event->getEventType() === $notificationType) {
                return true;
            }
        }
        
        return false;
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