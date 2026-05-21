<?php

namespace App\Tests\Service;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Repository\ContainerRepository;
use App\Service\ContainerSearchService;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Feature: terminal-team-pre-advice, Property 7: Container search and validation
 * 
 * Property-based test for validating container search functionality in the Terminal Team Pre-Advice system.
 * This test validates Requirements 7.1, 7.2, 7.3 by ensuring that container search operations
 * return accurate results and properly validate container availability.
 */
class ContainerSearchPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 7: Container search and validation
     * 
     * For any container number search, the system should return accurate container details if found,
     * or appropriate error messages if not found. Container availability validation should be consistent
     * with business rules.
     * 
     * Validates: Requirements 7.1, 7.2, 7.3
     */
    public function testContainerSearchAndValidation(): void
    {
        $this->forAll(
            Generator\elements('ABCD1234567', 'EFGH9876543', 'IJKL5555555', 'MNOP1111111', 'QRST9999999'),
            Generator\elements(
                ContainerStatus::AVAILABLE_FOR_RETURN,
                ContainerStatus::IN_TRANSIT,
                ContainerStatus::AT_TERMINAL,
                ContainerStatus::RETURNED,
                ContainerStatus::MAINTENANCE
            ),
            Generator\elements('20ft', '40ft', '45ft'),
            Generator\elements('Dry', 'Reefer', 'Hazardous', 'Tank'),
            Generator\choose(-30, 30) // Days offset from today
        )->then(function (
            string $containerNumber,
            ContainerStatus $status,
            string $size,
            string $type,
            int $daysOffset
        ) {
            // Create fresh mocks for each test iteration
            $containerRepository = $this->createMock(ContainerRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $containerSearchService = new ContainerSearchService($containerRepository, $logger);

            // Create a test container with reflection to set ID
            $container = new Container();
            $container->setContainerNumber($containerNumber)
                ->setSize($size)
                ->setType($type)
                ->setStatus($status)
                ->setCurrentLocation('Test Location')
                ->setExpectedReturnDate((new \DateTime())->modify("{$daysOffset} days"));

            // Use reflection to set the ID
            $reflection = new \ReflectionClass($container);
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($container, 1);

            // Test case 1: Container exists
            $containerRepository
                ->expects($this->any())
                ->method('findByContainerNumber')
                ->with($containerNumber)
                ->willReturn($container);

            $foundContainer = $containerSearchService->findByContainerNumber($containerNumber);
            
            // Requirement 7.1: System should return container details if found
            $this->assertNotNull($foundContainer);
            $this->assertEquals($containerNumber, $foundContainer->getContainerNumber());
            $this->assertEquals($status, $foundContainer->getStatus());
            $this->assertEquals($size, $foundContainer->getSize());
            $this->assertEquals($type, $foundContainer->getType());

            // Test case 2: Container availability validation (Requirement 7.2)
            $isAvailable = $containerSearchService->validateContainerAvailability($container);
            
            // Container should be available only if:
            // 1. Status is AVAILABLE_FOR_RETURN
            // 2. Expected return date is today or in the future
            $expectedAvailability = $status === ContainerStatus::AVAILABLE_FOR_RETURN && $daysOffset >= 0;
            $this->assertEquals($expectedAvailability, $isAvailable);

            // Test case 3: Container status checking (Requirement 7.3)
            $containerStatus = $containerSearchService->getContainerStatus($containerNumber);
            $this->assertEquals($status, $containerStatus);

            // Test case 4: Container details retrieval
            $containerDetails = $containerSearchService->getContainerDetails($containerNumber);
            $this->assertNotNull($containerDetails);
            $this->assertEquals($containerNumber, $containerDetails['containerNumber']);
            $this->assertEquals($status->value, $containerDetails['status']);
            $this->assertEquals($size, $containerDetails['size']);
            $this->assertEquals($type, $containerDetails['type']);
            $this->assertEquals($expectedAvailability, $containerDetails['isAvailableForReturn']);

            // Test case 5: Availability check by container number
            $availabilityCheck = $containerSearchService->isAvailableForReturn($containerNumber);
            $this->assertEquals($expectedAvailability, $availabilityCheck);
        });
    }

    /**
     * Property test for container not found scenarios
     * 
     * For any container number that doesn't exist, the system should return null
     * and handle the case gracefully.
     */
    public function testContainerNotFoundHandling(): void
    {
        $this->forAll(
            Generator\elements('NOTF1234567', 'MISS9876543', 'GONE5555555', 'NULL1111111', 'VOID9999999')
        )->then(function (string $containerNumber) {
            // Create fresh mocks for each test iteration
            $containerRepository = $this->createMock(ContainerRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $containerSearchService = new ContainerSearchService($containerRepository, $logger);

            // Mock repository to return null (container not found)
            $containerRepository
                ->expects($this->any())
                ->method('findByContainerNumber')
                ->with($containerNumber)
                ->willReturn(null);

            // Requirement 7.3: System should handle container not found gracefully
            $foundContainer = $containerSearchService->findByContainerNumber($containerNumber);
            $this->assertNull($foundContainer);

            $containerStatus = $containerSearchService->getContainerStatus($containerNumber);
            $this->assertNull($containerStatus);

            $containerDetails = $containerSearchService->getContainerDetails($containerNumber);
            $this->assertNull($containerDetails);

            $isAvailable = $containerSearchService->isAvailableForReturn($containerNumber);
            $this->assertFalse($isAvailable);
        });
    }

    /**
     * Property test for container number format validation
     * 
     * For any container number, the format validation should be consistent
     * with standard container number patterns.
     */
    public function testContainerNumberFormatValidation(): void
    {
        $this->forAll(
            Generator\oneOf(
                Generator\elements('ABCD1234567', 'EFGH9876543', 'IJKL5555555'), // Valid format
                Generator\elements('ABC123', 'TOOLONG12345678', '1234ABCD567', 'abcd1234567', '') // Invalid formats
            )
        )->then(function (string $containerNumber) {
            // Create fresh mocks for each test iteration
            $containerRepository = $this->createMock(ContainerRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $containerSearchService = new ContainerSearchService($containerRepository, $logger);

            $isValidFormat = $containerSearchService->validateContainerNumberFormat($containerNumber);
            
            // Container number should be valid if it matches pattern: 4 letters + 7 digits
            $expectedValid = preg_match('/^[A-Z]{4}[0-9]{7}$/', strtoupper($containerNumber)) === 1;
            $this->assertEquals($expectedValid, $isValidFormat);
        });
    }

    /**
     * Property test for container availability business rules
     * 
     * For any container with various statuses and return dates,
     * the availability logic should be consistent with business requirements.
     */
    public function testContainerAvailabilityBusinessRules(): void
    {
        $this->forAll(
            Generator\elements(
                ContainerStatus::AVAILABLE_FOR_RETURN,
                ContainerStatus::IN_TRANSIT,
                ContainerStatus::AT_TERMINAL,
                ContainerStatus::RETURNED,
                ContainerStatus::MAINTENANCE
            ),
            Generator\choose(-10, 10) // Days offset from today
        )->then(function (ContainerStatus $status, int $daysOffset) {
            // Create fresh mocks for each test iteration
            $containerRepository = $this->createMock(ContainerRepository::class);
            $logger = $this->createMock(LoggerInterface::class);
            $containerSearchService = new ContainerSearchService($containerRepository, $logger);

            $container = new Container();
            $container->setContainerNumber('TEST1234567')
                ->setSize('40ft')
                ->setType('Dry')
                ->setStatus($status)
                ->setCurrentLocation('Test Location')
                ->setExpectedReturnDate((new \DateTime())->modify("{$daysOffset} days"));

            $isAvailable = $containerSearchService->validateContainerAvailability($container);

            // Business rule: Container is available for return only if:
            // 1. Status is AVAILABLE_FOR_RETURN
            // 2. Expected return date is today or in the future
            $expectedAvailability = $status === ContainerStatus::AVAILABLE_FOR_RETURN && $daysOffset >= 0;
            
            $this->assertEquals($expectedAvailability, $isAvailable);

            // Additional validation: containers with past return dates should not be available
            if ($daysOffset < 0) {
                $this->assertFalse($isAvailable, 'Containers with past return dates should not be available');
            }

            // Additional validation: only AVAILABLE_FOR_RETURN status allows booking
            if ($status !== ContainerStatus::AVAILABLE_FOR_RETURN) {
                $this->assertFalse($isAvailable, 'Only containers with AVAILABLE_FOR_RETURN status should be available');
            }
        });
    }
}