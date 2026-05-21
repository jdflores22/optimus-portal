<?php

namespace App\Tests\Controller;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\Trucker;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Service\ContainerSearchService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Property Test for Container Search Workflow
 * 
 * **Property 7: Container search and validation**
 * **Validates: Requirements 7.1, 7.2, 7.3**
 * 
 * For any container number search, the system should return accurate container details if found,
 * or appropriate error messages if not found, and display only compatible terminals.
 */
class TruckerContainerSearchPropertyTest extends WebTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private ContainerSearchService $containerSearchService;
    private TerminalService $terminalService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        $this->containerSearchService = $kernel->getContainer()->get(ContainerSearchService::class);
        $this->terminalService = $kernel->getContainer()->get(TerminalService::class);
    }

    /**
     * Property 7.1: Container search returns accurate results
     * 
     * For any valid container number that exists in the system,
     * the search should return accurate container details.
     */
    public function testContainerSearchReturnsAccurateResults(): void
    {
        $this->forAll(
            Generator\choose(1, 100), // Container ID range
            Generator\elements(['ABCD', 'EFGH', 'IJKL', 'MNOP']), // Container prefix
            Generator\choose(1000000, 9999999), // Container number suffix
            Generator\elements(['20ft', '40ft', '45ft']), // Container sizes
            Generator\elements(['Dry', 'Reefer', 'Hazardous']), // Container types
            Generator\elements(ContainerStatus::cases()) // Container statuses
        )->then(function ($containerId, $prefix, $suffix, $size, $type, $status) {
            // Arrange: Create a container with generated properties
            $containerNumber = $prefix . $suffix;
            $container = $this->createTestContainer($containerNumber, $size, $type, $status);
            
            // Act: Search for the container
            $searchResult = $this->containerSearchService->getContainerDetails($containerNumber);
            
            // Assert: Verify search returns accurate details
            $this->assertNotNull($searchResult, "Container search should return results for existing container: {$containerNumber}");
            $this->assertEquals($containerNumber, $searchResult['containerNumber'], "Container number should match search query");
            $this->assertEquals($size, $searchResult['size'], "Container size should match stored value");
            $this->assertEquals($type, $searchResult['type'], "Container type should match stored value");
            $this->assertEquals($status->value, $searchResult['status'], "Container status should match stored value");
            
            // Cleanup
            $this->cleanupTestContainer($container);
        });
    }

    /**
     * Property 7.2: Container search handles non-existent containers
     * 
     * For any container number that doesn't exist in the system,
     * the search should return null or appropriate error indication.
     */
    public function testContainerSearchHandlesNonExistentContainers(): void
    {
        $this->forAll(
            Generator\elements(['ZZZZ', 'YYYY', 'XXXX']), // Non-existent prefixes
            Generator\choose(0, 999999) // Non-existent number range
        )->then(function ($prefix, $suffix) {
            // Arrange: Generate a container number that doesn't exist
            $nonExistentContainerNumber = $prefix . str_pad($suffix, 7, '0', STR_PAD_LEFT);
            
            // Ensure this container doesn't exist in the database
            $existingContainer = $this->entityManager->getRepository(Container::class)
                ->findOneBy(['containerNumber' => $nonExistentContainerNumber]);
            
            if ($existingContainer) {
                // Skip this iteration if container exists
                return;
            }
            
            // Act: Search for non-existent container
            $searchResult = $this->containerSearchService->getContainerDetails($nonExistentContainerNumber);
            
            // Assert: Should return null for non-existent containers
            $this->assertNull($searchResult, "Search should return null for non-existent container: {$nonExistentContainerNumber}");
        });
    }

    /**
     * Property 7.3: Compatible terminals are correctly identified
     * 
     * For any container that is found and available for return,
     * the system should display only terminals that can accept the container type.
     */
    public function testCompatibleTerminalsAreCorrectlyIdentified(): void
    {
        $this->forAll(
            Generator\elements(['CONT', 'TEST', 'DEMO']), // Container prefixes
            Generator\choose(1000000, 9999999), // Container numbers
            Generator\elements(['20ft', '40ft']), // Container sizes
            Generator\elements(['Dry', 'Reefer', 'Hazardous']) // Container types
        )->then(function ($prefix, $suffix, $size, $type) {
            // Arrange: Create container available for return
            $containerNumber = $prefix . $suffix;
            $container = $this->createTestContainer(
                $containerNumber, 
                $size, 
                $type, 
                ContainerStatus::AVAILABLE_FOR_RETURN
            );
            
            // Create test terminals of different types
            $terminals = $this->createTestTerminals();
            
            // Act: Find compatible terminals
            $compatibleTerminals = $this->terminalService->findCompatibleTerminals($container);
            
            // Assert: Verify compatibility rules
            foreach ($compatibleTerminals as $terminal) {
                $this->assertTrue(
                    $this->terminalService->canAcceptContainer($terminal, $container),
                    "Terminal {$terminal->getName()} should be able to accept container {$containerNumber} of type {$type}"
                );
                
                // Verify terminal is active
                $this->assertTrue(
                    $terminal->isActive(),
                    "Only active terminals should be returned as compatible"
                );
            }
            
            // Verify container type compatibility rules
            $this->verifyContainerTypeCompatibility($container, $compatibleTerminals, $type);
            
            // Cleanup
            $this->cleanupTestContainer($container);
            $this->cleanupTestTerminals($terminals);
        });
    }

    /**
     * Property 7.4: Container availability validation
     * 
     * For any container search, the system should correctly validate
     * whether the container is available for return based on status and business rules.
     */
    public function testContainerAvailabilityValidation(): void
    {
        $this->forAll(
            Generator\elements(['AVAI', 'TEST', 'CHCK']), // Container prefixes
            Generator\choose(1000000, 9999999), // Container numbers
            Generator\elements(ContainerStatus::cases()), // All possible statuses
            Generator\choose(-30, 30) // Days offset for expected return date
        )->then(function ($prefix, $suffix, $status, $daysOffset) {
            // Arrange: Create container with specific status and return date
            $containerNumber = $prefix . $suffix;
            $expectedReturnDate = new \DateTime();
            $expectedReturnDate->modify("{$daysOffset} days");
            
            $container = $this->createTestContainer($containerNumber, '20ft', 'Dry', $status);
            $container->setExpectedReturnDate($expectedReturnDate);
            $this->entityManager->flush();
            
            // Act: Check availability
            $isAvailable = $this->containerSearchService->isAvailableForReturn($containerNumber);
            $containerDetails = $this->containerSearchService->getContainerDetails($containerNumber);
            
            // Assert: Verify availability logic
            $shouldBeAvailable = $status === ContainerStatus::AVAILABLE_FOR_RETURN && 
                                $expectedReturnDate >= new \DateTime('today');
            
            $this->assertEquals(
                $shouldBeAvailable, 
                $isAvailable,
                "Container availability should match business rules. Status: {$status->value}, Return Date: {$expectedReturnDate->format('Y-m-d')}"
            );
            
            if ($containerDetails) {
                $this->assertEquals(
                    $shouldBeAvailable,
                    $containerDetails['isAvailableForReturn'],
                    "Container details should reflect correct availability status"
                );
            }
            
            // Cleanup
            $this->cleanupTestContainer($container);
        });
    }

    /**
     * Property 7.5: Container number format validation
     * 
     * For any container number input, the system should validate
     * the format according to international container number standards.
     */
    public function testContainerNumberFormatValidation(): void
    {
        $this->forAll(
            Generator\oneOf(
                // Valid format: 4 letters + 7 digits
                Generator\elements(['ABCD1234567', 'EFGH2345678', 'IJKL3456789', 'MNOP4567890']),
                // Invalid formats
                Generator\elements(['ABC123456', 'ABCD123456', 'ABCDE1234567', '1234567890A', 'abcd1234567', 'ABCD123456A'])
            )
        )->then(function ($containerNumber) {
            // Act: Validate container number format
            $isValidFormat = $this->containerSearchService->validateContainerNumberFormat($containerNumber);
            
            // Assert: Check if validation matches expected pattern
            $expectedValid = preg_match('/^[A-Z]{4}[0-9]{7}$/', $containerNumber) === 1;
            
            $this->assertEquals(
                $expectedValid,
                $isValidFormat,
                "Container number format validation should match pattern. Input: '{$containerNumber}'"
            );
        });
    }

    // Helper methods

    private function createTestContainer(string $containerNumber, string $size, string $type, ContainerStatus $status): Container
    {
        $container = new Container();
        $container->setContainerNumber($containerNumber);
        $container->setSize($size);
        $container->setType($type);
        $container->setStatus($status);
        $container->setCurrentLocation('Test Location');
        $container->setExpectedReturnDate(new \DateTime('+7 days'));
        $container->setCreatedAt(new \DateTime());
        $container->setUpdatedAt(new \DateTime());
        
        $this->entityManager->persist($container);
        $this->entityManager->flush();
        
        return $container;
    }

    private function createTestTerminals(): array
    {
        $terminals = [];
        
        foreach (TerminalType::cases() as $type) {
            $terminal = new Terminal();
            $terminal->setName("Test {$type->value} Terminal");
            $terminal->setType($type);
            $terminal->setLocation("Test Location {$type->value}");
            $terminal->setDailyCapacity(100);
            $terminal->setIsActive(true);
            $terminal->setCreatedAt(new \DateTime());
            $terminal->setUpdatedAt(new \DateTime());
            
            $this->entityManager->persist($terminal);
            $terminals[] = $terminal;
        }
        
        $this->entityManager->flush();
        return $terminals;
    }

    private function verifyContainerTypeCompatibility(Container $container, array $compatibleTerminals, string $containerType): void
    {
        $terminalTypes = array_map(fn($t) => $t->getType(), $compatibleTerminals);
        
        switch ($containerType) {
            case 'Dry':
                // All terminal types should accept dry containers
                $this->assertGreaterThan(0, count($compatibleTerminals), "Dry containers should be accepted by at least one terminal");
                break;
                
            case 'Reefer':
                // Only CY and ICTSI terminals should accept reefer containers
                foreach ($terminalTypes as $terminalType) {
                    $this->assertContains(
                        $terminalType,
                        [TerminalType::CY, TerminalType::ICTSI],
                        "Reefer containers should only be accepted by CY or ICTSI terminals"
                    );
                }
                break;
                
            case 'Hazardous':
                // Only CY terminals should accept hazardous containers
                foreach ($terminalTypes as $terminalType) {
                    $this->assertEquals(
                        TerminalType::CY,
                        $terminalType,
                        "Hazardous containers should only be accepted by CY terminals"
                    );
                }
                break;
        }
    }

    private function cleanupTestContainer(Container $container): void
    {
        $this->entityManager->remove($container);
        $this->entityManager->flush();
    }

    private function cleanupTestTerminals(array $terminals): void
    {
        foreach ($terminals as $terminal) {
            $this->entityManager->remove($terminal);
        }
        $this->entityManager->flush();
    }
}