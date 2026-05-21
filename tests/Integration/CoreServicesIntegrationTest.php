<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\PreAdviceRequest;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\SlotStatus;
use App\Entity\Enum\PreAdviceStatus;
use App\Service\ContainerSearchService;
use App\Service\TerminalService;
use App\Service\SlotManagementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for core services validation in Terminal Team Pre-Advice system
 * 
 * This test validates that all core services work together correctly and that
 * entity relationships and database operations function as expected.
 */
class CoreServicesIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ContainerSearchService $containerSearchService;
    private TerminalService $terminalService;
    private SlotManagementService $slotManagementService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer();
        
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->containerSearchService = $container->get(ContainerSearchService::class);
        $this->terminalService = $container->get(TerminalService::class);
        $this->slotManagementService = $container->get(SlotManagementService::class);
        
        // Ensure we have a fresh entity manager
        if (!$this->entityManager->isOpen()) {
            $this->entityManager = $container->get('doctrine')->resetManager();
        }
    }

    protected function tearDown(): void
    {
        if ($this->entityManager && $this->entityManager->isOpen()) {
            $this->entityManager->clear();
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    /**
     * Test complete workflow: Container search -> Terminal availability -> Slot assignment
     */
    public function testCompleteWorkflowIntegration(): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Step 1: Create test data
            $terminal = $this->createTestTerminal();
            $container = $this->createTestContainer();
            $trucker = $this->createTestTrucker();

            $this->entityManager->flush();

            // Step 2: Test container search functionality
            $foundContainer = $this->containerSearchService->findByContainerNumber($container->getContainerNumber());
            $this->assertNotNull($foundContainer, 'Container should be found by search service');
            $this->assertEquals($container->getId(), $foundContainer->getId());

            // Step 3: Validate container availability
            $isAvailable = $this->containerSearchService->validateContainerAvailability($foundContainer);
            $this->assertTrue($isAvailable, 'Container should be available for return');

            // Step 4: Test terminal compatibility
            $canAccept = $this->terminalService->canAcceptContainer($terminal, $foundContainer);
            $this->assertTrue($canAccept, 'Terminal should be able to accept the container');

            // Step 5: Find compatible terminals
            $compatibleTerminals = $this->terminalService->findCompatibleTerminals($foundContainer);
            $this->assertContains($terminal, $compatibleTerminals, 'Terminal should be in compatible terminals list');

            // Step 6: Create slots for the terminal
            $tomorrow = new \DateTime('tomorrow');
            $dayAfterTomorrow = new \DateTime('+2 days');
            $createdSlots = $this->slotManagementService->createDailySlots($terminal, $tomorrow, $dayAfterTomorrow);
            $this->assertCount(2, $createdSlots, 'Should create 2 daily slots');

            // Step 7: Check slot availability
            $slotAvailability = $this->slotManagementService->checkSlotAvailability($terminal, $tomorrow);
            $this->assertTrue($slotAvailability['available'], 'Slot should be available');
            $this->assertEquals($terminal->getDailyCapacity(), $slotAvailability['capacity']);
            $this->assertEquals(0, $slotAvailability['assigned']);

            // Step 8: Create and assign pre-advice request
            $preAdviceRequest = $this->createTestPreAdviceRequest($trucker, $foundContainer, $terminal);
            $this->entityManager->flush();

            // Step 9: Assign slot to pre-advice request
            $assignmentSuccess = $this->slotManagementService->assignSlot($terminal, $tomorrow, $preAdviceRequest);
            $this->assertTrue($assignmentSuccess, 'Slot assignment should succeed');

            // Step 10: Verify slot assignment
            $this->assertNotNull($preAdviceRequest->getAssignedSlot(), 'Pre-advice request should have assigned slot');
            $this->assertEquals($terminal->getId(), $preAdviceRequest->getAssignedSlot()->getTerminal()->getId());

            // Step 11: Check updated slot availability
            $updatedSlotAvailability = $this->slotManagementService->checkSlotAvailability($terminal, $tomorrow);
            $this->assertEquals(1, $updatedSlotAvailability['assigned'], 'Slot should have 1 assignment');
            $this->assertEquals($terminal->getDailyCapacity() - 1, $updatedSlotAvailability['remaining']);

            // Step 12: Test slot release
            $releaseSuccess = $this->slotManagementService->releaseSlot($preAdviceRequest);
            $this->assertTrue($releaseSuccess, 'Slot release should succeed');
            $this->assertNull($preAdviceRequest->getAssignedSlot(), 'Pre-advice request should not have assigned slot after release');

            // Step 13: Verify slot availability after release
            $finalSlotAvailability = $this->slotManagementService->checkSlotAvailability($terminal, $tomorrow);
            $this->assertEquals(0, $finalSlotAvailability['assigned'], 'Slot should have 0 assignments after release');
            $this->assertEquals($terminal->getDailyCapacity(), $finalSlotAvailability['remaining']);

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test terminal capacity and utilization calculations
     */
    public function testTerminalCapacityAndUtilization(): void
    {
        $this->entityManager->beginTransaction();

        try {
            $terminal = $this->createTestTerminal();
            $this->entityManager->flush();

            // Test initial capacity
            $today = new \DateTime('today');
            $capacityInfo = $this->terminalService->getTerminalCapacity($terminal, $today);
            
            $this->assertEquals($terminal->getDailyCapacity(), $capacityInfo['capacity']);
            $this->assertEquals(0, $capacityInfo['assigned']);
            $this->assertEquals($terminal->getDailyCapacity(), $capacityInfo['available']);

            // Create slots for a week
            $startDate = new \DateTime('today');
            $endDate = (clone $startDate)->modify('+6 days');
            $createdSlots = $this->slotManagementService->createDailySlots($terminal, $startDate, $endDate);
            $this->assertCount(7, $createdSlots, 'Should create 7 daily slots for a week');

            // Test utilization statistics
            $utilizationStats = $this->slotManagementService->getSlotUtilizationStats($terminal, $startDate, $endDate);
            
            $this->assertEquals(7, $utilizationStats['totalSlots']);
            $this->assertEquals(7 * $terminal->getDailyCapacity(), $utilizationStats['totalCapacity']);
            $this->assertEquals(0, $utilizationStats['totalAssigned']);
            $this->assertEquals(0, $utilizationStats['utilizationRate']);
            $this->assertEquals(7, $utilizationStats['availableSlots']);
            $this->assertEquals(0, $utilizationStats['fullSlots']);

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test container search with various criteria
     */
    public function testContainerSearchCriteria(): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Create multiple test containers
            $container1 = $this->createTestContainer('TEST1234567', '20ft', 'Dry', ContainerStatus::AVAILABLE_FOR_RETURN);
            $container2 = $this->createTestContainer('TEST9876543', '40ft', 'Reefer', ContainerStatus::IN_TRANSIT);
            $container3 = $this->createTestContainer('TEST5555555', '20ft', 'Dry', ContainerStatus::AVAILABLE_FOR_RETURN);
            
            $this->entityManager->flush();

            // Test search by status
            $availableContainers = $this->containerSearchService->searchContainers([
                'status' => ContainerStatus::AVAILABLE_FOR_RETURN
            ]);
            $this->assertCount(2, $availableContainers, 'Should find 2 available containers');

            // Test search by type
            $dryContainers = $this->containerSearchService->searchContainers([
                'type' => 'Dry'
            ]);
            $this->assertCount(2, $dryContainers, 'Should find 2 dry containers');

            // Test search by size
            $twentyFootContainers = $this->containerSearchService->searchContainers([
                'size' => '20ft'
            ]);
            $this->assertCount(2, $twentyFootContainers, 'Should find 2 twenty-foot containers');

            // Test combined criteria
            $specificContainers = $this->containerSearchService->searchContainers([
                'status' => ContainerStatus::AVAILABLE_FOR_RETURN,
                'type' => 'Dry',
                'size' => '20ft'
            ]);
            $this->assertCount(2, $specificContainers, 'Should find 2 containers matching all criteria');

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test terminal configuration and management
     */
    public function testTerminalConfiguration(): void
    {
        $this->entityManager->beginTransaction();

        try {
            $terminal = $this->createTestTerminal();
            $this->entityManager->flush();

            // Test terminal configuration update
            $newSettings = [
                'dailyCapacity' => 75,
                'isActive' => false,
                'location' => 'Updated Location'
            ];

            $updatedTerminal = $this->terminalService->configureTerminal($terminal, $newSettings);
            
            $this->assertEquals(75, $updatedTerminal->getDailyCapacity());
            $this->assertFalse($updatedTerminal->isActive());
            $this->assertEquals('Updated Location', $updatedTerminal->getLocation());

            // Test terminal details retrieval
            $terminalDetails = $this->terminalService->getTerminalDetails($updatedTerminal);
            
            $this->assertEquals($terminal->getId(), $terminalDetails['id']);
            $this->assertEquals(TerminalType::CY->value, $terminalDetails['type']);
            $this->assertEquals(75, $terminalDetails['dailyCapacity']);
            $this->assertFalse($terminalDetails['isActive']);
            $this->assertArrayHasKey('todayCapacity', $terminalDetails);

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Test error handling and edge cases
     */
    public function testErrorHandlingAndEdgeCases(): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Test container not found
            $nonExistentContainer = $this->containerSearchService->findByContainerNumber('NONEXISTENT123');
            $this->assertNull($nonExistentContainer, 'Should return null for non-existent container');

            // Test container availability for non-existent container
            $isAvailable = $this->containerSearchService->isAvailableForReturn('NONEXISTENT123');
            $this->assertFalse($isAvailable, 'Non-existent container should not be available');

            // Test container number format validation
            $validFormat = $this->containerSearchService->validateContainerNumberFormat('ABCD1234567');
            $this->assertTrue($validFormat, 'Valid container number format should pass validation');

            $invalidFormat = $this->containerSearchService->validateContainerNumberFormat('INVALID');
            $this->assertFalse($invalidFormat, 'Invalid container number format should fail validation');

            // Test terminal with inactive status
            $inactiveTerminal = $this->createTestTerminal();
            $inactiveTerminal->setIsActive(false);
            $container = $this->createTestContainer();
            $this->entityManager->flush();

            $canAcceptInactive = $this->terminalService->canAcceptContainer($inactiveTerminal, $container);
            $this->assertFalse($canAcceptInactive, 'Inactive terminal should not accept containers');

            // Test slot assignment to full capacity
            $activeTerminal = $this->createTestTerminal();
            $activeTerminal->setDailyCapacity(1); // Set capacity to 1
            $this->entityManager->flush();

            $tomorrow = new \DateTime('tomorrow');
            $this->slotManagementService->createDailySlots($activeTerminal, $tomorrow, $tomorrow);

            // Create first pre-advice request and assign slot
            $trucker1 = $this->createTestTrucker('trucker1@test.com');
            $preAdvice1 = $this->createTestPreAdviceRequest($trucker1, $container, $activeTerminal);
            $this->entityManager->flush();

            $firstAssignment = $this->slotManagementService->assignSlot($activeTerminal, $tomorrow, $preAdvice1);
            $this->assertTrue($firstAssignment, 'First slot assignment should succeed');

            // Try to assign second slot (should fail due to capacity)
            $trucker2 = $this->createTestTrucker('trucker2@test.com');
            $preAdvice2 = $this->createTestPreAdviceRequest($trucker2, $container, $activeTerminal);
            $this->entityManager->flush();

            $secondAssignment = $this->slotManagementService->assignSlot($activeTerminal, $tomorrow, $preAdvice2);
            $this->assertFalse($secondAssignment, 'Second slot assignment should fail due to capacity');

        } finally {
            $this->entityManager->rollback();
        }
    }

    /**
     * Create a test terminal
     */
    private function createTestTerminal(): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName('Test Terminal ' . uniqid())
            ->setType(TerminalType::CY)
            ->setLocation('Test Location')
            ->setDailyCapacity(50)
            ->setIsActive(true);

        $this->entityManager->persist($terminal);
        return $terminal;
    }

    /**
     * Create a test container
     */
    private function createTestContainer(
        string $containerNumber = null,
        string $size = '20ft',
        string $type = 'Dry',
        ContainerStatus $status = ContainerStatus::AVAILABLE_FOR_RETURN
    ): Container {
        $container = new Container();
        $container->setContainerNumber($containerNumber ?? 'TEST' . uniqid())
            ->setSize($size)
            ->setType($type)
            ->setStatus($status)
            ->setCurrentLocation('Test Location')
            ->setExpectedReturnDate(new \DateTime('+1 day'));

        $this->entityManager->persist($container);
        return $container;
    }

    /**
     * Create a test trucker
     */
    private function createTestTrucker(string $email = null): StaffUser
    {
        $trucker = new StaffUser();
        $trucker->setEmail($email ?? 'trucker' . uniqid() . '@test.com')
            ->setPasswordHash('hashed_password')
            ->setRole(UserRole::TRUCKER)
            ->setStatus(AccountStatus::ACTIVE)
            ->setFirstName('Test')
            ->setLastName('Trucker')
            ->setDepartment('Testing');

        $this->entityManager->persist($trucker);
        return $trucker;
    }

    /**
     * Create a test pre-advice request
     */
    private function createTestPreAdviceRequest(StaffUser $trucker, Container $container, Terminal $terminal): PreAdviceRequest
    {
        $preAdviceRequest = new PreAdviceRequest();
        $preAdviceRequest->setTrucker($trucker)
            ->setContainer($container)
            ->setSelectedTerminal($terminal)
            ->setPaymentReference('PAY' . uniqid())
            ->setStatus(PreAdviceStatus::PENDING);

        $this->entityManager->persist($preAdviceRequest);
        return $preAdviceRequest;
    }
}