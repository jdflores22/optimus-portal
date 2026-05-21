<?php

namespace App\Tests\Unit\Service;

use App\Service\ContainerDataService;
use App\ValueObject\Container;
use PHPUnit\Framework\TestCase;

class ContainerDataServiceTest extends TestCase
{
    private ContainerDataService $service;

    protected function setUp(): void
    {
        $this->service = new ContainerDataService();
    }

    public function testGetContainerDataReturnsContainerObjects(): void
    {
        $containers = $this->service->getContainerData();
        
        $this->assertIsArray($containers);
        $this->assertNotEmpty($containers);
        
        foreach ($containers as $container) {
            $this->assertInstanceOf(Container::class, $container);
        }
    }

    public function testGetFormattedContainerDataReturnsJsonSerializableArray(): void
    {
        $formattedData = $this->service->getFormattedContainerData();
        
        $this->assertIsArray($formattedData);
        $this->assertNotEmpty($formattedData);
        
        foreach ($formattedData as $containerData) {
            $this->assertIsArray($containerData);
            $this->assertArrayHasKey('containerNumber', $containerData);
            $this->assertArrayHasKey('sizeType', $containerData);
            $this->assertArrayHasKey('gateInDate', $containerData);
            $this->assertArrayHasKey('dwellTime', $containerData);
            $this->assertArrayHasKey('condition', $containerData);
            $this->assertArrayHasKey('status', $containerData);
            $this->assertArrayHasKey('location', $containerData);
            $this->assertArrayHasKey('teuCount', $containerData);
            $this->assertArrayHasKey('isAvailable', $containerData);
        }
    }

    public function testCalculateTotalTEUWithContainerObjects(): void
    {
        $containers = $this->service->getContainerData();
        $totalTEU = $this->service->calculateTotalTEU($containers);
        
        $this->assertIsInt($totalTEU);
        $this->assertGreaterThan(0, $totalTEU);
        
        // Verify calculation by manually counting
        $expectedTEU = 0;
        foreach ($containers as $container) {
            $expectedTEU += $container->getTeuCount();
        }
        
        $this->assertEquals($expectedTEU, $totalTEU);
    }

    public function testCalculateTotalTEUWithArrayFormat(): void
    {
        $sampleData = $this->service->getSampleContainerData();
        $totalTEU = $this->service->calculateTotalTEU($sampleData);
        
        $this->assertIsInt($totalTEU);
        $this->assertGreaterThan(0, $totalTEU);
    }

    public function testGetDepotNames(): void
    {
        $depotNames = $this->service->getDepotNames();
        
        $this->assertIsArray($depotNames);
        $this->assertNotEmpty($depotNames);
        
        // Check some expected depot IDs
        $this->assertArrayHasKey('MICT', $depotNames);
        $this->assertArrayHasKey('SBTC', $depotNames);
        $this->assertArrayHasKey('ICTSI', $depotNames);
    }

    public function testGetDepotFullName(): void
    {
        $fullName = $this->service->getDepotFullName('MICT');
        $this->assertEquals('Manila International Container Terminal', $fullName);
        
        // Test unknown depot ID returns the ID itself
        $unknownName = $this->service->getDepotFullName('UNKNOWN');
        $this->assertEquals('UNKNOWN', $unknownName);
    }

    public function testFilterContainersByStatus(): void
    {
        $containers = $this->service->getContainerData();
        $availableContainers = $this->service->filterContainersByStatus($containers, 'Available');
        
        $this->assertIsArray($availableContainers);
        
        foreach ($availableContainers as $container) {
            $this->assertInstanceOf(Container::class, $container);
            $this->assertEquals('Available', $container->getStatus());
        }
    }

    public function testFilterContainersByCondition(): void
    {
        $containers = $this->service->getContainerData();
        $goodContainers = $this->service->filterContainersByCondition($containers, 'Good');
        
        $this->assertIsArray($goodContainers);
        
        foreach ($goodContainers as $container) {
            $this->assertInstanceOf(Container::class, $container);
            $this->assertEquals('Good', $container->getCondition());
        }
    }

    public function testGetContainersWithHighDwellTime(): void
    {
        $containers = $this->service->getContainerData();
        $highDwellContainers = $this->service->getContainersWithHighDwellTime($containers, 20);
        
        $this->assertIsArray($highDwellContainers);
        
        foreach ($highDwellContainers as $container) {
            $this->assertInstanceOf(Container::class, $container);
            $this->assertGreaterThan(20, $container->getDwellTime());
        }
    }

    public function testSampleDataStructure(): void
    {
        $sampleData = $this->service->getSampleContainerData();
        
        $this->assertIsArray($sampleData);
        $this->assertGreaterThanOrEqual(10, count($sampleData)); // At least 10 containers as per requirements
        
        foreach ($sampleData as $containerData) {
            $this->assertIsArray($containerData);
            $this->assertArrayHasKey('containerNumber', $containerData);
            $this->assertArrayHasKey('sizeType', $containerData);
            $this->assertArrayHasKey('gateInDate', $containerData);
            $this->assertArrayHasKey('dwellTime', $containerData);
            $this->assertArrayHasKey('condition', $containerData);
            $this->assertArrayHasKey('status', $containerData);
            $this->assertArrayHasKey('location', $containerData);
        }
        
        // Verify we have both 20ft and 40ft containers
        $sizeTypes = array_column($sampleData, 'sizeType');
        $has20ft = false;
        $has40ft = false;
        
        foreach ($sizeTypes as $sizeType) {
            if (str_contains($sizeType, '20ft')) {
                $has20ft = true;
            }
            if (str_contains($sizeType, '40ft')) {
                $has40ft = true;
            }
        }
        
        $this->assertTrue($has20ft, 'Sample data should include 20ft containers');
        $this->assertTrue($has40ft, 'Sample data should include 40ft containers');
    }
}