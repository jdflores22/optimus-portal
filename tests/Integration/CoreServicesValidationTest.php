<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Service\ContainerSearchService;
use App\Service\TerminalService;
use App\Service\SlotManagementService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Core services validation test for Terminal Team Pre-Advice system
 * 
 * This test validates that all core services are working correctly without
 * requiring complex database operations that might have schema issues.
 */
class CoreServicesValidationTest extends KernelTestCase
{
    private ContainerSearchService $containerSearchService;
    private TerminalService $terminalService;
    private SlotManagementService $slotManagementService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = $kernel->getContainer();
        
        $this->containerSearchService = $container->get(ContainerSearchService::class);
        $this->terminalService = $container->get(TerminalService::class);
        $this->slotManagementService = $container->get(SlotManagementService::class);
    }

    /**
     * Test that all core services are properly instantiated and accessible
     */
    public function testCoreServicesInstantiation(): void
    {
        $this->assertInstanceOf(ContainerSearchService::class, $this->containerSearchService);
        $this->assertInstanceOf(TerminalService::class, $this->terminalService);
        $this->assertInstanceOf(SlotManagementService::class, $this->slotManagementService);
    }

    /**
     * Test container search service functionality without database operations
     */
    public function testContainerSearchServiceFunctionality(): void
    {
        // Test container number format validation
        $validFormat = $this->containerSearchService->validateContainerNumberFormat('ABCD1234567');
        $this->assertTrue($validFormat, 'Valid container number format should pass validation');

        $invalidFormat = $this->containerSearchService->validateContainerNumberFormat('INVALID');
        $this->assertFalse($invalidFormat, 'Invalid container number format should fail validation');

        // Test container not found scenario
        $nonExistentContainer = $this->containerSearchService->findByContainerNumber('NONEXISTENT123');
        $this->assertNull($nonExistentContainer, 'Should return null for non-existent container');

        // Test container availability for non-existent container
        $isAvailable = $this->containerSearchService->isAvailableForReturn('NONEXISTENT123');
        $this->assertFalse($isAvailable, 'Non-existent container should not be available');

        // Test container status for non-existent container
        $status = $this->containerSearchService->getContainerStatus('NONEXISTENT123');
        $this->assertNull($status, 'Should return null status for non-existent container');

        // Test container details for non-existent container
        $details = $this->containerSearchService->getContainerDetails('NONEXISTENT123');
        $this->assertNull($details, 'Should return null details for non-existent container');
    }

    /**
     * Test container availability validation logic
     */
    public function testContainerAvailabilityValidation(): void
    {
        // Create test containers with different statuses and dates
        $availableContainer = new Container();
        $availableContainer->setContainerNumber('AVAIL1234567')
            ->setSize('20ft')
            ->setType('Dry')
            ->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN)
            ->setCurrentLocation('Test Location')
            ->setExpectedReturnDate(new \DateTime('+1 day'));

        $unavailableContainer = new Container();
        $unavailableContainer->setContainerNumber('UNAVAIL123456')
            ->setSize('40ft')
            ->setType('Dry')
            ->setStatus(ContainerStatus::IN_TRANSIT)
            ->setCurrentLocation('Test Location')
            ->setExpectedReturnDate(new \DateTime('+1 day'));

        $expiredContainer = new Container();
        $expiredContainer->setContainerNumber('EXPIRED123456')
            ->setSize('20ft')
            ->setType('Dry')
            ->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN)
            ->setCurrentLocation('Test Location')
            ->setExpectedReturnDate(new \DateTime('-1 day'));

        // Test availability validation
        $this->assertTrue(
            $this->containerSearchService->validateContainerAvailability($availableContainer),
            'Available container with future return date should be available'
        );

        $this->assertFalse(
            $this->containerSearchService->validateContainerAvailability($unavailableContainer),
            'Container in transit should not be available'
        );

        $this->assertFalse(
            $this->containerSearchService->validateContainerAvailability($expiredContainer),
            'Container with past return date should not be available'
        );
    }

    /**
     * Test terminal service functionality
     */
    public function testTerminalServiceFunctionality(): void
    {
        // Create test terminal and container
        $terminal = new Terminal();
        $terminal->setName('Test Terminal')
            ->setType(TerminalType::CY)
            ->setLocation('Test Location')
            ->setDailyCapacity(50)
            ->setIsActive(true);

        // Use reflection to set ID to avoid database persistence issues
        $reflection = new \ReflectionClass($terminal);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($terminal, 1);

        $container = new Container();
        $container->setContainerNumber('TEST1234567')
            ->setSize('20ft')
            ->setType('Dry')
            ->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN)
            ->setCurrentLocation('Test Location')
            ->setExpectedReturnDate(new \DateTime('+1 day'));

        // Use reflection to set ID for container as well
        $containerReflection = new \ReflectionClass($container);
        $containerIdProperty = $containerReflection->getProperty('id');
        $containerIdProperty->setAccessible(true);
        $containerIdProperty->setValue($container, 1);

        // Test terminal-container compatibility
        $canAccept = $this->terminalService->canAcceptContainer($terminal, $container);
        $this->assertTrue($canAccept, 'Active terminal should accept dry container');

        // Test inactive terminal
        $inactiveTerminal = clone $terminal;
        $inactiveTerminal->setIsActive(false);
        $canAcceptInactive = $this->terminalService->canAcceptContainer($inactiveTerminal, $container);
        $this->assertFalse($canAcceptInactive, 'Inactive terminal should not accept containers');

        // Test container-terminal compatibility validation
        $this->assertTrue(
            $this->terminalService->validateContainerTerminalCompatibility($terminal, $container),
            'CY terminal should accept dry container'
        );

        // Test reefer container compatibility
        $reeferContainer = clone $container;
        $reeferContainer->setType('Reefer');
        $this->assertTrue(
            $this->terminalService->validateContainerTerminalCompatibility($terminal, $reeferContainer),
            'CY terminal should accept reefer container'
        );

        // Test hazardous container compatibility
        $hazardousContainer = clone $container;
        $hazardousContainer->setType('Hazardous');
        $this->assertTrue(
            $this->terminalService->validateContainerTerminalCompatibility($terminal, $hazardousContainer),
            'CY terminal should accept hazardous container'
        );

        // Test ATI terminal with reefer container
        $atiTerminal = clone $terminal;
        $atiTerminal->setType(TerminalType::ATI);
        $this->assertFalse(
            $this->terminalService->validateContainerTerminalCompatibility($atiTerminal, $reeferContainer),
            'ATI terminal should NOT accept reefer container'
        );

        // Test ICTSI terminal with reefer container
        $ictsiTerminal = clone $terminal;
        $ictsiTerminal->setType(TerminalType::ICTSI);
        $this->assertTrue(
            $this->terminalService->validateContainerTerminalCompatibility($ictsiTerminal, $reeferContainer),
            'ICTSI terminal should accept reefer container'
        );

        // Test ATI terminal with hazardous container
        $this->assertFalse(
            $this->terminalService->validateContainerTerminalCompatibility($atiTerminal, $hazardousContainer),
            'ATI terminal should not accept hazardous container'
        );
    }

    /**
     * Test slot management service functionality
     */
    public function testSlotManagementServiceFunctionality(): void
    {
        $terminal = new Terminal();
        $terminal->setName('Test Terminal')
            ->setType(TerminalType::CY)
            ->setLocation('Test Location')
            ->setDailyCapacity(50)
            ->setIsActive(true);

        $today = new \DateTime('today');

        // Test slot availability check for non-existent slot
        $availability = $this->slotManagementService->checkSlotAvailability($terminal, $today);
        
        $this->assertTrue($availability['available'], 'Non-existent slot should be available');
        $this->assertEquals(50, $availability['capacity'], 'Capacity should match terminal daily capacity');
        $this->assertEquals(0, $availability['assigned'], 'Assigned count should be 0 for new slot');
        $this->assertEquals(50, $availability['remaining'], 'Remaining should equal capacity for new slot');
        $this->assertEquals('available', $availability['status'], 'Status should be available');
    }

    /**
     * Test service method signatures and return types
     */
    public function testServiceMethodSignatures(): void
    {
        // Test ContainerSearchService methods exist and return expected types
        $this->assertTrue(method_exists($this->containerSearchService, 'findByContainerNumber'));
        $this->assertTrue(method_exists($this->containerSearchService, 'isAvailableForReturn'));
        $this->assertTrue(method_exists($this->containerSearchService, 'validateContainerAvailability'));
        $this->assertTrue(method_exists($this->containerSearchService, 'getContainerStatus'));
        $this->assertTrue(method_exists($this->containerSearchService, 'getContainerDetails'));
        $this->assertTrue(method_exists($this->containerSearchService, 'searchContainers'));
        $this->assertTrue(method_exists($this->containerSearchService, 'validateContainerNumberFormat'));

        // Test TerminalService methods exist
        $this->assertTrue(method_exists($this->terminalService, 'canAcceptContainer'));
        $this->assertTrue(method_exists($this->terminalService, 'validateContainerTerminalCompatibility'));
        $this->assertTrue(method_exists($this->terminalService, 'getActiveTerminals'));
        $this->assertTrue(method_exists($this->terminalService, 'getTerminalsByType'));
        $this->assertTrue(method_exists($this->terminalService, 'findCompatibleTerminals'));
        $this->assertTrue(method_exists($this->terminalService, 'hasAvailableCapacity'));
        $this->assertTrue(method_exists($this->terminalService, 'getTerminalCapacity'));
        $this->assertTrue(method_exists($this->terminalService, 'getTerminalDetails'));
        $this->assertTrue(method_exists($this->terminalService, 'configureTerminal'));

        // Test SlotManagementService methods exist
        $this->assertTrue(method_exists($this->slotManagementService, 'createDailySlots'));
        $this->assertTrue(method_exists($this->slotManagementService, 'createSlotsForAllTerminals'));
        $this->assertTrue(method_exists($this->slotManagementService, 'checkSlotAvailability'));
        $this->assertTrue(method_exists($this->slotManagementService, 'assignSlot'));
        $this->assertTrue(method_exists($this->slotManagementService, 'releaseSlot'));
        $this->assertTrue(method_exists($this->slotManagementService, 'updateSlotStatus'));
        $this->assertTrue(method_exists($this->slotManagementService, 'updateSlotCapacity'));
        $this->assertTrue(method_exists($this->slotManagementService, 'getSlotUtilizationStats'));
        $this->assertTrue(method_exists($this->slotManagementService, 'getAvailableSlots'));
        $this->assertTrue(method_exists($this->slotManagementService, 'findNextAvailableSlot'));
    }

    /**
     * Test business logic consistency across services
     */
    public function testBusinessLogicConsistency(): void
    {
        // Test that container availability logic is consistent
        $container = new Container();
        $container->setContainerNumber('LOGIC1234567')
            ->setSize('20ft')
            ->setType('Dry')
            ->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN)
            ->setCurrentLocation('Test Location')
            ->setExpectedReturnDate(new \DateTime('+1 day'));

        $isAvailable = $this->containerSearchService->validateContainerAvailability($container);
        $this->assertTrue($isAvailable, 'Container should be available based on status and date');

        // Test terminal compatibility logic is consistent
        $terminal = new Terminal();
        $terminal->setName('Logic Test Terminal')
            ->setType(TerminalType::CY)
            ->setLocation('Test Location')
            ->setDailyCapacity(50)
            ->setIsActive(true);

        // Use reflection to set ID to avoid database persistence issues
        $reflection = new \ReflectionClass($terminal);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($terminal, 1);

        $canAccept = $this->terminalService->canAcceptContainer($terminal, $container);
        $isCompatible = $this->terminalService->validateContainerTerminalCompatibility($terminal, $container);
        
        // Both methods should return true for active terminal and compatible container
        $this->assertTrue($canAccept, 'Terminal should accept compatible container');
        $this->assertTrue($isCompatible, 'Container should be compatible with terminal');

        // Test that inactive terminal is consistently rejected
        $terminal->setIsActive(false);
        $canAcceptInactive = $this->terminalService->canAcceptContainer($terminal, $container);
        $this->assertFalse($canAcceptInactive, 'Inactive terminal should not accept any container');
    }
}