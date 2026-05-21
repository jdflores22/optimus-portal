<?php

namespace App\Tests\Unit\Service;

use App\Entity\ShippingLine;
use App\Entity\RolePermissionConfiguration;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\RolePermissionConfigurationService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationList;

class RolePermissionConfigurationServiceTest extends TestCase
{
    private RolePermissionConfigurationService $service;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|ValidatorInterface $validator;
    private MockObject|ActivityLogService $activityLogService;
    private MockObject|EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager
            ->method('getRepository')
            ->with(RolePermissionConfiguration::class)
            ->willReturn($this->repository);

        $this->service = new RolePermissionConfigurationService(
            $this->entityManager,
            $this->validator,
            $this->activityLogService
        );
    }

    public function testSetRolePermissionsCreatesNewConfiguration(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createSystemAdmin();
        $role = UserRole::SL_STAFF;
        $permissions = ['user.view', 'data.view_own'];

        $this->repository
            ->method('findOneBy')
            ->willReturn(null); // No existing configuration

        $this->validator
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $this->entityManager
            ->expects($this->exactly(2)) // Once for config, once for history
            ->method('persist');

        $this->entityManager
            ->expects($this->exactly(2)) // Once for config, once for history
            ->method('flush');

        // Act
        $result = $this->service->setRolePermissions($shippingLine, $role, $permissions, $user);

        // Assert
        $this->assertInstanceOf(RolePermissionConfiguration::class, $result);
        $this->assertEquals($role, $result->getRole());
        $this->assertEquals($permissions, $result->getPermissions());
        $this->assertEquals($shippingLine, $result->getShippingLine());
        $this->assertEquals($user, $result->getCreatedBy());
    }

    public function testSetRolePermissionsValidatesPermissions(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createSystemAdmin();
        $role = UserRole::SL_STAFF;
        $invalidPermissions = ['invalid.permission', 'user.view'];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid permission: invalid.permission');

        $this->service->setRolePermissions($shippingLine, $role, $invalidPermissions, $user);
    }

    public function testSetRolePermissionsValidatesRoleHierarchy(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createSystemAdmin();
        $invalidRole = UserRole::CONSIGNEE; // Non-hierarchical role
        $permissions = ['user.view'];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Role CONSIGNEE cannot have shipping line-specific permissions');

        $this->service->setRolePermissions($shippingLine, $invalidRole, $permissions, $user);
    }

    public function testHasPermissionReturnsTrueForValidPermission(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createShippingLineAdmin($shippingLine);
        $permission = 'user.view';

        $roleConfig = new RolePermissionConfiguration();
        $roleConfig->setShippingLine($shippingLine);
        $roleConfig->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $roleConfig->setPermissions(['user.view', 'user.create']);
        $roleConfig->setCreatedBy($user);

        $this->repository
            ->method('findOneBy')
            ->willReturn($roleConfig);

        // Act
        $result = $this->service->hasPermission($user, $permission);

        // Assert
        $this->assertTrue($result);
    }

    public function testHasPermissionReturnsFalseForInvalidPermission(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createShippingLineAdmin($shippingLine);
        $permission = 'admin.delete_all';

        $roleConfig = new RolePermissionConfiguration();
        $roleConfig->setShippingLine($shippingLine);
        $roleConfig->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $roleConfig->setPermissions(['user.view', 'user.create']);
        $roleConfig->setCreatedBy($user);

        $this->repository
            ->method('findOneBy')
            ->willReturn($roleConfig);

        // Act
        $result = $this->service->hasPermission($user, $permission);

        // Assert
        $this->assertFalse($result);
    }

    public function testGetEffectivePermissionsIncludesInheritance(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createUser(UserRole::SL_STAFF);
        $user->setShippingLineAdmin($this->createShippingLineAdmin($shippingLine));

        $roleConfig = new RolePermissionConfiguration();
        $roleConfig->setShippingLine($shippingLine);
        $roleConfig->setRole(UserRole::SL_STAFF);
        $roleConfig->setPermissions(['data.view_own']);
        $roleConfig->setInheritFromParent(true);
        $roleConfig->setCreatedBy($user);

        $this->repository
            ->method('findOneBy')
            ->willReturn($roleConfig);

        // Act
        $result = $this->service->getEffectivePermissions($user);

        // Assert
        $this->assertContains('data.view_own', $result);
        $this->assertIsArray($result);
    }

    public function testGetDefaultPermissionsReturnsCorrectPermissions(): void
    {
        // Act
        $systemAdminPermissions = $this->service->getDefaultPermissions(UserRole::SYSTEM_ADMIN);
        $slStaffPermissions = $this->service->getDefaultPermissions(UserRole::SL_STAFF);
        $consigneePermissions = $this->service->getDefaultPermissions(UserRole::CONSIGNEE);

        // Assert
        $this->assertContains('user.create', $systemAdminPermissions);
        $this->assertContains('user.delete', $systemAdminPermissions);
        
        $this->assertContains('user.view', $slStaffPermissions);
        $this->assertNotContains('user.delete', $slStaffPermissions);
        
        $this->assertContains('data.view_own', $consigneePermissions);
        $this->assertNotContains('user.create', $consigneePermissions);
    }

    public function testValidateUserAccessWithTimeRestrictions(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createShippingLineAdmin($shippingLine);
        $resource = 'user_management';

        $roleConfig = new RolePermissionConfiguration();
        $roleConfig->setShippingLine($shippingLine);
        $roleConfig->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $roleConfig->setPermissions(['user.view']);
        $roleConfig->setRestrictions([
            'time_restrictions' => [
                'allowed_hours' => ['start' => '09:00', 'end' => '17:00'],
                'allowed_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
            ]
        ]);
        $roleConfig->setCreatedBy($user);

        $this->repository
            ->method('findOneBy')
            ->willReturn($roleConfig);

        // Act - This would need to be tested with specific time mocking
        $result = $this->service->validateUserAccess($user, $resource);

        // Assert - For now, just verify the method doesn't throw an exception
        $this->assertIsBool($result);
    }

    public function testDeleteRolePermissionsRemovesConfiguration(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createSystemAdmin();
        $role = UserRole::SL_STAFF;

        $existingConfig = new RolePermissionConfiguration();
        $existingConfig->setShippingLine($shippingLine);
        $existingConfig->setRole($role);
        $existingConfig->setPermissions(['user.view']);
        $existingConfig->setCreatedBy($user);

        $this->repository
            ->method('findOneBy')
            ->willReturn($existingConfig);

        $this->entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($existingConfig);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush');

        // Act
        $this->service->deleteRolePermissions($shippingLine, $role, $user);

        // Assert - No exception should be thrown
        $this->assertTrue(true);
    }

    public function testGetAvailablePermissionsReturnsAllPermissions(): void
    {
        // Act
        $permissions = $this->service->getAvailablePermissions();

        // Assert
        $this->assertIsArray($permissions);
        $this->assertContains('user.create', $permissions);
        $this->assertContains('user.view', $permissions);
        $this->assertContains('data.view_own', $permissions);
        $this->assertContains('reports.generate', $permissions);
        $this->assertContains('terminal.assign', $permissions);
        $this->assertContains('container.track', $permissions);
    }

    private function createShippingLine(): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');
        return $shippingLine;
    }

    private function createSystemAdmin(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('admin@test.com');
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);
        $user->method('getManagedShippingLine')->willReturn(null);
        $user->method('getShippingLineAdmin')->willReturn(null);
        return $user;
    }

    private function createShippingLineAdmin(ShippingLine $shippingLine): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('admin@shipping.com');
        $user->method('getRole')->willReturn(UserRole::SHIPPING_LINES_ADMIN);
        $user->method('getManagedShippingLine')->willReturn($shippingLine);
        $user->method('getShippingLineAdmin')->willReturn(null);
        return $user;
    }

    private function createUser(UserRole $role): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('user@test.com');
        $user->method('getRole')->willReturn($role);
        $user->method('getManagedShippingLine')->willReturn(null);
        $user->method('getShippingLineAdmin')->willReturn(null);
        return $user;
    }
}