<?php

namespace App\Tests\Property;

use App\Entity\PendingUser;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Feature: shipping-line-user-email-notifications, Property 5: Pending User Data Persistence
 * 
 * Property-based test for validating pending user data persistence in the email notification system.
 * This test validates Requirements 2.4 by ensuring that all pending user data is correctly stored
 * with associated token information in the pending users table.
 */
class PendingUserDataPersistencePropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 5: Pending User Data Persistence
     * 
     * For any pending user creation, the system should store the token with all associated 
     * user data in the pending users table.
     * 
     * **Validates: Requirements 2.4**
     */
    public function testPendingUserDataPersistence(): void
    {
        $this->forAll(
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\elements(
                UserRole::CONSIGNEE,
                UserRole::BROKER,
                UserRole::EVALUATOR,
                UserRole::SL_STAFF,
                UserRole::ACCOUNTING,
                UserRole::TRUCKER,
                UserRole::TERMINAL_TEAM
            ),
            Generator\choose(1, 30) // Days until expiration
        )->then(function (
            string $emailPrefix,
            string $firstName,
            string $lastName,
            UserRole $role,
            int $daysUntilExpiration
        ) {
            // Skip if generated data is invalid
            if (empty(trim($firstName)) || empty(trim($lastName)) || empty(trim($emailPrefix))) {
                return;
            }
            
            // Create a valid email by cleaning the prefix
            $cleanEmailPrefix = preg_replace('/[^a-zA-Z0-9]/', '', trim($emailPrefix));
            if (empty($cleanEmailPrefix)) {
                $cleanEmailPrefix = 'user';
            }
            // Limit email prefix to ensure total email length is under 180 chars
            $maxPrefixLength = 180 - strlen('@example.com'); // 168 chars max
            if (strlen($cleanEmailPrefix) > $maxPrefixLength) {
                $cleanEmailPrefix = substr($cleanEmailPrefix, 0, $maxPrefixLength);
            }
            $email = $cleanEmailPrefix . '@example.com';
            
            // Skip if email is too long
            if (strlen($email) > 180) {
                return;
            }

            // Create admin user
            $admin = new StaffUser();
            $admin->setEmail('admin@example.com')
                ->setPasswordHash('hashed_password')
                ->setRole(UserRole::SYSTEM_ADMIN)
                ->setStatus(AccountStatus::APPROVED)
                ->setFirstName('Admin')
                ->setLastName('User')
                ->setDepartment('IT');

            // Create shipping line (optional)
            $shippingLine = new ShippingLine();
            $shippingLine->setBrandName('Test Shipping Line');

            // Create shipping line admin (optional)
            $shippingLineAdmin = new StaffUser();
            $shippingLineAdmin->setEmail('sl_admin@example.com')
                ->setPasswordHash('hashed_password')
                ->setRole(UserRole::SHIPPING_LINES_ADMIN)
                ->setStatus(AccountStatus::APPROVED)
                ->setFirstName('SL Admin')
                ->setLastName('User')
                ->setDepartment('Management');

            // Create pending user
            $pendingUser = new PendingUser();
            $pendingUser->setEmail($email)
                ->setFirstName(trim($firstName))
                ->setLastName(trim($lastName))
                ->setRole($role)
                ->setCreatedByAdmin($admin);

            // Generate secure token
            $pendingUser->generateAcceptanceToken();
            
            // Set expiration date
            $expirationDate = (new \DateTime())->add(new \DateInterval("P{$daysUntilExpiration}D"));
            $pendingUser->setTokenExpiresAt($expirationDate);

            // Optionally set shipping line relationships
            if (in_array($role, [UserRole::SL_STAFF, UserRole::ACCOUNTING])) {
                $pendingUser->setShippingLine($shippingLine)
                    ->setShippingLineAdmin($shippingLineAdmin);
            }

            // Test that all data is properly stored and accessible
            $this->assertEquals($email, $pendingUser->getEmail());
            $this->assertEquals(trim($firstName), $pendingUser->getFirstName());
            $this->assertEquals(trim($lastName), $pendingUser->getLastName());
            $this->assertEquals($role, $pendingUser->getRole());
            $this->assertEquals($admin, $pendingUser->getCreatedByAdmin());
            $this->assertEquals($expirationDate, $pendingUser->getTokenExpiresAt());
            
            // Test token properties
            $token = $pendingUser->getAcceptanceToken();
            $this->assertNotEmpty($token);
            $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex characters
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token); // Valid hex string
            
            // Test default status
            $this->assertEquals('pending', $pendingUser->getStatus());
            
            // Test created timestamp
            $this->assertInstanceOf(\DateTimeInterface::class, $pendingUser->getCreatedAt());
            $this->assertLessThanOrEqual(new \DateTime(), $pendingUser->getCreatedAt());
            
            // Test full name generation
            $expectedFullName = trim($firstName) . ' ' . trim($lastName);
            $this->assertEquals($expectedFullName, $pendingUser->getFullName());
            
            // Test token validity logic
            if ($daysUntilExpiration > 0) {
                $this->assertTrue($pendingUser->isTokenValid());
                $this->assertFalse($pendingUser->isExpired());
                $this->assertTrue($pendingUser->canBeProcessed());
            }
            
            // Test shipping line relationships (if applicable)
            if (in_array($role, [UserRole::SL_STAFF, UserRole::ACCOUNTING])) {
                $this->assertEquals($shippingLine, $pendingUser->getShippingLine());
                $this->assertEquals($shippingLineAdmin, $pendingUser->getShippingLineAdmin());
            } else {
                $this->assertNull($pendingUser->getShippingLine());
                $this->assertNull($pendingUser->getShippingLineAdmin());
            }
            
            // Test validation passes for valid data
            $validationErrors = $pendingUser->validate();
            if (!empty($validationErrors)) {
                // Debug: print validation errors to understand what's failing
                error_log('Validation errors: ' . implode(', ', $validationErrors));
                error_log('Email: ' . $email);
                error_log('FirstName: ' . trim($firstName));
                error_log('LastName: ' . trim($lastName));
            }
            $this->assertEmpty($validationErrors, 'Validation should pass for valid pending user data. Errors: ' . implode(', ', $validationErrors));
            
            // Test string representation
            $expectedString = $expectedFullName . ' (' . $email . ')';
            $this->assertEquals($expectedString, (string) $pendingUser);
        });
    }

    /**
     * Property test for token uniqueness across multiple pending users
     * 
     * For any set of pending users, all generated tokens should be unique.
     */
    public function testTokenUniquenessAcrossMultiplePendingUsers(): void
    {
        $this->forAll(
            Generator\choose(2, 10) // Number of pending users to create
        )->then(function (int $userCount) {
            $tokens = [];
            $pendingUsers = [];
            
            // Create admin user
            $admin = new StaffUser();
            $admin->setEmail('admin@example.com')
                ->setPasswordHash('hashed_password')
                ->setRole(UserRole::SYSTEM_ADMIN)
                ->setStatus(AccountStatus::APPROVED)
                ->setFirstName('Admin')
                ->setLastName('User')
                ->setDepartment('IT');
            
            // Create multiple pending users
            for ($i = 0; $i < $userCount; $i++) {
                $pendingUser = new PendingUser();
                $pendingUser->setEmail("user{$i}@example.com")
                    ->setFirstName("First{$i}")
                    ->setLastName("Last{$i}")
                    ->setRole(UserRole::CONSIGNEE)
                    ->setCreatedByAdmin($admin);
                
                $pendingUser->generateAcceptanceToken();
                $pendingUser->setTokenExpirationToSevenDays();
                
                $token = $pendingUser->getAcceptanceToken();
                $tokens[] = $token;
                $pendingUsers[] = $pendingUser;
                
                // Each token should be valid
                $this->assertEquals(64, strlen($token));
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
            }
            
            // All tokens should be unique
            $uniqueTokens = array_unique($tokens);
            $this->assertCount($userCount, $uniqueTokens, 'All generated tokens should be unique');
            $this->assertEquals(count($tokens), count($uniqueTokens), 'No duplicate tokens should exist');
        });
    }

    /**
     * Property test for status transitions
     * 
     * For any pending user, status transitions should follow valid state changes.
     */
    public function testStatusTransitions(): void
    {
        $this->forAll(
            Generator\elements('accepted', 'declined', 'expired')
        )->then(function (string $newStatus) {
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
            
            $pendingUser->generateAcceptanceToken();
            $pendingUser->setTokenExpirationToSevenDays();
            
            // Initial status should be pending
            $this->assertEquals('pending', $pendingUser->getStatus());
            $this->assertTrue($pendingUser->canBeProcessed());
            
            // Test status transitions
            switch ($newStatus) {
                case 'accepted':
                    $pendingUser->markAsAccepted();
                    $this->assertEquals('accepted', $pendingUser->getStatus());
                    $this->assertFalse($pendingUser->canBeProcessed());
                    break;
                    
                case 'declined':
                    $pendingUser->markAsDeclined();
                    $this->assertEquals('declined', $pendingUser->getStatus());
                    $this->assertFalse($pendingUser->canBeProcessed());
                    break;
                    
                case 'expired':
                    $pendingUser->markAsExpired();
                    $this->assertEquals('expired', $pendingUser->getStatus());
                    $this->assertFalse($pendingUser->canBeProcessed());
                    $this->assertTrue($pendingUser->isExpired());
                    break;
            }
        });
    }

    /**
     * Property test for token expiration logic
     * 
     * For any pending user with different expiration dates, the expiration logic should work correctly.
     */
    public function testTokenExpirationLogic(): void
    {
        $this->forAll(
            Generator\choose(-10, 10) // Days relative to now (negative = past, positive = future)
        )->then(function (int $daysFromNow) {
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
            
            $pendingUser->generateAcceptanceToken();
            
            // Set expiration date
            if ($daysFromNow < 0) {
                $expirationDate = (new \DateTime())->sub(new \DateInterval("P" . abs($daysFromNow) . "D"));
            } else {
                $expirationDate = (new \DateTime())->add(new \DateInterval("P{$daysFromNow}D"));
            }
            $pendingUser->setTokenExpiresAt($expirationDate);
            
            // Test expiration logic
            if ($daysFromNow <= 0) {
                // Token should be expired
                $this->assertFalse($pendingUser->isTokenValid());
                $this->assertTrue($pendingUser->isExpired());
                $this->assertFalse($pendingUser->canBeProcessed());
            } else {
                // Token should be valid
                $this->assertTrue($pendingUser->isTokenValid());
                $this->assertFalse($pendingUser->isExpired());
                $this->assertTrue($pendingUser->canBeProcessed());
            }
        });
    }
}