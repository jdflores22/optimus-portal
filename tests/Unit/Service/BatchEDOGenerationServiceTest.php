<?php

namespace App\Tests\Unit\Service;

use App\Entity\Broker;
use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\Payment;
use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\EDOStatus;
use App\Exception\EDOWorkflowException;
use App\Service\BatchEDOGenerationService;
use App\Service\EDOGenerationServiceInterface;
use App\Service\EDOAuditServiceInterface;
use App\Service\NotificationService;
use App\Service\ConfigurationService;
use App\Service\InAppNotificationService;
use App\Service\EmailNotificationService;
use App\Utility\EDONumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit tests for BatchEDOGenerationService
 * 
 * Tests comprehensive eDO generation functionality including:
 * - Successful eDO generation for multiple containers
 * - Validation failure scenarios
 * - Duplicate eDO prevention
 * - Transaction rollback on error
 * - Audit log creation
 * - Notification sending
 */
class BatchEDOGenerationServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private EDOGenerationServiceInterface $edoGenerationService;
    private EDOAuditServiceInterface $auditService;
    private LoggerInterface $logger;
    private EDONumberGenerator $edoNumberGenerator;
    private NotificationService $notificationService;
    private ConfigurationService $configurationService;
    private InAppNotificationService $inAppNotificationService;
    private EmailNotificationService $emailNotificationService;
    private UrlGeneratorInterface $urlGenerator;
    private BatchEDOGenerationService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->edoGenerationService = $this->createMock(EDOGenerationServiceInterface::class);
        $this->auditService = $this->createMock(EDOAuditServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->edoNumberGenerator = $this->createMock(EDONumberGenerator::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->inAppNotificationService = $this->createMock(InAppNotificationService::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $this->service = new BatchEDOGenerationService(
            $this->entityManager,
            $this->edoGenerationService,
            $this->auditService,
            $this->logger,
            $this->edoNumberGenerator,
            $this->notificationService,
            $this->configurationService,
            $this->inAppNotificationService,
            $this->emailNotificationService,
            $this->urlGenerator
        );
    }

    /**
     * Test successful eDO generation for manifest with multiple containers
     */
    public function testSuccessfulEDOGenerationForMultipleContainers(): void
    {
        // Arrange
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(NOA::class);
        $container1 = $this->createMock(Container::class);
        $container1->method('getId')->willReturn(1);
        $container1->method('getContainerNumber')->willReturn('CONT001');
        $container1->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container1, 'CY-001');
        
        $container2 = $this->createMock(Container::class);
        $container2->method('getId')->willReturn(2);
        $container2->method('getContainerNumber')->willReturn('CONT002');
        $container2->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container2, 'CY-001');
        
        $containers = new ArrayCollection([$container1, $container2]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')
            ->willReturnOnConsecutiveCalls('EDO-001', 'EDO-002');
        
        // Expect transaction management
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        $this->entityManager->expects($this->never())->method('rollback');
        
        // Expect persist and flush calls
        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        
        // Expect audit logs
        $this->auditService->expects($this->exactly(3))->method('logAction');
        
        // Expect notifications
        $this->inAppNotificationService->expects($this->once())->method('createNotification');
        $this->emailNotificationService->expects($this->once())->method('sendTemplatedEmail');
        
        // Act
        $result = $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
        
        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('edos', $result);
        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['edos']);
    }

    /**
     * Test transaction rollback on error during eDO generation
     */
    public function testTransactionRollbackOnError(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $shippingLine = $this->createMock(ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        // Simulate error during flush
        $this->entityManager->method('flush')
            ->willThrowException(new \Exception('Database error'));
        
        // Expect transaction management
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('rollback');
        $this->entityManager->expects($this->never())->method('commit');
        
        $this->expectException(EDOWorkflowException::class);
        $this->expectExceptionMessage('Failed to generate eDOs: Database error');
        
        $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
    }

    /**
     * Test audit log creation during eDO generation
     */
    public function testAuditLogCreation(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')->willReturn('EDO-001');
        
        // Expect audit logs: 1 for initiation + 1 for each eDO created
        $this->auditService->expects($this->exactly(2))
            ->method('logAction')
            ->withConsecutive(
                [
                    $user,
                    'edo_generation_initiated',
                    'Manifest',
                    1,
                    $this->callback(function ($context) use ($expirationDate) {
                        return $context['container_count'] === 1
                            && $context['expiration_date'] === $expirationDate->format('Y-m-d');
                    })
                ],
                [
                    $user,
                    'edo_created',
                    'ElectronicDeliveryOrder',
                    $this->anything(),
                    $this->callback(function ($context) use ($edoFee) {
                        return $context['edo_number'] === 'EDO-001'
                            && $context['container_number'] === 'CONT001'
                            && $context['fee_amount'] === $edoFee;
                    })
                ]
            );
        
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        
        $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
    }

    /**
     * Test notification sending after successful eDO generation
     */
    public function testNotificationSendingAfterGeneration(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')->willReturn('EDO-001');
        $this->urlGenerator->method('generate')->willReturn('https://example.com/broker/edo/list');
        
        // Expect in-app notification
        $this->inAppNotificationService->expects($this->once())
            ->method('createNotification')
            ->with(
                $broker,
                'eDOs Generated',
                $this->stringContains('1 Electronic Delivery Order(s) have been generated for manifest MAN-001'),
                'edo_generated',
                ['manifest_id' => 1]
            );
        
        // Expect email notification
        $this->emailNotificationService->expects($this->once())
            ->method('sendTemplatedEmail')
            ->with(
                'broker@example.com',
                'eDOs Generated - OPTIMUS Portal',
                'emails/edo_generated.html.twig',
                $this->callback(function ($data) {
                    return $data['manifestNumber'] === 'MAN-001'
                        && $data['containerCount'] === 1
                        && $data['edoCount'] === 1;
                })
            );
        
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        
        $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
    }

    /**
     * Test eDO generation without broker (notification should be skipped)
     */
    public function testEDOGenerationWithoutBroker(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $shippingLine = $this->createMock(ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn(null); // No broker assigned
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')->willReturn('EDO-001');
        
        // Expect no notifications to be sent
        $this->inAppNotificationService->expects($this->never())->method('createNotification');
        $this->emailNotificationService->expects($this->never())->method('sendTemplatedEmail');
        
        // Expect warning log about missing broker
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Cannot send broker notification - no broker assigned to manifest',
                ['manifest_id' => 1]
            );
        
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        
        $result = $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
        
        $this->assertEquals(1, $result['count']);
    }

    /**
     * Test eDO properties are set correctly
     */
    public function testEDOPropertiesSetCorrectly(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(NOA::class);
        
        $container = $this->createMock(Container::class);
        $this->mockContainerCyAllocation($container, 'CY-LOCATION-001');
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')->willReturn('EDO-TEST-001');
        
        // Capture the persisted eDO
        $persistedEDO = null;
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedEDO) {
                if ($entity instanceof ElectronicDeliveryOrder) {
                    $persistedEDO = $entity;
                }
            });
        
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        
        $result = $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
        
        // Verify eDO properties
        $edo = $result['edos'][0];
        $this->assertInstanceOf(ElectronicDeliveryOrder::class, $edo);
        $this->assertEquals('EDO-TEST-001', $edo->getEdoNumber());
        $this->assertEquals(EDOStatus::PENDING_RELEASE, $edo->getStatus());
        $this->assertEquals($expirationDate, $edo->getExpiresAt());
        $this->assertEquals($edoFee, $edo->getFeeAmount());
        $this->assertEquals('CY-LOCATION-001', $edo->getCyLocation());
    }

    /**
     * Test manifest workflow state is updated after eDO generation
     */
    public function testManifestWorkflowStateUpdated(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('MAN-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        // Expect workflow state to be updated
        $manifest->expects($this->once())
            ->method('setWorkflowState')
            ->with(WorkflowState::EDO_GENERATED);
        
        $manifest->expects($this->once())
            ->method('setUpdatedAt')
            ->with($this->isInstanceOf(\DateTime::class));
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')->willReturn('EDO-001');
        
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        
        $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
    }
}

    /**
     * Test successful eDO generation for manifest with multiple containers
     */
    public function testSuccessfulEDOGenerationForMultipleContainers(): void
    {
        // Arrange
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(\App\Entity\Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(\App\Entity\ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(\App\Entity\NOA::class);
        $container1 = $this->createMock(Container::class);
        $container1->method('getId')->willReturn(1);
        $container1->method('getContainerNumber')->willReturn('CONT001');
        $container1->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container1, 'CY-001');
        
        $container2 = $this->createMock(Container::class);
        $container2->method('getId')->willReturn(2);
        $container2->method('getContainerNumber')->willReturn('CONT002');
        $container2->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container2, 'CY-001');
        
        $containers = new ArrayCollection([$container1, $container2]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')
            ->willReturnOnConsecutiveCalls('EDO-001', 'EDO-002');
        
        // Expect transaction management
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        $this->entityManager->expects($this->never())->method('rollback');
        
        // Expect persist and flush calls
        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        
        // Expect audit logs
        $this->auditService->expects($this->exactly(3))->method('logAction');
        
        // Expect notifications
        $this->inAppNotificationService->expects($this->once())->method('createNotification');
        $this->emailNotificationService->expects($this->once())->method('sendTemplatedEmail');
        
        // Act
        $result = $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
        
        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('edos', $result);
        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['edos']);
    }

    /**
     * Test transaction rollback on error during eDO generation
     */
    public function testTransactionRollbackOnError(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $shippingLine = $this->createMock(\App\Entity\ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(\App\Entity\NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        // Simulate error during flush
        $this->entityManager->method('flush')
            ->willThrowException(new \Exception('Database error'));
        
        // Expect transaction management
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('rollback');
        $this->entityManager->expects($this->never())->method('commit');
        
        $this->expectException(EDOWorkflowException::class);
        $this->expectExceptionMessage('Failed to generate eDOs: Database error');
        
        $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
    }

    /**
     * Test audit log creation during eDO generation
     */
    public function testAuditLogCreation(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(\App\Entity\Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(\App\Entity\ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(\App\Entity\NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')->willReturn('EDO-001');
        
        // Expect audit logs: 1 for initiation + 1 for each eDO created
        $this->auditService->expects($this->exactly(2))
            ->method('logAction')
            ->withConsecutive(
                [
                    $user,
                    'edo_generation_initiated',
                    'Manifest',
                    1,
                    $this->callback(function ($context) use ($expirationDate) {
                        return $context['container_count'] === 1
                            && $context['expiration_date'] === $expirationDate->format('Y-m-d');
                    })
                ],
                [
                    $user,
                    'edo_created',
                    'ElectronicDeliveryOrder',
                    $this->anything(),
                    $this->callback(function ($context) use ($edoFee) {
                        return $context['edo_number'] === 'EDO-001'
                            && $context['container_number'] === 'CONT001'
                            && $context['fee_amount'] === $edoFee;
                    })
                ]
            );
        
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        
        $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
    }

    /**
     * Test notification sending after successful eDO generation
     */
    public function testNotificationSendingAfterGeneration(): void
    {
        $expirationDate = new \DateTime('+30 days');
        $edoFee = 500.00;
        
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        
        $broker = $this->createMock(\App\Entity\Broker::class);
        $broker->method('getId')->willReturn(2);
        $broker->method('getEmail')->willReturn('broker@example.com');
        
        $shippingLine = $this->createMock(\App\Entity\ShippingLine::class);
        
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);
        
        $noa = $this->createMock(\App\Entity\NOA::class);
        $container = $this->createMock(Container::class);
        $container->method('getId')->willReturn(1);
        $container->method('getContainerNumber')->willReturn('CONT001');
        $container->method('getNoa')->willReturn($noa);
        $this->mockContainerCyAllocation($container, 'CY-001');
        
        $containers = new ArrayCollection([$container]);
        
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);
        $manifest->method('getShippingLine')->willReturn($shippingLine);
        $manifest->method('getBroker')->willReturn($broker);
        
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->configurationService->method('getEDOFee')->willReturn($edoFee);
        
        $this->edoNumberGenerator->method('generate')->willReturn('EDO-001');
        $this->urlGenerator->method('generate')->willReturn('https://example.com/broker/edo/list');
        
        // Expect in-app notification
        $this->inAppNotificationService->expects($this->once())
            ->method('createNotification')
            ->with(
                $broker,
                'eDOs Generated',
                $this->stringContains('1 Electronic Delivery Order(s) have been generated for manifest TEST-001'),
                'edo_generated',
                ['manifest_id' => 1]
            );
        
        // Expect email notification
        $this->emailNotificationService->expects($this->once())
            ->method('sendTemplatedEmail')
            ->with(
                'broker@example.com',
                'eDOs Generated - OPTIMUS Portal',
                'emails/edo_generated.html.twig',
                $this->callback(function ($data) {
                    return $data['manifestNumber'] === 'TEST-001'
                        && $data['containerCount'] === 1
                        && $data['edoCount'] === 1;
                })
            );
        
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        
        $this->service->generateEDOsForManifest($manifest, $expirationDate, $user);
    }

    private function mockContainerCyAllocation(Container $container, string $terminalName): void
    {
        $terminal = $this->createMock(\App\Entity\Terminal::class);
        $terminal->method('getName')->willReturn($terminalName);
        $allocation = $this->createMock(\App\Entity\ShippingLineTerminalAllocation::class);
        $allocation->method('getTerminal')->willReturn($terminal);
        $container->method('getCyAllocation')->willReturn($allocation);
    }
}
