<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\DwellTimeConfiguration;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Service\DwellTimeCalculator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DwellTimeCalculatorTest extends TestCase
{
    private DwellTimeCalculator $calculator;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private EntityRepository $configRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configRepository = $this->createMock(EntityRepository::class);
        
        $this->entityManager->method('getRepository')
            ->with(DwellTimeConfiguration::class)
            ->willReturn($this->configRepository);
        
        $this->calculator = new DwellTimeCalculator($this->entityManager, $this->logger);
    }

    public function testCalculateDwellTimeWithoutPausePeriods(): void
    {
        $arrivalDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-11'); // 10 days later
        
        $dwellTime = $this->calculator->calculateDwellTime($arrivalDate, [], $endDate);
        
        $this->assertEquals(10, $dwellTime);
    }

    public function testCalculateDwellTimeWithPausePeriods(): void
    {
        $arrivalDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-11'); // 10 days later
        
        $pausePeriods = [
            [
                'start' => new \DateTime('2024-01-03'),
                'end' => new \DateTime('2024-01-06') // 3 days pause
            ]
        ];
        
        $dwellTime = $this->calculator->calculateDwellTime($arrivalDate, $pausePeriods, $endDate);
        
        $this->assertEquals(7, $dwellTime); // 10 - 3 = 7 days
    }

    public function testCalculateNextNotificationDate(): void
    {
        $config = new DwellTimeConfiguration();
        $config->setNotificationThresholdDays(60);
        $config->setEnableNotifications(true);
        
        $this->configRepository->method('findOneBy')->willReturn($config);
        
        $container = new Container();
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        
        $notificationDate = $this->calculator->calculateNextNotificationDate($container);
        
        $this->assertNotNull($notificationDate);
        $this->assertEquals('2024-02-29', $notificationDate->format('Y-m-d')); // 60 days later (2024 is leap year)
    }

    public function testCalculateReturnDate(): void
    {
        $config = new DwellTimeConfiguration();
        $config->setAutomaticReturnThresholdDays(90);
        
        $this->configRepository->method('findOneBy')->willReturn($config);
        
        $container = new Container();
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        
        $returnDate = $this->calculator->calculateReturnDate($container);
        
        $this->assertEquals('2024-03-30', $returnDate->format('Y-m-d')); // 90 days later (2024 is leap year)
    }

    public function testGetTotalPauseDuration(): void
    {
        $config = new DwellTimeConfiguration();
        $this->configRepository->method('findOneBy')->willReturn($config);
        
        $container = new Container();
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        
        // Create pause and resume events
        $pauseEvent = new DwellTimeEvent();
        $pauseEvent->setEventType(DwellTimeEventType::PAUSE);
        $pauseEvent->setEventDate(new \DateTime('2024-01-05'));
        
        $resumeEvent = new DwellTimeEvent();
        $resumeEvent->setEventType(DwellTimeEventType::RESUME);
        $resumeEvent->setEventDate(new \DateTime('2024-01-10')); // 5 days pause
        
        $container->addDwellTimeEvent($pauseEvent);
        $container->addDwellTimeEvent($resumeEvent);
        
        $totalPauseDuration = $this->calculator->getTotalPauseDuration($container);
        
        $this->assertEquals(5, $totalPauseDuration);
    }

    public function testCalculateDwellTimeNeverNegative(): void
    {
        $arrivalDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2024-01-05'); // 4 days later
        
        // Pause period longer than total time
        $pausePeriods = [
            [
                'start' => new \DateTime('2024-01-01'), // Start from arrival
                'end' => new \DateTime('2024-01-10') // 9 days pause, longer than 4 day period
            ]
        ];
        
        $dwellTime = $this->calculator->calculateDwellTime($arrivalDate, $pausePeriods, $endDate);
        
        $this->assertEquals(0, $dwellTime); // Should never be negative
    }
}