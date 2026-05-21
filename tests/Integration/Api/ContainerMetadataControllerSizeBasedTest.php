<?php

namespace App\Tests\Integration\Api;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\StaffUser;
use App\Entity\Terminal;
use App\Entity\Enum\AllocationStatus;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 2.5, 2.6, 2.7: API Integration Tests for Size-Based CY Allocation
 * 
 * Tests verify:
 * - Response structure contains all size-specific fields
 * - Count calculations are accurate
 * - Backward compatibility with TEU fields
 */
class ContainerMetadataControllerSizeBasedTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?EntityManagerInterface $entityManager = null;
    private ?StaffUser $testUser = null;
    private ?ShippingLine $shippingLine = null;
    private ?Terminal $terminal = null;
    private ?ContainerSize $size20ft = null;
    private ?ContainerSize $size40ft = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        
        // Start transaction for test isolation
        $this->entityManager->beginTransaction();
        
        // Create test data
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->entityManager && $this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
        
        $this->entityManager->close();
        $this->entityManager = null;
        parent::tearDown();
    }

    /**
     * Task 2.5: Test response structure contains all size-specific fields
     * 
     * @test
     */
    public function testGetCYAllocationsReturnsAllSizeSpecificFields(): void
    {
        // Create allocation with capacity
        $allocation = $this->createAllocation(50, 25);
        
        // Create containers
        $this->createContainer($allocation, $this->size20ft, AllocationStatus::ALLOCATED);
        $this->createContainer($allocation, $this->size40ft, AllocationStatus::PRE_FORECAST);
        
        $this->entityManager->flush();
        
        // Authenticate and make request
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/api/cy-allocations/all');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('allocations', $response);
        $this->assertNotEmpty($response['allocations']);
        
        $allocation = $response['allocations'][0];
        
        // Task 2.5: Verify all size-specific fields are present
        // 20ft fields
        $this->assertArrayHasKey('capacity_20ft', $allocation);
        $this->assertArrayHasKey('allocated_20ft', $allocation);
        $this->assertArrayHasKey('pre_forecast_20ft', $allocation);
        $this->assertArrayHasKey('available_20ft', $allocation);
        $this->assertArrayHasKey('utilization_percentage_20ft', $allocation);
        
        // 40ft fields
        $this->assertArrayHasKey('capacity_40ft', $allocation);
        $this->assertArrayHasKey('allocated_40ft', $allocation);
        $this->assertArrayHasKey('pre_forecast_40ft', $allocation);
        $this->assertArrayHasKey('available_40ft', $allocation);
        $this->assertArrayHasKey('utilization_percentage_40ft', $allocation);
    }

    /**
     * Task 2.6: Test count calculations are accurate
     * 
     * @test
     */
    public function testCountCalculationsAreAccurate(): void
    {
        // Create allocation
        $allocation = $this->createAllocation(50, 25);
        
        // Create 20ft containers: 3 allocated, 2 pre-forecast
        for ($i = 0; $i < 3; $i++) {
            $this->createContainer($allocation, $this->size20ft, AllocationStatus::ALLOCATED);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createContainer($allocation, $this->size20ft, AllocationStatus::PRE_FORECAST);
        }
        
        // Create 40ft containers: 5 allocated, 3 pre-forecast
        for ($i = 0; $i < 5; $i++) {
            $this->createContainer($allocation, $this->size40ft, AllocationStatus::ALLOCATED);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->createContainer($allocation, $this->size40ft, AllocationStatus::PRE_FORECAST);
        }
        
        $this->entityManager->flush();
        
        // Make request
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/api/cy-allocations/all');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $allocationData = $response['allocations'][0];
        
        // Task 2.6: Verify 20ft counts
        $this->assertEquals(50, $allocationData['capacity_20ft']);
        $this->assertEquals(3, $allocationData['allocated_20ft']);
        $this->assertEquals(2, $allocationData['pre_forecast_20ft']);
        $this->assertEquals(45, $allocationData['available_20ft']); // 50 - (3 + 2)
        $this->assertEquals(10.0, $allocationData['utilization_percentage_20ft']); // (5/50) * 100
        
        // Task 2.6: Verify 40ft counts
        $this->assertEquals(25, $allocationData['capacity_40ft']);
        $this->assertEquals(5, $allocationData['allocated_40ft']);
        $this->assertEquals(3, $allocationData['pre_forecast_40ft']);
        $this->assertEquals(17, $allocationData['available_40ft']); // 25 - (5 + 3)
        $this->assertEquals(32.0, $allocationData['utilization_percentage_40ft']); // (8/25) * 100
    }

    /**
     * Task 2.7: Test backward compatibility with TEU fields
     * 
     * @test
     */
    public function testBackwardCompatibilityWithTEUFields(): void
    {
        // Create allocation
        $allocation = $this->createAllocation(50, 25);
        
        // Create containers
        $this->createContainer($allocation, $this->size20ft, AllocationStatus::ALLOCATED); // 1 TEU
        $this->createContainer($allocation, $this->size40ft, AllocationStatus::ALLOCATED); // 2 TEU
        $this->createContainer($allocation, $this->size20ft, AllocationStatus::PRE_FORECAST); // 1 TEU
        $this->createContainer($allocation, $this->size40ft, AllocationStatus::PRE_FORECAST); // 2 TEU
        
        $this->entityManager->flush();
        
        // Make request
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/api/cy-allocations/all');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $allocationData = $response['allocations'][0];
        
        // Task 2.7: Verify TEU-based fields are still present
        $this->assertArrayHasKey('total_teu_capacity', $allocationData);
        $this->assertArrayHasKey('allocated_teu', $allocationData);
        $this->assertArrayHasKey('pre_forecast_teu', $allocationData);
        $this->assertArrayHasKey('used_teu', $allocationData);
        $this->assertArrayHasKey('available_teu', $allocationData);
        
        // Verify TEU calculations
        $this->assertEquals(3.0, $allocationData['allocated_teu']); // 1 + 2
        $this->assertEquals(3.0, $allocationData['pre_forecast_teu']); // 1 + 2
        $this->assertEquals(6.0, $allocationData['used_teu']); // 3 + 3
    }

    /**
     * Task 2.6: Test available capacity calculation
     * 
     * @test
     */
    public function testAvailableCapacityCalculation(): void
    {
        // Create allocation with specific capacity
        $allocation = $this->createAllocation(10, 5);
        
        // Fill 20ft: 7 allocated, 2 pre-forecast (total 9, available 1)
        for ($i = 0; $i < 7; $i++) {
            $this->createContainer($allocation, $this->size20ft, AllocationStatus::ALLOCATED);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createContainer($allocation, $this->size20ft, AllocationStatus::PRE_FORECAST);
        }
        
        // Fill 40ft: 4 allocated, 1 pre-forecast (total 5, available 0)
        for ($i = 0; $i < 4; $i++) {
            $this->createContainer($allocation, $this->size40ft, AllocationStatus::ALLOCATED);
        }
        $this->createContainer($allocation, $this->size40ft, AllocationStatus::PRE_FORECAST);
        
        $this->entityManager->flush();
        
        // Make request
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/api/cy-allocations/all');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $allocationData = $response['allocations'][0];
        
        // Verify available = capacity - (allocated + pre_forecast)
        $this->assertEquals(1, $allocationData['available_20ft']); // 10 - 9
        $this->assertEquals(0, $allocationData['available_40ft']); // 5 - 5
    }

    /**
     * Task 2.6: Test utilization percentage calculation
     * 
     * @test
     */
    public function testUtilizationPercentageCalculation(): void
    {
        // Create allocation
        $allocation = $this->createAllocation(100, 50);
        
        // 20ft: 30 allocated, 20 pre-forecast = 50% utilization
        for ($i = 0; $i < 30; $i++) {
            $this->createContainer($allocation, $this->size20ft, AllocationStatus::ALLOCATED);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->createContainer($allocation, $this->size20ft, AllocationStatus::PRE_FORECAST);
        }
        
        // 40ft: 40 allocated, 5 pre-forecast = 90% utilization
        for ($i = 0; $i < 40; $i++) {
            $this->createContainer($allocation, $this->size40ft, AllocationStatus::ALLOCATED);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->createContainer($allocation, $this->size40ft, AllocationStatus::PRE_FORECAST);
        }
        
        $this->entityManager->flush();
        
        // Make request
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/api/cy-allocations/all');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $allocationData = $response['allocations'][0];
        
        // Verify utilization percentages
        $this->assertEquals(50.0, $allocationData['utilization_percentage_20ft']);
        $this->assertEquals(90.0, $allocationData['utilization_percentage_40ft']);
    }

    /**
     * Task 2.7: Test zero capacity handling
     * 
     * @test
     */
    public function testZeroCapacityHandling(): void
    {
        // Create allocation with zero 40ft capacity
        $allocation = $this->createAllocation(50, 0);
        
        $this->entityManager->flush();
        
        // Make request
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/api/cy-allocations/all');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $allocationData = $response['allocations'][0];
        
        // Verify zero capacity returns 0% utilization
        $this->assertEquals(0, $allocationData['capacity_40ft']);
        $this->assertEquals(0.0, $allocationData['utilization_percentage_40ft']);
    }

    // Helper methods

    private function createTestData(): void
    {
        // Create shipping line
        $this->shippingLine = new ShippingLine();
        $this->shippingLine->setBrandName('Test Shipping Line ' . uniqid());
        $this->shippingLine->setPortalConfig(['test' => true]);
        $this->entityManager->persist($this->shippingLine);
        
        // Create terminal
        $this->terminal = new Terminal();
        $this->terminal->setName('Test Terminal ' . uniqid());
        $this->terminal->setType(\App\Entity\Enum\TerminalType::CY);
        $this->terminal->setLocation('Test Location');
        $this->terminal->setDailyCapacity(1000);
        $this->terminal->setIsActive(true);
        $this->entityManager->persist($this->terminal);
        
        // Create container sizes
        $this->size20ft = new ContainerSize();
        $this->size20ft->setName('20ft');
        $this->size20ft->setCode('20FT_' . uniqid());
        $this->size20ft->setTeuValue(1.0);
        $this->entityManager->persist($this->size20ft);
        
        $this->size40ft = new ContainerSize();
        $this->size40ft->setName('40ft');
        $this->size40ft->setCode('40FT_' . uniqid());
        $this->size40ft->setTeuValue(2.0);
        $this->entityManager->persist($this->size40ft);
        
        // Create test user
        $this->testUser = new StaffUser();
        $this->testUser->setEmail('test_' . uniqid() . '@example.com');
        $this->testUser->setPasswordHash('$2y$13$hashedpassword'); // Hashed password
        $this->testUser->setRole(UserRole::SL_STAFF);
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setDepartment('Test Department');
        
        // Set shipping line admin relationship
        $admin = new StaffUser();
        $admin->setEmail('admin_' . uniqid() . '@example.com');
        $admin->setPasswordHash('$2y$13$hashedpassword');
        $admin->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setDepartment('Admin Department');
        $admin->setManagedShippingLine($this->shippingLine);
        $this->entityManager->persist($admin);
        
        $this->testUser->setShippingLineAdmin($admin);
        $this->entityManager->persist($this->testUser);
        
        $this->entityManager->flush();
    }

    private function createAllocation(int $capacity20ft, int $capacity40ft): ShippingLineTerminalAllocation
    {
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setShippingLine($this->shippingLine);
        $allocation->setTerminal($this->terminal);
        $allocation->setStaffUser($this->testUser);
        $allocation->setAllocatedCapacity($capacity20ft + ($capacity40ft * 2)); // TEU capacity
        $allocation->setCapacity20ft($capacity20ft);
        $allocation->setCapacity40ft($capacity40ft);
        
        $this->entityManager->persist($allocation);
        
        return $allocation;
    }

    private function createContainer(
        ShippingLineTerminalAllocation $allocation,
        ContainerSize $size,
        AllocationStatus $status
    ): Container {
        static $counter = 0;
        $counter++;
        
        $container = new Container();
        $container->setContainerNumber('TEST' . str_pad($counter, 7, '0', STR_PAD_LEFT));
        $container->setContainerSize($size);
        $container->setCyAllocation($allocation);
        $container->setAllocationStatus($status);
        $container->setShippingLine($this->shippingLine);
        $container->setAllocatedAt(new \DateTime());
        
        $this->entityManager->persist($container);
        
        return $container;
    }

    private function loginAsUser(StaffUser $user): void
    {
        $this->client->loginUser($user);
    }
}
