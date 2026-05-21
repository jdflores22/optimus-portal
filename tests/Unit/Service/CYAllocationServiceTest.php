<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Entity\Enum\AllocationStatus;
use App\Service\CYAllocationService;
use App\Service\ConfigurationService;
use App\ValueObject\UtilizationData;
use App\ValueObject\ValidationResult;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Unit tests for CYAllocationService size-specific methods
 * Task 1: Enhance CYAllocationService with Size-Specific Methods
 */
class CYAllocationServiceTest extends TestCase
{
    private CYAllocationService $service;
    private EntityManagerInterface $entityManager;
    private ConfigurationService $configurationService;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->cache = $this->createMock(CacheInterface::class);
        
        $this->service = new CYAllocationService(
            $this->entityManager,
            $this->configurationService,
            $this->cache
        );
    }

    /**
     * Task 1.5: Test calculateUtilizationBySize with 20ft containers only
     */
    public function testCalculateUtilizationBySize20ftOnly(): void
    {
        $allocation = $this->createAllocation(50, 25);
        
        // Add 15 allocated 20ft containers
        for ($i = 0; $i < 15; $i++) {
            $container = $this->createContainer(1.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        // Add 10 pre-forecast 20ft containers
        for ($i = 0; $i < 10; $i++) {
            $container = $this->createContainer(1.0, AllocationStatus::PRE_FORECAST);
            $allocation->getContainers()->add($container);
        }
        
        $result = $this->service->calculateUtilizationBySize($allocation);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('20ft', $result);
        $this->assertArrayHasKey('40ft', $result);
        
        // Verify 20ft utilization
        $utilization20ft = $result['20ft'];
        $this->assertInstanceOf(UtilizationData::class, $utilization20ft);
        $this->assertEquals(25, $utilization20ft->getUsedTEU()); // 15 + 10
        $this->assertEquals(25, $utilization20ft->getAvailableTEU()); // 50 - 25
        $this->assertEquals(50, $utilization20ft->getTotalCapacityTEU());
        $this->assertEquals(50.0, $utilization20ft->getUtilizationPercentage()); // (25/50) * 100
        $this->assertEquals(25, $utilization20ft->getContainerCount());
        
        // Verify 40ft utilization (should be empty)
        $utilization40ft = $result['40ft'];
        $this->assertInstanceOf(UtilizationData::class, $utilization40ft);
        $this->assertEquals(0, $utilization40ft->getUsedTEU());
        $this->assertEquals(25, $utilization40ft->getAvailableTEU());
        $this->assertEquals(25, $utilization40ft->getTotalCapacityTEU());
        $this->assertEquals(0.0, $utilization40ft->getUtilizationPercentage());
    }

    /**
     * Task 1.5: Test calculateUtilizationBySize with 40ft containers only
     */
    public function testCalculateUtilizationBySize40ftOnly(): void
    {
        $allocation = $this->createAllocation(50, 25);
        
        // Add 15 allocated 40ft containers
        for ($i = 0; $i < 15; $i++) {
            $container = $this->createContainer(2.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        // Add 10 pre-forecast 40ft containers
        for ($i = 0; $i < 10; $i++) {
            $container = $this->createContainer(2.0, AllocationStatus::PRE_FORECAST);
            $allocation->getContainers()->add($container);
        }
        
        $result = $this->service->calculateUtilizationBySize($allocation);
        
        // Verify 20ft utilization (should be empty)
        $utilization20ft = $result['20ft'];
        $this->assertEquals(0, $utilization20ft->getUsedTEU());
        $this->assertEquals(50, $utilization20ft->getAvailableTEU());
        
        // Verify 40ft utilization
        $utilization40ft = $result['40ft'];
        $this->assertEquals(25, $utilization40ft->getUsedTEU()); // 15 + 10
        $this->assertEquals(0, $utilization40ft->getAvailableTEU()); // 25 - 25
        $this->assertEquals(25, $utilization40ft->getTotalCapacityTEU());
        $this->assertEquals(100.0, $utilization40ft->getUtilizationPercentage()); // (25/25) * 100
    }

    /**
     * Task 1.5: Test calculateUtilizationBySize with mixed 20ft and 40ft containers
     */
    public function testCalculateUtilizationBySizeMixed(): void
    {
        $allocation = $this->createAllocation(50, 25);
        
        // Add 10 allocated 20ft containers
        for ($i = 0; $i < 10; $i++) {
            $container = $this->createContainer(1.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        // Add 5 pre-forecast 20ft containers
        for ($i = 0; $i < 5; $i++) {
            $container = $this->createContainer(1.0, AllocationStatus::PRE_FORECAST);
            $allocation->getContainers()->add($container);
        }
        
        // Add 8 allocated 40ft containers
        for ($i = 0; $i < 8; $i++) {
            $container = $this->createContainer(2.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        // Add 7 pre-forecast 40ft containers
        for ($i = 0; $i < 7; $i++) {
            $container = $this->createContainer(2.0, AllocationStatus::PRE_FORECAST);
            $allocation->getContainers()->add($container);
        }
        
        $result = $this->service->calculateUtilizationBySize($allocation);
        
        // Verify 20ft utilization
        $utilization20ft = $result['20ft'];
        $this->assertEquals(15, $utilization20ft->getUsedTEU()); // 10 + 5
        $this->assertEquals(35, $utilization20ft->getAvailableTEU()); // 50 - 15
        $this->assertEquals(30.0, $utilization20ft->getUtilizationPercentage()); // (15/50) * 100
        
        // Verify 40ft utilization
        $utilization40ft = $result['40ft'];
        $this->assertEquals(15, $utilization40ft->getUsedTEU()); // 8 + 7
        $this->assertEquals(10, $utilization40ft->getAvailableTEU()); // 25 - 15
        $this->assertEquals(60.0, $utilization40ft->getUtilizationPercentage()); // (15/25) * 100
    }

    /**
     * Task 1.6: Test validateContainerCapacityBySize for 20ft container with sufficient capacity
     */
    public function testValidateContainerCapacityBySize20ftSuccess(): void
    {
        $allocation = $this->createAllocation(50, 25);
        $terminal = $this->createTerminal('Terminal A');
        $allocation->setTerminal($terminal);
        
        // Add 10 containers (leaving 40 available)
        for ($i = 0; $i < 10; $i++) {
            $container = $this->createContainer(1.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        $newContainer = $this->createContainer(1.0, AllocationStatus::PRE_FORECAST);
        
        $result = $this->service->validateContainerCapacityBySize($newContainer, $allocation);
        
        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('Sufficient 20ft capacity available', $result->getMessage());
    }

    /**
     * Task 1.6: Test validateContainerCapacityBySize for 20ft container with insufficient capacity
     */
    public function testValidateContainerCapacityBySize20ftFailure(): void
    {
        $allocation = $this->createAllocation(50, 25);
        $terminal = $this->createTerminal('Terminal A');
        $allocation->setTerminal($terminal);
        
        // Fill all 50 20ft slots
        for ($i = 0; $i < 50; $i++) {
            $container = $this->createContainer(1.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        $newContainer = $this->createContainer(1.0, AllocationStatus::PRE_FORECAST);
        
        $result = $this->service->validateContainerCapacityBySize($newContainer, $allocation);
        
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Insufficient 20ft capacity at Terminal A', $result->getMessage());
        $this->assertStringContainsString('Required: 1 container', $result->getMessage());
        $this->assertStringContainsString('Available: 0 containers', $result->getMessage());
        
        $details = $result->getCapacityDetails();
        $this->assertEquals('20ft', $details['size']);
        $this->assertEquals('Terminal A', $details['terminal_name']);
    }

    /**
     * Task 1.6: Test validateContainerCapacityBySize for 40ft container with sufficient capacity
     */
    public function testValidateContainerCapacityBySize40ftSuccess(): void
    {
        $allocation = $this->createAllocation(50, 25);
        $terminal = $this->createTerminal('Terminal B');
        $allocation->setTerminal($terminal);
        
        // Add 10 40ft containers (leaving 15 available)
        for ($i = 0; $i < 10; $i++) {
            $container = $this->createContainer(2.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        $newContainer = $this->createContainer(2.0, AllocationStatus::PRE_FORECAST);
        
        $result = $this->service->validateContainerCapacityBySize($newContainer, $allocation);
        
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('Sufficient 40ft capacity available', $result->getMessage());
    }

    /**
     * Task 1.6: Test validateContainerCapacityBySize for 40ft container with insufficient capacity
     */
    public function testValidateContainerCapacityBySize40ftFailure(): void
    {
        $allocation = $this->createAllocation(50, 25);
        $terminal = $this->createTerminal('Terminal B');
        $allocation->setTerminal($terminal);
        
        // Fill all 25 40ft slots
        for ($i = 0; $i < 25; $i++) {
            $container = $this->createContainer(2.0, AllocationStatus::ALLOCATED);
            $allocation->getContainers()->add($container);
        }
        
        $newContainer = $this->createContainer(2.0, AllocationStatus::PRE_FORECAST);
        
        $result = $this->service->validateContainerCapacityBySize($newContainer, $allocation);
        
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Insufficient 40ft capacity at Terminal B', $result->getMessage());
        $this->assertStringContainsString('Required: 1 container', $result->getMessage());
        $this->assertStringContainsString('Available: 0 containers', $result->getMessage());
        
        $details = $result->getCapacityDetails();
        $this->assertEquals('40ft', $details['size']);
    }

    /**
     * Task 1.7: Test getAvailableAllocationsBySize for 20ft containers
     */
    public function testGetAvailableAllocationsBySize20ft(): void
    {
        $shippingLine = $this->createShippingLine();
        
        // Mock getAvailableAllocations to return test allocations
        $allocation1 = $this->createAllocation(50, 25);
        $allocation1->setTerminal($this->createTerminal('Terminal A'));
        $allocation1->setShippingLine($shippingLine);
        // 10 used, 40 available
        for ($i = 0; $i < 10; $i++) {
            $allocation1->getContainers()->add($this->createContainer(1.0, AllocationStatus::ALLOCATED));
        }
        
        $allocation2 = $this->createAllocation(30, 20);
        $allocation2->setTerminal($this->createTerminal('Terminal B'));
        $allocation2->setShippingLine($shippingLine);
        // 25 used, 5 available
        for ($i = 0; $i < 25; $i++) {
            $allocation2->getContainers()->add($this->createContainer(1.0, AllocationStatus::ALLOCATED));
        }
        
        $allocation3 = $this->createAllocation(20, 15);
        $allocation3->setTerminal($this->createTerminal('Terminal C'));
        $allocation3->setShippingLine($shippingLine);
        // 20 used, 0 available (should be filtered out)
        for ($i = 0; $i < 20; $i++) {
            $allocation3->getContainers()->add($this->createContainer(1.0, AllocationStatus::ALLOCATED));
        }
        
        // Mock cache to bypass caching and return the allocations
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) use ($allocation1, $allocation2, $allocation3) {
            // For the available allocations cache call
            if (strpos($key, 'available_') !== false) {
                return [
                    ['allocation' => $allocation1, 'utilization' => new UtilizationData(10, 40, 50, 20, 10)],
                    ['allocation' => $allocation2, 'utilization' => new UtilizationData(25, 5, 30, 83.33, 25)],
                    ['allocation' => $allocation3, 'utilization' => new UtilizationData(20, 0, 20, 100, 20)],
                ];
            }
            return $callback($this->createMock(\Symfony\Contracts\Cache\ItemInterface::class));
        });
        
        $result = $this->service->getAvailableAllocationsBySize($shippingLine, 1.0);
        
        // Should return 2 allocations (allocation3 filtered out due to 0 availability)
        $this->assertCount(2, $result);
        
        // Should be sorted by available capacity (highest first)
        $this->assertEquals(40, $result[0]['utilization']->getAvailableTEU());
        $this->assertEquals('20ft', $result[0]['size']);
        $this->assertEquals(5, $result[1]['utilization']->getAvailableTEU());
        $this->assertEquals('20ft', $result[1]['size']);
    }

    /**
     * Task 1.7: Test getAvailableAllocationsBySize for 40ft containers
     */
    public function testGetAvailableAllocationsBySize40ft(): void
    {
        $shippingLine = $this->createShippingLine();
        
        $allocation1 = $this->createAllocation(50, 25);
        $allocation1->setTerminal($this->createTerminal('Terminal A'));
        $allocation1->setShippingLine($shippingLine);
        // 20 40ft used, 5 available
        for ($i = 0; $i < 20; $i++) {
            $allocation1->getContainers()->add($this->createContainer(2.0, AllocationStatus::ALLOCATED));
        }
        
        $allocation2 = $this->createAllocation(30, 20);
        $allocation2->setTerminal($this->createTerminal('Terminal B'));
        $allocation2->setShippingLine($shippingLine);
        // 10 40ft used, 10 available
        for ($i = 0; $i < 10; $i++) {
            $allocation2->getContainers()->add($this->createContainer(2.0, AllocationStatus::ALLOCATED));
        }
        
        // Mock cache to return the allocations
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) use ($allocation1, $allocation2) {
            // For the available allocations cache call
            if (strpos($key, 'available_') !== false) {
                return [
                    ['allocation' => $allocation1, 'utilization' => new UtilizationData(20, 5, 25, 80, 20)],
                    ['allocation' => $allocation2, 'utilization' => new UtilizationData(10, 10, 20, 50, 10)],
                ];
            }
            return $callback($this->createMock(\Symfony\Contracts\Cache\ItemInterface::class));
        });
        
        $result = $this->service->getAvailableAllocationsBySize($shippingLine, 2.0);
        
        $this->assertCount(2, $result);
        
        // Should be sorted by available capacity (highest first)
        $this->assertEquals(10, $result[0]['utilization']->getAvailableTEU());
        $this->assertEquals('40ft', $result[0]['size']);
        $this->assertEquals(5, $result[1]['utilization']->getAvailableTEU());
        $this->assertEquals('40ft', $result[1]['size']);
    }

    /**
     * Task 1.7: Test getAvailableAllocationsBySize filters out allocations with no capacity
     */
    public function testGetAvailableAllocationsBySizeFiltersFullAllocations(): void
    {
        $shippingLine = $this->createShippingLine();
        
        $allocation1 = $this->createAllocation(50, 25);
        $allocation1->setTerminal($this->createTerminal('Terminal A'));
        $allocation1->setShippingLine($shippingLine);
        // Fill all 50 20ft slots
        for ($i = 0; $i < 50; $i++) {
            $allocation1->getContainers()->add($this->createContainer(1.0, AllocationStatus::ALLOCATED));
        }
        
        $allocation2 = $this->createAllocation(30, 20);
        $allocation2->setTerminal($this->createTerminal('Terminal B'));
        $allocation2->setShippingLine($shippingLine);
        // Fill all 30 20ft slots
        for ($i = 0; $i < 30; $i++) {
            $allocation2->getContainers()->add($this->createContainer(1.0, AllocationStatus::ALLOCATED));
        }
        
        // Mock cache to return the allocations
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) use ($allocation1, $allocation2) {
            // For the available allocations cache call
            if (strpos($key, 'available_') !== false) {
                return [
                    ['allocation' => $allocation1, 'utilization' => new UtilizationData(50, 0, 50, 100, 50)],
                    ['allocation' => $allocation2, 'utilization' => new UtilizationData(30, 0, 30, 100, 30)],
                ];
            }
            return $callback($this->createMock(\Symfony\Contracts\Cache\ItemInterface::class));
        });
        
        $result = $this->service->getAvailableAllocationsBySize($shippingLine, 1.0);
        
        // Should return empty array as all allocations are full
        $this->assertCount(0, $result);
    }

    // Helper methods

    private function createAllocation(int $capacity20ft, int $capacity40ft): ShippingLineTerminalAllocation
    {
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setCapacity20ft($capacity20ft);
        $allocation->setCapacity40ft($capacity40ft);
        
        // Use reflection to set the containers collection and id
        $reflection = new \ReflectionClass($allocation);
        
        $containersProperty = $reflection->getProperty('containers');
        $containersProperty->setAccessible(true);
        $containersProperty->setValue($allocation, new ArrayCollection());
        
        // Set ID to avoid uninitialized property error
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($allocation, rand(1, 10000));
        
        return $allocation;
    }

    private function createContainer(float $teuValue, AllocationStatus $status): Container
    {
        $container = new Container();
        
        $size = new ContainerSize();
        $size->setTeuValue($teuValue);
        $size->setName($teuValue == 1.0 ? '20ft' : '40ft');
        $size->setCode($teuValue == 1.0 ? '20FT' : '40FT');
        
        $container->setContainerSize($size);
        $container->setAllocationStatus($status);
        $container->setContainerNumber('TEST' . uniqid());
        
        return $container;
    }

    private function createTerminal(string $name): Terminal
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->method('getName')->willReturn($name);
        $terminal->method('getId')->willReturn(rand(1, 1000));
        
        return $terminal;
    }

    private function createShippingLine(): ShippingLine
    {
        $shippingLine = $this->createMock(ShippingLine::class);
        $shippingLine->method('getId')->willReturn(1);
        
        return $shippingLine;
    }
}
