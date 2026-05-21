<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\StaffUser;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Feature: optimus-shipping-portal, Property 9: Authentication lockout after failed attempts
 * 
 * For any user account, after three consecutive failed login attempts, 
 * the account should be locked and remain inaccessible until unlocked.
 * 
 * Validates: Requirements 9.5
 */
class UserServiceAuthenticationLockoutTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->userService = new UserService($this->entityManager, $this->passwordHasher);
        
        // Configure Eris
        $this->minimumEvaluationRatio = 0.5;
        $this->iterations = 100;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE audit_logs');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('TRUNCATE TABLE consignees');
        $connection->executeStatement('TRUNCATE TABLE brokers');
        $connection->executeStatement('TRUNCATE TABLE staff_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    /**
     * Property: For any user, after 3 failed login attempts, the account should be locked
     */
    public function testAuthenticationLockoutAfterThreeFailedAttempts(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'email' => strtolower(preg_replace('/[^a-z0-9]/', '', $parts[0])) . '@test.com',
                        'password' => $parts[1] . 'Pass123!', // Ensure minimum length
                        'wrongPassword' => $parts[2] . 'Wrong456!',
                        'userType' => $parts[3],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\string(),
                    Generator\string(),
                    Generator\elements(UserRole::CONSIGNEE, UserRole::BROKER, UserRole::SYSTEM_ADMIN)
                )
            )
        )->then(function ($userData) {
            // Ensure wrong password is different from correct password
            if ($userData['password'] === $userData['wrongPassword']) {
                $userData['wrongPassword'] .= '_different';
            }

            // Create user with valid password
            $user = $this->createUserForTest($userData['email'], $userData['password'], $userData['userType']);
            
            // Verify user is not locked initially
            $this->assertFalse($user->isLocked(), 'User should not be locked initially');
            $this->assertEquals(0, $user->getFailedLoginAttempts(), 'Failed login attempts should be 0 initially');

            // Attempt 1: Failed authentication
            $result1 = $this->userService->authenticate($userData['email'], $userData['wrongPassword']);
            $this->assertFalse($result1->isSuccess(), 'First failed authentication should return false');
            
            // Refresh user from database
            $this->entityManager->refresh($user);
            $this->assertEquals(1, $user->getFailedLoginAttempts(), 'Failed login attempts should be 1 after first failure');
            $this->assertFalse($user->isLocked(), 'User should not be locked after 1 failed attempt');

            // Attempt 2: Failed authentication
            $result2 = $this->userService->authenticate($userData['email'], $userData['wrongPassword']);
            $this->assertFalse($result2->isSuccess(), 'Second failed authentication should return false');
            
            $this->entityManager->refresh($user);
            $this->assertEquals(2, $user->getFailedLoginAttempts(), 'Failed login attempts should be 2 after second failure');
            $this->assertFalse($user->isLocked(), 'User should not be locked after 2 failed attempts');

            // Attempt 3: Failed authentication - should trigger lock
            $result3 = $this->userService->authenticate($userData['email'], $userData['wrongPassword']);
            $this->assertFalse($result3->isSuccess(), 'Third failed authentication should return false');
            
            $this->entityManager->refresh($user);
            $this->assertEquals(3, $user->getFailedLoginAttempts(), 'Failed login attempts should be 3 after third failure');
            $this->assertTrue($user->isLocked(), 'User should be locked after 3 failed attempts');
            $this->assertEquals(AccountStatus::LOCKED, $user->getStatus(), 'User status should be LOCKED');
            $this->assertNotNull($user->getLockedUntil(), 'LockedUntil should be set');

            // Attempt 4: Even with correct password, authentication should fail when locked
            $result4 = $this->userService->authenticate($userData['email'], $userData['password']);
            $this->assertFalse($result4->isSuccess(), 'Authentication should fail when account is locked, even with correct password');
            $this->assertStringContainsString('locked', strtolower($result4->getMessage()), 'Error message should mention account is locked');

            // Clean up this test user
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        });
    }

    /**
     * Property: Successful authentication should reset failed login attempts
     */
    public function testSuccessfulAuthenticationResetsFailedAttempts(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'email' => strtolower(preg_replace('/[^a-z0-9]/', '', $parts[0])) . '@test.com',
                        'password' => $parts[1] . 'Pass123!',
                        'wrongPassword' => $parts[2] . 'Wrong456!',
                        'userType' => $parts[3],
                        'failedAttempts' => $parts[4],
                    ];
                },
                Generator\tuple(
                    Generator\string(),
                    Generator\string(),
                    Generator\string(),
                    Generator\elements(UserRole::CONSIGNEE, UserRole::BROKER, UserRole::EVALUATOR),
                    Generator\choose(1, 2)
                )
            )
        )->then(function ($userData) {
            // Ensure wrong password is different
            if ($userData['password'] === $userData['wrongPassword']) {
                $userData['wrongPassword'] .= '_different';
            }

            // Create user
            $user = $this->createUserForTest($userData['email'], $userData['password'], $userData['userType']);

            // Make some failed attempts (but not enough to lock)
            for ($i = 0; $i < $userData['failedAttempts']; $i++) {
                $this->userService->authenticate($userData['email'], $userData['wrongPassword']);
            }

            $this->entityManager->refresh($user);
            $this->assertEquals($userData['failedAttempts'], $user->getFailedLoginAttempts(), 
                "Failed login attempts should be {$userData['failedAttempts']}");

            // Now authenticate successfully
            $result = $this->userService->authenticate($userData['email'], $userData['password']);
            $this->assertTrue($result->isSuccess(), 'Authentication with correct password should succeed');

            // Verify failed attempts are reset
            $this->entityManager->refresh($user);
            $this->assertEquals(0, $user->getFailedLoginAttempts(), 
                'Failed login attempts should be reset to 0 after successful authentication');

            // Clean up
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        });
    }

    /**
     * Helper method to create a user for testing
     */
    private function createUserForTest(string $email, string $password, UserRole $role): \App\Entity\User
    {
        $data = [
            'email' => $email,
            'password' => $password,
        ];

        // Add required fields based on user type
        if ($role === UserRole::CONSIGNEE || $role === UserRole::BROKER) {
            $data['businessName'] = 'Test Business ' . uniqid();
        } else {
            $data['firstName'] = 'Test';
            $data['lastName'] = 'User';
            $data['department'] = 'Testing';
        }

        return $this->userService->createUser($data, $role);
    }
}
