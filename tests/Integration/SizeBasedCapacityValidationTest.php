<?php

namespace App\Tests\Integration;

use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Entity\StaffUser;
use App\Entity\Container;
use App\Entity\ContainerSize;
use App\Entity\ContainerType;
use App\Entity\NOA;
use App\Entity\Consignee;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\AllocationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration Test: Size-Based Capacity Validation
 * 
 * Tests the complete workflow of size-specific capacity validation
 * for 20ft and 40ft containers during NOA creation.
 * 
 * **Validates: Task 3 - Size-Specific Capacity Validation**
 * **Properties: 6, 7, 18**
 */
class SizeBasedCapacityValidationTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;
    private StaffUser $slStaffUser;
    private ShippingLine $testShippingLine;
    private Terminal $testTerminal;
    private ShippingLineTerminalAllocation $testAllocation;
    private ContainerSize $size20ft;
    private ContainerSize $size40ft;
    private ContainerType $containerType;
    private Consignee $testConsignee;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        
        // Start transaction for each test
        $this->entityManager->beginTransaction();
        
        // Create test data
        $this->testShippingLine = $this->createShippingLine('Test Shipping Line');
        $this->slStaffUser = $this->createSLStaffUser($this->testShippingLine);
        $this->testTerminal = $this->createTerminal('Test Terminal');
        $this->testConsignee = $this->createConsignee();
        
        // Create container sizes
        $this->size20ft = $this->createContainerSize('20ft', 1.0);
        $this->size40ft = $this->createContainerSize('40ft', 2.0);
        
        // Create container type
        $this->containerType = $this->createContainerType('Dry');
        
        // Create allocation with size-specific capacity
        $this->testAllocation = $this->createAllocation(
            $this->testShippingLine,
            $this->testTerminal,
            $this->slStaffUser,
            capacity20ft: 5,
            capacity40ft: 3
        );
        
        // Login as SL staff user
        $this->client->loginUser($this->slStaffUser);
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
     * Task 3.5: Test 20ft capacity validation failure scenario
     * Validates: Property 6, 7 - Size-specific validation and error messages
     */
    public function test20ftCapacityValidationFailure(): void
    {
        // Fill up 20ft capacity (5 containers)
        for ($i = 0; $i < 5; $i++) {
            $container = $this->createContainer($this->testShippingLine, $this->size20ft, "CONT20-{$i}");
            $container->setCyAllocation($this->testAllocation);
            $container->setAllocationStatus(AllocationStatus::PRE_FORECAST);
            $this->entityManager->persist($container);
        }
        $this->entityManager->flush();
        
        // Attempt to create NOA with one more 20ft container
        $this->client->request(
            'POST',
            '/noa/create',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'blNumber' => 'BL-TEST-001',
                'vesselNumber' => 'VESSEL-001',
                'eta' => (new \DateTime('+7 days'))->format('Y-m-d H:i:s'),
                'cyLocation' => 'Test CY Location',
                'consigneeId' => $this->testConsignee->getId(),
                'containers' => [
                    [
                        'number' => 'CONT20-NEW',
                        'typeId' => $this->containerType->getId(),
                        'sizeId' => $this->size20ft->getId(),
                        'cyAllocationId' => $this->testAllocation->getId(),
                    ]
                ]
            ])
        );
        
        $this->assertEquals(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        // Verify error response structure (Task 3.2)
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Capacity validation failed', $responseData['error']);
        $this->assertArrayHasKey('allocation_errors', $responseData);
        $this->assertCount(1, $responseData['allocation_errors']);
        
        $error = $responseData['allocation_errors'][0];
        
        // Verify error code
        $this->assertEquals('INSUFFICIENT_20FT_CAPACITY', $error['error_code']);
        
        // Verify error message format (Property 7)
        $this->assertStringContainsString('Insufficient 20ft capacity', $error['message']);
        $this->assertStringContainsString($this->testTerminal->getName(), $error['message']);
        $this->assertStringContainsString('Required: 1 container', $error['message']);
        $this->assertStringContainsString('Available: 0 containers', $error['message']);
        
        // Verify error details
        $this->assertEquals('CONT20-NEW', $error['container']);
        $this->assertEquals($this->testTerminal->getName(), $error['terminal_name']);
        $this->assertEquals($this->testTerminal->getId(), $error['terminal_id']);
        $this->assertEquals('20ft', $error['container_size']);
        $this->assertEquals(1, $error['required_count']);
        $this->assertEquals(0, $error['available_count']);
      