<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\NotificationDeliveryLog;
use App\Entity\User;
use App\Service\NotificationMonitoringService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationMonitoringServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private NotificationMonitoringService $service;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->service = new NotificationMonitoringService(
            $this->entityManager,
            $this->logger
        );
    }

    public function testLogNotificationAttemptSuccess(): void
    {
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(NotificationDeliveryLog::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Notification delivery logged', $this->anything());

        $log = $this->service->logNotificationAttempt(
            $container,
            $user,
            'dwell_time_warning',
            'email',
            true
        );

        $this->assertInstanceOf(NotificationDeliveryLog::class, $log);
        $this->assertEquals('delivered', $log->getDeliveryStatus());
        $this->assertEquals('email', $log->getChannel());
        $this->assertEquals('dwell_time_warning', $log->getNotificationType());
    }

    public function testLogNotificationAttemptFailure(): void
    {
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(NotificationDeliveryLog::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $log = $this->service->logNotificationAttempt(
            $container,
            $user,
            'dwell_time_warning',
            'email',
            false,
            'SMTP connection failed'
        );

        $this->assertEquals('failed', $log->getDeliveryStatus());
        $this->assertEquals('SMTP connection failed', $log->getErrorMessage());
    }

    public function testGetDeliveryStatistics(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        // Create mock logs
        $log1 = $this->createMockLog('delivered', 'email', 'dwell_time_warning');
        $log2 = $this->createMockLog('failed', 'email', 'dwell_time_warning');
        $log3 = $this->createMockLog('delivered', 'sms', 'automatic_return');

        $query->method('getResult')->willReturn([$log1, $log2, $log3]);

        $stats = $this->service->getDeliveryStatistics();

        $this->assertEquals(3, $stats['total_notifications']);
        $this->assertEquals(2, $stats['delivered']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertArrayHasKey('by_channel', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertGreaterThan(0, $stats['success_rate']);
    }

    public function testSearchByContainerNumber(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $log = $this->createMockLog('delivered', 'email', 'dwell_time_warning');
        $query->method('getResult')->willReturn([$log]);

        $results = $this->service->searchByContainerNumber('CONT123', 50);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('container', $results[0]);
    }

    public function testFilterNotifications(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('setFirstResult')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $log = $this->createMockLog('delivered', 'email', 'dwell_time_warning');
        $query->method('getResult')->willReturn([$log]);

        $criteria = [
            'delivery_status' => 'delivered',
            'notification_type' => 'dwell_time_warning',
            'channel' => 'email',
            'limit' => 20,
            'offset' => 0
        ];

        $results = $this->service->filterNotifications($criteria);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
    }

    public function testGetFailedDeliveriesForAlerting(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $log = $this->createMockLog('failed', 'email', 'dwell_time_warning');
        $query->method('getResult')->willReturn([$log]);

        $results = $this->service->getFailedDeliveriesForAlerting(30);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
    }

    private function createMockLog(string $status, string $channel, string $type): NotificationDeliveryLog
    {
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT123456');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');

        $log = $this->createMock(NotificationDeliveryLog::class);
        $log->method('getId')->willReturn(1);
        $log->method('getContainer')->willReturn($container);
        $log->method('getRecipient')->willReturn($user);
        $log->method('getDeliveryStatus')->willReturn($status);
        $log->method('getChannel')->willReturn($channel);
        $log->method('getNotificationType')->willReturn($type);
        $log->method('getCreatedAt')->willReturn(new \DateTime());
        $log->method('getDeliveredAt')->willReturn($status === 'delivered' ? new \DateTime() : null);
        $log->method('getAttemptCount')->willReturn(1);
        $log->method('getLastAttemptAt')->willReturn(new \DateTime());
        $log->method('getErrorMessage')->willReturn($status === 'failed' ? 'Test error' : null);
        $log->method('getMetadata')->willReturn(null);

        return $log;
    }
}
