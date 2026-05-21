<?php

namespace App\Tests\Unit\Service;

use App\Entity\PendingUser;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\RoleAcceptanceSecurityService;
use App\Service\StructuredLogger;
use App\Service\ActivityLogService;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class RoleAcceptanceSecurityServiceTest extends TestCase
{
    private RoleAcceptanceSecurityService $service;
    private StructuredLogger|MockObject $structuredLogger;
    private ActivityLogService|MockObject $activityLogService;
    private InAppNotificationService|MockObject $notificationService;
    private EntityManagerInterface|MockObject $entityManager;
    private RequestStack|MockObject $requestStack;

    protected function setUp(): void
    {
        $this->structuredLogger = $this->createMock(StructuredLogger::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->notificationService = $this->createMock(InAppNotificationService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->service = new RoleAcceptanceSecurityService(
            $this->structuredLogger,
            $this->activityLogService,
            $this->notificationService,
            $this->entityManager,
            $this->requestStack
        );
    }

    public function testLogRoleAcceptanceActivity(): void
    {
        // Arrange
        $token = 'test-token-123';
        $action = 'page_access';
        $pendingUser = $this->createMock(PendingUser::class);
        $pendingUser->method('getId')->willReturn(1);
        $pendingUser->method('getEmail')->willReturn('test@example.com');
        $pendingUser->method('getRole')->willReturn(UserRole::SL_STAFF);
        
        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn('192.168.1.1');
        $request->headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $request->headers->method('get')->with('User-Agent')->willReturn('Test Browser');
        $request->server = $this->createMock(\Symfony\Component\HttpFoundation\ServerBag::class);
        $request->server->method('get')->willReturnMap([
            ['HTTP_CLIENT_IP', null, null],
            ['HTTP_X_FORWARDED_FOR', null, null]
        ]);
        
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        // Expect security event to be logged
        $this->structuredLogger->expects($this->once())
            ->method('logSecurityEvent')
            ->with(
                'Role acceptance activity: page_access',
                $this->callback(function($context) use ($token) {
                    return $context['action'] === 'page_access' 
                        && $context['token'] === $token
                        && $context['pending_user_id'] === 1
                        && $context['pending_user_email'] === 'test@example.com';
                })
            );

        // Expect audit event to be logged
        $this->structuredLogger->expects($this->once())
            ->method('logAuditEvent')
            ->with('page_access', 'PendingUser', 1);

        // Act
        $this->service->logRoleAcceptanceActivity($action, $token, $pendingUser);
    }

    public function testTemporarilyDisableToken(): void
    {
        // Arrange
        $pendingUser = $this->createMock(PendingUser::class);
        $pendingUser->method('getAcceptanceToken')->willReturn('test-token');
        $pendingUser->method('getId')->willReturn(1);
        $pendingUser->method('getEmail')->willReturn('test@example.com');
        
        $pendingUser->expects($this->once())
            ->method('setStatus')
            ->with('temporarily_disabled');
            
        $pendingUser->expects($this->once())
            ->method('setDisabledUntil')
            ->with($this->isInstanceOf(\DateTime::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->structuredLogger->expects($this->once())
            ->method('logSecurityEvent')
            ->with(
                'Token temporarily disabled due to suspicious activity',
                $this->callback(function($context) {
                    return $context['token'] === 'test-token'
                        && $context['pending_user_id'] === 1
                        && $context['pending_user_email'] === 'test@example.com';
                })
            );

        // Act
        $this->service->temporarilyDisableToken($pendingUser);
    }

    public function testIsTokenDisabled(): void
    {
        // Test case 1: Token is not disabled
        $pendingUser1 = $this->createMock(PendingUser::class);
        $pendingUser1->method('getStatus')->willReturn('pending');
        
        $this->assertFalse($this->service->isTokenDisabled($pendingUser1));

        // Test case 2: Token is disabled but period has expired
        $pendingUser2 = $this->createMock(PendingUser::class);
        $pendingUser2->method('getStatus')->willReturn('temporarily_disabled');
        $pendingUser2->method('getDisabledUntil')->willReturn(new \DateTime('-1 hour'));
        
        $pendingUser2->expects($this->once())
            ->method('setStatus')
            ->with('pending');
            
        $pendingUser2->expects($this->once())
            ->method('setDisabledUntil')
            ->with(null);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->assertFalse($this->service->isTokenDisabled($pendingUser2));

        // Test case 3: Token is currently disabled
        $pendingUser3 = $this->createMock(PendingUser::class);
        $pendingUser3->method('getStatus')->willReturn('temporarily_disabled');
        $pendingUser3->method('getDisabledUntil')->willReturn(new \DateTime('+1 hour'));
        
        $this->assertTrue($this->service->isTokenDisabled($pendingUser3));
    }

    public function testDetectSuspiciousActivity(): void
    {
        // This test is simplified to avoid complex database mocking
        // In a real scenario, we would use integration tests for this functionality
        
        // Mock request for IP
        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn('192.168.1.1');
        $request->server = $this->createMock(\Symfony\Component\HttpFoundation\ServerBag::class);
        $request->server->method('get')->willReturnMap([
            ['HTTP_CLIENT_IP', null, null],
            ['HTTP_X_FORWARDED_FOR', null, null]
        ]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        // Mock the entity manager to return a query that returns low counts (no suspicious activity)
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getSingleScalarResult')->willReturn(2); // Low count, not suspicious
        
        $this->entityManager->method('createQuery')->willReturn($query);

        // Test: No suspicious activity detected
        $result = $this->service->detectSuspiciousActivity('test-token', 'accept');
        $this->assertFalse($result);
    }
}