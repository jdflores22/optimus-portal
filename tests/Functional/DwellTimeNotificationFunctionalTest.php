<?php

namespace App\Tests\Functional;

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
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Functional test for dwell time notification system
 * Tests the complete notification workflow without full container setup
 */
class DwellTimeNotificationFunctionalTest extends TestCase
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

    public function testCompleteNotificationWorkflow(): void
    {
        // Test scenario: Container reaches 60-day threshold
        $container = $this->createTestContainer('FUNC001', 60);
        $admin = $this->createTestUser();

        // Setup mocks for successful notification delivery
        $this->setupSuccessfulNotificationMocks($container, $admin);

        // Execute notification
        $this->notificationService->sendDwellTimeWarning($container, 30);

        // Verify all notification channels were attempted
        $this->assertTrue(true, 'Dwell time warning notification completed successfully');
    }

    public function testNotificationRetryLogic(): void
    {
        // Test scenario: Email fails, but in-app notification succeeds
        $container = $this->createTestContainer('FUNC002', 60);
        $admin = $this->createTestUser();

        // Setup mocks for failed email but successful in-app notification
        $this->setupFailedEmailMocks($container, $admin);

        // Execute notification
        $this->notificationService->sendDwellTimeWarning($container, 30);

        // Verify retry logic was executed
        $this->assertTrue(true, 'Notification retry logic executed successfully');
    }

    public function testAutomaticReturnNotification(): void
    {
        // Test scenario: Container reaches 90-day threshold and is returned
        $container = $this->createTestContainer('FUNC003', 90);
        $container->setStatus(ContainerStatus::RETURNED);
        $admin = $this->createTestUser();

        // Setup mocks for automatic return notification
        $this->setupAutomaticReturnMocks($container, $admin);

        // Execute notification
        $this->notificationService->sendAutomaticReturnNotification($container);

        // Verify automatic return notification was sent
        $this->assertTrue(true, 'Automatic return notification completed successfully');
    }

    public function testPauseResumeNotifications(): void
    {
        // Test scenario: Container status changes trigger pause/resume notifications
        $container = $this->createTestContainer('FUNC004', 45);
        $admin = $this->createTestUser();

        // Setup mocks for pause/resume notifications
        $this->setupPauseResumeMocks($container, $admin);

        // Execute pause notification
        $this->notificationService->sendDwellTimePausedNotification($container, 'Under investigation');

        // Execute resume notification
        $this->notificationService->sendDwellTimeResumedNotification($container);

        // Verify pause/resume notifications were sent
        $this->assertTrue(true, 'Pause/resume notifications completed successfully');
    }

    public function testMultiChannelDeliveryWithSmsUnavailable(): void
    {
        // Test scenario: SMS service is unavailable, should fall back to email only
        $container = $this->createTestContainer('FUNC005', 60);
        $admin = $this->createTestUser();

        // Setup mocks with SMS unavailable
        $this->setupSmsUnavailableMocks($container, $admin);

        // Execute notification
        $this->notificationService->sendDwellTimeWarning($container, 30);

        // Verify email was used when SMS unavailable
        $this->assertTrue(true, 'Multi-channel delivery with SMS unavailable completed successfully');
    }

    private function createTestContainer(string $containerNumber, int $dwellTime): Container
    {
        $container = new Container();
        $this->setEntityId($container, rand(1, 1000));
        $container->setContainerNumber($containerNumber);
        $container->setSize('20');
        $container->setType('DRY');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime("-{$dwellTime} days"));
        $container->setCurrentDwellTime($dwellTime);

        return $container;
    }

    private function createTestUser(): MockObject
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

    private function setupSuccessfulNotificationMocks(Container $container, MockObject $admin): void
    {
        $this->setupUserRepositoryMock($admin);
        $this->urlGenerator->method('generate')->willReturn('/container/detail');
        $this->inAppService->expects($this->once())->method('createWarningNotification');
        $this->emailService->expects($this->once())->method('sendDwellTimeWarning');
        $this->smsService->method('isAvailable')->willReturn(false);
    }

    private function setupFailedEmailMocks(Container $container, MockObject $admin): void
    {
        $this->setupUserRepositoryMock($admin);
        $this->urlGenerator->method('generate')->willReturn('/container/detail');
        $this->inAppService->expects($this->once())->method('createWarningNotification');
        $this->emailService->expects($this->exactly(3))->method('sendDwellTimeWarning')
            ->willThrowException(new \Exception('Email delivery failed'));
        $this->smsService->method('isAvailable')->willReturn(false);
    }

    private function setupAutomaticReturnMocks(Container $container, MockObject $admin): void
    {
        $this->setupUserRepositoryMock($admin);
        $this->urlGenerator->method('generate')->willReturn('/container/detail');
        $this->inAppService->expects($this->once())->method('createErrorNotification');
        $this->emailService->expects($this->once())->method('sendAutomaticReturnNotification');
    }

    private function setupPauseResumeMocks(Container $container, MockObject $admin): void
    {
        $this->setupUserRepositoryMock($admin);
        $this->urlGenerator->method('generate')->willReturn('/container/detail');
        $this->inAppService->expects($this->exactly(2))->method('createInfoNotification');
    }

    private function setupSmsUnavailableMocks(Container $container, MockObject $admin): void
    {
        $this->setupUserRepositoryMock($admin);
        $this->urlGenerator->method('generate')->willReturn('/container/detail');
        $this->inAppService->expects($this->once())->method('createWarningNotification');
        $this->emailService->expects($this->once())->method('sendDwellTimeWarning');
        $this->smsService->method('isAvailable')->willReturn(false);
    }

    private function setupUserRepositoryMock(?MockObject $returnUser): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $queryBuilder = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);

        $this->entityManager->method('getRepository')->willReturn($repository);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getOneOrNullResult')->willReturn($returnUser);
    }
}