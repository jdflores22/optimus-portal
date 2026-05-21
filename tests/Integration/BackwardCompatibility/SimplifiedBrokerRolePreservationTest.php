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
 * Simplified integration tests to ensure BROKER role functionality remains unchanged
 * after implementing dynamic shipping line management features.
 * 
 * Validates Requirements: 6.2, 6.3, 6.4, 6.5, 6.6
 */
class SimplifiedBrokerRolePreservationTest extends KernelTestCase
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
     * Test that BROKER users can be created exactly as before
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerCreationRemainsUnchanged(): void
    {
        // Arrange & Act
        $broker = new Broker();
        $broker->setEmail('broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Test Broker Full Name');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

        // Assert
        $this->assertInstanceOf(Broker::class, $broker);
        $this->assertEquals(UserRole::BROKER, $broker->getRole());
        $this->assertEquals('broker@test.com', $broker->getEmail());
        $this->assertEquals('Test Broker Full Name', $broker->getFullName());
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
        $broker = new Broker();
        $broker->setEmail('broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::PENDING);

        // Create multiple consignees
        $consignees = [];
        for ($i = 1; $i <= 3; $i++) {
            $consignee = new Consignee();
            $consignee->setEmail("consignee{$i}@test.com");
            $consignee->setRole(UserRole::CONSIGNEE);
            $consignee->setBusinessName("Test Consignee Business {$i}");
            $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
            $consignee->setStatus(AccountStatus::PENDING);
            $consignees[] = $consignee;
        }

        $this->entityManager->persist($broker);
        foreach ($consignees as $consignee) {
            $this->entityManager->persist($consignee);
        }
        $this->entityManager->flush();

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
     * Test that BROKER account status management remains unchanged
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerAccountStatusManagementUnchanged(): void
    {
        // Arrange
        $broker = new Broker();
        $broker->setEmail('status-broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Status Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

        // Act & Assert - Test all status transitions
        $broker->setStatus(AccountStatus::PENDING);
        $this->assertEquals(AccountStatus::PENDING, $broker->getStatus());

        $broker->setStatus(AccountStatus::APPROVED);
        $this->assertEquals(AccountStatus::APPROVED, $broker->getStatus());
        $this->assertTrue($broker->isActive());

        $broker->setStatus(AccountStatus::DENIED);
        $this->assertEquals(AccountStatus::DENIED, $broker->getStatus());
        $this->assertFalse($broker->isActive());

        // Test account locking functionality
        $lockedUntil = new \DateTime();
        $lockedUntil->modify('+30 minutes');
        $broker->setLockedUntil($lockedUntil);
        $broker->setStatus(AccountStatus::LOCKED);
        
        $this->assertTrue($broker->isLocked());
        $this->assertEquals(AccountStatus::LOCKED, $broker->getStatus());

        // Test account unlocking
        $broker->setLockedUntil(null);
        $broker->resetFailedLoginAttempts();
        $broker->setStatus(AccountStatus::PENDING);
        
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
        $broker = new Broker();
        $broker->setEmail('interference-broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Interference Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

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
        $broker = new Broker();
        $broker->setEmail('mgmt-broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Management Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::PENDING);

        // Create consignees
        $consignee1 = new Consignee();
        $consignee1->setEmail('consignee1@test.com');
        $consignee1->setRole(UserRole::CONSIGNEE);
        $consignee1->setBusinessName('Test Consignee 1');
        $consignee1->setPasswordHash($this->passwordHasher->hashPassword($consignee1, 'TestPassword123!'));
        $consignee1->setStatus(AccountStatus::PENDING);

        $consignee2 = new Consignee();
        $consignee2->setEmail('consignee2@test.com');
        $consignee2->setRole(UserRole::CONSIGNEE);
        $consignee2->setBusinessName('Test Consignee 2');
        $consignee2->setPasswordHash($this->passwordHasher->hashPassword($consignee2, 'TestPassword123!'));
        $consignee2->setStatus(AccountStatus::PENDING);

        $this->entityManager->persist($broker);
        $this->entityManager->persist($consignee1);
        $this->entityManager->persist($consignee2);
        $this->entityManager->flush();

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
            $broker = new Broker();
            $broker->setEmail("broker{$i}@test.com");
            $broker->setRole(UserRole::BROKER);
            $broker->setFullName("Test Broker {$i}");
            $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
            $broker->setStatus(AccountStatus::PENDING);
            
            $this->entityManager->persist($broker);
            $brokers[] = $broker;
        }

        // Create consignees for each broker
        foreach ($brokers as $index => $broker) {
            $consignee = new Consignee();
            $consignee->setEmail("consignee-for-broker{$index}@test.com");
            $consignee->setRole(UserRole::CONSIGNEE);
            $consignee->setBusinessName("Consignee for Broker {$index}");
            $consignee->setPasswordHash($this->passwordHasher->hashPassword($consignee, 'TestPassword123!'));
            $consignee->setStatus(AccountStatus::PENDING);
            
            $this->entityManager->persist($consignee);
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
     * Test that BROKER Symfony security roles remain unchanged
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerSymfonySecurityRolesUnchanged(): void
    {
        // Arrange
        $broker = new Broker();
        $broker->setEmail('security-broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Security Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

        // Act & Assert - Verify Symfony security roles remain unchanged
        $this->assertEquals(['ROLE_BROKER'], $broker->getRoles());
        $this->assertEquals('security-broker@test.com', $broker->getUserIdentifier());
    }

    /**
     * Test that BROKER failed login attempts functionality remains unchanged
     * Validates: Requirements 6.2, 6.5
     */
    public function testBrokerFailedLoginAttemptsUnchanged(): void
    {
        // Arrange
        $broker = new Broker();
        $broker->setEmail('lockout-broker@test.com');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName('Lockout Test Broker');
        $broker->setPasswordHash($this->passwordHasher->hashPassword($broker, 'TestPassword123!'));
        $broker->setStatus(AccountStatus::APPROVED);

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

        // Act & Assert - Test failed login attempts
        $this->assertEquals(0, $broker->getFailedLoginAttempts());

        // First failed attempt
        $broker->incrementFailedLoginAttempts();
        $this->assertEquals(1, $broker->getFailedLoginAttempts());

        // Second failed attempt
        $broker->incrementFailedLoginAttempts();
        $this->assertEquals(2, $broker->getFailedLoginAttempts());

        // Third failed attempt
        $broker->incrementFailedLoginAttempts();
        $this->assertEquals(3, $broker->getFailedLoginAttempts());

        // Reset attempts
        $broker->resetFailedLoginAttempts();
        $this->assertEquals(0, $broker->getFailedLoginAttempts());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}