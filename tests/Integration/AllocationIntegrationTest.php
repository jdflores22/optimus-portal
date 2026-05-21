<?php

namespace App\Tests\Integration;

use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Entity\StaffUser;
use App\Entity\Container;
use App\Entity\PreAdviceRequest;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\TerminalType;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test: Container Yard Allocation Integration
 * 
 * This test verifies the complete allocation workflow from creation to removal,
 * including the integration between controller, service, and entity layers.
 * 
 * **Validates: End-to-End Container Yard Management Workflow**
 */
class AllocationIntegrationTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;
    private TerminalService $terminalService;
    private StaffUser $systemAdmin;
    private ShippingLine $testShippingLine;
    private Terminal $testTerminal1;
    private Terminal $testTerminal2;
    private StaffUser $shippingLineAdmin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->terminalService = $container->get(TerminalService::class);
        
        // Start transaction for each test
        $this->entityManager->beginTransaction();
        
        // Create test data
        $this->systemAdmin = $this->createSystemAdminUser();
        $this->testShippingLine = $this->createShippingLine('Integration Test Shipping Line');
        $this->testTerminal1 = $this->createTerminal('Terminal 1');
        $this->testTerminal2 = $this->createTerminal('Terminal 2');
        $this->shippingLineAdmin = $this->createShippingLineAdmin();
        
        // Assign admin to shipping line
        $this->testShippingLine->addShippingLineAdmin($this->shippingLineAdmin);
        $this->shippingLineAdmin->setManagedShippingLine($this->testShippingLine);
        $this->entityManager->flush();
        
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
     * Test: Complete allocation workflow (create, update, remove)
     * Validates: Requirements 3, 4, 5 - Full CRUD workflow
     */
    public function testCompleteAllocationWorkflow(): void
    {
        // Step 1: Get initial container yards list (should have no allocations)
        $this->client->request(
            'GET',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards'
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        
        // Find our test terminals in the response
        $terminal1Data = null;
        $terminal2Data = null;
        foreach ($responseData['terminals'] as $terminal) {
            if ($terminal['id'] === $this->testTerminal1->getId()) {
                $terminal1Data = $terminal;
            }
            if ($terminal['id'] === $this->testTerminal2->getId()) {
                $terminal2Data = $terminal;
            }
        }
        
        $this->assertNotNull($terminal1Data);
        $this->assertNotNull($terminal2Data);
        $this->assertNull($terminal1Data['allocation']);
        $this->assertNull($terminal2Data['allocation']);
        
        // Step 2: Create first allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal1->getId(),
                'allocatedTEUs' => 500
            ]
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $allocation1Id = $responseData['allocation']['id'];
        
        // Verify allocation was created
        $allocation1 = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->find($allocation1Id);
        $this->assertNotNull($allocation1);
        $this->assertEquals(500, $allocation1->getAllocatedCapacity());
        
        // Step 3: Create second allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal2->getId(),
                'allocatedTEUs' => 300
            ]
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $allocation2Id = $responseData['allocation']['id'];
        
        // Step 4: Verify both allocations appear in list
        $this->client->request(
            'GET',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards'
        );
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $allocatedCount = 0;
        foreach ($responseData['terminals'] as $terminal) {
            if ($terminal['allocation'] !== null) {
                $allocatedCount++;
            }
        }
        $this->assertEquals(2, $allocatedCount);
        
        // Step 5: Add containers to terminal 1 and verify utilization
        $container20ft = $this->createContainer($this->testShippingLine, '20ft');
        $container40ft = $this->createContainer($this->testShippingLine, '40ft');
        $this->createPreAdviceRequest($container20ft, $this->testTerminal1, 'approved');
        $this->createPreAdviceRequest($container40ft, $this->testTerminal1, 'approved');
        $this->entityManager->flush();
        
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal1,
            $this->testShippingLine
        );
        
        $this->assertEquals(3, $utilization['currentTEUs']); // 1 + 2 = 3 TEUs
        $this->assertEquals(500, $utilization['allocatedTEUs']);
        $this->assertEquals(1, $utilization['percentage']); // (3/500) * 100 = 0.6 rounded to 1
        
        // Step 6: Update first allocation
        sleep(1); // Ensure timestamp difference
        
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal1->getId(),
                'allocatedTEUs' => 800
            ]
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        // Verify allocation was updated, not duplicated
        $this->entityManager->clear(); // Clear entity manager cache
        $updatedAllocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->find($allocation1Id);
        $this->assertNotNull($updatedAllocation);
        $this->assertEquals(800, $updatedAllocation->getAllocatedCapacity());
        
        // Verify no duplicate was created
        $allAllocations = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->findBy(['staffUser' => $this->shippingLineAdmin, 'terminal' => $this->testTerminal1]);
        $this->assertCount(1, $allAllocations);
        
        // Verify utilization reflects new capacity
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal1,
            $this->testShippingLine
        );
        $this->assertEquals(800, $utilization['allocatedTEUs']);
        $this->assertEquals(0, $utilization['percentage']); // (3/800) * 100 = 0.375 rounded to 0
        
        // Step 7: Remove first allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/' . $allocation1Id . '/remove'
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        // Verify allocation was deleted
        $this->entityManager->clear();
        $deletedAllocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->find($allocation1Id);
        $this->assertNull($deletedAllocation);
        
        // Verify utilization now uses terminal daily capacity
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal1,
            $this->testShippingLine
        );
        $this->assertEquals($this->testTerminal1->getDailyCapacity(), $utilization['allocatedTEUs']);
        
        // Step 8: Remove second allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/' . $allocation2Id . '/remove'
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        
        // Step 9: Verify no allocations remain
        $this->client->request(
            'GET',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards'
        );
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $allocatedCount = 0;
        foreach ($responseData['terminals'] as $terminal) {
            if ($terminal['allocation'] !== null) {
                $allocatedCount++;
            }
        }
        $this->assertEquals(0, $allocatedCount);
    }

    /**
     * Test: Allocation workflow with multiple containers and utilization tracking
     * Validates: Requirements 7.3, 7.4, 7.5 - TEU calculation and color coding
     */
    public function testAllocationWorkflowWithUtilizationTracking(): void
    {
        // Create allocation with 100 TEU capacity
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal1->getId(),
                'allocatedTEUs' => 100
            ]
        );
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $allocationId = $responseData['allocation']['id'];
        
        // Scenario 1: Low utilization (<80%) - should be green
        $container1 = $this->createContainer($this->testShippingLine, '20ft');
        $container2 = $this->createContainer($this->testShippingLine, '40ft');
        $this->createPreAdviceRequest($container1, $this->testTerminal1, 'approved');
        $this->createPreAdviceRequest($container2, $this->testTerminal1, 'approved');
        $this->entityManager->flush();
        
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal1,
            $this->testShippingLine
        );
        
        $this->assertEquals(3, $utilization['currentTEUs']); // 1 + 2 = 3
        $this->assertEquals(3, $utilization['percentage']); // (3/100) * 100 = 3
        $this->assertLessThan(80, $utilization['percentage']); // Green zone
        
        // Scenario 2: Medium utilization (80-99%) - should be orange
        // Add more containers to reach ~85%
        for ($i = 0; $i < 41; $i++) {
            $container = $this->createContainer($this->testShippingLine, '40ft');
            $this->createPreAdviceRequest($container, $this->testTerminal1, 'edo_ready');
        }
        $this->entityManager->flush();
        
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal1,
            $this->testShippingLine
        );
        
        $this->assertEquals(85, $utilization['currentTEUs']); // 3 + (41 * 2) = 85
        $this->assertEquals(85, $utilization['percentage']);
        $this->assertGreaterThanOrEqual(80, $utilization['percentage']); // Orange zone
        $this->assertLessThan(100, $utilization['percentage']);
        
        // Scenario 3: High utilization (≥100%) - should be red
        // Add more containers to exceed capacity
        for ($i = 0; $i < 10; $i++) {
            $container = $this->createContainer($this->testShippingLine, '40ft');
            $this->createPreAdviceRequest($container, $this->testTerminal1, 'approved');
        }
        $this->entityManager->flush();
        
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal1,
            $this->testShippingLine
        );
        
        $this->assertEquals(105, $utilization['currentTEUs']); // 85 + (10 * 2) = 105
        $this->assertEquals(105, $utilization['percentage']);
        $this->assertGreaterThanOrEqual(100, $utilization['percentage']); // Red zone
        
        // Cleanup: Remove allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/' . $allocationId . '/remove'
        );
        
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Test: Cascade delete behavior
     * Validates: Requirement 10.3 - Cascade delete on staff user and terminal removal
     */
    public function testCascadeDeleteBehavior(): void
    {
        // Create allocation
        $this->client->request(
            'POST',
            '/admin/shipping-lines/' . $this->testShippingLine->getId() . '/container-yards/allocate',
            [
                'terminalId' => $this->testTerminal1->getId(),
                'allocatedTEUs' => 400
            ]
        );
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $allocationId = $responseData['allocation']['id'];
        
        // Verify allocation exists
        $allocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->find($allocationId);
        $this->assertNotNull($allocation);
        
        // Delete the terminal (should cascade delete the allocation)
        $this->entityManager->remove($this->testTerminal1);
        $this->entityManager->flush();
        $this->entityManager->clear();
        
        // Verify allocation was cascade deleted
        $deletedAllocation = $this->entityManager->getRepository(ShippingLineTerminalAllocation::class)
            ->find($allocationId);
        $this->assertNull($deletedAllocation);
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

    private function createContainer(ShippingLine $shippingLine, string $size): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . uniqid());
        $container->setSize($size);
        $container->setType('Dry');
        $container->setShippingLine($shippingLine);
        
        $this->entityManager->persist($container);
        
        return $container;
    }

    private function createPreAdviceRequest(Container $container, Terminal $terminal, string $status): PreAdviceRequest
    {
        $preAdvice = new PreAdviceRequest();
        $preAdvice->setContainer($container);
        $preAdvice->setSelectedTerminal($terminal);
        $preAdvice->setStatus($status);
        $preAdvice->setRequestedDate(new \DateTime());
        
        $this->entityManager->persist($preAdvice);
        
        return $preAdvice;
    }
}
