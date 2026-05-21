<?php

namespace App\Tests\Unit;

use App\Entity\PendingUser;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PendingUser entity
 */
class PendingUserEntityTest extends TestCase
{
    public function testPendingUserCreation(): void
    {
        // Create admin user
        $admin = new StaffUser();
        $admin->setEmail('admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SYSTEM_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setDepartment('IT');

        // Create pending user
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRole(UserRole::CONSIGNEE)
            ->setCreatedByAdmin($admin);

        // Generate token and set expiration
        $pendingUser->generateAcceptanceToken();
        $pendingUser->setTokenExpirationToSevenDays();

        // Test basic properties
        $this->assertEquals('test@example.com', $pendingUser->getEmail());
        $this->assertEquals('Test', $pendingUser->getFirstName());
        $this->assertEquals('User', $pendingUser->getLastName());
        $this->assertEquals('Test User', $pendingUser->getFullName());
        $this->assertEquals(UserRole::CONSIGNEE, $pendingUser->getRole());
        $this->assertEquals($admin, $pendingUser->getCreatedByAdmin());
        $this->assertEquals('pending', $pendingUser->getStatus());

        // Test token properties
        $token = $pendingUser->getAcceptanceToken();
        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        // Test token validity
        $this->assertTrue($pendingUser->isTokenValid());
        $this->assertFalse($pendingUser->isExpired());
        $this->assertTrue($pendingUser->canBeProcessed());

        // Test string representation
        $this->assertEquals('Test User (test@example.com)', (string) $pendingUser);
    }

    public function testTokenUniqueness(): void
    {
        $admin = new StaffUser();
        $admin->setEmail('admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SYSTEM_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setDepartment('IT');

        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $pendingUser = new PendingUser();
            $pendingUser->setEmail("user{$i}@example.com")
                ->setFirstName("First{$i}")
                ->setLastName("Last{$i}")
                ->setRole(UserRole::CONSIGNEE)
                ->setCreatedByAdmin($admin);
            
            $pendingUser->generateAcceptanceToken();
            $tokens[] = $pendingUser->getAcceptanceToken();
        }

        // All tokens should be unique
        $uniqueTokens = array_unique($tokens);
        $this->assertCount(10, $uniqueTokens);
    }

    public function testStatusTransitions(): void
    {
        $admin = new StaffUser();
        $admin->setEmail('admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SYSTEM_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setDepartment('IT');

        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRole(UserRole::CONSIGNEE)
            ->setCreatedByAdmin($admin);
        
        $pendingUser->generateAcceptanceToken();
        $pendingUser->setTokenExpirationToSevenDays();

        // Initial status
        $this->assertEquals('pending', $pendingUser->getStatus());
        $this->assertTrue($pendingUser->canBeProcessed());

        // Test accepted status
        $pendingUser->markAsAccepted();
        $this->assertEquals('accepted', $pendingUser->getStatus());
        $this->assertFalse($pendingUser->canBeProcessed());

        // Reset and test declined status
        $pendingUser->setStatus('pending');
        $pendingUser->markAsDeclined();
        $this->assertEquals('declined', $pendingUser->getStatus());
        $this->assertFalse($pendingUser->canBeProcessed());

        // Reset and test expired status
        $pendingUser->setStatus('pending');
        $pendingUser->markAsExpired();
        $this->assertEquals('expired', $pendingUser->getStatus());
        $this->assertFalse($pendingUser->canBeProcessed());
        $this->assertTrue($pendingUser->isExpired());
    }

    public function testTokenExpiration(): void
    {
        $admin = new StaffUser();
        $admin->setEmail('admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SYSTEM_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setDepartment('IT');

        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRole(UserRole::CONSIGNEE)
            ->setCreatedByAdmin($admin);
        
        $pendingUser->generateAcceptanceToken();

        // Test future expiration
        $futureDate = (new \DateTime())->add(new \DateInterval('P7D'));
        $pendingUser->setTokenExpiresAt($futureDate);
        $this->assertTrue($pendingUser->isTokenValid());
        $this->assertFalse($pendingUser->isExpired());

        // Test past expiration
        $pastDate = (new \DateTime())->sub(new \DateInterval('P1D'));
        $pendingUser->setTokenExpiresAt($pastDate);
        $this->assertFalse($pendingUser->isTokenValid());
        $this->assertTrue($pendingUser->isExpired());
    }

    public function testShippingLineRelationships(): void
    {
        $admin = new StaffUser();
        $admin->setEmail('admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SYSTEM_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setDepartment('IT');

        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');

        $shippingLineAdmin = new StaffUser();
        $shippingLineAdmin->setEmail('sl_admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SHIPPING_LINES_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('SL Admin')
            ->setLastName('User')
            ->setDepartment('Management');

        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRole(UserRole::SL_STAFF)
            ->setCreatedByAdmin($admin)
            ->setShippingLine($shippingLine)
            ->setShippingLineAdmin($shippingLineAdmin);

        $this->assertEquals($shippingLine, $pendingUser->getShippingLine());
        $this->assertEquals($shippingLineAdmin, $pendingUser->getShippingLineAdmin());
    }

    public function testValidation(): void
    {
        $admin = new StaffUser();
        $admin->setEmail('admin@example.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::SYSTEM_ADMIN)
            ->setStatus(AccountStatus::APPROVED)
            ->setFirstName('Admin')
            ->setLastName('User')
            ->setDepartment('IT');

        $pendingUser = new PendingUser();
        $pendingUser->setEmail('test@example.com')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRole(UserRole::CONSIGNEE)
            ->setCreatedByAdmin($admin);
        
        $pendingUser->generateAcceptanceToken();
        $pendingUser->setTokenExpirationToSevenDays();

        // Valid pending user should pass validation
        $errors = $pendingUser->validate();
        $this->assertEmpty($errors);

        // Test invalid email
        $pendingUser->setEmail('invalid-email');
        $errors = $pendingUser->validate();
        $this->assertContains('Please enter a valid email address', $errors);
    }
}