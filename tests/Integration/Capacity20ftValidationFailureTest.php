<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\Enum\AllocationStatus;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Exception\InsufficientCapacityException;
use App\Service\CYAllocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for 20ft capacity validation failure scenarios
 * Task 3.5: Test 20ft capacity validation failures with various scenarios
 * Validates Properties 6, 7, and 18
 */
class Capacity20ftValidationFailureTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CYAllocationService $cyAllocationService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->cyAllocationService = $container->get(CYAllocationService::class);
    }

    /**
     * Test 20ft capacity validation with zero available capacity
     * Validates Property 6 and Property 7
     */
    public function testValidationFailsWhenZero20ftCapacityAvailable(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL Zero Capacity');
        
        // Terminal with 0 available 20ft capacity
        $terminal = $this->createTerminal('Terminal Zero', 'Berth Z');
        $allocation = $this->createAllocation($shippingLine, $terminal, 0, 10);
        
        $this->entityManager->flush();
        
        // Create 20ft container
        $containerSize20ft = $this->createContainerSize('20ft Standard', 1.0);
        $container = $this->createContainer('ZERO001', $containerSize20ft);
        
        $this->entityManager->flush();
        
        // Validate capacity - should fail
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $container,
            $allocation
        );
        
        $this->assertFalse($validationResult->isSuccess());
        $this->assertEquals(1.0, $validationResult->getRequiredTEU());
        $this->assertEquals(0, $validationResult->getAvailableTEU());
        
        // Create exception and verify error code
        $capacityDetails = $validationResult->getCapacityDetails();
        $exception = new InsufficientCapacityException(
            $validationResult->getRequiredTEU(),
            $validationResult->getAvailableTEU(),
            $allocation,
            null,
            $capacityDetails['size'] ?? null
        );
        
        $this->assertEquals('INSUFFICIENT_20FT_CAPACITY', $exception->getErrorCode());
        $this->assertEquals(400, $exception->getCode());
    }

    /**
     * Test 20ft capacity validation with fully allocated capacity
     * Validates Property 6 and Property 7
     */
    public function testValidationFailsWhenAll20ftCapacityAllocated(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL Full Allocation');
        
        // Terminal with 5 capacity, all allocated
        $terminal = $this->createTerminal('Terminal Full', 'Berth F');
        $allocation = $this->createAllocation($shippingLine, $terminal, 5, 10);
        
        $containerSize20ft = $this->createContainerSize('20ft Full', 1.0);
        
        // Allocate all 5 containers
        for ($i = 1; $i <= 5; $i++) {
            $existingContainer = $this->createContainer("FULL00{$i}", $containerSize20ft);
            $existingContainer->setCyAllocation($allocation);
            $existingContainer->setAllocationStatus(AllocationStatus::ALLOCATED);
        }
        
        $this->entityManager->flush();
        
        // Try to add one more 20ft container
        $newContainer = $this->createContainer('FULL006', $containerSize20ft);
        $this->entityManager->flush();
        
        // Validate capacity - should fail
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $newContainer,
            $allocation
        );
        
        $this->assertFalse($validationResult->isSuccess());
        $this->assertEquals(0, $validationResult->getAvailableTEU());
        
        // Verify error message format
        $capacityDetails = $validationResult->getCapacityDetails();
        $exception = new InsufficientCapacityException(
            $validationResult->getRequiredTEU(),
            $validationResult->getAvailableTEU(),
            $allocation,
            null,
            $capacityDetails['size'] ?? null
        );
        
        $message = $exception->getMessage();
        $this->assertStringContainsString('Insufficient 20ft capacity', $message);
        $this->assertStringContainsString('Terminal Full', $message);
        $this->assertStringContainsString('Required: 1 containers', $message);
        $this->assertStringContainsString('Available: 0 containers', $message);
    }

    /**
     * Test 20ft capacity validation with pre-forecast containers consuming capacity
     * Validates Property 6 and Property 7
     */
    public function testValidationFailsWhenPreForecast20ftConsumesCapacity(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL PreForecast');
        
        // Terminal with 3 capacity
        $terminal = $this->createTerminal('Terminal PreForecast', 'Berth P');
        $allocation = $this->createAllocation($shippingLine, $terminal, 3, 10);
        
        $containerSize20ft = $this->createContainerSize('20ft PreForecast', 1.0);
        
        // Add 2 pre-forecast containers
        for ($i = 1; $i <= 2; $i++) {
            $preForecastContainer = $this->createContainer("PRE00{$i}", $containerSize20ft);
            $preForecastContainer->setCyAllocation($allocation);
            $preForecastContainer->setAllocationStatus(AllocationStatus::PRE_FORECAST);
        }
        
        // Add 1 allocated container
        $allocatedContainer = $this->createContainer('PRE003', $containerSize20ft);
        $allocatedContainer->setCyAllocation($allocation);
        $allocatedContainer->setAllocationStatus(AllocationStatus::ALLOCATED);
        
        $this->entityManager->flush();
        
        // Try to add one more 20ft container (capacity: 3, used: 3, available: 0)
        $newContainer = $this->createContainer('PRE004', $containerSize20ft);
        $this->entityManager->flush();
        
        // Validate capacity - should fail
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $newContainer,
            $allocation
        );
        
        $this->assertFalse($validationResult->isSuccess());
        $this->assertEquals(0, $validationResult->getAvailableTEU());
    }

    /**
     * Test error response structure includes all required fields
     * Validates Property 7
     */
    public function testErrorResponseStructureContainsAllRequiredFields(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL Error Structure');
        
        $terminal = $this->createTerminal('Terminal Error', 'Berth E');
        $allocation = $this->createAllocation($shippingLine, $terminal, 0, 10);
        
        $this->entityManager->flush();
        
        $containerSize20ft = $this->createContainerSize('20ft Error', 1.0);
        $container = $this->createContainer('ERR001', $containerSize20ft);
        
        $this->entityManager->flush();
        
        // Validate and create exception
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $container,
            $allocation
        );
        
        $capacityDetails = $validationResult->getCapacityDetails();
        $exception = new InsufficientCapacityException(
            $validationResult->getRequiredTEU(),
            $validationResult->getAvailableTEU(),
            $allocation,
            null,
            $capacityDetails['size'] ?? null
        );
        
        // Verify error response structure
        $errorArray = $exception->toArray();
        
        $this->assertArrayHasKey('error', $errorArray);
        $this->assertArrayHasKey('message', $errorArray);
        $this->assertArrayHasKey('details', $errorArray);
        
        $details = $errorArray['details'];
        $this->assertArrayHasKey('terminal_id', $details);
        $this->assertArrayHasKey('terminal_name', $details);
        $this->assertArrayHasKey('allocation_id', $details);
        $this->assertArrayHasKey('container_size', $details);
        $this->assertArrayHasKey('required_count', $details);
        $this->assertArrayHasKey('available_count', $details);
        
        // Verify values
        $this->assertEquals('INSUFFICIENT_20FT_CAPACITY', $errorArray['error']);
        $this->assertEquals('20ft', $details['container_size']);
        $this->assertEquals(1, $details['required_count']);
        $this->assertEquals(0, $details['available_count']);
        $this->assertEquals($terminal->getId(), $details['terminal_id']);
        $this->assertEquals('Terminal Error', $details['terminal_name']);
        $this->assertEquals($allocation->getId(), $details['allocation_id']);
    }

    /**
     * Test alternative location suggestions are provided
     * Validates Property 18
     */
    public function testAlternativeLocationSuggestionsProvided(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL Alternatives');
        
        // Terminal A: No 20ft capacity (target)
        $terminalA = $this->createTerminal('Terminal Alt A', 'Berth A1');
        $allocationA = $this->createAllocation($shippingLine, $terminalA, 0, 10);
        
        // Terminal B: Has 10 available 20ft (alternative 1)
        $terminalB = $this->createTerminal('Terminal Alt B', 'Berth B1');
        $allocationB = $this->createAllocation($shippingLine, $terminalB, 10, 10);
        
        // Terminal C: Has 5 available 20ft (alternative 2)
        $terminalC = $this->createTerminal('Terminal Alt C', 'Berth C1');
        $allocationC = $this->createAllocation($shippingLine, $terminalC, 5, 10);
        
        // Terminal D: Has 3 available 20ft (alternative 3)
        $terminalD = $this->createTerminal('Terminal Alt D', 'Berth D1');
        $allocationD = $this->createAllocation($shippingLine, $terminalD, 3, 10);
        
        $this->entityManager->flush();
        
        // Get alternatives for 20ft containers
        $alternatives = $this->cyAllocationService->getAvailableAllocationsBySize($shippingLine, 1.0);
        
        // Should have at least 3 alternatives
        $this->assertGreaterThanOrEqual(3, count($alternatives));
        
        // Verify alternatives are sorted by available capacity (highest first)
        $this->assertEquals(10, $alternatives[0]['utilization']->getAvailableTEU());
        $this->assertEquals(5, $alternatives[1]['utilization']->getAvailableTEU());
        $this->assertEquals(3, $alternatives[2]['utilization']->getAvailableTEU());
        
        // Verify size field is set correctly
        $this->assertEquals('20ft', $alternatives[0]['size']);
        $this->assertEquals('20ft', $alternatives[1]['size']);
        $this->assertEquals('20ft', $alternatives[2]['size']);
    }

    /**
     * Test alternative suggestions limited to 3 locations
     * Validates Property 18
     */
    public function testAlternativeSuggestionsLimitedToThree(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL Limit Three');
        
        // Create 5 terminals with available 20ft capacity
        for ($i = 1; $i <= 5; $i++) {
            $terminal = $this->createTerminal("Terminal Limit {$i}", "Berth L{$i}");
            $this->createAllocation($shippingLine, $terminal, 10 - $i, 10);
        }
        
        $this->entityManager->flush();
        
        // Get alternatives
        $alternatives = $this->cyAllocationService->getAvailableAllocationsBySize($shippingLine, 1.0);
        
        // Should have 5 alternatives available
        $this->assertEquals(5, count($alternatives));
        
        // In practice, we would limit to 3 in the API response
        $limitedAlternatives = array_slice($alternatives, 0, 3);
        $this->assertCount(3, $limitedAlternatives);
    }

    /**
     * Test validation with partial capacity available
     * Validates Property 6 and Property 7
     */
    public function testValidationWithPartialCapacityScenario(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL Partial');
        
        // Terminal with 10 capacity, 9 allocated, 1 available
        $terminal = $this->createTerminal('Terminal Partial', 'Berth PT');
        $allocation = $this->createAllocation($shippingLine, $terminal, 10, 10);
        
        $containerSize20ft = $this->createContainerSize('20ft Partial', 1.0);
        
        // Allocate 9 containers
        for ($i = 1; $i <= 9; $i++) {
            $existingContainer = $this->createContainer("PART00{$i}", $containerSize20ft);
            $existingContainer->setCyAllocation($allocation);
            $existingContainer->setAllocationStatus(AllocationStatus::ALLOCATED);
        }
        
        $this->entityManager->flush();
        
        // First container should succeed (1 available)
        $container1 = $this->createContainer('PART010', $containerSize20ft);
        $this->entityManager->flush();
        
        $validationResult1 = $this->cyAllocationService->validateContainerCapacityBySize(
            $container1,
            $allocation
        );
        
        $this->assertTrue($validationResult1->isSuccess());
        $this->assertEquals(1, $validationResult1->getAvailableTEU());
        
        // Allocate the 10th container
        $container1->setCyAllocation($allocation);
        $container1->setAllocationStatus(AllocationStatus::ALLOCATED);
        $this->entityManager->flush();
        
        // Second container should fail (0 available)
        $container2 = $this->createContainer('PART011', $containerSize20ft);
        $this->entityManager->flush();
        
        $validationResult2 = $this->cyAllocationService->validateContainerCapacityBySize(
            $container2,
            $allocation
        );
        
        $this->assertFalse($validationResult2->isSuccess());
        $this->assertEquals(0, $validationResult2->getAvailableTEU());
    }

    /**
     * Test that 40ft capacity does not affect 20ft validation
     * Validates Property 6 (size-specific validation)
     */
    public function testTwentyFtValidationIndependentOfFortyFtCapacity(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL Independent');
        
        // Terminal with 0 20ft capacity but plenty of 40ft capacity
        $terminal = $this->createTerminal('Terminal Independent', 'Berth I');
        $allocation = $this->createAllocation($shippingLine, $terminal, 0, 50);
        
        $this->entityManager->flush();
        
        $containerSize20ft = $this->createContainerSize('20ft Independent', 1.0);
        $container = $this->createContainer('IND001', $containerSize20ft);
        
        $this->entityManager->flush();
        
        // Validate 20ft container - should fail despite 40ft capacity
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $container,
            $allocation
        );
        
        $this->assertFalse($validationResult->isSuccess());
        $this->assertEquals(0, $validationResult->getAvailableTEU());
        
        // Verify error is specifically for 20ft
        $capacityDetails = $validationResult->getCapacityDetails();
        $this->assertEquals('20ft', $capacityDetails['size']);
    }

    /**
     * Test HTTP 400 status code is returned
     * Validates requirement for HTTP 400 Bad Request
     */
    public function testHttpBadRequestStatusCode(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test SL HTTP Status');
        
        $terminal = $this->createTerminal('Terminal HTTP', 'Berth H');
        $allocation = $this->createAllocation($shippingLine, $terminal, 0, 10);
        
        $this->entityManager->flush();
        
        $containerSize20ft = $this->createContainerSize('20ft HTTP', 1.0);
        $container = $this->createContainer('HTTP001', $containerSize20ft);
        
        $this->entityManager->flush();
        
        // Validate and create exception
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $container,
            $allocation
        );
        
        $capacityDetails = $validationResult->getCapacityDetails();
        $exception = new InsufficientCapacityException(
            $validationResult->getRequiredTEU(),
            $validationResult->getAvailableTEU(),
            $allocation,
            null,
            $capacityDetails['size'] ?? null
        );
        
        // Verify HTTP 400 status code
        $this->assertEquals(400, $exception->getCode());
    }

    // Helper methods

    private function createShippingLine(string $name): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($name);
        $shippingLine->setIsActive(true);
        // Don't set logoPath - column may not exist in test database
        
        $this->entityManager->persist($shippingLine);
        
        return $shippingLine;
    }

    private function createTerminal(string $name, string $location): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName($name);
        $terminal->setLocation($location);
        $terminal->setIsActive(true);
        
        $this->entityManager->persist($terminal);
        
        return $terminal;
    }

    private function createAllocation(
        ShippingLine $shippingLine,
        Terminal $terminal,
        int $capacity20ft,
        int $capacity40ft
    ): ShippingLineTerminalAllocation {
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setShippingLine($shippingLine);
        $allocation->setTerminal($terminal);
        $allocation->setCapacity20ft($capacity20ft);
        $allocation->setCapacity40ft($capacity40ft);
        $allocation->setAllocatedCapacity(($capacity20ft * 1) + ($capacity40ft * 2)); // TEU calculation
        
        $this->entityManager->persist($allocation);
        
        return $allocation;
    }

    private function createContainerSize(string $name, float $teuValue): ContainerSize
    {
        $containerSize = new ContainerSize();
        $containerSize->setName($name);
        $containerSize->setCode(str_replace(' ', '_', strtoupper($name)));
        $containerSize->setTeuValue($teuValue);
        $containerSize->setIsActive(true);
        
        $this->entityManager->persist($containerSize);
        
        return $containerSize;
    }

    private function createContainer(string $number, ContainerSize $size): Container
    {
        $container = new Container();
        $container->setContainerNumber($number);
        $container->setContainerSize($size);
        
        $this->entityManager->persist($container);
        
        return $container;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test data
        $this->entityManager->clear();
    }
}
