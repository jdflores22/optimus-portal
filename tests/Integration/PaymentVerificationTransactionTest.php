<?php

namespace App\Tests\Integration;

use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Billing;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\UserRole;
use App\Service\PaymentVerificationTransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PaymentVerificationTransactionTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PaymentVerificationTransactionService $transactionService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->transactionService = $container->get(PaymentVerificationTransactionService::class);
    }

    public function testVerifyFinalPaymentWithEDOTransaction(): void
    {
        // This test verifies that payment verification, state transition, and eDO generation
        // happen atomically in a single transaction
        
        // Create test data
        $admin = $this->createTestUser(UserRole::ACCOUNTING);
        $manifest = $this->createTestManifest($admin);
        $manifest->setWorkflowState(WorkflowState::PAYMENT_SUBMITTED);
        
        // Create billing
        $billing = new Billing();
        $billing->setManifest($manifest);
        $billing->setFreightCharges(10000.00);
        $billing->setThcCharges(3000.00);
        $billing->setGeneratedBy($admin);
        $billing->computeTotal();
        $this->entityManager->persist($billing);
        
        // Create payment
        $payment = new Payment();
        $payment->setManifest($manifest);
        $payment->setPaymentType(PaymentType::FINAL_PAYMENT);
        $payment->setAmount(13000.00);
        $payment->setReceiptFilePath('/test/receipt.pdf');
        $payment->setSubmittedBy($admin);
        $payment->verify($admin);
        
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
        
        // Execute transaction
        $edo = $this->transactionService->verifyFinalPaymentWithEDO($payment);
        
        // Verify results
        $this->assertNotNull($edo);
        $this->assertNotNull($edo->getId());
        $this->assertEquals($manifest->getId(), $edo->getManifest()->getId());
        $this->assertEquals(WorkflowState::EDO_GENERATED, $manifest->getWorkflowState());
        $this->assertEquals(PaymentStatus::VERIFIED, $payment->getStatus());
        
        // Verify workflow history was created
        $history = $this->entityManager->getRepository(\App\Entity\WorkflowStateHistory::class)
            ->findBy(['manifest' => $manifest]);
        $this->assertNotEmpty($history);
        
        // Cleanup
        $this->cleanup($manifest, $admin);
    }

    public function testVerifyManifestAccessPaymentTransaction(): void
    {
        // Create test data
        $admin = $this->createTestUser(UserRole::SYSTEM_ADMIN);
        $manifest = $this->createTestManifest($admin);
        $manifest->setWorkflowState(WorkflowState::PENDING_PAYMENT);
        
        // Create payment
        $payment = new Payment();
        $payment->setManifest($manifest);
        $payment->setPaymentType(PaymentType::MANIFEST_ACCESS);
        $payment->setAmount(500.00);
        $payment->setReceiptFilePath('/test/receipt.pdf');
        $payment->setSubmittedBy($admin);
        $payment->verify($admin);
        
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
        
        // Execute transaction
        $this->transactionService->verifyManifestAccessPayment($payment);
        
        // Verify results
        $this->assertEquals(WorkflowState::PAYMENT_VERIFIED, $manifest->getWorkflowState());
        $this->assertEquals(PaymentStatus::VERIFIED, $payment->getStatus());
        
        // Verify workflow history was created
        $history = $this->entityManager->getRepository(\App\Entity\WorkflowStateHistory::class)
            ->findBy(['manifest' => $manifest]);
        $this->assertNotEmpty($history);
        
        // Cleanup
        $this->cleanup($manifest, $admin);
    }

    private function createTestUser(UserRole $role): User
    {
        $user = new User();
        $user->setEmail('test_' . uniqid() . '@example.com');
        $user->setPassword('test_password');
        $user->setRole($role);
        $user->setFirstName('Test');
        $user->setLastName('User');
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createTestManifest(User $creator): Manifest
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('TEST-' . uniqid());
        $manifest->setCreatedBy($creator);
        $manifest->setVesselName('Test Vessel');
        $manifest->setVoyageNumber('V123');
        
        $this->entityManager->persist($manifest);
        $this->entityManager->flush();
        
        return $manifest;
    }

    private function cleanup(Manifest $manifest, User $user): void
    {
        // Clean up test data
        $this->entityManager->remove($manifest);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
