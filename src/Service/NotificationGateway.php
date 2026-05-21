<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Central notification gateway that routes notifications to multiple channels
 * Handles push notifications, in-app notifications, and email notifications
 */
class NotificationGateway
{
    public function __construct(
        private PushNotificationService $pushNotificationService,
        private InAppNotificationService $inAppNotificationService,
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger
    ) {}

    /**
     * Send notification to all available channels for each recipient
     * Gracefully handles failures in individual channels without failing the entire operation
     */
    public function sendNotification(
        array $recipients,
        string $subject,
        string $message,
        string $type,
        array $metadata = []
    ): void {
        foreach ($recipients as $recipient) {
            $channels = $this->getAvailableChannels($recipient);
            
            $this->logger->info('Sending notification via gateway', [
                'recipient_id' => $recipient->getId(),
                'type' => $type,
                'channels' => $channels
            ]);
            
            // Send to push notification channel
            if (in_array('push', $channels)) {
                try {
                    $this->pushNotificationService->sendPushNotification(
                        $recipient,
                        $subject,
                        $message,
                        $type,
                        $metadata
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Failed to send push notification', [
                        'recipient_id' => $recipient->getId(),
                        'type' => $type,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Send to in-app notification channel
            if (in_array('in_app', $channels)) {
                try {
                    $this->inAppNotificationService->createNotification(
                        $recipient,
                        $subject,
                        $message,
                        $type,
                        $metadata
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Failed to create in-app notification', [
                        'recipient_id' => $recipient->getId(),
                        'type' => $type,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Send to email channel
            if (in_array('email', $channels)) {
                try {
                    $this->sendEmail($recipient, $subject, $message, $type, $metadata);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to send email notification', [
                        'recipient_id' => $recipient->getId(),
                        'type' => $type,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Determine which notification channels are available for a user
     * Returns array of channel names: 'push', 'in_app', 'email'
     */
    public function getAvailableChannels(User $user): array
    {
        $channels = [];
        
        // In-app notifications are always available
        $channels[] = 'in_app';
        
        // Email is always available if user has an email address
        if ($user->getEmail()) {
            $channels[] = 'email';
        }
        
        // Push notifications are available if user has active subscriptions
        if ($this->pushNotificationService->hasActiveSubscriptions($user)) {
            $channels[] = 'push';
        }
        
        return $channels;
    }

    /**
     * Send email notification using existing email templates
     * Private helper method that handles email template rendering and sending
     */
    private function sendEmail(
        User $recipient,
        string $subject,
        string $message,
        string $type,
        array $metadata
    ): void {
        // Map notification type to email template
        $template = $this->getEmailTemplate($type);
        
        if (!$template) {
            $this->logger->warning('No email template found for notification type', [
                'type' => $type
            ]);
            return;
        }
        
        // Prepare template variables
        $templateVars = $this->prepareTemplateVariables($recipient, $type, $metadata);
        
        // Render email template
        $htmlBody = $this->twig->render($template, $templateVars);
        
        // Create and send email
        $email = (new Email())
            ->from('noreply@optimus-shipping.com')
            ->to($recipient->getEmail())
            ->subject($subject)
            ->html($htmlBody);
        
        // Attach official receipt for approved eDO payments
        if ($type === 'edo_payment_approved' && isset($metadata['official_receipt_path'])) {
            $receiptPath = $metadata['official_receipt_path'];
            if (file_exists($receiptPath)) {
                $email->attachFromPath($receiptPath, 'Official_Receipt.pdf', 'application/pdf');
            }
        }
        
        // Send email asynchronously to avoid blocking the request
        // The email will be queued and sent by a background worker
        try {
            $this->mailer->send($email);
            
            $this->logger->info('Email notification queued', [
                'recipient_id' => $recipient->getId(),
                'type' => $type,
                'template' => $template
            ]);
        } catch (\Exception $e) {
            // Log but don't fail - email sending shouldn't block the main operation
            $this->logger->error('Failed to queue email notification', [
                'recipient_id' => $recipient->getId(),
                'type' => $type,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Map notification type to email template path
     */
    private function getEmailTemplate(string $type): ?string
    {
        return match($type) {
            'manifest_payment_required' => 'emails/manifest_payment_required.html.twig',
            'manifest_consignee_declared' => 'emails/manifest_consignee_declared.html.twig',
            'manifest_access_granted' => 'emails/manifest_access_granted.html.twig',
            'noa_generated' => 'emails/noa_generated.html.twig',
            'billing_generated' => 'emails/billing_generated.html.twig',
            'payment_rejected' => 'emails/payment_rejected.html.twig',
            'payment_submitted' => 'emails/payment_submitted.html.twig',
            'payment_approved' => 'emails/payment_approved.html.twig',
            'payment_validated' => 'emails/payment_approved.html.twig', // Use same template as approved
            'bl_uploaded' => 'emails/bl_uploaded.html.twig',
            'edo_generated' => 'emails/edo_generated.html.twig',
            'edo_pending_release' => 'emails/edo_pending_release.html.twig',
            'edo_released' => 'emails/edo_released.html.twig',
            'edo_rejected' => 'emails/edo_rejected.html.twig',
            'edo_under_review' => 'emails/edo_under_review.html.twig',
            'edo_expiration' => 'emails/edo_expiration.html.twig',
            'edo_billing' => 'emails/billing_generated.html.twig',
            'edo_payment_submitted' => 'emails/edo_payment_submitted.html.twig',
            'edo_payment_approved' => 'emails/edo_payment_approved.html.twig',
            'edo_payment_rejected' => 'emails/edo_payment_rejected.html.twig',
            default => null
        };
    }

    /**
     * Prepare template variables for email rendering
     * Extracts relevant data from metadata based on notification type
     */
    private function prepareTemplateVariables(User $recipient, string $type, array $metadata): array
    {
        $vars = [
            'recipient' => [
                'id' => $recipient->getId(),
                'email' => $recipient->getEmail()
            ]
        ];
        
        // Add manifest data if present
        if (isset($metadata['manifest_id'])) {
            $vars['manifest'] = [
                'id' => $metadata['manifest_id']
            ];
            
            // Add additional manifest fields if provided
            if (isset($metadata['manifest_number'])) {
                $vars['manifest']['manifestNumber'] = $metadata['manifest_number'];
                $vars['manifest_number'] = $metadata['manifest_number'];
            }
            if (isset($metadata['vessel_name'])) {
                $vars['manifest']['vesselName'] = $metadata['vessel_name'];
            }
            if (isset($metadata['voyage_number'])) {
                $vars['manifest']['voyageNumber'] = $metadata['voyage_number'];
            }
            if (isset($metadata['bl_number'])) {
                $vars['manifest']['blNumber'] = $metadata['bl_number'];
            }
        }
        
        // Add payment data if present
        if (isset($metadata['payment_id'])) {
            $vars['payment'] = [
                'id' => $metadata['payment_id']
            ];
            
            if (isset($metadata['amount'])) {
                $vars['payment']['amount'] = $metadata['amount'];
            }
            if (isset($metadata['payment_type'])) {
                $vars['payment']['type'] = $metadata['payment_type'];
            }
        }
        
        // Add billing data if present
        if (isset($metadata['billing_id'])) {
            $vars['billing'] = [
                'id' => $metadata['billing_id']
            ];
            
            if (isset($metadata['total_amount'])) {
                $vars['billing']['totalAmount'] = $metadata['total_amount'];
            }
            if (isset($metadata['freight_charges'])) {
                $vars['billing']['freightCharges'] = $metadata['freight_charges'];
            }
            if (isset($metadata['thc_charges'])) {
                $vars['billing']['thcCharges'] = $metadata['thc_charges'];
            }
        }
        
        // Add EDO data if present
        if (isset($metadata['edo_id'])) {
            $vars['edo'] = [
                'id' => $metadata['edo_id']
            ];
            
            if (isset($metadata['edo_number'])) {
                $vars['edo']['edoNumber'] = $metadata['edo_number'];
                $vars['edo_number'] = $metadata['edo_number'];
            }
            if (isset($metadata['status'])) {
                $vars['edo']['status'] = $metadata['status'];
            }
            if (isset($metadata['released_at'])) {
                $vars['edo']['releasedAt'] = $metadata['released_at'];
            }
            if (isset($metadata['released_by'])) {
                $vars['edo']['releasedBy'] = $metadata['released_by'];
            }
            if (isset($metadata['rejection_reason'])) {
                $vars['edo']['rejectionReason'] = $metadata['rejection_reason'];
            }
            if (isset($metadata['expired_days'])) {
                $vars['edo']['expiredDays'] = $metadata['expired_days'];
            }
        }
        
        // Add Container data if present
        if (isset($metadata['container_id'])) {
            $vars['container'] = [
                'id' => $metadata['container_id']
            ];
            
            if (isset($metadata['container_number'])) {
                $vars['container']['containerNumber'] = $metadata['container_number'];
                $vars['container_number'] = $metadata['container_number'];
            }
        }
        
        // Add NOA data if present
        if (isset($metadata['noa_id'])) {
            $vars['noa'] = [
                'id' => $metadata['noa_id']
            ];
            
            if (isset($metadata['noa_number'])) {
                $vars['noa']['noaNumber'] = $metadata['noa_number'];
            }
        }
        
        // Add eDO payment specific fields
        if (isset($metadata['edo_number'])) {
            $vars['edo_number'] = $metadata['edo_number'];
        }
        if (isset($metadata['container_number'])) {
            $vars['container_number'] = $metadata['container_number'];
        }
        if (isset($metadata['broker_name'])) {
            $vars['broker_name'] = $metadata['broker_name'];
        }
        if (isset($metadata['submitted_at'])) {
            $vars['submitted_at'] = $metadata['submitted_at'];
        }
        if (isset($metadata['approved_at'])) {
            $vars['approved_at'] = $metadata['approved_at'];
        }
        if (isset($metadata['dashboard_link'])) {
            $vars['dashboard_link'] = $metadata['dashboard_link'];
        }
        if (isset($metadata['download_link'])) {
            $vars['download_link'] = $metadata['download_link'];
        }
        if (isset($metadata['resubmission_link'])) {
            $vars['resubmission_link'] = $metadata['resubmission_link'];
        }
        if (isset($metadata['rejection_reason'])) {
            $vars['rejection_reason'] = $metadata['rejection_reason'];
        }
        
        // Add reason if present (for rejections)
        if (isset($metadata['reason'])) {
            $vars['reason'] = $metadata['reason'];
        }
        
        // Add amount if present (for payment required)
        if (isset($metadata['amount']) && !isset($vars['payment'])) {
            $vars['amount'] = $metadata['amount'];
        }
        
        return $vars;
    }
}
