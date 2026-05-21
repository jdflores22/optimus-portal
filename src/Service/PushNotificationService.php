<?php

namespace App\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushNotificationService
{
    private const MAX_SUBSCRIPTIONS_PER_USER = 5;
    private const MAX_RETRY_ATTEMPTS = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $vapidPublicKey,
        private string $vapidPrivateKey,
        private string $vapidSubject
    ) {}

    /**
     * Register a new push subscription for a user
     * If user has 5 active subscriptions, removes the oldest one
     */
    public function registerSubscription(User $user, array $subscriptionData): PushSubscription
    {
        $repository = $this->entityManager->getRepository(PushSubscription::class);
        
        // Check if this endpoint already exists for this user
        $existingSubscription = $repository->findOneBy([
            'user' => $user,
            'endpoint' => $subscriptionData['endpoint']
        ]);
        
        if ($existingSubscription) {
            // Update existing subscription instead of creating duplicate
            $existingSubscription->setP256dhKey($subscriptionData['keys']['p256dh']);
            $existingSubscription->setAuthKey($subscriptionData['keys']['auth']);
            $existingSubscription->setIsActive(true);
            $existingSubscription->setLastUsedAt(new \DateTime());
            
            if (isset($subscriptionData['userAgent'])) {
                $existingSubscription->setUserAgent($subscriptionData['userAgent']);
            }
            
            $this->entityManager->flush();
            
            $this->logger->info('Push subscription updated', [
                'user_id' => $user->getId(),
                'subscription_id' => $existingSubscription->getId()
            ]);
            
            return $existingSubscription;
        }
        
        // Check subscription limit
        $existingCount = $repository->countActiveByUser($user);
        
        if ($existingCount >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            // Remove oldest subscription
            $oldest = $repository->findOldestForUser($user);
            if ($oldest) {
                $this->removeSubscription($oldest->getId());
            }
        }
        
        // Create new subscription
        $subscription = new PushSubscription();
        $subscription->setUser($user);
        $subscription->setEndpoint($subscriptionData['endpoint']);
        $subscription->setP256dhKey($subscriptionData['keys']['p256dh']);
        $subscription->setAuthKey($subscriptionData['keys']['auth']);
        $subscription->setIsActive(true);
        $subscription->setCreatedAt(new \DateTime());
        
        // Set user agent if provided
        if (isset($subscriptionData['userAgent'])) {
            $subscription->setUserAgent($subscriptionData['userAgent']);
        }
        
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
        
        $this->logger->info('Push subscription registered', [
            'user_id' => $user->getId(),
            'subscription_id' => $subscription->getId()
        ]);
        
        return $subscription;
    }

    /**
     * Send push notification to all active subscriptions for a user
     */
    public function sendPushNotification(
        User $user,
        string $title,
        string $message,
        string $type,
        array $metadata = []
    ): void {
        // Check notification preferences
        $preferences = $this->entityManager
            ->getRepository(\App\Entity\NotificationPreferences::class)
            ->findOneBy(['user' => $user]);
        
        // If preferences exist, check if this notification type is enabled
        if ($preferences && !$preferences->isTypeEnabled($type)) {
            $this->logger->debug('Notification type disabled by user preferences', [
                'user_id' => $user->getId(),
                'type' => $type
            ]);
            return;
        }
        
        // Check Do Not Disturb mode
        if ($preferences && $preferences->isInDoNotDisturbPeriod()) {
            $this->logger->info('Notification queued due to Do Not Disturb mode', [
                'user_id' => $user->getId(),
                'type' => $type
            ]);
            $this->queueNotification($user, $title, $message, $type, $metadata);
            return;
        }
        
        $subscriptions = $this->entityManager
            ->getRepository(PushSubscription::class)
            ->findActiveByUser($user);
        
        if (empty($subscriptions)) {
            $this->logger->debug('No active push subscriptions for user', [
                'user_id' => $user->getId()
            ]);
            return;
        }
        
        foreach ($subscriptions as $subscription) {
            $this->sendToSubscription($subscription, $title, $message, $type, $metadata);
        }
    }

    /**
     * Send push notification to a specific subscription with retry logic
     */
    private function sendToSubscription(
        PushSubscription $subscription,
        string $title,
        string $message,
        string $type,
        array $metadata,
        int $attempt = 1
    ): void {
        // Create metrics record for tracking
        $metrics = $this->createMetricsRecord($subscription->getUser(), $type, $metadata);
        
        try {
            // Validate subscription keys before attempting to send
            if (empty($subscription->getP256dhKey()) || empty($subscription->getAuthKey())) {
                throw new \InvalidArgumentException('Invalid subscription keys: p256dh or auth key is empty');
            }
            
            // Try to decode keys - support both base64url (new) and standard base64 (legacy)
            $p256dhKey = $this->decodeSubscriptionKey($subscription->getP256dhKey());
            $authKey = $this->decodeSubscriptionKey($subscription->getAuthKey());
            
            // Validate decoded keys - must be non-empty strings
            if ($p256dhKey === false || $authKey === false || empty($p256dhKey) || empty($authKey)) {
                throw new \InvalidArgumentException(
                    'Invalid subscription keys: failed to decode or empty result. ' .
                    'p256dh length: ' . strlen($subscription->getP256dhKey()) . ', ' .
                    'auth length: ' . strlen($subscription->getAuthKey())
                );
            }
            
            // Re-encode as standard base64 for web-push library
            $p256dhBase64 = base64_encode($p256dhKey);
            $authBase64 = base64_encode($authKey);
            
            // Validate base64 encoding succeeded
            if (empty($p256dhBase64) || empty($authBase64)) {
                throw new \InvalidArgumentException('Failed to encode keys to base64');
            }
            
            $payload = json_encode([
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'icon' => '/images/notification-icon.png',
                'url' => $this->buildNotificationUrl($type, $metadata),
                'id' => $metadata['notification_id'] ?? null,
                'metrics_id' => $metrics->getId()
            ]);
            
            // Configure VAPID authentication
            $auth = [
                'VAPID' => [
                    'subject' => $this->vapidSubject,
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey
                ]
            ];
            
            $webPush = new WebPush($auth);
            // Disable automatic padding to prevent issues with web-push library v8.0+
            $webPush->setAutomaticPadding(false);
            
            // Create subscription object for web-push library
            $pushSubscription = Subscription::create([
                'endpoint' => $subscription->getEndpoint(),
                'keys' => [
                    'p256dh' => $p256dhBase64,
                    'auth' => $authBase64
                ]
            ]);
            
            // Send notification
            $report = $webPush->sendOneNotification($pushSubscription, $payload);
            
            if ($report->isSuccess()) {
                // Update last used timestamp
                $subscription->setLastUsedAt(new \DateTime());
                
                // Mark metrics as delivered
                $metrics->markAsDelivered();
                $this->entityManager->flush();
                
                $this->logger->info('Push notification sent successfully', [
                    'subscription_id' => $subscription->getId(),
                    'type' => $type,
                    'metrics_id' => $metrics->getId()
                ]);
            } else {
                $this->handleDeliveryFailure($subscription, $report, $attempt, $title, $message, $type, $metadata, $metrics);
            }
            
        } catch (\InvalidArgumentException $e) {
            // Invalid subscription keys - deactivate subscription
            $subscription->setIsActive(false);
            $metrics->markAsFailed($e->getMessage());
            $this->entityManager->flush();
            
            $this->logger->warning('Push subscription deactivated due to invalid keys', [
                'subscription_id' => $subscription->getId(),
                'error' => $e->getMessage(),
                'metrics_id' => $metrics->getId()
            ]);
        } catch (\Exception $e) {
            // Mark metrics as failed
            $metrics->markAsFailed($e->getMessage());
            $this->entityManager->flush();
            
            $this->logger->error('Push notification send failed', [
                'subscription_id' => $subscription->getId(),
                'error' => $e->getMessage(),
                'attempt' => $attempt,
                'metrics_id' => $metrics->getId()
            ]);
            
            if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                $this->retryWithBackoff($subscription, $title, $message, $type, $metadata, $attempt);
            }
        }
    }

    /**
     * Handle delivery failure based on error code
     */
    private function handleDeliveryFailure(
        PushSubscription $subscription,
        $report,
        int $attempt,
        string $title,
        string $message,
        string $type,
        array $metadata,
        ?\App\Entity\NotificationMetrics $metrics = null
    ): void {
        $response = $report->getResponse();
        $statusCode = $response ? $response->getStatusCode() : null;
        $errorMessage = $response ? $response->getReasonPhrase() : 'Unknown error';
        
        // Mark metrics as failed if provided
        if ($metrics) {
            $metrics->markAsFailed("HTTP {$statusCode}: {$errorMessage}");
            $this->entityManager->flush();
        }
        
        $this->logger->warning('Push notification delivery failed', [
            'subscription_id' => $subscription->getId(),
            'status_code' => $statusCode,
            'attempt' => $attempt,
            'metrics_id' => $metrics?->getId()
        ]);
        
        // 410 Gone or 404 Not Found = subscription is invalid
        if (in_array($statusCode, [410, 404])) {
            $subscription->setIsActive(false);
            $this->entityManager->flush();
            
            $this->logger->info('Deactivated invalid push subscription', [
                'subscription_id' => $subscription->getId(),
                'status_code' => $statusCode
            ]);
            return;
        }
        
        // Retry for other errors (5xx, network issues, etc.)
        if ($attempt < self::MAX_RETRY_ATTEMPTS) {
            $this->retryWithBackoff($subscription, $title, $message, $type, $metadata, $attempt);
        }
    }

    /**
     * Retry notification delivery with exponential backoff
     */
    private function retryWithBackoff(
        PushSubscription $subscription,
        string $title,
        string $message,
        string $type,
        array $metadata,
        int $attempt
    ): void {
        // Exponential backoff: 2s, 4s, 8s
        $delay = pow(2, $attempt);
        
        $this->logger->info('Scheduling push notification retry', [
            'subscription_id' => $subscription->getId(),
            'attempt' => $attempt + 1,
            'delay_seconds' => $delay
        ]);
        
        // Sleep for backoff delay
        sleep($delay);
        
        // Retry sending
        $this->sendToSubscription($subscription, $title, $message, $type, $metadata, $attempt + 1);
    }

    /**
     * Build deep link URL based on notification type
     */
    private function buildNotificationUrl(string $type, array $metadata): string
    {
        return match($type) {
            'manifest_payment_required',
            'manifest_consignee_declared',
            'manifest_access_granted' => "/manifests/{$metadata['manifest_id']}",
            'noa_generated' => "/manifests/{$metadata['manifest_id']}/noa",
            'billing_generated' => "/manifests/{$metadata['manifest_id']}/billing",
            'payment_submitted',
            'payment_approved',
            'payment_rejected' => "/manifests/{$metadata['manifest_id']}/payments",
            'bl_uploaded' => "/manifests/{$metadata['manifest_id']}/documents",
            'edo_generated' => "/manifests/{$metadata['manifest_id']}/edo",
            default => "/notifications"
        };
    }

    /**
     * Check if user has any active push subscriptions
     */
    public function hasActiveSubscriptions(User $user): bool
    {
        $count = $this->entityManager
            ->getRepository(PushSubscription::class)
            ->countActiveByUser($user);
        
        return $count > 0;
    }

    /**
     * Remove a push subscription
     */
    public function removeSubscription(int $subscriptionId): void
    {
        $subscription = $this->entityManager
            ->getRepository(PushSubscription::class)
            ->find($subscriptionId);
        
        if ($subscription) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
            
            $this->logger->info('Push subscription removed', [
                'subscription_id' => $subscriptionId
            ]);
        }
    }

    /**
     * Clean up inactive push subscriptions
     * Returns the number of subscriptions removed
     */
    public function cleanupInvalidSubscriptions(): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->delete(PushSubscription::class, 'ps')
            ->where('ps.isActive = false');
        
        $count = $qb->getQuery()->execute();
        
        $this->logger->info('Cleaned up invalid push subscriptions', [
            'count' => $count
        ]);
        
        return $count;
    }

    /**
     * Get a push subscription by ID
     */
    public function getSubscriptionById(int $id): ?PushSubscription
    {
        return $this->entityManager
            ->getRepository(PushSubscription::class)
            ->find($id);
    }

    /**
     * Get all active subscriptions for a user
     */
    public function getActiveSubscriptionsByUser(User $user): array
    {
        return $this->entityManager
            ->getRepository(PushSubscription::class)
            ->findActiveByUser($user);
    }

    /**
     * Get the VAPID public key for client-side subscription
     */
    public function getVapidPublicKey(): string
    {
        return $this->vapidPublicKey;
    }

    /**
     * Queue a notification for later delivery (during Do Not Disturb)
     */
    private function queueNotification(
        User $user,
        string $title,
        string $message,
        string $type,
        array $metadata
    ): void {
        // Create a queued notification entity
        $queuedNotification = new \App\Entity\QueuedNotification();
        $queuedNotification->setUser($user);
        $queuedNotification->setTitle($title);
        $queuedNotification->setMessage($message);
        $queuedNotification->setType($type);
        $queuedNotification->setMetadata($metadata);
        $queuedNotification->setQueuedAt(new \DateTime());
        
        $this->entityManager->persist($queuedNotification);
        $this->entityManager->flush();
    }

    /**
     * Deliver all queued notifications for a user
     * Called when Do Not Disturb mode is disabled
     */
    public function deliverQueuedNotifications(User $user): int
    {
        $queuedNotifications = $this->entityManager
            ->getRepository(\App\Entity\QueuedNotification::class)
            ->findBy(['user' => $user], ['queuedAt' => 'ASC']);
        
        $count = 0;
        foreach ($queuedNotifications as $queued) {
            // Check if notification type is still enabled
            $preferences = $this->entityManager
                ->getRepository(\App\Entity\NotificationPreferences::class)
                ->findOneBy(['user' => $user]);
            
            if ($preferences && !$preferences->isTypeEnabled($queued->getType())) {
                // Skip disabled notification types
                $this->entityManager->remove($queued);
                continue;
            }
            
            // Send the notification
            $subscriptions = $this->entityManager
                ->getRepository(PushSubscription::class)
                ->findActiveByUser($user);
            
            foreach ($subscriptions as $subscription) {
                $this->sendToSubscription(
                    $subscription,
                    $queued->getTitle(),
                    $queued->getMessage(),
                    $queued->getType(),
                    $queued->getMetadata()
                );
            }
            
            // Remove from queue
            $this->entityManager->remove($queued);
            $count++;
        }
        
        $this->entityManager->flush();
        
        $this->logger->info('Delivered queued notifications', [
            'user_id' => $user->getId(),
            'count' => $count
        ]);
        
        return $count;
    }

    /**
     * Create a metrics record for tracking notification delivery
     */
    private function createMetricsRecord(User $user, string $type, array $metadata): \App\Entity\NotificationMetrics
    {
        $metrics = new \App\Entity\NotificationMetrics();
        $metrics->setUser($user);
        $metrics->setNotificationType($type);
        $metrics->setSentAt(new \DateTime());
        
        // Link to notification entity if available
        if (isset($metadata['notification_id'])) {
            $notification = $this->entityManager
                ->getRepository(\App\Entity\Notification::class)
                ->find($metadata['notification_id']);
            if ($notification) {
                $metrics->setNotification($notification);
            }
        }
        
        $this->entityManager->persist($metrics);
        $this->entityManager->flush();
        
        return $metrics;
    }

    /**
     * Mark a notification as opened (called when user taps notification)
     */
    public function markNotificationAsOpened(int $metricsId): void
    {
        $metrics = $this->entityManager
            ->getRepository(\App\Entity\NotificationMetrics::class)
            ->find($metricsId);
        
        if ($metrics && !$metrics->getOpenedAt()) {
            $metrics->markAsOpened();
            $this->entityManager->flush();
            
            $this->logger->info('Notification marked as opened', [
                'metrics_id' => $metricsId,
                'user_id' => $metrics->getUser()->getId()
            ]);
        }
    }
    
    /**
     * Decode subscription key - supports both base64url (new) and standard base64 (legacy)
     * @param string $key Encoded key string
     * @return string|false Decoded binary string or false on failure
     */
    private function decodeSubscriptionKey(string $key): string|false
    {
        // Detect format by checking for base64url-specific characters or standard base64 characters
        // Base64url uses: - and _ (no + or / or =)
        // Standard base64 uses: + and / and = for padding
        
        $hasStandardBase64Chars = (strpos($key, '+') !== false || strpos($key, '/') !== false || strpos($key, '=') !== false);
        
        if ($hasStandardBase64Chars) {
            // Standard base64 format (legacy)
            return base64_decode($key, true);
        } else {
            // Base64url format (new)
            return $this->base64UrlDecode($key);
        }
    }
    
    /**
     * Decode base64url string to binary
     * Base64url is URL-safe base64 without padding
     * @param string $input Base64url encoded string
     * @return string|false Decoded binary string or false on failure
     */
    private function base64UrlDecode(string $input): string|false
    {
        // Convert base64url to standard base64
        $base64 = strtr($input, '-_', '+/');
        
        // Add padding if needed
        $remainder = strlen($base64) % 4;
        if ($remainder) {
            $base64 .= str_repeat('=', 4 - $remainder);
        }
        
        return base64_decode($base64, true);
    }
}






