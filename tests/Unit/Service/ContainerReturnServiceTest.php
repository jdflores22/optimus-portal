<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\DwellTimeConfiguration;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\User;
use App\Service\ContainerReturnService;
use App\Service\DwellTimeAuditService;
use App\Service\DwellTimeNotificationService;
use App\Service\DwellTimeServiceInterface;
use App\Service\TerminalTeamDwellTimeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContainerReturnServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DwellTimeServiceInterface $dwellTimeService;
    private DwellTimeNotificationService $notificationService;
    private TerminalTeamDwellTimeService $terminalTeamService;
    private DwellTimeAuditService $auditService;
    private LoggerInterface $logger;
    private ContainerReturnService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->dwellTimeService = $this->createMock(DwellTimeServiceInterface::class);
        $this->notificationService = $this->createMock(DwellTimeNotificationService::class);
        $this->terminalTeamService = $this->createMock(TerminalTeamDwellTimeService::class);
        $this->auditService = $this->createMock(DwellTimeAuditService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ContainerReturnService(
            $this->entityManager,
            $this->dwellTimeService,
            $this->notificationService,
            $this->terminalTeamService,
            $this->auditService,
            $this->logger
        );
    }

    public function testProcessAutomaticReturnSuccessfully(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::AT_TERMINAL, 95);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->method('calculateCurrentDwellTime')
            ->with($container)
            ->willReturn(95);

        $this->entityManager->expects($this->once())
            ->method('beginTransaction');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->entityManager->expects($this->once())
            ->method('commit');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(DwellTimeEvent::class));

        $this->notificationService->expects($this->once())
            ->method('sendAutomaticReturnNotification')
            ->with($container);

        $this->terminalTeamService->expects($this->once())
            ->method('notifyTerminalTeamAutomaticReturn')
            ->with($container);

        // Act
        $result = $this->service->processAutomaticReturn($container);

        // Assert
        $this->assertTrue($result);
        $this->assertEquals(ContainerStatus::RETURNED, $container->getStatus());
    }

    public function testProcessAutomaticReturnNotEligibleDueToAlertStatus(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::ALERT, 95);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->expects($this->never())
            ->method('calculateCurrentDwellTime');

        // Act
        $result = $this->service->processAutomaticReturn($container);

        // Assert
        $this->assertFalse($result);
        $this->assertEquals(ContainerStatus::ALERT, $container->getStatus());
    }

    public function testProcessAutomaticReturnNotEligibleAlreadyReturned(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::RETURNED, 95);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->expects($this->never())
            ->method('calculateCurrentDwellTime');

        // Act
        $result = $this->service->processAutomaticReturn($container);

        // Assert
        $this->assertFalse($result);
        $this->assertEquals(ContainerStatus::RETURNED, $container->getStatus());
    }

    public function testProcessAutomaticReturnNotEligibleBelowThreshold(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::AT_TERMINAL, 85);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->expects($this->once())
            ->method('calculateCurrentDwellTime')
            ->with($container)
            ->willReturn(85);

        // Act
        $result = $this->service->processAutomaticReturn($container);

        // Assert
        $this->assertFalse($result);
        $this->assertEquals(ContainerStatus::AT_TERMINAL, $container->getStatus());
    }

    public function testProcessAutomaticReturnHandlesException(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::AT_TERMINAL, 95);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->expects($this->once())
            ->method('calculateCurrentDwellTime')
            ->with($container)
            ->willReturn(95);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects($this->once())
            ->method('isTransactionActive')
            ->willReturn(true);

        $this->entityManager->expects($this->once())
            ->method('getConnection')
            ->willReturn($connection);

        $this->entityManager->expects($this->once())
            ->method('beginTransaction');

        $this->entityManager->expects($this->once())
            ->method('flush')
            ->willThrowException(new \Exception('Database error'));

        $this->entityManager->expects($this->once())
            ->method('rollback');

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        // Act
        $result = $this->service->processAutomaticReturn($container);

        // Assert
        $this->assertFalse($result);
    }

    public function testUpdateContainerStatusForReturn(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::AT_TERMINAL, 95);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(DwellTimeEvent::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->updateContainerStatusForReturn($container);

        // Assert
        $this->assertEquals(ContainerStatus::RETURNED, $container->getStatus());
    }

    public function testUpdateContainerStatusForReturnAlreadyReturned(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::RETURNED, 95);

        $this->entityManager->expects($this->never())
            ->method('persist');

        $this->entityManager->expects($this->never())
            ->method('flush');

        // Act
        $this->service->updateContainerStatusForReturn($container);

        // Assert
        $this->assertEquals(ContainerStatus::RETURNED, $container->getStatus());
    }

    public function testGetReturnProcessStatus(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::AT_TERMINAL, 85);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->method('calculateCurrentDwellTime')
            ->with($container)
            ->willReturn(85);

        // Act
        $status = $this->service->getReturnProcessStatus($container);

        // Assert
        $this->assertEquals('CONT123', $status['container_number']);
        $this->assertEquals('at_terminal', $status['current_status']);
        $this->assertEquals(85, $status['current_dwell_time']);
        $this->assertEquals(90, $status['return_threshold']);
        $this->assertFalse($status['is_eligible_for_return']);
        $this->assertFalse($status['is_returned']);
        $this->assertFalse($status['is_paused']);
        $this->assertEquals(5, $status['days_until_return']);
    }

    public function testGetReturnProcessStatusForEligibleContainer(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::AT_TERMINAL, 95);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->method('calculateCurrentDwellTime')
            ->with($container)
            ->willReturn(95);

        // Act
        $status = $this->service->getReturnProcessStatus($container);

        // Assert
        $this->assertTrue($status['is_eligible_for_return']);
        $this->assertEquals(0, $status['days_until_return']);
    }

    public function testGetReturnProcessStatusForReturnedContainer(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::RETURNED, 95);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->method('calculateCurrentDwellTime')
            ->with($container)
            ->willReturn(95);

        // Act
        $status = $this->service->getReturnProcessStatus($container);

        // Assert
        $this->assertTrue($status['is_returned']);
        $this->assertFalse($status['is_eligible_for_return']);
    }

    public function testProcessAutomaticReturnWithTriggeredByUser(): void
    {
        // Arrange
        $container = $this->createContainer('CONT123', ContainerStatus::AT_TERMINAL, 95);
        $user = $this->createMock(User::class);
        $config = $this->createConfiguration();

        $this->setupConfigurationRepository($config);
        $this->dwellTimeService->method('calculateCurrentDwellTime')
            ->with($container)
            ->willReturn(95);

        $this->entityManager->expects($this->once())
            ->method('beginTransaction');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->entityManager->expects($this->once())
            ->method('commit');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (DwellTimeEvent $event) use ($user) {
                return $event->getTriggeredBy() === $user;
            }));

        $this->notificationService->expects($this->once())
            ->method('sendAutomaticReturnNotification')
            ->with($container);

        $this->terminalTeamService->expects($this->once())
            ->method('notifyTerminalTeamAutomaticReturn')
            ->with($container);

        // Act
        $result = $this->service->processAutomaticReturn($container, $user);

        // Assert
        $this->assertTrue($result);
    }

    private function createContainer(string $number, ContainerStatus $status, int $dwellTime): Container
    {
        $container = new Container();
        
        // Use reflection to set the ID
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);
        
        $container->setContainerNumber($number);
        $container->setStatus($status);
        $container->setSize('20');
        $container->setType('DRY');
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime("-{$dwellTime} days"));
        $container->setCurrentDwellTime($dwellTime);

        return $container;
    }

    private function createConfiguration(): DwellTimeConfiguration
    {
        $config = new DwellTimeConfiguration();
        $config->setNotificationThresholdDays(60);
        $config->setAutomaticReturnThresholdDays(90);
        $config->setEnableAutomaticReturns(true);
        $config->setEnableNotifications(true);

        return $config;
    }

    private function setupConfigurationRepository(DwellTimeConfiguration $config): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')
            ->with([])
            ->willReturn($config);

        $this->entityManager->method('getRepository')
            ->with(DwellTimeConfiguration::class)
            ->willReturn($repository);
    }
}
