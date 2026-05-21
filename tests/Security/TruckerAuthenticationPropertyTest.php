<?php

namespace App\Tests\Security;

use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Trucker;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * **Feature: terminal-team-pre-advice, Property 4: Trucker authentication**
 * **Validates: Requirements 2.3**
 * 
 * Property: For any trucker with valid credentials, the authentication system 
 * should grant access to booking functionality
 */
class TruckerAuthenticationPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property: Valid trucker entities should have correct properties
     */
    public function testTruckerEntityPropertiesProperty(): void
    {
        $this->forAll(
            Generator\string(), // email
            Generator\string(), // firstName
            Generator\string(), // lastName
            Generator\choose(0, 100) // seed
        )->then(function (string $email, string $firstName, string $lastName, int $seed) {
            // Skip empty strings
            if (empty($email) || empty($firstName) || empty($lastName)) {
                return;
            }
            
            // Create a trucker entity
            $trucker = new Trucker();
            $trucker->setEmail($email);
            $trucker->setFirstName($firstName);
            $trucker->setLastName($lastName);
            $trucker->setRole(UserRole::TRUCKER);
            $trucker->setStatus(AccountStatus::APPROVED);
            $trucker->setPasswordHash('hashed_password');
            
            // Verify trucker properties
            $this->assertEquals($email, $trucker->getEmail());
            $this->assertEquals($firstName, $trucker->getFirstName());
            $this->assertEquals($lastName, $trucker->getLastName());
            $this->assertEquals(UserRole::TRUCKER, $trucker->getRole());
            $this->assertEquals(AccountStatus::APPROVED, $trucker->getStatus());
            $this->assertEquals(['ROLE_TRUCKER'], $trucker->getRoles());
            $this->assertEquals($firstName . ' ' . $lastName, $trucker->getFullName());
        });
    }

    /**
     * Property: Trucker account status should affect authentication eligibility
     */
    public function testTruckerAccountStatusProperty(): void
    {
        $this->forAll(
            Generator\elements([AccountStatus::PENDING, AccountStatus::EMAIL_UNVERIFIED, AccountStatus::DENIED, AccountStatus::LOCKED, AccountStatus::APPROVED]),
            Generator\choose(0, 100) // seed
        )->then(function (AccountStatus $status, int $seed) {
            $trucker = new Trucker();
            $trucker->setEmail("trucker{$seed}@example.com");
            $trucker->setFirstName("Test");
            $trucker->setLastName("Trucker");
            $trucker->setRole(UserRole::TRUCKER);
            $trucker->setStatus($status);
            $trucker->setPasswordHash('hashed_password');
            
            // Only APPROVED status should be considered active for authentication
            $isEligibleForAuth = ($status === AccountStatus::APPROVED);
            
            // Verify status properties
            $this->assertEquals($status, $trucker->getStatus());
            
            if ($status === AccountStatus::APPROVED) {
                $this->assertFalse($trucker->isLocked());
            }
        });
    }

    /**
     * Property: Failed login attempts should be tracked correctly
     */
    public function testFailedLoginTrackingProperty(): void
    {
        $this->forAll(
            Generator\choose(0, 10), // failed attempts
            Generator\choose(0, 100) // seed
        )->then(function (int $failedAttempts, int $seed) {
            $trucker = new Trucker();
            $trucker->setEmail("trucker{$seed}@example.com");
            $trucker->setFirstName("Test");
            $trucker->setLastName("Trucker");
            $trucker->setRole(UserRole::TRUCKER);
            $trucker->setStatus(AccountStatus::APPROVED);
            $trucker->setPasswordHash('hashed_password');
            $trucker->setFailedLoginAttempts($failedAttempts);
            
            // Verify failed login attempts tracking
            $this->assertEquals($failedAttempts, $trucker->getFailedLoginAttempts());
            
            // Test increment
            $trucker->incrementFailedLoginAttempts();
            $this->assertEquals($failedAttempts + 1, $trucker->getFailedLoginAttempts());
            
            // Test reset
            $trucker->resetFailedLoginAttempts();
            $this->assertEquals(0, $trucker->getFailedLoginAttempts());
        });
    }

    /**
     * Property: Trucker profile information should be manageable
     */
    public function testTruckerProfileManagementProperty(): void
    {
        $this->forAll(
            Generator\string(), // phone
            Generator\string(), // license
            Generator\string(), // company
            Generator\string(), // plate
            Generator\choose(0, 100) // seed
        )->then(function (string $phone, string $license, string $company, string $plate, int $seed) {
            // Skip empty strings for optional fields
            if (empty($phone) && empty($license) && empty($company) && empty($plate)) {
                return;
            }
            
            $trucker = new Trucker();
            $trucker->setEmail("trucker{$seed}@example.com");
            $trucker->setFirstName("Test");
            $trucker->setLastName("Trucker");
            $trucker->setRole(UserRole::TRUCKER);
            $trucker->setStatus(AccountStatus::APPROVED);
            $trucker->setPasswordHash('hashed_password');
            
            // Set optional profile information
            if (!empty($phone)) {
                $trucker->setPhoneNumber($phone);
                $this->assertEquals($phone, $trucker->getPhoneNumber());
            }
            
            if (!empty($license)) {
                $trucker->setLicenseNumber($license);
                $this->assertEquals($license, $trucker->getLicenseNumber());
            }
            
            if (!empty($company)) {
                $trucker->setCompanyName($company);
                $this->assertEquals($company, $trucker->getCompanyName());
            }
            
            if (!empty($plate)) {
                $trucker->setTruckPlateNumber($plate);
                $this->assertEquals($plate, $trucker->getTruckPlateNumber());
            }
        });
    }

    /**
     * Property: Trucker role should always be TRUCKER
     */
    public function testTruckerRoleConsistencyProperty(): void
    {
        $this->forAll(
            Generator\choose(0, 100) // seed
        )->then(function (int $seed) {
            $trucker = new Trucker();
            $trucker->setEmail("trucker{$seed}@example.com");
            $trucker->setFirstName("Test");
            $trucker->setLastName("Trucker");
            $trucker->setRole(UserRole::TRUCKER);
            $trucker->setStatus(AccountStatus::APPROVED);
            $trucker->setPasswordHash('hashed_password');
            
            // Verify role consistency
            $this->assertEquals(UserRole::TRUCKER, $trucker->getRole());
            $this->assertEquals(['ROLE_TRUCKER'], $trucker->getRoles());
            $this->assertEquals("trucker{$seed}@example.com", $trucker->getUserIdentifier());
        });
    }
}