<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\DwellTimeConfiguration;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\StaffUser;
use App\Repository\ContainerRepository;
use App\Service\DwellTimeMonitor;
use App\Service\DwellTimeServiceInterface;
use App\Service\EmailNotificationService;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DwellTimeMonitor
 * 
 * **Validates: Requirements 1.1, 2.1, 8.3**
 */
class DwellTimeMonitorTest extends TestCase
{
    private DwellTimeMonitor $monitor;
    private EntityManagerInterface|MockObject $entityManager;
    private DwellTimeServiceInterface|MockObject $dwellTimeService;
    private EmailNotificationService|MockObject $emailNotificationService;
    private InAppNotificationService|MockObject $inAppNotificationService;
    private ContainerRepository|MockObject $containerRepository;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->dwellTimeService = $this->createMock(DwellTimeServiceInterface::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->inAppNotificationService = $this->createMock(InAppNotificationService::class);
        $this->containerRepository = $this->createMock(ContainerRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->monitor = new DwellTimeMonitor(
            $this->entityManager,
            $this->dwellTimeService,
            $this->emailNotificationService,
            $this->inAppNotificationService,
            $this->containerRepository,
            $this->logger
        );
    }

    public function testProcessContainersWithNotifications(): void
    {
        // Arrange
        $container = $this->createContainer();
        $containers = [$container];
        
        $this->setupContainerRepositoryMock($containers);
        
        // Mock notification check
        $notifications = [
            [
                'type' => 'notification_60_day',
                'dwell_time' => 60,
                'days_remaining' => 30
            ]
        ];
        
        $this->dwellTimeService->expects($this->once())
            ->method('updateContainerDwellTime')
            ->with($container);
            
        $this->dwellTimeService->expects($this->once())
            ->method('checkNotificationThresholds')
            ->with($container)
            ->willReturn($notifications);
            
        $this->dwellTimeService->expects($this->once())
            ->method('processAutomaticReturn')
            ->with($container);

        // Mock shipping line admin
        $admin = $this->createShippingLineAdmin();
        $this->setupEntityManagerForUserQuery($admin);

        // Expect notification to be sent
        $this->inAppNotificationService->expects($this->once())
            ->method('createWarningNotification');
            
        $this->emailNotificationService->expects($this->once())
            ->method('sendDwellTimeWarning')
            ->with($container, 30);

        // Act
        $this->monitor->processContainers();

        // Assert - method completes without exception
        $this->assertTrue(true);
    }

    public function testProcessContainersWithAutomaticReturn(): void
    {
        // Arrange
        $container = $this->createContainer();
        $containers = [$container];
        
        $this->setupContainerRepositoryMock($containers);
        
        $this->dwellTimeService->expects($this->once())
            ->method('updateContainerDwellTime')
            ->with($container);
            
        $this->dwellTimeService->expects($this->once())
            ->method('checkNotificationThresholds')
            ->with($container)
            ->willReturn([]);
            
        // Mock automatic return
        $this->dwellTimeService->expects($this->once())
            ->method('processAutomaticReturn')
            ->with($container)
            ->willReturnCallback(function($container) {
                $container->setStatus(ContainerStatus::RETURNED);
            });

        // Expect automatic return notification
        $this->emailNotificationService->expects($this->once())
            ->method('sendAutomaticReturnNotification')
            ->with($container);

        // Act
        $this->monitor->processContainers();

        // Assert
        $this->assertEquals(ContainerStatus::RETURNED, $container->getStatus());
    }

    public function testCheckNotificationThresholds(): void
    {
        // Arrange
        $container = $this->createContainer();
        $containers = [$container];
        
        $this->setupContainerRepositoryMock($containers);
        
        $notifications = [
            [
                'type' => 'notification_60_day',
                'dwell_time' => 60,
                'days_remaining' => 30
            ]
        ];
        
        $this->dwellTimeService->expects($this->once())
            ->method('checkNotificationThresholds')
            ->with($container)
            ->willReturn($notifications);

        // Act
        $result = $this->monitor->checkNotificationThresholds();

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals($container->getId(), $result[0]['container_id']);
        $this->assertEquals($container->getContainerNumber(), $result[0]['container_number']);
        $this->assertEquals($notifications, $result[0]['notifications']);
    }

    public function testProcessAutomaticReturns(): void
    {
        // Arrange
        $container = $this->createContainer();
        $containers = [$container];
        
        $this->setupContainerRepositoryMock($containers);
        
        // Mock automatic return
        $this->dwellTimeService->expects($this->once())
            ->method('processAutomaticReturn')
            ->with($container)
            ->willReturnCallback(function($container) {
                $container->setStatus(ContainerStatus::RETURNED);
                $container->setCurrentDwellTime(90);
            });

        // Act
        $result = $this->monitor->processAutomaticReturns();

        // Assert
        $this->assertCount(1, $result);
        $this->assertEquals($container->getId(), $result[0]['container_id']);
        $this->assertEquals($container->getContainerNumber(), $result[0]['container_number']);
        $this->assertEquals(90, $result[0]['dwell_time']);
    }

    public function testGenerateDailyReport(): void
    {
        // Arrange
        $containers = [
            $this->createContainer(),
            $this->createContainer(),
        ];
        
        $this->setupContainerRepositoryMock($containers);
        $this->setupDwellTimeConfigurationMock();
        
        // Mock dwell time calculations
        $this->dwellTimeService->expects($this->exactly(2))
            ->method('calculateCurrentDwellTime')
            ->willReturnOnConsecutiveCalls(55, 91); // One approaching notification, one over return threshold
            
        $this->dwellTimeService->expects($this->exactly(2))
            ->method('checkNotificationThresholds')
            ->willReturnOnConsecutiveCalls([], []); // No notifications due

        // Act
        $report = $this->monitor->generateDailyReport();

        // Assert
        $this->assertArrayHasKey('date', $report);
        $this->assertEquals(2, $report['total_containers']);
        $this->assertEquals(1, $report['containers_approaching_notification']);
        $this->assertEquals(0, $report['containers_approaching_return']);
        $this->assertEquals(0, $report['containers_paused']);
        $this->assertEquals(0, $report['notifications_due']);
        $this->assertEquals(1, $report['returns_due']);
        $this->assertIsArray($report['summary']);
    }

    private function createContainer(): Container
    {
        $container = new Container();
        $container->setContainerNumber('TEST123456');
        $container->setSize('40');
        $container->setType('HC');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime('-30 days'));
        
        // Use reflection to set the ID
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);
        
        return $container;
    }

    private function createShippingLineAdmin(): StaffUser
    {
        $admin = new StaffUser();
        $admin->setEmail('admin@test.com');
        $admin->setPasswordHash('hashed_password');
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setDepartment('Shipping');
        
        return $admin;
    }

    private function setupContainerRepositoryMock(array $containers): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);
        
        $this->containerRepository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('c')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);
            
        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($containers);
    }

    private function setupEntityManagerForUserQuery(StaffUser $admin): void
    {
        $userRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);
        
        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(\App\Entity\User::class)
            ->willReturn($userRepository);
            
        $userRepository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('u')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('where')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->willReturn($queryBuilder);
            
        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);
            
        $query->expects($this->once())
            ->method('getOneOrNullResult')
            ->willReturn($admin);
    }

    private function setupDwellTimeConfigurationMock(): void
    {
        $config = new DwellTimeConfiguration();
        
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->willReturn($config);
            
        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(DwellTimeConfiguration::class)
            ->willReturn($repository);
    }
}