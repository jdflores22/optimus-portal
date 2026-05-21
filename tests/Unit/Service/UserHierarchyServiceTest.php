<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\UserHierarchyService;
use App\Service\ActivityLogService;
use App\Service\ShippingLineService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class UserHierarchyServiceTest extends TestCase
{
    private UserHierarchyService $service;
    private EntityManagerInterface|MockObject $entityManager;
    private ActivityLogService|MockObject $activityLogService;
    private ShippingLineService|MockObject $shippingLineService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->shippingLineService = $this->createMock(ShippingLineService::class);

        $this->service = new UserHierarchyService(
            $this->entityManager,
            $this->activityLogService,
            $this->shippingLineService
        );
    }

    public function testLinkUserToAdminSuccess(): void
    {
        // Arrange
        $linker = new StaffUser();
        $linker->setRole(UserRole::SYSTEM_ADMIN);
        $linker->setEmail('system@test.com');

        $admin = new StaffUser();
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setEmail('admin@test.com');
        $admin->setStatus(AccountStatus::APPROVED);

        $user = new StaffUser();
        $user->setRole(UserRole::SL_STAFF);
        $user->setEmail('staff@test.com');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->activityLogService->expects($this->once())
            ->method('logHierarchyChange')
            ->with($linker, $user, null, $admin);

        // Act
        $this->service->linkUserToAdmin($user, $admin, $linker);

        // Assert
        $this->assertEquals($admin, $user->getShippingLineAdmin());
    }

    public function testLinkUserToAdminFailsWithWrongAdminRole(): void
    {
        // Arrange
        $linker = new StaffUser();
        $linker->setRole(UserRole::SYSTEM_ADMIN);

        $admin = new StaffUser();
        $admin->setRole(UserRole::SL_STAFF); // Wrong role

        $user = new StaffUser();
        $user->setRole(UserRole::EVALUATOR);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid hierarchy: SL_STAFF cannot supervise EVALUATOR');

        // Act
        $this->service->linkUserToAdmin($user, $admin, $linker);
    }

    public function testLinkUserToAdminFailsWithInactiveAdmin(): void
    {
        // Arrange
        $linker = new StaffUser();
        $linker->setRole(UserRole::SYSTEM_ADMIN);

        $admin = new StaffUser();
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setStatus(AccountStatus::LOCKED); // Inactive

        $user = new StaffUser();
        $user->setRole(UserRole::SL_STAFF);

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot link to inactive admin');

        // Act
        $this->service->linkUserToAdmin($user, $admin, $linker);
    }

    public function testValidateRoleHierarchyValidCombinations(): void
    {
        // Act & Assert
        $this->assertTrue($this->service->validateRoleHierarchy(
            UserRole::SYSTEM_ADMIN, 
            UserRole::SHIPPING_LINES_ADMIN
        ));
        
        $this->assertTrue($this->service->validateRoleHierarchy(
            UserRole::SHIPPING_LINES_ADMIN, 
            UserRole::SL_STAFF
        ));
        
        $this->assertTrue($this->service->validateRoleHierarchy(
            UserRole::SHIPPING_LINES_ADMIN, 
            UserRole::EVALUATOR
        ));
        
        $this->assertTrue($this->service->validateRoleHierarchy(
            UserRole::SHIPPING_LINES_ADMIN, 
            UserRole::ACCOUNTING
        ));
        
        $this->assertTrue($this->service->validateRoleHierarchy(
            UserRole::SHIPPING_LINES_ADMIN, 
            UserRole::TERMINAL_TEAM
        ));
    }

    public function testValidateRoleHierarchyInvalidCombinations(): void
    {
        // Act & Assert
        $this->assertFalse($this->service->validateRoleHierarchy(
            UserRole::SL_STAFF, 
            UserRole::EVALUATOR
        ));
        
        $this->assertFalse($this->service->validateRoleHierarchy(
            UserRole::EVALUATOR, 
            UserRole::SHIPPING_LINES_ADMIN
        ));
        
        $this->assertFalse($this->service->validateRoleHierarchy(
            UserRole::ACCOUNTING, 
            UserRole::SYSTEM_ADMIN
        ));
    }

    public function testGetSubordinateUsers(): void
    {
        // Arrange
        $admin = new StaffUser();
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $staff1 = new StaffUser();
        $staff1->setRole(UserRole::SL_STAFF);
        
        $staff2 = new StaffUser();
        $staff2->setRole(UserRole::EVALUATOR);

        $subordinates = new ArrayCollection([$staff1, $staff2]);
        
        // Mock the getSubordinateUsers method
        $adminMock = $this->createMock(User::class);
        $adminMock->expects($this->once())
            ->method('getSubordinateUsers')
            ->willReturn($subordinates);

        // Act
        $result = $this->service->getSubordinateUsers($adminMock);

        // Assert
        $this->assertEquals($subordinates, $result);
        $this->assertCount(2, $result);
    }

    public function testOrphanedUserCleanupWithReassignment(): void
    {
        // Arrange
        $actor = new StaffUser();
        $actor->setRole(UserRole::SYSTEM_ADMIN);

        $deletedAdmin = new StaffUser();
        $deletedAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $newAdmin = new StaffUser();
        $newAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $newAdmin->setStatus(AccountStatus::APPROVED);

        $orphanedUser = new StaffUser();
        $orphanedUser->setRole(UserRole::SL_STAFF);

        $subordinates = new ArrayCollection([$orphanedUser]);
        
        // Mock the deletedAdmin properly
        $deletedAdminMock = $this->createMock(User::class);
        $deletedAdminMock->expects($this->once())
            ->method('getSubordinateUsers')
            ->willReturn($subordinates);

        $this->entityManager->expects($this->atLeastOnce())
            ->method('flush');

        $this->activityLogService->expects($this->once())
            ->method('logUserReassignment')
            ->with($actor, $orphanedUser, $deletedAdminMock, $newAdmin);

        // Act
        $this->service->orphanedUserCleanup($deletedAdminMock, $actor, 'reassign', $newAdmin);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testOrphanedUserCleanupFailsWithNonSystemAdmin(): void
    {
        // Arrange
        $actor = new StaffUser();
        $actor->setRole(UserRole::SHIPPING_LINES_ADMIN); // Not SYSTEM_ADMIN

        $deletedAdmin = new StaffUser();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only SYSTEM_ADMIN can perform orphaned user cleanup');

        // Act
        $this->service->orphanedUserCleanup($deletedAdmin, $actor, 'reassign');
    }

    public function testOrphanedUserCleanupFailsWithInvalidStrategy(): void
    {
        // Arrange
        $actor = new StaffUser();
        $actor->setRole(UserRole::SYSTEM_ADMIN);

        $deletedAdmin = new StaffUser();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cleanup strategy');

        // Act
        $this->service->orphanedUserCleanup($deletedAdmin, $actor, 'invalid_strategy');
    }

    public function testOrphanedUserCleanupFailsWithReassignButNoNewAdmin(): void
    {
        // Arrange
        $actor = new StaffUser();
        $actor->setRole(UserRole::SYSTEM_ADMIN);

        $deletedAdmin = new StaffUser();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('New admin is required for reassignment strategy');

        // Act
        $this->service->orphanedUserCleanup($deletedAdmin, $actor, 'reassign', null);
    }

    public function testRemoveFromHierarchySuccess(): void
    {
        // Arrange
        $remover = new StaffUser();
        $remover->setRole(UserRole::SYSTEM_ADMIN);

        $admin = new StaffUser();
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $user = new StaffUser();
        $user->setRole(UserRole::SL_STAFF);
        $user->setShippingLineAdmin($admin);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->activityLogService->expects($this->once())
            ->method('logHierarchyChange')
            ->with($remover, $user, $admin, null);

        // Act
        $this->service->removeFromHierarchy($user, $remover);

        // Assert
        $this->assertNull($user->getShippingLineAdmin());
    }

    public function testRemoveFromHierarchyFailsWithNoAdmin(): void
    {
        // Arrange
        $remover = new StaffUser();
        $remover->setRole(UserRole::SYSTEM_ADMIN);

        $user = new StaffUser();
        $user->setRole(UserRole::SL_STAFF);
        // No admin set

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User is not linked to any admin');

        // Act
        $this->service->removeFromHierarchy($user, $remover);
    }

    public function testCanManageHierarchySystemAdmin(): void
    {
        // Arrange
        $manager = new StaffUser();
        $manager->setRole(UserRole::SYSTEM_ADMIN);

        $target = new StaffUser();
        $target->setRole(UserRole::SL_STAFF);

        // Act
        $result = $this->service->canManageHierarchy($manager, $target);

        // Assert
        $this->assertTrue($result);
    }

    public function testCanManageHierarchyShippingLineAdmin(): void
    {
        // Arrange
        $manager = new StaffUser();
        $manager->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $target = new StaffUser();
        $target->setRole(UserRole::SL_STAFF);
        $target->setShippingLineAdmin($manager);

        // Act
        $result = $this->service->canManageHierarchy($manager, $target);

        // Assert
        $this->assertTrue($result);
    }

    public function testCanManageHierarchyUnauthorized(): void
    {
        // Arrange
        $manager = new StaffUser();
        $manager->setRole(UserRole::SL_STAFF);

        $target = new StaffUser();
        $target->setRole(UserRole::EVALUATOR);

        // Act
        $result = $this->service->canManageHierarchy($manager, $target);

        // Assert
        $this->assertFalse($result);
    }

    public function testTransferUsersSuccess(): void
    {
        // Arrange
        $transferor = new StaffUser();
        $transferor->setRole(UserRole::SYSTEM_ADMIN);

        $fromAdmin = new StaffUser();
        $fromAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $toAdmin = new StaffUser();
        $toAdmin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $toAdmin->setStatus(AccountStatus::APPROVED);

        $user1 = new StaffUser();
        $user1->setId(1);
        $user1->setRole(UserRole::SL_STAFF);
        $user1->setShippingLineAdmin($fromAdmin);

        $user2 = new StaffUser();
        $user2->setId(2);
        $user2->setRole(UserRole::EVALUATOR);
        $user2->setShippingLineAdmin($fromAdmin);

        $userRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $userRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturnMap([
                [1, $user1],
                [2, $user2]
            ]);

        $this->entityManager->expects($this->any())
            ->method('getRepository')
            ->willReturn($userRepository);

        // Act
        $this->service->transferUsers($fromAdmin, $toAdmin, $transferor, [1, 2]);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testTransferUsersFailsWithNonSystemAdmin(): void
    {
        // Arrange
        $transferor = new StaffUser();
        $transferor->setRole(UserRole::SHIPPING_LINES_ADMIN); // Not SYSTEM_ADMIN

        $fromAdmin = new StaffUser();
        $toAdmin = new StaffUser();

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only SYSTEM_ADMIN can transfer users between admins');

        // Act
        $this->service->transferUsers($fromAdmin, $toAdmin, $transferor);
    }
}