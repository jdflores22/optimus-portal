<?php

namespace App\Tests\Service;

use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Simple unit tests for UserService to verify basic functionality
 */
class UserServiceSimpleTest extends KernelTestCase
{
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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('TRUNCATE TABLE consignees');
        $connection->executeStatement('TRUNCATE TABLE brokers');
        $connection->executeStatement('TRUNCATE TABLE staff_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    public function testCreateUserHashesPassword(): void
    {
        $userData = [
            'email' => 'test@example.com',
            'password' => 'testpassword123',
            'businessName' => 'Test Business',
        ];

        $user = $this->userService->createUser($userData, UserRole::CONSIGNEE);

        $this->assertNotNull($user->getId());
        $this->assertEquals('test@example.com', $user->getEmail());
        $this->assertNotEquals('testpassword123', $user->getPasswordHash());
        $this->assertTrue($this->passwordHasher->isPasswordValid($user, 'testpassword123'));
    }

    public function testAuthenticationLockoutAfterThreeFailedAttempts(): void
    {
        // Create a user
        $userData = [
            'email' => 'lockout@example.com',
            'password' => 'correctpassword',
            'businessName' => 'Test Business',
        ];
        $user = $this->userService->createUser($userData, UserRole::CONSIGNEE);

        // Verify user is not locked initially
        $this->assertFalse($user->isLocked());
        $this->assertEquals(0, $user->getFailedLoginAttempts());

        // First failed attempt
        $result1 = $this->userService->authenticate('lockout@example.com', 'wrongpassword');
        $this->assertFalse($result1->isSuccess());
        $this->entityManager->refresh($user);
        $this->assertEquals(1, $user->getFailedLoginAttempts());
        $this->assertFalse($user->isLocked());

        // Second failed attempt
        $result2 = $this->userService->authenticate('lockout@example.com', 'wrongpassword');
        $this->assertFalse($result2->isSuccess());
        $this->entityManager->refresh($user);
        $this->assertEquals(2, $user->getFailedLoginAttempts());
        $this->assertFalse($user->isLocked());

        // Third failed attempt - should trigger lock
        $result3 = $this->userService->authenticate('lockout@example.com', 'wrongpassword');
        $this->assertFalse($result3->isSuccess());
        $this->entityManager->refresh($user);
        $this->assertEquals(3, $user->getFailedLoginAttempts());
        $this->assertTrue($user->isLocked());
        $this->assertEquals(AccountStatus::LOCKED, $user->getStatus());

        // Even with correct password, authentication should fail when locked
        $result4 = $this->userService->authenticate('lockout@example.com', 'correctpassword');
        $this->assertFalse($result4->isSuccess());
        $this->assertStringContainsString('locked', strtolower($result4->getMessage()));
    }

    public function testSuccessfulAuthenticationResetsFailedAttempts(): void
    {
        // Create a user
        $userData = [
            'email' => 'reset@example.com',
            'password' => 'correctpassword',
            'businessName' => 'Test Business',
        ];
        $user = $this->userService->createUser($userData, UserRole::BROKER);

        // Make 2 failed attempts
        $this->userService->authenticate('reset@example.com', 'wrongpassword');
        $this->userService->authenticate('reset@example.com', 'wrongpassword');
        
        $this->entityManager->refresh($user);
        $this->assertEquals(2, $user->getFailedLoginAttempts());

        // Successful authentication
        $result = $this->userService->authenticate('reset@example.com', 'correctpassword');
        $this->assertTrue($result->isSuccess());

        // Verify failed attempts are reset
        $this->entityManager->refresh($user);
        $this->assertEquals(0, $user->getFailedLoginAttempts());
    }
}
