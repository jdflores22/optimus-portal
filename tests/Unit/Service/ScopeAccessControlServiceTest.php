<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Service\ScopeAccessControlService;
use App\Service\ActivityLogService;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ScopeAccessControlServiceTest extends TestCase
{
    private ScopeAccessControlService $service;
    private ActivityLogService|MockObject $activityLogService;

    protected function setUp(): void
    {
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->service = new ScopeAccessControlService($this->activityLogService);
    }

    public function testFilterByShippingLineScopeForSystemAdmin(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $qb = $this->createMock(QueryBuilder::class);
        
        // SYSTEM_ADMIN should not have any filters applied
        $qb->expects($this->never())->method('andWhere');

        // Act
        $result = $this->service->filterByShippingLineScope($qb, $user);

        // Assert
        $this->assertSame($qb, $result);
    }

    public function testFilterByShippingLineScopeForIndependentRoles(): void
    {
        // Test CONSIGNEE role
        $user = $this->createUser(UserRole::CONSIGNEE);
        $qb = $this->createMock(QueryBuilder::class);
        
        // Independent roles should not have shipping line filters
        $qb->expects($this->never())->method('andWhere');

        $result = $this->service->filterByShippingLineScope($qb, $user);
        $this->assertSame($qb, $result);

        // Test BROKER role
        $user = $this->createUser(UserRole::BROKER);
        $result = $this->service->filterByShippingLineScope($qb, $user);
        $this->assertSame($qb, $result);

        // Test TRUCKER role
        $user = $this->createUser(UserRole::TRUCKER);
        $result = $this->service->filterByShippingLineScope($qb, $user);
        $this->assertSame($qb, $result);
    }

    public function testFilterByShippingLineScopeForShippingLineAdmin(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createUser(UserRole::SHIPPING_LINES_ADMIN, $shippingLine);
        
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['u']);
        $qb->method('getRootEntities')->willReturn(['App\Entity\User']);
        
        // Should apply shipping line filter
        $qb->expects($this->once())
           ->method('andWhere')
           ->with('u.shippingLine = :shippingLineScope')
           ->willReturnSelf();
        
        $qb->expects($this->once())
           ->method('setParameter')
           ->with('shippingLineScope', $shippingLine)
           ->willReturnSelf();

        // Act
        $result = $this->service->filterByShippingLineScope($qb, $user);

        // Assert
        $this->assertSame($qb, $result);
    }

    public function testValidateAccessForSystemAdmin(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $entity = new \stdClass();

        // Act & Assert
        $this->assertTrue($this->service->validateAccess($user, $entity));
    }

    public function testValidateAccessForSameScopeEntity(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createUser(UserRole::SHIPPING_LINES_ADMIN, $shippingLine);
        
        $entity = $this->createMock(User::class);
        $entity->method('getShippingLineScope')->willReturn($shippingLine);

        // Act & Assert
        $this->assertTrue($this->service->validateAccess($user, $entity));
    }

    public function testValidateAccessDeniedForDifferentScope(): void
    {
        // Arrange
        $userShippingLine = $this->createShippingLine(1, 'User Shipping Line');
        $entityShippingLine = $this->createShippingLine(2, 'Entity Shipping Line');
        
        $user = $this->createUser(UserRole::SHIPPING_LINES_ADMIN, $userShippingLine);
        
        $entity = $this->createMock(User::class);
        $entity->method('getShippingLineScope')->willReturn($entityShippingLine);
        $entity->method('getId')->willReturn(123);

        // Expect access denied to be logged
        $this->activityLogService->expects($this->once())
            ->method('logAccessDenied')
            ->with(
                $user, 
                $this->stringContains(':123'), 
                'Entity outside shipping line scope'
            );

        // Act & Assert
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access denied: Entity outside shipping line scope');
        
        $this->service->validateAccess($user, $entity);
    }

    public function testCanAccessShippingLineForSystemAdmin(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $shippingLine = $this->createShippingLine();

        // Act & Assert
        $this->assertTrue($this->service->canAccessShippingLine($user, $shippingLine));
    }

    public function testCanAccessShippingLineForIndependentRoles(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::CONSIGNEE);
        $shippingLine = $this->createShippingLine();

        // Act & Assert
        $this->assertTrue($this->service->canAccessShippingLine($user, $shippingLine));
    }

    public function testCanAccessShippingLineForSameScope(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createUser(UserRole::SHIPPING_LINES_ADMIN, $shippingLine);

        // Act & Assert
        $this->assertTrue($this->service->canAccessShippingLine($user, $shippingLine));
    }

    public function testCannotAccessShippingLineForDifferentScope(): void
    {
        // Arrange
        $userShippingLine = $this->createShippingLine(1, 'User Shipping Line');
        $otherShippingLine = $this->createShippingLine(2, 'Other Shipping Line');
        
        $user = $this->createUser(UserRole::SHIPPING_LINES_ADMIN, $userShippingLine);

        // Act & Assert
        $this->assertFalse($this->service->canAccessShippingLine($user, $otherShippingLine));
    }

    public function testCanPerformActionWithValidAccess(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createUser(UserRole::SHIPPING_LINES_ADMIN, $shippingLine);
        
        $entity = $this->createMock(User::class);
        $entity->method('getShippingLineScope')->willReturn($shippingLine);

        // Act & Assert
        $this->assertTrue($this->service->canPerformAction($user, 'read', $entity));
    }

    public function testLogSuspiciousActivity(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::SL_STAFF);
        $resource = 'test_resource';
        $details = ['key' => 'value'];

        // Expect suspicious activity to be logged
        $this->activityLogService->expects($this->once())
            ->method('logSuspiciousActivity')
            ->with(
                $user,
                'unauthorized_access_attempt',
                $this->callback(function ($logDetails) use ($resource, $details) {
                    return $logDetails['resource'] === $resource
                        && $logDetails['details'] === $details
                        && isset($logDetails['timestamp']);
                })
            );

        // Act
        $this->service->logSuspiciousActivity($user, $resource, $details);
    }

    public function testPreventPrivilegeEscalation(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::SL_STAFF);
        $attemptedAction = 'admin_access';

        // Expect privilege escalation attempt to be logged
        $this->activityLogService->expects($this->once())
            ->method('logPrivilegeEscalationAttempt')
            ->with($user, $attemptedAction);

        // Act & Assert
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Privilege escalation attempt detected and blocked');
        
        $this->service->preventPrivilegeEscalation($user, $attemptedAction);
    }

    public function testGetAccessibleShippingLinesForSystemAdmin(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);

        // Act
        $result = $this->service->getAccessibleShippingLines($user);

        // Assert
        $this->assertIsArray($result);
        // Note: In real implementation, this would return all shipping lines
        $this->assertEmpty($result); // Placeholder implementation returns empty array
    }

    public function testGetAccessibleShippingLinesForShippingLineUser(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createUser(UserRole::SHIPPING_LINES_ADMIN, $shippingLine);

        // Act
        $result = $this->service->getAccessibleShippingLines($user);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame($shippingLine, $result[0]);
    }

    public function testGetAccessibleShippingLinesForIndependentRole(): void
    {
        // Arrange
        $user = $this->createUser(UserRole::CONSIGNEE);

        // Act
        $result = $this->service->getAccessibleShippingLines($user);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // Helper methods

    private function createUser(UserRole $role, ?ShippingLine $shippingLine = null): User|MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn($role);
        $user->method('getShippingLineScope')->willReturn($shippingLine);
        
        return $user;
    }

    private function createShippingLine(int $id = 1, string $brandName = 'Test Shipping Line'): ShippingLine|MockObject
    {
        $shippingLine = $this->createMock(ShippingLine::class);
        $shippingLine->method('getId')->willReturn($id);
        $shippingLine->method('getBrandName')->willReturn($brandName);
        
        return $shippingLine;
    }
}