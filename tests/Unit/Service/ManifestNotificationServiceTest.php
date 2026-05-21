<?php

namespace App\Tests\Unit\Service;

use App\Entity\EDOPayment;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\PaymentStatus;
use App\Service\ManifestNotificationService;
use App\Service\NotificationGateway;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class ManifestNotificationServiceTest extends TestCase
{
    private ManifestNotificationService $notificationService;
    private MockObject $entityManager;
    private MockObject $notificationGateway;
    private MockObject $inAppNotificationService;
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->notificationGateway = $this->createMock(NotificationGateway::class);
        $this->inAppNotificationService = $this->createMock(InAppNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->notificationService = new ManifestNotificationService(
            $this->entityManager,
            $this->notificationGateway,
            $this->inAppNotificationService,
            $this->logger
        );
    }

    public function testNotifyEDOPaymentSubmittedSendsNotificationToSystemAdmins(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $submitter = $this->createUser(UserRole::BROKER, 'broker@test.com');
        $systemAdmin1 = $this->createUser(UserRole::SYSTEM_ADMIN, 'admin1@test.com');
        $systemAdmin2 = $this->createUser(UserRole::SYSTEM_ADMIN, 'admin2@test.com');

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getSubmittedBy')->willReturn($submitter);
        $edoPayment->method('getAmount')->willReturn(500.00);
        $edoPayment->method('getId')->willReturn(1);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        $manifest->method('getId')->willReturn(1);

        // Mock repository to return SYSTEM_ADMIN users
        $this->setupUserRepositoryMock([$systemAdmin1, $systemAdmin2], UserRole::SYSTEM_ADMIN);

        // Expect notification gateway to be called with correct parameters
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->equalTo([$systemAdmin1, $systemAdmin2]),
                $this->equalTo('eDO Payment Submitted for Validation'),
                $this->stringContains('has submitted an eDO payment of ₱500.00 for manifest MAN-2024-001'),
                $this->equalTo('edo_payment_submitted'),
                $this->callback(function ($metadata) {
                    return $metadata['manifest_id'] === 1
                        && $metadata['manifest_number'] === 'MAN-2024-001'
                        && $metadata['edo_payment_id'] === 1
                        && $metadata['amount'] === 500.00;
                })
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentSubmitted($edoPayment);
    }

    public function testNotifyEDOPaymentValidatedWithApprovalSendsCorrectNotification(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $broker = $this->createUser(UserRole::BROKER, 'broker@test.com');
        $consignee = $this->createUser(UserRole::CONSIGNEE, 'consignee@test.com');

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getAmount')->willReturn(500.00);
        $edoPayment->method('getId')->willReturn(1);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getBroker')->willReturn($broker);
        $manifest->method('getConsignee')->willReturn($consignee);

        // Expect notification gateway to be called with approval message
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->equalTo([$broker, $consignee]),
                $this->equalTo('eDO Payment Approved'),
                $this->stringContains('Your eDO payment of ₱500.00 for manifest MAN-2024-001 has been approved'),
                $this->equalTo('edo_payment_approved'),
                $this->callback(function ($metadata) {
                    return $metadata['manifest_id'] === 1
                        && $metadata['edo_payment_id'] === 1
                        && $metadata['approved'] === true;
                })
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentValidated($edoPayment, true);
    }

    public function testNotifyEDOPaymentValidatedWithRejectionSendsCorrectNotification(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $broker = $this->createUser(UserRole::BROKER, 'broker@test.com');
        $consignee = $this->createUser(UserRole::CONSIGNEE, 'consignee@test.com');

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getAmount')->willReturn(500.00);
        $edoPayment->method('getId')->willReturn(1);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getBroker')->willReturn($broker);
        $manifest->method('getConsignee')->willReturn($consignee);

        $rejectionReason = 'Invalid receipt document';

        // Expect notification gateway to be called with rejection message
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->equalTo([$broker, $consignee]),
                $this->equalTo('eDO Payment Rejected'),
                $this->stringContains('Your eDO payment of ₱500.00 for manifest MAN-2024-001 has been rejected. Reason: Invalid receipt document'),
                $this->equalTo('edo_payment_rejected'),
                $this->callback(function ($metadata) use ($rejectionReason) {
                    return $metadata['manifest_id'] === 1
                        && $metadata['edo_payment_id'] === 1
                        && $metadata['approved'] === false
                        && $metadata['reason'] === $rejectionReason;
                })
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentValidated($edoPayment, false, $rejectionReason);
    }

    public function testNotifyEDOPaymentValidatedWithRejectionAndNoReasonUsesDefault(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $broker = $this->createUser(UserRole::BROKER, 'broker@test.com');

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getAmount')->willReturn(500.00);
        $edoPayment->method('getId')->willReturn(1);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getBroker')->willReturn($broker);
        $manifest->method('getConsignee')->willReturn(null);

        // Expect notification gateway to be called with default reason
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->stringContains('Reason: No reason provided'),
                $this->anything(),
                $this->anything()
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentValidated($edoPayment, false, null);
    }

    public function testNotifyEDOPaymentSubmittedIncludesCorrectMetadata(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $submitter = $this->createUser(UserRole::BROKER, 'broker@test.com');
        $systemAdmin = $this->createUser(UserRole::SYSTEM_ADMIN, 'admin@test.com');

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getSubmittedBy')->willReturn($submitter);
        $edoPayment->method('getAmount')->willReturn(750.50);
        $edoPayment->method('getId')->willReturn(42);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-999');
        $manifest->method('getId')->willReturn(99);

        $submitter->method('getId')->willReturn(5);

        // Mock repository
        $this->setupUserRepositoryMock([$systemAdmin], UserRole::SYSTEM_ADMIN);

        // Verify metadata structure
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($metadata) {
                    return isset($metadata['manifest_id'])
                        && isset($metadata['manifest_number'])
                        && isset($metadata['edo_payment_id'])
                        && isset($metadata['amount'])
                        && isset($metadata['submitted_by'])
                        && $metadata['manifest_id'] === 99
                        && $metadata['manifest_number'] === 'MAN-2024-999'
                        && $metadata['edo_payment_id'] === 42
                        && $metadata['amount'] === 750.50
                        && $metadata['submitted_by'] === 5;
                })
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentSubmitted($edoPayment);
    }

    public function testNotifyEDOPaymentSubmittedHandlesMultipleSystemAdmins(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $submitter = $this->createUser(UserRole::BROKER, 'broker@test.com');
        
        // Create multiple system admins
        $admins = [
            $this->createUser(UserRole::SYSTEM_ADMIN, 'admin1@test.com'),
            $this->createUser(UserRole::SYSTEM_ADMIN, 'admin2@test.com'),
            $this->createUser(UserRole::SYSTEM_ADMIN, 'admin3@test.com'),
        ];

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getSubmittedBy')->willReturn($submitter);
        $edoPayment->method('getAmount')->willReturn(500.00);
        $edoPayment->method('getId')->willReturn(1);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        $manifest->method('getId')->willReturn(1);

        // Mock repository to return multiple admins
        $this->setupUserRepositoryMock($admins, UserRole::SYSTEM_ADMIN);

        // Verify all admins receive notification
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->equalTo($admins),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentSubmitted($edoPayment);
    }

    public function testNotifyEDOPaymentValidatedSendsToCorrectRecipients(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $broker = $this->createUser(UserRole::BROKER, 'broker@test.com');
        $consignee = $this->createUser(UserRole::CONSIGNEE, 'consignee@test.com');

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getAmount')->willReturn(500.00);
        $edoPayment->method('getId')->willReturn(1);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getBroker')->willReturn($broker);
        $manifest->method('getConsignee')->willReturn($consignee);

        // Verify both broker and consignee receive notification
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->callback(function ($recipients) use ($broker, $consignee) {
                    return count($recipients) === 2
                        && in_array($broker, $recipients, true)
                        && in_array($consignee, $recipients, true);
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentValidated($edoPayment, true);
    }

    public function testNotifyEDOPaymentValidatedHandlesMissingConsignee(): void
    {
        // Create test data
        $edoPayment = $this->createEDOPayment();
        $manifest = $this->createManifest();
        $broker = $this->createUser(UserRole::BROKER, 'broker@test.com');

        $edoPayment->method('getManifest')->willReturn($manifest);
        $edoPayment->method('getAmount')->willReturn(500.00);
        $edoPayment->method('getId')->willReturn(1);

        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');
        $manifest->method('getId')->willReturn(1);
        $manifest->method('getBroker')->willReturn($broker);
        $manifest->method('getConsignee')->willReturn(null); // No consignee

        // Verify only broker receives notification
        $this->notificationGateway->expects($this->once())
            ->method('sendNotification')
            ->with(
                $this->callback(function ($recipients) use ($broker) {
                    return count($recipients) === 1
                        && $recipients[0] === $broker;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            );

        // Execute test
        $this->notificationService->notifyEDOPaymentValidated($edoPayment, true);
    }

    private function createEDOPayment(): MockObject
    {
        return $this->createMock(EDOPayment::class);
    }

    private function createManifest(): MockObject
    {
        return $this->createMock(Manifest::class);
    }

    private function createUser(UserRole $role, string $email): MockObject
    {
        // Create appropriate mock based on role
        if ($role === UserRole::BROKER) {
            $user = $this->createMock(Broker::class);
        } elseif ($role === UserRole::CONSIGNEE) {
            $user = $this->createMock(Consignee::class);
        } else {
            $user = $this->createMock(User::class);
        }
        
        $user->method('getRole')->willReturn($role);
        $user->method('getEmail')->willReturn($email);
        $user->method('getStatus')->willReturn(AccountStatus::APPROVED);
        return $user;
    }

    private function setupUserRepositoryMock(array $returnUsers, UserRole $role): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $repository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('u')
            ->willReturn($queryBuilder);

        $queryBuilder->expects($this->once())
            ->method('where')
            ->with('u.role = :role')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('u.status = :status')
            ->willReturnSelf();

        $queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('getQuery')
            ->willReturn($query);

        $query->expects($this->once())
            ->method('getResult')
            ->willReturn($returnUsers);
    }
}
