<?php

namespace App\Tests\Fixtures;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\StaffUser;

/**
 * Factory class for generating test data for dwell time management scenarios.
 * 
 * This factory provides methods to create containers with various dwell time scenarios,
 * pause/resume patterns, and edge cases for comprehensive testing.
 */
class DwellTimeTestDataFactory
{
    /**
     * Create a container approaching 60-day notification threshold.
     * 
     * @param int $daysFromThreshold Days before/after 60-day threshold (negative = before, positive = after)
     * @return Container
     */
    public static function createContainerApproaching60Days(int $daysFromThreshold = -5): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('20ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal A');
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        
        // Set arrival date to be 60 + daysFromThreshold days ago
        // If daysFromThreshold is -5, container is 55 days old (5 days before 60)
        // If daysFromThreshold is 5, container is 65 days old (5 days after 60)
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . (60 + $daysFromThreshold) . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        $container->setCurrentDwellTime(60 + $daysFromThreshold);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        // Calculate notification and return dates
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+60 days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+90 days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container approaching 90-day automatic return threshold.
     * 
     * @param int $daysFromThreshold Days before/after 90-day threshold (negative = before, positive = after)
     * @return Container
     */
    public static function createContainerApproaching90Days(int $daysFromThreshold = -5): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('40ft');
        $container->setType('Reefer');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal B');
        $container->setExpectedReturnDate(new \DateTime('+10 days'));
        
        // Set arrival date to be 90 + daysFromThreshold days ago
        // If daysFromThreshold is -5, container is 85 days old (5 days before 90)
        // If daysFromThreshold is 5, container is 95 days old (5 days after 90)
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . (90 + $daysFromThreshold) . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        $container->setCurrentDwellTime(90 + $daysFromThreshold);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        // Notification already sent
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+60 days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+90 days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container in various dwell time stages.
     * 
     * @param string $stage One of: 'early' (0-30 days), 'mid' (30-60 days), 'warning' (60-90 days), 'overdue' (90+ days)
     * @return Container
     */
    public static function createContainerInStage(string $stage): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('20ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal C');
        $container->setExpectedReturnDate(new \DateTime('+60 days'));
        
        $daysAgo = match($stage) {
            'early' => rand(1, 30),
            'mid' => rand(31, 59),
            'warning' => rand(60, 89),
            'overdue' => rand(90, 120),
            default => 15
        };
        
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . $daysAgo . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        $container->setCurrentDwellTime($daysAgo);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        if ($daysAgo < 60) {
            $notificationDate = clone $arrivalDate;
            $notificationDate->modify('+60 days');
            $container->setNextNotificationDate($notificationDate);
        }
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+90 days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container with a single pause/resume cycle.
     * 
     * @param int $pauseDurationDays Duration of the pause in days
     * @param int $totalDaysAgo Total days since arrival
     * @return Container
     */
    public static function createContainerWithSinglePause(int $pauseDurationDays = 10, int $totalDaysAgo = 50): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('20ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal D');
        $container->setExpectedReturnDate(new \DateTime('+40 days'));
        
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . $totalDaysAgo . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        
        // Actual dwell time excludes pause period
        $actualDwellTime = $totalDaysAgo - $pauseDurationDays;
        $container->setCurrentDwellTime($actualDwellTime);
        $container->setTotalPausedDays($pauseDurationDays);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        // Calculate dates accounting for pause
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+' . (60 + $pauseDurationDays) . ' days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+' . (90 + $pauseDurationDays) . ' days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container with multiple pause/resume cycles.
     * 
     * @param array $pauseDurations Array of pause durations in days
     * @param int $totalDaysAgo Total days since arrival
     * @return Container
     */
    public static function createContainerWithMultiplePauses(array $pauseDurations = [5, 10, 7], int $totalDaysAgo = 70): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('40ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal E');
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . $totalDaysAgo . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        
        $totalPauseDays = array_sum($pauseDurations);
        $actualDwellTime = $totalDaysAgo - $totalPauseDays;
        $container->setCurrentDwellTime($actualDwellTime);
        $container->setTotalPausedDays($totalPauseDays);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        // Calculate dates accounting for all pauses
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+' . (60 + $totalPauseDays) . ' days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+' . (90 + $totalPauseDays) . ' days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container currently in alert status (paused).
     * 
     * @param int $daysBeforePause Days of dwell time before pause
     * @param int $daysSincePause Days since the pause started
     * @return Container
     */
    public static function createContainerInAlertStatus(int $daysBeforePause = 45, int $daysSincePause = 10): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('20ft');
        $container->setType('Reefer');
        $container->setStatus(ContainerStatus::ALERT);
        $container->setCurrentLocation('Terminal F');
        $container->setExpectedReturnDate(new \DateTime('+45 days'));
        
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . ($daysBeforePause + $daysSincePause) . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        
        $pauseDate = new \DateTime();
        $pauseDate->modify('-' . $daysSincePause . ' days');
        $container->setDwellTimePausedAt($pauseDate);
        
