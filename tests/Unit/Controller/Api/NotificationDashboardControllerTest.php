<?php

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\NotificationDashboardController;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Service\JwtService;
use App\Service\NotificationMonitoringService;
use App\Service\UserService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class NotificationDashboardControllerTest extends TestCase
{
    private JwtService $jwtService;
    private UserService $userService;
    private NotificationMonitoringService $monitoringService;
    private NotificationDashboardController $controller;

    protected function setUp(): void
    {
        $this->jwtService = $this->createMock(JwtService::class);
        $this->userService = $this->createMock(UserService::class);
        $this->monitoringService = $this->createMock(NotificationMonitoringService::class);

        $this->controller = new NotificationDashboardController(
            $this->jwtService,
            $this->userService,
            $this->monitoringService
        );
    }

    public function testGetDashboardRequiresAuthentication(): void
    {
        $request = new Request();

        $this->jwtService->method('getUserIdFromToken')->willReturn(null);

        $response = $this->controller->getDashboard($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication required', $data['error']);
    }

    public function testGetDashboardRequiresAdminRole(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::TRUCKER);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $response = $this->controller->getDashboard($request);

        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Insufficient permissions', $data['error']);
    }

    public function testGetDashboardSuccess(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $stats = [
            'total_notifications' => 100,
            'delivered' => 85,
            'failed' => 10,
            'pending' => 5,
            'success_rate' => 85.0
        ];

        $this->monitoringService->method('getDeliveryStatistics')->willReturn($stats);
        $this->monitoringService->method('getPendingNotifications')->willReturn([]);
        $this->monitoringService->method('getFailedNotifications')->willReturn([]);

        $response = $this->controller->getDashboard($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('statistics', $data);
        $this->assertArrayHasKey('recent_pending', $data);
        $this->assertArrayHasKey('recent_failed', $data);
        $this->assertEquals(100, $data['statistics']['total_notifications']);
    }

    public function testGetStatisticsWithDateRange(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');
        $request->query->set('from_date', '2026-01-01');
        $request->query->set('to_date', '2026-01-31');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $stats = [
            'total_notifications' => 50,
            'delivered' => 45,
            'failed' => 5,
            'success_rate' => 90.0
        ];

        $this->monitoringService->expects($this->once())
            ->method('getDeliveryStatistics')
            ->with(
                $this->isInstanceOf(\DateTime::class),
                $this->isInstanceOf(\DateTime::class)
            )
            ->willReturn($stats);

        $response = $this->controller->getStatistics($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(50, $data['total_notifications']);
    }

    public function testGetPendingNotificationsWithPagination(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');
        $request->query->set('page', '2');
        $request->query->set('limit', '10');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $notifications = [
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'pending']
        ];

        $this->monitoringService->expects($this->once())
            ->method('getPendingNotifications')
            ->with(10, 10) // limit, offset
            ->willReturn($notifications);

        $this->monitoringService->method('countNotifications')->willReturn(25);

        $response = $this->controller->getPendingNotifications($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('notifications', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertEquals(2, $data['pagination']['page']);
        $this->assertEquals(10, $data['pagination']['limit']);
        $this->assertEquals(25, $data['pagination']['total']);
    }

    public function testSearchNotificationsRequiresContainerNumber(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $response = $this->controller->searchNotifications($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('container_number parameter is required', $data['error']);
    }

    public function testSearchNotificationsSuccess(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');
        $request->query->set('container_number', 'CONT123');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $notifications = [
            ['id' => 1, 'container' => ['container_number' => 'CONT123456']]
        ];

        $this->monitoringService->expects($this->once())
            ->method('searchByContainerNumber')
            ->with('CONT123', 50)
            ->willReturn($notifications);

        $response = $this->controller->searchNotifications($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('notifications', $data);
        $this->assertEquals('CONT123', $data['search_term']);
    }

    public function testFilterNotificationsWithMultipleCriteria(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');
        $request->query->set('delivery_status', 'delivered');
        $request->query->set('notification_type', 'dwell_time_warning');
        $request->query->set('channel', 'email');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $notifications = [
            ['id' => 1, 'status' => 'delivered', 'type' => 'dwell_time_warning']
        ];

        $this->monitoringService->expects($this->once())
            ->method('filterNotifications')
            ->with($this->callback(function($criteria) {
                return $criteria['delivery_status'] === 'delivered'
                    && $criteria['notification_type'] === 'dwell_time_warning'
                    && $criteria['channel'] === 'email';
            }))
            ->willReturn($notifications);

        $this->monitoringService->method('countNotifications')->willReturn(1);

        $response = $this->controller->filterNotifications($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('notifications', $data);
        $this->assertArrayHasKey('filters', $data);
    }

    public function testFilterNotificationsValidatesDeliveryStatus(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');
        $request->query->set('delivery_status', 'invalid_status');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $response = $this->controller->filterNotifications($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Invalid delivery_status', $data['error']);
    }

    public function testGetFailedDeliveryAlerts(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');
        $request->query->set('threshold_minutes', '60');

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $alerts = [
            ['id' => 1, 'status' => 'failed', 'error' => 'SMTP timeout']
        ];

        $this->monitoringService->expects($this->once())
            ->method('getFailedDeliveriesForAlerting')
            ->with(60)
            ->willReturn($alerts);

        $response = $this->controller->getFailedDeliveryAlerts($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('alerts', $data);
        $this->assertEquals(60, $data['threshold_minutes']);
        $this->assertEquals(1, $data['total_alerts']);
    }
}
