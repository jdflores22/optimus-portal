<?php

namespace App\Tests\Integration;

use App\Entity\Payment;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\PaymentHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PaymentHistoryServiceIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PaymentHistoryService $paymentHistoryService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->paymentHistoryService = $container->get(PaymentHistoryService::class);
        
        // Clean up database
        $this->entityManager->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE payments');
        $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE manifests');
        $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE users');
        $this->entityManager->getConnection()->executeStatement('TRUNCATE TABLE shipping_lines');
        $this->entityManager->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testGetPaymentHistoryReturnsAllVersions(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $broker = $this->createUser('broker@test.com', UserRole::BROKER);
        $manifest = $this->createManifest($shippingLine, $broker);
        
        // Create payment version chain: v1 -> v2 -> v3
        $payment1 = $this->createPayment($manifest, $broker, 1, null, PaymentStatus::REJECTED);
        $payment2 = $this->createPayment($manifest, $broker, 2, $payment1, PaymentStatus::REJECTED);
        $payment3 = $this->createPayment($manifest, $broker, 3, $payment2, PaymentStatus::PENDING_VALIDATION);
        
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Act
        $history = $this->paymentHistoryService->getPaymentHistory($manifest, PaymentType::FINAL_PAYMENT->value);

        // Assert
        $this->assertCount(3, $history);
        $this->assertEquals(1, $history[0]->getVersion());
        $this->assertEquals(2, $history[1]->getVersion());
        $this->assertEquals(3, $history[2]->getVersion());
    }

    public function testGetPaymentChainReturnsCompleteChain(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $broker = $this->createUser('broker@test.com', UserRole::BROKER);
        $manifest = $this->createManifest($shippingLine, $broker);
        
        // Create payment version chain
        $payment1 = $this->createPayment($manifest, $broker, 1, null, PaymentStatus::REJECTED);
        $payment2 = $this->createPayment($manifest, $broker, 2, $payment1, PaymentStatus::REJECTED);
        $payment3 = $this->createPayment($manifest, $broker, 3, $payment2, PaymentStatus::VERIFIED);
        
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Refresh payment3 to get the full chain
        $payment3 = $this->entityManager->find(Payment::class, $payment3->getId());

        // Act
        $chain = $this->paymentHistoryService->getPaymentChain($payment3);

        // Assert
        $this->assertCount(3, $chain);
        $this->assertEquals(1, $chain[0]->getVersion());
        $this->assertEquals(2, $chain[1]->getVersion());
        $this->assertEquals(3, $chain[2]->getVersion());
    }

    public function testGetPaymentStatisticsReturnsCorrectCounts(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $broker = $this->createUser('broker@test.com', UserRole::BROKER);
        $manifest = $this->createManifest($shippingLine, $broker);
        
        // Create payment version chain with 2 rejections
        $payment1 = $this->createPayment($manifest, $broker, 1, null, PaymentStatus::REJECTED);
        $payment2 = $this->createPayment($manifest, $broker, 2, $payment1, PaymentStatus::REJECTED);
        $payment3 = $this->createPayment($manifest, $broker, 3, $payment2, PaymentStatus::VERIFIED);
        
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Act
        $stats = $this->paymentHistoryService->getPaymentStatistics($manifest, PaymentType::FINAL_PAYMENT->value);

        // Assert
        $this->assertEquals(3, $stats['total_versions']);
        $this->assertEquals(2, $stats['total_rejections']);
        $this->assertEquals(3, $stats['current_version']);
        $this->assertInstanceOf(\DateTimeInterface::class, $stats['first_submission']);
        $this->assertInstanceOf(\DateTimeInterface::class, $stats['last_submission']);
    }

    public function testGetPaymentStatisticsWithNoPayments(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $broker = $this->createUser('broker@test.com', UserRole::BROKER);
        $manifest = $this->createManifest($shippingLine, $broker);
        
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Act
        $stats = $this->paymentHistoryService->getPaymentStatistics($manifest, PaymentType::FINAL_PAYMENT->value);

        // Assert
        $this->assertEquals(0, $stats['total_versions']);
        $this->assertEquals(0, $stats['total_rejections']);
        $this->assertEquals(0, $stats['current_version']);
        $this->assertNull($stats['first_submission']);
        $this->assertNull($stats['last_submission']);
    }

    public function testInvalidatePaymentHistoryCacheWorks(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $broker = $this->createUser('broker@test.com', UserRole::BROKER);
        $manifest = $this->createManifest($shippingLine, $broker);
        
        $payment1 = $this->createPayment($manifest, $broker, 1, null, PaymentStatus::VERIFIED);
        
        $this->entityManager->flush();
        $this->entityManager->clear();

        // First call to populate cache
        $history1 = $this->paymentHistoryService->getPaymentHistory($manifest, PaymentType::FINAL_PAYMENT->value);
        $this->assertCount(1, $history1);

        // Act - Invalidate cache
        $this->paymentHistoryService->invalidatePaymentHistoryCache($manifest, PaymentType::FINAL_PAYMENT->value);

        // Add another payment
        $payment2 = $this->createPayment($manifest, $broker, 2, $payment1, PaymentStatus::PENDING_VALIDATION);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Second call should get fresh data
        $history2 = $this->paymentHistoryService->getPaymentHistory($manifest, PaymentType::FINAL_PAYMENT->value);

        // Assert
        $this->assertCount(2, $history2);
    }

    // Helper methods

    private function createShippingLine(): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setName('Test Shipping Line');
        $shippingLine->setCode('TSL');
        $shippingLine->setContactEmail('test@shippingline.com');
        $shippingLine->setContactPhone('1234567890');
        
        $this->entityManager->persist($shippingLine);
        
        return $shippingLine;
    }

    private function createUser(string $email, UserRole $role): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hashed_password');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRole($role);
        $user->setAccountStatus(AccountStatus::ACTIVE);
        
        $this->entityManager->persist($user);
        
        return $user;
    }

    private function createManifest(ShippingLine $shippingLine, User $broker): Manifest
    {
        $manifest = new Manifest();
        $manifest->setManifestNumber('MAN-TEST-' . uniqid());
        $manifest->setShippingLine($shippingLine);
        $manifest->setBroker($broker);
        $manifest->setVesselName('Test Vessel');
        $manifest->setVoyageNumber('V001');
        $manifest->setArrivalDate(new \DateTime());
        
        $this->entityManager->persist($manifest);
        
        return $manifest;
    }

    private function createPayment(
        Manifest $manifest,
        User $broker,
        int $version,
        ?Payment $previousPayment,
        PaymentStatus $status
    ): Payment {
        $payment = new Payment();
        $payment->setManifest($manifest);
        $payment->setShippingLine($manifest->getShippingLine());
        $payment->setPaymentType(PaymentType::FINAL_PAYMENT);
        $payment->setAmount(1000.00);
        $payment->setReceiptFilePath('/uploads/receipt_' . $version . '.pdf');
        $payment->setSubmittedBy($broker);
        $payment->setStatus($status);
        $payment->setVersion($version);
        $payment->setPreviousPayment($previousPayment);
        
        if ($status === PaymentStatus::REJECTED) {
            $payment->setRejectionReason('Test rejection reason for version ' . $version);
        }
        
        $this->entityManager->persist($payment);
        
        return $payment;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
