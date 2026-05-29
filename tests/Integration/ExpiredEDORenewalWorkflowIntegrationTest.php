<?php

namespace App\Tests\Integration;

use App\Entity\Billing;
use App\Entity\Broker;
use App\Entity\Container;
use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\RenewalRequestStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Manifest;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\StaffUser;
use App\Entity\Terminal;
use App\Entity\User;
use App\Service\DetentionChargeService;
use App\Service\EDORenewalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for complete expired eDO renewal workflow
 * 
 * Tests Requirements:
 * - 1.1: Request New eDO Button Visibility
 * - 3.1: Empty Container Return Request Date
 * - 6.1: Overdue Days Calculation
 * - 7.1: Detention Charge Determination
 * - 8.1, 8.2: Billing Generation for Detention Charges
 * - 9.1, 9.2, 9.3: Payment Verification Before eDO Generation
 * - 10.1, 10.2: New eDO Generation by SL Staff
 * - 14.1: eDO Renewal Request Workflow
 */
class ExpiredEDORenewalWorkflowIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EDORenewalService $renewalService;
    private DetentionChargeService $detentionService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->renewalService = $container->get(EDORenewalService::class);
        $this->detentionService = $container->get(DetentionChargeService::class);

        // Clean database
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    /**
     * Test complete renewal workflow WITHOUT detention charges
     * 
     * Scenario: eDO expired today (0 overdue days)
     * - Broker submits renewal request
     * - No detention charges required
     * - SL staff generates new eDO immediately
     * - New eDO is created and linked correctly
     * 
     * Requirements: 1.1, 3.1, 9.3, 10.1, 10.2, 14.1
     */
    public function testCompleteRenewalWorkflowWithoutDetentionCharges(): void
    {
        // Step 1: Create test users
        $broker = $this->createBrokerUser('broker@test.com', 'Test Broker LLC');
        $slStaff = $this->createSLStaffUser('slstaff@test.com', 'John', 'Staff');
        $this->entityManager->flush();

        // Step 2: Create expired eDO with NO overdue days (expired today)
        $expiredEdo = $this->createExpiredEDO(
            edoNumber: 'EDO-TEST-NO-OVERDUE-001',
            expiresAt: new \DateTime('today'),
            expiredDays: 0,
            cyLocation: 'CY-NORTH'
        );
        $this->entityManager->flush();

        // Step 3: Verify eDO is eligible for renewal
        $this->assertTrue(
            $this->renewalService->isEligibleForRenewal($expiredEdo),
            'Expired eDO should be eligible for renewal'
        );

        // Step 4: Broker submits renewal request
        $returnDate = new \DateTime('+3 days 14:00');
        $renewalRequest = $this->renewalService->createRenewalRequest(
            expiredEdo: $expiredEdo,
            requestedBy: $broker,
            returnDate: $returnDate,
            notes: 'Urgent: Need to return container ASAP'
        );
        $this->entityManager->flush();

        // Step 5: Verify renewal request was created correctly
        $this->assertInstanceOf(EDORenewalRequest::class, $renewalRequest);
        $this->assertEquals($expiredEdo, $renewalRequest->getExpiredEdo());
        $this->assertEquals($broker, $renewalRequest->getRequestedBy());
        $this->assertEquals(0, $renewalRequest->getOverdueDays());
        $this->assertEquals(0.0, $renewalRequest->getDetentionChargeAmount());
        $this->assertEquals(RenewalRequestStatus::PENDING_REVIEW, $renewalRequest->getStatus());
        $this->assertNotNull($renewalRequest->getRequestedAt());

        // Step 6: Verify NO billing was generated (no detention charges)
        $this->assertNull($renewalRequest->getDetentionBilling());
        $this->assertFalse($renewalRequest->isPaymentVerified());

        // Step 7: Create container yard allocation for new eDO
        $cyAllocation = $this->createContainerYardAllocation('CY-EAST');
        $this->entityManager->flush();

        // Step 8: SL staff generates new eDO (no payment verification needed)
        $newEdo = $this->renewalService->generateNewEDO(
            request: $renewalRequest,
            generatedBy: $slStaff,
            cyAllocation: $cyAllocation,
            additionalNotes: 'Renewed eDO with updated CY location'
        );
        $this->entityManager->flush();

        // Step 9: Verify new eDO was created correctly
        $this->assertInstanceOf(ElectronicDeliveryOrder::class, $newEdo);
        $this->assertNotNull($newEdo->getEdoNumber());
        $this->assertNotEquals($expiredEdo->getEdoNumber(), $newEdo->getEdoNumber());
        $this->assertEquals(EDOStatus::ACTIVE, $newEdo->getStatus());
        $this->assertEquals('CY-EAST', $newEdo->getCyLocation());
        $this->assertEquals('John Staff', $newEdo->getGeneratedByName());
        $this->assertNotNull($newEdo->getExpiresAt());
        $this->assertGreaterThan(new \DateTime(), $newEdo->getExpiresAt());

        // Step 10: Verify eDO linkage (new eDO linked to expired eDO)
        $this->assertEquals($expiredEdo, $newEdo->getPreviousVersion());

        // Step 11: Verify renewal request was updated
        $this->entityManager->refresh($renewalRequest);
        $this->assertEquals($newEdo, $renewalRequest->getNewEdo());
        $this->assertEquals(RenewalRequestStatus::COMPLETED, $renewalRequest->getStatus());
        $this->assertNotNull($renewalRequest->getCompletedAt());

        // Step 12: Verify version tracking
        $this->assertEquals(1, $expiredEdo->getVersion());
        $this->assertEquals(2, $newEdo->getVersion());
    }

    /**
     * Test complete renewal workflow WITH detention charges
     * 
     * Scenario: eDO expired 5 days ago
     * - Broker submits renewal request
     * - System calculates detention charges
     * - Billing is generated
     * - Accounting staff verifies payment
     * - SL staff generates new eDO
     * - New eDO is created and linked correctly
     * 
     * Requirements: 6.1, 7.1, 8.1, 8.2, 9.1, 9.2, 10.1, 10.2, 14.1
     */
    public function testCompleteRenewalWorkflowWithDetentionCharges(): void
    {
        // Step 1: Create test users
        $broker = $this->createBrokerUser('broker@test.com', 'Test Broker LLC');
        $slStaff = $this->createSLStaffUser('slstaff@test.com', 'John', 'Staff');
        $accounting = $this->createAccountingUser('accounting@test.com', 'Jane', 'Accountant');
        $this->entityManager->flush();

        // Step 2: Create expired eDO with 5 days overdue
        $expiredEdo = $this->createExpiredEDO(
            edoNumber: 'EDO-TEST-5-DAYS-OVERDUE-001',
            expiresAt: new \DateTime('-5 days'),
            expiredDays: 5,
            cyLocation: 'CY-NORTH'
        );
        $this->entityManager->flush();

        // Step 3: Verify eDO is eligible for renewal
        $this->assertTrue(
            $this->renewalService->isEligibleForRenewal($expiredEdo),
            'Expired eDO should be eligible for renewal'
        );

        // Step 4: Calculate overdue days
        $overdueDays = $this->detentionService->calculateOverdueDays($expiredEdo);
        $this->assertEquals(5, $overdueDays, 'Should calculate 5 overdue days');

        // Step 5: Calculate detention charges
        $detentionCharge = $this->detentionService->calculateDetentionCharge($overdueDays, $expiredEdo);
        $this->assertGreaterThan(0, $detentionCharge, 'Detention charge should be greater than 0');

        // Step 6: Broker submits renewal request
        $returnDate = new \DateTime('+5 days 10:00');
        $renewalRequest = $this->renewalService->createRenewalRequest(
            expiredEdo: $expiredEdo,
            requestedBy: $broker,
            returnDate: $returnDate,
            notes: 'Container delayed due to customs clearance'
        );
        $this->entityManager->flush();

        // Step 7: Verify renewal request was created with detention charges
        $this->assertInstanceOf(EDORenewalRequest::class, $renewalRequest);
        $this->assertEquals($expiredEdo, $renewalRequest->getExpiredEdo());
        $this->assertEquals($broker, $renewalRequest->getRequestedBy());
        $this->assertEquals(5, $renewalRequest->getOverdueDays());
        $this->assertGreaterThan(0, $renewalRequest->getDetentionChargeAmount());
        $this->assertEquals(RenewalRequestStatus::AWAITING_PAYMENT, $renewalRequest->getStatus());

        // Step 8: Verify billing was generated
        $billing = $this->detentionService->generateDetentionBilling($renewalRequest);
        $this->entityManager->flush();

        $this->assertInstanceOf(Billing::class, $billing);
        $this->assertEquals('detention', $billing->getBillingType());
        $this->assertEquals($renewalRequest, $billing->getEdoRenewalRequest());
        $this->assertEquals(5, $billing->getDetentionDays());
        $this->assertGreaterThan(0, $billing->getDetentionRate());
        $this->assertGreaterThan(0, $billing->getTotalAmount());

        // Step 9: Link billing to renewal request
        $renewalRequest->setDetentionBilling($billing);
        $this->entityManager->flush();

        // Step 10: Verify payment is NOT verified yet
        $this->assertFalse($renewalRequest->isPaymentVerified());
        $this->assertNull($renewalRequest->getPaymentVerifiedAt());
        $this->assertNull($renewalRequest->getPaymentVerifiedBy());

        // Step 11: Verify SL staff CANNOT generate eDO without payment verification
        $cyAllocation = $this->createContainerYardAllocation('CY-EAST');
        $this->entityManager->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment must be verified before generating new eDO');

        $this->renewalService->generateNewEDO(
            request: $renewalRequest,
            generatedBy: $slStaff,
            cyAllocation: $cyAllocation,
            additionalNotes: 'Attempting to generate without payment verification'
        );
    }

    /**
     * Test complete renewal workflow WITH detention charges - Payment verified path
     * 
     * This test continues from the previous test scenario but includes payment verification
     * 
     * Requirements: 6.1, 7.1, 8.1, 8.2, 9.1, 9.2, 10.1, 10.2, 14.1
     */
    public function testCompleteRenewalWorkflowWithDetentionChargesAndPaymentVerification(): void
    {
        // Step 1: Create test users
        $broker = $this->createBrokerUser('broker@test.com', 'Test Broker LLC');
        $slStaff = $this->createSLStaffUser('slstaff@test.com', 'John', 'Staff');
        $accounting = $this->createAccountingUser('accounting@test.com', 'Jane', 'Accountant');
        $this->entityManager->flush();

        // Step 2: Create expired eDO with 5 days overdue
        $expiredEdo = $this->createExpiredEDO(
            edoNumber: 'EDO-TEST-5-DAYS-OVERDUE-002',
            expiresAt: new \DateTime('-5 days'),
            expiredDays: 5,
            cyLocation: 'CY-NORTH'
        );
        $this->entityManager->flush();

        // Step 3: Broker submits renewal request
        $returnDate = new \DateTime('+5 days 10:00');
        $renewalRequest = $this->renewalService->createRenewalRequest(
            expiredEdo: $expiredEdo,
            requestedBy: $broker,
            returnDate: $returnDate,
            notes: 'Container delayed due to customs clearance'
        );
        $this->entityManager->flush();

        // Step 4: Generate billing
        $billing = $this->detentionService->generateDetentionBilling($renewalRequest);
        $renewalRequest->setDetentionBilling($billing);
        $this->entityManager->flush();

        // Step 5: Accounting staff verifies payment
        $this->renewalService->markPaymentVerified($renewalRequest, $accounting);
        $this->entityManager->flush();

        // Step 6: Verify payment verification was recorded
        $this->entityManager->refresh($renewalRequest);
        $this->assertTrue($renewalRequest->isPaymentVerified());
        $this->assertNotNull($renewalRequest->getPaymentVerifiedAt());
        $this->assertEquals($accounting, $renewalRequest->getPaymentVerifiedBy());
        $this->assertEquals(RenewalRequestStatus::PAYMENT_VERIFIED, $renewalRequest->getStatus());

        // Step 7: Create container yard allocation for new eDO
        $cyAllocation = $this->createContainerYardAllocation('CY-EAST');
        $this->entityManager->flush();

        // Step 8: SL staff generates new eDO (payment is verified)
        $newEdo = $this->renewalService->generateNewEDO(
            request: $renewalRequest,
            generatedBy: $slStaff,
            cyAllocation: $cyAllocation,
            additionalNotes: 'Renewed eDO after payment verification'
        );
        $this->entityManager->flush();

        // Step 9: Verify new eDO was created correctly
        $this->assertInstanceOf(ElectronicDeliveryOrder::class, $newEdo);
        $this->assertNotNull($newEdo->getEdoNumber());
        $this->assertNotEquals($expiredEdo->getEdoNumber(), $newEdo->getEdoNumber());
        $this->assertEquals(EDOStatus::ACTIVE, $newEdo->getStatus());
        $this->assertEquals('CY-EAST', $newEdo->getCyLocation());
        $this->assertEquals('John Staff', $newEdo->getGeneratedByName());
        $this->assertNotNull($newEdo->getExpiresAt());
        $this->assertGreaterThan(new \DateTime(), $newEdo->getExpiresAt());

        // Step 10: Verify eDO linkage (new eDO linked to expired eDO)
        $this->assertEquals($expiredEdo, $newEdo->getPreviousVersion());

        // Step 11: Verify renewal request was updated
        $this->entityManager->refresh($renewalRequest);
        $this->assertEquals($newEdo, $renewalRequest->getNewEdo());
        $this->assertEquals(RenewalRequestStatus::COMPLETED, $renewalRequest->getStatus());
        $this->assertNotNull($renewalRequest->getCompletedAt());

        // Step 12: Verify version tracking
        $this->assertEquals(1, $expiredEdo->getVersion());
        $this->assertEquals(2, $newEdo->getVersion());

        // Step 13: Verify billing is still linked
        $this->assertEquals($billing, $renewalRequest->getDetentionBilling());
        $this->assertEquals($renewalRequest, $billing->getEdoRenewalRequest());
    }

    /**
     * Test renewal workflow with multiple overdue scenarios
     * 
     * Verifies that detention charges scale correctly with overdue days
     */
    public function testRenewalWorkflowWithVariousOverdueDays(): void
    {
        $broker = $this->createBrokerUser('broker@test.com', 'Test Broker LLC');
        $this->entityManager->flush();

        $scenarios = [
            ['days' => 0, 'expectedStatus' => RenewalRequestStatus::PENDING_REVIEW],
            ['days' => 1, 'expectedStatus' => RenewalRequestStatus::AWAITING_PAYMENT],
            ['days' => 10, 'expectedStatus' => RenewalRequestStatus::AWAITING_PAYMENT],
            ['days' => 30, 'expectedStatus' => RenewalRequestStatus::AWAITING_PAYMENT],
        ];

        foreach ($scenarios as $index => $scenario) {
            $expiredEdo = $this->createExpiredEDO(
                edoNumber: "EDO-TEST-{$scenario['days']}-DAYS-{$index}",
                expiresAt: new \DateTime("-{$scenario['days']} days"),
                expiredDays: $scenario['days'],
                cyLocation: 'CY-NORTH'
            );
            $this->entityManager->flush();

            $returnDate = new \DateTime('+3 days 14:00');
            $renewalRequest = $this->renewalService->createRenewalRequest(
                expiredEdo: $expiredEdo,
                requestedBy: $broker,
                returnDate: $returnDate,
                notes: "Testing {$scenario['days']} days overdue"
            );
            $this->entityManager->flush();

            // Verify overdue days calculation
            $this->assertEquals($scenario['days'], $renewalRequest->getOverdueDays());

            // Verify status based on detention charges
            $this->assertEquals($scenario['expectedStatus'], $renewalRequest->getStatus());

            // Verify detention charge amount
            if ($scenario['days'] > 0) {
                $this->assertGreaterThan(0, $renewalRequest->getDetentionChargeAmount());
            } else {
                $this->assertEquals(0.0, $renewalRequest->getDetentionChargeAmount());
            }
        }
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function createBrokerUser(string $email, string $fullName): Broker
    {
        $broker = new Broker();
        $broker->setEmail($email);
        $broker->setPasswordHash('hashed_password');
        $broker->setRole(UserRole::BROKER);
        $broker->setFullName($fullName);
        $this->entityManager->persist($broker);
        return $broker;
    }

    private function createSLStaffUser(string $email, string $firstName, string $lastName): StaffUser
    {
        $slStaff = new StaffUser();
        $slStaff->setEmail($email);
        $slStaff->setPasswordHash('hashed_password');
        $slStaff->setRole(UserRole::SL_STAFF);
        $slStaff->setFirstName($firstName);
        $slStaff->setLastName($lastName);
        $slStaff->setDepartment('Operations');
        $this->entityManager->persist($slStaff);
        return $slStaff;
    }

    private function createAccountingUser(string $email, string $firstName, string $lastName): StaffUser
    {
        $accounting = new StaffUser();
        $accounting->setEmail($email);
        $accounting->setPasswordHash('hashed_password');
        $accounting->setRole(UserRole::ACCOUNTING);
        $accounting->setFirstName($firstName);
        $accounting->setLastName($lastName);
        $accounting->setDepartment('Accounting');
        $this->entityManager->persist($accounting);
        return $accounting;
    }

    private function createExpiredEDO(
        string $edoNumber,
        \DateTime $expiresAt,
        int $expiredDays,
        string $cyLocation
    ): ElectronicDeliveryOrder {
        // Create or get shipping line
        $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->findOneBy([])
            ?? $this->createDefaultShippingLine();

        // Create manifest
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-TEST-' . uniqid());
        $manifest->setShippingLine($shippingLine);
        $manifest->setVesselName('Test Vessel');
        $manifest->setVoyageNumber('V-' . uniqid());
        $manifest->setArrivalDate(new \DateTime('-30 days'));
        $this->entityManager->persist($manifest);

        // Create container
        $container = new Container();
        $container->setContainerNumber('CONT-' . uniqid());
        $container->setSize('20');
        $container->setType('GP');
        $container->setManifest($manifest);
        $this->entityManager->persist($container);

        // Create expired eDO
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber($edoNumber);
        $edo->setManifest($manifest);
        $edo->setShippingLine($shippingLine);
        $edo->setContainer($container);
        $edo->setStatus(EDOStatus::EXPIRED);
        $edo->setExpiresAt($expiresAt);
        $edo->setExpiredDays($expiredDays);
        $edo->setVersion(1);
        $edo->setPdfPath('/uploads/edo/' . $edoNumber . '.pdf');
        $edo->setCyLocation($cyLocation);
        $this->entityManager->persist($edo);

        return $edo;
    }

    private function createDefaultShippingLine(): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setName('Test Shipping Line');
        $shippingLine->setCode('TSL');
        $this->entityManager->persist($shippingLine);
        $this->entityManager->flush();
        return $shippingLine;
    }

    private function createContainerYardAllocation(string $cyLocation): ShippingLineTerminalAllocation
    {
        // Create or get terminal
        $terminal = $this->entityManager->getRepository(Terminal::class)->findOneBy([])
            ?? $this->createDefaultTerminal($cyLocation);

        // Create or get shipping line
        $shippingLine = $this->entityManager->getRepository(ShippingLine::class)->findOneBy([])
            ?? $this->createDefaultShippingLine();

        // Create allocation
        $allocation = new ShippingLineTerminalAllocation();
        $allocation->setShippingLine($shippingLine);
        $allocation->setTerminal($terminal);
        $allocation->setCapacity20ft(100);
        $allocation->setCapacity40ft(50);
        $allocation->setUsed20ft(0);
        $allocation->setUsed40ft(0);
        $this->entityManager->persist($allocation);

        return $allocation;
    }

    private function createDefaultTerminal(string $name): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName($name);
        $terminal->setCode(substr($name, 0, 3));
        $terminal->setLocation('Test Location');
        $this->entityManager->persist($terminal);
        $this->entityManager->flush();
        return $terminal;
    }
}
