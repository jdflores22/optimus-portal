<?php

namespace App\Service;

use App\Entity\PendingUser;
use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\StaffUser;
use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\ActivityLog;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Repository\PendingUserRepository;
use App\Service\EmailNotificationService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Psr\Log\LoggerInterface;

class PendingUserService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PendingUserRepository $pendingUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailNotificationService $emailNotificationService,
        private LoggerInterface $logger,
        private ActivityLogService $activityLogService
    ) {
    }

    /**
     * Create a new pending user with secure token
     */
    public function createPendingUser(
        string $email,
        string $firstName,
        string $lastName,
        UserRole $role,
        User $createdByAdmin,
        ?ShippingLine $shippingLine = null,
        ?User $shippingLineAdmin = null
    ): PendingUser {
        // Validate input parameters
        $this->validateCreatePendingUserInput($email, $firstName, $lastName, $role, $createdByAdmin);

        // Create new pending user
        $pendingUser = new PendingUser();
        $pendingUser->setEmail($email);
        $pendingUser->setFirstName($firstName);
        $pendingUser->setLastName($lastName);
        $pendingUser->setRole($role);
        $pendingUser->setCreatedByAdmin($createdByAdmin);
        $pendingUser->setShippingLine($shippingLine);
        $pendingUser->setShippingLineAdmin($shippingLineAdmin);

        // Generate secure token and set expiration
        $pendingUser->generateAcceptanceToken();
        $pendingUser->setTokenExpirationToSevenDays();

        // Persist to database
        $this->entityManager->persist($pendingUser);
        $this->entityManager->flush();

        // Log the pending user creation activity
        $this->activityLogService->logActivity(
            $createdByAdmin,
            ActivityLog::TYPE_USER_INVITATION_CREATED,
            'PendingUser',
            $pendingUser->getId(),
            null,
            [
                'email' => $email,
                'role' => $role->value,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'shipping_line' => $shippingLine ? $shippingLine->getBrandName() : null,
                'shipping_line_admin' => $shippingLineAdmin ? $shippingLineAdmin->getEmail() : null,
                'invitation_token' => $pendingUser->getAcceptanceToken(),
                'expires_at' => $pendingUser->getTokenExpiresAt()->format('Y-m-d H:i:s')
            ]
        );

        $this->logger->info('Pending user created', [
            'email' => $email,
            'role' => $role->value,
            'created_by_admin_id' => $createdByAdmin->getId(),
            'token' => $pendingUser->getAcceptanceToken()
        ]);

        return $pendingUser;
    }

    /**
     * Find pending user by acceptance token
     */
    public function findByToken(string $token): ?PendingUser
    {
        if (empty($token) || strlen($token) !== 64) {
            return null;
        }

        return $this->pendingUserRepository->findByToken($token);
    }

    /**
     * Accept role and create actual user account
     */
    public function acceptRole(PendingUser $pendingUser): User
    {
        // Validate that the pending user can be processed
        if (!$pendingUser->canBeProcessed()) {
            throw new \InvalidArgumentException('Pending user cannot be processed - token may be expired or already used');
        }

        try {
            $this->entityManager->beginTransaction();

            // Create the actual user based on role
            $user = $this->createUserFromPendingUser($pendingUser);

            // Mark pending user as accepted instead of removing immediately
            // This allows the controller to access it for notifications and logging
            $pendingUser->markAsAccepted();

            // Persist the new user and update pending user status
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            
            // Log the role acceptance activity
            // Since the user is accepting their own role, we log it as the new user accepting
            $this->activityLogService->logActivity(
                $user, // The newly created user is the actor
                ActivityLog::TYPE_USER_ROLE_ACCEPTED,
                'User',
                $user->getId(),
                null,
                [
                    'email' => $user->getEmail(),
                    'role' => $user->getRole()->value,
                    'invited_by' => $pendingUser->getCreatedByAdmin()->getEmail(),
                    'invitation_token' => $pendingUser->getAcceptanceToken(),
                    'accepted_at' => (new \DateTime())->format('Y-m-d H:i:s')
                ]
            );
            
            $this->entityManager->commit();

            $this->logger->info('Role accepted and user created', [
                'email' => $pendingUser->getEmail(),
                'role' => $pendingUser->getRole()->value,
                'user_id' => $user->getId(),
                'token' => $pendingUser->getAcceptanceToken()
            ]);

            return $user;

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            
            $this->logger->error('Failed to accept role and create user', [
                'email' => $pendingUser->getEmail(),
                'error' => $e->getMessage(),
                'token' => $pendingUser->getAcceptanceToken()
            ]);

            throw new \RuntimeException('Failed to create user account: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decline role and remove pending user
     */
    public function declineRole(PendingUser $pendingUser): void
    {
        // Validate that the pending user can be processed
        if (!$pendingUser->canBeProcessed()) {
            throw new \InvalidArgumentException('Pending user cannot be processed - token may be expired or already used');
        }

        try {
            // Mark as declined
            $pendingUser->markAsDeclined();
            
            // Log the role decline activity
            // Since the person declining doesn't have a user account, we log it as the admin who sent the invitation
            $this->activityLogService->logActivity(
                $pendingUser->getCreatedByAdmin(), // The admin who sent the invitation is the actor
                ActivityLog::TYPE_USER_ROLE_DECLINED,
                'PendingUser',
                $pendingUser->getId(),
                null,
                [
                    'email' => $pendingUser->getEmail(),
                    'role' => $pendingUser->getRole()->value,
                    'invited_by' => $pendingUser->getCreatedByAdmin()->getEmail(),
                    'invitation_token' => $pendingUser->getAcceptanceToken(),
                    'declined_at' => (new \DateTime())->format('Y-m-d H:i:s')
                ]
            );
            
            $this->entityManager->flush();

            $this->logger->info('Role declined', [
                'email' => $pendingUser->getEmail(),
                'role' => $pendingUser->getRole()->value,
                'token' => $pendingUser->getAcceptanceToken()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to decline role', [
                'email' => $pendingUser->getEmail(),
                'error' => $e->getMessage(),
                'token' => $pendingUser->getAcceptanceToken()
            ]);

            throw new \RuntimeException('Failed to decline role: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Expire pending users and return count of expired users
     */
    public function expirePendingUsers(): int
    {
        try {
            $expiredCount = $this->pendingUserRepository->removeExpired();
            
            $this->logger->info('Expired pending users processed', [
                'expired_count' => $expiredCount
            ]);

            return $expiredCount;

        } catch (\Exception $e) {
            $this->logger->error('Failed to expire pending users', [
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to expire pending users: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Resend invitation for a pending user
     */
    public function resendInvitation(PendingUser $pendingUser): void
    {
        // Check if pending user is still valid for resending
        if ($pendingUser->getStatus() !== 'pending' && 
            $pendingUser->getStatus() !== 'expired' && 
            $pendingUser->getStatus() !== 'delivery_failed') {
            throw new \InvalidArgumentException('Cannot resend invitation for a user that has already accepted or declined');
        }

        try {
            // Reset status to pending and extend expiration
            $pendingUser->setStatus('pending');
            $pendingUser->setTokenExpirationToSevenDays();
            
            // Generate new token for security
            $pendingUser->generateAcceptanceToken();

            $this->entityManager->flush();

            $this->logger->info('Pending user updated for resend', [
                'email' => $pendingUser->getEmail(),
                'role' => $pendingUser->getRole()->value,
                'new_token' => $pendingUser->getAcceptanceToken()
            ]);

            // Send the role acceptance email
            $this->emailNotificationService->sendRoleAcceptanceEmail($pendingUser);

            $this->logger->info('Invitation resent successfully', [
                'email' => $pendingUser->getEmail(),
                'role' => $pendingUser->getRole()->value,
                'new_token' => $pendingUser->getAcceptanceToken()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to resend invitation', [
                'email' => $pendingUser->getEmail(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException('Failed to resend invitation: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create actual User entity from PendingUser data
     */
    private function createUserFromPendingUser(PendingUser $pendingUser): User
    {
        $role = $pendingUser->getRole();
        
        // Create appropriate user type based on role
        $user = match ($role) {
            UserRole::CONSIGNEE => new Consignee(),
            UserRole::BROKER => new Broker(),
            UserRole::EVALUATOR, UserRole::SHIPPING_LINES_ADMIN, 
            UserRole::SL_STAFF, UserRole::ACCOUNTING, 
            UserRole::SYSTEM_ADMIN, UserRole::TRUCKER, 
            UserRole::TERMINAL_TEAM => new StaffUser(),
        };

        // Set basic user properties
        $user->setEmail($pendingUser->getEmail());
        $user->setRole($role);
        $user->setStatus(AccountStatus::APPROVED);
        
        // Mark email as verified since they responded to the invitation email
        // This proves they have access to the email address and serves as email verification
        // No additional email verification step is needed
        $user->setEmailVerifiedAt(new \DateTime());

        // Generate a temporary password that user will need to change
        $temporaryPassword = $this->generateTemporaryPassword();
        $hashedPassword = $this->passwordHasher->hashPassword($user, $temporaryPassword);
        $user->setPasswordHash($hashedPassword);

        // Set type-specific fields
        if ($user instanceof StaffUser) {
            $user->setFirstName($pendingUser->getFirstName());
            $user->setLastName($pendingUser->getLastName());
            
            // Set department based on role
            $department = match ($role) {
                UserRole::SYSTEM_ADMIN => 'Administration',
                UserRole::SHIPPING_LINES_ADMIN => 'Management',
                UserRole::ACCOUNTING => 'Accounting',
                UserRole::TERMINAL_TEAM => 'Terminal Operations',
                UserRole::EVALUATOR, UserRole::SL_STAFF => 'Operations',
                UserRole::TRUCKER => 'Logistics',
                default => 'General'
            };
            $user->setDepartment($department);
        } elseif ($user instanceof Broker) {
            $user->setFullName($pendingUser->getFirstName() . ' ' . $pendingUser->getLastName());
        } elseif ($user instanceof Consignee) {
            // For consignee, we'll use the full name as business name for now
            // This might need adjustment based on business requirements
            $user->setBusinessName($pendingUser->getFirstName() . ' ' . $pendingUser->getLastName());
        }

        // Set hierarchy relationships
        if ($pendingUser->getShippingLineAdmin()) {
            $user->setShippingLineAdmin($pendingUser->getShippingLineAdmin());
        }

        if ($pendingUser->getShippingLine()) {
            $user->setManagedShippingLine($pendingUser->getShippingLine());
        }

        return $user;
    }

    /**
     * Generate a secure temporary password
     */
    private function generateTemporaryPassword(): string
    {
        // Generate a 12-character password with mixed case, numbers, and symbols
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        
        for ($i = 0; $i < 12; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }

    /**
     * Validate input parameters for createPendingUser
     */
    private function validateCreatePendingUserInput(
        string $email,
        string $firstName,
        string $lastName,
        UserRole $role,
        User $createdByAdmin
    ): void {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Valid email address is required');
        }

        if (empty(trim($firstName))) {
            throw new \InvalidArgumentException('First name is required');
        }

        if (empty(trim($lastName))) {
            throw new \InvalidArgumentException('Last name is required');
        }

        if (!$createdByAdmin->getId()) {
            throw new \InvalidArgumentException('Creating admin must be a persisted user');
        }

        // Check if there's already a pending user with this email
        $existingPendingUsers = $this->pendingUserRepository->findByEmail($email);
        foreach ($existingPendingUsers as $existingPendingUser) {
            if ($existingPendingUser->getStatus() === 'pending' && $existingPendingUser->isTokenValid()) {
                throw new \InvalidArgumentException('A pending invitation already exists for this email address');
            }
        }

        // Check if user already exists with this email
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            throw new \InvalidArgumentException('A user with this email address already exists');
        }
    }

    /**
     * Get statistics about pending users by status
     */
    public function getPendingUserStatistics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $results = $qb->select('p.status, COUNT(p.id) as count')
            ->from(PendingUser::class, 'p')
            ->groupBy('p.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'pending' => 0,
            'expired' => 0,
            'delivery_failed' => 0,
            'accepted' => 0,
            'declined' => 0,
            'total' => 0
        ];

        foreach ($results as $result) {
            $status = $result['status'];
            $count = (int) $result['count'];
            
            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
            
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Get all pending users with delivery failed status for admin management
     */
    public function getDeliveryFailedInvitations(?User $admin = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $qb->select('p')
            ->from(PendingUser::class, 'p')
            ->where('p.status = :status')
            ->setParameter('status', 'delivery_failed')
            ->orderBy('p.createdAt', 'DESC');

        // Filter by admin if specified (for shipping line admins)
        if ($admin && $admin->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $qb->andWhere('p.createdByAdmin = :admin OR p.shippingLineAdmin = :admin')
               ->setParameter('admin', $admin);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Mark a pending user as delivery failed and notify admin
     */
    public function markAsDeliveryFailed(PendingUser $pendingUser, string $errorMessage = null): void
    {
        try {
            $pendingUser->setStatus('delivery_failed');
            $this->entityManager->flush();

            $this->logger->warning('Pending user marked as delivery failed', [
                'email' => $pendingUser->getEmail(),
                'role' => $pendingUser->getRole()->value,
                'admin_id' => $pendingUser->getCreatedByAdmin()->getId(),
                'error' => $errorMessage
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to mark pending user as delivery failed', [
                'email' => $pendingUser->getEmail(),
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to update delivery status: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get count of delivery failed invitations for admin dashboard
     */
    public function getDeliveryFailedCount(?User $admin = null): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $qb->select('COUNT(p.id)')
            ->from(PendingUser::class, 'p')
            ->where('p.status = :status')
            ->setParameter('status', 'delivery_failed');

        // Filter by admin if specified (for shipping line admins)
        if ($admin && $admin->getRole() === UserRole::SHIPPING_LINES_ADMIN) {
            $qb->andWhere('p.createdByAdmin = :admin OR p.shippingLineAdmin = :admin')
               ->setParameter('admin', $admin);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Clean up accepted pending users (can be called by a scheduled task)
     */
    public function cleanupAcceptedPendingUsers(): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            
            $acceptedPendingUsers = $qb->select('p')
                ->from(PendingUser::class, 'p')
                ->where('p.status = :status')
                ->setParameter('status', 'accepted')
                ->getQuery()
                ->getResult();

            $count = count($acceptedPendingUsers);
            
            foreach ($acceptedPendingUsers as $pendingUser) {
                $this->entityManager->remove($pendingUser);
            }
            
            $this->entityManager->flush();

            $this->logger->info('Cleaned up accepted pending users', [
                'count' => $count
            ]);

            return $count;

        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup accepted pending users', [
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to cleanup accepted pending users: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clean up declined pending users older than 30 days
     */
    public function cleanupDeclinedPendingUsers(): int
    {
        try {
            $thirtyDaysAgo = new \DateTime('-30 days');
            
            $qb = $this->entityManager->createQueryBuilder();
            
            $declinedPendingUsers = $qb->select('p')
                ->from(PendingUser::class, 'p')
                ->where('p.status = :status')
                ->andWhere('p.createdAt < :thirtyDaysAgo')
                ->setParameter('status', 'declined')
                ->setParameter('thirtyDaysAgo', $thirtyDaysAgo)
                ->getQuery()
                ->getResult();

            $count = count($declinedPendingUsers);
            
            foreach ($declinedPendingUsers as $pendingUser) {
                $this->entityManager->remove($pendingUser);
            }
            
            $this->entityManager->flush();

            $this->logger->info('Cleaned up declined pending users', [
                'count' => $count
            ]);

            return $count;

        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup declined pending users', [
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to cleanup declined pending users: ' . $e->getMessage(), 0, $e);
        }
    }
}