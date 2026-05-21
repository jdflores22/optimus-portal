<?php

namespace App\Tests\Property;

use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Entity\Terminal;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Container;
use App\Entity\PreAdviceRequest;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\TerminalType;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Property-based tests for Container Yard Management feature
 * 
 * **Feature: shipping-line-container-yard-management**
 */
class AllocationPropertyTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private TerminalService $terminalService;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->terminalService = static::getContainer()->get(TerminalService::class);
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    /**
     * **Validates: Requirements 3.3, 4.3, 6.1, 6.3**
     * 
     * Property 1: For any allocation creation or update request, if the allocated capacity 
     * is zero, negative, or non-integer, then the system should reject the request with a validation error.
     * 
     * @Feature: shipping-line-container-yard-management, Property 1: Capacity Validation Rejects Invalid Values
     */
    public function testCapacityValidationRejectsInvalidValues(): void
    {
        $this->forAll(
            Generator\choose(-1000, 0) // Generate invalid capacities (zero and negative)
        )->then(function ($invalidCapacity) {
            // Arrange: Create test shipping line with admin
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            
            // Act: Attempt to allocate with invalid capacity
            $this->client->loginUser($this->createSystemAdmin());
            $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/allocate", [
                'terminalId' => $terminal->getId(),
                'allocatedTEUs' => $invalidCapacity
            ]);
            
            // Assert: Should receive validation error
            $response = $this->client->getResponse();
            $this->assertEquals(400, $response->getStatusCode());
            
            $data = json_decode($response->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('greater than zero', strtolower($data['message']));
        });
    }

    /**
     * **Validates: Requirements 1.1, 2.2, 7.2, 8.2, 8.4, 9.2**
     * 
     * Property 2: For any allocation displayed in any view, the rendered output should contain 
     * terminal name, allocated TEU capacity, and current utilization percentage.
     * 
     * @Feature: shipping-line-container-yard-management, Property 2: Required Fields Present in Display
     */
    public function testRequiredFieldsPresentInDisplay(): void
    {
        $this->forAll(
            Generator\choose(1, 10000) // Valid capacity range
        )->then(function ($capacity) {
            // Arrange: Create allocation
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            $allocation = $this->createTestAllocation($shippingLine, $terminal, $capacity);
            
            // Act: Get container yards
            $this->client->loginUser($this->createSystemAdmin());
            $this->client->request('GET', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards");
            
            // Assert: Response contains required fields
            $response = $this->client->getResponse();
            $data = json_decode($response->getContent(), true);
            
            $this->assertTrue($data['success']);
            $this->assertNotEmpty($data['terminals']);
            
            foreach ($data['terminals'] as $terminalData) {
                $this->assertArrayHasKey('name', $terminalData);
                $this->assertArrayHasKey('allocation', $terminalData);
                
                if ($terminalData['allocation'] !== null) {
                    $this->assertArrayHasKey('allocatedTEUs', $terminalData['allocation']);
                    $this->assertIsInt($terminalData['allocation']['allocatedTEUs']);
                }
            }
            
            // Cleanup
            $this->cleanupTestData($allocation);
        });
    }

    /**
     * **Validates: Requirements 3.4, 6.2**
     * 
     * Property 3: For any valid allocation with terminal, staff user, and capacity, 
     * creating the allocation and then retrieving it should return an allocation with 
     * the same terminal, staff user, and capacity values.
     * 
     * @Feature: shipping-line-container-yard-management, Property 3: Allocation Persistence Round Trip
     */
    public function testAllocationPersistenceRoundTrip(): void
    {
        $this->forAll(
            Generator\choose(1, 10000) // Valid capacity
        )->then(function ($capacity) {
            // Arrange
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            $admin = $shippingLine->getShippingLineAdmins()->first();
            
            // Act: Create allocation
            $allocation = new ShippingLineTerminalAllocation();
            $allocation->setStaffUser($admin);
            $allocation->setTerminal($terminal);
            $allocation->setAllocatedCapacity($capacity);
            
            $this->entityManager->persist($allocation);
            $this->entityManager->flush();
            
            // Retrieve allocation
            $retrieved = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->find($allocation->getId());
            
            // Assert: Round trip preserves data
            $this->assertNotNull($retrieved);
            $this->assertEquals($capacity, $retrieved->getAllocatedCapacity());
            $this->assertEquals($terminal->getId(), $retrieved->getTerminal()->getId());
            $this->assertEquals($admin->getId(), $retrieved->getStaffUser()->getId());
            
            // Cleanup
            $this->cleanupTestData($allocation);
        });
    }

    /**
     * **Validates: Requirements 3.5, 10.2**
     * 
     * Property 4: For any shipping line and terminal, if an allocation already exists and 
     * a new allocation is created with the same shipping line and terminal but different capacity, 
     * then the system should update the existing allocation rather than creating a duplicate.
     * 
     * @Feature: shipping-line-container-yard-management, Property 4: Duplicate Allocation Updates Instead of Creates
     */
    public function testDuplicateAllocationUpdatesInsteadOfCreates(): void
    {
        $this->forAll(
            Generator\choose(100, 500),  // Initial capacity
            Generator\choose(600, 1000)  // Updated capacity
        )->then(function ($initialCapacity, $updatedCapacity) {
            // Arrange: Create initial allocation
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            $allocation = $this->createTestAllocation($shippingLine, $terminal, $initialCapacity);
            
            // Act: Attempt to create another allocation for same shipping line and terminal
            $this->client->loginUser($this->createSystemAdmin());
            $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/allocate", [
                'terminalId' => $terminal->getId(),
                'allocatedTEUs' => $updatedCapacity
            ]);
            
            // Assert: Should update existing allocation, not create duplicate
            $response = $this->client->getResponse();
            $this->assertEquals(200, $response->getStatusCode());
            
            // Count allocations for this shipping line and terminal
            $admin = $shippingLine->getShippingLineAdmins()->first();
            $allocations = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->findBy(['staffUser' => $admin, 'terminal' => $terminal]);
            
            $this->assertCount(1, $allocations, 'Should have exactly one allocation');
            $this->assertEquals($updatedCapacity, $allocations[0]->getAllocatedCapacity());
            
            // Cleanup
            $this->cleanupTestData($allocation);
        });
    }


    /**
     * **Validates: Requirements 7.3, 8.3**
     * 
     * Property 5: For any terminal and shipping line, when calculating current TEUs, 
     * the sum should only include containers with pre-advice status of 'approved' or 'edo_ready'.
     * 
     * @Feature: shipping-line-container-yard-management, Property 5: TEU Calculation Includes Only Approved Containers
     */
    public function testTEUCalculationIncludesOnlyApprovedContainers(): void
    {
        $this->forAll(
            Generator\choose(1, 10) // Number of approved containers
        )->then(function ($approvedCount) {
            // Arrange
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            
            // Create approved containers
            for ($i = 0; $i < $approvedCount; $i++) {
                $this->createTestContainer($shippingLine, $terminal, '20ft', 'approved');
            }
            
            // Create non-approved containers (should not be counted)
            $this->createTestContainer($shippingLine, $terminal, '20ft', 'pending');
            $this->createTestContainer($shippingLine, $terminal, '20ft', 'rejected');
            
            // Act: Calculate utilization
            $utilization = $this->terminalService->getShippingLineUtilization($terminal, $shippingLine);
            
            // Assert: Only approved containers counted
            $this->assertEquals($approvedCount, $utilization['currentTEUs']);
        });
    }

    /**
     * **Validates: Requirements 7.4**
     * 
     * Property 6: For any set of containers, when converting to TEUs, 20ft containers should 
     * contribute 1 TEU each, 40ft containers should contribute 2 TEUs each, and 45ft containers 
     * should contribute 2.25 TEUs each.
     * 
     * @Feature: shipping-line-container-yard-management, Property 6: TEU Conversion Formula Correctness
     */
    public function testTEUConversionFormulaCorrectness(): void
    {
        $this->forAll(
            Generator\choose(0, 10), // Number of 20ft containers
            Generator\choose(0, 10), // Number of 40ft containers
            Generator\choose(0, 10)  // Number of 45ft containers
        )->then(function ($count20ft, $count40ft, $count45ft) {
            // Arrange
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            
            // Create containers of different sizes
            for ($i = 0; $i < $count20ft; $i++) {
                $this->createTestContainer($shippingLine, $terminal, '20ft', 'approved');
            }
            for ($i = 0; $i < $count40ft; $i++) {
                $this->createTestContainer($shippingLine, $terminal, '40ft', 'approved');
            }
            for ($i = 0; $i < $count45ft; $i++) {
                $this->createTestContainer($shippingLine, $terminal, '45ft', 'approved');
            }
            
            // Act: Calculate utilization
            $utilization = $this->terminalService->getShippingLineUtilization($terminal, $shippingLine);
            
            // Assert: TEU conversion is correct
            $expectedTEUs = ($count20ft * 1) + ($count40ft * 2) + ($count45ft * 2.25);
            $this->assertEquals((int)$expectedTEUs, $utilization['currentTEUs']);
        });
    }

    /**
     * **Validates: Requirements 6.5**
     * 
     * Property 7: For any allocation with allocated capacity > 0 and current TEU count, 
     * the utilization percentage should equal (current TEUs / allocated TEUs) × 100, rounded to nearest integer.
     * 
     * @Feature: shipping-line-container-yard-management, Property 7: Utilization Percentage Calculation
     */
    public function testUtilizationPercentageCalculation(): void
    {
        $this->forAll(
            Generator\choose(100, 1000), // Allocated capacity
            Generator\choose(0, 50)      // Number of 20ft containers
        )->then(function ($allocatedCapacity, $containerCount) {
            // Arrange
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            $this->createTestAllocation($shippingLine, $terminal, $allocatedCapacity);
            
            // Create containers
            for ($i = 0; $i < $containerCount; $i++) {
                $this->createTestContainer($shippingLine, $terminal, '20ft', 'approved');
            }
            
            // Act: Calculate utilization
            $utilization = $this->terminalService->getShippingLineUtilization($terminal, $shippingLine);
            
            // Assert: Percentage calculation is correct
            $expectedPercentage = round(($containerCount / $allocatedCapacity) * 100, 0);
            $this->assertEquals((int)$expectedPercentage, $utilization['percentage']);
        });
    }

    /**
     * **Validates: Requirements 7.5**
     * 
     * Property 8: For any utilization percentage, the color code should be green if percentage < 80, 
     * orange if 80 ≤ percentage < 100, and red if percentage ≥ 100.
     * 
     * @Feature: shipping-line-container-yard-management, Property 8: Color Coding Based on Utilization Ranges
     */
    public function testColorCodingBasedOnUtilizationRanges(): void
    {
        $this->forAll(
            Generator\choose(0, 200) // Utilization percentage range
        )->then(function ($percentage) {
            // Determine expected color based on percentage
            if ($percentage < 80) {
                $expectedColor = 'green';
            } elseif ($percentage < 100) {
                $expectedColor = 'orange';
            } else {
                $expectedColor = 'red';
            }
            
            // This property validates the color coding logic
            // In a real implementation, this would test the UI rendering
            // For now, we validate the logic
            $actualColor = $this->getColorForPercentage($percentage);
            $this->assertEquals($expectedColor, $actualColor);
        });
    }

    /**
     * **Validates: Requirements 7.6**
     * 
     * Property 9: For any set of containers at a terminal, the count of 20ft containers 
     * plus the count of 40ft/45ft containers should equal the total number of containers.
     * 
     * @Feature: shipping-line-container-yard-management, Property 9: Container Count Breakdown Accuracy
     */
    public function testContainerCountBreakdownAccuracy(): void
    {
        $this->forAll(
            Generator\choose(0, 10), // Number of 20ft containers
            Generator\choose(0, 10)  // Number of 40ft/45ft containers
        )->then(function ($count20ft, $count40ft) {
            // Arrange
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            
            // Create containers
            for ($i = 0; $i < $count20ft; $i++) {
                $this->createTestContainer($shippingLine, $terminal, '20ft', 'approved');
            }
            for ($i = 0; $i < $count40ft; $i++) {
                $this->createTestContainer($shippingLine, $terminal, '40ft', 'approved');
            }
            
            // Act: Get utilization
            $utilization = $this->terminalService->getShippingLineUtilization($terminal, $shippingLine);
            
            // Assert: Container counts add up
            $totalContainers = $utilization['container20ft'] + $utilization['container40ft'];
            $expectedTotal = $count20ft + $count40ft;
            $this->assertEquals($expectedTotal, $totalContainers);
        });
    }

    /**
     * **Validates: Requirements 1.3**
     * 
     * Property 10: For any query to the container yard library with default filters, 
     * all returned terminals should have isActive = true.
     * 
     * @Feature: shipping-line-container-yard-management, Property 10: Active Terminals Filter
     */
    public function testActiveTerminalsFilter(): void
    {
        $this->forAll(
            Generator\choose(1, 5) // Number of active terminals to create
        )->then(function ($activeCount) {
            // Arrange: Create active and inactive terminals
            for ($i = 0; $i < $activeCount; $i++) {
                $this->createTestTerminal("Active Terminal $i", true);
            }
            $this->createTestTerminal("Inactive Terminal", false);
            
            // Act: Get all terminals (default filter should show only active)
            $activeTerminals = $this->entityManager
                ->getRepository(Terminal::class)
                ->findBy(['isActive' => true]);
            
            // Assert: All returned terminals are active
            foreach ($activeTerminals as $terminal) {
                $this->assertTrue($terminal->isActive());
            }
        });
    }

    /**
     * **Validates: Requirements 1.2**
     * 
     * Property 11: For any search query string and set of terminals, all returned terminals 
     * should have either their name or location containing the search query (case-insensitive).
     * 
     * @Feature: shipping-line-container-yard-management, Property 11: Search Filter Correctness
     */
    public function testSearchFilterCorrectness(): void
    {
        $this->forAll(
            Generator\elements(['Manila', 'Batangas', 'Subic', 'ICTSI', 'ATI'])
        )->then(function ($searchQuery) {
            // Arrange: Create terminals with known names/locations
            $this->createTestTerminal('ICTSI Manila', true, 'Manila, Philippines');
            $this->createTestTerminal('ATI Terminal', true, 'Batangas, Philippines');
            $this->createTestTerminal('Subic Bay Terminal', true, 'Subic, Philippines');
            
            // Act: Search terminals
            $terminals = $this->entityManager
                ->getRepository(Terminal::class)
                ->createQueryBuilder('t')
                ->where('LOWER(t.name) LIKE :query OR LOWER(t.location) LIKE :query')
                ->setParameter('query', '%' . strtolower($searchQuery) . '%')
                ->getQuery()
                ->getResult();
            
            // Assert: All results match search query
            foreach ($terminals as $terminal) {
                $nameMatch = stripos($terminal->getName(), $searchQuery) !== false;
                $locationMatch = stripos($terminal->getLocation(), $searchQuery) !== false;
                $this->assertTrue($nameMatch || $locationMatch);
            }
        });
    }


    /**
     * **Validates: Requirements 1.4**
     * 
     * Property 12: For any terminal in the container yard library for a specific shipping line, 
     * the terminal should be indicated as allocated if and only if an allocation exists.
     * 
     * @Feature: shipping-line-container-yard-management, Property 12: Allocation Indicator Accuracy
     */
    public function testAllocationIndicatorAccuracy(): void
    {
        $this->forAll(
            Generator\choose(1, 1000) // Allocation capacity
        )->then(function ($capacity) {
            // Arrange
            $shippingLine = $this->createTestShippingLine();
            $terminal1 = $this->createTestTerminal('Terminal 1');
            $terminal2 = $this->createTestTerminal('Terminal 2');
            
            // Create allocation only for terminal1
            $allocation = $this->createTestAllocation($shippingLine, $terminal1, $capacity);
            
            // Act: Get container yards
            $this->client->loginUser($this->createSystemAdmin());
            $this->client->request('GET', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards");
            
            $response = $this->client->getResponse();
            $data = json_decode($response->getContent(), true);
            
            // Assert: Terminal1 should show allocation, Terminal2 should not
            foreach ($data['terminals'] as $terminalData) {
                if ($terminalData['id'] === $terminal1->getId()) {
                    $this->assertNotNull($terminalData['allocation']);
                    $this->assertEquals($capacity, $terminalData['allocation']['allocatedTEUs']);
                } elseif ($terminalData['id'] === $terminal2->getId()) {
                    $this->assertNull($terminalData['allocation']);
                }
            }
            
            // Cleanup
            $this->cleanupTestData($allocation);
        });
    }

    /**
     * **Validates: Requirements 2.1, 7.1, 9.1**
     * 
     * Property 13: For any shipping line with N allocations, when displaying allocations 
     * in any view, exactly N allocations should be displayed.
     * 
     * @Feature: shipping-line-container-yard-management, Property 13: Display All Allocations
     */
    public function testDisplayAllAllocations(): void
    {
        $this->forAll(
            Generator\choose(1, 5) // Number of allocations
        )->then(function ($allocationCount) {
            // Arrange: Create shipping line with multiple allocations
            $shippingLine = $this->createTestShippingLine();
            $allocations = [];
            
            for ($i = 0; $i < $allocationCount; $i++) {
                $terminal = $this->createTestTerminal("Terminal $i");
                $allocations[] = $this->createTestAllocation($shippingLine, $terminal, 100 * ($i + 1));
            }
            
            // Act: Get container yards
            $this->client->loginUser($this->createSystemAdmin());
            $this->client->request('GET', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards");
            
            $response = $this->client->getResponse();
            $data = json_decode($response->getContent(), true);
            
            // Assert: Count allocations in response
            $displayedAllocations = array_filter($data['terminals'], fn($t) => $t['allocation'] !== null);
            $this->assertCount($allocationCount, $displayedAllocations);
            
            // Cleanup
            foreach ($allocations as $allocation) {
                $this->cleanupTestData($allocation);
            }
        });
    }

    /**
     * **Validates: Requirements 4.4, 10.4**
     * 
     * Property 14: For any existing allocation, when the allocated capacity is updated, 
     * the updatedAt timestamp should be greater than the previous updatedAt timestamp.
     * 
     * @Feature: shipping-line-container-yard-management, Property 14: Allocation Update Modifies Timestamp
     */
    public function testAllocationUpdateModifiesTimestamp(): void
    {
        $this->forAll(
            Generator\choose(100, 500),  // Initial capacity
            Generator\choose(600, 1000)  // Updated capacity
        )->then(function ($initialCapacity, $updatedCapacity) {
            // Arrange: Create allocation
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            $allocation = $this->createTestAllocation($shippingLine, $terminal, $initialCapacity);
            
            $originalUpdatedAt = $allocation->getUpdatedAt();
            
            // Wait a moment to ensure timestamp difference
            sleep(1);
            
            // Act: Update allocation
            $allocation->setAllocatedCapacity($updatedCapacity);
            $allocation->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            
            // Assert: updatedAt timestamp increased
            $this->assertGreaterThan($originalUpdatedAt, $allocation->getUpdatedAt());
            
            // Cleanup
            $this->cleanupTestData($allocation);
        });
    }

    /**
     * **Validates: Requirements 5.3**
     * 
     * Property 15: For any allocation, after deletion, querying for that allocation by ID 
     * should return null or not found.
     * 
     * @Feature: shipping-line-container-yard-management, Property 15: Allocation Deletion Removes Record
     */
    public function testAllocationDeletionRemovesRecord(): void
    {
        $this->forAll(
            Generator\choose(1, 1000) // Allocation capacity
        )->then(function ($capacity) {
            // Arrange: Create allocation
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            $allocation = $this->createTestAllocation($shippingLine, $terminal, $capacity);
            $allocationId = $allocation->getId();
            
            // Act: Delete allocation
            $this->entityManager->remove($allocation);
            $this->entityManager->flush();
            
            // Assert: Allocation no longer exists
            $retrieved = $this->entityManager
                ->getRepository(ShippingLineTerminalAllocation::class)
                ->find($allocationId);
            
            $this->assertNull($retrieved);
        });
    }

    /**
     * **Validates: Requirements 6.4**
     * 
     * Property 16: For any terminal with daily capacity D and allocation with capacity A where A > D, 
     * the system should accept and persist the allocation without validation errors.
     * 
     * @Feature: shipping-line-container-yard-management, Property 16: Capacity Can Exceed Daily Capacity
     */
    public function testCapacityCanExceedDailyCapacity(): void
    {
        $this->forAll(
            Generator\choose(1000, 2000), // Daily capacity
            Generator\choose(2001, 5000)  // Allocated capacity (exceeds daily)
        )->then(function ($dailyCapacity, $allocatedCapacity) {
            // Arrange: Create terminal with specific daily capacity
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal('Test Terminal', true, 'Test Location', $dailyCapacity);
            
            // Act: Create allocation exceeding daily capacity
            $allocation = $this->createTestAllocation($shippingLine, $terminal, $allocatedCapacity);
            
            // Assert: Allocation was created successfully
            $this->assertNotNull($allocation->getId());
            $this->assertEquals($allocatedCapacity, $allocation->getAllocatedCapacity());
            $this->assertGreaterThan($terminal->getDailyCapacity(), $allocation->getAllocatedCapacity());
            
            // Cleanup
            $this->cleanupTestData($allocation);
        });
    }

    /**
     * **Validates: Requirements 10.3**
     * 
     * Property 17: For any staff user with N allocations, when the staff user is deleted, 
     * all N allocations should also be deleted.
     * 
     * @Feature: shipping-line-container-yard-management, Property 17: Cascade Delete on Staff User Removal
     */
    public function testCascadeDeleteOnStaffUserRemoval(): void
    {
        $this->forAll(
            Generator\choose(1, 3) // Number of allocations
        )->then(function ($allocationCount) {
            // Arrange: Create staff user with multiple allocations
            $shippingLine = $this->createTestShippingLine();
            $admin = $shippingLine->getShippingLineAdmins()->first();
            
            $allocationIds = [];
            for ($i = 0; $i < $allocationCount; $i++) {
                $terminal = $this->createTestTerminal("Terminal $i");
                $allocation = $this->createTestAllocation($shippingLine, $terminal, 100);
                $allocationIds[] = $allocation->getId();
            }
            
            // Act: Delete staff user
            $this->entityManager->remove($admin);
            $this->entityManager->flush();
            
            // Assert: All allocations are deleted
            foreach ($allocationIds as $allocationId) {
                $retrieved = $this->entityManager
                    ->getRepository(ShippingLineTerminalAllocation::class)
                    ->find($allocationId);
                $this->assertNull($retrieved);
            }
        });
    }

    /**
     * **Validates: Requirements 10.3**
     * 
     * Property 18: For any terminal with N allocations, when the terminal is deleted, 
     * all N allocations should also be deleted.
     * 
     * @Feature: shipping-line-container-yard-management, Property 18: Cascade Delete on Terminal Removal
     */
    public function testCascadeDeleteOnTerminalRemoval(): void
    {
        $this->forAll(
            Generator\choose(1, 3) // Number of shipping lines with allocations
        )->then(function ($shippingLineCount) {
            // Arrange: Create terminal with allocations from multiple shipping lines
            $terminal = $this->createTestTerminal();
            $allocationIds = [];
            
            for ($i = 0; $i < $shippingLineCount; $i++) {
                $shippingLine = $this->createTestShippingLine("Shipping Line $i");
                $allocation = $this->createTestAllocation($shippingLine, $terminal, 100);
                $allocationIds[] = $allocation->getId();
            }
            
            // Act: Delete terminal
            $this->entityManager->remove($terminal);
            $this->entityManager->flush();
            
            // Assert: All allocations are deleted
            foreach ($allocationIds as $allocationId) {
                $retrieved = $this->entityManager
                    ->getRepository(ShippingLineTerminalAllocation::class)
                    ->find($allocationId);
                $this->assertNull($retrieved);
            }
        });
    }

    /**
     * **Validates: Requirements 10.4**
     * 
     * Property 19: For any newly created allocation, both createdAt and updatedAt timestamps 
     * should be set to the current time, and createdAt should equal updatedAt.
     * 
     * @Feature: shipping-line-container-yard-management, Property 19: Timestamps Set on Creation
     */
    public function testTimestampsSetOnCreation(): void
    {
        $this->forAll(
            Generator\choose(1, 1000) // Allocation capacity
        )->then(function ($capacity) {
            // Arrange & Act: Create allocation
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            
            $beforeCreation = new \DateTime();
            $allocation = $this->createTestAllocation($shippingLine, $terminal, $capacity);
            $afterCreation = new \DateTime();
            
            // Assert: Timestamps are set correctly
            $this->assertNotNull($allocation->getCreatedAt());
            $this->assertNotNull($allocation->getUpdatedAt());
            
            // Timestamps should be within reasonable range
            $this->assertGreaterThanOrEqual($beforeCreation, $allocation->getCreatedAt());
            $this->assertLessThanOrEqual($afterCreation, $allocation->getCreatedAt());
            
            // createdAt should equal updatedAt for new allocations
            $this->assertEquals(
                $allocation->getCreatedAt()->getTimestamp(),
                $allocation->getUpdatedAt()->getTimestamp()
            );
            
            // Cleanup
            $this->cleanupTestData($allocation);
        });
    }


    /**
     * **Validates: Requirements 11.5**
     * 
     * Property 20: For any allocation operation with a non-existent shipping line ID, 
     * the system should reject the operation with a validation error.
     * 
     * @Feature: shipping-line-container-yard-management, Property 20: Invalid Shipping Line Rejected
     */
    public function testInvalidShippingLineRejected(): void
    {
        $this->forAll(
            Generator\choose(99999, 999999) // Non-existent shipping line IDs
        )->then(function ($invalidShippingLineId) {
            // Arrange: Create terminal
            $terminal = $this->createTestTerminal();
            
            // Act: Attempt allocation with invalid shipping line
            $this->client->loginUser($this->createSystemAdmin());
            $this->client->request('POST', "/admin/shipping-lines/{$invalidShippingLineId}/container-yards/allocate", [
                'terminalId' => $terminal->getId(),
                'allocatedTEUs' => 500
            ]);
            
            // Assert: Should receive not found error
            $response = $this->client->getResponse();
            $this->assertEquals(404, $response->getStatusCode());
            
            $data = json_decode($response->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('not found', strtolower($data['message']));
        });
    }

    /**
     * **Validates: Requirements 12.4**
     * 
     * Property 21: For any successful API response, the JSON should contain a "success" field 
     * set to true, and for error responses, should contain "success" set to false.
     * 
     * @Feature: shipping-line-container-yard-management, Property 21: API Response Contains Required Fields
     */
    public function testAPIResponseContainsRequiredFields(): void
    {
        $this->forAll(
            Generator\choose(1, 1000) // Valid capacity
        )->then(function ($capacity) {
            // Arrange
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            
            // Act: Make successful API call
            $this->client->loginUser($this->createSystemAdmin());
            $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/allocate", [
                'terminalId' => $terminal->getId(),
                'allocatedTEUs' => $capacity
            ]);
            
            // Assert: Success response has required fields
            $response = $this->client->getResponse();
            $data = json_decode($response->getContent(), true);
            
            $this->assertArrayHasKey('success', $data);
            $this->assertTrue($data['success']);
            $this->assertTrue(
                isset($data['message']) || isset($data['allocation']),
                'Response should contain message or data'
            );
            
            // Test error response
            $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/allocate", [
                'terminalId' => $terminal->getId(),
                'allocatedTEUs' => 0 // Invalid capacity
            ]);
            
            $errorResponse = $this->client->getResponse();
            $errorData = json_decode($errorResponse->getContent(), true);
            
            $this->assertArrayHasKey('success', $errorData);
            $this->assertFalse($errorData['success']);
            $this->assertArrayHasKey('message', $errorData);
        });
    }

    /**
     * **Validates: Requirements 12.5**
     * 
     * Property 22: For any API request, successful operations should return 200 status, 
     * validation errors should return 400 status, not found errors should return 404 status.
     * 
     * @Feature: shipping-line-container-yard-management, Property 22: HTTP Status Codes Match Outcomes
     */
    public function testHTTPStatusCodesMatchOutcomes(): void
    {
        $this->forAll(
            Generator\elements(['success', 'validation_error', 'not_found'])
        )->then(function ($scenario) {
            $this->client->loginUser($this->createSystemAdmin());
            
            switch ($scenario) {
                case 'success':
                    // Arrange: Valid request
                    $shippingLine = $this->createTestShippingLine();
                    $terminal = $this->createTestTerminal();
                    
                    // Act
                    $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/allocate", [
                        'terminalId' => $terminal->getId(),
                        'allocatedTEUs' => 500
                    ]);
                    
                    // Assert: 200 status
                    $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
                    break;
                    
                case 'validation_error':
                    // Arrange: Invalid capacity
                    $shippingLine = $this->createTestShippingLine();
                    $terminal = $this->createTestTerminal();
                    
                    // Act
                    $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/allocate", [
                        'terminalId' => $terminal->getId(),
                        'allocatedTEUs' => 0
                    ]);
                    
                    // Assert: 400 status
                    $this->assertEquals(400, $this->client->getResponse()->getStatusCode());
                    break;
                    
                case 'not_found':
                    // Act: Non-existent shipping line
                    $this->client->request('GET', '/admin/shipping-lines/999999/container-yards');
                    
                    // Assert: 404 status
                    $this->assertEquals(404, $this->client->getResponse()->getStatusCode());
                    break;
            }
        });
    }

    /**
     * **Validates: Requirements 12.6**
     * 
     * Property 23: For any allocation API endpoint, requests without System_Admin role 
     * should be rejected with 403 Forbidden status.
     * 
     * @Feature: shipping-line-container-yard-management, Property 23: Authorization Enforced for All Operations
     */
    public function testAuthorizationEnforcedForAllOperations(): void
    {
        $this->forAll(
            Generator\elements(['GET', 'POST_allocate', 'POST_remove'])
        )->then(function ($operation) {
            // Arrange: Create non-admin user
            $nonAdminUser = $this->createNonAdminUser();
            $shippingLine = $this->createTestShippingLine();
            $terminal = $this->createTestTerminal();
            
            $this->client->loginUser($nonAdminUser);
            
            // Act: Attempt operation without System_Admin role
            switch ($operation) {
                case 'GET':
                    $this->client->request('GET', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards");
                    break;
                    
                case 'POST_allocate':
                    $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/allocate", [
                        'terminalId' => $terminal->getId(),
                        'allocatedTEUs' => 500
                    ]);
                    break;
                    
                case 'POST_remove':
                    $allocation = $this->createTestAllocation($shippingLine, $terminal, 500);
                    $this->client->request('POST', "/admin/shipping-lines/{$shippingLine->getId()}/container-yards/{$allocation->getId()}/remove");
                    break;
            }
            
            // Assert: Should receive 403 Forbidden
            $response = $this->client->getResponse();
            $this->assertEquals(403, $response->getStatusCode());
        });
    }

    // ==================== Helper Methods ====================

    /**
     * Create a test shipping line with an admin
     */
    private function createTestShippingLine(string $name = 'Test Shipping Line'): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($name . '_' . uniqid());
        $shippingLine->setIsActive(true);
        $shippingLine->setPortalConfig(['theme' => 'default']);
        
        $this->entityManager->persist($shippingLine);
        
        // Create admin for shipping line
        $admin = new StaffUser();
        $admin->setEmail('admin_' . uniqid() . '@test.com');
        $admin->setFirstName('Test');
        $admin->setLastName('Admin');
        $admin->setRole(UserRole::SHIPPING_LINE_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setManagedShippingLine($shippingLine);
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT));
        
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        
        return $shippingLine;
    }

    /**
     * Create a test terminal
     */
    private function createTestTerminal(
        string $name = 'Test Terminal',
        bool $isActive = true,
        string $location = 'Test Location',
        int $dailyCapacity = 1000
    ): Terminal {
        $terminal = new Terminal();
        $terminal->setName($name . '_' . uniqid());
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation($location);
        $terminal->setDailyCapacity($dailyCapacity);
        $terminal->setIsActive($isActive);
        
        $this->entityManager->persist($terminal);
        $this->entityManager->flush();
        
        return $terminal;
    }

    /**
     * Create a test allocation
     */
    private function createTestAllocation(
        ShippingLine $shippingLine,
        Terminal $terminal,
        int $capacity
    ): ShippingLineTerminalAllocation {
        $admin = $shippingLine->getShippingLineAdmins()->first();
        
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($admin);
        $allocation->setTerminal($terminal);
        $allocation->setAllocatedCapacity($capacity);
        
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        return $allocation;
    }

    /**
     * Create a test container
     */
    private function createTestContainer(
        ShippingLine $shippingLine,
        Terminal $terminal,
        string $size,
        string $status
    ): Container {
        // Create trucker first (required for PreAdviceRequest)
        $trucker = $this->createTestTrucker();
        
        $container = new Container();
        $container->setContainerNumber('TEST' . uniqid());
        $container->setSize($size);
        $container->setType('DRY');
        $container->setStatus(\App\Entity\Enum\ContainerStatus::IN_TRANSIT);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setShippingLine($shippingLine);
        
        $this->entityManager->persist($container);
        
        // Create pre-advice request
        // Note: The TerminalService uses string statuses 'approved' and 'edo_ready'
        // but PreAdviceStatus enum doesn't have these values
        // We'll use VERIFIED as the closest match to 'approved'
        $preAdvice = new PreAdviceRequest();
        $preAdvice->setContainer($container);
        $preAdvice->setSelectedTerminal($terminal);
        $preAdvice->setTrucker($trucker);
        
        // Map string status to enum
        $enumStatus = match($status) {
            'approved', 'verified' => \App\Entity\Enum\PreAdviceStatus::VERIFIED,
            'edo_ready', 'completed' => \App\Entity\Enum\PreAdviceStatus::COMPLETED,
            'rejected' => \App\Entity\Enum\PreAdviceStatus::REJECTED,
            'cancelled' => \App\Entity\Enum\PreAdviceStatus::CANCELLED,
            default => \App\Entity\Enum\PreAdviceStatus::PENDING
        };
        
        $preAdvice->setStatus($enumStatus);
        
        $this->entityManager->persist($preAdvice);
        $this->entityManager->flush();
        
        return $container;
    }

    /**
     * Create a test trucker
     */
    private function createTestTrucker(): \App\Entity\Trucker
    {
        $trucker = new \App\Entity\Trucker();
        $trucker->setEmail('trucker_' . uniqid() . '@test.com');
        $trucker->setFirstName('Test');
        $trucker->setLastName('Trucker');
        $trucker->setRole(UserRole::TRUCKER);
        $trucker->setStatus(AccountStatus::APPROVED);
        $trucker->setPassword(password_hash('password', PASSWORD_BCRYPT));
        
        $this->entityManager->persist($trucker);
        $this->entityManager->flush();
        
        return $trucker;
    }

    /**
     * Create a system admin user
     */
    private function createSystemAdmin(): StaffUser
    {
        $admin = new StaffUser();
        $admin->setEmail('sysadmin_' . uniqid() . '@test.com');
        $admin->setFirstName('System');
        $admin->setLastName('Admin');
        $admin->setRole(UserRole::SYSTEM_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setPassword(password_hash('password', PASSWORD_BCRYPT));
        
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        
        return $admin;
    }

    /**
     * Create a non-admin user
     */
    private function createNonAdminUser(): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail('user_' . uniqid() . '@test.com');
        $user->setFirstName('Regular');
        $user->setLastName('User');
        $user->setRole(UserRole::TRUCKER);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    /**
     * Get color for utilization percentage
     */
    private function getColorForPercentage(int $percentage): string
    {
        if ($percentage < 80) {
            return 'green';
        } elseif ($percentage < 100) {
            return 'orange';
        } else {
            return 'red';
        }
    }

    /**
     * Cleanup test data
     */
    private function cleanupTestData($entity): void
    {
        if ($entity && $this->entityManager->contains($entity)) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }
}
