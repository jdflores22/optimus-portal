<?php

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\DwellTimeAuditController;
use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\Enum\UserRole;
use App\Entity\ShippingLine;
use App\Entity\User;
use App\Service\DwellTimeAuditService;
use App\Service\JwtService;
use App\Service\ShippingLineService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class DwellTimeAuditControllerTest extends TestCase
{
    private DwellTimeAuditController $controller;
    private JwtService $jwtService;
    private UserService $userService;
    private DwellTimeAuditService $auditService;
    private ShippingLineService $shippingLineService;
    private EntityManagerInterface $entityManager;
    private EntityRepository $containerRepository;

    protected function setUp(): void
    {
        $this->jwtService = $this->createMock(JwtService::class);
        $this->userService = $this->createMock(UserService::class);
        $this->auditService = $this->createMock(DwellTimeAuditService::class);
        $this->shippingLineService = $this->createMock(ShippingLineService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->containerRepository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(Container::class)
            ->willReturn($this->containerRepository);

        $this->controller = new DwellTimeAuditController(
            $this->jwtService,
            $this->userService,
            $this->auditService,
            $this->shippingLineService,
            $this->entityManager
        );
    }

    public function testGetContainerHistoryReturnsAuditTrail(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $container = $this->createContainer();
        $auditTrail = $this->createSampleAuditTrail();

        $request = $this->createAuthenticatedRequest($user);

        $this->containerRepository->method('find')->willReturn($container);
        $this->auditService->method('getAuditTrail')->willReturn($auditTrail);

        $response = $this->controller->getContainerHistory($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('container', $data);
        $this->assertArrayHasKey('audit_trail', $data);
        $this->assertArrayHasKey('total_events', $data);
        $this->assertEquals(3, $data['total_events']);
    }

    public function testGetContainerHistoryReturns404WhenContainerNotFound(): void
    {
        $user = $this->createUser(UserRole::SHIPPING_LINE_ADMIN);
        $request = $this->createAuthenticatedRequest($user);

        $this->containerRepository->method('find')->willReturn(null);

        $response = $this->controller->getContainerHistory($request, 999);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Container not found', $data['error']);
    }

    public function testGetContainerHistoryReturns403WhenAccessDenied(): void
    {
        $user = $this->createUser(UserRole::BROKER);
        $container = $this->createContainer();
        $request = $this->createAuthenticatedRequest($user);

        $this->containerRepository->method('find')->willReturn($container);
        $this->shippingLineService->method('getShippingLineScope')->willReturn(null);

        $response = $this->controller->getContainerHistory($request, 1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Access denied', $data['error']);
    }

    public function testGetContainerHistoryWithEventTypeFilter(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $container = $this->createContainer();
        $auditTrail = [$this->createAuditEvent(DwellTimeEventType::PAUSE)];

        $request = $this->createAuthenticatedRequest($user);
        $request->query->set('event_type', DwellTimeEventType::PAUSE->value);

        $this->containerRepository->method('find')->willReturn($container);
        $this->auditService->method('getAuditTrail')->willReturn($auditTrail);

        $response = $this->controller->getContainerHistory($request, 1);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(1, $data['total_events']);
    }

    public function testListEventsReturnsPaginatedResults(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $events = $this->createSampleAuditTrail();

        $request = $this->createAuthenticatedRequest($user);
        $request->query->set('page', '1');
        $request->query->set('limit', '20');

        $this->auditService->method('queryEvents')->willReturn($events);
        $this->auditService->method('countEvents')->willReturn(3);

        $response = $this->controller->listEvents($request);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertEquals(1, $data['pagination']['page']);
        $this->assertEquals(20, $data['pagination']['limit']);
        $this->assertEquals(3, $data['pagination']['total']);
    }

    public function testListEventsWithMultipleEventTypes(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $events = $this->createSampleAuditTrail();

        $request = $this->createAuthenticatedRequest($user);
        $request->query->set('event_type', 'pause,resume');

        $this->auditService->method('queryEvents')->willReturn($events);
        $this->auditService->method('countEvents')->willReturn(3);

        $response = $this->controller->listEvents($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testGetPauseResumeHistoryReturnsHistory(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $container = $this->createContainer();
        $history = [
            [
                'pause_event' => $this->createAuditEvent(DwellTimeEventType::PAUSE),
                'resume_event' => $this->createAuditEvent(DwellTimeEventType::RESUME),
                'duration_days' => 5
            ]
        ];

        $request = $this->createAuthenticatedRequest($user);

        $this->containerRepository->method('find')->willReturn($container);
        $this->auditService->method('getPauseResumeHistory')->willReturn($history);

        $response = $this->controller->getPauseResumeHistory($request, 1);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('pause_resume_history', $data);
        $this->assertArrayHasKey('total_pause_cycles', $data);
        $this->assertArrayHasKey('total_pause_days', $data);
        $this->assertEquals(1, $data['total_pause_cycles']);
        $this->assertEquals(5, $data['total_pause_days']);
    }

    public function testGetNotificationHistoryReturnsNotifications(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $container = $this->createContainer();
        $notifications = [
            $this->createAuditEvent(DwellTimeEventType::NOTIFICATION_60_DAY),
            $this->createAuditEvent(DwellTimeEventType::AUTOMATIC_RETURN)
        ];

        $request = $this->createAuthenticatedRequest($user);

        $this->containerRepository->method('find')->willReturn($container);
        $this->auditService->method('getNotificationHistory')->willReturn($notifications);

        $response = $this->controller->getNotificationHistory($request, 1);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('notifications', $data);
        $this->assertArrayHasKey('total_notifications', $data);
        $this->assertEquals(2, $data['total_notifications']);
    }

    public function testGenerateSummaryReportReturnsReport(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $report = [
            'date_range' => ['from' => '2024-01-01', 'to' => '2024-12-31'],
            'total_events' => 10,
            'events_by_type' => [],
            'statistics' => []
        ];

        $request = $this->createAuthenticatedRequest($user);
        $request->query->set('from_date', '2024-01-01');
        $request->query->set('to_date', '2024-12-31');

        $this->auditService->method('generateReport')->willReturn($report);

        $response = $this->controller->generateSummaryReport($request);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('report_type', $data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertArrayHasKey('report', $data);
        $this->assertEquals('dwell_time_summary', $data['report_type']);
    }

    public function testGenerateSummaryReportRequiresAdminRole(): void
    {
        $user = $this->createUser(UserRole::BROKER);
        $request = $this->createAuthenticatedRequest($user);
        $request->query->set('from_date', '2024-01-01');
        $request->query->set('to_date', '2024-12-31');

        $response = $this->controller->generateSummaryReport($request);

        $this->assertEquals(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Insufficient permissions', $data['error']);
    }

    public function testGenerateSummaryReportRequiresDateParameters(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $request = $this->createAuthenticatedRequest($user);

        $response = $this->controller->generateSummaryReport($request);

        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('required', $data['error']);
    }

    public function testGetEventTypesReturnsAllTypes(): void
    {
        $user = $this->createUser(UserRole::SHIPPING_LINE_ADMIN);
        $request = $this->createAuthenticatedRequest($user);

        $response = $this->controller->getEventTypes($request);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('event_types', $data);
        $this->assertIsArray($data['event_types']);
        $this->assertGreaterThan(0, count($data['event_types']));
    }

    private function createUser(UserRole $role): User
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRole($role);

        // Use reflection to set the ID
        $reflection = new \ReflectionClass($user);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($user, 1);

        return $user;
    }

    private function createContainer(): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT123456');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));
        $container->setCurrentDwellTime(30);
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

    private function createAuthenticatedRequest(User $user): Request
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer valid-token');

        $this->jwtService->method('getUserIdFromToken')->willReturn($user->getId());
        $this->userService->method('findById')->willReturn($user);

        return $request;
    }

    private function createAuditEvent(DwellTimeEventType $type): array
    {
        return [
            'id' => rand(1, 1000),
            'container_id' => 1,
            'container_number' => 'CONT123456',
            'event_type' => $type->value,
            'event_date' => (new \DateTime())->format('Y-m-d H:i:s'),
            'dwell_time_at_event' => 30,
            'reason' => 'Test reason',
            'metadata' => null
        ];
    }

    private function createSampleAuditTrail(): array
    {
        return [
            $this->createAuditEvent(DwellTimeEventType::PAUSE),
            $this->createAuditEvent(DwellTimeEventType::RESUME),
            $this->createAuditEvent(DwellTimeEventType::NOTIFICATION_60_DAY)
        ];
    }
}
