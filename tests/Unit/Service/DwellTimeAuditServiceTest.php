<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\DwellTimeAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DwellTimeAuditServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private DwellTimeAuditService $service;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(DwellTimeEvent::class)
            ->willReturn($this->repository);

        $this->service = new DwellTimeAuditService(
            $this->entityManager,
            $this->logger
        );
    }

    public function testGetAuditTrailReturnsEventsForContainer(): void
    {
        $container = $this->createContainer();
        $events = $this->createSampleEvents($container);

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->getAuditTrail($container);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals(DwellTimeEventType::PAUSE->value, $result[0]['event_type']);
    }

    public function testGetAuditTrailWithEventTypeFilter(): void
    {
        $container = $this->createContainer();
        $events = [$this->createEvent($container, DwellTimeEventType::PAUSE)];

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->getAuditTrail($container, [
            'event_type' => DwellTimeEventType::PAUSE
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(DwellTimeEventType::PAUSE->value, $result[0]['event_type']);
    }

    public function testGetAuditTrailWithDateRangeFilter(): void
    {
        $container = $this->createContainer();
        $events = $this->createSampleEvents($container);

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $fromDate = new \DateTime('2024-01-01');
        $toDate = new \DateTime('2024-12-31');

        $result = $this->service->getAuditTrail($container, [
            'from_date' => $fromDate,
            'to_date' => $toDate
        ]);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    public function testQueryEventsWithContainerId(): void
    {
        $container = $this->createContainer();
        $events = $this->createSampleEvents($container);

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->queryEvents([
            'container_id' => $container->getId()
        ]);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    public function testQueryEventsWithContainerNumber(): void
    {
        $container = $this->createContainer();
        $events = $this->createSampleEvents($container);

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->queryEvents([
            'container_number' => 'CONT123'
        ]);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    public function testQueryEventsWithMultipleEventTypes(): void
    {
        $container = $this->createContainer();
        $events = $this->createSampleEvents($container);

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->queryEvents([
            'event_type' => [DwellTimeEventType::PAUSE, DwellTimeEventType::RESUME]
        ]);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    public function testQueryEventsWithPagination(): void
    {
        $container = $this->createContainer();
        $events = [$this->createEvent($container, DwellTimeEventType::PAUSE)];

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->queryEvents([
            'limit' => 10,
            'offset' => 0
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testCountEventsReturnsCorrectCount(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('leftJoin')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $query->method('getSingleScalarResult')->willReturn(5);

        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $count = $this->service->countEvents([
            'event_type' => DwellTimeEventType::PAUSE
        ]);

        $this->assertEquals(5, $count);
    }

    public function testGenerateReportReturnsStatistics(): void
    {
        $container = $this->createContainer();
        $events = $this->createSampleEvents($container);

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $fromDate = new \DateTime('2024-01-01');
        $toDate = new \DateTime('2024-12-31');

        $report = $this->service->generateReport($fromDate, $toDate);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('date_range', $report);
        $this->assertArrayHasKey('total_events', $report);
        $this->assertArrayHasKey('events_by_type', $report);
        $this->assertArrayHasKey('statistics', $report);
        $this->assertEquals(3, $report['total_events']);
    }

    public function testGetPauseResumeHistoryReturnsCompleteCycles(): void
    {
        $container = $this->createContainer();
        
        // Create pause and resume events
        $pauseEvent = $this->createEvent($container, DwellTimeEventType::PAUSE);
        $pauseEvent->setEventDate(new \DateTime('2024-01-01'));
        
        $resumeEvent = $this->createEvent($container, DwellTimeEventType::RESUME);
        $resumeEvent->setEventDate(new \DateTime('2024-01-10'));

        $events = [$pauseEvent, $resumeEvent];

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $history = $this->service->getPauseResumeHistory($container);

        $this->assertIsArray($history);
        $this->assertCount(1, $history);
        $this->assertEquals(9, $history[0]['duration_days']);
        $this->assertArrayNotHasKey('is_ongoing', $history[0]);
    }

    public function testGetPauseResumeHistoryWithOngoingPause(): void
    {
        $container = $this->createContainer();
        $container->setDwellTimePausedAt(new \DateTime('2024-01-01'));
        
        $pauseEvent = $this->createEvent($container, DwellTimeEventType::PAUSE);
        $pauseEvent->setEventDate(new \DateTime('2024-01-01'));

        $events = [$pauseEvent];

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $history = $this->service->getPauseResumeHistory($container);

        $this->assertIsArray($history);
        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['is_ongoing']);
        $this->assertGreaterThan(0, $history[0]['duration_days']);
    }

    public function testGetNotificationHistoryReturnsOnlyNotifications(): void
    {
        $container = $this->createContainer();
        
        $notificationEvent = $this->createEvent($container, DwellTimeEventType::NOTIFICATION_60_DAY);
        $returnEvent = $this->createEvent($container, DwellTimeEventType::AUTOMATIC_RETURN);

        $events = [$notificationEvent, $returnEvent];

        $queryBuilder = $this->createMockQueryBuilder($events);
        $this->repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $notifications = $this->service->getNotificationHistory($container);

        $this->assertIsArray($notifications);
        $this->assertCount(2, $notifications);
    }

    private function createContainer(): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT123456');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setSize('40');
        $container->setType('HC');
        $container->setExpectedReturnDate(new \DateTime('2024-12-31'));

        // Use reflection to set the ID
        $reflection = new \ReflectionClass($container);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($container, 1);

        return $container;
    }

    private function createEvent(Container $container, DwellTimeEventType $type): DwellTimeEvent
    {
        $event = new DwellTimeEvent();
        $event->setContainer($container);
        $event->setEventType($type);
        $event->setEventDate(new \DateTime());
        $event->setDwellTimeAtEvent(30);
        $event->setReason('Test reason');

        // Use reflection to set the ID
        $reflection = new \ReflectionClass($event);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($event, rand(1, 1000));

        return $event;
    }

    private function createSampleEvents(Container $container): array
    {
        return [
            $this->createEvent($container, DwellTimeEventType::PAUSE),
            $this->createEvent($container, DwellTimeEventType::RESUME),
            $this->createEvent($container, DwellTimeEventType::NOTIFICATION_60_DAY)
        ];
    }

    private function createMockQueryBuilder(array $events): QueryBuilder
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('leftJoin')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('setFirstResult')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $query->method('getResult')->willReturn($events);

        return $queryBuilder;
    }
}
