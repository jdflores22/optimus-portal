<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\Collection;

/**
 * Service for managing user hierarchy relationships and role validation
 * Handles linking users to admins, hierarchy validation, and orphaned user cleanup
 */
class UserHierarchyService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogService $activityLogService,
        private ShippingLineService $shippingLineService,
        private ValidationService $validationService,
        private ErrorRecoveryService $errorRecoveryService
    ) {
    }

    /**
     * Link a user to their shipping line admin
     * 
     * @param User $user The user to link (must be subordinate role)
     * @param User $admin The admin to link to (must be SHIPPING_LINES_ADMIN)
     * @param User $linker The user performing the linking operation
     * @throws \InvalidArgumentException If validation fails
     */
    /**
     * Create a new user with comprehensive validation and error handling
     * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6
     */
    public function createUserWithValidation(array $userData, User $creator): User
    {
        // Comprehensive input validation
        $validationErrors = $this->validationService->validateUserHierarchyCreation($userData);
        if (!empty($validationErrors)) {
            throw new ValidationException($validationErrors, 'User creation validation failed');
        }

        try {
            // Create user entity
            $user = new User();
            $user->setEmail($userData['email']);
            $user->setFirstName(trim($userData['firstName']));
            $user->setLastName(trim($userData['lastName']));
            $user->setRole(UserRole::from($userData['role']));
            $user->setStatus(AccountStatus::ACTIVE);

            // Set hierarchy relationships if required
            if (isset($userData['shippingLineAdminId'])) {
                $admin = $this->entityManager->getRepository(User::class)->find($userData['shippingLineAdminId']);
                if ($admin) {
                    $user->setShippingLineAdmin($admin);
                }
            }

            if (isset($userData['managedShippingLineId'])) {
                $shippingLine = $this->entityManager->getRepository(\App\Entity\ShippingLine::class)->find($userData['managedShippingLineId']);
                if ($shippingLine) {
                    $user->setManagedShippingLine($shippingLine);
                }
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Log the creation activity
            $this->activityLogService->logUserCreation($creator, $user);

            return $user;

        } catch (ValidationException $e) {
            // Re-throw validation exceptions
            throw $e;
        } catch (\Exception $e) {
            // Handle database constraint violations gracefully
            if (str_contains($e->getMessage(), 'constraint') || str_contains($e->getMessage(), 'duplicate')) {
                $constraintErrors = $this->validationService->handleConstraintViolation($e);
                throw new ValidationException(['database' => $constraintErrors], 'Database constraint violation during user creation');
            }

            // Attempt error recovery
            $recoveryResult = $this->errorRecoveryService->handleUserHierarchyFailure($userData, $e, $creator);
            
            // Log detailed error for debugging
            $this->activityLogService->logActivity(
                $creator,
                'user_creation_failed',
                'user',
                null,
                null,
                [
                    'error' => $e->getMessage(),
                    'user_data' => array_intersect_key($userData, array_flip(['email', 'role'])),
                    'recovery_result' => $recoveryResult
                ]
            );

            throw new \RuntimeException('Failed to create user: ' . $e->getMessage() . ' Recovery actions: ' . implode(', ', $recoveryResult['recovery_actions']), 0, $e);
        }
    }

    public function linkUserToAdmin(User $user, User $admin, User $linker): void
    {
        // Validate linker permissions
        if (!$this->canManageHierarchy($linker, $user)) {
            throw new \InvalidArgumentException('Insufficient permissions to manage user hierarchy');
        }

        // Comprehensive validation using ValidationService
        $hierarchyData = [
            'role' => $user->getRole()->value,
            'shippingLineAdminId' => $admin->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName()
        ];

        $validationErrors = $this->validationService->validateUserHierarchyCreation($hierarchyData);
        if (!empty($validationErrors)) {
            throw new ValidationException($validationErrors, 'User hierarchy validation failed');
        }

        try {
            $oldAdmin = $user->getShippingLineAdmin();
            
            // Set the hierarchy relationship
            $user->setShippingLineAdmin($admin);
            $admin->addSubordinateUser($user);

            $this->entityManager->flush();

            // Log the hierarchy change
            $this->activityLogService->logHierarchyChange($linker, $user, $oldAdmin, $admin);

        } catch (ValidationException $e) {
            // Re-throw validation exceptions
            throw $e;
        } catch (\Exception $e) {
            // Handle database constraint violations gracefully
            if (str_contains($e->getMessage(), 'constraint') || str_contains($e->getMessage(), 'foreign key')) {
                $constraintErrors = $this->validationService->handleConstraintViolation($e);
                throw new ValidationException(['database' => $constraintErrors], 'Database constraint violation during hierarchy linking');
            }

            // Attempt error recovery
            $recoveryResult = $this->errorRecoveryService->handleUserHierarchyFailure($hierarchyData, $e, $linker);
            
            // Log detailed error for debugging
            $this->activityLogService->logActivity(
                $linker,
                'user_hierarchy_linking_failed',
                'user',
                $user->getId(),
                null,
                [
                    'error' => $e->getMessage(),
                    'admin_id' => $admin->getId(),
                    'recovery_result' => $recoveryResult
                ]
            );

            throw new \RuntimeException('Failed to link user to admin: ' . $e->getMessage() . ' Recovery actions: ' . implode(', ', $recoveryResult['recovery_actions']), 0, $e);
        }
    }

    /**
     * Validate role hierarchy relationships
     * 
     * @param UserRole $parentRole The parent role in the hierarchy
     * @param UserRole $childRole The child role in the hierarchy
     * @return bool True if hierarchy is valid
     */
    public function validateRoleHierarchy(UserRole $parentRole, UserRole $childRole): bool
    {
        // SYSTEM_ADMIN can supervise SHIPPING_LINES_ADMIN
        if ($parentRole === UserRole::SYSTEM_ADMIN && $childRole === UserRole::SHIPPING_LINES_ADMIN) {
            return true;
        }

        // SHIPPING_LINES_ADMIN can supervise subordinate roles
        if ($parentRole === UserRole::SHIPPING_LINES_ADMIN) {
            $validSubordinateRoles = [
                UserRole::SL_STAFF,
                UserRole::EVALUATOR,
                UserRole::ACCOUNTING,
                UserRole::TERMINAL_TEAM
            ];
            return in_array($childRole, $validSubordinateRoles);
        }

        return false;
    }

    /**
     * Get all subordinate users for an admin
     * 
     * @param User $admin The admin user
     * @return Collection<int, User> Collection of subordinate users
     */
    public function getSubordinateUsers(User $admin): Collection
    {
        return $admin->getSubordinateUsers();
    }

    /**
     * Handle cleanup when an admin is deleted (orphaned user management)
     * 
     * @param User $deletedAdmin The admin that was deleted
     * @param User $actor The user performing the cleanup
     * @param string $cleanupStrategy Strategy: 'reassign', 'suspend', or 'delete'
     * @param User|null $newAdmin New admin for reassignment (required if strategy is 'reassign')
     * @throws \InvalidArgumentException If validation fails
     */
    public function orphanedUserCleanup(User $deletedAdmin, User $actor, string $cleanupStrategy, ?User $newAdmin = null): void
    {
        // Validate actor permissions
        if ($actor->getRole() !== UserRole::SYSTEM_ADMIN) {
            throw new \InvalidArgumentException('Only SYSTEM_ADMIN can perform orphaned user cleanup');
        }

        // Validate cleanup strategy
        $validStrategies = ['reassign', 'suspend', 'delete'];
        if (!in_array($cleanupStrategy, $validStrategies)) {
            throw new \InvalidArgumentException('Invalid cleanup strategy');
        }

        // Validate new admin if reassigning
        if ($cleanupStrategy === 'reassign') {
            if ($newAdmin === null) {
                throw new \InvalidArgumentException('New admin is required for reassignment strategy');
            }
            if ($newAdmin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
                throw new \InvalidArgumentException('New admin must have SHIPPING_LINES_ADMIN role');
            }
            if (!$newAdmin->isActive()) {
                throw new \InvalidArgumentException('New admin must be active');
            }
        }

        $subordinateUsers = $deletedAdmin->getSubordinateUsers()->toArray();

        try {
            foreach ($subordinateUsers as $user) {
                switch ($cleanupStrategy) {
                    case 'reassign':
                        $this->linkUserToAdmin($user, $newAdmin, $actor);
                        $this->activityLogService->logUserReassignment($actor, $user, $deletedAdmin, $newAdmin);
                        break;

                    case 'suspend':
                        $user->setStatus(AccountStatus::SUSPENDED);
                        $user->setShippingLineAdmin(null);
                        $this->activityLogService->logUserSuspension($actor, $user);
                        break;

                    case 'delete':
                        $this->activityLogService->logUserDeletion($actor, $user);
                        $this->entityManager->remove($user);
                        break;
                }
            }

            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to cleanup orphaned users: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Remove a user from hierarchy (unlink from admin)
     * 
     * @param User $user The user to remove from hierarchy
     * @param User $remover The user performing the removal
     * @throws \InvalidArgumentException If validation fails
     */
    public function removeFromHierarchy(User $user, User $remover): void
    {
        // Validate remover permissions
        if (!$this->canManageHierarchy($remover, $user)) {
            throw new \InvalidArgumentException('Insufficient permissions to manage user hierarchy');
        }

        $oldAdmin = $user->getShippingLineAdmin();
        
        if ($oldAdmin === null) {
            throw new \InvalidArgumentException('User is not linked to any admin');
        }

        try {
            // Remove hierarchy relationship
            $user->setShippingLineAdmin(null);
            $oldAdmin->removeSubordinateUser($user);

            $this->entityManager->flush();

            // Log the hierarchy change
            $this->activityLogService->logHierarchyChange($remover, $user, $oldAdmin, null);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to remove user from hierarchy: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the complete hierarchy tree for a user
     * 
     * @param User $root The root user to start from
     * @return array Hierarchical array of users
     */
    public function getHierarchyTree(User $root): array
    {
        $tree = [
            'user' => $root,
            'children' => []
        ];

        foreach ($root->getSubordinateUsers() as $subordinate) {
            $tree['children'][] = $this->getHierarchyTree($subordinate);
        }

        return $tree;
    }

    /**
     * Validate complete user hierarchy integrity
     * 
     * @return array Array of validation errors (empty if valid)
     */
    public function validateHierarchyIntegrity(): array
    {
        $errors = [];
        $userRepository = $this->entityManager->getRepository(User::class);
        
        // Get all users that require shipping line admin
        $hierarchicalRoles = [
            UserRole::SL_STAFF,
            UserRole::EVALUATOR,
            UserRole::ACCOUNTING,
            UserRole::TERMINAL_TEAM
        ];

        foreach ($hierarchicalRoles as $role) {
            $users = $userRepository->findBy(['role' => $role]);
            
            foreach ($users as $user) {
                $userErrors = $user->validateHierarchy();
                if (!empty($userErrors)) {
                    $errors[] = sprintf('User %s (ID: %d): %s', 
                        $user->getEmail(), 
                        $user->getId(), 
                        implode(', ', $userErrors)
                    );
                }
            }
        }

        // Check SHIPPING_LINES_ADMIN users have managed shipping lines
        $admins = $userRepository->findBy(['role' => UserRole::SHIPPING_LINES_ADMIN]);
        foreach ($admins as $admin) {
            if ($admin->getManagedShippingLine() === null) {
                $errors[] = sprintf('SHIPPING_LINES_ADMIN %s (ID: %d) has no managed shipping line', 
                    $admin->getEmail(), 
                    $admin->getId()
                );
            }
        }

        return $errors;
    }

    /**
     * Get all users within the same shipping line scope as the given user
     * 
     * @param User $user The user to get scope for
     * @return array Array of users in the same scope
     */
    public function getScopedUsers(User $user): array
    {
        $shippingLine = $this->shippingLineService->getShippingLineScope($user);
        
        if ($shippingLine === null) {
            // SYSTEM_ADMIN or independent roles - return empty array or all users based on role
            if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
                return $this->entityManager->getRepository(User::class)->findAll();
            }
            return [];
        }

        return $shippingLine->getScopedUsers();
    }

    /**
     * Check if a user can manage another user's hierarchy
     * 
     * @param User $manager The user attempting to manage
     * @param User $target The user being managed
     * @return bool True if management is allowed
     */
    public function canManageHierarchy(User $manager, User $target): bool
    {
        // SYSTEM_ADMIN can manage anyone
        if ($manager->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // SHIPPING_LINES_ADMIN can manage their subordinates
        if ($manager->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            return $target->getShippingLineAdmin() === $manager || 
                   $target->requiresShippingLineAdmin();
        }

        return false;
    }

    /**
     * Transfer users from one admin to another
     * 
     * @param User $fromAdmin The current admin
     * @param User $toAdmin The new admin
     * @param User $transferor The user performing the transfer
     * @param array|null $userIds Specific user IDs to transfer (null for all)
     * @throws \InvalidArgumentException If validation fails
     */
    public function transferUsers(User $fromAdmin, User $toAdmin, User $transferor, ?array $userIds = null): void
    {
        // Validate transferor permissions
        if ($transferor->getRole() !== UserRole::SYSTEM_ADMIN) {
            throw new \InvalidArgumentException('Only SYSTEM_ADMIN can transfer users between admins');
        }

        // Validate both admins
        if ($fromAdmin->getRole() !== UserRole::SHIPPING_LINES_ADMIN || 
            $toAdmin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
            throw new \InvalidArgumentException('Both users must be SHIPPING_LINES_ADMIN');
        }

        if (!$toAdmin->isActive()) {
            throw new \InvalidArgumentException('Target admin must be active');
        }

        $usersToTransfer = [];
        
        if ($userIds === null) {
            // Transfer all subordinates
            $usersToTransfer = $fromAdmin->getSubordinateUsers()->toArray();
        } else {
            // Transfer specific users
            foreach ($userIds as $userId) {
                $user = $this->entityManager->getRepository(User::class)->find($userId);
                if ($user === null) {
                    throw new \InvalidArgumentException("User with ID {$userId} not found");
                }
                if ($user->getShippingLineAdmin() !== $fromAdmin) {
                    throw new \InvalidArgumentException("User {$userId} is not subordinate to source admin");
                }
                $usersToTransfer[] = $user;
            }
        }

        try {
            foreach ($usersToTransfer as $user) {
                $this->linkUserToAdmin($user, $toAdmin, $transferor);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to transfer users: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get hierarchy statistics for reporting
     * 
     * @return array Statistics about user hierarchy
     */
    public function getHierarchyStatistics(): array
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        
        return [
            'total_admins' => count($userRepository->findBy(['role' => UserRole::SHIPPING_LINES_ADMIN])),
            'total_staff' => count($userRepository->findBy(['role' => UserRole::SL_STAFF])),
            'total_evaluators' => count($userRepository->findBy(['role' => UserRole::EVALUATOR])),
            'total_accounting' => count($userRepository->findBy(['role' => UserRole::ACCOUNTING])),
            'total_terminal_team' => count($userRepository->findBy(['role' => UserRole::TERMINAL_TEAM])),
            'orphaned_users' => $this->getOrphanedUsers(),
            'integrity_errors' => count($this->validateHierarchyIntegrity())
        ];
    }

    /**
     * Get users without proper hierarchy links (orphaned users)
     * 
     * @return array Array of orphaned users
     */
    private function getOrphanedUsers(): array
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $orphaned = [];

        $hierarchicalRoles = [
            UserRole::SL_STAFF,
            UserRole::EVALUATOR,
            UserRole::ACCOUNTING,
            UserRole::TERMINAL_TEAM
        ];

        foreach ($hierarchicalRoles as $role) {
            $users = $userRepository->findBy(['role' => $role]);
            foreach ($users as $user) {
                if ($user->getShippingLineAdmin() === null) {
                    $orphaned[] = $user;
                }
            }
        }

        return $orphaned;
    }
}