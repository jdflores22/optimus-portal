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
 * Integration tests to ensure CONSIGNEE role functionality remains unchanged
 * after implementing dynamic shipping line management features.
 * 
 * Validates Requirements: 6.1, 6.3, 6.4, 6.5, 6.6
 */
class ConsigneeRolePreservationTest extends KernelTestCase
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
     * Test that CONSIGNEE users can be created exactly as before
     * Validates: Requirements 6.1, 6.5
     */
    public function testConsigneeCreationRemainsUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'consignee@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Test Consignee Business'
        ];

        // Act
        $consignee = $this->userService->createUser($userData, UserRole::CONSIGNEE);

        // Assert
        $this->assertInstanceOf(Consignee::class, $consignee);
        $this->assertEquals(UserRole::CONSIGNEE, $consignee->getRole());
        $this->assertEquals($userData['email'], $consignee->getEmail());
        $this->assertEquals($userData['businessName'], $consignee->getBusinessName());
        $this->assertTrue($this->passwordHasher->isPasswordValid($consignee, $userData['password']));
        $this->assertNotNull($consignee->getId());
        
        // Verify CONSIGNEE doesn't require shipping line admin (independent role)
        $this->assertFalse($consignee->requiresShippingLineAdmin());
        $this->assertNull($consignee->getShippingLineAdmin());
        $this->assertNull($consignee->getManagedShippingLine());
        
        // Verify hierarchy level is 3 (independent role)
        $this->assertEquals(3, $consignee->getHierarchyLevel());
    }

    /**
     * Test that CONSIGNEE-BROKER linking mechanism remains unchanged
     * Validates: Requirements 6.3, 6.6
     */
    public function testConsigneeBrokerLinkingPreserved(): void
    {
        // Arrange - Create broker first
        $brokerData = [
            'email' => 'broker@test.com',
            'password' => 'TestPassword123!',
            'fullName' => 'Test Broker'
        ];
        $broker = $this->userService->createUser($brokerData, UserRole::BROKER);

        // Create consignee
        $consigneeData = [
            'email' => 'consignee@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Test Consignee Business'
        ];
        $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);

        // Act - Link consignee to broker (existing functionality)
        $consignee->setLinkedBroker($broker);
        $broker->addLinkedConsignee($consignee);
        $this->entityManager->flush();

        // Assert - Verify linking works as before
        $this->assertSame($broker, $consignee->getLinkedBroker());
        $this->assertTrue($broker->getLinkedConsignees()->contains($consignee));
        $this->assertCount(1, $broker->getLinkedConsignees());

        // Verify the relationship persists in database
        $this->entityManager->clear();
        $persistedConsignee = $this->entityManager->getRepository(Consignee::class)->find($consignee->getId());
        $persistedBroker = $this->entityManager->getRepository(Broker::class)->find($broker->getId());

        $this->assertNotNull($persistedConsignee->getLinkedBroker());
        $this->assertEquals($broker->getId(), $persistedConsignee->getLinkedBroker()->getId());
        $this->assertCount(1, $persistedBroker->getLinkedConsignees());
    }

    /**
     * Test that CONSIGNEE authentication and authorization remain unchanged
     * Validates: Requirements 6.1, 6.4, 6.5
     */
    public function testConsigneeAuthenticationUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'auth-consignee@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Auth Test Consignee'
        ];
        $consignee = $this->userService->createUser($userData, UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();

        // Act - Test authentication
        $authResult = $this->userService->authenticate($userData['email'], $userData['password']);

        // Assert
        $this->assertTrue($authResult->isSuccess());
        $this->assertSame($consignee->getId(), $authResult->getUser()->getId());
        $this->assertEquals(UserRole::CONSIGNEE, $authResult->getUser()->getRole());
        
        // Verify Symfony security roles remain unchanged
        $this->assertEquals(['ROLE_CONSIGNEE'], $consignee->getRoles());
    }

    /**
     * Test that CONSIGNEE email verification workflow remains unchanged
     * Validates: Requirements 6.1, 6.5
     */
    public function testConsigneeEmailVerificationUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'verify-consignee@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Verify Test Consignee'
        ];
        $consignee = $this->userService->createUser($userData, UserRole::CONSIGNEE);

        // Act - Send verification email (existing functionality)
        $this->emailVerificationService->sendVerificationEmail($consignee);

        // Assert - Verify token is generated
        $this->assertNotNull($consignee->getEmailVerificationToken());
        $this->assertNotNull($consignee->getEmailVerificationTokenExpiresAt());
        $this->assertFalse($consignee->isEmailVerified());

        // Test token validation (existing functionality)
        $this->assertTrue($consignee->isEmailVerificationTokenValid());

        // Test email verification (existing functionality)
        $this->emailVerificationService->verifyEmail($consignee->getEmailVerificationToken());
        
        $this->entityManager->refresh($consignee);
        $this->assertTrue($consignee->isEmailVerified());
        $this->assertNotNull($consignee->getEmailVerifiedAt());
    }

    /**
     * Test that CONSIGNEE account status management remains unchanged
     * Validates: Requirements 6.1, 6.5
     */
    public function testConsigneeAccountStatusManagementUnchanged(): void
    {
        // Arrange
        $userData = [
            'email' => 'status-consignee@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Status Test Consignee'
        ];
        $consignee = $this->userService->createUser($userData, UserRole::CONSIGNEE);

        // Act & Assert - Test all status transitions
        $consignee->setStatus(AccountStatus::PENDING);
        $this->assertEquals(AccountStatus::PENDING, $consignee->getStatus());

        $consignee->setStatus(AccountStatus::APPROVED);
        $this->assertEquals(AccountStatus::APPROVED, $consignee->getStatus());
        $this->assertTrue($consignee->isActive());

        $consignee->setStatus(AccountStatus::SUSPENDED);
        $this->assertEquals(AccountStatus::SUSPENDED, $consignee->getStatus());
        $this->assertFalse($consignee->isActive());

        // Test account locking functionality
        $this->userService->lockAccount($consignee->getId(), 30);
        $this->entityManager->refresh($consignee);
        
        $this->assertTrue($consignee->isLocked());
        $this->assertEquals(AccountStatus::LOCKED, $consignee->getStatus());

        // Test account unlocking
        $this->userService->unlockAccount($consignee->getId());
        $this->entityManager->refresh($consignee);
        
        $this->assertFalse($consignee->isLocked());
        $this->assertNull($consignee->getLockedUntil());
    }

    /**
     * Test that CONSIGNEE database relationships remain unchanged
     * Validates: Requirements 6.6
     */
    public function testConsigneeDatabaseRelationshipsPreserved(): void
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
        $consignee->setLinkedBroker($broker);
        $this->entityManager->flush();

        // Assert - Verify database constraints and foreign keys work
        $connection = $this->entityManager->getConnection();
        
        // Check consignees table structure remains unchanged
        $consigneeTableInfo = $connection->fetchAllAssociative("DESCRIBE consignees");
        $columnNames = array_column($consigneeTableInfo, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('business_name', $columnNames);
        $this->assertContains('broker_id', $columnNames);

        // Verify foreign key constraint to brokers table still exists
        $foreignKeys = $connection->fetchAllAssociative("
            SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = 'consignees' 
            AND TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        $brokerForeignKey = array_filter($foreignKeys, function($fk) {
            return $fk['REFERENCED_TABLE_NAME'] === 'brokers';
        });
        
        $this->assertNotEmpty($brokerForeignKey, 'Foreign key to brokers table should exist');

        // Test cascade behavior - removing broker should set consignee broker_id to NULL
        $this->entityManager->remove($broker);
        $this->entityManager->flush();
        $this->entityManager->refresh($consignee);

        $this->assertNull($consignee->getLinkedBroker());
    }

    /**
     * Test that CONSIGNEE role doesn't interfere with new shipping line features
     * Validates: Requirements 6.4
     */
    public function testConsigneeRoleDoesNotInterfereWithShippingLineFeatures(): void
    {
        // Arrange
        $consigneeData = [
            'email' => 'interference-consignee@test.com',
            'password' => 'TestPassword123!',
            'businessName' => 'Interference Test Consignee'
        ];
        $consignee = $this->userService->createUser($consigneeData, UserRole::CONSIGNEE);

        // Act & Assert - Verify CONSIGNEE is not affected by shipping line hierarchy
        $this->assertNull($consignee->getShippingLineAdmin());
        $this->assertNull($consignee->getManagedShippingLine());
        $this->assertNull($consignee->getShippingLineScope());
        
        // Verify CONSIGNEE doesn't require shipping line relationships
        $this->assertFalse($consignee->requiresShippingLineAdmin());
        $this->assertFalse($consignee->requiresManagedShippingLine());
        
        // Verify hierarchy validation passes for independent role
        $validationErrors = $consignee->validateHierarchy();
        $this->assertEmpty($validationErrors);
        
        // Verify CONSIGNEE can't manage other users (not part of hierarchy)
        $this->assertFalse($consignee->canManageUser($consignee));
        
        // Verify scoped users returns empty (no subordinates)
        $this->assertEmpty($consignee->getScopedUsers());
    }

    /**
     * Test that multiple CONSIGNEE users can exist independently
     * Validates: Requirements 6.1, 6.5
     */
    public function testMultipleConsigneesIndependentOperation(): void
    {
        // Arrange & Act - Create multiple consignees
        $consignees = [];
        for ($i = 1; $i <= 3; $i++) {
            $userData = [
                'email' => "consignee{$i}@test.com",
                'password' => 'TestPassword123!',
                'businessName' => "Test Consignee Business {$i}"
            ];
            $consignees[] = $this->userService->createUser($userData, UserRole::CONSIGNEE);
        }

        // Assert - Verify all consignees are independent
        foreach ($consignees as $consignee) {
            $this->assertEquals(UserRole::CONSIGNEE, $consignee->getRole());
            $this->assertNull($consignee->getShippingLineAdmin());
            $this->assertNull($consignee->getManagedShippingLine());
            $this->assertEquals(3, $consignee->getHierarchyLevel());
        }

        // Verify they don't interfere with each other
        $this->assertCount(3, $consignees);
        $emails = array_map(fn($c) => $c->getEmail(), $consignees);
        $this->assertCount(3, array_unique($emails));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}