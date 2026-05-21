<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\DwellTimeConfiguration;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DwellTimeCalculator implements DwellTimeCalculatorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function calculateDwellTime(
        \DateTime $arrivalDate, 
        array $pausePeriods = [], 
        ?\DateTime $endDate = null
    ): int {
        $endDate = $endDate ?? new \DateTime();
        
        // Ensure we're working with timezone-consistent dates
        $arrivalDate = $this->normalizeToTimezone($arrivalDate);
        $endDate = $this->normalizeToTimezone($endDate);
        
        // Calculate total calendar days from arrival to end date
        $totalDays = $arrivalDate->diff($endDate)->days;
        
        // Subtract pause periods
        $pausedDays = $this->calculatePausedDays($pausePeriods, $arrivalDate, $endDate);
        
        $dwellTime = max(0, $totalDays - $pausedDays);
        
        $this->logger->debug('Dwell time calculated', [
            'arrival_date' => $arrivalDate->format('Y-m-d H:i:s'),
            'end_date' => $endDate->format('Y-m-d H:i:s'),
            'total_days' => $totalDays,
            'paused_days' => $pausedDays,
            'dwell_time' => $dwellTime
        ]);
        
        return $dwellTime;
    }

    public function calculateNextNotificationDate(Container $container): ?\DateTime
    {
        $config = $this->getDwellTimeConfiguration();
        
        if (!$config->isEnableNotifications() || !$container->getTerminalArrivalDate()) {
            return null;
        }
        
        $arrivalDate = $this->normalizeToTimezone($container->getTerminalArrivalDate());
        $pausePeriods = $this->getPausePeriodsFromEvents($container);
        
        // Calculate when the container will reach notification threshold
        $targetDate = clone $arrivalDate;
        $daysAdded = 0;
        $notificationThreshold = $config->getNotificationThresholdDays();
        
        while ($daysAdded < $notificationThreshold) {
            $targetDate->add(new \DateInterval('P1D'));
            
            // Check if this day is within a pause period
            if (!$this->isDateInPausePeriods($targetDate, $pausePeriods)) {
                $daysAdded++;
            }
        }
        
        return $targetDate;
    }

    public function calculateReturnDate(Container $container): \DateTime
    {
        $config = $this->getDwellTimeConfiguration();
        $arrivalDate = $this->normalizeToTimezone($container->getTerminalArrivalDate());
        $pausePeriods = $this->getPausePeriodsFromEvents($container);
        
        // Calculate when the container will reach return threshold
        $targetDate = clone $arrivalDate;
        $daysAdded = 0;
        $returnThreshold = $config->getAutomaticReturnThresholdDays();
        
        while ($daysAdded < $returnThreshold) {
            $targetDate->add(new \DateInterval('P1D'));
            
            // Check if this day is within a pause period
            if (!$this->isDateInPausePeriods($targetDate, $pausePeriods)) {
                $daysAdded++;
            }
        }
        
        return $targetDate;
    }

    public function getTotalPauseDuration(Container $container): int
    {
        $pausePeriods = $this->getPausePeriodsFromEvents($container);
        $arrivalDate = $container->getTerminalArrivalDate();
        $endDate = new \DateTime();
        
        if (!$arrivalDate) {
            return 0;
        }
        
        return $this->calculatePausedDays($pausePeriods, $arrivalDate, $endDate);
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
        
        // If container is currently paused (no resume event after last pause)
        if ($pauseStart && $container->getStatus() === ContainerStatus::ALERT) {
            $pausePeriods[] = [
                'start' => $pauseStart,
                'end' => new \DateTime() // Current time
            ];
        }
        
        return $pausePeriods;
    }

    /**
     * Calculate total paused days within the given date range
     */
    private function calculatePausedDays(array $pausePeriods, \DateTime $startDate, \DateTime $endDate): int
    {
        $totalPausedDays = 0;
        
        foreach ($pausePeriods as $period) {
            $pauseStart = $this->normalizeToTimezone($period['start']);
            $pauseEnd = $this->normalizeToTimezone($period['end']);
            
            // Clamp pause period to the calculation range
            $effectiveStart = max($pauseStart, $startDate);
            $effectiveEnd = min($pauseEnd, $endDate);
            
            // Only count if there's an overlap
            if ($effectiveStart <= $effectiveEnd) {
                $pausedDays = $effectiveStart->diff($effectiveEnd)->days;
                $totalPausedDays += $pausedDays;
            }
        }
        
        return $totalPausedDays;
    }

    /**
     * Check if a given date falls within any pause period
     */
    private function isDateInPausePeriods(\DateTime $date, array $pausePeriods): bool
    {
        foreach ($pausePeriods as $period) {
            $pauseStart = $this->normalizeToTimezone($period['start']);
            $pauseEnd = $this->normalizeToTimezone($period['end']);
            
            if ($date >= $pauseStart && $date <= $pauseEnd) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Normalize date to system timezone for consistent calculations
     */
    private function normalizeToTimezone(\DateTime $date): \DateTime
    {
        $config = $this->getDwellTimeConfiguration();
        $timezone = new \DateTimeZone($config->getTimezone());
        
        $normalized = clone $date;
        $normalized->setTimezone($timezone);
        
        return $normalized;
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
            
            $this->logger->info('Created default dwell time configuration');
        }
        
        return $config;
    }
}