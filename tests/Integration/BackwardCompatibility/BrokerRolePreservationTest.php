<?php

namespace App\Tests\Integration\BackwardCompatibility;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Service\UserService;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Integration tests to ensure BROKER role functionality remains unchanged
 * after implementing dynamic shipping line management features.
 * 
 * Validates Requirements: 6.2, 6.3, 6.4, 6.5, 6.6
 */
class BrokerRolePreservationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private EmailVerificationService $emailVerificationService;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userService = $container->get(UserService::class);
        $this->emailVerificationService = $container->get(EmailVerificationService::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('TRUNCATE TABLE consignees');
        $connection->executeStatement('TRUNCATE TABLE brokers');
        $connection->executeStatement('TRUNCATE TABLE staff_users');
        $connection->executeStatement('TRUNCATE TABLE shipping_lines');
        $connection->executeStatement('TRUNCATE TABLE activity_logs');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Test that BROKER users can be created exactly as before
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerCreationRemainsUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Test Broker Full Name'
        ];

        // Act
        $broker = $this->userService->createUser($userData, UserRole::BROKER);

        // Assert
        $this->assertInstanceOf(Broker::class, $broker);
        $this->assertEquals(UserRole::BROKER, $broker->getRole());
        $this->assertEquals($userData['email'], $broker->getEmail());
        $this->assertEquals($userData['fullName'], $broker->getFullName());
        $this->assertTrue($this->passwordHasher->isPasswordValid($broker, $userData['password']));
        $this->assertNotNull($broker->getId());
        
        // Verify BROKER doesn't require shipping line admin (independent role)
        $this->assertFalse($broker->requiresShippingLineAdmin());
        $this->assertNull($broker->getShippingLineAdmin());
        $this->assertNull($broker->getManagedShippingLine());
        
        // Verify hierarchy level is 3 (independent role)
        $this->assertEquals(3, $broker->getHierarchyLevel());
        
        // Verify linked consignees collection is initialized
        $this->assertNotNull($broker->getLinkedConsignees());
        $this->assertCount(0, $broker->getLinkedConsignees());
    }

    /**
     * Test that BROKER-CONSIGNEE linking mechanism remains unchanged
     * Validates: Requirements 6.3, 6.6
     */
    public function testBrokerConsigneeLinkingPreserved(): void
    {
        // Arrange - Create broker
        $brokerData = [
            'email' => 'broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Test Broker'
        ];
        $broker = $this->userService->createUser($brokerData, UserRole::BROKER);

        // Create multiple consignees
        $consignees = [];
        for ($i = 1; $i <= 3; $i++) {
            $consigneeData = [
                'email' => "consignee{$i}@test.com",
                'password' => 'TestPassword123!',
                'businessName' => "Test Consignee Business {$i}"
            ];
            $consignees[] = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);
        }

        // Act - Link consignees to broker (existing functionality)
        foreach ($consignees as $consignee) {
            $broker->addLinkedConsignee($consignee);
        }
        $this->entityManager->flush();

        // Assert - Verify linking works as before
        $this->assertCount(3, $broker->getLinkedConsignees());
        
        foreach ($consignees as $consignee) {
            $this->assertTrue($broker->getLinkedConsignees()->contains($consignee));
            $this->assertSame($broker, $consignee->getLinkedBroker());
        }

        // Test removing a consignee
        $broker->removeLinkedConsignee($consignees[0]);
        $this->entityManager->flush();
        
        $this->assertCount(2, $broker->getLinkedConsignees());
        $this->assertFalse($broker->getLinkedConsignees()->contains($consignees[0]));
        $this->assertNull($consignees[0]->getLinkedBroker());

        // Verify the relationship persists in database
        $this->entityManager->clear();
        $persistedBroker = $this->entityManager->getRepository(Broker::class)->find($broker->getId());
        $this->assertCount(2, $persistedBroker->getLinkedConsignees());
    }

    /**
     * Test that BROKER authentication and authorization remain unchanged
     * Validates: Requirements 6.2, 6.4, 6.5
     */
    public function testBrokerAuthenticationUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'auth-broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Auth Test Broker'
        ];
        $broker = $this->userService->createUser($userData, UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();

        // Act - Test authentication
        $authResult = $this->userService->authenticate($userData['email'], $userData['password']);

        // Assert
        $this->assertTrue($authResult->isSuccess());
        $this->assertSame($broker->getId(), $authResult->getUser()->getId());
        $this->assertEquals(UserRole::BROKER, $authResult->getUser()->getRole());
        
        // Verify Symfony security roles remain unchanged
        $this->assertEquals(['ROLE_BROKER'], $broker->getRoles());
    }

    /**
     * Test that BROKER email verification workflow remains unchanged
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerEmailVerificationUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'verify-broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Verify Test Broker'
        ];
        $broker = $this->userService->createUser($userData, UserRole::BROKER);

        // Act - Send verification email (existing functionality)
        $this->emailVerificationService->sendVerificationEmail($broker);

        // Assert - Verify token is generated
        $this->assertNotNull($broker->getEmailVerificationToken());
        $this->assertNotNull($broker->getEmailVerificationTokenExpiresAt());
        $this->assertFalse($broker->isEmailVerified());

        // Test token validation (existing functionality)
        $this->assertTrue($broker->isEmailVerificationTokenValid());

        // Test email verification (existing functionality)
        $this->emailVerificationService->verifyEmail($broker->getEmailVerificationToken());
        
        $this->entityManager->refresh($broker);
        $this->assertTrue($broker->isEmailVerified());
        $this->assertNotNull($broker->getEmailVerifiedAt());
    }

    /**
     * Test that BROKER account status management remains unchanged
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerAccountStatusManagementUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'status-broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Status Test Broker'
        ];
        $broker = $this->userService->createUser($userData, UserRole::BROKER);

        // Act & Assert - Test all status transitions
        $broker->setStatus(AccountStatus::PENDING);
        $this->assertEquals(AccountStatus::PENDING, $broker->getStatus());

        $broker->setStatus(AccountStatus::APPROVED);
        $this->assertEquals(AccountStatus::APPROVED, $broker->getStatus());
        $this->assertTrue($broker->isActive());

        $broker->setStatus(AccountStatus::SUSPENDED);
        $this->assertEquals(AccountStatus::SUSPENDED, $broker->getStatus());
        $this->assertFalse($broker->isActive());

        // Test account locking functionality
        $this->userService->lockAccount($broker->getId(), 30);
        $this->entityManager->refresh($broker);
        
        $this->assertTrue($broker->isLocked());
        $this->assertEquals(AccountStatus::LOCKED, $broker->getStatus());

        // Test account unlocking
        $this->userService->unlockAccount($broker->getId());
        $this->entityManager->refresh($broker);
        
        $this->assertFalse($broker->isLocked());
        $this->assertNull($broker->getLockedUntil());
    }

    /**
     * Test that BROKER database relationships remain unchanged
     * Validates: Requirements 6.6
     */
    public function testBrokerDatabaseRelationshipsPreserved(): void
    {
        // Arrange
        $brokerData = [
            'email' => 'db-broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'DB Test Broker'
        ];
        $broker = $this->userService->createUser($brokerData, UserRole::BROKER);

        $consigneeData = [
            'email' => 'db-consignee@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'DB Test Consignee'
        ];
        $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);

        // Act - Establish relationship
        $broker->addLinkedConsignee($consignee);
        $this->entityManager->flush();

        // Assert - Verify database constraints and foreign keys work
        $connection = $this->entityManager->getConnection();
        
        // Check brokers table structure remains unchanged
        $brokerTableInfo = $connection->fetchAllAssociative("DESCRIBE brokers");
        $columnNames = array_column($brokerTableInfo, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('full_name', $columnNames);

        // Verify one-to-many relationship with consignees works
        $this->assertCount(1, $broker->getLinkedConsignees());
        $this->assertSame($broker, $consignee->getLinkedBroker());

        // Test cascade behavior - removing consignee should not affect broker
        $consigneeId = $consignee->getId();
        $this->entityManager->remove($consignee);
        $this->entityManager->flush();
        $this->entityManager->refresh($broker);

        $this->assertCount(0, $broker->getLinkedConsignees());
        
        // Verify consignee is actually deleted
        $deletedConsignee = $this->entityManager->getRepository(Consignee::class)->find($consigneeId);
        $this->assertNull($deletedConsignee);
    }

    /**
     * Test that BROKER role doesn't interfere with new shipping line features
     * Validates: Requirements 6.4
     */
    public function testBrokerRoleDoesNotInterfereWithShippingLineFeatures(): void
    {
        // Arrange
        $brokerData = [
            'email' => 'interference-broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Interference Test Broker'
        ];
        $broker = $this->userService->createUser($brokerData, UserRole::BROKER);

        // Act & Assert - Verify BROKER is not affected by shipping line hierarchy
        $this->assertNull($broker->getShippingLineAdmin());
        $this->assertNull($broker->getManagedShippingLine());
        $this->assertNull($broker->getShippingLineScope());
        
        // Verify BROKER doesn't require shipping line relationships
        $this->assertFalse($broker->requiresShippingLineAdmin());
        $this->assertFalse($broker->requiresManagedShippingLine());
        
        // Verify hierarchy validation passes for independent role
        $validationErrors = $broker->validateHierarchy();
        $this->assertEmpty($validationErrors);
        
        // Verify BROKER can't manage other users (not part of hierarchy)
        $this->assertFalse($broker->canManageUser($broker));
        
        // Verify scoped users returns empty (no subordinates)
        $this->assertEmpty($broker->getScopedUsers());
    }

    /**
     * Test that BROKER consignee management functionality remains unchanged
     * Validates: Requirements 6.2, 6.3, 6.5
     */
    public function testBrokerConsigneeManagementUnchanged(): void
    {
        // Arrange
        $brokerData = [
            'email' => 'mgmt-broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Management Test Broker'
        ];
        $broker = $this->userService->createUser($brokerData, UserRole::BROKER);

        // Create consignees
        $consignee1Data = [
            'email' => 'consignee1@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Test Consignee 1'
        ];
        $consignee1 = $this->userService->createUser($consignee1Data, UserRole::CONSIGNEE);

        $consignee2Data = [
            'email' => 'consignee2@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Test Consignee 2'
        ];
        $consignee2 = $this->userService->createUser($consignee2Data, UserRole::CONSIGNEE);

        // Act & Assert - Test adding consignees
        $broker->addLinkedConsignee($consignee1);
        $this->assertCount(1, $broker->getLinkedConsignees());
        $this->assertSame($broker, $consignee1->getLinkedBroker());

        $broker->addLinkedConsignee($consignee2);
        $this->assertCount(2, $broker->getLinkedConsignees());
        $this->assertSame($broker, $consignee2->getLinkedBroker());

        // Test adding same consignee twice (should not duplicate)
        $broker->addLinkedConsignee($consignee1);
        $this->assertCount(2, $broker->getLinkedConsignees());

        // Test removing consignee
        $broker->removeLinkedConsignee($consignee1);
        $this->assertCount(1, $broker->getLinkedConsignees());
        $this->assertNull($consignee1->getLinkedBroker());
        $this->assertFalse($broker->getLinkedConsignees()->contains($consignee1));

        // Verify remaining consignee is still linked
        $this->assertTrue($broker->getLinkedConsignees()->contains($consignee2));
        $this->assertSame($broker, $consignee2->getLinkedBroker());
    }

    /**
     * Test that multiple BROKER users can exist independently
     * Validates: Requirements 6.2, 6.5
     */
    public function testMultipleBrokersIndependentOperation(): void
    {
        // Arrange & Act - Create multiple brokers
        $brokers = [];
        for ($i = 1; $i <= 3; $i++) {
            $userData = [
                'email' => "broker{$i}@test.com",
                'password' => 'TestPassword123!',
                'fullName' => "Test Broker {$i}"
            ];
            $brokers[] = $this->userService->createUser($userData, UserRole::BROKER);
        }

        // Create consignees for each broker
        foreach ($brokers as $index => $broker) {
            $consigneeData = [
                'email' => "consignee-for-broker{$index}@test.com",
                'password' => 'TestPassword123!',
                'businessName' => "Consignee for Broker {$index}"
            ];
            $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);
            $broker->addLinkedConsignee($consignee);
        }
        $this->entityManager->flush();

        // Assert - Verify all brokers are independent
        foreach ($brokers as $broker) {
            $this->assertEquals(UserRole::BROKER, $broker->getRole());
            $this->assertNull($broker->getShippingLineAdmin());
            $this->assertNull($broker->getManagedShippingLine());
            $this->assertEquals(3, $broker->getHierarchyLevel());
            $this->assertCount(1, $broker->getLinkedConsignees());
        }

        // Verify they don't interfere with each other
        $this->assertCount(3, $brokers);
        $emails = array_map(fn($b) => $b->getEmail(), $brokers);
        $this->assertCount(3, array_unique($emails));

        // Verify each broker has their own consignees
        $allConsignees = [];
        foreach ($brokers as $broker) {
            foreach ($broker->getLinkedConsignees() as $consignee) {
                $allConsignees[] = $consignee->getId();
            }
        }
        $this->assertCount(3, array_unique($allConsignees));
    }

    /**
     * Test that BROKER failed login attempts and lockout mechanism remains unchanged
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerFailedLoginLockoutUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'lockout-broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Lockout Test Broker'
        ];
        $broker = $this->userService->createUser($userData, UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();

        // Act & Assert - Test failed login attempts
        $this->assertEquals(0, $broker->getFailedLoginAttempts());

        // First failed attempt
        $result1 = $this->userService->authenticate($userData['email'], 'wrong-password');
        $this->assertFalse($result1->isSuccess());
        $this->entityManager->refresh($broker);
        $this->assertEquals(1, $broker->getFailedLoginAttempts());

        // Second failed attempt
        $result2 = $this->userService->authenticate($userData['email'], 'wrong-password');
        $this->assertFalse($result2->isSuccess());
        $this->entityManager->refresh($broker);
        $this->assertEquals(2, $broker->getFailedLoginAttempts());

        // Third failed attempt should lock account
        $result3 = $this->userService->authenticate($userData['email'], 'wrong-password');
        $this->assertFalse($result3->isSuccess());
        $this->entityManager->refresh($broker);
        $this->assertEquals(3, $broker->getFailedLoginAttempts());
        $this->assertTrue($broker->isLocked());

        // Successful authentication should be blocked while locked
        $result4 = $this->userService->authenticate($userData['email'], $userData['password']);
        $this->assertFalse($result4->isSuccess());
        $this->assertEquals('Account is locked', $result4->getMessage());

        // Unlock and verify successful authentication
        $this->userService->unlockAccount($broker->getId());
        $this->entityManager->refresh($broker);
        
        $result5 = $this->userService->authenticate($userData['email'], $userData['password']);
        $this->assertTrue($result5->isSuccess());
        $this->assertEquals(0, $broker->getFailedLoginAttempts());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}