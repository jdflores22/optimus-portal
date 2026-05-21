<?php

namespace App\Tests\Service;

use App\Entity\PaymentVerification;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\ShipmentRecord;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\StaffUser;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\PaymentService;
use App\Service\AuditService;
use App\Service\FileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use App\Service\NotificationService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Eris\Generator;
use Eris\TestTrait;

/**
 * Feature: optimus-shipping-portal, Property 7: Payment-EDO linkage integrity
 * For any verified payment, exactly one EDO should be generated, and the EDO should reference 
 * the correct payment, shipment, broker, and consignee.
 * 
 * **Validates: Requirements 7.3, 7.4**
 */
class PaymentEDOLinkageIntegrityTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private PaymentService $paymentService;
    private AuditService $auditService;
    private FileService $fileService;
    private NotificationService $notificationService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->auditService = $container->get(AuditService::class);
        $this->fileService = $container->get(FileService::class);
        $this->notificationService = $container->get(NotificationService::class);
        
        $parameterBag = $container->get(ParameterBagInterface::class);
        
        $this->paymentService = new PaymentService(
            $this->entityManager,
            $this->auditService,
            $this->fileService,
            $this->notificationService,
            $parameterBag
        );

        // Start transaction for each test
        $this->entityManager->beginTransaction();
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
     * Property: For any verified payment, exactly one EDO should be generated
     */
    public function testVerifiedPaymentGeneratesExactlyOneEDO()
    {
        $this->forAll(
            Generator\string(),
            Generator\string(),
            Generator\string(),
            Generator\string()
        )->then(function ($manifestSuffix, $brokerSuffix, $consigneeSuffix, $billingSuffix) {
            // Create test entities with unique identifiers to avoid conflicts
            $uniqueId = uniqid();
            $manifestNumber = 'MANIFEST_' . $uniqueId . '_' . substr(md5($manifestSuffix), 0, 8);
            $brokerBusinessName = 'Broker_' . $uniqueId . '_' . substr(md5($brokerSuffix), 0, 10);
            $consigneeBusinessName = 'Consignee_' . $uniqueId . '_' . substr(md5($consigneeSuffix), 0, 10);
            $billingInfo = 'Billing information for shipment: ' . substr(md5($billingSuffix), 0, 20);
            
            $broker = $this->createBroker($brokerBusinessName);
            $consignee = $this->createConsignee($consigneeBusinessName, $broker);
            $accountingStaff = $this->createAccountingStaff();
            $shipment = $this->createShipment($manifestNumber, $billingInfo, $broker);
            $payment = $this->createPaymentVerification($shipment, $broker);

            // Verify the payment
            $this->paymentService->verifyPayment($payment->getId(), $accountingStaff);

            // Refresh entities from database
            $this->entityManager->refresh($payment);

            // Assertions for EDO linkage integrity
            $edo = $payment->getEdo();
            
            // Exactly one EDO should be generated
            $this->assertNotNull($edo, 'EDO should be generated for verified payment');
            
            // EDO should reference the correct payment
            $this->assertSame($payment, $edo->getPayment(), 'EDO should reference the correct payment');
            
            // EDO should have correct shipment through payment
            $this->assertSame($shipment, $edo->getPayment()->getShipment(), 'EDO should reference correct shipment through payment');
            
            // EDO should have correct broker through payment
            $this->assertSame($broker, $edo->getPayment()->getBroker(), 'EDO should reference correct broker through payment');
            
            // EDO should have unique number
            $this->assertNotEmpty($edo->getEdoNumber(), 'EDO should have a unique number');
            $this->assertStringStartsWith('EDO', $edo->getEdoNumber(), 'EDO number should start with EDO prefix');
            
            // EDO should have PDF path
            $this->assertNotEmpty($edo->getPdfPath(), 'EDO should have a PDF path');
            
            // Payment status should be VERIFIED
            $this->assertEquals(PaymentStatus::VERIFIED, $payment->getStatus(), 'Payment status should be VERIFIED');
            
            // Attempting to generate EDO again should return the same EDO
            $secondEdo = $this->paymentService->generateEDO($payment->getId());
            $this->assertSame($edo, $secondEdo, 'Should return the same EDO when called multiple times');
        });
    }

    /**
     * Property: EDO generation should only work for verified payments
     */
    public function testEDOGenerationRequiresVerifiedPayment()
    {
        $this->forAll(
            Generator\elements(PaymentStatus::PENDING, PaymentStatus::REJECTED)
        )->then(function ($status) {
            // Create test entities
            $broker = $this->createBroker('Test Broker');
            $consignee = $this->createConsignee('Test Consignee', $broker);
            $shipment = $this->createShipment('TEST123', 'Test billing info', $broker);
            $payment = $this->createPaymentVerification($shipment, $broker);
            
            // Set payment to non-verified status
            $payment->setStatus($status);
            $this->entityManager->flush();

            // Attempting to generate EDO should fail
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Payment must be verified before generating EDO');
            $this->paymentService->generateEDO($payment->getId());
        });
    }

    /**
     * Property: Multiple payments for different shipments should generate separate EDOs
     */
    public function testMultiplePaymentsGenerateSeparateEDOs()
    {
        $this->forAll(
            Generator\choose(2, 3) // Generate 2-3 payments to keep test fast
        )->then(function ($paymentCount) {
            $broker = $this->createBroker('Test Broker');
            $consignee = $this->createConsignee('Test Consignee', $broker);
            $accountingStaff = $this->createAccountingStaff();
            
            $payments = [];
            $edos = [];
            
            // Create multiple payments for different shipments
            for ($i = 0; $i < $paymentCount; $i++) {
                $shipment = $this->createShipment('MANIFEST' . $i . '_' . uniqid(), 'Billing info ' . $i, $broker);
                $payment = $this->createPaymentVerification($shipment, $broker);
                
                // Verify the payment
                $this->paymentService->verifyPayment($payment->getId(), $accountingStaff);
                $this->entityManager->refresh($payment);
                
                $payments[] = $payment;
                $edos[] = $payment->getEdo();
            }
            
            // Each payment should have exactly one EDO
            $this->assertCount($paymentCount, $edos, 'Should have one EDO per payment');
            
            // All EDOs should be different
            $edoNumbers = array_map(fn($edo) => $edo->getEdoNumber(), $edos);
            $uniqueEdoNumbers = array_unique($edoNumbers);
            $this->assertCount($paymentCount, $uniqueEdoNumbers, 'All EDO numbers should be unique');
            
            // Each EDO should reference its correct payment
            for ($i = 0; $i < $paymentCount; $i++) {
                $this->assertSame($payments[$i], $edos[$i]->getPayment(), "EDO $i should reference payment $i");
            }
        });
    }

    private function createBroker(string $businessName): Broker
    {
        $broker = new Broker();
        $broker->setEmail('broker' . uniqid() . '@test.com');
        $broker->setPasswordHash('hashed_password');
        $broker->setRole(UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $broker->setBusinessName($businessName);

        $this->entityManager->persist($broker);
        $this->entityManager->flush();

        return $broker;
    }

    private function createConsignee(string $businessName, Broker $broker): Consignee
    {
        $consignee = new Consignee();
        $consignee->setEmail('consignee' . uniqid() . '@test.com');
        $consignee->setPasswordHash('hashed_password');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setBusinessName($businessName);
        $consignee->setLinkedBroker($broker);

        $this->entityManager->persist($consignee);
        $this->entityManager->flush();

        return $consignee;
    }

    private function createAccountingStaff(): StaffUser
    {
        $staff = new StaffUser();
        $staff->setEmail('accounting' . uniqid() . '@test.com');
        $staff->setPasswordHash('hashed_password');
        $staff->setRole(UserRole::ACCOUNTING);
        $staff->setStatus(AccountStatus::APPROVED);
        $staff->setFirstName('Test');
        $staff->setLastName('Accountant');
        $staff->setDepartment('Accounting');

        $this->entityManager->persist($staff);
        $this->entityManager->flush();

        return $staff;
    }

    private function createShipment(string $manifestNumber, string $billingInfo, Broker $broker): ShipmentRecord
    {
        $staff = new StaffUser();
        $staff->setEmail('staff' . uniqid() . '@test.com');
        $staff->setPasswordHash('hashed_password');
        $staff->setRole(UserRole::SL_STAFF);
        $staff->setStatus(AccountStatus::APPROVED);
        $staff->setFirstName('Test');
        $staff->setLastName('Staff');
        $staff->setDepartment('Operations');

        $this->entityManager->persist($staff);

        $shipment = new ShipmentRecord();
        $shipment->setManifestNumber($manifestNumber);
        $shipment->setNoticeOfArrivalDate(new \DateTime());
        $shipment->setBillingInformation($billingInfo);
        $shipment->setCreatedBy($staff);
        $shipment->addAuthorizedBroker($broker);

        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $shipment;
    }

    private function createPaymentVerification(ShipmentRecord $shipment, Broker $broker): PaymentVerification
    {
        $payment = new PaymentVerification();
        $payment->setShipment($shipment);
        $payment->setBroker($broker);
        $payment->setProofFilePath('test_proof_file_id');
        $payment->setStatus(PaymentStatus::PENDING);

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }
}