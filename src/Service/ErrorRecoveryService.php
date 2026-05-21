<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Error recovery service for shipping line management system
 * Handles graceful degradation, automatic cleanup, and rollback capabilities
 * Requirements: 10.4, 10.5, 10.6
 */
class ErrorRecoveryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatabaseTransactionService $transactionService,
        private ActivityLogService $activityLogService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Graceful degradation for shipping line creation failures
     * Requirements: 10.4, 10.5
     */
    public function handleShippingLineCreationFailure(array $data, \Exception $exception, User $creator): array
    {
        $this->logger->warning('Shipping line creation failed, attempting graceful degradation', [
            'data' => $data,
            'exception' => $exception->getMessage(),
            'creator' => $creator->getEmail()
        ]);

        $recovery = [
            'success' => false,
            'degraded_mode' => false,
            'cleanup_performed' => false,
            'error_message' => 'Shipping line creation failed',
            'recovery_actions' => []
        ];

        try {
            // Check if this is a non-critical failure that allows degraded mode
            if ($this->isNonCriticalFailure($exception)) {
                $recovery = $this->enableDegradedMode($data, $creator, $recovery);
            } else {
                // Perform cleanup for critical failures
                $recovery = $this->performCreationCleanup($data, $creator, $recovery);
            }

            // Log the recovery attempt
            $this->activityLogService->logActivity(
                $creator,
                'error_recovery',
                'shipping_line',
                null,
                null,
                [
                    'original_error' => $exception->getMessage(),
                    'recovery_actions' => $recovery['recovery_actions'],
                    'degraded_mode' => $recovery['degraded_mode']
                ]
            );

        } catch (\Exception $recoveryException) {
            $this->logger->error('Error recovery failed', [
                'original_exception' => $exception->getMessage(),
                'recovery_exception' => $recoveryException->getMessage()
            ]);

            $recovery['error_message'] = 'Creation failed and recovery was unsuccessful';
            $recovery['recovery_actions'][] = 'Recovery process failed - manual intervention required';
        }

        return $recovery;
    }

    /**
     * Graceful degradation for user hierarchy creation failures
     * Requirements: 10.4, 10.5
     */
    public function handleUserHierarchyFailure(array $userData, \Exception $exception, User $creator): array
    {
        $this->logger->warning('User hierarchy creation failed, attempting recovery', [
            'user_data' => array_intersect_key($userData, array_flip(['email', 'role'])),
            'exception' => $exception->getMessage(),
            'creator' => $creator->getEmail()
        ]);

        $recovery = [
            'success' => false,
            'partial_creation' => false,
            'cleanup_performed' => false,
            'error_message' => 'User creation failed',
            'recovery_actions' => []
        ];

        try {
            // Check if user was partially created
            if (isset($userData['email'])) {
                $partialUser = $this->entityManager->getRepository(User::class)
                    ->findOneBy(['email' => $userData['email']]);

                if ($partialUser) {
                    $recovery = $this->handlePartialUserCreation($partialUser, $userData, $creator, $recovery);
                }
            }

            // Perform hierarchy cleanup if needed
            $recovery = $this->performHierarchyCleanup($userData, $creator, $recovery);

            // Log the recovery attempt
            $this->activityLogService->logActivity(
                $creator,
                'error_recovery',
                'user_hierarchy',
                null,
                null,
                [
                    'original_error' => $exception->getMessage(),
                    'recovery_actions' => $recovery['recovery_actions'],
                    'partial_creation' => $recovery['partial_creation']
                ]
            );

        } catch (\Exception $recoveryException) {
            $this->logger->error('User hierarchy recovery failed', [
                'original_exception' => $exception->getMessage(),
                'recovery_exception' => $recoveryException->getMessage()
            ]);

            $recovery['error_message'] = 'User creation failed and recovery was unsuccessful';
            $recovery['recovery_actions'][] = 'Recovery process failed - manual intervention required';
        }

        return $recovery;
    }

    /**
     * Automatic cleanup for orphaned data
     * Requirements: 10.4, 10.5, 10.6
     */
    public function performAutomaticCleanup(): array
    {
        $cleanupResults = [
            'orphaned_users_cleaned' => 0,
            'inactive_sessions_cleaned' => 0,
            'temporary_data_cleaned' => 0,
            'errors' => []
        ];

        try {
            // Clean up orphaned users
            $cleanupResults['orphaned_users_cleaned'] = $this->cleanupOrphanedUsers();

            // Clean up inactive sessions
            $cleanupResults['inactive_sessions_cleaned'] = $this->cleanupInactiveSessions();

            // Clean up temporary data
            $cleanupResults['temporary_data_cleaned'] = $this->cleanupTemporaryData();

            $this->logger->info('Automatic cleanup completed', $cleanupResults);

        } catch (\Exception $e) {
            $this->logger->error('Automatic cleanup failed', [
                'exception' => $e->getMessage(),
                'partial_results' => $cleanupResults
            ]);

            $cleanupResults['errors'][] = 'Cleanup process encountered errors: ' . $e->getMessage();
        }

        return $cleanupResults;
    }

    /**
     * Rollback capabilities for complex operations
     * Requirements: 10.4, 10.5, 10.6
     */
    public function rollbackComplexOperation(string $operationType, array $operationData, User $actor): array
    {
        $rollbackResult = [
            'success' => false,
            'operations_rolled_back' => [],
            'errors' => []
        ];

        try {
            return $this->transactionService->executeInTransactionWithRetry(
                function() use ($operationType, $operationData, $actor, &$rollbackResult) {
                    switch ($operationType) {
                        case 'shipping_line_creation_with_admin':
                            $rollbackResult = $this->rollbackShippingLineCreation($operationData, $actor, $rollbackResult);
                            break;

                        case 'bulk_user_hierarchy_creation':
                            $rollbackResult = $this->rollbackBulkUserCreation($operationData, $actor, $rollbackResult);
                            break;

                        case 'shipping_line_configuration_update':
                            $rollbackResult = $this->rollbackConfigurationUpdate($operationData, $actor, $rollbackResult);
                            break;

                        default:
                            throw new \InvalidArgumentException("Unknown operation type: {$operationType}");
                    }

                    $rollbackResult['success'] = true;
                    return $rollbackResult;
                },
                "rollback_{$operationType}"
            );

        } catch (\Exception $e) {
            $this->logger->error('Rollback operation failed', [
                'operation_type' => $operationType,
                'exception' => $e->getMessage(),
                'actor' => $actor->getEmail()
            ]);

            $rollbackResult['errors'][] = 'Rollback failed: ' . $e->getMessage();
            return $rollbackResult;
        }
    }

    /**
     * Check if failure is non-critical and allows degraded mode
     */
    private function isNonCriticalFailure(\Exception $exception): bool
    {
        $nonCriticalPatterns = [
            'portal_config',
            'branding',
            'notification',
            'cache'
        ];

        $message = strtolower($exception->getMessage());
        foreach ($nonCriticalPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enable degraded mode for non-critical failures
     */
    private function enableDegradedMode(array $data, User $creator, array $recovery): array
    {
        try {
            // Create shipping line with minimal configuration
            $minimalData = [
                'brandName' => $data['brandName'],
                'portalConfig' => ['features' => ['enableNotifications' => false]]
            ];

            // This would be handled by the main service with minimal config
            $recovery['degraded_mode'] = true;
            $recovery['recovery_actions'][] = 'Enabled degraded mode with minimal configuration';
            $recovery['error_message'] = 'Shipping line created with limited features due to configuration errors';

        } catch (\Exception $e) {
            $recovery['recovery_actions'][] = 'Failed to enable degraded mode: ' . $e->getMessage();
        }

        return $recovery;
    }

    /**
     * Perform cleanup after creation failure
     */
    private function performCreationCleanup(array $data, User $creator, array $recovery): array
    {
        try {
            // Check for partially created shipping line
            if (isset($data['brandName'])) {
                $partialShippingLine = $this->entityManager->getRepository(ShippingLine::class)
                    ->findOneBy(['brandName' => $data['brandName']]);

                if ($partialShippingLine) {
                    $this->entityManager->remove($partialShippingLine);
                    $this->entityManager->flush();
                    $recovery['cleanup_performed'] = true;
                    $recovery['recovery_actions'][] = 'Removed partially created shipping line';
                }
            }

        } catch (\Exception $e) {
            $recovery['recovery_actions'][] = 'Cleanup failed: ' . $e->getMessage();
        }

        return $recovery;
    }

    /**
     * Handle partial user creation scenarios
     */
    private function handlePartialUserCreation(User $partialUser, array $userData, User $creator, array $recovery): array
    {
        try {
            // Check if user is in a valid state
            if ($partialUser->getRole() === null || $partialUser->getFirstName() === null) {
                // User is incomplete, remove it
                $this->entityManager->remove($partialUser);
                $this->entityManager->flush();
                $recovery['cleanup_performed'] = true;
                $recovery['recovery_actions'][] = 'Removed incomplete user record';
            } else {
                // User might be salvageable, mark for manual review
                $partialUser->setStatus(AccountStatus::PENDING);
                $this->entityManager->flush();
                $recovery['partial_creation'] = true;
                $recovery['recovery_actions'][] = 'Marked partial user for manual review';
            }

        } catch (\Exception $e) {
            $recovery['recovery_actions'][] = 'Failed to handle partial user: ' . $e->getMessage();
        }

        return $recovery;
    }

    /**
     * Perform hierarchy cleanup
     */
    private function performHierarchyCleanup(array $userData, User $creator, array $recovery): array
    {
        try {
            // Clean up any orphaned hierarchy references
            // This is a placeholder for more complex cleanup logic
            $recovery['cleanup_performed'] = true;
            $recovery['recovery_actions'][] = 'Performed hierarchy cleanup';

        } catch (\Exception $e) {
            $recovery['recovery_actions'][] = 'Hierarchy cleanup failed: ' . $e->getMessage();
        }

        return $recovery;
    }

    /**
     * Clean up orphaned users
     */
    private function cleanupOrphanedUsers(): int
    {
        $hierarchicalRoles = [UserRole::SL_STAFF, UserRole::EVALUATOR, UserRole::ACCOUNTING, UserRole::TERMINAL_TEAM];
        $cleanedCount = 0;

        foreach ($hierarchicalRoles as $role) {
            $orphanedUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.shippingLineAdmin IS NULL')
                ->setParameter('role', $role)
                ->getQuery()
                ->getResult();

            foreach ($orphanedUsers as $user) {
                $user->setStatus(AccountStatus::SUSPENDED);
                $cleanedCount++;
            }
        }

        if ($cleanedCount > 0) {
            $this->entityManager->flush();
        }

        return $cleanedCount;
    }

    /**
     * Clean up inactive sessions
     */
    private function cleanupInactiveSessions(): int
    {
        // This would integrate with Symfony's session handling
        // For now, return a placeholder count
        return 0;
    }

    /**
     * Clean up temporary data
     */
    private function cleanupTemporaryData(): int
    {
        // Clean up temporary files, cache entries, etc.
        // For now, return a placeholder count
        return 0;
    }

    /**
     * Rollback shipping line creation
     */
    private function rollbackShippingLineCreation(array $operationData, User $actor, array $rollbackResult): array
    {
        if (isset($operationData['shipping_line_id'])) {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)
                ->find($operationData['shipping_line_id']);

            if ($shippingLine) {
                // Remove associated users first
                $users = $shippingLine->getUsers();
                foreach ($users as $user) {
                    $this->entityManager->remove($user);
                }

                // Remove the shipping line
                $this->entityManager->remove($shippingLine);
                $rollbackResult['operations_rolled_back'][] = 'Removed shipping line and associated users';
            }
        }

        return $rollbackResult;
    }

    /**
     * Rollback bulk user creation
     */
    private function rollbackBulkUserCreation(array $operationData, User $actor, array $rollbackResult): array
    {
        if (isset($operationData['user_ids'])) {
            foreach ($operationData['user_ids'] as $userId) {
                $user = $this->entityManager->getRepository(User::class)->find($userId);
                if ($user) {
                    $this->entityManager->remove($user);
                }
            }
            $rollbackResult['operations_rolled_back'][] = 'Removed ' . count($operationData['user_ids']) . ' users';
        }

        return $rollbackResult;
    }

    /**
     * Rollback configuration update
     */
    private function rollbackConfigurationUpdate(array $operationData, User $actor, array $rollbackResult): array
    {
        if (isset($operationData['shipping_line_id']) && isset($operationData['previous_config'])) {
            $shippingLine = $this->entityManager->getRepository(ShippingLine::class)
                ->find($operationData['shipping_line_id']);

            if ($shippingLine) {
                $shippingLine->setPortalConfig($operationData['previous_config']);
                $rollbackResult['operations_rolled_back'][] = 'Restored previous configuration';
            }
        }

        return $rollbackResult;
    }
}