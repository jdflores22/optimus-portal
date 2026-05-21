<?php

namespace App\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\StaffUser;
use App\Entity\User;
use App\Entity\ActivityLog;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ?NotificationService $notificationService = null,
        private ?ActivityLogService $activityLogService = null
    ) {
    }

    /**
     * Create a new user with hashed password (bcrypt cost 12)
     */
    public function createUser(array $data, UserRole $role): User
    {
        $user = match ($role) {
            UserRole::CONSIGNEE => new Consignee(),
            UserRole::BROKER => new Broker(),
            UserRole::EVALUATOR, UserRole::SHIPPING_LINES_ADMIN, 
            UserRole::SL_STAFF, UserRole::ACCOUNTING, 
            UserRole::SYSTEM_ADMIN => new StaffUser(),
        };

        $user->setEmail($data['email']);
        $user->setRole($role);
        
        // Hash password with bcrypt cost 12
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPasswordHash($hashedPassword);

        // Set type-specific fields
        if ($user instanceof Consignee && isset($data['businessName'])) {
            $user->setBusinessName($data['businessName']);
        } elseif ($user instanceof Broker && isset($data['fullName'])) {
            $user->setFullName($data['fullName']);
        } elseif ($user instanceof StaffUser) {
            if (isset($data['firstName'])) {
                $user->setFirstName($data['firstName']);
            }
            if (isset($data['lastName'])) {
                $user->setLastName($data['lastName']);
            }
            if (isset($data['department'])) {
                $user->setDepartment($data['department']);
            }
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Authenticate user with credential validation
     */
    public function authenticate(string $email, string $password): AuthenticationResult
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            return new AuthenticationResult(false, null, 'Invalid credentials');
        }

        // Check if account is locked
        if ($user->isLocked()) {
            return new AuthenticationResult(false, $user, 'Account is locked');
        }

        // Verify password
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            // Increment failed login attempts
            $user->incrementFailedLoginAttempts();
            
            // Lock account after 3 failed attempts
            if ($user->getFailedLoginAttempts() >= 3) {
                $this->lockAccount($user->getId(), 30); // Lock for 30 minutes
                
                // Send account lock notification
                if ($this->notificationService) {
                    try {
                        $this->notificationService->sendAccountLockNotification($user);
                    } catch (\Exception $e) {
                        // Log notification failure but don't fail authentication
                        error_log('Failed to send account lock notification: ' . $e->getMessage());
                    }
                }
                
                $this->entityManager->flush();
                return new AuthenticationResult(false, $user, 'Account locked due to multiple failed login attempts');
            }
            
            $this->entityManager->flush();
            return new AuthenticationResult(false, $user, 'Invalid credentials');
        }

        // Reset failed login attempts on successful authentication
        $user->resetFailedLoginAttempts();
        $this->entityManager->flush();

        return new AuthenticationResult(true, $user, 'Authentication successful');
    }

    /**
     * Assign role to user
     */
    public function assignRole(int $userId, UserRole $role): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $user->setRole($role);
        $this->entityManager->flush();
    }

    /**
     * Lock user account for specified duration in minutes
     */
    public function lockAccount(int $userId, int $durationMinutes): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $lockedUntil = new \DateTime();
        $lockedUntil->modify("+{$durationMinutes} minutes");
        
        $user->setLockedUntil($lockedUntil);
        $user->setStatus(AccountStatus::LOCKED);
        
        $this->entityManager->flush();
        
        // Log account lock due to failed attempts
        if ($this->activityLogService) {
            $this->activityLogService->logActivity(
                $user,
                ActivityLog::TYPE_USER_ACCOUNT_LOCKED_FAILED_ATTEMPTS,
                'User',
                $user->getId(),
                null,
                [
                    'email' => $user->getEmail(),
                    'failed_attempts' => $user->getFailedLoginAttempts(),
                    'locked_until' => $lockedUntil->format('Y-m-d H:i:s'),
                    'lock_duration_minutes' => $durationMinutes,
                    'reason' => 'Exceeded maximum failed login attempts'
                ]
            );
        }
    }

    /**
     * Unlock user account
     */
    public function unlockAccount(int $userId): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $user->setLockedUntil(null);
        $user->resetFailedLoginAttempts();
        
        // Only change status if it was LOCKED, preserve other statuses
        if ($user->getStatus() === AccountStatus::LOCKED) {
            $user->setStatus(AccountStatus::PENDING);
        }
        
        $this->entityManager->flush();
    }

    /**
     * Set the notification service (used for dependency injection)
     */
    public function setNotificationService(NotificationService $notificationService): void
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Check if user has required role/permission
     */
    public function hasRole(User $user, UserRole $requiredRole): bool
    {
        return $user->getRole() === $requiredRole;
    }

    /**
     * Check if user has any of the specified roles
     */
    public function hasAnyRole(User $user, array $roles): bool
    {
        return in_array($user->getRole(), $roles, true);
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?User
    {
        return $this->entityManager->getRepository(User::class)->find($id);
    }

    /**
     * Simple authenticate method for API usage - returns User or null
     */
    public function authenticateForApi(string $email, string $password): ?User
    {
        $result = $this->authenticate($email, $password);
        return $result->isSuccess() ? $result->getUser() : null;
    }

    /**
     * Get all approved consignees for manifest declaration
     */
    public function getApprovedConsignees(): array
    {
        return $this->entityManager->getRepository(Consignee::class)
            ->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', AccountStatus::APPROVED)
            ->orderBy('c.businessName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
