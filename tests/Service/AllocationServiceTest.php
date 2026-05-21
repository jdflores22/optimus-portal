<?php

namespace App\Tests\Service;

use App\Entity\Container;
use App\Entity\PreAdviceRequest;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\TerminalType;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Psr\Log\LoggerInterface;

/**
 * Test: Container Yard Allocation Service
 * 
 * This test verifies the TerminalService.getShippingLineUtilization() method:
 * - TEU calculation from containers
 * - Utilization percentage calculation
 * - Container size breakdown
 * - Handling of allocations and no allocations
 * 
 * **Validates: Container Yard Utilization Calculation Requirements**
 */
class AllocationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TerminalService $terminalService;
    private ShippingLine $testShippingLine;
    private Terminal $testTerminal;
    private StaffUser $testAdmin;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->terminalService = $container->get(TerminalService::class);
        
        // Start transaction for each test
        $this->entityManager->beginTransaction();
        
        // Create test data
        $this->testShippingLine = $this->createShippingLine('Test Shipping Line');
        $this->testTerminal = $this->createTerminal('Test Terminal');
        $this->testAdmin = $this->createShippingLineAdmin();
        $this->testShippingLine->addShippingLineAdmin($this->testAdmin);
        $this->testAdmin->setManagedShippingLine($this->testShippingLine);
        $this->entityManager->flush();
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
     * Test: getShippingLineUtilization with allocation
     * Validates: Requirement 7.2, 7.3, 7.4
     */
    public function testGetShippingLineUtilizationWithAllocation(): void
    {
        // Create allocation
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($this->testAdmin);
        $allocation->setTerminal($this->testTerminal);
        $allocation->setAllocatedCapacity(500);
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        // Create containers with approved pre-advice
        $container20ft = $this->createContainer($this->testShippingLine, '20ft');
        $container40ft = $this->createContainer($this->testShippingLine, '40ft');
        $this->createPreAdviceRequest($container20ft, $this->testTerminal, 'approved');
        $this->createPreAdviceRequest($container40ft, $this->testTerminal, 'approved');
        $this->entityManager->flush();
        
        // Get utilization
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal,
            $this->testShippingLine
        );
        
        // Verify results
        $this->assertEquals(3, $utilization['currentTEUs']); // 1 TEU (20ft) + 2 TEUs (40ft)
        $this->assertEquals(500, $utilization['allocatedTEUs']);
        $this->assertEquals(1, $utilization['percentage']); // (3/500) * 100 = 0.6 rounded to 1
        $this->assertEquals(1, $utilization['container20ft']);
        $this->assertEquals(1, $utilization['container40ft']);
    }

    /**
     * Test: getShippingLineUtilization without allocation
     * Validates: Requirement 8.5
     */
    public function testGetShippingLineUtilizationWithoutAllocation(): void
    {
        // No allocation created, should use terminal daily capacity
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal,
            $this->testShippingLine
        );
        
        // Verify results
        $this->assertEquals(0, $utilization['currentTEUs']);
        $this->assertEquals($this->testTerminal->getDailyCapacity(), $utilization['allocatedTEUs']);
        $this->assertEquals(0, $utilization['percentage']);
        $this->assertEquals(0, $utilization['container20ft']);
        $this->assertEquals(0, $utilization['container40ft']);
    }

    /**
     * Test: empty allocation list
     * Validates: Requirement 2.4
     */
    public function testEmptyAllocationList(): void
    {
        // Get utilization with null shipping line
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal,
            null
        );
        
        // Verify results for null shipping line
        $this->assertEquals(0, $utilization['currentTEUs']);
        $this->assertEquals($this->testTerminal->getDailyCapacity(), $utilization['allocatedTEUs']);
        $this->assertEquals(0, $utilization['percentage']);
        $this->assertEquals(0, $utilization['container20ft']);
        $this->assertEquals(0, $utilization['container40ft']);
    }

    /**
     * Test: allocation with zero containers
     * Validates: Requirement 7.3
     */
    public function testAllocationWithZeroContainers(): void
    {
        // Create allocation
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($this->testAdmin);
        $allocation->setTerminal($this->testTerminal);
        $allocation->setAllocatedCapacity(300);
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        // No containers created
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal,
            $this->testShippingLine
        );
        
        // Verify results
        $this->assertEquals(0, $utilization['currentTEUs']);
        $this->assertEquals(300, $utilization['allocatedTEUs']);
        $this->assertEquals(0, $utilization['percentage']);
        $this->assertEquals(0, $utilization['container20ft']);
        $this->assertEquals(0, $utilization['container40ft']);
    }

    /**
     * Test: allocation exceeding daily capacity
     * Validates: Requirement 6.4
     */
    public function testAllocationExceedingDailyCapacity(): void
    {
        // Create allocation exceeding terminal daily capacity
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($this->testAdmin);
        $allocation->setTerminal($this->testTerminal);
        $allocation->setAllocatedCapacity(1500); // Terminal daily capacity is 1000
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        // Create containers
        $container20ft1 = $this->createContainer($this->testShippingLine, '20ft');
        $container20ft2 = $this->createContainer($this->testShippingLine, '20ft');
        $container40ft = $this->createContainer($this->testShippingLine, '40ft');
        $this->createPreAdviceRequest($container20ft1, $this->testTerminal, 'approved');
        $this->createPreAdviceRequest($container20ft2, $this->testTerminal, 'approved');
        $this->createPreAdviceRequest($container40ft, $this->testTerminal, 'edo_ready');
        $this->entityManager->flush();
        
        // Get utilization
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal,
            $this->testShippingLine
        );
        
        // Verify results
        $this->assertEquals(4, $utilization['currentTEUs']); // 1 + 1 + 2 = 4 TEUs
        $this->assertEquals(1500, $utilization['allocatedTEUs']); // Exceeds daily capacity
        $this->assertEquals(0, $utilization['percentage']); // (4/1500) * 100 = 0.27 rounded to 0
        $this->assertEquals(2, $utilization['container20ft']);
        $this->assertEquals(1, $utilization['container40ft']);
    }

    /**
     * Test: TEU calculation with 45ft containers
     * Validates: Requirement 7.4
     */
    public function testTEUCalculationWith45ftContainers(): void
    {
        // Create allocation
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($this->testAdmin);
        $allocation->setTerminal($this->testTerminal);
        $allocation->setAllocatedCapacity(100);
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        // Create 45ft container
        $container45ft = $this->createContainer($this->testShippingLine, '45ft');
        $this->createPreAdviceRequest($container45ft, $this->testTerminal, 'approved');
        $this->entityManager->flush();
        
        // Get utilization
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal,
            $this->testShippingLine
        );
        
        // Verify results - 45ft should count as 2.25 TEUs but be counted in 40ft category
        $this->assertEquals(2, $utilization['currentTEUs']); // 2.25 rounded down to 2 in the implementation
        $this->assertEquals(0, $utilization['container20ft']);
        $this->assertEquals(1, $utilization['container40ft']); // 45ft counted as 40ft
    }

    /**
     * Test: only approved and edo_ready containers are counted
     * Validates: Requirement 7.3
     */
    public function testOnlyApprovedAndEdoReadyContainersCounted(): void
    {
        // Create allocation
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setStaffUser($this->testAdmin);
        $allocation->setTerminal($this->testTerminal);
        $allocation->setAllocatedCapacity(200);
        $this->entityManager->persist($allocation);
        $this->entityManager->flush();
        
        // Create containers with different statuses
        $container1 = $this->createContainer($this->testShippingLine, '20ft');
        $container2 = $this->createContainer($this->testShippingLine, '20ft');
        $container3 = $this->createContainer($this->testShippingLine, '40ft');
        $container4 = $this->createContainer($this->testShippingLine, '40ft');
        
        $this->createPreAdviceRequest($container1, $this->testTerminal, 'approved');
        $this->createPreAdviceRequest($container2, $this->testTerminal, 'edo_ready');
        $this->createPreAdviceRequest($container3, $this->testTerminal, 'pending');
        $this->createPreAdviceRequest($container4, $this->testTerminal, 'rejected');
        $this->entityManager->flush();
        
        // Get utilization
        $utilization = $this->terminalService->getShippingLineUtilization(
            $this->testTerminal,
            $this->testShippingLine
        );
        
        // Verify only approved and edo_ready are counted
        $this->assertEquals(2, $utilization['currentTEUs']); // 1 (approved 20ft) + 1 (edo_ready 20ft)
        $this->assertEquals(2, $utilization['container20ft']);
        $this->assertEquals(0, $utilization['container40ft']);
    }

    // Helper methods

    private function createShippingLine(string $brandName): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName($brandName . uniqid());
        $shippingLine->setPortalConfig(['test' => true]);
        
        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();
        
        return $shippingLine;
    }

    private function createTerminal(string $name): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName($name . uniqid());
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation('Test Location');
        $terminal->setDailyCapacity(1000);
        $terminal->setIsActive(true);
        
        $this->entityManager->persist($terminal);
        $this->entityManager->flush();
        
        return $terminal;
    }

    private function createShippingLineAdmin(): StaffUser
    {
        $user = new StaffUser();
        $user->setEmail('sladmin' . uniqid() . '@test.com');
        $user->setPasswordHash('hashed_password');
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setFirstName('Shipping');
        $user->setLastName('Admin');
        $user->setDepartment('Operations');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createContainer(ShippingLine $shippingLine, string $size): Container
    {
        $container = new Container();
        $container->setContainerNumber('CONT' . uniqid());
        $container->setSize($size);
        $container->setType('Dry');
        $container->setShippingLine($shippingLine);
        
        $this->entityManager->persist($container);
        
        return $container;
    }

    private function createPreAdviceRequest(Container $container, Terminal $terminal, string $status): PreAdviceRequest
    {
        $preAdvice = new PreAdviceRequest();
        $preAdvice->setContainer($container);
        $preAdvice->setSelectedTerminal($terminal);
        $preAdvice->setStatus($status);
        $preAdvice->setRequestedDate(new \DateTime());
        
        $this->entityManager->persist($preAdvice);
        
        return $preAdvice;
    }
}
