<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Enum\WorkflowState;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\PaymentType;
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
 * Unit tests for Task 13: Payment Status Validation
 * 
 * Tests the comprehensive validation logic in BatchEDOGenerationService
 * to ensure eDOs are only generated for manifests with verified payments.
 */
class BatchEDOPaymentValidationTest extends TestCase
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
     * Test validation fails when workflow state is not payment_verified
     */
    public function testValidationFailsWithIncorrectWorkflowState(): void
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::BL_UPLOADED);

        // Expect logger to be called with warning
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'eDO generation validation failed: Invalid workflow state',
                $this->callback(function ($context) {
                    return $context['validation_type'] === 'workflow_state'
                        && $context['current_state'] === 'bl_uploaded'
                        && $context['required_state'] === 'payment_verified';
                })
            );

        $this->expectException(EDOWorkflowException::class);
        $this->expectExceptionMessage('Manifest workflow state must be payment_verified. Current state: bl_uploaded');

        $this->service->validateManifestForEDOGeneration($manifest);
    }

    /**
     * Test validation fails when final payment does not exist
     */
    public function testValidationFailsWithNoFinalPayment(): void
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn(null);

        // Expect logger to be called with warning
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'eDO generation validation failed: No final payment',
                $this->callback(function ($context) {
                    return $context['validation_type'] === 'final_payment_existence';
                })
            );

        $this->expectException(EDOWorkflowException::class);
        $this->expectExceptionMessage('No final payment found for manifest TEST-001');

        $this->service->validateManifestForEDOGeneration($manifest);
    }

    /**
     * Test validation fails when payment status is not verified
     */
    public function testValidationFailsWithUnverifiedPayment(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::PENDING_VALIDATION);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);

        // Expect logger to be called with warning
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'eDO generation validation failed: Payment not verified',
                $this->callback(function ($context) {
                    return $context['validation_type'] === 'payment_status'
                        && $context['current_status'] === 'pending_validation'
                        && $context['required_status'] === 'verified';
                })
            );

        $this->expectException(EDOWorkflowException::class);
        $this->expectExceptionMessage('Final payment for manifest TEST-001 is not verified. Current status: pending_validation');

        $this->service->validateManifestForEDOGeneration($manifest);
    }

    /**
     * Test validation fails when eDOs already exist (duplicate prevention)
     */
    public function testValidationFailsWithExistingEDOs(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);

        $edo1 = $this->createMock(ElectronicDeliveryOrder::class);
        $edo1->method('getEdoNumber')->willReturn('EDO-001');
        
        $edo2 = $this->createMock(ElectronicDeliveryOrder::class);
        $edo2->method('getEdoNumber')->willReturn('EDO-002');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$edo1, $edo2]);

        $this->entityManager->method('getRepository')->willReturn($repository);

        // Expect logger to be called with warning
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'eDO generation validation failed: Duplicate eDOs exist',
                $this->callback(function ($context) {
                    return $context['validation_type'] === 'duplicate_prevention'
                        && $context['existing_edo_count'] === 2
                        && in_array('EDO-001', $context['existing_edo_numbers'])
                        && in_array('EDO-002', $context['existing_edo_numbers']);
                })
            );

        $this->expectException(EDOWorkflowException::class);
        $this->expectExceptionMessage('eDOs already generated for manifest TEST-001. Found 2 existing eDO(s): EDO-001, EDO-002');

        $this->service->validateManifestForEDOGeneration($manifest);
    }

    /**
     * Test validation fails when no containers are linked
     */
    public function testValidationFailsWithNoLinkedContainers(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn(new ArrayCollection());

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')->willReturn($repository);

        // Expect logger to be called with warning
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'eDO generation validation failed: No linked containers',
                $this->callback(function ($context) {
                    return $context['validation_type'] === 'container_linkage';
                })
            );

        $this->expectException(EDOWorkflowException::class);
        $this->expectExceptionMessage('No containers linked to manifest TEST-001');

        $this->service->validateManifestForEDOGeneration($manifest);
    }

    /**
     * Test validation passes with all correct conditions
     */
    public function testValidationPassesWithCorrectConditions(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getId')->willReturn(1);
        $payment->method('getStatus')->willReturn(PaymentStatus::VERIFIED);

        $container = $this->createMock(Container::class);
        $containers = new ArrayCollection([$container]);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getManifestNumber')->willReturn('TEST-001');
        $manifest->method('getWorkflowState')->willReturn(WorkflowState::PAYMENT_VERIFIED);
        $manifest->method('getFinalPayment')->willReturn($payment);
        $manifest->method('getContainersLinkedToManifest')->willReturn($containers);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')->willReturn($repository);

        // Expect logger to be called with info for successful validation
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'eDO generation validation passed',
                $this->callback(function ($context) {
                    return $context['manifest_id'] === 1
                        && $context['manifest_number'] === 'TEST-001'
                        && $context['container_count'] === 1
                        && $context['payment_status'] === 'verified'
                        && $context['workflow_state'] === 'payment_verified';
                })
            );

        $result = $this->service->validateManifestForEDOGeneration($manifest);
        $this->assertTrue($result);
    }
}
