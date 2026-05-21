<?php

namespace App\Tests\Unit\Service;

use App\Entity\EDOPayment;
use App\Entity\Manifest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use App\Entity\StoredFile;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\EDOStatus;
use App\Repository\EDOPaymentRepository;
use App\Service\EDOPaymentService;
use App\Service\FileService;
use App\Service\AuditService;
use App\Service\ManifestNotificationService;
use App\Service\ActivityLogService;
use App\Service\PaymentFeeConfigurationServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class EDOPaymentServiceTest extends TestCase
{
    private EDOPaymentService $service;
    private EntityManagerInterface|MockObject $entityManager;
    private FileService|MockObject $fileService;
    private AuditService|MockObject $auditService;
    private ManifestNotificationService|MockObject $notificationService;
    private ActivityLogService|MockObject $activityLogService;
    private PaymentFeeConfigurationServiceInterface|MockObject $paymentFeeConfigService;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->notificationService = $this->createMock(ManifestNotificationService::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->paymentFeeConfigService = $this->createMock(PaymentFeeConfigurationServiceInterface::class);
        $this->projectDir = '/var/www/html';

        $this->service = new EDOPaymentService(
            $this->entityManager,
            $this->fileService,
            $this->auditService,
            $this->notificationService,
            $this->activityLogService,
            $this->paymentFeeConfigService,
            $this->projectDir
        );
    }

    public function testSubmitEDOAccessPaymentSuccess(): void
    {
        // Arrange
        $manifestId = 1;
        $broker = $this->createMockUser('broker@test.com');
        $manifest = $this->createMockManifest($manifestId);
        $edo = $this->createMockEDO('EDO-2024-001');
        $receipt = $this->createMockUploadedFile();
        $storedFile = $this->createMockStoredFile('/var/www/html/public/uploads/receipts/receipt123.pdf');
        $feeAmount = 500.00;

        $manifest->method('getEdo')->willReturn($edo);
        $manifest->method('getManifestAccessPayment')->willReturn(null);

        // Mock repository
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($manifestId)
            ->willReturn($manifest);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Manifest::class)
            ->willReturn($repository);

        // Mock file service
        $this->fileService->expects($this->once())
            ->method('uploadFile')
            ->with($receipt, 'receipt', $broker)
            ->willReturn($storedFile);

        // Mock payment fee config service
        $this->paymentFeeConfigService->expects($this->once())
            ->method('getCurrentManifestAccessFee')
            ->willReturn($feeAmount);

        // Mock entity manager persist and flush
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(EDOPayment::class))
            ->willReturnCallback(function (EDOPayment $payment) {
                // Simulate database assigning an ID
                $this->setEntityId($payment, 1);
            });

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logAction')
            ->with(
                $broker,
                'edo_payment_submission',
                'EDOPayment',
                1, // ID set by persist callback
                $this->callback(function ($data) use ($feeAmount, $manifestId) {
                    return $data['amount'] === $feeAmount &&
                           $data['manifest_id'] === $manifestId &&
                           $data['edo_number'] === 'EDO-2024-001';
                })
            );

        // Mock activity log service
        $this->activityLogService->expects($this->once())
            ->method('logEDOPaymentSubmission')
            ->with($broker, $this->isInstanceOf(EDOPayment::class), $manifest);

        // Mock notification service
        $this->notificationService->expects($this->once())
            ->method('notifyEDOPaymentSubmitted')
            ->with($this->isInstanceOf(EDOPayment::class));

        // Act
        $result = $this->service->submitEDOAccessPayment($manifestId, $receipt, $broker);

        // Assert
        $this->assertInstanceOf(EDOPayment::class, $result);
        $this->assertEquals($manifest, $result->getManifest());
        $this->assertEquals($feeAmount, $result->getAmount());
        $this->assertEquals('/uploads/receipts/receipt123.pdf', $result->getReceiptFilePath());
        $this->assertEquals($broker, $result->getSubmittedBy());
        $this->assertEquals(PaymentStatus::PENDING_VALIDATION, $result->getStatus());
    }

    public function testSubmitEDOAccessPaymentManifestNotFound(): void
    {
        // Arrange
        $manifestId = 999;
        $broker = $this->createMockUser('broker@test.com');
        $receipt = $this->createMockUploadedFile();

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($manifestId)
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Manifest::class)
            ->willReturn($repository);

        // Expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Manifest not found');

        // Act
        $this->service->submitEDOAccessPayment($manifestId, $receipt, $broker);
    }

    public function testSubmitEDOAccessPaymentEDONotFound(): void
    {
        // Arrange
        $manifestId = 1;
        $broker = $this->createMockUser('broker@test.com');
        $manifest = $this->createMockManifest($manifestId);
        $receipt = $this->createMockUploadedFile();

        $manifest->method('getEdo')->willReturn(null);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($manifestId)
            ->willReturn($manifest);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Manifest::class)
            ->willReturn($repository);

        // Expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EDO not found for manifest');

        // Act
        $this->service->submitEDOAccessPayment($manifestId, $receipt, $broker);
    }

    public function testSubmitEDOAccessPaymentAlreadyExists(): void
    {
        // Arrange
        $manifestId = 1;
        $broker = $this->createMockUser('broker@test.com');
        $manifest = $this->createMockManifest($manifestId);
        $edo = $this->createMockEDO('EDO-2024-001');
        $receipt = $this->createMockUploadedFile();
        $existingPayment = new EDOPayment();

        $manifest->method('getEdo')->willReturn($edo);
        $manifest->method('getManifestAccessPayment')->willReturn($existingPayment);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($manifestId)
            ->willReturn($manifest);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Manifest::class)
            ->willReturn($repository);

        // Expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('eDO payment already submitted for this manifest');

        // Act
        $this->service->submitEDOAccessPayment($manifestId, $receipt, $broker);
    }

    public function testValidateEDOAccessPaymentApprovalSuccess(): void
    {
        // Arrange
        $paymentId = 1;
        $systemAdmin = $this->createMockUser('admin@test.com');
        $edoPayment = new EDOPayment();
        $manifest = $this->createMockManifest(1);
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');
        $edo->setStatus(EDOStatus::PENDING_RELEASE);

        $this->setEntityId($edoPayment, $paymentId);
        $edoPayment->setManifest($manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setStatus(PaymentStatus::PENDING_VALIDATION);

        $manifest->method('getEdo')->willReturn($edo);

        // Mock repository
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($paymentId)
            ->willReturn($edoPayment);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(EDOPayment::class)
            ->willReturn($repository);

        // Mock entity manager flush
        $this->entityManager->expects($this->once())
            ->method('flush');

        // Mock notification service
        $this->notificationService->expects($this->exactly(2))
            ->method($this->logicalOr(
                $this->equalTo('notifyEDOPaymentValidated'),
                $this->equalTo('notifyEDOGenerated')
            ));

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logAction')
            ->with(
                $systemAdmin,
                'edo_payment_validation',
                'EDOPayment',
                $paymentId,
                $this->callback(function ($data) {
                    return $data['approved'] === true &&
                           $data['edo_released'] === true;
                })
            );

        // Mock activity log service
        $this->activityLogService->expects($this->once())
            ->method('logEDOPaymentValidation')
            ->with($systemAdmin, $edoPayment, $manifest, true);

        // Act
        $this->service->validateEDOAccessPayment($paymentId, true, null, $systemAdmin);

        // Assert
        $this->assertEquals(PaymentStatus::VERIFIED, $edoPayment->getStatus());
        $this->assertEquals($systemAdmin, $edoPayment->getValidatedBy());
        $this->assertInstanceOf(\DateTimeInterface::class, $edoPayment->getValidatedAt());
        $this->assertEquals(EDOStatus::RELEASED, $edo->getStatus());
        $this->assertEquals($systemAdmin, $edo->getReleasedBy());
        $this->assertInstanceOf(\DateTimeInterface::class, $edo->getReleasedAt());
    }

    public function testValidateEDOAccessPaymentRejectionSuccess(): void
    {
        // Arrange
        $paymentId = 1;
        $systemAdmin = $this->createMockUser('admin@test.com');
        $reason = 'Receipt is not clear';
        $edoPayment = new EDOPayment();
        $manifest = $this->createMockManifest(1);
        $edo = new ElectronicDeliveryOrder();
        $edo->setEdoNumber('EDO-2024-001');

        $this->setEntityId($edoPayment, $paymentId);
        $edoPayment->setManifest($manifest);
        $edoPayment->setAmount(500.00);
        $edoPayment->setStatus(PaymentStatus::PENDING_VALIDATION);

        $manifest->method('getEdo')->willReturn($edo);

        // Mock repository
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($paymentId)
            ->willReturn($edoPayment);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(EDOPayment::class)
            ->willReturn($repository);

        // Mock entity manager flush
        $this->entityManager->expects($this->once())
            ->method('flush');

        // Mock notification service
        $this->notificationService->expects($this->once())
            ->method('notifyEDOPaymentValidated')
            ->with($edoPayment, false, $reason);

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logAction')
            ->with(
                $systemAdmin,
                'edo_payment_validation',
                'EDOPayment',
                $paymentId,
                $this->callback(function ($data) use ($reason) {
                    return $data['approved'] === false &&
                           $data['reason'] === $reason;
                })
            );

        // Mock activity log service
        $this->activityLogService->expects($this->once())
            ->method('logEDOPaymentValidation')
            ->with($systemAdmin, $edoPayment, $manifest, false);

        // Act
        $this->service->validateEDOAccessPayment($paymentId, false, $reason, $systemAdmin);

        // Assert
        $this->assertEquals(PaymentStatus::REJECTED, $edoPayment->getStatus());
        $this->assertEquals($systemAdmin, $edoPayment->getValidatedBy());
        $this->assertEquals($reason, $edoPayment->getRejectionReason());
        $this->assertInstanceOf(\DateTimeInterface::class, $edoPayment->getValidatedAt());
    }

    public function testValidateEDOAccessPaymentNotFound(): void
    {
        // Arrange
        $paymentId = 999;
        $systemAdmin = $this->createMockUser('admin@test.com');

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($paymentId)
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(EDOPayment::class)
            ->willReturn($repository);

        // Expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('eDO payment not found');

        // Act
        $this->service->validateEDOAccessPayment($paymentId, true, null, $systemAdmin);
    }

    public function testValidateEDOAccessPaymentRejectionWithoutReason(): void
    {
        // Arrange
        $paymentId = 1;
        $systemAdmin = $this->createMockUser('admin@test.com');
        $edoPayment = new EDOPayment();
        $manifest = $this->createMockManifest(1);

        $this->setEntityId($edoPayment, $paymentId);
        $edoPayment->setManifest($manifest);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($paymentId)
            ->willReturn($edoPayment);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(EDOPayment::class)
            ->willReturn($repository);

        // Expect exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rejection reason is required');

        // Act
        $this->service->validateEDOAccessPayment($paymentId, false, null, $systemAdmin);
    }

    public function testGetPendingEDOAccessPayments(): void
    {
        // Arrange
        $expectedPayments = [new EDOPayment(), new EDOPayment()];
        
        $query = $this->createMock(Query::class);
        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($expectedPayments);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects($this->exactly(4))
            ->method('leftJoin')
            ->willReturnCallback(function ($join, $alias) use ($queryBuilder) {
                return $queryBuilder;
            });
        $queryBuilder->expects($this->exactly(4))
            ->method('addSelect')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('where')
            ->with('ep.status = :status')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('e.id IS NOT NULL')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->with('status', PaymentStatus::PENDING_VALIDATION)
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('orderBy')
            ->with('ep.createdAt', 'ASC')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $repository = $this->createMock(EDOPaymentRepository::class);
        $repository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('ep')
            ->willReturn($queryBuilder);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(EDOPayment::class)
            ->willReturn($repository);

        // Act
        $result = $this->service->getPendingEDOAccessPayments();

        // Assert
        $this->assertEquals($expectedPayments, $result);
        $this->assertCount(2, $result);
    }

    public function testGetEDOPaymentByIdFound(): void
    {
        // Arrange
        $paymentId = 1;
        $expectedPayment = new EDOPayment();
        $this->setEntityId($expectedPayment, $paymentId);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($paymentId)
            ->willReturn($expectedPayment);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(EDOPayment::class)
            ->willReturn($repository);

        // Act
        $result = $this->service->getEDOPaymentById($paymentId);

        // Assert
        $this->assertEquals($expectedPayment, $result);
    }

    public function testGetEDOPaymentByIdNotFound(): void
    {
        // Arrange
        $paymentId = 999;

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with($paymentId)
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(EDOPayment::class)
            ->willReturn($repository);

        // Act
        $result = $this->service->getEDOPaymentById($paymentId);

        // Assert
        $this->assertNull($result);
    }

    // Helper methods

    private function createMockUser(string $email): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getId')->willReturn(1);
        return $user;
    }

    private function createMockManifest(int $id): MockObject
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn($id);
        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        return $manifest;
    }

    private function createMockEDO(string $edoNumber): MockObject
    {
        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getEdoNumber')->willReturn($edoNumber);
        return $edo;
    }

    private function createMockUploadedFile(): MockObject
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getClientOriginalName')->willReturn('receipt.pdf');
        return $file;
    }

    private function createMockStoredFile(string $path): MockObject
    {
        $storedFile = $this->createMock(StoredFile::class);
        $storedFile->method('getEncryptedPath')->willReturn($path);
        return $storedFile;
    }

    private function setEntityId($entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
