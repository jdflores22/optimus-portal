<?php

namespace App\Tests\Unit\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\ShippingLine;
use App\Entity\Manifest;
use App\Entity\EDOPayment;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Repository\ActivityLogRepository;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class ActivityLogServiceTest extends TestCase
{
    private ActivityLogService $service;
    private EntityManagerInterface|MockObject $entityManager;
    private ActivityLogRepository|MockObject $repository;
    private RequestStack|MockObject $requestStack;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(ActivityLogRepository::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->service = new ActivityLogService(
            $this->entityManager,
            $this->repository,
            $this->requestStack
        );
    }

    public function testLogActivityWithRequest(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())
            ->method('getId')
            ->willReturn('session123');

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getClientIp')
            ->willReturn('192.168.1.1');
        $request->headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $request->headers->expects($this->once())
            ->method('get')
            ->with('User-Agent')
            ->willReturn('Mozilla/5.0');
        $request->expects($this->once())
            ->method('getSession')
            ->willReturn($session);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn($request);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(ActivityLog::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logActivity(
            $user,
            ActivityLog::TYPE_LOGIN,
            'User',
            $user->getId()
        );

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogActivityWithoutRequest(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) {
                return $log->getIpAddress() === '127.0.0.1' &&
                       $log->getActivityType() === ActivityLog::TYPE_LOGIN;
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logActivity(
            $user,
            ActivityLog::TYPE_LOGIN,
            'User',
            $user->getId()
        );

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogLogin(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user) {
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_LOGIN &&
                       $log->getEntityType() === 'User' &&
                       $log->getEntityId() === $user->getId();
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logLogin($user, '192.168.1.1', 'Mozilla/5.0');

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogLogout(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user) {
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_LOGOUT;
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logLogout($user);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogCreate(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Line');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user) {
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_CREATE &&
                       $log->getEntityType() === 'ShippingLine';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logCreate($user, $shippingLine);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogUpdate(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Line');

        $changes = [
            'old' => ['brand_name' => 'Old Name'],
            'new' => ['brand_name' => 'New Name']
        ];

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user, $changes) {
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_UPDATE &&
                       $log->getOldValues() === $changes['old'] &&
                       $log->getNewValues() === $changes['new'];
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logUpdate($user, $shippingLine, $changes);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogUserCreation(): void
    {
        // Arrange
        $actor = new StaffUser();
        $actor->setRole(UserRole::SYSTEM_ADMIN);
        $actor->setEmail('admin@test.com');

        $newUser = new StaffUser();
        $newUser->setRole(UserRole::SL_STAFF);
        $newUser->setEmail('staff@test.com');
        $newUser->setStatus(AccountStatus::PENDING);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($actor, $newUser) {
                $newValues = $log->getNewValues();
                return $log->getUser() === $actor &&
                       $log->getActivityType() === ActivityLog::TYPE_USER_CREATION &&
                       $newValues['email'] === $newUser->getEmail() &&
                       $newValues['role'] === $newUser->getRole()->value;
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logUserCreation($actor, $newUser);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogShippingLineCreation(): void
    {
        // Arrange
        $actor = new StaffUser();
        $actor->setRole(UserRole::SYSTEM_ADMIN);
        $actor->setEmail('admin@test.com');

        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');
        $shippingLine->setPortalConfig(['theme' => 'blue']);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($actor, $shippingLine) {
                $newValues = $log->getNewValues();
                return $log->getUser() === $actor &&
                       $log->getActivityType() === ActivityLog::TYPE_SHIPPING_LINE_CREATION &&
                       $newValues['brand_name'] === $shippingLine->getBrandName() &&
                       $newValues['portal_config'] === $shippingLine->getPortalConfig();
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logShippingLineCreation($actor, $shippingLine);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogHierarchyChange(): void
    {
        // Arrange
        $actor = new StaffUser();
        $actor->setRole(UserRole::SYSTEM_ADMIN);
        $actor->setEmail('admin@test.com');

        $child = new StaffUser();
        $child->setRole(UserRole::SL_STAFF);
        $child->setEmail('staff@test.com');

        $oldParent = new StaffUser();
        $oldParent->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $oldParent->setEmail('old-admin@test.com');

        $newParent = new StaffUser();
        $newParent->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $newParent->setEmail('new-admin@test.com');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($actor, $child, $oldParent, $newParent) {
                $oldValues = $log->getOldValues();
                $newValues = $log->getNewValues();
                return $log->getUser() === $actor &&
                       $log->getActivityType() === ActivityLog::TYPE_HIERARCHY_CHANGE &&
                       $log->getEntityId() === $child->getId() &&
                       $oldValues['parent_email'] === $oldParent->getEmail() &&
                       $newValues['parent_email'] === $newParent->getEmail();
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logHierarchyChange($actor, $child, $oldParent, $newParent);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogSearch(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SL_STAFF);
        $user->setEmail('staff@test.com');

        $results = ['result1', 'result2', 'result3'];

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user, $results) {
                $context = $log->getAdditionalContext();
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_SEARCH &&
                       $context['search_term'] === 'test query' &&
                       $context['result_count'] === count($results);
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logSearch($user, 'test query', $results, 'containers');

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogAccessDenied(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SL_STAFF);
        $user->setEmail('staff@test.com');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user) {
                $context = $log->getAdditionalContext();
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_ACCESS_DENIED &&
                       $context['resource'] === '/admin/users' &&
                       $context['reason'] === 'Insufficient permissions';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logAccessDenied($user, '/admin/users', 'Insufficient permissions');

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testGetActivityHistory(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SL_STAFF);
        $user->setEmail('staff@test.com');

        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Line');

        $from = new \DateTime('2024-01-01');
        $to = new \DateTime('2024-01-31');

        $expectedFilters = [
            'user_id' => $user->getId(),
            'shipping_line_id' => $shippingLine->getId(),
            'from_date' => $from,
            'to_date' => $to
        ];

        $expectedResults = []; // Mock results

        $this->repository->expects($this->once())
            ->method('searchWithFilters')
            ->with($expectedFilters)
            ->willReturn($expectedResults);

        // Act
        $result = $this->service->getActivityHistory($user, $shippingLine, $from, $to);

        // Assert
        $this->assertEquals($expectedResults, $result);
    }

    public function testLogActivityHandlesException(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->willThrowException(new \Exception('Database error'));

        // Act - should not throw exception, just log error
        $this->service->logActivity(
            $user,
            ActivityLog::TYPE_LOGIN,
            'User',
            $user->getId()
        );

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogEDOPaymentSubmission(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::BROKER);
        $user->setEmail('broker@test.com');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(123);
        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');

        $edoPayment = $this->createMock(EDOPayment::class);
        $edoPayment->method('getId')->willReturn(456);
        $edoPayment->method('getAmount')->willReturn(5000.00);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user) {
                $context = $log->getAdditionalContext();
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_PAYMENT_PROCESSING &&
                       $log->getEntityId() === 456 &&
                       $context['edo_payment_id'] === 456 &&
                       $context['manifest_id'] === 123 &&
                       $context['manifest_number'] === 'MAN-2024-001' &&
                       $context['amount'] === 5000.00 &&
                       $context['payment_type'] === 'edo_access' &&
                       $context['description'] === 'Submitted eDO payment for manifest MAN-2024-001 (Amount: ₱5,000.00)';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logEDOPaymentSubmission($user, $edoPayment, $manifest);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogEDOPaymentValidationApproved(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(123);
        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');

        $edoPayment = $this->createMock(EDOPayment::class);
        $edoPayment->method('getId')->willReturn(456);
        $edoPayment->method('getRejectionReason')->willReturn(null);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user) {
                $context = $log->getAdditionalContext();
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_PAYMENT_PROCESSING &&
                       $log->getEntityId() === 456 &&
                       $context['edo_payment_id'] === 456 &&
                       $context['manifest_id'] === 123 &&
                       $context['manifest_number'] === 'MAN-2024-001' &&
                       $context['approved'] === true &&
                       $context['rejection_reason'] === null &&
                       $context['payment_type'] === 'edo_access' &&
                       $context['description'] === 'Approved eDO payment for manifest MAN-2024-001';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logEDOPaymentValidation($user, $edoPayment, $manifest, true);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogEDOPaymentValidationRejected(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::SYSTEM_ADMIN);
        $user->setEmail('admin@test.com');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(123);
        $manifest->method('getManifestNumber')->willReturn('MAN-2024-001');

        $edoPayment = $this->createMock(EDOPayment::class);
        $edoPayment->method('getId')->willReturn(456);
        $edoPayment->method('getRejectionReason')->willReturn('Invalid receipt document');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) use ($user) {
                $context = $log->getAdditionalContext();
                return $log->getUser() === $user &&
                       $log->getActivityType() === ActivityLog::TYPE_PAYMENT_PROCESSING &&
                       $log->getEntityId() === 456 &&
                       $context['edo_payment_id'] === 456 &&
                       $context['manifest_id'] === 123 &&
                       $context['manifest_number'] === 'MAN-2024-001' &&
                       $context['approved'] === false &&
                       $context['rejection_reason'] === 'Invalid receipt document' &&
                       $context['payment_type'] === 'edo_access' &&
                       $context['description'] === 'Rejected eDO payment for manifest MAN-2024-001';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logEDOPaymentValidation($user, $edoPayment, $manifest, false);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogEDOPaymentSubmissionWithLargeAmount(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::BROKER);
        $user->setEmail('broker@test.com');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(999);
        $manifest->method('getManifestNumber')->willReturn('MAN-2024-999');

        $edoPayment = $this->createMock(EDOPayment::class);
        $edoPayment->method('getId')->willReturn(789);
        $edoPayment->method('getAmount')->willReturn(123456.78);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) {
                $context = $log->getAdditionalContext();
                return $context['description'] === 'Submitted eDO payment for manifest MAN-2024-999 (Amount: ₱123,456.78)';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logEDOPaymentSubmission($user, $edoPayment, $manifest);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogEDOPaymentSubmissionWithMetadata(): void
    {
        // Arrange
        $user = new StaffUser();
        $user->setRole(UserRole::BROKER);
        $user->setEmail('broker@test.com');

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getId')->willReturn(100);
        $manifest->method('getManifestNumber')->willReturn('MAN-2024-100');

        $edoPayment = $this->createMock(EDOPayment::class);
        $edoPayment->method('getId')->willReturn(200);
        $edoPayment->method('getAmount')->willReturn(2500.50);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) {
                $context = $log->getAdditionalContext();
                // Verify all metadata fields are present
                return isset($context['edo_payment_id']) &&
                       isset($context['manifest_id']) &&
                       isset($context['manifest_number']) &&
                       isset($context['amount']) &&
                       isset($context['payment_type']) &&
                       isset($context['description']) &&
                       $context['payment_type'] === 'edo_access';
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logEDOPaymentSubmission($user, $edoPayment, $manifest);

        // Assert - no exception thrown
        $this->assertTrue(true);
    }

    public function testLogFailedLoginPersistsForKnownUser(): void
    {
        $user = new StaffUser();
        $user->setRole(UserRole::BROKER);
        $user->setEmail('broker@test.com');
        $user->setStatus(AccountStatus::APPROVED);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (ActivityLog $log) {
                return $log->getActivityType() === ActivityLog::TYPE_FAILED_LOGIN
                    && $log->getEntityType() === 'User'
                    && ($log->getAdditionalContext()['reason'] ?? '') === 'Invalid password';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->logFailedLogin('broker@test.com', '10.0.0.1', $user, 'Invalid password');
    }
}