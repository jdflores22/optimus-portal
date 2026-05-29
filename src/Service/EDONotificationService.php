<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\EDOBilling;
use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Service for sending eDO-related notifications
 * 
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5
 */
class EDONotificationService implements EDONotificationServiceInterface
{
    public function __construct(
        private InAppNotificationService $inAppNotificationService,
        private EmailNotificationService $emailNotificationService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Send expiration notifications to Broker and Consignee
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function notifyExpiration(ElectronicDeliveryOrder $edo): void
    {
        $retryCount = 0;
        $maxRetries = 3;
        
        while ($retryCount < $maxRetries) {
            try {
                $container = $edo->getContainer();
                $manifest = $edo->getManifest();
                
                // Skip if container or manifest is missing
                if (!$container || !$manifest) {
                    $this->logger->warning('Cannot send expiration notification - missing container or manifest', [
                        'edoId' => $edo->getId(),
                        'edoNumber' => $edo->getEdoNumber(),
                        'hasContainer' => $container !== null,
                        'hasManifest' => $manifest !== null
                    ]);
                    return;
                }
                
                // Get Broker and Consignee from manifest
                $broker = $manifest->getBroker();
                $consignee = $manifest->getConsignee();
                
                $edoNumber = $edo->getEdoNumber();
                $containerNumber = $container->getContainerNumber();
                $expiredDays = $edo->getExpiredDays() ?? 0;

                // Notification message
                $title = 'eDO Expired - Action Required';
                $message = sprintf(
                    'eDO %s for container %s has expired. Expired days: %d. Please request a new eDO.',
                    $edoNumber,
                    $containerNumber,
                    $expiredDays
                );

                // Send to Broker
                if ($broker) {
                    $this->sendNotificationToUser(
                        $broker, 
                        $title, 
                        $message, 
                        'edo_expiration', 
                        $edo->getId(),
                        'emails/edo_expiration.html.twig',
                        [
                            'edo' => $edo,
                            'container' => $container,
                            'manifest' => $manifest,
                            'recipient' => $broker
                        ]
                    );
                }

                // Send to Consignee
                if ($consignee) {
                    $this->sendNotificationToUser(
                        $consignee, 
                        $title, 
                        $message, 
                        'edo_expiration', 
                        $edo->getId(),
                        'emails/edo_expiration.html.twig',
                        [
                            'edo' => $edo,
                            'container' => $container,
                            'manifest' => $manifest,
                            'recipient' => $consignee
                        ]
                    );
                }

                $this->logger->info('eDO expiration notifications sent', [
                    'edoId' => $edo->getId(),
                    'edoNumber' => $edoNumber,
                    'containerNumber' => $containerNumber,
                    'brokerId' => $broker?->getId(),
                    'consigneeId' => $consignee?->getId()
                ]);
                
                return; // Success, exit retry loop
                
            } catch (\Exception $e) {
                $retryCount++;
                $this->logger->warning('Failed to send eDO expiration notifications', [
                    'edoId' => $edo->getId(),
                    'error' => $e->getMessage(),
                    'attempt' => $retryCount,
                    'maxRetries' => $maxRetries
                ]);
                
                if ($retryCount >= $maxRetries) {
                    $this->logger->error('eDO expiration notification failed after all retries', [
                        'edoId' => $edo->getId(),
                        'error' => $e->getMessage()
                    ]);
                    // Don't throw - notification failure shouldn't break the workflow
                    return;
                }
                
                // Wait before retry (exponential backoff: 1s, 2s, 4s)
                sleep(pow(2, $retryCount - 1));
            }
        }
    }

    /**
     * Send billing notifications to Broker and Consignee
     * 
     * @param EDOBilling $billing
     * @return void
     */
    public function notifyBilling(EDOBilling $billing): void
    {
        $retryCount = 0;
        $maxRetries = 3;
        
        while ($retryCount < $maxRetries) {
            try {
                $regenerationRequest = $billing->getRegenerationRequest();
                $edo = $regenerationRequest->getEdo();
                $container = $edo->getContainer();
                $manifest = $edo->getManifest();
                
                $broker = $manifest->getBroker();
                $consignee = $manifest->getConsignee();
                
                $edoNumber = $edo->getEdoNumber();
                $containerNumber = $container->getContainerNumber();
                $totalAmount = $billing->getTotalAmount();
                $expiredDays = $billing->getExpiredDays();

                // Notification message
                $title = 'eDO Billing Generated';
                $message = sprintf(
                    'Billing for expired eDO %s (container %s) has been generated. Amount: ₱%.2f for %d expired days. Please submit payment receipt.',
                    $edoNumber,
                    $containerNumber,
                    $totalAmount,
                    $expiredDays
                );

                // Send to Broker
                if ($broker) {
                    $this->sendNotificationToUser(
                        $broker, 
                        $title, 
                        $message, 
                        'edo_billing', 
                        $billing->getId(),
                        'emails/billing_generated.html.twig',
                        [
                            'billing' => $billing,
                            'edo' => $edo,
                            'container' => $container,
                            'manifest' => $manifest,
                            'recipient' => $broker
                        ]
                    );
                }

                // Send to Consignee
                if ($consignee) {
                    $this->sendNotificationToUser(
                        $consignee, 
                        $title, 
                        $message, 
                        'edo_billing', 
                        $billing->getId(),
                        'emails/billing_generated.html.twig',
                        [
                            'billing' => $billing,
                            'edo' => $edo,
                            'container' => $container,
                            'manifest' => $manifest,
                            'recipient' => $consignee
                        ]
                    );
                }

                $this->logger->info('eDO billing notifications sent', [
                    'billingId' => $billing->getId(),
                    'edoNumber' => $edoNumber,
                    'containerNumber' => $containerNumber,
                    'totalAmount' => $totalAmount
                ]);
                
                return; // Success, exit retry loop
                
            } catch (\Exception $e) {
                $retryCount++;
                $this->logger->warning('Failed to send eDO billing notifications', [
                    'billingId' => $billing->getId(),
                    'error' => $e->getMessage(),
                    'attempt' => $retryCount,
                    'maxRetries' => $maxRetries
                ]);
                
                if ($retryCount >= $maxRetries) {
                    $this->logger->error('eDO billing notification failed after all retries', [
                        'billingId' => $billing->getId(),
                        'error' => $e->getMessage()
                    ]);
                    // Don't throw - notification failure shouldn't break the workflow
                    return;
                }
                
                // Wait before retry (exponential backoff: 1s, 2s, 4s)
                sleep(pow(2, $retryCount - 1));
            }
        }
    }

    /**
     * Send eDO generation notifications to Broker and Consignee
     * 
     * @param ElectronicDeliveryOrder $edo
     * @return void
     */
    public function notifyEDOGenerated(ElectronicDeliveryOrder $edo): void
    {
        $retryCount = 0;
        $maxRetries = 3;
        
        while ($retryCount < $maxRetries) {
            try {
                $container = $edo->getContainer();
                $manifest = $edo->getManifest();
                
                $broker = $manifest->getBroker();
                $consignee = $manifest->getConsignee();
                
                $edoNumber = $edo->getEdoNumber();
                $containerNumber = $container->getContainerNumber();
                $expiresAt = $edo->getExpiresAt();

                // Notification message
                $title = 'New eDO Generated';
                $message = sprintf(
                    'A new eDO %s has been generated for container %s. Expires: %s',
                    $edoNumber,
                    $containerNumber,
                    $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : 'N/A'
                );

                // Send to Broker
                if ($broker) {
                    $this->sendNotificationToUser(
                        $broker, 
                        $title, 
                        $message, 
                        'edo_generated', 
                        $edo->getId(),
                        'emails/edo_generated.html.twig',
                        [
                            'edo' => $edo,
                            'container' => $container,
                            'manifest' => $manifest,
                            'recipient' => $broker
                        ]
                    );
                }

                // Send to Consignee
                if ($consignee) {
                    $this->sendNotificationToUser(
                        $consignee, 
                        $title, 
                        $message, 
                        'edo_generated', 
                        $edo->getId(),
                        'emails/edo_generated.html.twig',
                        [
                            'edo' => $edo,
                            'container' => $container,
                            'manifest' => $manifest,
                            'recipient' => $consignee
                        ]
                    );
                }

                $this->logger->info('eDO generation notifications sent', [
                    'edoId' => $edo->getId(),
                    'edoNumber' => $edoNumber,
                    'containerNumber' => $containerNumber
                ]);
                
                return; // Success, exit retry loop
                
            } catch (\Exception $e) {
                $retryCount++;
                $this->logger->warning('Failed to send eDO generation notifications', [
                    'edoId' => $edo->getId(),
                    'error' => $e->getMessage(),
                    'attempt' => $retryCount,
                    'maxRetries' => $maxRetries
                ]);
                
                if ($retryCount >= $maxRetries) {
                    $this->logger->error('eDO generation notification failed after all retries', [
                        'edoId' => $edo->getId(),
                        'error' => $e->getMessage()
                    ]);
                    // Don't throw - notification failure shouldn't break the workflow
                    return;
                }
                
                // Wait before retry (exponential backoff: 1s, 2s, 4s)
                sleep(pow(2, $retryCount - 1));
            }
        }
    }

    /**
     * Helper method to send notification to a user
     * 
     * @param User $user
     * @param string $title
     * @param string $message
     * @param string $type
     * @param int $relatedId
     * @param string|null $emailTemplate
     * @param array $emailTemplateData
     * @return void
     */
    private function sendNotificationToUser(
        User $user, 
        string $title, 
        string $message, 
        string $type, 
        int $relatedId,
        ?string $emailTemplate = null,
        array $emailTemplateData = []
    ): void {
        // Send in-app notification
        $this->inAppNotificationService->createNotification(
            $user,
            $title,
            $message,
            $type,
            ['related_id' => $relatedId]
        );

        // Send email notification with template if provided
        if ($emailTemplate) {
            $this->emailNotificationService->sendTemplatedEmail(
                $user->getEmail(),
                $title,
                $emailTemplate,
                $emailTemplateData
            );
        } else {
            // Fallback to plain text email
            $this->emailNotificationService->sendEmail(
                $user->getEmail(),
                $title,
                $message
            );
        }
    }
}
