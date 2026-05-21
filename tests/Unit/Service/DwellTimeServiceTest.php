<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\DwellTimeConfiguration;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\StaffUser;
use App\Service\DwellTimeCalculatorInterface;
use App\Service\DwellTimeService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DwellTimeServiceTest extends TestCase
{
    private DwellTimeService $service;
    private EntityManagerInterface $entityManager;
    private DwellTimeCalculatorInterface $calculator;
    private LoggerInterface $logger;
    private EntityRepository $configRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->calculator = $this->createMock(DwellTimeCalculatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configRepository = $this->createMock(EntityRepository::class);
        
        $this->entityManager->method('getRepository')
            ->with(DwellTimeConfiguration::class)
            ->willReturn($this->configRepository);
        
        $this->service = new DwellTimeService($this->entityManager, $this->calculator, $this->logger);
    }

    public function testCalculateCurrentDwellTimeWithoutArrivalDate(): void
    {
        $container = new Container();
        $container->setTerminalArrivalDate(null);
        
        $dwellTime = $this->service->calculateCurrentDwellTime($container);
        
        $this->assertEquals(0, $dwellTime);
    }

    public function testCalculateCurrentDwellTimeWithArrivalDate(): void
    {
        $container = new Container();
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        
        $this->calculator->expects($this->once())
            ->method('calculateDwellTime')
            ->willReturn(30);
        
        $dwellTime = $this->service->calculateCurrentDwellTime($container);
        
        $this->assertEquals(30, $dwellTime);
    }

    public function testPauseDwellTime(): void
    {
        $container = new Container();
        // Use reflection to set ID since there's no setter
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);
        
        $container->setContainerNumber('TEST123');
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setDwellTimePausedAt(null); // Not currently paused
        
        $user = new StaffUser();
        $user->setId(1);
        
        $this->calculator->method('calculateDwellTime')->willReturn(30);
        
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        
        $this->service->pauseDwellTime($container, 'Test pause', $user);
        
        $this->assertNotNull($container->getDwellTimePausedAt());
        $this->assertEquals(30, $container->getCurrentDwellTime());
    }

    public function testResumeDwellTime(): void
    {
        $pauseDate = new \DateTime('2024-01-10');
        
        $container = new Container();
        // Use reflection to set ID since there's no setter
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);
        
        $container->setContainerNumber('TEST123');
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setDwellTimePausedAt($pauseDate);
        $container->setTotalPausedDays(0);
        
        $user = new StaffUser();
        $user->setId(1);
        
        $this->calculator->method('calculateDwellTime')->willReturn(35);
        $this->calculator->method('calculateNextNotificationDate')->willReturn(new \DateTime('2024-03-01'));
        $this->calculator->method('calculateReturnDate')->willReturn(new \DateTime('2024-04-01'));
        
        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');
        
        $this->service->resumeDwellTime($container, $user);
        
        $this->assertNull($container->getDwellTimePausedAt());
        $this->assertGreaterThan(0, $container->getTotalPausedDays());
    }

    public function testCheckNotificationThresholds(): void
    {
        $config = new DwellTimeConfiguration();
        $config->setNotificationThresholdDays(60);
        $config->setAutomaticReturnThresholdDays(90);
        $config->setEnableNotifications(true);
        
        $this->configRepository->method('findOneBy')->willReturn($config);
        
        $container = new Container();
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        
        $this->calculator->method('calculateDwellTime')->willReturn(65); // Over threshold
        
        $notifications = $this->service->checkNotificationThresholds($container);
        
        $this->assertCount(1, $notifications);
        $this->assertEquals('notification_60_day', $notifications[0]['type']);
        $this->assertEquals(65, $notifications[0]['dwell_time']);
        $this->assertEquals(25, $notifications[0]['days_remaining']); // 90 - 65
    }

    public function testProcessAutomaticReturn(): void
    {
        $config = new DwellTimeConfiguration();
        $config->setAutomaticReturnThresholdDays(90);
        $config->setEnableAutomaticReturns(true);
        
        $this->configRepository->method('findOneBy')->willReturn($config);
        
        $container = new Container();
        // Use reflection to set ID since there's no setter
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);
        
        $container->setContainerNumber('TEST123');
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        
        $this->calculator->method('calculateDwellTime')->willReturn(95); // Over threshold
        
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        
        $this->service->processAutomaticReturn($container);
        
        $this->assertEquals(ContainerStatus::RETURNED, $container->getStatus());
    }

    public function testHandleStatusChangeToAlert(): void
    {
        $container = new Container();
        // Use reflection to set ID since there's no setter
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);
        
        $container->setContainerNumber('TEST123');
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setDwellTimePausedAt(null);
        
        $user = new StaffUser();
        $user->setId(1);
        
        $this->calculator->method('calculateDwellTime')->willReturn(30);
        
        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');
        
        $this->service->handleStatusChange(
            $container, 
            ContainerStatus::AT_TERMINAL, 
            ContainerStatus::ALERT, 
            $user
        );
        
        $this->assertNotNull($container->getDwellTimePausedAt());
    }

    public function testHandleStatusChangeFromAlert(): void
    {
        $pauseDate = new \DateTime('2024-01-10');
        
        $container = new Container();
        // Use reflection to set ID since there's no setter
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);
        
        $container->setContainerNumber('TEST123');
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setDwellTimePausedAt($pauseDate);
        $container->setTotalPausedDays(0);
        
        $user = new StaffUser();
        $user->setId(1);
        
        $this->calculator->method('calculateDwellTime')->willReturn(35);
        $this->calculator->method('calculateNextNotificationDate')->willReturn(new \DateTime('2024-03-01'));
        $this->calculator->method('calculateReturnDate')->willReturn(new \DateTime('2024-04-01'));
        
        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');
        
        $this->service->handleStatusChange(
            $container, 
            ContainerStatus::ALERT, 
            ContainerStatus::AT_TERMINAL, 
            $user
        );
        
        $this->assertNull($container->getDwellTimePausedAt());
    }
}