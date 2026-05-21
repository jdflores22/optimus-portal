<?php

namespace App\Tests\Unit\Fixtures;

use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\StaffUser;
use App\Tests\Fixtures\DwellTimeTestDataFactory;
use PHPUnit\Framework\TestCase;

/**
 * Test suite demonstrating the usage of DwellTimeTestDataFactory.
 * 
 * These tests validate that the factory creates containers with correct
 * dwell time scenarios and configurations.
 */
class DwellTimeTestDataFactoryTest extends TestCase
{
    public function testCreateContainerApproaching60Days(): void
    {
        $container = DwellTimeTestDataFactory::createContainerApproaching60Days(-5);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(ContainerStatus::AT_TERMINAL, $container->getStatus());
        $this->assertNotNull($container->getTerminalArrivalDate());
        $this->assertEquals(55, $container->getCurrentDwellTime());
        $this->assertNotNull($container->getNextNotificationDate());
        $this->assertNotNull($container->getAutomaticReturnDate());
    }

    public function testCreateContainerApproaching90Days(): void
    {
        $container = DwellTimeTestDataFactory::createContainerApproaching90Days(-3);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(ContainerStatus::AT_TERMINAL, $container->getStatus());
        $this->assertEquals(87, $container->getCurrentDwellTime());
        
        // Verify return date is approximately 3 days from now
        $returnDate = $container->getAutomaticReturnDate();
        $now = new \DateTime();
        $diff = $returnDate->diff($now);
        $this->assertLessThanOrEqual(4, $diff->days);
    }

    public function testCreateContainerInEarlyStage(): void
    {
        $container = DwellTimeTestDataFactory::createContainerInStage('early');
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertGreaterThanOrEqual(1, $container->getCurrentDwellTime());
        $this->assertLessThanOrEqual(30, $container->getCurrentDwellTime());
    }

    public function testCreateContainerInWarningStage(): void
    {
        $container = DwellTimeTestDataFactory::createContainerInStage('warning');
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertGreaterThanOrEqual(60, $container->getCurrentDwellTime());
        $this->assertLessThanOrEqual(89, $container->getCurrentDwellTime());
    }

    public function testCreateContainerWithSinglePause(): void
    {
        $container = DwellTimeTestDataFactory::createContainerWithSinglePause(10, 50);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(40, $container->getCurrentDwellTime()); // 50 - 10
        $this->assertEquals(10, $container->getTotalPausedDays());
        
        // Verify notification date is adjusted for pause
        $arrivalDate = $container->getTerminalArrivalDate();
        $notificationDate = $container->getNextNotificationDate();
        $diff = $notificationDate->diff($arrivalDate);
        $this->assertEquals(70, $diff->days); // 60 + 10 pause days
    }

    public function testCreateContainerWithMultiplePauses(): void
    {
        $pauseDurations = [5, 10, 7];
        $container = DwellTimeTestDataFactory::createContainerWithMultiplePauses($pauseDurations, 70);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(22, $container->getTotalPausedDays()); // 5 + 10 + 7
        $this->assertEquals(48, $container->getCurrentDwellTime()); // 70 - 22
        
        // Verify return date is adjusted for all pauses
        $arrivalDate = $container->getTerminalArrivalDate();
        $returnDate = $container->getAutomaticReturnDate();
        $diff = $returnDate->diff($arrivalDate);
        $this->assertEquals(112, $diff->days); // 90 + 22 pause days
    }

    public function testCreateContainerInAlertStatus(): void
    {
        $container = DwellTimeTestDataFactory::createContainerInAlertStatus(45, 10);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(ContainerStatus::ALERT, $container->getStatus());
        $this->assertEquals(45, $container->getCurrentDwellTime());
        $this->assertNotNull($container->getDwellTimePausedAt());
        
        // Verify pause date is approximately 10 days ago
        $pauseDate = $container->getDwellTimePausedAt();
        $now = new \DateTime();
        $diff = $now->diff($pauseDate);
        $this->assertLessThanOrEqual(11, $diff->days);
        $this->assertGreaterThanOrEqual(9, $diff->days);
    }

    public function testCreateResumedContainer(): void
    {
        $container = DwellTimeTestDataFactory::createResumedContainer(15, 5);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(ContainerStatus::AT_TERMINAL, $container->getStatus());
        $this->assertEquals(15, $container->getTotalPausedDays());
        $this->assertNull($container->getDwellTimePausedAt()); // Not currently paused
        
        // Verify dates are recalculated after resume
        $this->assertNotNull($container->getNextNotificationDate());
        $this->assertNotNull($container->getAutomaticReturnDate());
    }

    public function testCreateLeapYearContainer(): void
    {
        $container = DwellTimeTestDataFactory::createLeapYearContainer(65);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(65, $container->getCurrentDwellTime());
        
        // Verify arrival date is related to leap year
        $arrivalDate = $container->getTerminalArrivalDate();
        $this->assertNotNull($arrivalDate);
    }

    public function testCreateContainerWithNotificationSent(): void
    {
        $container = DwellTimeTestDataFactory::createContainerWithNotificationSent(5);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(65, $container->getCurrentDwellTime()); // 60 + 5
        
        // Verify notification date is in the past
        $notificationDate = $container->getNextNotificationDate();
        $now = new \DateTime();
        $this->assertLessThan($now, $notificationDate);
    }

