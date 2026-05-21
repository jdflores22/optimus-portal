<?php

namespace App\Tests\Integration\Service;

use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\ActivityLog;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\ScopeAccessControlService;
use App\Service\AuthenticationService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AccessControlIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ScopeAccessControlService $scopeAccessControlService;
    private AuthenticationService $authenticationService;
    private ActivityLogService $activityLogService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->scopeAccessControlService = $container->get(ScopeAccessControlService::class);
        $this->authenticationService = $container->get(AuthenticationService::class);
        $this->activityLogService = $container->get(ActivityLogService::class);

        // Start a transaction for test isolation
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testShippingLineScopeEnforcement(): void
    {
        // Arrange - Create two shipping lines
        $shippingLine1 = $this->createShippingLine('Shipping Line 1');
        $shippingLine2 = $this->createShippingLine('Shipping Line 2');

        // Create users in different shipping lines
        $admin1 = $this->createShippingLineAdmin('admin1@example.com', $shippingLine1);
        $staff1 = $this->createStaffUser('staff1@example.com', $admin1);
        
        $admin2 = $this->createShippingLineAdmin('admin2@example.com', $shippingLine2);
        $staff2 = $this->createStaffUser('staff2@example.com', $admin2);

        $this->entityManager->flush();

        // Act & Assert - staff1 should not be able to access staff2's data
        $this->expectException(AccessDeniedException::class);
        $this->scopeAccessControlService->validateAccess($staff1, $staff2);
    }

    public function testSystemAdminCanAccessAllShippingLines(): void
    {
        // Arrange
        $systemAdmin = $this->createSystemAdmin('sysadmin@example.com');
        $shippingLine = $this->createShippingLine('Test Shipping Line');
        $admin = $this->createShippingLineAdmin('admin@example.com', $shippingLine);
        $staff = $this->createStaffUser('staff@example.com', $admin);

        $this->entityManager->flush();

        // Act & Assert - System admin should have access to all users
        $this->assertTrue($this->scopeAccessControlService->validateAccess($systemAdmin, $admin));
        $this->assertTrue($this->scopeAccessControlService->validateAccess($systemAdmin, $staff));
        $this->assertTrue($this->scopeAccessControlService->canAccessShippingLine($systemAdmin, $shippingLine));
    }

    public function testShippingLineAdminCanManageSubordinates(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine('Test Shipping Line');
        $admin = $this->createShippingLineAdmin('admin@example.com', $shippingLine);
        $staff = $this->createStaffUser('staff@example.com', $admin);
        $evaluator = $this->createEvaluatorUser('evaluator@example.com', $admin);

        $this->entityManager->flush();

        // Act & Assert - Admin should be able to access their subordinates
        $this->assertTrue($this->scopeAccessControlService->validateAccess($admin, $staff));
        $this->assertTrue($this->scopeAccessControlService->validateAccess($admin, $evaluator));
        $this->assertTrue($admin->canManageUser($staff));
        $this->assertTrue($admin->canManageUser($evaluator));
    }

    public function testSubordinateUsersCannotAccessOtherShippingLines(): void
    {
        // Arrange
        $shippingLine1 = $this->createShippingLine('Shipping Line 1');
        $shippingLine2 = $this->createShippingLine('Shipping Line 2');

        $admin1 = $this->createShippingLineAdmin('admin1@example.com', $shippingLine1);
        $staff1 = $this->createStaffUser('staff1@example.com', $admin1);

        $admin2 = $this->createShippingLineAdmin('admin2@example.com', $shippingLine2);

        $this->entityManager->flush();

        // Act & Assert - staff1 should not be able to access admin2
        $this->assertFalse($this->scopeAccessControlService->canAccessShippingLine($staff1, $shippingLine2));
        
        $this->expectException(AccessDeniedException::class);
        $this->scopeAccessControlService->validateAccess($staff1, $admin2);
    }

    public function testActivityLoggingForSecurityEvents(): void
    {
        // Arrange
        $shippingLine1 = $this->createShippingLine('Shipping Line 1');
        $shippingLine2 = $this->createShippingLine('Shipping Line 2');

        $admin1 = $this->createShippingLineAdmin('admin1@example.com', $shippingLine1);
        $staff1 = $this->createStaffUser('staff1@example.com', $admin1);

        $admin2 = $this->createShippingLineAdmin('admin2@example.com', $shippingLine2);

        $this->entityManager->flush();

        // Act - Attempt unauthorized access (should be logged)
        try {
            $this->scopeAccessControlService->validateAccess($staff1, $admin2);
        } catch (AccessDeniedException $e) {
            // Expected exception
        }

        $this->entityManager->flush();

        // Assert - Check that security event was logged
        $activityLogs = $this->entityManager->getRepository(ActivityLog::class)
            ->findBy(['user' => $staff1, 'activityType' => ActivityLog::TYPE_ACCESS_DENIED]);

        $this->assertCount(1, $activityLogs);
        $this->assertEquals(ActivityLog::TYPE_ACCESS_DENIED, $activityLogs[0]->getActivityType());
    }

    public function testIndependentRolesPreserveExistingAccess(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine('Test Shipping Line');
        $consignee = $this->createConsigneeUser('consignee@example.com');
        $broker = $this->createBrokerUser('broker@example.com');

        $this->entityManager->flush();

        // Act & Assert - Independent roles should maintain their access patterns
        $this->assertTrue($this->scopeAccessControlService->canAccessShippingLine($consignee, $shippingLine));
        $this->assertTrue($this->scopeAccessControlService->canAccessShippingLine($broker, $shippingLine));
        $this->assertTrue($this->scopeAccessControlService->canAccessShippingLine($consignee, null));
        $this->assertTrue($this->scopeAccessControlService->canAccessShippingLine($broker, null));
    }

    public function testAuthenticationWithHierarchyValidation(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine('Test Shipping Line');
        $admin = $this->createShippingLineAdmin('admin@example.com', $shippingLine);
        $staff = $this->createStaffUser('staff@example.com', $admin);

        // Set passwords
        $admin->setPasswordHash(password_hash('admin123', PASSWORD_BCRYPT));
        $staff->setPasswordHash(password_hash('staff123', PASSWORD_BCRYPT));

        $this->entityManager->flush();

        // Act & Assert - Authentication should validate hierarchy
        $authenticatedAdmin = $this->authenticationService->authenticateUser('admin@example.com', 'admin123');
        $this->assertEquals($admin->getId(), $authenticatedAdmin->getId());

        $authenticatedStaff = $this->authenticationService->authenticateUser('staff@example.com', 'staff123');
        $this->assertEquals($staff->getId(), $authenticatedStaff->getId());
    }

    public function testSuspendedAdminAffectsSubordinates(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine('Test Shipping Line');
        $admin = $this->createShippingLineAdmin('admin@example.com', $shippingLine);
        $staff = $this->createStaffUser('staff@example.com', $admin);

        $admin->setPasswordHash(password_hash('admin123', PASSWORD_BCRYPT));
        $staff->setPasswordHash(password_hash('staff123', PASSWORD_BCRYPT));

        $this->entityManager->flush();

        // Act - Suspend the admin
        $admin->setStatus(AccountStatus::SUSPENDED);
        $this->entityManager->flush();

        // Assert - Staff should not be able to authenticate when admin is suspended
        $this->expectException(\Symfony\Component\Security\Core\Exception\DisabledException::class);
        $this->authenticationService->authenticateUser('staff@example.com', 'staff123');
    }

    // Helper methods for creating test entities

    private function createShippingLine(string $brandName): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($brandName);
        $shippingLine->setPortalConfig(['theme' => 'default']);

        $this->entityManager->persist($shippingLine);
        return $shippingLine;
    }

    private function createSystemAdmin(string $email): User
    {
        $user = new \App\Entity\StaffUser(); // Using concrete class
        $user->setEmail($email);
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setPasswordHash(password_hash('password', PASSWORD_BCRYPT));

        $this->entityManager->persist($user);
        return $user;
    }

    private function createShippingLineAdmin(string $email, ShippingLine $shippingLine): User
    {
        $user = new \App\Entity\StaffUser(); // Using concrete class
        $user->setEmail($email);
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setManagedShippingLine($shippingLine);
        $user->setPasswordHash(password_hash('password', PASSWORD_BCRYPT));

        $this->entityManager->persist($user);
        return $user;
    }

    private function createStaffUser(string $email, User $admin): User
    {
        $user = new \App\Entity\StaffUser(); // Using concrete class
        $user->setEmail($email);
        $user->setRole(UserRole::SL_STAFF);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setShippingLineAdmin($admin);
        $user->setPasswordHash(password_hash('password', PASSWORD_BCRYPT));

        $this->entityManager->persist($user);
        return $user;
    }

    private function createEvaluatorUser(string $email, User $admin): User
    {
        $user = new \App\Entity\StaffUser(); // Using concrete class
        $user->setEmail($email);
        $user->setRole(UserRole::EVALUATOR);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setShippingLineAdmin($admin);
        $user->setPasswordHash(password_hash('password', PASSWORD_BCRYPT));

        $this->entityManager->persist($user);
        return $user;
    }

    private function createConsigneeUser(string $email): User
    {
        $user = new \App\Entity\Consignee(); // Using concrete class
        $user->setEmail($email);
        $user->setRole(UserRole::CONSIGNEE);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setPasswordHash(password_hash('password', PASSWORD_BCRYPT));

        $this->entityManager->persist($user);
        return $user;
    }

    private function createBrokerUser(string $email): User
    {
        $user = new \App\Entity\Broker(); // Using concrete class
        $user->setEmail($email);
        $user->setRole(UserRole::BROKER);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setPasswordHash(password_hash('password', PASSWORD_BCRYPT));

        $this->entityManager->persist($user);
        return $user;
    }
}