<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\TerminalTeamUser;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\AccountStatus;
use App\Service\TerminalTeamDwellTimeService;
use App\Service\InAppNotificationService;
use App\Service\EmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TerminalTeamDwellTimeServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private InAppNotificationService $inAppService;
    private EmailNotificationService $emailService;
    private UrlGeneratorInterface $urlGenerator;
    private LoggerInterface $logger;
    private TerminalTeamDwellTimeService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->inAppService = $this->createMock(InAppNotificationService::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new TerminalTeamDwellTimeService(
            $this->entityManager,
            $this->inAppService,
            $this->emailService,
            $this->urlGenerator,
            $this->logger
        );
    }

    public function testNotifyTerminalTeamDwellTimeWarning(): void
    {
        // Arrange
        $container = new Container();
        $container->setContainerNumber('TEST123456');
        $container->setCurrentDwellTime(60);
        
        // Use reflection to set the ID
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);

        $terminalUser1 = new TerminalTeamUser();
        $terminalUser1->setEmail('terminal1@test.com');
        $terminalUser1->setFirstName('Terminal');
        $terminalUser1->setLastName('User1');
        
        $terminalUser2 = new TerminalTeamUser();
        $terminalUser2->setEmail('terminal2@test.com');
        $terminalUser2->setFirstName('Terminal');
        $terminalUser2->setLastName('User2');

        $terminalUsers = [$terminalUser1, $terminalUser2];

        // Mock repository and query builder
        $this->setupTerminalTeamUserRepositoryMock($terminalUsers);

        // Mock URL generator
        $this->urlGenerator->expects($this->exactly(2))
            ->method('generate')
            ->with('container_detail', ['id' => 1])
            ->willReturn('/container/1');

        // Expect in-app notifications for both users
        $this->inAppService->expects($this->exactly(2))
            ->method('createWarningNotification');

        // Act
        $this->service->notifyTerminalTeamDwellTimeWarning($container, 30);

        // Assert - method completes without exception
        $this->assertTrue(true);
    }

    public function testNotifyTerminalTeamAutomaticReturn(): void
    {
        // Arrange
        $container = new Container();
        $container->setContainerNumber('TEST123456');
        $container->setCurrentDwellTime(90);
        
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);

        $terminalUser = new TerminalTeamUser();
        $terminalUser->setEmail('terminal@test.com');
        $terminalUser->setFirstName('Terminal');
        $terminalUser->setLastName('User');

        $this->setupTerminalTeamUserRepositoryMock([$terminalUser]);

        $this->urlGenerator->expects($this->once())
            ->method('generate')
            ->with('container_detail', ['id' => 1])
            ->willReturn('/container/1');

        $this->inAppService->expects($this->once())
            ->method('createErrorNotification');

        // Act
        $this->service->notifyTerminalTeamAutomaticReturn($container);

        // Assert
        $this->assertTrue(true);
    }

    public function testNotifyTerminalTeamAlertStatusChange(): void
    {
        // Arrange
        $container = new Container();
        $container->setContainerNumber('TEST123456');
        
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);

        $terminalUser = new TerminalTeamUser();
        $terminalUser->setEmail('terminal@test.com');
        $terminalUser->setFirstName('Terminal');
        $terminalUser->setLastName('User');

        $this->setupTerminalTeamUserRepositoryMock([$terminalUser]);

        $this->urlGenerator->expects($this->once())
            ->method('generate')
            ->with('container_detail', ['id' => 1])
            ->willReturn('/container/1');

        $this->inAppService->expects($this->once())
            ->method('createInfoNotification');

        // Act
        $this->service->notifyTerminalTeamAlertStatusChange($container, true, 'Test reason');

        // Assert
        $this->assertTrue(true);
    }

    public function testGetContainerAlertStatusInfo(): void
    {
        // Arrange
        $container = new Container();
        $container->setContainerNumber('TEST123456');
        $container->setStatus(ContainerStatus::ALERT);
        $container->setCurrentDwellTime(55);
        $container->setDwellTimePausedAt(new \DateTime('2024-01-01'));
        $container->setTotalPausedDays(5);
        $container->setNextNotificationDate(new \DateTime('2024-02-01'));
        $container->setAutomaticReturnDate(new \DateTime('2024-03-01'));

        // Act
        $result = $this->service->getContainerAlertStatusInfo($container);

        // Assert
        $this->assertEquals('TEST123456', $result['container_number']);
        $this->assertTrue($result['is_alerted']);
        $this->assertTrue($result['is_dwell_time_paused']);
        $this->assertEquals(55, $result['current_dwell_time']);
        $this->assertEquals(5, $result['total_paused_days']);
        $this->assertEquals('alert', $result['status']);
    }

    public function testNotifyTerminalTeamWithNoActiveUsers(): void
    {
        // Arrange
        $container = new Container();
        $container->setContainerNumber('TEST123456');
        
        $reflection = new \ReflectionClass($container);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($container, 1);

        // Mock empty terminal team users
        $this->setupTerminalTeamUserRepositoryMock([]);

        // Expect warning log
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'No active terminal team users found for dwell time warning notification',
                $this->anything()
            );

        // Expect no notifications to be sent
        $this->inAppService->expects($this->never())
            ->method('createWarningNotification');

        // Act
        $this->service->notifyTerminalTeamDwellTimeWarning($container, 30);

        // Assert
        $this->assertTrue(true);
    }

    private function setupTerminalTeamUserRepositoryMock(array $users): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(TerminalTeamUser::class)
            ->willReturn($repository);

        $repository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('ttu')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('where')
            ->with('ttu.status = :status')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('status', AccountStatus::APPROVED)
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($users);
    }
}