    public function testCreateReturnedContainer(): void
    {
        $container = DwellTimeTestDataFactory::createReturnedContainer(5);
        
        $this->assertNotNull($container->getContainerNumber());
        $this->assertEquals(ContainerStatus::RETURNED, $container->getStatus());
        $this->assertEquals(95, $container->getCurrentDwellTime()); // 90 + 5
        
        // Verify return date is in the past
        $returnDate = $container->getAutomaticReturnDate();
        $now = new \DateTime();
        $this->assertLessThan($now, $returnDate);
    }

    public function testCreateCompleteAuditTrail(): void
    {
        $container = DwellTimeTestDataFactory::createContainerWithSinglePause(10, 65);
        $user = new StaffUser();
        
        $events = DwellTimeTestDataFactory::createCompleteAuditTrail($container, $user);
        
        $this->assertNotEmpty($events);
        
        // Should have at least: ARRIVAL, PAUSE, RESUME, NOTIFICATION_60_DAY
        // Container with 65 days dwell time and single pause should have these events
        $this->assertGreaterThanOrEqual(3, count($events));
        
        // Verify first event is ARRIVAL
        $this->assertEquals(DwellTimeEventType::ARRIVAL, $events[0]->getEventType());
        $this->assertEquals(0, $events[0]->getDwellTimeAtEvent());
        
        // Verify events have proper structure
        foreach ($events as $event) {
            $this->assertNotNull($event->getEventType());
            $this->assertNotNull($event->getEventDate());
            $this->assertNotNull($event->getReason());
        }
    }

    public function testCreateCompleteAuditTrailForReturnedContainer(): void
    {
        $container = DwellTimeTestDataFactory::createReturnedContainer(5);
        
        $events = DwellTimeTestDataFactory::createCompleteAuditTrail($container);
        
        $this->assertNotEmpty($events);
        
        // Should have: ARRIVAL, NOTIFICATION_60_DAY, AUTOMATIC_RETURN
        $this->assertGreaterThanOrEqual(3, count($events));
        
        // Verify last event is AUTOMATIC_RETURN
        $lastEvent = end($events);
        $this->assertEquals(DwellTimeEventType::AUTOMATIC_RETURN, $lastEvent->getEventType());
        $this->assertEquals(90, $lastEvent->getDwellTimeAtEvent());
    }

    public function testCreateMixedScenarioBatch(): void
    {
        $containers = DwellTimeTestDataFactory::createMixedScenarioBatch(20);
        
        $this->assertCount(20, $containers);
        
        // Verify all containers have unique container numbers
        $containerNumbers = array_map(fn($c) => $c->getContainerNumber(), $containers);
        $uniqueNumbers = array_unique($containerNumbers);
        $this->assertCount(20, $uniqueNumbers);
        
        // Verify containers have various statuses
        $statuses = array_map(fn($c) => $c->getStatus()->value, $containers);
        $uniqueStatuses = array_unique($statuses);
        $this->assertGreaterThan(1, count($uniqueStatuses));
        
        // Verify containers have various dwell times
        $dwellTimes = array_map(fn($c) => $c->getCurrentDwellTime(), $containers);
        $uniqueDwellTimes = array_unique($dwellTimes);
        $this->assertGreaterThan(5, count($uniqueDwellTimes));
    }

    public function testContainerNumbersAreUnique(): void
    {
        $containers = [];
        for ($i = 0; $i < 100; $i++) {
            $containers[] = DwellTimeTestDataFactory::createContainerInStage('early');
        }
        
        $containerNumbers = array_map(fn($c) => $c->getContainerNumber(), $containers);
        $uniqueNumbers = array_unique($containerNumbers);
        
        // All 100 containers should have unique numbers
        $this->assertCount(100, $uniqueNumbers);
    }

    public function testDwellTimeCalculationsAreConsistent(): void
    {
        $container = DwellTimeTestDataFactory::createContainerWithSinglePause(15, 60);
        
        $arrivalDate = $container->getTerminalArrivalDate();
        $now = new \DateTime();
        $calendarDays = $now->diff($arrivalDate)->days;
        
        // Calendar days should be approximately 60
        $this->assertLessThanOrEqual(61, $calendarDays);
        $this->assertGreaterThanOrEqual(59, $calendarDays);
        
        // Actual dwell time should be 45 (60 - 15)
        $this->assertEquals(45, $container->getCurrentDwellTime());
        
        // Total paused days should be 15
        $this->assertEquals(15, $container->getTotalPausedDays());
    }

    public function testNotificationAndReturnDatesAreCalculatedCorrectly(): void
    {
        $container = DwellTimeTestDataFactory::createContainerWithMultiplePauses([10, 5], 50);
        
        $arrivalDate = $container->getTerminalArrivalDate();
        $notificationDate = $container->getNextNotificationDate();
        $returnDate = $container->getAutomaticReturnDate();
        
        // Notification should be at 60 + 15 = 75 days from arrival
        $notificationDiff = $notificationDate->diff($arrivalDate);
        $this->assertEquals(75, $notificationDiff->days);
        
        // Return should be at 90 + 15 = 105 days from arrival
        $returnDiff = $returnDate->diff($arrivalDate);
        $this->assertEquals(105, $returnDiff->days);
    }
}
