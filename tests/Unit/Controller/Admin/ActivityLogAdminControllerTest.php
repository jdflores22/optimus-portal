<?php

namespace App\Tests\Unit\Controller\Admin;

use App\Controller\Admin\ActivityLogAdminController;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\ActivityLogService;
use App\Service\ShippingLineService;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ActivityLogAdminControllerTest extends TestCase
{
    private ActivityLogAdminController $controller;
    private MockObject $activityLogService;
    private MockObject $shippingLineService;
    private MockObject $activityLogRepository;
    private MockObject $entityManager;

    protected function setUp(): void
    {
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->shippingLineService = $this->createMock(ShippingLineService::class);
        $this->activityLogRepository = $this->createMock(ActivityLogRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->controller = new ActivityLogAdminController(
            $this->activityLogService,
            $this->shippingLineService,
            $this->activityLogRepository,
            $this->entityManager
        );
    }

    public function testSystemAdminCanAccessAllUsers(): void
    {
        // Create a system admin user
        $systemAdmin = new StaffUser();
        $systemAdmin->setId(1);
        $systemAdmin->setEmail('admin@system.com');
        $systemAdmin->setRole(UserRole::SYSTEM_ADMIN);
        $systemAdmin->setStatus(AccountStatus::APPROVED);
        $systemAdmin->setFirstName('System');
        $systemAdmin->setLastName('Admin');
        $systemAdmin->setDepartment('Administration');

        // Create a target user
        $targetUser = new StaffUser();
        $targetUser->setId(68);
        $targetUser->setEmail('user@maersk.com');
        $targetUser->setRole(UserRole::SL_STAFF);
        $targetUser->setStatus(AccountStatus::APPROVED);
        $targetUser->setFirstName('John');
        $targetUser->setLastName('Doe');
        $targetUser->setDepartment('Operations');

        // Test canAccessUser method using reflection
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('canAccessUser');
        $method->setAccessible(true);

        // Mock entity manager to return the target user (only if called)
        $userRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $userRepository->method('find')
            ->with(68)
            ->willReturn($targetUser);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        // System admin should be able to access any user (returns true immediately)
        $result = $method->invoke($this->controller, $systemAdmin, 68);
        $this->assertTrue($result);
    }

    public function testShippingLineAdminCanOnlyAccessUsersInHierarchy(): void
    {
        // Create a shipping line
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Maersk Line');

        // Create a shipping line admin
        $shippingLineAdmin = new StaffUser();
        $shippingLineAdmin->setId(2);
        $shippingLineAdmin->setEmail('admin@maersk.com');
        $shippingLineAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $shippingLineAdmin->setStatus(AccountStatus::APPROVED);
        $shippingLineAdmin->setFirstName('Shipping');
        $shippingLineAdmin->setLastName('Admin');
        $shippingLineAdmin->setDepartment('Management');
        $shippingLineAdmin->setManagedShippingLine($shippingLine);

        // Create a user in the same hierarchy
        $subordinateUser = new StaffUser();
        $subordinateUser->setId(68);
        $subordinateUser->setEmail('user@maersk.com');
        $subordinateUser->setRole(UserRole::SL_STAFF);
        $subordinateUser->setStatus(AccountStatus::APPROVED);
        $subordinateUser->setFirstName('John');
        $subordinateUser->setLastName('Doe');
        $subordinateUser->setDepartment('Operations');
        $subordinateUser->setShippingLineAdmin($shippingLineAdmin);

        // Create a user outside the hierarchy
        $outsideUser = new StaffUser();
        $outsideUser->setId(99);
        $outsideUser->setEmail('outside@other.com');
        $outsideUser->setRole(UserRole::SL_STAFF);
        $outsideUser->setStatus(AccountStatus::APPROVED);
        $outsideUser->setFirstName('Outside');
        $outsideUser->setLastName('User');
        $outsideUser->setDepartment('Operations');

        // Test canAccessUser method using reflection
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('canAccessUser');
        $method->setAccessible(true);

        // Mock entity manager and shipping line service
        $userRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $userRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturnMap([
                [68, null, null, $subordinateUser],
                [99, null, null, $outsideUser]
            ]);

        $this->entityManager->expects($this->exactly(2))
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        $this->shippingLineService->expects($this->exactly(4))
            ->method('getShippingLineScope')
            ->willReturnMap([
                [$shippingLineAdmin, $shippingLine],
                [$subordinateUser, $shippingLine],
                [$shippingLineAdmin, $shippingLine],
                [$outsideUser, null]
            ]);

        // Should be able to access user in same hierarchy
        $result = $method->invoke($this->controller, $shippingLineAdmin, 68);
        $this->assertTrue($result);

        // Should NOT be able to access user outside hierarchy
        $result = $method->invoke($this->controller, $shippingLineAdmin, 99);
        $this->assertFalse($result);
    }
}