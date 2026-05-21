<?php

namespace App\Service;

use App\Entity\Enum\AccountStatus;
use App\Entity\ActivityLog;
use App\Entity\Trucker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\LockedException;

class TruckerAuthenticationService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 30; // minutes

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ?ActivityLogService $activityLogService = null
    ) {
    }

    public function authenticateTrucker(string $email, string $password): Trucker
    {
        $trucker = $this->entityManager->getRepository(Trucker::class)
            ->findOneBy(['email' => $email]);

        if (!$trucker) {
            throw new AuthenticationException('Invalid credentials');
        }

        // Check if account is locked
        if ($trucker->isLocked()) {
            throw new LockedException('Account is locked due to too many failed login attempts');
        }

        // Check if account is active
        if ($trucker->getStatus() !== AccountStatus::APPROVED) {
            throw new DisabledException('Account is not active. Please verify your email address.');
        }

        // Verify password
        if (!$this->passwordHasher->isPasswordValid($trucker, $password)) {
            $this->handleFailedLogin($trucker);
            throw new AuthenticationException('Invalid credentials');
        }

        // Reset failed login attempts on successful login
        $trucker->resetFailedLoginAttempts();
        $trucker->setLockedUntil(null);
        $this->entityManager->flush();

        return $trucker;
    }

    private function handleFailedLogin(Trucker $trucker): void
    {
        $trucker->incrementFailedLoginAttempts();

        if ($trucker->getFailedLoginAttempts() >= self::MAX_FAILED_ATTEMPTS) {
            $lockoutUntil = new \DateTime('+' . self::LOCKOUT_DURATION . ' minutes');
            $trucker->setLockedUntil($lockoutUntil);
            $trucker->setStatus(AccountStatus::LOCKED);
            
            $this->entityManager->flush();
            
            // Log account lock due to failed attempts
            if ($this->activityLogService) {
                $this->activityLogService->logActivity(
                    $trucker,
                    ActivityLog::TYPE_USER_ACCOUNT_LOCKED_FAILED_ATTEMPTS,
                    'Trucker',
                    $trucker->getId(),
                    null,
                    [
                        'email' => $trucker->getEmail(),
                        'failed_attempts' => $trucker->getFailedLoginAttempts(),
                        'locked_until' => $lockoutUntil->format('Y-m-d H:i:s'),
                        'reason' => 'Exceeded maximum failed login attempts'
                    ]
                );
            }
        } else {
            $this->entityManager->flush();
        }
    }

    public function unlockAccount(Trucker $trucker): void
    {
        $trucker->resetFailedLoginAttempts();
        $trucker->setLockedUntil(null);
        $trucker->setStatus(AccountStatus::APPROVED);
        $this->entityManager->flush();
    }

    public function changePassword(Trucker $trucker, string $currentPassword, string $newPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($trucker, $currentPassword)) {
            throw new AuthenticationException('Current password is incorrect');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($trucker, $newPassword);
        $trucker->setPasswordHash($hashedPassword);
        $this->entityManager->flush();
    }

    public function resetPassword(string $email): ?Trucker
    {
        $trucker = $this->entityManager->getRepository(Trucker::class)
            ->findOneBy(['email' => $email]);

        if (!$trucker) {
            return null;
        }

        // Generate password reset token (reusing email verification token field)
        $trucker->setEmailVerificationToken(bin2hex(random_bytes(32)));
        $trucker->setEmailVerificationTokenExpiresAt(new \DateTime('+1 hour'));

        $this->entityManager->flush();

        return $trucker;
    }

    public function confirmPasswordReset(string $token, string $newPassword): bool
    {
        $trucker = $this->entityManager->getRepository(Trucker::class)
            ->findOneBy(['emailVerificationToken' => $token]);

        if (!$trucker || !$trucker->isEmailVerificationTokenValid()) {
            return false;
        }

        $hashedPassword = $this->passwordHasher->hashPassword($trucker, $newPassword);
        $trucker->setPasswordHash($hashedPassword);
        $trucker->setEmailVerificationToken(null);
        $trucker->setEmailVerificationTokenExpiresAt(null);
        $trucker->resetFailedLoginAttempts();
        $trucker->setLockedUntil(null);

        if ($trucker->getStatus() === AccountStatus::LOCKED) {
            $trucker->setStatus(AccountStatus::APPROVED);
        }

        $this->entityManager->flush();

        return true;
    }
}