<?php

namespace App\Tests\Integration\Entity;

use App\Entity\ShippingLine;
use App\Entity\ActivityLog;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ShippingLineIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()->get('doctrine')->getManager();
    }

    public function testShippingLineEntityPersistence(): void
    {
        // Create a shipping line
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Integration Shipping Line');
        $shippingLine->setPortalConfig(['theme' => 'blue', 'logo' => 'test.png']);

        // Persist and flush
        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();

        // Verify it was saved
        $this->assertNotNull($shippingLine->getId());
        
        // Retrieve from database
        $repository = $this->entityManager->getRepository(ShippingLine::class);
        $retrievedShippingLine = $repository->findByBrandName('Test Integration Shipping Line');
        
        $this->assertNotNull($retrievedShippingLine);
        $this->assertEquals('Test Integration Shipping Line', $retrievedShippingLine->getBrandName());
        $this->assertEquals(['theme' => 'blue', 'logo' => 'test.png'], $retrievedShippingLine->getPortalConfig());
        $this->assertTrue($retrievedShippingLine->isActive());

        // Clean up
        $this->entityManager->remove($retrievedShippingLine);
        $this->entityManager->flush();
    }

    public function testActivityLogEntityPersistence(): void
    {
        // Create a mock user for the activity log
        $user = $this->createMockUser();
        $this->entityManager->persist($user);
        
        // Create a shipping line
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Activity Log Shipping Line');
        $this->entityManager->persist($shippingLine);
        
        // Create an activity log
        $activityLog = new ActivityLog();
        $activityLog->setUser($user);
        $activityLog->setShippingLine($shippingLine);
        $activityLog->setActivityType(ActivityLog::TYPE_SHIPPING_LINE_CREATION);
        $activityLog->setEntityType('ShippingLine');
        $activityLog->setEntityId($shippingLine->getId());
        $activityLog->setIpAddress('192.168.1.1');
        $activityLog->setUserAgent('Test User Agent');
        $activityLog->setNewValues(['brand_name' => 'Test Activity Log Shipping Line']);

        $this->entityManager->persist($activityLog);
        $this->entityManager->flush();

        // Verify it was saved
        $this->assertNotNull($activityLog->getId());
        
        // Retrieve from database
        $repository = $this->entityManager->getRepository(ActivityLog::class);
        $retrievedLog = $repository->findOneBy(['id' => $activityLog->getId()]);
        
        $this->assertNotNull($retrievedLog);
        $this->assertEquals(ActivityLog::TYPE_SHIPPING_LINE_CREATION, $retrievedLog->getActivityType());
        $this->assertEquals('ShippingLine', $retrievedLog->getEntityType());
        $this->assertEquals('192.168.1.1', $retrievedLog->getIpAddress());
        $this->assertEquals($user->getId(), $retrievedLog->getUser()->getId());
        $this->assertEquals($shippingLine->getId(), $retrievedLog->getShippingLine()->getId());

        // Clean up
        $this->entityManager->remove($retrievedLog);
        $this->entityManager->remove($shippingLine);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testUserHierarchyRelationships(): void
    {
        // Create a shipping line
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Hierarchy Shipping Line');
        $this->entityManager->persist($shippingLine);

        // Create a shipping line admin
        $admin = $this->createMockUser(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setManagedShippingLine($shippingLine);
        $this->entityManager->persist($admin);

        // Create subordinate users
        $staff = $this->createMockUser(UserRole::SL_STAFF);
        $staff->setShippingLineAdmin($admin);
        $this->entityManager->persist($staff);

        $evaluator = $this->createMockUser(UserRole::EVALUATOR);
        $evaluator->setShippingLineAdmin($admin);
        $this->entityManager->persist($evaluator);

        $this->entityManager->flush();

        // Verify relationships
        $this->assertEquals($shippingLine, $admin->getManagedShippingLine());
        $this->assertEquals($admin, $staff->getShippingLineAdmin());
        $this->assertEquals($admin, $evaluator->getShippingLineAdmin());
        $this->assertEquals($shippingLine, $staff->getShippingLineScope());
        $this->assertEquals($shippingLine, $evaluator->getShippingLineScope());

        // Test hierarchy validation
        $this->assertEmpty($admin->validateHierarchy());
        $this->assertEmpty($staff->validateHierarchy());
        $this->assertEmpty($evaluator->validateHierarchy());

        // Clean up
        $this->entityManager->remove($staff);
        $this->entityManager->remove($evaluator);
        $this->entityManager->remove($admin);
        $this->entityManager->remove($shippingLine);
        $this->entityManager->flush();
    }

    private function createMockUser(UserRole $role = UserRole::SYSTEM_ADMIN): User
    {
        $user = new StaffUser();
        $user->setEmail('test' . uniqid() . '@example.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole($role);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setDepartment('Testing');
        
        return $user;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}