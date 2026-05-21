<?php

namespace App\Tests\Entity;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\DwellTimeConfiguration;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use PHPUnit\Framework\TestCase;

class DwellTimeEntityTest extends TestCase
{
    public function testContainerDwellTimeFields(): void
    {
        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setSize('20');
        $container->setType('DRY');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        
        // Test dwell time fields
        $arrivalDate = new \DateTime('-10 days');
        $container->setTerminalArrivalDate($arrivalDate);
        $container->setCurrentDwellTime(10);
        $container->setLastDwellTimeCalculation(new \DateTime());
        $container->setTotalPausedDays(2);
        $container->setNextNotificationDate(new \DateTime('+50 days'));
        $container->setAutomaticReturnDate(new \DateTime('+80 days'));
        
        $this->assertEquals($arrivalDate, $container->getTerminalArrivalDate());
        $this->assertEquals(10, $container->getCurrentDwellTime());
        $this->assertInstanceOf(\DateTime::class, $container->getLastDwellTimeCalculation());
        $this->assertEquals(2, $container->getTotalPausedDays());
        $this->assertInstanceOf(\DateTime::class, $container->getNextNotificationDate());
        $this->assertInstanceOf(\DateTime::class, $container->getAutomaticReturnDate());
    }
    
    public function testContainerAlertStatus(): void
    {
        $container = new Container();
        $container->setStatus(ContainerStatus::ALERT);
        
        $this->assertEquals(ContainerStatus::ALERT, $container->getStatus());
    }
    
    public function testDwellTimeEvent(): void
    {
        $container = new Container();
        $container->setContainerNumber('TEST456');
        $container->setSize('40');
        $container->setType('REEFER');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        
        $event = new DwellTimeEvent();
        $event->setContainer($container);
        $event->setEventType(DwellTimeEventType::ARRIVAL);
        $event->setDwellTimeAtEvent(0);
        $event->setReason('Container arrived at terminal');
        $event->setMetadata(['location' => 'Terminal A', 'gate' => '1']);
        
        $this->assertEquals($container, $event->getContainer());
        $this->assertEquals(DwellTimeEventType::ARRIVAL, $event->getEventType());
        $this->assertEquals(0, $event->getDwellTimeAtEvent());
        $this->assertEquals('Container arrived at terminal', $event->getReason());
        $this->assertEquals(['location' => 'Terminal A', 'gate' => '1'], $event->getMetadata());
        $this->assertInstanceOf(\DateTime::class, $event->getEventDate());
    }
    
    public function testDwellTimeConfiguration(): void
    {
        $config = new DwellTimeConfiguration();
        $config->setNotificationThresholdDays(60);
        $config->setAutomaticReturnThresholdDays(90);
        $config->setTimezone('UTC');
        $config->setEnableAutomaticReturns(true);
        $config->setEnableNotifications(true);
        
        $this->assertEquals(60, $config->getNotificationThresholdDays());
        $this->assertEquals(90, $config->getAutomaticReturnThresholdDays());
        $this->assertEquals('UTC', $config->getTimezone());
        $this->assertTrue($config->isEnableAutomaticReturns());
        $this->assertTrue($config->isEnableNotifications());
    }
    
    public function testContainerDwellTimeEventRelationship(): void
    {
        $container = new Container();
        $container->setContainerNumber('TEST789');
        $container->setSize('20');
        $container->setType('DRY');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        
        $event1 = new DwellTimeEvent();
        $event1->setEventType(DwellTimeEventType::ARRIVAL);
        $event1->setDwellTimeAtEvent(0);
        
        $event2 = new DwellTimeEvent();
        $event2->setEventType(DwellTimeEventType::PAUSE);
        $event2->setDwellTimeAtEvent(5);
        
        $container->addDwellTimeEvent($event1);
        $container->addDwellTimeEvent($event2);
        
        $this->assertCount(2, $container->getDwellTimeEvents());
        $this->assertTrue($container->getDwellTimeEvents()->contains($event1));
        $this->assertTrue($container->getDwellTimeEvents()->contains($event2));
        $this->assertEquals($container, $event1->getContainer());
        $this->assertEquals($container, $event2->getContainer());
    }
}