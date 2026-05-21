<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\User;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\DwellTimeNotificationService;
use App\Service\EmailNotificationService;
use App\Service\SmsServiceInterface;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class NotificationServiceTest extends TestCase
{
    private DwellTimeNotificationService $notificationService;
    private MockObject $entityManager;
    private MockObject $emailService;
    private MockObject $smsService;
    private MockObject $inAppService;
    private MockObject $urlGenerator;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->smsService = $this->createMock(SmsServiceInterface::class);
        $this->inAppService = $this->createMock(InAppNotificationService::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->notificationService = new DwellTimeNotificationService(
            $this->entityManager,
            $this->emailService,
            $this->smsService,
            $this->inAppService,
            $this->urlGenerator,
            $this->logger
        );
    }

    public function testSendDwellTimeWarningWithValidAdmin(): void
    {
        // Create test container
        $container = new Container();
        $this->setEntityId($container, 1);
        $container->setContainerNumber('TEST123');
        $container->setCurrentDwellTime(60);

        // Create test admin user
        $admin = $this->createMockUser();

        // Mock repository and query builder
        $this->setupUserRepositoryMock($admin);

        // Mock URL generator
        $this->urlGenerator->expects($this->once())
            ->method('generate')
            ->with('container_detail', ['id' => 1])
            ->willReturn('/container/detail');

        // Mock in-app notification service
        $this->inAppService->expects($this->once())
            ->method('createWarningNotification')
            ->with(
                $admin,
                'Container Dwell Time Alert',
                'Container TEST123 has reached 60 days dwell time. 30 days remaining before automatic return.',
                '/container/detail',
                'View Container'
            );

        // Mock email service
        $this->emailService->expects($this->once())
            ->method('sendDwellTimeWarning')
            ->with($container, 30);

        // Mock SMS service as not available
        $this->smsService->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        // Execute test
        $this->notificationService->sendDwellTimeWarning($container, 30);
    }

    public function testSendDwellTimeWarningWithNoAdmin(): void
    {
        // Create test container
        $container = new Container();
        $this->setEntityId($container, 1);
        $container->setContainerNumber('TEST123');

        // Mock repository to return no admin
        $this->setupUserRepositoryMock(null);

        // Expect warning log
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('No shipping line admin found for dwell time warning');

        // Should not call email service
        $this->emailService->expects($this->never())
            ->method('sendDwellTimeWarning');

        // Execute test
        $this->notificationService->sendDwellTimeWarning($container, 30);
    }

    public function testSendAutomaticReturnNotification(): void
    {
        // Create test container
        $container = new Container();
        $this->setEntityId($container, 1);
        $container->setContainerNumber('TEST123');
        $container->setCurrentDwellTime(90);

        // Create test admin user
        $admin = $this->createMockUser();

        // Mock repository and query builder
        $this->setupUserRepositoryMock($admin);

        // Mock URL generator
        $this->urlGenerator->expects($this->once())
            ->method('generate')
            ->with('container_detail', ['id' => 1])
            ->willReturn('/container/detail');

        // Mock in-app notification service
        $this->inAppService->expects($this->once())
            ->method('createErrorNotification')
            ->with(
                $admin,
                'Container Automatic Return',
                'Container TEST123 has been automatically returned to terminal after 90 days dwell time.',
                '/container/detail',
                'View Container'
            );

        // Mock email service
        $this->emailService->expects($this->once())
            ->method('sendAutomaticReturnNotification')
            ->with($container);

        // Execute test
        $this->notificationService->sendAutomaticReturnNotification($container);
    }

    public function testSendDwellTimePausedNotification(): void
    {
        // Create test container
        $container = new Container();
        $this->setEntityId($container, 1);
        $container->setContainerNumber('TEST123');

        // Create test admin user
        $admin = $this->createMockUser();

        // Mock repository and query builder
        $this->setupUserRepositoryMock($admin);

        // Mock URL generator
        $this->urlGenerator->expects($this->once())
            ->method('generate')
            ->with('container_detail', ['id' => 1])
            ->willReturn('/container/detail');

        // Mock in-app notification service
        $this->inAppService->expects($this->once())
            ->method('createInfoNotification')
            ->with(
                $admin,
                'Container Dwell Time Paused',
                'Dwell time counting has been paused for container TEST123. Reason: Under investigation',
                '/container/detail',
                'View Container'
            );

        // Execute test
        $this->notificationService->sendDwellTimePausedNotification($container, 'Under investigation');
    }

    public function testSendDwellTimeResumedNotification(): void
    {
        // Create test container
        $container = new Container();
        $this->setEntityId($container, 1);
        $container->setContainerNumber('TEST123');

        // Create test admin user
        $admin = $this->createMockUser();

        // Mock repository and query builder
        $this->setupUserRepositoryMock($admin);

        // Mock URL generator
        $this->urlGenerator->expects($this->once())
            ->method('generate')
            ->with('container_detail', ['id' => 1])
            ->willReturn('/container/detail');

        // Mock in-app notification service
        $this->inAppService->expects($this->once())
            ->method('createInfoNotification')
            ->with(
                $admin,
                'Container Dwell Time Resumed',
                'Dwell time counting has resumed for container TEST123. Please monitor its status.',
                '/container/detail',
                'View Container'
            );

        // Execute test
        $this->notificationService->sendDwellTimeResumedNotification($container);
    }

    public function testGetDeliveryStatistics(): void
    {
        $stats = $this->notificationService->getDeliveryStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_notifications_sent', $stats);
        $this->assertArrayHasKey('successful_deliveries', $stats);
        $this->assertArrayHasKey('failed_deliveries', $stats);
        $this->assertArrayHasKey('retry_attempts', $stats);
        $this->assertArrayHasKey('channels_used', $stats);
    }

    private function createMockUser(): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('admin@test.com');
        return $user;
    }

    private function setEntityId($entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function setupUserRepositoryMock(?MockObject $returnUser): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $repository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('u')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('where')
            ->with('u.role = :role')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('u.status = :status')
            ->willReturnSelf();

        $queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('getOneOrNullResult')
            ->willReturn($returnUser);
    }
}