<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use PHPUnit\Framework\TestCase;

class UserHierarchyTest extends TestCase
{
    public function testShippingLineScopeForShippingLinesAdmin(): void
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');
        
        $admin = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setManagedShippingLine($shippingLine);
        
        $this->assertEquals($shippingLine, $admin->getShippingLineScope());
    }

    public function testShippingLineScopeForSubordinateUsers(): void
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');
        
        $admin = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setManagedShippingLine($shippingLine);
        
        $staff = $this->createUser(UserRole::SL_STAFF);
        $staff->setShippingLineAdmin($admin);
        
        $this->assertEquals($shippingLine, $staff->getShippingLineScope());
    }

    public function testShippingLineScopeForSystemAdmin(): void
    {
        $systemAdmin = $this->createUser(UserRole::SYSTEM_ADMIN);
        
        $this->assertNull($systemAdmin->getShippingLineScope());
    }

    public function testCanManageUserForSystemAdmin(): void
    {
        $systemAdmin = $this->createUser(UserRole::SYSTEM_ADMIN);
        $otherUser = $this->createUser(UserRole::SL_STAFF);
        
        $this->assertTrue($systemAdmin->canManageUser($otherUser));
    }

    public function testCanManageUserForShippingLinesAdmin(): void
    {
        $admin = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $subordinate = $this->createUser(UserRole::SL_STAFF);
        $subordinate->setShippingLineAdmin($admin);
        
        $this->assertTrue($admin->canManageUser($subordinate));
        
        $otherUser = $this->createUser(UserRole::EVALUATOR);
        $this->assertFalse($admin->canManageUser($otherUser));
    }

    public function testHierarchyLevels(): void
    {
        $systemAdmin = $this->createUser(UserRole::SYSTEM_ADMIN);
        $shippingLinesAdmin = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $staff = $this->createUser(UserRole::SL_STAFF);
        $evaluator = $this->createUser(UserRole::EVALUATOR);
        $accounting = $this->createUser(UserRole::ACCOUNTING);
        $terminalTeam = $this->createUser(UserRole::TERMINAL_TEAM);
        $consignee = $this->createUser(UserRole::CONSIGNEE);
        
        $this->assertEquals(0, $systemAdmin->getHierarchyLevel());
        $this->assertEquals(1, $shippingLinesAdmin->getHierarchyLevel());
        $this->assertEquals(2, $staff->getHierarchyLevel());
        $this->assertEquals(2, $evaluator->getHierarchyLevel());
        $this->assertEquals(2, $accounting->getHierarchyLevel());
        $this->assertEquals(2, $terminalTeam->getHierarchyLevel());
        $this->assertEquals(3, $consignee->getHierarchyLevel());
    }

    public function testRequiresShippingLineAdmin(): void
    {
        $staff = $this->createUser(UserRole::SL_STAFF);
        $evaluator = $this->createUser(UserRole::EVALUATOR);
        $accounting = $this->createUser(UserRole::ACCOUNTING);
        $terminalTeam = $this->createUser(UserRole::TERMINAL_TEAM);
        $consignee = $this->createUser(UserRole::CONSIGNEE);
        $systemAdmin = $this->createUser(UserRole::SYSTEM_ADMIN);
        
        $this->assertTrue($staff->requiresShippingLineAdmin());
        $this->assertTrue($evaluator->requiresShippingLineAdmin());
        $this->assertTrue($accounting->requiresShippingLineAdmin());
        $this->assertTrue($terminalTeam->requiresShippingLineAdmin());
        $this->assertFalse($consignee->requiresShippingLineAdmin());
        $this->assertFalse($systemAdmin->requiresShippingLineAdmin());
    }

    public function testRequiresManagedShippingLine(): void
    {
        $shippingLinesAdmin = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $staff = $this->createUser(UserRole::SL_STAFF);
        
        $this->assertTrue($shippingLinesAdmin->requiresManagedShippingLine());
        $this->assertFalse($staff->requiresManagedShippingLine());
    }

    public function testValidateHierarchy(): void
    {
        // Test valid hierarchy
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');
        
        $admin = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setManagedShippingLine($shippingLine);
        
        $staff = $this->createUser(UserRole::SL_STAFF);
        $staff->setShippingLineAdmin($admin);
        
        $errors = $staff->validateHierarchy();
        $this->assertEmpty($errors);
        
        // Test missing admin for hierarchical role
        $staffWithoutAdmin = $this->createUser(UserRole::SL_STAFF);
        $errors = $staffWithoutAdmin->validateHierarchy();
        $this->assertContains('SL_STAFF role requires a shipping line admin', $errors);
        
        // Test missing managed shipping line for admin
        $adminWithoutShippingLine = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $errors = $adminWithoutShippingLine->validateHierarchy();
        $this->assertContains('SHIPPING_LINES_ADMIN role requires a managed shipping line', $errors);
    }

    public function testSubordinateUserManagement(): void
    {
        $admin = $this->createUser(UserRole::SHIPPING_LINES_ADMIN);
        $staff1 = $this->createUser(UserRole::SL_STAFF);
        $staff2 = $this->createUser(UserRole::EVALUATOR);
        
        $admin->addSubordinateUser($staff1);
        $admin->addSubordinateUser($staff2);
        
        $this->assertCount(2, $admin->getSubordinateUsers());
        $this->assertTrue($admin->getSubordinateUsers()->contains($staff1));
        $this->assertTrue($admin->getSubordinateUsers()->contains($staff2));
        $this->assertEquals($admin, $staff1->getShippingLineAdmin());
        $this->assertEquals($admin, $staff2->getShippingLineAdmin());
        
        $admin->removeSubordinateUser($staff1);
        $this->assertCount(1, $admin->getSubordinateUsers());
        $this->assertFalse($admin->getSubordinateUsers()->contains($staff1));
        $this->assertNull($staff1->getShippingLineAdmin());
    }

    private function createUser(UserRole $role): User
    {
        // Create a concrete User subclass for testing
        return new class($role) extends User {
            public function __construct(UserRole $role)
            {
                parent::__construct();
                $this->role = $role;
                $this->email = 'test@example.com';
                $this->passwordHash = 'hashed_password';
                $this->status = AccountStatus::APPROVED;
            }
        };
    }
}