        // Dwell time frozen at pause point
        $container->setCurrentDwellTime($daysBeforePause);
        $container->setLastDwellTimeCalculation($pauseDate);
        
        // Dates calculated before pause
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+60 days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+90 days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container that has been resumed from alert status.
     * 
     * @param int $pauseDuration Duration of the pause in days
     * @param int $daysSinceResume Days since resume
     * @return Container
     */
    public static function createResumedContainer(int $pauseDuration = 15, int $daysSinceResume = 5): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('40ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal G');
        $container->setExpectedReturnDate(new \DateTime('+35 days'));
        
        $totalDaysAgo = 50 + $pauseDuration + $daysSinceResume;
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . $totalDaysAgo . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        
        // Dwell time excludes pause period
        $actualDwellTime = $totalDaysAgo - $pauseDuration;
        $container->setCurrentDwellTime($actualDwellTime);
        $container->setTotalPausedDays($pauseDuration);
        $container->setDwellTimePausedAt(null); // Resumed
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        // Recalculated dates after resume
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+' . (60 + $pauseDuration) . ' days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+' . (90 + $pauseDuration) . ' days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container that arrived on a leap year (edge case).
     * 
     * @param int $daysAgo Days since arrival
     * @return Container
     */
    public static function createLeapYearContainer(int $daysAgo = 65): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('20ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal H');
        $container->setExpectedReturnDate(new \DateTime('+25 days'));
        
        // Set arrival to Feb 29, 2024 (leap year)
        $arrivalDate = new \DateTime('2024-02-29');
        $arrivalDate->modify('-' . $daysAgo . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        $container->setCurrentDwellTime($daysAgo);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+60 days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+90 days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container with notification already sent.
     * 
     * @param int $daysSinceNotification Days since 60-day notification was sent
     * @return Container
     */
    public static function createContainerWithNotificationSent(int $daysSinceNotification = 5): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('40ft');
        $container->setType('Reefer');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentLocation('Terminal I');
        $container->setExpectedReturnDate(new \DateTime('+25 days'));
        
        $daysAgo = 60 + $daysSinceNotification;
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . $daysAgo . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        $container->setCurrentDwellTime($daysAgo);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        // Notification date is in the past
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+60 days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+90 days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create a container that has been automatically returned.
     * 
     * @param int $daysOverThreshold Days over the 90-day threshold
     * @return Container
     */
    public static function createReturnedContainer(int $daysOverThreshold = 5): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . str_pad((string)rand(1000000, 9999999), 10, '0', STR_PAD_LEFT));
        $container->setSize('20ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::RETURNED);
        $container->setCurrentLocation('Terminal J');
        $container->setExpectedReturnDate(new \DateTime('-' . $daysOverThreshold . ' days'));
        
        $daysAgo = 90 + $daysOverThreshold;
        $arrivalDate = new \DateTime();
        $arrivalDate->modify('-' . $daysAgo . ' days');
        $container->setTerminalArrivalDate($arrivalDate);
        $container->setCurrentDwellTime($daysAgo);
        $container->setLastDwellTimeCalculation(new \DateTime());
        
        $notificationDate = clone $arrivalDate;
        $notificationDate->modify('+60 days');
        $container->setNextNotificationDate($notificationDate);
        
        $returnDate = clone $arrivalDate;
        $returnDate->modify('+90 days');
        $container->setAutomaticReturnDate($returnDate);
        
        return $container;
    }

