<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class InAppNotificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Create a notification for a user
     */
    public function createNotification(
        User $user,
        string $title,
        string $message,
        string $type = 'info',
        ?array $metadata = null
    ): Notification {
        // Check for duplicate notifications within the last 5 minutes
        $fiveMinutesAgo = new \DateTime('-5 minutes');
        
        $existingNotification = $this->entityManager
            ->getRepository(Notification::class)
            ->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.title = :title')
            ->andWhere('n.message = :message')
            ->andWhere('n.createdAt > :fiveMinutesAgo')
            ->setParameter('user', $user)
            ->setParameter('title', $title)
            ->setParameter('message', $message)
            ->setParameter('fiveMinutesAgo', $fiveMinutesAgo)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        // If duplicate found, return existing notification instead of creating new one
        if ($existingNotification) {
            return $existingNotification;
        }
        
        // Map workflow event types to notification types
        $notificationType = $this->mapEventTypeToNotificationType($type);
        
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setType($notificationType);
        
        // Generate action URL and text from metadata if provided
        if ($metadata) {
            $actionData = $this->generateActionFromMetadata($type, $metadata, $user);
            if ($actionData['url']) {
                $notification->setActionUrl($actionData['url']);
            }
            if ($actionData['text']) {
                $notification->setActionText($actionData['text']);
            }
        }

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    /**
     * Map workflow event types to notification display types
     */
    private function mapEventTypeToNotificationType(string $eventType): string
    {
        return match($eventType) {
            'manifest_payment_required', 'billing_generated' => 'warning',
            'manifest_access_granted', 'noa_generated', 'edo_generated', 'payment_approved', 'bl_uploaded', 'manifest_broker_assigned' => 'success',
            'payment_rejected' => 'error',
            'accreditation_status' => 'info',
            'broker_linked' => 'success',
            'consignee_linked' => 'success',
            'success' => 'success',
            'error' => 'error',
            'warning' => 'warning',
            default => 'info'
        };
    }

    /**
     * Generate action URL and text from metadata
     */
    private function generateActionFromMetadata(string $eventType, array $metadata, User $user): array
    {
        $url = null;
        $text = null;

        if (isset($metadata['manifest_id'])) {
            $manifestId = $metadata['manifest_id'];
            $userRole = $user->getRole()->value;
            
            switch ($eventType) {
                case 'manifest_consignee_declared':
                    // Generate role-specific URL
                    if ($userRole === 'BROKER') {
                        $url = "/broker/manifests/{$manifestId}";
                    } elseif ($userRole === 'CONSIGNEE') {
                        $url = "/consignee/manifests/{$manifestId}";
                    } else {
                        // SL_STAFF, SYSTEM_ADMIN, and other roles use manifest-workflow
                        $url = "/manifest-workflow/{$manifestId}";
                    }
                    $text = 'View Manifest';
                    break;
                case 'manifest_broker_assigned':
                    // Broker receives notification when assigned to manifest
                    if ($userRole === 'BROKER') {
                        $url = "/broker/manifests/{$manifestId}";
                        $text = 'View Manifest';
                    }
                    break;
                case 'manifest_payment_required':
                    if ($userRole === 'BROKER') {
                        $url = "/broker/manifests/{$manifestId}/payment";
                    } elseif ($userRole === 'CONSIGNEE') {
                        $url = "/consignee/manifests/{$manifestId}/payment";
                    } else {
                        $url = "/manifest-workflow/{$manifestId}";
                    }
                    $text = 'Submit Payment';
                    break;
                case 'payment_submitted':
                    // For ACCOUNTING, link to final payment validation page
                    if ($userRole === 'ACCOUNTING' && isset($metadata['payment_id'])) {
                        $paymentId = $metadata['payment_id'];
                        $paymentType = $metadata['payment_type'] ?? null;
                        
                        if ($paymentType === 'final_payment') {
                            $url = "/accounting/payments/final/{$paymentId}";
                            $text = 'Review Payment';
                        }
                    }
                    // For SYSTEM_ADMIN, link to eDO payment validation
                    elseif ($userRole === 'SYSTEM_ADMIN' && isset($metadata['payment_id'])) {
                        $paymentType = $metadata['payment_type'] ?? null;
                        if ($paymentType === 'edo_access' || $paymentType === 'edo_payment') {
                            $url = '/admin/edo-payments';
                            $text = 'Review Payment';
                        }
                    }
                    break;
                case 'manifest_access_granted':
                case 'noa_generated':
                case 'edo_generated':
                    if ($userRole === 'BROKER') {
                        $url = "/broker/manifests/{$manifestId}";
                    } elseif ($userRole === 'CONSIGNEE') {
                        $url = "/consignee/manifests/{$manifestId}";
                    } else {
                        // SL_STAFF, SYSTEM_ADMIN, and other roles use manifest-workflow
                        $url = "/manifest-workflow/{$manifestId}";
                    }
                    $text = 'View Manifest';
                    break;
                case 'bl_uploaded':
                    // For ACCOUNTING, go directly to billing generation page
                    if ($userRole === 'ACCOUNTING') {
                        $url = "/manifest-workflow/{$manifestId}/generate-billing";
                        $text = 'Generate Billing';
                    } elseif ($userRole === 'BROKER') {
                        $url = "/broker/manifests/{$manifestId}";
                        $text = 'View Manifest';
                    } elseif ($userRole === 'CONSIGNEE') {
                        $url = "/consignee/manifests/{$manifestId}";
                        $text = 'View Manifest';
                    } else {
                        // SL_STAFF, SYSTEM_ADMIN, and other roles use manifest-workflow
                        $url = "/manifest-workflow/{$manifestId}";
                        $text = 'View Manifest';
                    }
                    break;
                case 'billing_generated':
                    if ($userRole === 'BROKER') {
                        $url = "/broker/manifests/{$manifestId}";
                    } elseif ($userRole === 'CONSIGNEE') {
                        $url = "/consignee/manifests/{$manifestId}";
                    } else {
                        // SL_STAFF, SYSTEM_ADMIN, and other roles use manifest-workflow
                        $url = "/manifest-workflow/{$manifestId}";
                    }
                    $text = 'View Manifest';
                    break;
                case 'payment_rejected':
                    if ($userRole === 'BROKER') {
                        // Check payment type to determine correct URL
                        $paymentType = $metadata['payment_type'] ?? null;
                        if ($paymentType === 'final_payment') {
                            $url = "/broker/manifests/{$manifestId}/final-payment";
                        } else {
                            // manifest_access payment
                            $url = "/broker/manifests/{$manifestId}/payment";
                        }
                    } elseif ($userRole === 'CONSIGNEE') {
                        // Check payment type for consignee as well
                        $paymentType = $metadata['payment_type'] ?? null;
                        if ($paymentType === 'final_payment') {
                            $url = "/consignee/manifests/{$manifestId}/final-payment";
                        } else {
                            $url = "/consignee/manifests/{$manifestId}/payment";
                        }
                    } else {
                        $url = "/manifest-workflow/{$manifestId}";
                    }
                    $text = 'Resubmit Payment';
                    break;
                case 'payment_approved':
                    if ($userRole === 'BROKER') {
                        $url = "/broker/manifests/{$manifestId}";
                    } elseif ($userRole === 'CONSIGNEE') {
                        $url = "/consignee/manifests/{$manifestId}";
                    } else {
                        // SL_STAFF, SYSTEM_ADMIN, and other roles use manifest-workflow
                        $url = "/manifest-workflow/{$manifestId}";
                    }
                    $text = 'View Manifest';
                    break;
            }
        } elseif ($eventType === 'accreditation_status') {
            $url = '/accreditation';
            $text = 'View Accreditation';
        } elseif ($eventType === 'broker_linked' || $eventType === 'consignee_linked') {
            $url = $user->getRole()->value === 'BROKER' ? '/broker/manifests' : '/consignee/manifests';
            $text = 'View Manifests';
        }

        return ['url' => $url, 'text' => $text];
    }

    /**
     * Create a success notification
     */
    public function createSuccessNotification(
        User $user,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null
    ): Notification {
        return $this->createNotification($user, $title, $message, 'success', $actionUrl, $actionText);
    }

    /**
     * Create an error notification
     */
    public function createErrorNotification(
        User $user,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null
    ): Notification {
        return $this->createNotification($user, $title, $message, 'error', $actionUrl, $actionText);
    }

    /**
     * Create a warning notification
     */
    public function createWarningNotification(
        User $user,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null
    ): Notification {
        return $this->createNotification($user, $title, $message, 'warning', $actionUrl, $actionText);
    }

    /**
     * Create an info notification
     */
    public function createInfoNotification(
        User $user,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null
    ): Notification {
        return $this->createNotification($user, $title, $message, 'info', $actionUrl, $actionText);
    }

    /**
     * Create notifications for multiple users
     */
    public function createBulkNotifications(
        array $users,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        ?string $actionText = null
    ): array {
        $notifications = [];
        
        foreach ($users as $user) {
            $notifications[] = $this->createNotification($user, $title, $message, $type, $actionUrl, $actionText);
        }

        return $notifications;
    }

    /**
     * Sync read status from PWA to database
     * This ensures read status changes are reflected across all user devices
     */
    public function syncReadStatus(User $user, int $notificationId, bool $isRead): bool
    {
        $notification = $this->entityManager
            ->getRepository(Notification::class)
            ->findOneBy(['id' => $notificationId, 'user' => $user]);

        if (!$notification) {
            return false;
        }

        $notification->setIsRead($isRead);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Sync multiple notification read statuses at once
     * Used for bulk synchronization from PWA
     */
    public function syncBulkReadStatus(User $user, array $notificationStatuses): int
    {
        $syncedCount = 0;

        foreach ($notificationStatuses as $status) {
            if (!isset($status['id']) || !isset($status['isRead'])) {
                continue;
            }

            if ($this->syncReadStatus($user, $status['id'], $status['isRead'])) {
                $syncedCount++;
            }
        }

        return $syncedCount;
    }
}