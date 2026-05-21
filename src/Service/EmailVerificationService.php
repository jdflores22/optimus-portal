<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class EmailVerificationService
{
    private const TOKEN_EXPIRY_HOURS = 24;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private string $fromAddress
    ) {
    }

    /**
     * Generate and send email verification token
     */
    public function sendVerificationEmail(User $user): void
    {
        // Generate verification token
        $token = $this->generateVerificationToken();
        $expiresAt = new \DateTime('+' . self::TOKEN_EXPIRY_HOURS . ' hours');

        // Update user with verification token
        $user->setEmailVerificationToken($token);
        $user->setEmailVerificationTokenExpiresAt($expiresAt);
        $user->setStatus(AccountStatus::EMAIL_UNVERIFIED);

        $this->entityManager->flush();

        // Generate verification URL
        $verificationUrl = $this->urlGenerator->generate(
            'email_verify',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            // Send verification email
            $email = (new Email())
                ->from($this->fromAddress)
                ->to($user->getEmail())
                ->subject('Verify Your Email Address - OPTIMUS Portal')
                ->html($this->twig->render('emails/email_verification.html.twig', [
                    'user' => $user,
                    'verificationUrl' => $verificationUrl,
                    'expiresAt' => $expiresAt
                ]));

            $this->mailer->send($email);

            $this->logger->info('Email verification sent', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email verification', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Verify email using token
     */
    public function verifyEmail(string $token): ?User
    {
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['emailVerificationToken' => $token]);

        if (!$user) {
            $this->logger->warning('Email verification attempted with invalid token', [
                'token' => substr($token, 0, 8) . '...'
            ]);
            return null;
        }

        // Check if token is still valid
        if (!$user->isEmailVerificationTokenValid()) {
            $this->logger->warning('Email verification attempted with expired token', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'token_expires_at' => $user->getEmailVerificationTokenExpiresAt()?->format('Y-m-d H:i:s')
            ]);
            return null;
        }

        // Verify the email
        $user->setEmailVerifiedAt(new \DateTime());
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);
        $user->setStatus(AccountStatus::PENDING); // Move to pending for admin approval

        $this->entityManager->flush();

        $this->logger->info('Email verified successfully', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'verified_at' => $user->getEmailVerifiedAt()->format('Y-m-d H:i:s')
        ]);

        return $user;
    }

    /**
     * Resend verification email
     */
    public function resendVerificationEmail(User $user): void
    {
        if ($user->isEmailVerified()) {
            throw new \InvalidArgumentException('Email is already verified');
        }

        $this->sendVerificationEmail($user);
    }

    /**
     * Check if user can login (email must be verified)
     */
    public function canUserLogin(User $user): bool
    {
        return $user->isEmailVerified() && $user->getStatus() !== AccountStatus::EMAIL_UNVERIFIED;
    }

    /**
     * Generate a secure verification token
     */
    private function generateVerificationToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Clean up expired verification tokens
     */
    public function cleanupExpiredTokens(): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->update(User::class, 'u')
           ->set('u.emailVerificationToken', ':null')
           ->set('u.emailVerificationTokenExpiresAt', ':null')
           ->where('u.emailVerificationTokenExpiresAt < :now')
           ->setParameter('null', null)
           ->setParameter('now', new \DateTime());

        $affectedRows = $qb->getQuery()->execute();

        $this->logger->info('Cleaned up expired email verification tokens', [
            'affected_rows' => $affectedRows
        ]);

        return $affectedRows;
    }
}