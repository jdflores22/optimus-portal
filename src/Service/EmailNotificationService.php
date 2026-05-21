<?php

namespace App\Service;

use App\Entity\PendingUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class EmailNotificationService
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAYS = [60, 300, 900]; // 1min, 5min, 15min in seconds

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private InAppNotificationService $notificationService,
        private string $fromAddress
    ) {
    }

    /**
     * Send role acceptance email to pending user
     */
    public function sendRoleAcceptanceEmail(PendingUser $pendingUser): void
    {
        $acceptanceUrl = $this->urlGenerator->generate(
            'role_acceptance_page',
            ['token' => $pendingUser->getAcceptanceToken()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $emailData = [
            'pendingUser' => $pendingUser,
            'acceptanceUrl' => $acceptanceUrl,
            'admin' => $pendingUser->getCreatedByAdmin(),
            'shippingLine' => $pendingUser->getShippingLine(),
            'expiresAt' => $pendingUser->getTokenExpiresAt()
        ];

        $this->sendEmailWithRetry(
            $pendingUser->getEmail(),
            'Role Invitation - OPTIMUS Portal',
            'emails/role_acceptance.html.twig',
            $emailData,
            'role_acceptance',
            $pendingUser->getId()
        );
    }

    /**
     * Send welcome email to newly created user
     */
    public function sendWelcomeEmail(User $user): void
    {
        $emailData = [
            'user' => $user,
            'loginUrl' => $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL)
        ];

        $this->sendEmailWithRetry(
            $user->getEmail(),
            'Welcome to OPTIMUS Portal',
            'emails/welcome.html.twig',
            $emailData,
            'welcome',
            $user->getId()
        );
    }

    /**
     * Send password reset OTP to user
     */
    public function sendPasswordResetOtp(User $user, string $otp): void
    {
        $emailData = [
            'user' => $user,
            'otp' => $otp,
            'expiryMinutes' => 15
        ];

        $this->sendEmailWithRetry(
            $user->getEmail(),
            'Password Reset OTP - OPTIMUS Portal',
            'emails/password_reset_otp.html.twig',
            $emailData,
            'password_reset_otp',
            $user->getId()
        );
    }

    /**
     * Send password change confirmation email to user
     */
    public function sendPasswordChangeConfirmation(User $user): void
    {
        $emailData = [
            'user' => $user,
            'changeTime' => new \DateTime(),
            'loginUrl' => $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'supportEmail' => $this->fromAddress
        ];

        $this->sendEmailWithRetry(
            $user->getEmail(),
            'Password Changed Successfully - OPTIMUS Portal',
            'emails/password_change_confirmation.html.twig',
            $emailData,
            'password_change_confirmation',
            $user->getId()
        );
    }

    /**
     * Send notification to admin when user declines role
     */
    public function sendRoleDeclinedNotification(User $admin, PendingUser $pendingUser): void
    {
        // Send in-app notification
        $this->notificationService->createNotification(
            $admin,
            'Role Invitation Declined',
            sprintf(
                '%s (%s) has declined the %s role invitation for %s.',
                $pendingUser->getFullName(),
                $pendingUser->getEmail(),
                $pendingUser->getRole()->value,
                $pendingUser->getShippingLine()?->getName() ?? 'the system'
            ),
            'warning'
        );

        // Send email notification
        $emailData = [
            'admin' => $admin,
            'pendingUser' => $pendingUser,
            'resendUrl' => $this->urlGenerator->generate(
                'admin_pending_users_resend',
                ['id' => $pendingUser->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        ];

        $this->sendEmailWithRetry(
            $admin->getEmail(),
            'Role Invitation Declined - OPTIMUS Portal',
            'emails/admin_role_declined.html.twig',
            $emailData,
            'admin_role_declined',
            $pendingUser->getId()
        );
    }

    /**
     * Send notification to admin when user accepts role
     */
    public function sendRoleAcceptedNotification(User $admin, User $newUser): void
    {
        // Send in-app notification
        $this->notificationService->createNotification(
            $admin,
            'Role Invitation Accepted',
            sprintf(
                '%s (%s) has accepted their %s role invitation and their account has been created.',
                $newUser->getEmail(),
                $newUser->getEmail(),
                $newUser->getRole()->value
            ),
            'success'
        );

        // Send email notification
        $emailData = [
            'admin' => $admin,
            'newUser' => $newUser,
            'userProfileUrl' => $this->urlGenerator->generate(
                'admin_user_detail',
                ['id' => $newUser->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        ];

        $this->sendEmailWithRetry(
            $admin->getEmail(),
            'Role Invitation Accepted - OPTIMUS Portal',
            'emails/admin_role_accepted.html.twig',
            $emailData,
            'admin_role_accepted',
            $newUser->getId()
        );
    }

    /**
     * Send notification to admin when token expires
     */
    public function sendTokenExpiredNotification(User $admin, PendingUser $pendingUser): void
    {
        // Send in-app notification
        $this->notificationService->createNotification(
            $admin,
            'Role Invitation Expired',
            sprintf(
                'The role invitation for %s (%s) has expired without response. You can resend the invitation if needed.',
                $pendingUser->getFullName(),
                $pendingUser->getEmail()
            ),
            'info'
        );

        // Send email notification
        $emailData = [
            'admin' => $admin,
            'pendingUser' => $pendingUser,
            'resendUrl' => $this->urlGenerator->generate(
                'admin_pending_users_resend',
                ['id' => $pendingUser->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        ];

        $this->sendEmailWithRetry(
            $admin->getEmail(),
            'Role Invitation Expired - OPTIMUS Portal',
            'emails/admin_token_expired.html.twig',
            $emailData,
            'admin_token_expired',
            $pendingUser->getId()
        );
    }

    /**
     * Send dwell time warning notification to shipping line admin
     */
    public function sendDwellTimeWarning(\App\Entity\Container $container, int $daysRemaining): void
    {
        // Get shipping line admin for this container
        $shippingLineAdmin = $this->getShippingLineAdminForContainer($container);
        
        if (!$shippingLineAdmin) {
            $this->logger->warning('No shipping line admin found for dwell time notification', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $emailData = [
            'container' => $container,
            'shippingLineAdmin' => $shippingLineAdmin,
            'daysRemaining' => $daysRemaining,
            'currentDwellTime' => $container->getCurrentDwellTime(),
            'containerUrl' => $this->urlGenerator->generate(
                'container_detail',
                ['id' => $container->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        ];

        $this->sendEmailWithRetry(
            $shippingLineAdmin->getEmail(),
            'Container Dwell Time Alert - OPTIMUS Portal',
            'emails/dwell_time_warning.html.twig',
            $emailData,
            'dwell_time_warning',
            $container->getId()
        );
    }

    /**
     * Send automatic return notification to shipping line admin
     */
    public function sendAutomaticReturnNotification(\App\Entity\Container $container): void
    {
        // Get shipping line admin for this container
        $shippingLineAdmin = $this->getShippingLineAdminForContainer($container);
        
        if (!$shippingLineAdmin) {
            $this->logger->warning('No shipping line admin found for automatic return notification', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $emailData = [
            'container' => $container,
            'shippingLineAdmin' => $shippingLineAdmin,
            'dwellTime' => $container->getCurrentDwellTime(),
            'containerUrl' => $this->urlGenerator->generate(
                'container_detail',
                ['id' => $container->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        ];

        $this->sendEmailWithRetry(
            $shippingLineAdmin->getEmail(),
            'Container Automatic Return - OPTIMUS Portal',
            'emails/automatic_return_notification.html.twig',
            $emailData,
            'automatic_return',
            $container->getId()
        );
    }

    /**
     * Get shipping line admin for a container
     */
    private function getShippingLineAdminForContainer(\App\Entity\Container $container): ?\App\Entity\User
    {
        // For now, get any shipping line admin
        // In a real implementation, this would be based on container ownership/assignment
        return $this->entityManager->getRepository(\App\Entity\User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', \App\Entity\Enum\UserRole::SHIPPING_LINES_ADMIN)
            ->setParameter('status', \App\Entity\Enum\AccountStatus::APPROVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Send email with retry logic and error handling
     */
    private function sendEmailWithRetry(
        string $toEmail,
        string $subject,
        string $template,
        array $templateData,
        string $emailType,
        ?int $entityId = null
    ): void {
        // Validate email address
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->error('Invalid email address provided', [
                'email' => $toEmail,
                'type' => $emailType,
                'entity_id' => $entityId
            ]);
            throw new \InvalidArgumentException('Invalid email address: ' . $toEmail);
        }

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            try {
                $attempt++;

                // Render email template
                $htmlContent = $this->twig->render($template, $templateData);

                // Create and send email
                $email = (new Email())
                    ->from($this->fromAddress)
                    ->to($toEmail)
                    ->subject($subject)
                    ->html($htmlContent);

                $this->mailer->send($email);

                // Log successful send
                $this->logger->info('Email sent successfully', [
                    'to' => $toEmail,
                    'subject' => $subject,
                    'type' => $emailType,
                    'entity_id' => $entityId,
                    'attempt' => $attempt
                ]);

                return; // Success, exit retry loop

            } catch (\Exception $e) {
                $lastException = $e;
                
                $this->logger->warning('Email sending failed', [
                    'to' => $toEmail,
                    'subject' => $subject,
                    'type' => $emailType,
                    'entity_id' => $entityId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'max_attempts' => self::MAX_RETRY_ATTEMPTS
                ]);

                // If this was the last attempt, don't wait
                if ($attempt >= self::MAX_RETRY_ATTEMPTS) {
                    break;
                }

                // Wait before retry (exponential backoff)
                $delay = self::RETRY_DELAYS[$attempt - 1] ?? 900;
                $this->logger->info('Retrying email send', [
                    'to' => $toEmail,
                    'type' => $emailType,
                    'next_attempt' => $attempt + 1,
                    'delay_seconds' => $delay
                ]);

                // In a real application, this would be handled by a queue system
                // For now, we'll just log the retry attempt
                sleep(1); // Brief delay to prevent immediate retry in tests
            }
        }

        // All attempts failed
        $this->handleEmailDeliveryFailure($toEmail, $subject, $emailType, $entityId, $lastException);
    }

    /**
     * Handle email delivery failure after all retries
     */
    private function handleEmailDeliveryFailure(
        string $toEmail,
        string $subject,
        string $emailType,
        ?int $entityId,
        ?\Exception $lastException
    ): void {
        $this->logger->error('Email delivery failed after all retries', [
            'to' => $toEmail,
            'subject' => $subject,
            'type' => $emailType,
            'entity_id' => $entityId,
            'max_attempts' => self::MAX_RETRY_ATTEMPTS,
            'final_error' => $lastException?->getMessage()
        ]);

        // Mark pending user as delivery failed if applicable
        if ($emailType === 'role_acceptance' && $entityId) {
            try {
                $pendingUser = $this->entityManager->getRepository(PendingUser::class)->find($entityId);
                if ($pendingUser && $pendingUser->getStatus() === 'pending') {
                    $pendingUser->setStatus('delivery_failed');
                    $this->entityManager->flush();

                    // Notify admin about delivery failure
                    $this->notificationService->createNotification(
                        $pendingUser->getCreatedByAdmin(),
                        'Email Delivery Failed',
                        sprintf(
                            'Failed to deliver role invitation email to %s (%s). Please verify the email address and try resending.',
                            $pendingUser->getFullName(),
                            $pendingUser->getEmail()
                        ),
                        'error'
                    );
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to mark pending user as delivery failed', [
                    'entity_id' => $entityId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Throw exception to notify calling code
        throw new \RuntimeException(
            sprintf('Failed to send %s email to %s after %d attempts', $emailType, $toEmail, self::MAX_RETRY_ATTEMPTS),
            0,
            $lastException
        );
    }

    /**
     * Get delivery failure statistics for monitoring
     */
    public function getDeliveryFailureStatistics(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        // Get delivery failed count
        $deliveryFailedCount = $qb->select('COUNT(p.id)')
            ->from(PendingUser::class, 'p')
            ->where('p.status = :status')
            ->setParameter('status', 'delivery_failed')
            ->getQuery()
            ->getSingleScalarResult();

        // Get total pending users count
        $totalCount = $qb->select('COUNT(p.id)')
            ->from(PendingUser::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        $failureRate = $totalCount > 0 ? round(($deliveryFailedCount / $totalCount) * 100, 2) : 0;

        return [
            'delivery_failed_count' => (int) $deliveryFailedCount,
            'total_count' => (int) $totalCount,
            'failure_rate_percentage' => $failureRate
        ];
    }

    /**
     * Retry failed email deliveries for specific pending users
     */
    public function retryFailedDeliveries(array $pendingUserIds = []): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('p')
            ->from(PendingUser::class, 'p')
            ->where('p.status = :status')
            ->setParameter('status', 'delivery_failed');

        if (!empty($pendingUserIds)) {
            $qb->andWhere('p.id IN (:ids)')
               ->setParameter('ids', $pendingUserIds);
        }

        $failedPendingUsers = $qb->getQuery()->getResult();

        foreach ($failedPendingUsers as $pendingUser) {
            try {
                // Reset status to pending before retry
                $pendingUser->setStatus('pending');
                $this->entityManager->flush();

                // Attempt to resend the email
                $this->sendRoleAcceptanceEmail($pendingUser);
                
                $results['success']++;
                
                $this->logger->info('Successfully retried failed email delivery', [
                    'pending_user_id' => $pendingUser->getId(),
                    'email' => $pendingUser->getEmail()
                ]);

            } catch (\Exception $e) {
                // Mark as delivery failed again
                $pendingUser->setStatus('delivery_failed');
                $this->entityManager->flush();
                
                $results['failed']++;
                $results['errors'][] = [
                    'pending_user_id' => $pendingUser->getId(),
                    'email' => $pendingUser->getEmail(),
                    'error' => $e->getMessage()
                ];

                $this->logger->error('Failed to retry email delivery', [
                    'pending_user_id' => $pendingUser->getId(),
                    'email' => $pendingUser->getEmail(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Send templated email with retry logic
     * 
     * @param string $toEmail
     * @param string $subject
     * @param string $template
     * @param array $templateData
     * @return void
     */
    public function sendTemplatedEmail(
        string $toEmail,
        string $subject,
        string $template,
        array $templateData
    ): void {
        $this->sendEmailWithRetry(
            $toEmail,
            $subject,
            $template,
            $templateData,
            'templated_notification',
            null
        );
    }

    /**
     * Send plain text email (legacy method)
     * 
     * @param string $toEmail
     * @param string $subject
     * @param string $message
     * @return void
     */
    public function sendEmail(string $toEmail, string $subject, string $message): void
    {
        // For plain text emails, we'll create a simple template inline
        $attempt = 0;
        $maxRetries = 3;
        
        while ($attempt < $maxRetries) {
            try {
                $attempt++;
                
                $email = (new Email())
                    ->from($this->fromAddress)
                    ->to($toEmail)
                    ->subject($subject)
                    ->text($message);

                $this->mailer->send($email);
                
                $this->logger->info('Plain text email sent successfully', [
                    'to' => $toEmail,
                    'subject' => $subject,
                    'attempt' => $attempt
                ]);
                
                return;
                
            } catch (\Exception $e) {
                $this->logger->warning('Plain text email sending failed', [
                    'to' => $toEmail,
                    'subject' => $subject,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt >= $maxRetries) {
                    $this->logger->error('Plain text email delivery failed after all retries', [
                        'to' => $toEmail,
                        'subject' => $subject,
                        'error' => $e->getMessage()
                    ]);
                    return; // Don't throw - notification failure shouldn't break workflow
                }
                
                sleep(1);
            }
        }
    }
}
