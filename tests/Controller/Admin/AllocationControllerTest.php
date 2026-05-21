<?php

namespace App\Tests\Controller\Admin;

use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\TerminalType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test: Container Yard Allocation Controller
 * 
 * This test verifies the ShippingLineController container yard management endpoints:
 * - GET /admin/shipping-lines/{id}/container-yards
 * - POST /admin/shipping-lines/{id}/container-yards/allocate
 * - POST /admin/shipping-lines/{id}/container-yards/{allocationId}/remove
 * 
 * **Validates: Container Yard Management API Requirements**
 */
class AllocationControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;
    private StaffUser $systemAdmin;
    private ShippingLine $testShippingLine;
    private Terminal $testTerminal;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        
        // Start transaction for each test
        $this->entityManager->beginTransaction();
        
        // Create test data
        $this->systemAdmin = $this->createSystemAdminUser();
        $this->testShippingLine = $this->createShippingLine('Test Shipping Line');
        $this->testTerminal = $this->createTerminal('Test Terminal');
        
        // Login as system admin
        $this->client->loginUser($this->systemAdmin);
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
        parent::tearDown();
    }

    /**
     * Test: getContainerYards returns all terminals
     * Validates: Requirement 1.1, 12.1
     */
    public function testGetContainerYardsReturnsAllTerminals(): void
    {
        // Create additional terminals
        $terminal2 = $this->createTerminal('Terminal 2');
        $terminal3 = $this->createTerminal('Terminal 3');
        
        $this->client->request(
            'GET',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards'
        );
        
        // Debug output
        if ($this->client->getResponse()->getStatusCode() !== Response::HTTP_OK) {
            echo "\nResponse Status: " . $this->client->getResponse()->getStatusCode();
            echo "\nResponse Content: " . $this->client->getResponse()->getContent();
        }
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('terminals', $responseData);
        $this->assertGreaterThanOrEqual(3, count($responseData['terminals']));
        
        // Verify terminal structure
        $terminal = $responseData['terminals'][0];
        $this->assertArrayHasKey('id', $terminal);
        $this->assertArrayHasKey('name', $terminal);
        $this->assertArrayHasKey('type', $terminal);
        $this->assertArrayHasKey('location', $terminal);
        $this->assertArrayHasKey('dailyCapacity', $terminal);
        $this->assertArrayHasKey('isActive', $terminal);
        $this->assertArrayHasKey('allocation', $terminal);
    }

    /**
     * Test: allocateContainerYard creates allocation
     * Validates: Requirement 3.4, 12.2
     */
    public function testAllocateContainerYardCreatesAllocation(): void
    {
        // Assign admin to shipping line
        $admin = $this->createShippingLineAdmin();
        $this->testShippingLine->addShippingLineAdmin($admin);
        $admin->setManagedShippingLine($this->testShippingLine);
        $this->entityManager->flush();
        
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal->getId(),
                'allocatedTEUs' => 500
            ]
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertEquals('Container yard allocated successfully', $responseData['message']);
        $this->assertArrayHasKey('allocation', $responseData);
        $this->assertEquals($this->testTerminal->getId(), $responseData['allocation']['terminalId']);
        $this->assertEquals(500, $responseData['allocation']['allocatedTEUs']);
        
        // Verify allocation was persisted
        $allocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->findOneBy(['staffUser' => $admin, 'terminal' => $this->testTerminal]);
        $this->assertNotNull($allocation);
        $this->assertEquals(500, $allocation->getAllocatedCapacity());
    }

    /**
     * Test: allocateContainerYard updates existing allocation
     * Validates: Requirement 3.5, 4.4
     */
    public function testAllocateContainerYardUpdatesExistingAllocation(): void
    {
        // Create initial allocation
        $admin = $this->createShippingLineAdmin();
        $this->testShippingLine->addShippingLineAdmin($admin);
        $admin->setManagedShippingLine($this->testShippingLine);
        $this->entityManager->flush();
        
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($admin);
        $allocation->setTerminal($this->testTerminal);
        $allocation->setAllocatedCapacity(300);
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        $originalUpdatedAt = $allocation->getUpdatedAt();
        $allocationId = $allocation->getId();
        
        // Wait a moment to ensure timestamp difference
        sleep(1);
        
        // Update allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal->getId(),
                'allocatedTEUs' => 600
            ]
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertEquals(600, $responseData['allocation']['allocatedTEUs']);
        
        // Verify allocation was updated, not duplicated
        $updatedAllocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->find($allocationId);
        $this->assertNotNull($updatedAllocation);
        $this->assertEquals(600, $updatedAllocation->getAllocatedCapacity());
        $this->assertGreaterThan($originalUpdatedAt, $updatedAllocation->getUpdatedAt());
        
        // Verify no duplicate was created
        $allAllocations = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->findBy(['staffUser' => $admin, 'terminal' => $this->testTerminal]);
        $this->assertCount(1, $allAllocations);
    }

    /**
     * Test: removeContainerYard deletes allocation
     * Validates: Requirement 5.3, 12.3
     */
    public function testRemoveContainerYardDeletesAllocation(): void
    {
        // Create allocation
        $admin = $this->createShippingLineAdmin();
        $this->testShippingLine->addShippingLineAdmin($admin);
        $admin->setManagedShippingLine($this->testShippingLine);
        
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($admin);
        $allocation->setTerminal($this->testTerminal);
        $allocation->setAllocatedCapacity(400);
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        $allocationId = $allocation->getId();
        
        // Remove allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/' . $allocationId . '/remove'
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        // Verify allocation was deleted
        $deletedAllocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->find($allocationId);
        $this->assertNull($deletedAllocation);
    }

    /**
     * Test: error handling for invalid shipping line
     * Validates: Requirement 11.5, 12.5
     */
    public function testErrorHandlingForInvalidShippingLine(): void
    {
        $this->client->request(
            'GET',
            '/admin/shipping-lines/99999/container-yards'
        );
        
        $this->assertEquals(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Shipping line not found', $responseData['message']);
    }

    /**
     * Test: error handling for invalid terminal
     * Validates: Requirement 11.1, 12.5
     */
    public function testErrorHandlingForInvalidTerminal(): void
    {
        // Assign admin to shipping line
        $admin = $this->createShippingLineAdmin();
        $this->testShippingLine->addShippingLineAdmin($admin);
        $admin->setManagedShippingLine($this->testShippingLine);
        $this->entityManager->flush();
        
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => 99999,
                'allocatedTEUs' => 500
            ]
        );
        
        $this->assertEquals(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Terminal not found', $responseData['message']);
    }

    /**
     * Test: error handling for zero/negative capacity
     * Validates: Requirement 11.2, 6.3
     */
    public function testErrorHandlingForZeroOrNegativeCapacity(): void
    {
        // Assign admin to shipping line
        $admin = $this->createShippingLineAdmin();
        $this->testShippingLine->addShippingLineAdmin($admin);
        $admin->setManagedShippingLine($this->testShippingLine);
        $this->entityManager->flush();
        
        // Test zero capacity
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal->getId(),
                'allocatedTEUs' => 0
            ]
        );
        
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContainsString('Invalid terminal or TEU allocation', $responseData['message']);
        
        // Test negative capacity
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal->getId(),
                'allocatedTEUs' => -100
            ]
        );
        
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Test: error handling for shipping line with no admins
     * Validates: Requirement 11.3
     */
    public function testErrorHandlingForShippingLineWithNoAdmins(): void
    {
        // Ensure shipping line has no admins
        $this->testShippingLine->getShippingLineAdmins()->clear();
        $this->entityManager->flush();
        
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal->getId(),
                'allocatedTEUs' => 500
            ]
        );
        
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('No administrators assigned', $responseData['message']);
    }

    /**
     * Test: allocation not found error
     * Validates: Requirement 11.4, 12.5
     */
    public function testAllocationNotFoundError(): void
    {
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/99999/remove'
        );
        
        $this->assertEquals(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Allocation not found', $responseData['message']);
    }

    // Helper methods

    private function createSystemAdminUser(): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail('admin' . uniqid() . '@test.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('System');
        $user->setLastName('Admin');
        $user->setDepartment('IT');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createShippingLineAdmin(): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail('sladmin' . uniqid() . '@test.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('Shipping');
        $user->setLastName('Admin');
        $user->setDepartment('Operations');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createShippingLine(string $brandName): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($brandName . uniqid());
        $shippingLine->setPortalConfig(['test' => true]);
        
        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();
        
        return $shippingLine;
    }

    private function createTerminal(string $name): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName($name . uniqid());
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation('Test Location');
        $terminal->setDailyCapacity(1000);
        $terminal->setIsActive(true);
        
        $this->entityManager->persist($terminal);
        $this->entityManager->flush();
        
        return $terminal;
    }
}
