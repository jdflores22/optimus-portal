<?php

namespace App\Tests\Unit\Service;

use App\Entity\Payment;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\PaymentType;
use App\Repository\PaymentRepository;
use App\Service\PaymentHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

class PaymentHistoryServiceTest extends TestCase
{
    private PaymentHistoryService $service;
    private PaymentRepository|MockObject $paymentRepository;
    private EntityManagerInterface|MockObject $entityManager;
    private CacheInterface|MockObject $cache;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(PaymentRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new PaymentHistoryService(
            $this->paymentRepository,
            $this->entityManager,
            $this->cache,
            $this->logger
        );
    }

    public function testGetPaymentHistoryFromCache(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';
        $expectedPayments = [
            $this->createMockPayment(1, 1),
            $this->createMockPayment(2, 2),
            $this->createMockPayment(3, 3),
        ];

        $this->cache->expects($this->once())
            ->method('get')
            ->with('payment_history_1_final_payment')
            ->willReturn($expectedPayments);

        // Act
        $result = $this->service->getPaymentHistory($manifest, $paymentType);

        // Assert
        $this->assertEquals($expectedPayments, $result);
        $this->assertCount(3, $result);
    }

    public function testGetPaymentHistoryCacheMiss(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';
        $expectedPayments = [
            $this->createMockPayment(1, 1),
            $this->createMockPayment(2, 2),
        ];

        $this->cache->expects($this->once())
            ->method('get')
            ->with('payment_history_1_final_payment')
            ->willReturnCallback(function ($key, $callback) use ($expectedPayments) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(300);
                return $callback($item);
            });

        $this->paymentRepository->expects($this->once())
            ->method('findAllVersionsByManifest')
            ->with($manifest, $paymentType)
            ->willReturn($expectedPayments);

        // Act
        $result = $this->service->getPaymentHistory($manifest, $paymentType);

