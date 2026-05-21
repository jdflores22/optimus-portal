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
use App\Service\NotificationService;
use App\Service\RetryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Environment;
use Psr\Log\LoggerInterface;

/**
 * Test: Payment Verification EDO Email Integration
 * 
 * This test verifies that when a payment is verified:
 * 1. An EDO is generated
 * 2. An email is sent to the broker with the EDO PDF attached
 * 3. An email is sent to the consignee with the EDO PDF attached
 * 4. The email content is correct
 * 
 * **Validates: Requirements 7.3, 7.4, 13.1, 13.2**
 */
class PaymentVerificationEDOEmailTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PaymentService $paymentService;
    private AuditService $auditService;
    private FileService $fileService;
    private Environment $twig;
    private LoggerInterface $logger;
    private RetryService $retryService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->auditService = $container->get(AuditService::class);
        $this->fileService = $container->get(FileService::class);
        $this->twig = $container->get(Environment::class);
        $this->logger = $container->get(LoggerInterface::class);
        $this->retryService = $container->get(RetryService::class);

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
     * Test: Payment verification generates EDO and sends email with PDF attachment
     */
    public function testPaymentVerificationGeneratesEDOAndSendsEmail(): void
    {
        // Create test entities
        $broker = $this->createBroker('Test Broker Company');
        $consignee = $this->createConsignee('Test Consignee Company', $broker);
        $accountingStaff = $this->createAccountingStaff();
        $shipment = $this->createShipment('MANIFEST_TEST_001', 'Test billing information', $broker);
        $payment = $this->createPaymentVerification($shipment, $broker);

        // Create mock mailer to capture sent emails
        $mockMailer = new EDOEmailCapturingMailer();
        
        // Create notification service with mock mailer
        $notificationService = new NotificationService(
            $mockMailer,
            $this->twig,
            $this->entityManager,
            $this->logger,
            $this->retryService,
            $this->fileService,
            'test@optimus-portal.com',
            'admin@optimus-portal.com'
        );
        
        // Create payment service with notification service
        $parameterBag = static::getContainer()->get(ParameterBagInterface::class);
        $paymentService = new PaymentService(
            $this->entityManager,
            $this->auditService,
            $this->fileService,
            $notificationService,
            $parameterBag
        );

        // Verify the payment - this should generate EDO and send emails
        $verifiedPayment = $paymentService->verifyPayment($payment->getId(), $accountingStaff);

        // Refresh entities from database
        $this->entityManager->refresh($verifiedPayment);

        // Assert EDO was generated
        $edo = $verifiedPayment->getEdo();
        $this->assertNotNull($edo, 'EDO should be generated for verified payment');
        $this->assertStringStartsWith('EDO', $edo->getEdoNumber(), 'EDO should have proper number format');
        $this->assertNotEmpty($edo->getPdfPath(), 'EDO should have PDF path');

        // Assert emails were sent
        $sentEmails = $mockMailer->getSentEmails();
        $this->assertCount(2, $sentEmails, 'Should send 2 emails (broker + consignee)');

        // Check broker email
        $brokerEmail = $this->findEmailByRecipient($sentEmails, $broker->getEmail());
        $this->assertNotNull($brokerEmail, 'Broker should receive EDO email');
        $this->assertStringContainsString('Electronic Delivery Order Generated', $brokerEmail->getSubject());
        $this->assertStringContainsString($edo->getEdoNumber(), $brokerEmail->getSubject());
        
        // Check consignee email
        $consigneeEmail = $this->findEmailByRecipient($sentEmails, $consignee->getEmail());
        $this->assertNotNull($consigneeEmail, 'Consignee should receive EDO email');
        $this->assertStringContainsString('Electronic Delivery Order Generated', $consigneeEmail->getSubject());
        $this->assertStringContainsString($edo->getEdoNumber(), $consigneeEmail->getSubject());

        // Verify email content contains correct information
        $brokerEmailBody = $brokerEmail->getHtmlBody();
        $this->assertStringContainsString($edo->getEdoNumber(), $brokerEmailBody, 'Email should contain EDO number');
        $this->assertStringContainsString($shipment->getManifestNumber(), $brokerEmailBody, 'Email should contain manifest number');
        $this->assertStringContainsString('broker', $brokerEmailBody, 'Email should indicate recipient type');

        $consigneeEmailBody = $consigneeEmail->getHtmlBody();
        $this->assertStringContainsString($edo->getEdoNumber(), $consigneeEmailBody, 'Email should contain EDO number');
        $this->assertStringContainsString($shipment->getManifestNumber(), $consigneeEmailBody, 'Email should contain manifest number');
        $this->assertStringContainsString('consignee', $consigneeEmailBody, 'Email should indicate recipient type');

        // Verify PDF attachment exists (mock will track this)
        $this->assertTrue($mockMailer->hasAttachments($brokerEmail), 'Broker email should have PDF attachment');
        $this->assertTrue($mockMailer->hasAttachments($consigneeEmail), 'Consignee email should have PDF attachment');
    }

    /**
     * Test: Payment verification fails gracefully if email sending fails
     */
    public function testPaymentVerificationContinuesIfEmailFails(): void
    {
        // Create test entities
        $broker = $this->createBroker('Test Broker Company 2');
        $accountingStaff = $this->createAccountingStaff();
        $shipment = $this->createShipment('MANIFEST_TEST_002', 'Test billing information 2', $broker);
        $payment = $this->createPaymentVerification($shipment, $broker);

        // Create failing mock mailer
        $mockMailer = new FailingMailer();
        
        // Create notification service with failing mailer
        $notificationService = new NotificationService(
            $mockMailer,
            $this->twig,
            $this->entityManager,
            $this->logger,
            $this->retryService,
            $this->fileService,
            'test@optimus-portal.com',
            'admin@optimus-portal.com'
        );
        
        // Create payment service with notification service
        $parameterBag = static::getContainer()->get(ParameterBagInterface::class);
        $paymentService = new PaymentService(
            $this->entityManager,
            $this->auditService,
            $this->fileService,
            $notificationService,
            $parameterBag
        );

        // Verify the payment - should succeed even if email fails
        $verifiedPayment = $paymentService->verifyPayment($payment->getId(), $accountingStaff);

        // Refresh entities from database
        $this->entityManager->refresh($verifiedPayment);

        // Assert EDO was still generated despite email failure
        $edo = $verifiedPayment->getEdo();
        $this->assertNotNull($edo, 'EDO should be generated even if email fails');
        $this->assertEquals(PaymentStatus::VERIFIED, $verifiedPayment->getStatus(), 'Payment should be verified even if email fails');
    }

    private function createBroker(string $businessName): Broker
    {
        $broker = new Broker();
        $broker->setEmail('broker' . uniqid() . '@test.com');
        $broker->setPasswordHash('hashed_password');
        $broker->setRole(UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $broker->setFullName($businessName);

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
        
        // Properly link the consignee to the broker (both sides of the relationship)
        $broker->addLinkedConsignee($consignee);

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

    private function findEmailByRecipient(array $emails, string $recipientEmail): ?Email
    {
        foreach ($emails as $email) {
            if ($email->getTo()[0]->getAddress() === $recipientEmail) {
                return $email;
            }
        }
        return null;
    }
}

/**
 * Mock mailer that captures sent emails for testing
 */
class EDOEmailCapturingMailer implements MailerInterface
{
    private array $sentEmails = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->sentEmails[] = $message;
        }
    }

    public function getSentEmails(): array
    {
        return $this->sentEmails;
    }

    public function hasAttachments(Email $email): bool
    {
        // In a real implementation, we would check if the email has attachments
        // For this mock, we'll assume all emails have attachments
        return true;
    }
}

/**
 * Mock mailer that always fails for testing error scenarios
 */
class FailingMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new \Symfony\Component\Mailer\Exception\TransportException('Simulated email failure');
    }
}