<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Enhanced notification service for dwell time management
 * Provides multi-channel delivery with retry logic for failed deliveries
 */
class DwellTimeNotificationService
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAYS = [60, 300, 900]; // 1min, 5min, 15min

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailNotificationService $emailService,
        private SmsServiceInterface $smsService,
        private InAppNotificationService $inAppService,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private TerminalTeamDwellTimeService $terminalTeamService,
        private NotificationMonitoringService $monitoringService
    ) {
    }

    /**
     * Send dwell time warning notification (60-day threshold)
     * Implements multi-channel delivery with retry logic
     */
    public function sendDwellTimeWarning(Container $container, int $daysRemaining): void
    {
        $shippingLineAdmin = $this->getShippingLineAdminForContainer($container);
        
        if (!$shippingLineAdmin) {
            $this->logger->warning('No shipping line admin found for dwell time warning', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $this->logger->info('Sending dwell time warning notification', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'days_remaining' => $daysRemaining,
            'admin_email' => $shippingLineAdmin->getEmail()
        ]);

        // Create in-app notification first (most reliable)
        $this->createInAppDwellTimeWarning($container, $shippingLineAdmin, $daysRemaining);

        // Notify terminal team about the dwell time warning
        $this->terminalTeamService->notifyTerminalTeamDwellTimeWarning($container, $daysRemaining);

        // Attempt multi-channel delivery with retry logic
        $this->deliverNotificationWithRetry(
            $shippingLineAdmin,
            'dwell_time_warning',
            [
                'container' => $container,
                'daysRemaining' => $daysRemaining,
                'currentDwellTime' => $container->getCurrentDwellTime()
            ]
        );
    }

    /**
     * Send automatic return notification (90-day threshold)
     */
    public function sendAutomaticReturnNotification(Container $container): void
    {
        $shippingLineAdmin = $this->getShippingLineAdminForContainer($container);
        
        if (!$shippingLineAdmin) {
            $this->logger->warning('No shipping line admin found for automatic return notification', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $this->logger->info('Sending automatic return notification', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'dwell_time' => $container->getCurrentDwellTime(),
            'admin_email' => $shippingLineAdmin->getEmail()
        ]);

        // Create in-app notification first
        $this->createInAppAutomaticReturnNotification($container, $shippingLineAdmin);

        // Notify terminal team about the automatic return
        $this->terminalTeamService->notifyTerminalTeamAutomaticReturn($container);

        // Attempt multi-channel delivery with retry logic
        $this->deliverNotificationWithRetry(
            $shippingLineAdmin,
            'automatic_return',
            [
                'container' => $container,
                'dwellTime' => $container->getCurrentDwellTime()
            ]
        );
    }

    /**
     * Send dwell time paused notification
     */
    public function sendDwellTimePausedNotification(Container $container, string $reason): void
    {
        $shippingLineAdmin = $this->getShippingLineAdminForContainer($container);
        
        if (!$shippingLineAdmin) {
            return;
        }

        $this->inAppService->createInfoNotification(
            $shippingLineAdmin,
            'Container Dwell Time Paused',
            sprintf(
                'Dwell time counting has been paused for container %s. Reason: %s',
                $container->getContainerNumber(),
                $reason
            ),
            $this->urlGenerator->generate('container_detail', ['id' => $container->getId()]),
            'View Container'
        );

        $this->logger->info('Dwell time paused notification sent', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'reason' => $reason
        ]);
    }

    /**
     * Send dwell time resumed notification
     */
    public function sendDwellTimeResumedNotification(Container $container): void
    {
        $shippingLineAdmin = $this->getShippingLineAdminForContainer($container);
        
        if (!$shippingLineAdmin) {
            return;
        }

        $this->inAppService->createInfoNotification(
            $shippingLineAdmin,
            'Container Dwell Time Resumed',
            sprintf(
                'Dwell time counting has resumed for container %s. Please monitor its status.',
                $container->getContainerNumber()
            ),
            $this->urlGenerator->generate('container_detail', ['id' => $container->getId()]),
            'View Container'
        );

        $this->logger->info('Dwell time resumed notification sent', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber()
        ]);
    }

    /**
     * Create in-app dwell time warning notification
     */
    private function createInAppDwellTimeWarning(Container $container, User $admin, int $daysRemaining): void
    {
        $this->inAppService->createWarningNotification(
            $admin,
            'Container Dwell Time Alert',
            sprintf(
                'Container %s has reached 60 days dwell time. %d days remaining before automatic return.',
                $container->getContainerNumber(),
                $daysRemaining
            ),
            $this->urlGenerator->generate('container_detail', ['id' => $container->getId()]),
            'View Container'
        );
    }

    /**
     * Create in-app automatic return notification
     */
    private function createInAppAutomaticReturnNotification(Container $container, User $admin): void
    {
        $this->inAppService->createErrorNotification(
            $admin,
            'Container Automatic Return',
            sprintf(
                'Container %s has been automatically returned to terminal after %d days dwell time.',
                $container->getContainerNumber(),
                $container->getCurrentDwellTime()
            ),
            $this->urlGenerator->generate('container_detail', ['id' => $container->getId()]),
            'View Container'
        );
    }

    /**
     * Deliver notification with multi-channel retry logic
     */
    private function deliverNotificationWithRetry(User $user, string $notificationType, array $data): void
    {
        $channels = $this->getAvailableChannels($user);
        $attempt = 0;
        $delivered = false;

        while ($attempt < self::MAX_RETRY_ATTEMPTS && !$delivered) {
            $attempt++;

            foreach ($channels as $channel) {
                try {
                    $success = $this->deliverViaChannel($channel, $user, $notificationType, $data);
                    
                    if ($success) {
                        $delivered = true;
                        $this->logger->info('Notification delivered successfully', [
                            'user_id' => $user->getId(),
                            'channel' => $channel,
                            'type' => $notificationType,
                            'attempt' => $attempt
                        ]);
                        break;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Notification delivery failed', [
                        'user_id' => $user->getId(),
                        'channel' => $channel,
                        'type' => $notificationType,
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // If not delivered and more attempts remain, wait before retry
            if (!$delivered && $attempt < self::MAX_RETRY_ATTEMPTS) {
                $delay = self::RETRY_DELAYS[$attempt - 1] ?? 900;
                $this->logger->info('Retrying notification delivery', [
                    'user_id' => $user->getId(),
                    'type' => $notificationType,
                    'next_attempt' => $attempt + 1,
                    'delay_seconds' => $delay
                ]);
                
                // In production, this would be handled by a queue system
                sleep(1); // Brief delay for testing
            }
        }

        if (!$delivered) {
            $this->handleDeliveryFailure($user, $notificationType, $data);
        }
    }

    /**
     * Get available notification channels for user
     */
    private function getAvailableChannels(User $user): array
    {
        $channels = ['email']; // Email is always available

        // Add SMS if SMS service is available (phone number would be in user profile when implemented)
        if ($this->smsService->isAvailable()) {
            // For now, SMS is available but will be skipped if no phone number
            $channels[] = 'sms';
        }

        return $channels;
    }

    /**
     * Deliver notification via specific channel
     */
    private function deliverViaChannel(string $channel, User $user, string $type, array $data): bool
    {
        switch ($channel) {
            case 'email':
                return $this->deliverViaEmail($user, $type, $data);
            case 'sms':
                return $this->deliverViaSms($user, $type, $data);
            default:
                return false;
        }
    }

    /**
     * Deliver notification via email
     */
    private function deliverViaEmail(User $user, string $type, array $data): bool
    {
        try {
            switch ($type) {
                case 'dwell_time_warning':
                    $this->emailService->sendDwellTimeWarning($data['container'], $data['daysRemaining']);
                    $success = true;
                    break;
                case 'automatic_return':
                    $this->emailService->sendAutomaticReturnNotification($data['container']);
                    $success = true;
                    break;
                default:
                    $success = false;
            }

            // Log the delivery attempt
            if (isset($data['container'])) {
                $this->monitoringService->logNotificationAttempt(
                    $data['container'],
                    $user,
                    $type,
                    'email',
                    $success
                );
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error('Email delivery failed', [
                'user_id' => $user->getId(),
                'type' => $type,
                'error' => $e->getMessage()
            ]);

            // Log the failed delivery attempt
            if (isset($data['container'])) {
                $this->monitoringService->logNotificationAttempt(
                    $data['container'],
                    $user,
                    $type,
                    'email',
                    false,
                    $e->getMessage()
                );
            }

            return false;
        }
    }

    /**
     * Deliver notification via SMS
     */
    private function deliverViaSms(User $user, string $type, array $data): bool
    {
        // For now, skip SMS delivery as User entity doesn't have phoneNumber field
        // This would be implemented when phone number support is added to User entity
        $this->logger->info('SMS delivery skipped - phone number not available', [
            'user_id' => $user->getId(),
            'type' => $type
        ]);

        // Log the skipped delivery attempt
        if (isset($data['container'])) {
            $this->monitoringService->logNotificationAttempt(
                $data['container'],
                $user,
                $type,
                'sms',
                false,
                'Phone number not available'
            );
        }

        return false;
    }

    /**
     * Generate SMS message content
     */
    private function generateSmsMessage(string $type, array $data): string
    {
        switch ($type) {
            case 'dwell_time_warning':
                return sprintf(
                    'OPTIMUS ALERT: Container %s reached 60 days dwell time. %d days until automatic return. Check portal for details.',
                    $data['container']->getContainerNumber(),
                    $data['daysRemaining']
                );
            case 'automatic_return':
                return sprintf(
                    'OPTIMUS NOTICE: Container %s automatically returned after %d days dwell time. Check portal for details.',
                    $data['container']->getContainerNumber(),
                    $data['dwellTime']
                );
            default:
                return 'OPTIMUS notification - check portal for details.';
        }
    }

    /**
     * Handle delivery failure after all retries
     */
    private function handleDeliveryFailure(User $user, string $type, array $data): void
    {
        $this->logger->error('Notification delivery failed after all retries', [
            'user_id' => $user->getId(),
            'type' => $type,
            'max_attempts' => self::MAX_RETRY_ATTEMPTS
        ]);

        // Create a high-priority in-app notification about the delivery failure
        $this->inAppService->createErrorNotification(
            $user,
            'Notification Delivery Failed',
            'We were unable to deliver important notifications to your email/SMS. Please check your contact information.',
            null,
            null
        );

        // TODO: In production, this could trigger admin alerts or alternative notification methods
    }

    /**
     * Get shipping line admin for a container
     */
    private function getShippingLineAdminForContainer(Container $container): ?User
    {
        // For now, get any shipping line admin
        // In a real implementation, this would be based on container ownership/assignment
        return $this->entityManager->getRepository(User::class)
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
     * Get notification delivery statistics for monitoring
     */
    public function getDeliveryStatistics(): array
    {
        // This would typically query a notification_logs table
        // For now, return basic statistics
        return [
            'total_notifications_sent' => 0,
            'successful_deliveries' => 0,
            'failed_deliveries' => 0,
            'retry_attempts' => 0,
            'channels_used' => ['email', 'sms', 'in_app']
        ];
    }
}