        // Assert
        $this->assertEquals($expectedPayments, $result);
    }

    public function testGetPaymentHistoryCacheException(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';
        $expectedPayments = [
            $this->createMockPayment(1, 1),
        ];

        $this->cache->expects($this->once())
            ->method('get')
            ->willThrowException(new \Exception('Cache error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to get payment history from cache',
                $this->callback(function ($context) {
                    return $context['manifest_id'] === 1 &&
                           $context['payment_type'] === 'final_payment' &&
                           $context['error'] === 'Cache error';
                })
            );

        $this->paymentRepository->expects($this->once())
            ->method('findAllVersionsByManifest')
            ->with($manifest, $paymentType)
            ->willReturn($expectedPayments);

        // Act
        $result = $this->service->getPaymentHistory($manifest, $paymentType);

        // Assert
        $this->assertEquals($expectedPayments, $result);
    }

    public function testGetPaymentChainFromCache(): void
    {
        // Arrange
        $payment = $this->createMockPayment(3, 3);
        $expectedChain = [
            $this->createMockPayment(1, 1),
            $this->createMockPayment(2, 2),
            $this->createMockPayment(3, 3),
        ];

        $this->cache->expects($this->once())
            ->method('get')
            ->with('payment_chain_3')
            ->willReturn($expectedChain);

        // Act
        $result = $this->service->getPaymentChain($payment);

        // Assert
        $this->assertEquals($expectedChain, $result);
        $this->assertCount(3, $result);
    }

    public function testGetPaymentChainCacheMiss(): void
    {
        // Arrange
        $payment = $this->createMockPayment(2, 2);
        $expectedChain = [
            $this->createMockPayment(1, 1),
            $this->createMockPayment(2, 2),
        ];

        $this->cache->expects($this->once())
            ->method('get')
            ->with('payment_chain_2')
            ->willReturnCallback(function ($key, $callback) use ($expectedChain) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(300);
                return $callback($item);
            });

        $this->paymentRepository->expects($this->once())
            ->method('getPaymentChain')
            ->with($payment)
            ->willReturn($expectedChain);

        // Act
        $result = $this->service->getPaymentChain($payment);

        // Assert
        $this->assertEquals($expectedChain, $result);
    }

    public function testGetPaymentStatisticsWithPayments(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';
        
        // Create real Payment objects instead of mocks for proper status comparison
        $payment1 = new Payment();
        $this->setEntityId($payment1, 1);
        $payment1->setVersion(1);
        $payment1->setStatus(PaymentStatus::VERIFIED);
        $payment1->setAmount(1000.00);
        $payment1->setReceiptFilePath('/uploads/receipt1.pdf');
        $payment1->setSubmittedBy($this->createMockUser());
        
        $payment2 = new Payment();
        $this->setEntityId($payment2, 2);
        $payment2->setVersion(2);
        $payment2->setStatus(PaymentStatus::REJECTED);
        $payment2->setAmount(1000.00);
        $payment2->setReceiptFilePath('/uploads/receipt2.pdf');
        $payment2->setSubmittedBy($this->createMockUser());
        
        $payment3 = new Payment();
        $this->setEntityId($payment3, 3);
        $payment3->setVersion(3);
        $payment3->setStatus(PaymentStatus::PENDING_VALIDATION);
        $payment3->setAmount(1000.00);
        $payment3->setReceiptFilePath('/uploads/receipt3.pdf');
        $payment3->setSubmittedBy($this->createMockUser());

        $payments = [$payment1, $payment2, $payment3];

        $this->cache->expects($this->once())
            ->method('get')
            ->with('payment_statistics_1_final_payment')
            ->willReturnCallback(function ($key, $callback) use ($payments) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(300);
                return $callback($item);
            });

        $this->paymentRepository->expects($this->once())
            ->method('findAllVersionsByManifest')
            ->with($manifest, $paymentType)
            ->willReturn($payments);

        // Act
        $result = $this->service->getPaymentStatistics($manifest, $paymentType);

        // Assert
        $this->assertEquals(3, $result['total_versions']);
        $this->assertEquals(1, $result['total_rejections']);
        $this->assertEquals(3, $result['current_version']);
        $this->assertInstanceOf(\DateTimeInterface::class, $result['first_submission']);
        $this->assertInstanceOf(\DateTimeInterface::class, $result['last_submission']);
    }

    public function testGetPaymentStatisticsWithNoPayments(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';

        $this->cache->expects($this->once())
            ->method('get')
            ->with('payment_statistics_1_final_payment')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(300);
                return $callback($item);
            });

        $this->paymentRepository->expects($this->once())
            ->method('findAllVersionsByManifest')
            ->with($manifest, $paymentType)
            ->willReturn([]);

        // Act
        $result = $this->service->getPaymentStatistics($manifest, $paymentType);

        // Assert
        $this->assertEquals(0, $result['total_versions']);
        $this->assertEquals(0, $result['total_rejections']);
        $this->assertEquals(0, $result['current_version']);
        $this->assertNull($result['first_submission']);
        $this->assertNull($result['last_submission']);
    }

    public function testGetPaymentStatisticsCacheException(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';
        
        $payment1 = $this->createMockPayment(1, 1);
        $payment1->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        $payment1->method('getCreatedAt')->willReturn(new \DateTime('2024-01-01'));
        
        $payments = [$payment1];

        $this->cache->expects($this->once())
            ->method('get')
            ->willThrowException(new \Exception('Cache error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to get payment statistics from cache',
                $this->callback(function ($context) {
                    return $context['manifest_id'] === 1 &&
                           $context['payment_type'] === 'final_payment';
                })
            );

        $this->paymentRepository->expects($this->once())
            ->method('findAllVersionsByManifest')
            ->with($manifest, $paymentType)
            ->willReturn($payments);

        // Act
        $result = $this->service->getPaymentStatistics($manifest, $paymentType);

        // Assert
        $this->assertEquals(1, $result['total_versions']);
        $this->assertEquals(0, $result['total_rejections']);
    }

    public function testInvalidatePaymentHistoryCache(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';

        $this->cache->expects($this->exactly(2))
            ->method('delete');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Invalidated payment history cache',
                $this->callback(function ($context) {
                    return $context['manifest_id'] === 1 &&
                           $context['payment_type'] === 'final_payment';
                })
            );

        // Act
        $this->service->invalidatePaymentHistoryCache($manifest, $paymentType);
    }

    public function testInvalidatePaymentHistoryCacheException(): void
    {
        // Arrange
        $manifest = $this->createMockManifest(1);
        $paymentType = 'final_payment';

        $this->cache->expects($this->once())
            ->method('delete')
            ->willThrowException(new \Exception('Cache error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to invalidate payment history cache',
                $this->callback(function ($context) {
                    return $context['manifest_id'] === 1 &&
                           $context['payment_type'] === 'final_payment' &&
                           $context['error'] === 'Cache error';
                })
            );

        // Act
        $this->service->invalidatePaymentHistoryCache($manifest, $paymentType);
    }

    public function testInvalidatePaymentChainCache(): void
    {
        // Arrange
        $payment = $this->createMockPayment(3, 3);
        $chain = [
            $this->createMockPayment(1, 1),
            $this->createMockPayment(2, 2),
            $this->createMockPayment(3, 3),
        ];

        $this->paymentRepository->expects($this->once())
            ->method('getPaymentChain')
            ->with($payment)
            ->willReturn($chain);

        $this->cache->expects($this->exactly(3))
            ->method('delete');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Invalidated payment chain cache',
                $this->callback(function ($context) {
                    return $context['payment_id'] === 3 &&
                           $context['chain_length'] === 3;
                })
            );

        // Act
        $this->service->invalidatePaymentChainCache($payment);
    }

    // Helper methods

    private function createMockManifest(int $id): MockObject
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn($id);
        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        return $manifest;
    }

    private function createMockPayment(int $id, int $version): MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn($id);
        $payment->method('getVersion')->willReturn($version);
        $payment->method('getAmount')->willReturn(1000.00);
        $payment->method('getStatus')->willReturn(PaymentStatus::PENDING_VALIDATION);
        $payment->method('getCreatedAt')->willReturn(new \DateTime());
        return $payment;
    }

    private function createMockUser(): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');
        return $user;
    }

    private function setEntityId($entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
