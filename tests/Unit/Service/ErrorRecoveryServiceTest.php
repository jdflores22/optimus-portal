<?php

namespace App\Tests\Unit\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\ErrorRecoveryService;
use App\Service\DatabaseTransactionService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ErrorRecoveryServiceTest extends TestCase
{
    private ErrorRecoveryService $errorRecoveryService;
    private EntityManagerInterface $entityManager;
    private DatabaseTransactionService $transactionService;
    private ActivityLogService $activityLogService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->transactionService = $this->createMock(DatabaseTransactionService::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->errorRecoveryService = new ErrorRecoveryService(
            $this->entityManager,
            $this->transactionService,
            $this->activityLogService,
            $this->logger
        );
    }

    public function testHandleShippingLineCreationFailureWithNonCriticalError(): void
    {
        // Arrange
        $data = ['brandName' => 'Test Line', 'portalConfig' => []];
        $exception = new \Exception('portal_config validation failed');
        $creator = $this->createMockUser(UserRole::SYSTEM_ADMIN);

        // Act
        $result = $this->errorRecoveryService->handleShippingLineCreationFailure($data, $exception, $creator);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertTrue($result['degraded_mode']);
        $this->assertContains('Enabled degraded mode with minimal configuration', $result['recovery_actions']);
    }

    public function testHandleShippingLineCreationFailureWithCriticalError(): void
    {
        // Arrange
        $data = ['brandName' => 'Test Line'];
        $exception = new \Exception('Database connection failed');
        $creator = $this->createMockUser(UserRole::SYSTEM_ADMIN);

        // Mock repository to return null (no partial creation)
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // Act
        $result = $this->errorRecoveryService->handleShippingLineCreationFailure($data, $exception, $creator);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertFalse($result['degraded_mode']);
        $this->assertNotEmpty($result['recovery_actions']);
    }

    public function testHandleUserHierarchyFailureWithPartialCreation(): void
    {
        // Arrange
        $userData = [
            'email' => 'test@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'role' => UserRole::SL_STAFF->value
        ];
        $exception = new \Exception('Hierarchy linking failed');
        $creator = $this->createMockUser(UserRole::SYSTEM_ADMIN);

        // Mock partial user creation
        $partialUser = $this->createMockUser(UserRole::SL_STAFF);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($partialUser);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // Act
        $result = $this->errorRecoveryService->handleUserHierarchyFailure($userData, $exception, $creator);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertTrue($result['partial_creation']);
        $this->assertContains('Marked partial user for manual review', $result['recovery_actions']);
    }

    public function testPerformAutomaticCleanupSuccess(): void
    {
        // Arrange - simplified test
        $this->entityManager->method('getRepository')->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));

        // Act
        $result = $this->errorRecoveryService->performAutomaticCleanup();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('orphaned_users_cleaned', $result);
        $this->assertArrayHasKey('inactive_sessions_cleaned', $result);
        $this->assertArrayHasKey('temporary_data_cleaned', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testRollbackComplexOperationWithShippingLineCreation(): void
    {
        // Arrange
        $operationData = ['shipping_line_id' => 1];
        $actor = $this->createMockUser(UserRole::SYSTEM_ADMIN);

        $shippingLine = $this->createMock(ShippingLine::class);
        $users = [$this->createMockUser(UserRole::SL_STAFF)];
        
        // Mock the getUsers method properly
        $shippingLine->expects($this->once())
            ->method('getUsers')
            ->willReturn($users);

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('find')->willReturn($shippingLine);
        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->transactionService->method('executeInTransactionWithRetry')
            ->willReturnCallback(function($callback) {
                return $callback();
            });

        // Act
        $result = $this->errorRecoveryService->rollbackComplexOperation(
            'shipping_line_creation_with_admin',
            $operationData,
            $actor
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertContains('Removed shipping line and associated users', $result['operations_rolled_back']);
    }

    public function testRollbackComplexOperationWithInvalidOperationType(): void
    {
        // Arrange
        $operationData = [];
        $actor = $this->createMockUser(UserRole::SYSTEM_ADMIN);

        $this->transactionService->method('executeInTransactionWithRetry')
            ->willThrowException(new \InvalidArgumentException('Unknown operation type: invalid_type'));

        // Act
        $result = $this->errorRecoveryService->rollbackComplexOperation(
            'invalid_type',
            $operationData,
            $actor
        );

        // Assert
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Unknown operation type', $result['errors'][0]);
    }

    public function testRollbackComplexOperationWithBulkUserCreation(): void
    {
        // Arrange
        $operationData = ['user_ids' => [1, 2, 3]];
        $actor = $this->createMockUser(UserRole::SYSTEM_ADMIN);

        $users = [
            $this->createMockUser(UserRole::SL_STAFF),
            $this->createMockUser(UserRole::EVALUATOR),
            $this->createMockUser(UserRole::ACCOUNTING)
        ];

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('find')->willReturnOnConsecutiveCalls(...$users);
        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->transactionService->method('executeInTransactionWithRetry')
            ->willReturnCallback(function($callback) {
                return $callback();
            });

        // Act
        $result = $this->errorRecoveryService->rollbackComplexOperation(
            'bulk_user_hierarchy_creation',
            $operationData,
            $actor
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertContains('Removed 3 users', $result['operations_rolled_back']);
    }

    public function testRollbackComplexOperationWithConfigurationUpdate(): void
    {
        // Arrange
        $operationData = [
            'shipping_line_id' => 1,
            'previous_config' => ['feature' => 'old_value']
        ];
        $actor = $this->createMockUser(UserRole::SYSTEM_ADMIN);

        $shippingLine = $this->createMock(ShippingLine::class);
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('find')->willReturn($shippingLine);
        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->transactionService->method('executeInTransactionWithRetry')
            ->willReturnCallback(function($callback) {
                return $callback();
            });

        // Act
        $result = $this->errorRecoveryService->rollbackComplexOperation(
            'shipping_line_configuration_update',
            $operationData,
            $actor
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertContains('Restored previous configuration', $result['operations_rolled_back']);
    }

    private function createMockUser(UserRole $role): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn($role);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getId')->willReturn(1);
        $user->method('isActive')->willReturn(true);
        return $user;
    }
}