<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\Billing;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ManifestNotificationService implements NotificationServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationGateway $notificationGateway,
        private InAppNotificationService $inAppNotificationService,
        private LoggerInterface $logger
    ) {
    }

    public function notifyConsigneeDeclared(Manifest $manifest): void
    {
        $this->logger->info('DEBUG: notifyConsigneeDeclared called for manifest ID: ' . $manifest->getId());
        
        $recipients = $this->getManifestRecipients($manifest);
        
        $this->logger->info('DEBUG: Recipients count: ' . count($recipients));
        
        $subject = 'You Have Been Assigned to a Manifest';
        $message = sprintf(
            'You have been assigned to manifest %s. You can now view the manifest details and proceed with the workflow.',
            $manifest->getManifestNumber()
        );

        $this->notificationGateway->sendNotification(
            $recipients,
            $subject,
            $message,
            'manifest_consignee_declared',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'vessel_name' => $manifest->getVesselName(),
                'voyage_number' => $manifest->getVoyageNumber()
            ]
        );
        
        $this->logger->info('DEBUG: notifyConsigneeDeclared completed');
    }

    public function notifyNOAGenerated(Manifest $manifest, string $noaPath): void
    {
        $this->logger->info('DEBUG: notifyNOAGenerated called for manifest ID: ' . $manifest->getId());
        
        $recipients = $this->getManifestRecipients($manifest);
        
        $this->logger->info('DEBUG: Recipients count for NOA notification: ' . count($recipients));
        
        $subject = 'Notice of Arrival Generated';
        $message = sprintf(
            'Notice of Arrival has been generated for manifest %s. You can download it from the manifest details page.',
            $manifest->getManifestNumber()
        );

        $this->notificationGateway->sendNotification(
            $recipients,
            $subject,
            $message,
            'noa_generated',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'noa_id' => $manifest->getNoaDocument()?->getId(),
                'noa_number' => $manifest->getNoaDocument()?->getNoaNumber()
            ]
        );
        
        $this->logger->info('DEBUG: notifyNOAGenerated completed');
    }

    public function notifyBillingGenerated(Manifest $manifest, Billing $billing): void
    {
        $this->logger->info('DEBUG: notifyBillingGenerated called for manifest ID: ' . $manifest->getId());
        
        $recipients = $this->getManifestRecipients($manifest);
        
        $this->logger->info('DEBUG: Recipients count for Billing notification: ' . count($recipients));
        
        $subject = 'Billing Document Generated';
        $message = sprintf(
            'Billing document has been generated for manifest %s. Total amount: ₱%s',
            $manifest->getManifestNumber(),
            number_format($billing->getTotalAmount(), 2)
        );

        $this->notificationGateway->sendNotification(
            $recipients,
            $subject,
            $message,
            'billing_generated',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'billing_id' => $billing->getId(),
                'total_amount' => $billing->getTotalAmount(),
                'freight_charges' => $billing->getFreightCharges(),
                'thc_charges' => $billing->getThcCharges()
            ]
        );
        
        $this->logger->info('DEBUG: notifyBillingGenerated completed');
    }

    public function notifyPaymentRejected(Payment $payment, string $reason): void
    {
        $submitter = $payment->getSubmittedBy();
        $manifest = $payment->getManifest();
        
        $subject = 'Payment Rejected';
        $message = sprintf(
            'Your payment for manifest %s has been rejected. Reason: %s',
            $manifest->getManifestNumber(),
            $reason
        );

        $this->notificationGateway->sendNotification(
            [$submitter],
            $subject,
            $message,
            'payment_rejected',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'payment_id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'reason' => $reason
            ]
        );
    }

    public function notifyEDOGenerated(ElectronicDeliveryOrder $edo): void
    {
        $manifest = $edo->getManifest();
        
        $this->logger->info('DEBUG: notifyEDOGenerated called for manifest ID: ' . $manifest->getId());
        
        // Get all SYSTEM_ADMIN users for eDO release review
        $systemAdmins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::SYSTEM_ADMIN)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
        
        $this->logger->info('DEBUG: SYSTEM_ADMIN users count for EDO notification: ' . count($systemAdmins));
        
        $subject = 'eDO Pending Release Review';
        $message = sprintf(
            'Electronic Delivery Order %s has been generated for manifest %s and requires your review for release approval.',
            $edo->getEdoNumber(),
            $manifest->getManifestNumber()
        );

        $this->notificationGateway->sendNotification(
            $systemAdmins,
            $subject,
            $message,
            'edo_pending_release',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber(),
                'status' => $edo->getStatus()->value
            ]
        );
        
        $this->logger->info('DEBUG: notifyEDOGenerated completed');
    }

    public function notifyEDOReleased(ElectronicDeliveryOrder $edo): void
    {
        $manifest = $edo->getManifest();
        
        $this->logger->info('DEBUG: notifyEDOReleased called for EDO ID: ' . $edo->getId());
        
        // Get recipients: broker and consignee
        $recipients = $this->getManifestRecipients($manifest);
        
        $this->logger->info('DEBUG: Recipients count for EDO release notification: ' . count($recipients));
        
        $subject = 'Electronic Delivery Order Released';
        $message = sprintf(
            'Electronic Delivery Order %s for manifest %s has been released and is now available for download. You can proceed with cargo release.',
            $edo->getEdoNumber(),
            $manifest->getManifestNumber()
        );

        $this->notificationGateway->sendNotification(
            $recipients,
            $subject,
            $message,
            'edo_released',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber(),
                'released_at' => $edo->getReleasedAt()?->format('Y-m-d H:i:s'),
                'released_by' => $edo->getReleasedBy()?->getEmail()
            ]
        );
        
        $this->logger->info('DEBUG: notifyEDOReleased completed');
    }

    public function notifyEDORejected(ElectronicDeliveryOrder $edo, string $reason): void
    {
        $manifest = $edo->getManifest();
        
        $this->logger->info('DEBUG: notifyEDORejected called for EDO ID: ' . $edo->getId());
        
        // Get all ACCOUNTING users
        $accountingUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::ACCOUNTING)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
        
        $this->logger->info('DEBUG: ACCOUNTING users count for EDO rejection notification: ' . count($accountingUsers));
        
        // Notify ACCOUNTING with rejection reason
        $accountingSubject = 'eDO Release Rejected';
        $accountingMessage = sprintf(
            'Electronic Delivery Order %s for manifest %s has been rejected. Reason: %s. Please review and resubmit if necessary.',
            $edo->getEdoNumber(),
            $manifest->getManifestNumber(),
            $reason
        );

        $this->notificationGateway->sendNotification(
            $accountingUsers,
            $accountingSubject,
            $accountingMessage,
            'edo_rejected',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber(),
                'rejection_reason' => $reason
            ]
        );
        
        // Notify Broker with instructions to contact support
        $broker = $manifest->getBroker();
        if ($broker) {
            $brokerSubject = 'eDO Under Review';
            $brokerMessage = sprintf(
                'Electronic Delivery Order %s for manifest %s is currently under review. Please contact support for more information.',
                $edo->getEdoNumber(),
                $manifest->getManifestNumber()
            );

            $this->notificationGateway->sendNotification(
                [$broker],
                $brokerSubject,
                $brokerMessage,
                'edo_under_review',
                [
                    'manifest_id' => $manifest->getId(),
                    'manifest_number' => $manifest->getManifestNumber(),
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber()
                ]
            );
        }
        
        $this->logger->info('DEBUG: notifyEDORejected completed');
    }

    /**
     * Notify SYSTEM_ADMIN users when a payment is submitted for validation
     */
    public function notifyPaymentSubmitted(Payment $payment): void
    {
        $manifest = $payment->getManifest();
        $submitter = $payment->getSubmittedBy();
        $paymentType = $payment->getPaymentType()->value === 'manifest_access' ? 'Manifest Access' : 'Final';
        $isFinalPayment = $payment->getPaymentType()->value === 'final_payment';
        
        // For manifest access payment: notify SYSTEM_ADMIN
        // For final payment: notify ACCOUNTING (shipping line's accounting department)
        if ($isFinalPayment) {
            // Final payment - notify ACCOUNTING only
            $validatorUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.status = :status')
                ->setParameter('role', UserRole::ACCOUNTING)
                ->setParameter('status', AccountStatus::APPROVED)
                ->getQuery()
                ->getResult();
        } else {
            // Manifest access payment - notify SYSTEM_ADMIN only
            $validatorUsers = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.status = :status')
                ->setParameter('role', UserRole::SYSTEM_ADMIN)
                ->setParameter('status', AccountStatus::APPROVED)
                ->getQuery()
                ->getResult();
        }
        
        // Get all SL_STAFF users (notified for all payment types)
        $slStaffUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::SL_STAFF)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
        
        // Notify validators (ACCOUNTING for final payment, SYSTEM_ADMIN for manifest access)
        $validatorSubject = sprintf('%s Payment Submitted for Validation', $paymentType);
        $validatorMessage = sprintf(
            '%s has submitted a %s payment of ₱%s for manifest %s. Please review and validate the payment.',
            $this->getRecipientName($submitter),
            strtolower($paymentType),
            number_format($payment->getAmount(), 2),
            $manifest->getManifestNumber()
        );

        foreach ($validatorUsers as $validator) {
            try {
                // Send in-app notification
                $this->inAppNotificationService->createNotification(
                    $validator,
                    $validatorSubject,
                    $validatorMessage,
                    'payment_submitted',
                    [
                        'manifest_id' => $manifest->getId(),
                        'payment_id' => $payment->getId(),
                        'payment_type' => $payment->getPaymentType()->value,
                        'amount' => $payment->getAmount()
                    ]
                );
            } catch (\Exception $e) {
                // Log error but don't fail the operation
                error_log('Failed to send notification to validator: ' . $e->getMessage());
            }
        }
        
        // Notify SL_STAFF users
        $slStaffSubject = sprintf('%s Payment Submitted', $paymentType);
        $slStaffMessage = sprintf(
            '%s has submitted a %s payment of ₱%s for manifest %s.',
            $this->getRecipientName($submitter),
            strtolower($paymentType),
            number_format($payment->getAmount(), 2),
            $manifest->getManifestNumber()
        );

        foreach ($slStaffUsers as $slStaff) {
            try {
                // Send in-app notification
                $this->inAppNotificationService->createNotification(
                    $slStaff,
                    $slStaffSubject,
                    $slStaffMessage,
                    'payment_submitted',
                    [
                        'manifest_id' => $manifest->getId(),
                        'payment_id' => $payment->getId(),
                        'payment_type' => $payment->getPaymentType()->value,
                        'amount' => $payment->getAmount()
                    ]
                );
            } catch (\Exception $e) {
                // Log error but don't fail the operation
                error_log('Failed to send notification to SL staff: ' . $e->getMessage());
            }
        }
        
        // Notify consignee and broker about payment submission
        $recipients = $this->getManifestRecipients($manifest);
        $recipientSubject = sprintf('%s Payment Submitted', $paymentType);
        $recipientMessage = sprintf(
            'A %s payment of ₱%s has been submitted for manifest %s and is pending validation.',
            strtolower($paymentType),
            number_format($payment->getAmount(), 2),
            $manifest->getManifestNumber()
        );

        $this->notificationGateway->sendNotification(
            $recipients,
            $recipientSubject,
            $recipientMessage,
            'payment_submitted',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'bl_number' => $manifest->getBlNumber(),
                'payment_id' => $payment->getId(),
                'payment_type' => $payment->getPaymentType()->value,
                'amount' => $payment->getAmount()
            ]
        );
    }

    /**
     * Notify relevant users when a payment is validated (approved or rejected)
     * Notifies: Broker, Consignee, and SL_STAFF users
     */
    public function notifyPaymentValidated(Payment $payment, bool $approved, ?string $reason = null): void
    {
        $manifest = $payment->getManifest();
        $paymentType = $payment->getPaymentType()->value === 'manifest_access' ? 'Manifest Access' : 'Final';
        
        // Get recipients: broker, consignee
        $recipients = $this->getManifestRecipients($manifest);
        
        // Get all SL_STAFF users
        $slStaffUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::SL_STAFF)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
        
        if ($approved) {
            $subject = sprintf('%s Payment Approved', $paymentType);
            $message = sprintf(
                'Your %s payment of ₱%s for manifest %s has been approved.',
                strtolower($paymentType),
                number_format($payment->getAmount(), 2),
                $manifest->getManifestNumber()
            );
            $notificationType = 'payment_approved';
        } else {
            $subject = sprintf('%s Payment Rejected', $paymentType);
            $message = sprintf(
                'Your %s payment of ₱%s for manifest %s has been rejected. Reason: %s',
                strtolower($paymentType),
                number_format($payment->getAmount(), 2),
                $manifest->getManifestNumber(),
                $reason ?? 'No reason provided'
            );
            $notificationType = 'payment_rejected';
        }

        // Send notifications to broker and consignee via gateway (all channels)
        $this->notificationGateway->sendNotification(
            $recipients,
            $subject,
            $message,
            $notificationType,
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'payment_id' => $payment->getId(),
                'payment_type' => $payment->getPaymentType()->value,
                'amount' => $payment->getAmount(),
                'approved' => $approved,
                'reason' => $reason
            ]
        );
        
        // Send in-app notifications to SL_STAFF users (internal notifications only)
        foreach ($slStaffUsers as $slStaff) {
            try {
                $this->inAppNotificationService->createNotification(
                    $slStaff,
                    $subject,
                    $message,
                    $notificationType,
                    [
                        'manifest_id' => $manifest->getId(),
                        'payment_id' => $payment->getId(),
                        'payment_type' => $payment->getPaymentType()->value,
                        'amount' => $payment->getAmount(),
                        'approved' => $approved,
                        'reason' => $reason
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->error('Failed to send payment validation notification to SL_STAFF', [
                    'recipient_id' => $slStaff->getId(),
                    'payment_id' => $payment->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Notify SL_STAFF and ACCOUNTING users when a BL is uploaded
     */
    public function notifyBLUploaded(Manifest $manifest, User $uploader): void
    {
        $this->logger->info('DEBUG: notifyBLUploaded called for manifest ID: ' . $manifest->getId());
        
        // Get all SL_STAFF users
        $slStaffUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::SL_STAFF)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
        
        $this->logger->info('DEBUG: SL_STAFF users count for BL notification: ' . count($slStaffUsers));
        
        // Get all ACCOUNTING users (they are responsible for billing generation)
        $accountingUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::ACCOUNTING)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
        
        $this->logger->info('DEBUG: ACCOUNTING users count for BL notification: ' . count($accountingUsers));
        
        // Combine both user groups
        $allRecipients = array_merge($slStaffUsers, $accountingUsers);
        
        $uploaderName = $this->getRecipientName($uploader);
        $subject = 'Bill of Lading Uploaded - Billing Required';
        $message = sprintf(
            '%s has uploaded Bill of Lading %s for manifest %s. You can now proceed to generate billing.',
            $uploaderName,
            $manifest->getBlNumber(),
            $manifest->getManifestNumber()
        );

        $this->notificationGateway->sendNotification(
            $allRecipients,
            $subject,
            $message,
            'bl_uploaded',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'bl_number' => $manifest->getBlNumber()
            ]
        );
        
        $this->logger->info('DEBUG: notifyBLUploaded completed - notified ' . count($allRecipients) . ' users');
    }

    /**
     * Get recipients for manifest notifications (broker and consignee)
     */
    private function getManifestRecipients(Manifest $manifest): array
    {
        $recipients = [];
        
        $broker = $manifest->getBroker();
        $consignee = $manifest->getConsignee();
        
        $this->logger->info('DEBUG: Broker ID: ' . ($broker ? $broker->getId() : 'NULL'));
        $this->logger->info('DEBUG: Consignee ID: ' . ($consignee ? $consignee->getId() : 'NULL'));
        
        if ($broker) {
            $recipients[] = $broker;
            $this->logger->info('DEBUG: Added broker to recipients: ' . $broker->getEmail());
        }
        
        if ($consignee) {
            $recipients[] = $consignee;
            $this->logger->info('DEBUG: Added consignee to recipients: ' . $consignee->getEmail());
        }
        
        $this->logger->info('DEBUG: Total recipients: ' . count($recipients));
        
        return $recipients;
    }

    /**
     * Notify SYSTEM_ADMIN users when an eDO payment is submitted
     */
    public function notifyEDOPaymentSubmitted(\App\Entity\EDOPayment $edoPayment): void
    {
        $manifest = $edoPayment->getManifest();
        $submitter = $edoPayment->getSubmittedBy();
        $edo = $edoPayment->getEdo();
        
        $this->logger->info('DEBUG: notifyEDOPaymentSubmitted called for manifest ID: ' . $manifest->getId());
        
        // Get all SYSTEM_ADMIN users for eDO payment validation
        $systemAdmins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', UserRole::SYSTEM_ADMIN)
            ->setParameter('status', AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
        
        $this->logger->info('DEBUG: SYSTEM_ADMIN users count for eDO payment notification: ' . count($systemAdmins));
        
        $subject = 'eDO Payment Submitted for Validation';
        $message = sprintf(
            '%s has submitted an eDO payment of ₱%s for manifest %s. Please review and validate the payment.',
            $this->getRecipientName($submitter),
            number_format($edoPayment->getAmount(), 2),
            $manifest->getManifestNumber()
        );

        $this->notificationGateway->sendNotification(
            $systemAdmins,
            $subject,
            $message,
            'edo_payment_submitted',
            [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'edo_payment_id' => $edoPayment->getId(),
                'edo_number' => $edo ? $edo->getEdoNumber() : 'N/A',
                'container_number' => $edo && $edo->getContainer() ? $edo->getContainer()->getContainerNumber() : 'N/A',
                'broker_name' => $this->getRecipientName($submitter),
                'amount' => $edoPayment->getAmount(),
                'submitted_at' => $edoPayment->getCreatedAt(),
                'submitted_by' => $submitter->getId(),
                'dashboard_link' => '/admin/edo-payments'
            ]
        );
        
        $this->logger->info('DEBUG: notifyEDOPaymentSubmitted completed');
    }

    /**
     * Notify broker when an eDO payment is validated (approved or rejected)
     */
    public function notifyEDOPaymentValidated(\App\Entity\EDOPayment $edoPayment, bool $approved, ?string $reason = null): void
    {
        $manifest = $edoPayment->getManifest();
        $edo = $edoPayment->getEdo();
        $broker = $edoPayment->getSubmittedBy();
        
        $this->logger->info('DEBUG: notifyEDOPaymentValidated called for eDO payment ID: ' . $edoPayment->getId());
        
        // Get recipients: broker and consignee
        $recipients = $this->getManifestRecipients($manifest);
        
        $this->logger->info('DEBUG: Recipients count for eDO payment validation notification: ' . count($recipients));
        
        if ($approved) {
            $subject = 'eDO Payment Approved';
            $message = sprintf(
                'Your eDO payment of ₱%s for manifest %s has been approved. The eDO will be released shortly.',
                number_format($edoPayment->getAmount(), 2),
                $manifest->getManifestNumber()
            );
            $notificationType = 'edo_payment_approved';
            
            $metadata = [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'edo_payment_id' => $edoPayment->getId(),
                'edo_number' => $edo ? $edo->getEdoNumber() : 'N/A',
                'container_number' => $edo && $edo->getContainer() ? $edo->getContainer()->getContainerNumber() : 'N/A',
                'broker_name' => $this->getRecipientName($broker),
                'amount' => $edoPayment->getAmount(),
                'approved' => $approved,
                'approved_at' => $edoPayment->getValidatedAt(),
                'download_link' => $edo ? '/broker/edos/' . $edo->getId() . '/download' : '#',
                'official_receipt_path' => $edoPayment->getOfficialReceiptPath()
            ];
        } else {
            $subject = 'eDO Payment Rejected';
            $message = sprintf(
                'Your eDO payment of ₱%s for manifest %s has been rejected. Reason: %s',
                number_format($edoPayment->getAmount(), 2),
                $manifest->getManifestNumber(),
                $reason ?? 'No reason provided'
            );
            $notificationType = 'edo_payment_rejected';
            
            $metadata = [
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'edo_payment_id' => $edoPayment->getId(),
                'edo_number' => $edo ? $edo->getEdoNumber() : 'N/A',
                'container_number' => $edo && $edo->getContainer() ? $edo->getContainer()->getContainerNumber() : 'N/A',
                'broker_name' => $this->getRecipientName($broker),
                'amount' => $edoPayment->getAmount(),
                'approved' => $approved,
                'rejection_reason' => $reason ?? 'No reason provided',
                'resubmission_link' => $edo ? '/broker/edos/' . $edo->getId() . '/payment' : '#'
            ];
        }

        $this->notificationGateway->sendNotification(
            $recipients,
            $subject,
            $message,
            $notificationType,
            $metadata
        );
        
        $this->logger->info('DEBUG: notifyEDOPaymentValidated completed');
    }

    /**
     * Get the display name for a recipient (handles both Broker and Consignee)
     */
    private function getRecipientName(User $recipient): string
    {
        if ($recipient instanceof \App\Entity\Broker) {
            return $recipient->getFullName();
        }
        
        if ($recipient instanceof \App\Entity\Consignee) {
            return $recipient->getBusinessName();
        }
        
        return $recipient->getEmail(); // Fallback to email
    }
}
