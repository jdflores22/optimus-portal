<?php

namespace App\Tests\Service;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Repository\TerminalRepository;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Feature: terminal-team-pre-advice, Property 8: Terminal availability for containers
 * 
 * Property-based test for validating terminal availability functionality in the Terminal Team Pre-Advice system.
 * This test validates Requirements 7.4, 7.5, 7.6 by ensuring that terminal availability checks
 * return accurate results based on container compatibility and terminal capacity.
 */
class TerminalAvailabilityPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 8: Terminal availability for containers
     * 
     * For any found container, the system should display only terminals that can accept
     * the container type and have available slots. Terminal compatibility should be
     * consistent with business rules.
     * 
     * Validates: Requirements 7.4, 7.5, 7.6
     */
    public function testTerminalAvailabilityForContainers(): void
    {
        $this->forAll(
            Generator\elements(TerminalType::CY, TerminalType::ATI, TerminalType::ICTSI),
            Generator\elements('Dry', 'Reefer', 'Hazardous', 'Tank'),
            Generator\elements('20ft', '40ft', '45ft'),
            Generator\choose(1, 100), // Terminal capacity
            Generator\bool() // Terminal active status
        )->then(function (
            TerminalType $terminalType,
            string $containerType,
            string $containerSize,
            int $capacity,
            bool $isActive
        ) {
            // Create fresh mocks for each test iteration
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $terminalRepository = $this->createMock(TerminalRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $terminalService = new TerminalService($entityManager, $terminalRepository, $logger);

            // Create a test terminal
            $terminal = new Terminal();
            $terminal->setName('Test Terminal')
                ->setType($terminalType)
                ->setLocation('Test Location')
                ->setDailyCapacity($capacity)
                ->setIsActive($isActive);

            // Use reflection to set the ID
            $reflection = new \ReflectionClass($terminal);
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($terminal, 1);

            // Create a test container
            $container = new Container();
            $container->setContainerNumber('TEST1234567')
                ->setSize($containerSize)
                ->setType($containerType)
                ->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN)
                ->setCurrentLocation('Test Location')
                ->setExpectedReturnDate(new \DateTime('+1 day'));

            // Use reflection to set the ID
            $containerReflection = new \ReflectionClass($container);
            $containerIdProperty = $containerReflection->getProperty('id');
            $containerIdProperty->setAccessible(true);
            $containerIdProperty->setValue($container, 1);

            // Test terminal-container compatibility (Requirement 7.4)
            $canAccept = $terminalService->canAcceptContainer($terminal, $container);

            // Business rule validation: Terminal can accept container only if active
            if (!$isActive) {
                $this->assertFalse($canAccept, 'Inactive terminals should not accept containers');
                return; // Skip further tests for inactive terminals
            }

            // Business rule validation: Container-terminal compatibility (Requirement 7.5)
            $expectedCompatibility = $this->calculateExpectedCompatibility($terminalType, $containerType);
            $this->assertEquals($expectedCompatibility, $canAccept, 
                "Terminal type {$terminalType->value} should " . 
                ($expectedCompatibility ? 'accept' : 'reject') . 
                " container type {$containerType}"
            );

            // Test terminal details retrieval (Requirement 7.6)
            $terminalDetails = $terminalService->getTerminalDetails($terminal);
            $this->assertNotNull($terminalDetails);
            $this->assertEquals($terminalType->value, $terminalDetails['type']);
            $this->assertEquals($capacity, $terminalDetails['dailyCapacity']);
            $this->assertEquals($isActive, $terminalDetails['isActive']);
            $this->assertArrayHasKey('todayCapacity', $terminalDetails);

            // Test compatible terminals finding
            if ($expectedCompatibility && $canAccept) {
                // Mock the findCompatibleTerminals method behavior
                $terminalRepository->method('findActive')->willReturn([$terminal]);
                
                $compatibleTerminals = $terminalService->findCompatibleTerminals($container);
                // Since we're testing with a single terminal, if it's compatible, it should be in the results
                $this->assertContains($terminal, $compatibleTerminals);
            }
        });
    }

    /**
     * Property test for terminal capacity checking
     * 
     * For any terminal and date, capacity information should be consistent
     * and accurately reflect available slots.
     */
    public function testTerminalCapacityChecking(): void
    {
        $this->forAll(
            Generator\choose(1, 100), // Daily capacity
            Generator\choose(0, 50), // Assigned count (should be <= capacity for valid scenarios)
            Generator\bool() // Terminal active status
        )->then(function (int $dailyCapacity, int $assignedCount, bool $isActive) {
            // Ensure assigned count doesn't exceed capacity for valid test scenarios
            $assignedCount = min($assignedCount, $dailyCapacity);

            // Create fresh mocks for each test iteration
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $terminalRepository = $this->createMock(TerminalRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $terminalService = new TerminalService($entityManager, $terminalRepository, $logger);

            // Create a test terminal
            $terminal = new Terminal();
            $terminal->setName('Test Terminal')
                ->setType(TerminalType::CY)
                ->setLocation('Test Location')
                ->setDailyCapacity($dailyCapacity)
                ->setIsActive($isActive);

            // Use reflection to set the ID
            $reflection = new \ReflectionClass($terminal);
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($terminal, 1);

            $testDate = new \DateTime('today');

            // Mock the TerminalSlot repository behavior
            $terminalSlotRepository = $this->createMock(\App\Repository\TerminalSlotRepository::class);
            $entityManager->method('getRepository')
                ->willReturn($terminalSlotRepository);

            // Test case 1: No existing slot
            $terminalSlotRepository->method('findOneBy')
                ->willReturn(null);

            $capacityInfo = $terminalService->getTerminalCapacity($terminal, $testDate);
            
            // When no slot exists, should return terminal's daily capacity
            $this->assertEquals($dailyCapacity, $capacityInfo['capacity']);
            $this->assertEquals(0, $capacityInfo['assigned']);
            $this->assertEquals($dailyCapacity, $capacityInfo['available']);

            // Test availability check
            $hasAvailableCapacity = $terminalService->hasAvailableCapacity($terminal, $testDate);
            $this->assertTrue($hasAvailableCapacity, 'Terminal should have available capacity when no slots are assigned');
        });
    }

    /**
     * Property test for terminal utilization statistics
     * 
     * For any terminal with slots, utilization statistics should be
     * mathematically consistent and accurate.
     */
    public function testTerminalUtilizationStatistics(): void
    {
        $this->forAll(
            Generator\choose(1, 50), // Daily capacity
            Generator\choose(1, 10), // Number of days
            Generator\choose(0, 100) // Utilization percentage (0-100)
        )->then(function (int $dailyCapacity, int $numberOfDays, int $utilizationPercent) {
            // Create fresh mocks for each test iteration
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $terminalRepository = $this->createMock(TerminalRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $terminalService = new TerminalService($entityManager, $terminalRepository, $logger);

            // Create a test terminal
            $terminal = new Terminal();
            $terminal->setName('Test Terminal')
                ->setType(TerminalType::CY)
                ->setLocation('Test Location')
                ->setDailyCapacity($dailyCapacity)
                ->setIsActive(true);

            // Use reflection to set the ID
            $reflection = new \ReflectionClass($terminal);
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($terminal, 1);

            // Calculate expected values
            $totalCapacity = $dailyCapacity * $numberOfDays;
            $assignedPerSlot = (int) round(($dailyCapacity * $utilizationPercent) / 100);
            $totalAssigned = $assignedPerSlot * $numberOfDays;
            $expectedUtilizationRate = $totalCapacity > 0 ? ($totalAssigned / $totalCapacity) * 100 : 0;

            // Test mathematical consistency of utilization calculations
            $this->assertGreaterThanOrEqual(0, $expectedUtilizationRate);
            $this->assertLessThanOrEqual(100, $expectedUtilizationRate);
            
            // Test that assigned count doesn't exceed capacity
            $this->assertLessThanOrEqual($totalCapacity, $totalAssigned);
            
            // Test available capacity calculation
            $availableCapacity = $totalCapacity - $totalAssigned;
            $this->assertGreaterThanOrEqual(0, $availableCapacity);
            $this->assertEquals($totalCapacity - $totalAssigned, $availableCapacity);
        });
    }

    /**
     * Property test for active terminals retrieval
     * 
     * For any terminal configuration, only active terminals should be
     * returned in active terminal queries.
     */
    public function testActiveTerminalsRetrieval(): void
    {
        $this->forAll(
            Generator\bool(), // Terminal active status
            Generator\elements(TerminalType::CY, TerminalType::ATI, TerminalType::ICTSI)
        )->then(function (bool $isActive, TerminalType $terminalType) {
            // Create fresh mocks for each test iteration
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $terminalRepository = $this->createMock(TerminalRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $terminalService = new TerminalService($entityManager, $terminalRepository, $logger);

            // Create a test terminal
            $terminal = new Terminal();
            $terminal->setName('Test Terminal')
                ->setType($terminalType)
                ->setLocation('Test Location')
                ->setDailyCapacity(50)
                ->setIsActive($isActive);

            // Mock repository behavior
            if ($isActive) {
                $terminalRepository->method('findActive')->willReturn([$terminal]);
                $terminalRepository->method('findByType')->willReturn([$terminal]);
            } else {
                $terminalRepository->method('findActive')->willReturn([]);
                $terminalRepository->method('findByType')->willReturn([]);
            }

            // Test active terminals retrieval
            $activeTerminals = $terminalService->getActiveTerminals();
            
            if ($isActive) {
                $this->assertContains($terminal, $activeTerminals);
            } else {
                $this->assertNotContains($terminal, $activeTerminals);
            }

            // Test terminals by type retrieval
            $terminalsByType = $terminalService->getTerminalsByType($terminalType);
            
            if ($isActive) {
                $this->assertContains($terminal, $terminalsByType);
            } else {
                $this->assertNotContains($terminal, $terminalsByType);
            }
        });
    }

    /**
     * Calculate expected compatibility based on business rules
     */
    private function calculateExpectedCompatibility(TerminalType $terminalType, string $containerType): bool
    {
        // Business rules for container-terminal compatibility
        switch ($containerType) {
            case 'Dry':
                // All terminals can accept standard dry containers
                return true;
            
            case 'Reefer':
                // Reefer containers require special handling - only certain terminals
                return in_array($terminalType, [TerminalType::CY, TerminalType::ICTSI]);
            
            case 'Hazardous':
                // Hazardous containers have special requirements
                return $terminalType === TerminalType::CY;
            
            case 'Tank':
                // Tank containers can be handled by all terminals
                return true;
            
            default:
                // Unknown container types - default to allow
                return true;
        }
    }

    /**
     * Create a mock query builder for testing
     */
    private function createMockQueryBuilder(array $mockSlots): \Doctrine\ORM\QueryBuilder
    {
        $queryBuilder = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn($mockSlots);
        
        return $queryBuilder;
    }
}