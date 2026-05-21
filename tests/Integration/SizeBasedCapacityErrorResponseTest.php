<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Exception\InsufficientCapacityException;
use App\Service\CYAllocationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for size-based capacity error responses
 * Task 3.2: Test error response structure with alternative locations
 * Validates Property 7 and Property 18
 */
class SizeBasedCapacityErrorResponseTest extends KernelTestCase
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
     * Test 20ft capacity error with alternative suggestions
     * Validates Property 7 and Property 18
     */
    public function testInsufficientCapacityErrorFor20ftWithAlternatives(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test Shipping Line');
        
        // Terminal A: No 20ft capacity
        $terminalA = $this->createTerminal('Terminal A', 'Berth 1');
        $allocationA = $this->createAllocation($shippingLine, $terminalA, 0, 10);
        
        // Terminal B: Has 20ft capacity (alternative)
        $terminalB = $this->createTerminal('Terminal B', 'Berth 2');
        $allocationB = $this->createAllocation($shippingLine, $terminalB, 5, 10);
        
        // Terminal C: Has 20ft capacity (alternative)
        $terminalC = $this->createTerminal('Terminal C', 'Berth 3');
        $allocationC = $this->createAllocation($shippingLine, $terminalC, 3, 10);
        
        $this->entityManager->flush();
        
        // Create 20ft container
        $containerSize20ft = $this->createContainerSize('20ft', 1.0);
        $container = $this->createContainer('TEST001', $containerSize20ft);
        
        $this->entityManager->flush();
        
        // Validate capacity - should fail for Terminal A
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $container,
            $allocationA
        );
        
        $this->assertFalse($validationResult->isSuccess());
        
        // Create exception
        $capacityDetails = $validationResult->getCapacityDetails();
        $exception = new InsufficientCapacityException(
            $validationResult->getRequiredTEU(),
            $validationResult->getAvailableTEU(),
            $allocationA,
            null,
            $capacityDetails['size'] ?? null
        );
        
        // Verify error code
        $this->assertEquals('INSUFFICIENT_20FT_CAPACITY', $exception->getErrorCode());
        
        // Verify error message format
        $message = $exception->getMessage();
        $this->assertStringContainsString('Insufficient 20ft capacity', $message);
        $this->assertStringContainsString('Terminal A', $message);
        $this->assertStringContainsString('Required: 1 containers', $message);
        $this->assertStringContainsString('Available: 0 containers', $message);
        
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
        
        $this->assertEquals('20ft', $details['container_size']);
        $this->assertEquals(1, $details['required_count']);
        $this->assertEquals(0, $details['available_count']);
        
        // Verify alternatives can be retrieved
        $alternatives = $this->cyAllocationService->getAvailableAllocationsBySize($shippingLine, 1.0);
        
        $this->assertNotEmpty($alternatives);
        $this->assertGreaterThanOrEqual(2, count($alternatives)); // Should have Terminal B and C
        
        // Verify alternatives are sorted by available capacity
        $firstAlt = $alternatives[0];
        $this->assertEquals(5, $firstAlt['utilization']->getAvailableTEU()); // Terminal B has more
    }

    /**
     * Test 40ft capacity error with alternative suggestions
     * Validates Property 7 and Property 18
     */
    public function testInsufficientCapacityErrorFor40ftWithAlternatives(): void
    {
        // Create test data
        $shippingLine = $this->createShippingLine('Test Shipping Line 2');
        
        // Terminal A: No 40ft capacity
        $terminalA = $this->createTerminal('Terminal A2', 'Berth 1');
        $allocationA = $this->createAllocation($shippingLine, $terminalA, 10, 0);
        
        // Terminal B: Has 40ft capacity (alternative)
        $terminalB = $this->createTerminal('Terminal B2', 'Berth 2');
        $allocationB = $this->createAllocation($shippingLine, $terminalB, 10, 8);
        
        // Terminal C: Has 40ft capacity (alternative)
        $terminalC = $this->createTerminal('Terminal C2', 'Berth 3');
        $allocationC = $this->createAllocation($shippingLine, $terminalC, 10, 5);
        
        $this->entityManager->flush();
        
        // Create 40ft container
        $containerSize40ft = $this->createContainerSize('40ft', 2.0);
        $container = $this->createContainer('TEST002', $containerSize40ft);
        
        $this->entityManager->flush();
        
        // Validate capacity - should fail for Terminal A
        $validationResult = $this->cyAllocationService->validateContainerCapacityBySize(
            $container,
            $allocationA
        );
        
        $this->assertFalse($validationResult->isSuccess());
        
        // Create exception
        $capacityDetails = $validationResult->getCapacityDetails();
        $exception = new InsufficientCapacityException(
            $validationResult->getRequiredTEU(),
            $validationResult->getAvailableTEU(),
            $allocationA,
            null,
            $capacityDetails['size'] ?? null
        );
        
        // Verify error code
        $this->assertEquals('INSUFFICIENT_40FT_CAPACITY', $exception->getErrorCode());
        
        // Verify error message format
        $message = $exception->getMessage();
        $this->assertStringContainsString('Insufficient 40ft capacity', $message);
        $this->assertStringContainsString('Terminal A2', $message);
        $this->assertStringContainsString('Required: 1 containers', $message);
        $this->assertStringContainsString('Available: 0 containers', $message);
        
        // Verify error response structure
        $errorArray = $exception->toArray();
        $details = $errorArray['details'];
        
        $this->assertEquals('40ft', $details['container_size']);
        $this->assertEquals(1, $details['required_count']);
        $this->assertEquals(0, $details['available_count']);
        
        // Verify alternatives can be retrieved
        $alternatives = $this->cyAllocationService->getAvailableAllocationsBySize($shippingLine, 2.0);
        
        $this->assertNotEmpty($alternatives);
        $this->assertGreaterThanOrEqual(2, count($alternatives)); // Should have Terminal B and C
        
        // Verify alternatives are sorted by available capacity
        $firstAlt = $alternatives[0];
        $this->assertEquals(8, $firstAlt['utilization']->getAvailableTEU()); // Terminal B has more
    }

    // Helper methods

    private function createShippingLine(string $name): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setName($name);
        $shippingLine->setCode(strtoupper(substr($name, 0, 3)));
        $shippingLine->setIsActive(true);
        
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
        $containerSize->setCode($name);
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