    /**
     * Create dwell time events for a container with complete audit trail.
     * 
     * @param Container $container The container to create events for
     * @param StaffUser|null $user The user who triggered events
     * @return array Array of DwellTimeEvent objects
     */
    public static function createCompleteAuditTrail(Container $container, ?StaffUser $user = null): array
    {
        $events = [];
        $arrivalDate = $container->getTerminalArrivalDate();
        
        // Arrival event
        $arrivalEvent = new DwellTimeEvent();
        $arrivalEvent->setContainer($container);
        $arrivalEvent->setEventType(DwellTimeEventType::ARRIVAL);
        $arrivalEvent->setEventDate(clone $arrivalDate);
        $arrivalEvent->setDwellTimeAtEvent(0);
        $arrivalEvent->setReason('Container arrived at terminal');
        $arrivalEvent->setMetadata(['location' => $container->getCurrentLocation()]);
        if ($user) {
            $arrivalEvent->setTriggeredBy($user);
        }
        $events[] = $arrivalEvent;
        
        // Pause event (if applicable)
        if ($container->getTotalPausedDays() > 0) {
            $pauseDate = clone $arrivalDate;
            $pauseDate->modify('+30 days');
            
            $pauseEvent = new DwellTimeEvent();
            $pauseEvent->setContainer($container);
            $pauseEvent->setEventType(DwellTimeEventType::PAUSE);
            $pauseEvent->setEventDate($pauseDate);
            $pauseEvent->setDwellTimeAtEvent(30);
            $pauseEvent->setReason('Container status changed to ALERT');
            $pauseEvent->setMetadata(['previous_status' => 'AT_TERMINAL', 'new_status' => 'ALERT']);
            if ($user) {
                $pauseEvent->setTriggeredBy($user);
            }
            $events[] = $pauseEvent;
            
            // Resume event (if not currently paused)
            if (!$container->getDwellTimePausedAt()) {
                $resumeDate = clone $pauseDate;
                $resumeDate->modify('+' . $container->getTotalPausedDays() . ' days');
                
                $resumeEvent = new DwellTimeEvent();
                $resumeEvent->setContainer($container);
                $resumeEvent->setEventType(DwellTimeEventType::RESUME);
                $resumeEvent->setEventDate($resumeDate);
                $resumeEvent->setDwellTimeAtEvent(30);
                $resumeEvent->setReason('Container status changed from ALERT to AT_TERMINAL');
                $resumeEvent->setMetadata([
                    'previous_status' => 'ALERT',
                    'new_status' => 'AT_TERMINAL',
                    'pause_duration_days' => $container->getTotalPausedDays()
                ]);
                if ($user) {
                    $resumeEvent->setTriggeredBy($user);
                }
                $events[] = $resumeEvent;
            }
        }
        
        // Notification event (if past 60 days and not in alert status)
        if ($container->getCurrentDwellTime() >= 60 && $container->getStatus() !== ContainerStatus::ALERT) {
            $notificationDate = clone $arrivalDate;
            $notificationDate->modify('+' . (60 + $container->getTotalPausedDays()) . ' days');
            
            $notificationEvent = new DwellTimeEvent();
            $notificationEvent->setContainer($container);
            $notificationEvent->setEventType(DwellTimeEventType::NOTIFICATION_60_DAY);
            $notificationEvent->setEventDate($notificationDate);
            $notificationEvent->setDwellTimeAtEvent(60);
            $notificationEvent->setReason('60-day dwell time notification sent');
            $notificationEvent->setMetadata([
                'days_remaining' => 30,
                'notification_channels' => ['email', 'sms']
            ]);
            $events[] = $notificationEvent;
        }
        
        // Automatic return event (if returned)
        if ($container->getStatus() === ContainerStatus::RETURNED) {
            $returnDate = clone $arrivalDate;
            $returnDate->modify('+' . (90 + $container->getTotalPausedDays()) . ' days');
            
            $returnEvent = new DwellTimeEvent();
            $returnEvent->setContainer($container);
            $returnEvent->setEventType(DwellTimeEventType::AUTOMATIC_RETURN);
            $returnEvent->setEventDate($returnDate);
            $returnEvent->setDwellTimeAtEvent(90);
            $returnEvent->setReason('Automatic return triggered at 90 days dwell time');
            $returnEvent->setMetadata([
                'previous_status' => 'AT_TERMINAL',
                'new_status' => 'RETURNED',
                'return_location' => $container->getCurrentLocation()
            ]);
            $events[] = $returnEvent;
        }
        
        return $events;
    }

    /**
     * Create a batch of containers with various scenarios for comprehensive testing.
     * 
     * @param int $count Number of containers to create
     * @return array Array of Container objects
     */
    public static function createMixedScenarioBatch(int $count = 20): array
    {
        $containers = [];
        
        for ($i = 0; $i < $count; $i++) {
            $scenario = $i % 10;
            
            $container = match($scenario) {
                0 => self::createContainerApproaching60Days(-5),
                1 => self::createContainerApproaching60Days(5),
                2 => self::createContainerApproaching90Days(-5),
                3 => self::createContainerApproaching90Days(2),
                4 => self::createContainerInStage('early'),
                5 => self::createContainerInStage('mid'),
                6 => self::createContainerWithSinglePause(10, 50),
                7 => self::createContainerWithMultiplePauses([5, 10, 7], 70),
                8 => self::createContainerInAlertStatus(45, 10),
                9 => self::createResumedContainer(15, 5),
                default => self::createContainerInStage('early')
            };
            
            $containers[] = $container;
        }
        
        return $containers;
    }
}
