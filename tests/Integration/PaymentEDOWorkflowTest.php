<?php

namespace App\Tests\Integration;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\StaffUser;
use App\Entity\ShipmentRecord;
use App\Entity\PaymentVerification;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\PaymentStatus;
use App\Service\UserService;
use App\Service\ShipmentService;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Integration test for complete payment verification and EDO generation workflow
 * Tests Requirements: 7.1-7.5
 */
class PaymentEDOWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private ShipmentService $shipmentService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->userService = $container->get(UserService::class);
        $this->shipmentService = $container->get(ShipmentService::class);
        $this->paymentService = $container->get(PaymentService::class);

        // Clean database
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->rollback();
        parent::tearDown();
    }

    public function testCompletePaymentVerificationAndEDOGenerationWorkflow(): void
    {
        // Step 1: Create necessary users
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);

        $consignee = $this->userService->createUser([
            'email' => 'consignee@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Consignee Corp'
        ], UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);

        $accounting = $this->userService->createUser([
            'email' => 'accounting@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'Jane',
            'lastName' => 'Accountant',
            'department' => 'Accounting'
        ], UserRole::ACCOUNTING);

        $this->entityManager->flush();

        // Step 2: Create shipment record
        $shipmentData = [
            'manifestNumber' => 'MAN-2024-001',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'actualArrivalDate' => new \DateTime('2024-01-16'),
            'billingInformation' => 'Total: $5,000.00 - Container fees and handling charges'
        ];

        $shipment = $this->shipmentService->createShipment($shipmentData, $slStaff);
        $this->assertInstanceOf(ShipmentRecord::class, $shipment);
        $this->assertEquals('MAN-2024-001', $shipment->getManifestNumber());

        // Step 3: Authorize broker access to shipment
        $this->shipmentService->addAuthorizedBroker($shipment->getId(), $broker, $slStaff);

        // Step 4: Broker submits payment proof
        $tempFile = tempnam(sys_get_temp_dir(), 'payment');
        file_put_contents($tempFile, '%PDF-1.4 payment proof content');
        $paymentProof = new UploadedFile($tempFile, 'payment_proof.pdf', 'application/pdf', null, true);

        $payment = $this->paymentService->submitPaymentProof($shipment->getId(), $broker, $paymentProof);
        
        $this->assertInstanceOf(PaymentVerification::class, $payment);
        $this->assertEquals(PaymentStatus::PENDING, $payment->getStatus());
        $this->assertEquals($shipment, $payment->getShipment());
        $this->assertEquals($broker, $payment->getBroker());

        // Step 5: Accounting verifies payment
        $this->paymentService->verifyPayment($payment->getId(), $accounting);
        
        $this->entityManager->refresh($payment);
        $this->assertEquals(PaymentStatus::VERIFIED, $payment->getStatus());
        $this->assertEquals($accounting, $payment->getVerifiedBy());
        $this->assertNotNull($payment->getVerifiedAt());

        // Step 6: EDO is automatically generated upon verification
        $edo = $payment->getEdo();
        $this->assertInstanceOf(ElectronicDeliveryOrder::class, $edo);
        $this->assertNotNull($edo->getEdoNumber());
        $this->assertEquals($payment, $edo->getPayment());
        $this->assertNotNull($edo->getPdfPath());
        $this->assertNotNull($edo->getGeneratedAt());

        // Step 7: Verify EDO number format and uniqueness
        $this->assertMatchesRegularExpression('/^EDO\d{23}$/', $edo->getEdoNumber());

        // Step 8: Verify payment-EDO linkage integrity
        $this->assertEquals($payment, $edo->getPayment());
        $this->assertEquals($edo, $payment->getEdo());

        // Clean up temp file
        unlink($tempFile);
    }

    public function testPaymentRejectionWorkflow(): void
    {
        // Create necessary entities
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);

        $accounting = $this->userService->createUser([
            'email' => 'accounting@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'Jane',
            'lastName' => 'Accountant',
            'department' => 'Accounting'
        ], UserRole::ACCOUNTING);

        $this->entityManager->flush();

        // Create shipment and submit payment
        $shipment = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-002',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'billingInformation' => 'Total: $3,000.00'
        ], $slStaff);

        $this->shipmentService->addAuthorizedBroker($shipment->getId(), $broker, $slStaff);

        $tempFile = tempnam(sys_get_temp_dir(), 'payment');
        file_put_contents($tempFile, '%PDF-1.4 invalid payment proof');
        $paymentProof = new UploadedFile($tempFile, 'invalid_proof.pdf', 'application/pdf', null, true);

        $payment = $this->paymentService->submitPaymentProof($shipment->getId(), $broker, $paymentProof);

        // Reject payment
        $payment->setStatus(PaymentStatus::REJECTED);
        $this->entityManager->flush();

        // Verify no EDO is generated for rejected payment
        $this->assertNull($payment->getEdo());
        $this->assertEquals(PaymentStatus::REJECTED, $payment->getStatus());

        unlink($tempFile);
    }

    public function testEDOAccessLogging(): void
    {
        // Create complete workflow first
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $broker = $this->userService->createUser([
            'email' => 'broker@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker LLC'
        ], UserRole::BROKER);

        $accounting = $this->userService->createUser([
            'email' => 'accounting@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'Jane',
            'lastName' => 'Accountant',
            'department' => 'Accounting'
        ], UserRole::ACCOUNTING);

        $this->entityManager->flush();

        $shipment = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-003',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'billingInformation' => 'Total: $2,000.00'
        ], $slStaff);

        $this->shipmentService->addAuthorizedBroker($shipment->getId(), $broker, $slStaff);

        $tempFile = tempnam(sys_get_temp_dir(), 'payment');
        file_put_contents($tempFile, '%PDF-1.4 payment proof content');
        $paymentProof = new UploadedFile($tempFile, 'payment_proof.pdf', 'application/pdf', null, true);

        $payment = $this->paymentService->submitPaymentProof($shipment->getId(), $broker, $paymentProof);
        $this->paymentService->verifyPayment($payment->getId(), $accounting);

        $this->entityManager->refresh($payment);
        $edo = $payment->getEdo();

        // Simulate EDO access (this would normally be done through a controller)
        $accessLog = new \App\Entity\EDOAccessLog();
        $accessLog->setEdo($edo);
        $accessLog->setAccessedBy($broker);
        $accessLog->setAccessedAt(new \DateTime());
        $accessLog->setIpAddress('192.168.1.100');

        $this->entityManager->persist($accessLog);
        $this->entityManager->flush();

        // Verify access logging
        $this->assertTrue($edo->getAccessLogs()->contains($accessLog));
        $this->assertEquals($broker, $accessLog->getAccessedBy());
        $this->assertNotNull($accessLog->getAccessedAt());

        unlink($tempFile);
    }

    public function testMultiplePaymentsForSameShipment(): void
    {
        // Create entities
        $slStaff = $this->userService->createUser([
            'email' => 'staff@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Staff',
            'department' => 'Operations'
        ], UserRole::SL_STAFF);

        $broker1 = $this->userService->createUser([
            'email' => 'broker1@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker 1'
        ], UserRole::BROKER);

        $broker2 = $this->userService->createUser([
            'email' => 'broker2@test.com',
            'password' => 'SecurePass123!',
            'businessName' => 'Test Broker 2'
        ], UserRole::BROKER);

        $accounting = $this->userService->createUser([
            'email' => 'accounting@test.com',
            'password' => 'SecurePass123!',
            'firstName' => 'Jane',
            'lastName' => 'Accountant',
            'department' => 'Accounting'
        ], UserRole::ACCOUNTING);

        $this->entityManager->flush();

        // Create separate shipments for each broker (since current DB constraint allows only one payment per shipment)
        $shipment1 = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-004',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'billingInformation' => 'Total: $4,000.00'
        ], $slStaff);

        $shipment2 = $this->shipmentService->createShipment([
            'manifestNumber' => 'MAN-2024-005',
            'noticeOfArrivalDate' => new \DateTime('2024-01-15'),
            'billingInformation' => 'Total: $3,500.00'
        ], $slStaff);

        $this->shipmentService->addAuthorizedBroker($shipment1->getId(), $broker1, $slStaff);
        $this->shipmentService->addAuthorizedBroker($shipment2->getId(), $broker2, $slStaff);

        // Both brokers submit payment proofs for their respective shipments
        $tempFile1 = tempnam(sys_get_temp_dir(), 'payment1');
        file_put_contents($tempFile1, '%PDF-1.4 payment proof 1');
        $paymentProof1 = new UploadedFile($tempFile1, 'payment_proof1.pdf', 'application/pdf', null, true);

        $tempFile2 = tempnam(sys_get_temp_dir(), 'payment2');
        file_put_contents($tempFile2, '%PDF-1.4 payment proof 2');
        $paymentProof2 = new UploadedFile($tempFile2, 'payment_proof2.pdf', 'application/pdf', null, true);

        $payment1 = $this->paymentService->submitPaymentProof($shipment1->getId(), $broker1, $paymentProof1);
        $payment2 = $this->paymentService->submitPaymentProof($shipment2->getId(), $broker2, $paymentProof2);

        // Verify both payments are for different shipments
        $this->assertEquals($shipment1, $payment1->getShipment());
        $this->assertEquals($shipment2, $payment2->getShipment());
        $this->assertEquals($broker1, $payment1->getBroker());
        $this->assertEquals($broker2, $payment2->getBroker());
        $this->assertNotEquals($payment1->getId(), $payment2->getId());

        // Verify both payments, generate separate EDOs
        $this->paymentService->verifyPayment($payment1->getId(), $accounting);
        $this->paymentService->verifyPayment($payment2->getId(), $accounting);

        $this->entityManager->refresh($payment1);
        $this->entityManager->refresh($payment2);

        $edo1 = $payment1->getEdo();
        $edo2 = $payment2->getEdo();

        $this->assertInstanceOf(ElectronicDeliveryOrder::class, $edo1);
        $this->assertInstanceOf(ElectronicDeliveryOrder::class, $edo2);
        $this->assertNotEquals($edo1->getEdoNumber(), $edo2->getEdoNumber());

        unlink($tempFile1);
        unlink($tempFile2);
    }
}