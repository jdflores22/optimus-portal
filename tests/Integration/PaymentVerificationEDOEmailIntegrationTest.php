<?php

namespace App\Tests\Integration;

use App\Entity\PaymentVerification;
use App\Entity\ShipmentRecord;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\StaffUser;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration Test: Payment Verification EDO Email Flow
 * 
 * This test verifies the complete end-to-end flow:
 * 1. Accounting staff can access payment verification page
 * 2. Clicking "Verify Payment" generates EDO and sends emails
 * 3. Success message is displayed
 * 4. User is redirected to dashboard
 * 
 * **Validates: Requirements 7.3, 7.4, 13.1, 13.2**
 */
class PaymentVerificationEDOEmailIntegrationTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Test: Complete payment verification flow through web interface
     */
    public function testPaymentVerificationWebFlow(): void
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();

        // Create test entities
        $broker = $this->createBroker('Integration Test Broker', $entityManager);
        $consignee = $this->createConsignee('Integration Test Consignee', $broker, $entityManager);
        $accountingStaff = $this->createAccountingStaff($entityManager);
        $shipment = $this->createShipment('INTEGRATION_TEST_001', 'Integration test billing', $broker, $entityManager);
        $payment = $this->createPaymentVerification($shipment, $broker, $entityManager);

        // Login as accounting staff
        $client->loginUser($accountingStaff);

        // Access payment verification page
        $crawler = $client->request('GET', '/payment/verify/' . $payment->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Payment Verification');
        $this->assertSelectorExists('button:contains("Verify Payment")');

        // Submit payment verification
        $form = $crawler->selectButton('Verify Payment')->form();
        $client->submit($form);

        // Should redirect to accounting dashboard
        $this->assertResponseRedirects('/payment/dashboard');
        $client->followRedirect();

        // Should show success message
        $this->assertSelectorExists('.bg-green-50:contains("Payment verified successfully")');
        $this->assertSelectorExists('.bg-green-50:contains("EDO has been generated and sent via email")');

        // Verify payment status in database
        $entityManager->refresh($payment);
        $this->assertEquals(PaymentStatus::VERIFIED, $payment->getStatus());
        $this->assertNotNull($payment->getVerifiedBy());
        $this->assertNotNull($payment->getVerifiedAt());

        // Verify EDO was generated
        $edo = $payment->getEdo();
        $this->assertNotNull($edo, 'EDO should be generated');
        $this->assertStringStartsWith('EDO', $edo->getEdoNumber());
        $this->assertNotEmpty($edo->getPdfPath());
    }

    /**
     * Test: Payment verification page shows correct information
     */
    public function testPaymentVerificationPageContent(): void
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();

        // Create test entities
        $broker = $this->createBroker('Content Test Broker', $entityManager);
        $accountingStaff = $this->createAccountingStaff($entityManager);
        $shipment = $this->createShipment('CONTENT_TEST_001', 'Content test billing', $broker, $entityManager);
        $payment = $this->createPaymentVerification($shipment, $broker, $entityManager);

        // Login as accounting staff
        $client->loginUser($accountingStaff);

        // Access payment verification page
        $crawler = $client->request('GET', '/payment/verify/' . $payment->getId());
        $this->assertResponseIsSuccessful();

        // Check that all required information is displayed
        $this->assertSelectorTextContains('body', $shipment->getManifestNumber());
        $this->assertSelectorTextContains('body', $broker->getBusinessName());
        $this->assertSelectorTextContains('body', $broker->getEmail());
        $this->assertSelectorTextContains('body', 'Pending Review');
        
        // Check that verification actions are available
        $this->assertSelectorExists('button:contains("Verify Payment")');
        $this->assertSelectorExists('button:contains("Reject Payment")');
    }

    /**
     * Test: Only accounting staff can access payment verification
     */
    public function testPaymentVerificationAccessControl(): void
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();

        // Create test entities
        $broker = $this->createBroker('Access Test Broker', $entityManager);
        $shipment = $this->createShipment('ACCESS_TEST_001', 'Access test billing', $broker, $entityManager);
        $payment = $this->createPaymentVerification($shipment, $broker, $entityManager);

        // Try to access without login - should redirect to login
        $client->request('GET', '/payment/verify/' . $payment->getId());
        $this->assertResponseRedirects();

        // Try to access as broker - should be forbidden
        $client->loginUser($broker);
        $client->request('GET', '/payment/verify/' . $payment->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function createBroker(string $businessName, EntityManagerInterface $entityManager): Broker
    {
        $broker = new Broker();
        $broker->setEmail('broker' . uniqid() . '@test.com');
        $broker->setPasswordHash('hashed_password');
        $broker->setRole(UserRole::BROKER);
        $broker->setStatus(AccountStatus::APPROVED);
        $broker->setBusinessName($businessName);

        $entityManager->persist($broker);
        $entityManager->flush();

        return $broker;
    }

    private function createConsignee(string $businessName, Broker $broker, EntityManagerInterface $entityManager): Consignee
    {
        $consignee = new Consignee();
        $consignee->setEmail('consignee' . uniqid() . '@test.com');
        $consignee->setPasswordHash('hashed_password');
        $consignee->setRole(UserRole::CONSIGNEE);
        $consignee->setStatus(AccountStatus::APPROVED);
        $consignee->setBusinessName($businessName);
        
        // Properly link the consignee to the broker
        $broker->addLinkedConsignee($consignee);

        $entityManager->persist($consignee);
        $entityManager->flush();

        return $consignee;
    }

    private function createAccountingStaff(EntityManagerInterface $entityManager): StaffUser
    {
        $staff = new StaffUser();
        $staff->setEmail('accounting' . uniqid() . '@test.com');
        $staff->setPasswordHash('hashed_password');
        $staff->setRole(UserRole::ACCOUNTING);
        $staff->setStatus(AccountStatus::APPROVED);
        $staff->setFirstName('Test');
        $staff->setLastName('Accountant');
        $staff->setDepartment('Accounting');

        $entityManager->persist($staff);
        $entityManager->flush();

        return $staff;
    }

    private function createShipment(string $manifestNumber, string $billingInfo, Broker $broker, EntityManagerInterface $entityManager): ShipmentRecord
    {
        $staff = new StaffUser();
        $staff->setEmail('staff' . uniqid() . '@test.com');
        $staff->setPasswordHash('hashed_password');
        $staff->setRole(UserRole::SL_STAFF);
        $staff->setStatus(AccountStatus::APPROVED);
        $staff->setFirstName('Test');
        $staff->setLastName('Staff');
        $staff->setDepartment('Operations');

        $entityManager->persist($staff);

        $shipment = new ShipmentRecord();
        $shipment->setManifestNumber($manifestNumber);
        $shipment->setNoticeOfArrivalDate(new \DateTime());
        $shipment->setBillingInformation($billingInfo);
        $shipment->setCreatedBy($staff);
        $shipment->addAuthorizedBroker($broker);

        $entityManager->persist($shipment);
        $entityManager->flush();

        return $shipment;
    }

    private function createPaymentVerification(ShipmentRecord $shipment, Broker $broker, EntityManagerInterface $entityManager): PaymentVerification
    {
        $payment = new PaymentVerification();
        $payment->setShipment($shipment);
        $payment->setBroker($broker);
        $payment->setProofFilePath('test_proof_file_id');
        $payment->setStatus(PaymentStatus::PENDING);

        $entityManager->persist($payment);
        $entityManager->flush();

        return $payment;
    }
}