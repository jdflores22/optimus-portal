<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\LockedException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enhanced authentication service supporting hierarchical roles and shipping line scope
 */
class AuthenticationService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 30; // minutes

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ActivityLogService $activityLogService,
        private ScopeAccessControlService $scopeAccessControlService,
        private RequestStack $requestStack
    ) {
    }

    /**
     * Authenticates a user with enhanced security for hierarchical roles
     * 
     * @param string $email User email
     * @param string $password User password
     * @return User Authenticated user
     * @throws AuthenticationException If authentication fails
     */
    public function authenticateUser(string $email, string $password): User
    {
        $request = $this->requestStack->getCurrentRequest();
        $ipAddress = $request?->getClientIp() ?? '127.0.0.1';
        $userAgent = $request?->headers->get('User-Agent') ?? 'Unknown';

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if (!$user) {
            $this->activityLogService->logFailedLogin($email, $ipAddress, null, 'Invalid credentials');
            throw new AuthenticationException('Invalid credentials');
        }

        // Check if account is locked
        if ($user->isLocked()) {
            $this->activityLogService->logAccessDenied($user, 'login', 'Account locked');
            throw new LockedException('Account is locked due to too many failed login attempts');
        }

        // Check if account is active
        if ($user->getStatus() !== AccountStatus::APPROVED) {
            $this->activityLogService->logAccessDenied($user, 'login', 'Account not active');
            throw new DisabledException('Account is not active. Please verify your email address.');
        }

        // Validate shipping line hierarchy
        if (!$this->validateUserHierarchy($user)) {
            $this->activityLogService->logAccessDenied($user, 'login', 'Invalid user hierarchy');
            throw new DisabledException('Account configuration is invalid. Please contact administrator.');
        }

        // Verify password
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->handleFailedLogin($user, $ipAddress);
            throw new AuthenticationException('Invalid credentials');
        }

        // Check if user's shipping line admin is active (for hierarchical roles)
        if ($user->requiresShippingLineAdmin()) {
            $admin = $user->getShippingLineAdmin();
            if ($admin === null || !$admin->isActive()) {
                $this->activityLogService->logAccessDenied($user, 'login', 'Shipping line admin inactive');
                throw new DisabledException('Your shipping line administrator is inactive. Please contact support.');
            }
        }

        // Reset failed login attempts on successful login
        $user->resetFailedLoginAttempts();
        $user->setLockedUntil(null);
        $this->entityManager->flush();

        // Log successful login
        $this->activityLogService->logLogin($user, $ipAddress, $userAgent);

        return $user;
    }

    /**
     * Validates user authorization for specific actions with shipping line scope
     * 
     * @param User $user The user to authorize
     * @param string $action The action being performed
     * @param object|null $resource The resource being accessed
     * @return bool True if authorized
     */
    public function authorizeAction(User $user, string $action, ?object $resource = null): bool
    {
        // Check if user is active
        if (!$user->isActive()) {
            $this->activityLogService->logAccessDenied($user, $action, 'User not active');
            return false;
        }

        // Validate shipping line scope if resource is provided
        if ($resource !== null) {
            try {
                $this->scopeAccessControlService->validateAccess($user, $resource);
            } catch (\Exception $e) {
                return false;
            }
        }

        // Role-based authorization
        return $this->authorizeByRole($user, $action, $resource);
    }

    /**
     * Prevents privilege escalation attempts
     * 
     * @param User $user The user attempting escalation
     * @param string $targetRole The role they're trying to access
     * @param string $action The action they're trying to perform
     */
    public function preventPrivilegeEscalation(User $user, string $targetRole, string $action): void
    {
        $userRole = $user->getRole();
        
        // Check if user is trying to access higher privilege role
        if ($this->isPrivilegeEscalation($userRole, $targetRole)) {
            $this->scopeAccessControlService->preventPrivilegeEscalation(
                $user, 
                "Attempted to access {$targetRole} while having {$userRole->value} role for action: {$action}"
            );
        }
    }

    /**
     * Changes user password with enhanced security
     * 
     * @param User $user The user changing password
     * @param string $currentPassword Current password
     * @param string $newPassword New password
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            $this->activityLogService->logAccessDenied($user, 'password_change', 'Invalid current password');
            throw new AuthenticationException('Current password is incorrect');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPasswordHash($hashedPassword);
        $this->entityManager->flush();

        $this->activityLogService->logPasswordChange($user);
    }

    /**
     * Unlocks a user account (SYSTEM_ADMIN or SHIPPING_LINES_ADMIN only)
     * 
     * @param User $actor The user performing the unlock
     * @param User $targetUser The user to unlock
     */
    public function unlockAccount(User $actor, User $targetUser): void
    {
        // Validate authorization
        if (!$this->canManageUser($actor, $targetUser)) {
            $this->activityLogService->logAccessDenied($actor, 'unlock_account', 'Cannot manage target user');
            throw new AuthenticationException('You do not have permission to unlock this account');
        }

        $targetUser->resetFailedLoginAttempts();
        $targetUser->setLockedUntil(null);
        
        if ($targetUser->getStatus() === AccountStatus::LOCKED) {
            $targetUser->setStatus(AccountStatus::APPROVED);
        }
        
        $this->entityManager->flush();

        $this->activityLogService->logUserActivation($actor, $targetUser);
    }

    /**
     * Suspends a user account
     * 
     * @param User $actor The user performing the suspension
     * @param User $targetUser The user to suspend
     */
    public function suspendAccount(User $actor, User $targetUser): void
    {
        // Validate authorization
        if (!$this->canManageUser($actor, $targetUser)) {
            $this->activityLogService->logAccessDenied($actor, 'suspend_account', 'Cannot manage target user');
            throw new AuthenticationException('You do not have permission to suspend this account');
        }

        $targetUser->setStatus(AccountStatus::SUSPENDED);
        $this->entityManager->flush();

        $this->activityLogService->logUserSuspension($actor, $targetUser);
    }

    /**
     * Validates session security for new role types
     * 
     * @param User $user The user to validate
     * @return bool True if session is valid
     */
    public function validateSession(User $user): bool
    {
        // Check if user is still active
        if (!$user->isActive()) {
            return false;
        }

        // Check if user hierarchy is still valid
        if (!$this->validateUserHierarchy($user)) {
            return false;
        }

        // Check if shipping line admin is still active (for hierarchical roles)
        if (!$this->isShippingLineAdminActive($user)) {
            return false;
        }

        return true;
    }

    /**
     * Logs user logout
     * 
     * @param User $user The user logging out
     */
    public function logoutUser(User $user): void
    {
        $this->activityLogService->logLogout($user);
    }

    /**
     * Logs session timeout
     * 
     * @param User $user The user whose session timed out
     */
    public function logSessionTimeout(User $user): void
    {
        $this->activityLogService->logSessionTimeout($user);
    }

    // Private helper methods

    /**
     * Checks if account is active based on status and hierarchy
     */
    private function isAccountActive(User $user): bool
    {
        if ($user->getStatus() !== AccountStatus::APPROVED) {
            return false;
        }

        // For hierarchical roles, check if their admin is also active
        if ($user->requiresShippingLineAdmin()) {
            $admin = $user->getShippingLineAdmin();
            if ($admin === null || !$admin->isActive()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates user hierarchy relationships
     */
    private function validateUserHierarchy(User $user): bool
    {
        $errors = $user->validateHierarchy();
        return empty($errors);
    }

    /**
     * Checks if user's shipping line admin is active
     */
    private function isShippingLineAdminActive(User $user): bool
    {
        if (!$user->requiresShippingLineAdmin()) {
            return true; // Not applicable for non-hierarchical roles
        }

        $admin = $user->getShippingLineAdmin();
        return $admin !== null && $admin->isActive();
    }

    /**
     * Handles failed login attempts
     */
    private function handleFailedLogin(User $user, string $ipAddress): void
    {
        $user->incrementFailedLoginAttempts();

        if ($user->getFailedLoginAttempts() >= self::MAX_FAILED_ATTEMPTS) {
            $lockoutUntil = new \DateTime('+' . self::LOCKOUT_DURATION . ' minutes');
            $user->setLockedUntil($lockoutUntil);
            $user->setStatus(AccountStatus::LOCKED);
            
            $this->entityManager->flush();
            
            // Log account lock due to failed attempts
            $this->activityLogService->logActivity(
                $user,
                ActivityLog::TYPE_USER_ACCOUNT_LOCKED_FAILED_ATTEMPTS,
                'User',
                $user->getId(),
                null,
                [
                    'email' => $user->getEmail(),
                    'failed_attempts' => $user->getFailedLoginAttempts(),
                    'locked_until' => $lockoutUntil->format('Y-m-d H:i:s'),
                    'reason' => 'Exceeded maximum failed login attempts'
                ]
            );
        } else {
            $this->entityManager->flush();
        }

        $this->activityLogService->logFailedLogin(
            $user->getEmail(),
            $ipAddress,
            $user,
            'Invalid password'
        );
    }

    /**
     * Role-based authorization logic
     */
    private function authorizeByRole(User $user, string $action, ?object $resource): bool
    {
        $role = $user->getRole();

        // SYSTEM_ADMIN has all permissions
        if ($role === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // Role-specific authorization logic
        switch ($role) {
            case UserRole::SHIPPING_LINES_ADMIN:
                return $this->authorizeShippingLineAdmin($user, $action, $resource);
            
            case UserRole::SL_STAFF:
            case UserRole::EVALUATOR:
            case UserRole::ACCOUNTING:
            case UserRole::TERMINAL_TEAM:
                return $this->authorizeSubordinateRole($user, $action, $resource);
            
            case UserRole::CONSIGNEE:
            case UserRole::BROKER:
            case UserRole::TRUCKER:
                return $this->authorizeIndependentRole($user, $action, $resource);
            
            default:
                return false;
        }
    }

    /**
     * Authorization for SHIPPING_LINES_ADMIN role
     */
    private function authorizeShippingLineAdmin(User $user, string $action, ?object $resource): bool
    {
        // SHIPPING_LINES_ADMIN can manage their shipping line and subordinates
        return true; // Implement specific business logic
    }

    /**
     * Authorization for subordinate roles (SL_STAFF, EVALUATOR, ACCOUNTING, TERMINAL_TEAM)
     */
    private function authorizeSubordinateRole(User $user, string $action, ?object $resource): bool
    {
        // Subordinate roles have limited permissions within their scope
        return true; // Implement specific business logic
    }

    /**
     * Authorization for independent roles (CONSIGNEE, BROKER, TRUCKER)
     */
    private function authorizeIndependentRole(User $user, string $action, ?object $resource): bool
    {
        // Independent roles maintain existing authorization logic
        return true; // Implement existing business logic
    }

    /**
     * Checks if user can manage another user
     */
    private function canManageUser(User $actor, User $targetUser): bool
    {
        return $actor->canManageUser($targetUser);
    }

    /**
     * Checks if accessing a role constitutes privilege escalation
     */
    private function isPrivilegeEscalation(UserRole $userRole, string $targetRole): bool
    {
        $roleHierarchy = [
            'SYSTEM_ADMIN' => 0,
            'SHIPPING_LINES_ADMIN' => 1,
            'SL_STAFF' => 2,
            'EVALUATOR' => 2,
            'ACCOUNTING' => 2,
            'TERMINAL_TEAM' => 2,
            'CONSIGNEE' => 3,
            'BROKER' => 3,
            'TRUCKER' => 3,
        ];

        $userLevel = $roleHierarchy[$userRole->value] ?? 999;
        $targetLevel = $roleHierarchy[$targetRole] ?? 999;

        return $targetLevel < $userLevel;
    }
}