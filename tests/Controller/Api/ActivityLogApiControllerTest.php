<?php

namespace App\Tests\Controller\Api;

use App\Entity\ActivityLog;
use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Service\ActivityLogService;
use App\Service\ShippingLineService;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ActivityLogApiControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $activityLogService;
    private $shippingLineService;
    private $activityLogRepository;
    private $jwtService;
    private $userService;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->shippingLineService = $this->createMock(ShippingLineService::class);
        $this->activityLogRepository = $this->createMock(ActivityLogRepository::class);
        $this->jwtService = $container->get('App\Service\JwtService');
        $this->userService = $container->get('App\Service\UserService');

        // Replace services with mocks
        $this->client->getContainer()->set('App\Service\ActivityLogService', $this->activityLogService);
        $this->client->getContainer()->set('App\Service\ShippingLineService', $this->shippingLineService);
        $this->client->getContainer()->set('App\Repository\ActivityLogRepository', $this->activityLogRepository);
    }

    public function testListActivityLogsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/activity-logs');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Authentication required', $response['error']);
    }

    public function testListActivityLogsAsSystemAdmin(): void
    {
        // Create test user
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        // Mock activity logs
        $activityLogs = [
            $this->createMockActivityLog(1, $user, 'login'),
            $this->createMockActivityLog(2, $user, 'create')
        ];

        $this->activityLogRepository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(null, null, null, [], 20, 0)
            ->willReturn($activityLogs);

        $this->activityLogRepository
            ->expects($this->once())
            ->method('countWithFilters')
            ->with(null, null, null, [])
            ->willReturn(2);

        $this->shippingLineService
            ->expects($this->once())
            ->method('getShippingLineScope')
            ->with($user)
            ->willReturn(null);

        $this->activityLogService
            ->expects($this->once())
            ->method('logView');

        $this->client->request('GET', '/api/activity-logs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('activity_logs', $response);
        $this->assertArrayHasKey('pagination', $response);
        $this->assertCount(2, $response['activity_logs']);
        $this->assertEquals(2, $response['pagination']['total']);
    }

    public function testListActivityLogsWithShippingLineScope(): void
    {
        // Create test user and shipping line
        $shippingLine = $this->createTestShippingLine();
        $user = $this->createTestUser(UserRole::SHIPPING_LINES_ADMIN);
        
        $token = $this->jwtService->generateToken($user);

        // Mock activity logs
        $activityLogs = [
            $this->createMockActivityLog(1, $user, 'login', $shippingLine)
        ];

        $this->shippingLineService
            ->expects($this->once())
            ->method('getShippingLineScope')
            ->with($user)
            ->willReturn($shippingLine);

        $this->activityLogRepository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with($shippingLine, null, null, [], 20, 0)
            ->willReturn($activityLogs);

        $this->activityLogRepository
            ->expects($this->once())
            ->method('countWithFilters')
            ->with($shippingLine, null, null, [])
            ->willReturn(1);

        $this->activityLogService
            ->expects($this->once())
            ->method('logView');

        $this->client->request('GET', '/api/activity-logs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertCount(1, $response['activity_logs']);
        $this->assertEquals('Test Shipping Line', $response['activity_logs'][0]['shipping_line']['brand_name']);
    }

    public function testListActivityLogsWithFilters(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->shippingLineService
            ->expects($this->once())
            ->method('getShippingLineScope')
            ->willReturn(null);

        $this->activityLogRepository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(null, null, null, ['activity_type' => 'login'], 20, 0)
            ->willReturn([]);

        $this->activityLogRepository
            ->expects($this->once())
            ->method('countWithFilters')
            ->willReturn(0);

        $this->client->request('GET', '/api/activity-logs?activity_type=login', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertEquals(['activity_type' => 'login'], $response['filters_applied']);
    }

    public function testShowActivityLogRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/activity-logs/1');
        
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testShowActivityLogNotFound(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->client->request('GET', '/api/activity-logs/999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Activity log not found', $response['error']);
    }

    public function testShowActivityLogAccessDenied(): void
    {
        $shippingLine1 = $this->createTestShippingLine('Shipping Line 1');
        $shippingLine2 = $this->createTestShippingLine('Shipping Line 2');
        
        $user = $this->createTestUser(UserRole::SHIPPING_LINES_ADMIN);
        $otherUser = $this->createTestUser(UserRole::SHIPPING_LINES_ADMIN, 'other@example.com');
        
        $activityLog = $this->createMockActivityLog(1, $otherUser, 'login', $shippingLine2);
        
        $token = $this->jwtService->generateToken($user);

        $this->shippingLineService
            ->expects($this->once())
            ->method('getShippingLineScope')
            ->with($user)
            ->willReturn($shippingLine1);

        // Mock finding the activity log
        $this->entityManager
            ->expects($this->once())
            ->method('getRepository')
            ->with(ActivityLog::class)
            ->willReturn($this->activityLogRepository);

        $this->activityLogRepository
            ->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($activityLog);

        $this->client->request('GET', '/api/activity-logs/1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Access denied', $response['error']);
    }

    public function testSearchActivityLogsRequiresSearchTerm(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->client->request('POST', '/api/activity-logs/search', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Search term is required', $response['error']);
    }

    public function testSearchActivityLogsMinimumLength(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->client->request('POST', '/api/activity-logs/search', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode(['search_term' => 'a']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Search term must be at least 2 characters', $response['error']);
    }

    public function testSearchActivityLogsSuccess(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $searchResults = [
            $this->createMockActivityLog(1, $user, 'login')
        ];

        $this->shippingLineService
            ->expects($this->once())
            ->method('getShippingLineScope')
            ->willReturn(null);

        $this->activityLogService
            ->expects($this->once())
            ->method('searchActivityLogs')
            ->with($user, 'login', null, [])
            ->willReturn($searchResults);

        $this->activityLogService
            ->expects($this->once())
            ->method('logSearch');

        $this->client->request('POST', '/api/activity-logs/search', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json'
        ], json_encode(['search_term' => 'login']));

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertEquals('login', $response['search_term']);
        $this->assertCount(1, $response['results']);
        $this->assertEquals(1, $response['total_found']);
    }

    public function testSummaryReportRequiresDateRange(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->client->request('GET', '/api/activity-logs/reports/summary', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('from_date and to_date parameters are required', $response['error']);
    }

    public function testSummaryReportInvalidDateFormat(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->client->request('GET', '/api/activity-logs/reports/summary?from_date=invalid&to_date=invalid', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Invalid date format. Use Y-m-d format', $response['error']);
    }

    public function testSummaryReportSuccess(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $reportData = [
            'total_activities' => 100,
            'by_type' => ['login' => 50, 'create' => 30, 'update' => 20]
        ];

        $this->shippingLineService
            ->expects($this->once())
            ->method('getShippingLineScope')
            ->willReturn(null);

        $this->activityLogService
            ->expects($this->once())
            ->method('generateSummaryReport')
            ->willReturn($reportData);

        $this->activityLogService
            ->expects($this->once())
            ->method('logReportGeneration');

        $this->client->request('GET', '/api/activity-logs/reports/summary?from_date=2024-01-01&to_date=2024-01-31', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertEquals('summary', $response['report_type']);
        $this->assertEquals('2024-01-01', $response['date_range']['from']);
        $this->assertEquals('2024-01-31', $response['date_range']['to']);
        $this->assertEquals('system_wide', $response['scope']);
        $this->assertEquals($reportData, $response['data']);
    }

    public function testExportActivityLogsInvalidFormat(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->client->request('GET', '/api/activity-logs/export?format=xml', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Invalid format. Supported formats: json, csv', $response['error']);
    }

    public function testExportActivityLogsJsonFormat(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $activityLogs = [
            $this->createMockActivityLog(1, $user, 'login')
        ];

        $this->shippingLineService
            ->expects($this->once())
            ->method('getShippingLineScope')
            ->willReturn(null);

        $this->activityLogRepository
            ->expects($this->once())
            ->method('findWithFilters')
            ->with(null, null, null, [], 1000, 0)
            ->willReturn($activityLogs);

        $this->activityLogService
            ->expects($this->once())
            ->method('logExport');

        $this->client->request('GET', '/api/activity-logs/export?format=json', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertEquals('json', $response['export_format']);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals(1, $response['total_records']);
    }

    public function testGetActivityTypesSuccess(): void
    {
        $user = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $token = $this->jwtService->generateToken($user);

        $this->activityLogService
            ->expects($this->once())
            ->method('logView');

        $this->client->request('GET', '/api/activity-logs/activity-types', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('activity_types', $response);
        $this->assertIsArray($response['activity_types']);
    }

    private function createTestUser(UserRole $role, string $email = 'test@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hashed_password');
        $user->setRole($role);
        $user->setStatus(UserStatus::APPROVED);
        $user->setIsActive(true);
        $user->setEmailVerified(true);
        
        // Use reflection to set the ID
        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($user, 1);
        
        return $user;
    }

    private function createTestShippingLine(string $brandName = 'Test Shipping Line'): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($brandName);
        $shippingLine->setPortalConfig(['theme' => 'blue']);
        $shippingLine->setIsActive(true);
        
        // Use reflection to set the ID
        $reflection = new \ReflectionClass($shippingLine);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($shippingLine, 1);
        
        return $shippingLine;
    }

    private function createMockActivityLog(int $id, User $user, string $activityType, ?ShippingLine $shippingLine = null): ActivityLog
    {
        $activityLog = $this->createMock(ActivityLog::class);
        
        $activityLog->method('getId')->willReturn($id);
        $activityLog->method('getUser')->willReturn($user);
        $activityLog->method('getActivityType')->willReturn($activityType);
        $activityLog->method('getEntityType')->willReturn('User');
        $activityLog->method('getEntityId')->willReturn(1);
        $activityLog->method('getIpAddress')->willReturn('127.0.0.1');
        $activityLog->method('getCreatedAt')->willReturn(new \DateTime());
        $activityLog->method('getActivityDescription')->willReturn('Test activity');
        $activityLog->method('getShippingLine')->willReturn($shippingLine);
        $activityLog->method('getOldValues')->willReturn(null);
        $activityLog->method('getNewValues')->willReturn(null);
        $activityLog->method('getUserAgent')->willReturn('Test Agent');
        $activityLog->method('getSessionId')->willReturn('test_session');
        $activityLog->method('getAdditionalContext')->willReturn(null);
        $activityLog->method('isSecurityActivity')->willReturn($activityType === 'login');
        $activityLog->method('isBusinessActivity')->willReturn($activityType === 'create');
        
        return $activityLog;
    }
}