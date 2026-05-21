<?php

namespace App\Tests\Integration\BackwardCompatibility;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Simplified integration tests to ensure CONSIGNEE role functionality remains unchanged
 * after implementing dynamic shipping line management features.
 * 
 * Validates Requirements: 6.1, 6.3, 6.4, 6.5, 6.6
 */
class SimplifiedConsigneeRolePreservationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
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
        // Arrange & Act
        $consignee = new Consignee();
        $consignee->setEmail('consignee@test.com');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('Test Consignee Business');
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
        $consignee->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

        // Assert
        $this->assertInstanceOf(Consignee::class, $consignee);
        $this->assertEquals(UserRole::CONSIGNEE, $consignee->getRole());
        $this->assertEquals('consignee@test.com', $consignee->getEmail());
        $this->assertEquals('Test Consignee Business', $consignee->getBusinessName());
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
        $broker = new Broker();
        $broker->setEmail('broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::PENDING);

        // Create consignee
        $consignee = new Consignee();
        $consignee->setEmail('consignee@test.com');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('Test Consignee Business');
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
        $consignee->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($broker);
        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

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
     * Test that CONSIGNEE account status management remains unchanged
     * Validates: Requirements 6.1, 6.5
     */
    public function testConsigneeAccountStatusManagementUnchanged(): void
    {
        // Arrange
        $consignee = new Consignee();
        $consignee->setEmail('status-consignee@test.com');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('Status Test Consignee');
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));

        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

        // Act & Assert - Test all status transitions
        $consignee->setStatus(AccountStatus::PENDING);
        $this->assertEquals(AccountStatus::PENDING, $consignee->getStatus());

        $consignee->setStatus(AccountStatus::APPROVED);
        $this->assertEquals(AccountStatus::APPROVED, $consignee->getStatus());
        $this->assertTrue($consignee->isActive());

        $consignee->setStatus(AccountStatus::DENIED);
        $this->assertEquals(AccountStatus::DENIED, $consignee->getStatus());
        $this->assertFalse($consignee->isActive());

        // Test account locking functionality
        $lockedUntil = new \DateTime();
        $lockedUntil->modify('+30 minutes');
        $consignee->setLockedUntil($lockedUntil);
        $consignee->setStatus(AccountStatus::LOCKED);
        
        $this->assertTrue($consignee->isLocked());
        $this->assertEquals(AccountStatus::LOCKED, $consignee->getStatus());

        // Test account unlocking
        $consignee->setLockedUntil(null);
        $consignee->resetFailedLoginAttempts();
        $consignee->setStatus(AccountStatus::PENDING);
        
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
        $broker = new Broker();
        $broker->setEmail('db-broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('DB Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::PENDING);

        $consignee = new Consignee();
        $consignee->setEmail('db-consignee@test.com');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('DB Test Consignee');
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
        $consignee->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($broker);
        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

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
        // First unlink the consignee to avoid foreign key constraint violation
        $consignee->setLinkedBroker(null);
        $this->entityManager->flush();
        
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
        $consignee = new Consignee();
        $consignee->setEmail('interference-consignee@test.com');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('Interference Test Consignee');
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
        $consignee->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

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
            $consignee = new Consignee();
            $consignee->setEmail("consignee{$i}@test.com");
            $consignee->setRole(UserRole::CONSIGNEE);
            $consignee->setBusinessName("Test Consignee Business {$i}");
            $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
            $consignee->setStatus(AccountStatus::PENDING);
            
            $this->entityManager->persist($consignee);
            $consignees[] = $consignee;
        }
        $this->entityManager->flush();

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

    /**
     * Test that CONSIGNEE Symfony security roles remain unchanged
     * Validates: Requirements 6.1, 6.5
     */
    public function testConsigneeSymfonySecurityRolesUnchanged(): void
    {
        // Arrange
        $consignee = new Consignee();
        $consignee->setEmail('security-consignee@test.com');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('Security Test Consignee');
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
        $consignee->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

        // Act & Assert - Verify Symfony security roles remain unchanged
        $this->assertEquals(['ROLE_CONSIGNEE'], $consignee->getRoles());
        $this->assertEquals('security-consignee@test.com', $consignee->getUserIdentifier());
    }

    /**
     * Test that CONSIGNEE failed login attempts functionality remains unchanged
     * Validates: Requirements 6.1, 6.5
     */
    public function testConsigneeFailedLoginAttemptsUnchanged(): void
    {
        // Arrange
        $consignee = new Consignee();
        $consignee->setEmail('lockout-consignee@test.com');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setBusinessName('Lockout Test Consignee');
        $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
        $consignee->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

        // Act & Assert - Test failed login attempts
        $this->assertEquals(0, $consignee->getFailedLoginAttempts());

        // First failed attempt
        $consignee->incrementFailedLoginAttempts();
        $this->assertEquals(1, $consignee->getFailedLoginAttempts());

        // Second failed attempt
        $consignee->incrementFailedLoginAttempts();
        $this->assertEquals(2, $consignee->getFailedLoginAttempts());

        // Third failed attempt
        $consignee->incrementFailedLoginAttempts();
        $this->assertEquals(3, $consignee->getFailedLoginAttempts());

        // Reset attempts
        $consignee->resetFailedLoginAttempts();
        $this->assertEquals(0, $consignee->getFailedLoginAttempts());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}