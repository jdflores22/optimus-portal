<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\WorkflowState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling payment verification with transactional integrity
 * Ensures payment verification, state transition, and eDO generation happen atomically
 */
class PaymentVerificationTransactionService implements PaymentVerificationTransactionServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatabaseTransactionService $transactionService,
        private EDOService $edoService,
        private WorkflowOrchestrator $workflowOrchestrator,
        private ManifestAuthorizationService $authorizationService,
        private AuditService $auditService,
        private ActivityLogService $activityLogService,
        private ManifestNotificationService $notificationService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Verify final payment and auto-generate eDO in a single transaction
     * 
     * @param Payment $payment The payment to verify
     * @return ElectronicDeliveryOrder The generated eDO
     * @throws \Exception If verification or eDO generation fails
     */
    public function verifyFinalPaymentWithEDO(Payment $payment): ElectronicDeliveryOrder
    {
        if ($payment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw new \InvalidArgumentException('Payment must be a final payment');
        }

        $manifest = $payment->getManifest();
        $validator = $payment->getValidatedBy();

        if (!$validator) {
            throw new \InvalidArgumentException('Payment must have a validator set before transaction');
        }

        $this->logger->info('Starting payment verification transaction', [
            'payment_id' => $payment->getId(),
            'manifest_id' => $manifest->getId(),
            'manifest_number' => $manifest->getManifestNumber()
        ]);

        $edo = $this->transactionService->executeInTransactionWithRetry(
            function() use ($payment, $manifest, $validator) {
                // Step 1: Verify payment (already done by caller, just persist)
                $this->entityManager->persist($payment);
                
                // Step 2: Transition manifest state to EDO_GENERATED
                // This will also create workflow history entry
                $this->workflowOrchestrator->transitionState(
                    $manifest,
                    WorkflowState::EDO_GENERATED,
                    $validator,
                    'Final payment verified'
                );
                
                // Step 3: Auto-generate eDO
                $edo = $this->edoService->autoGenerateEDO($payment);
                
                // Flush all changes
                $this->entityManager->flush();
                
                $this->logger->info('Payment verification transaction completed successfully', [
                    'payment_id' => $payment->getId(),
                    'manifest_id' => $manifest->getId(),
                    'edo_id' => $edo->getId(),
                    'edo_number' => $edo->getEdoNumber()
                ]);
                
                return $edo;
            },
            'verify_final_payment_with_edo'
        );
        
        // After transaction completes successfully, log and notify
        // This is done outside the transaction to avoid issues with entity IDs
        $this->logAndNotifyEDOGeneration($edo, $payment, $manifest, $validator);
        
        return $edo;
    }

    /**
     * Verify manifest access payment with state transition in a transaction
     * 
     * @param Payment $payment The payment to verify
     * @return void
     * @throws \Exception If verification fails
     */
    public function verifyManifestAccessPayment(Payment $payment): void
    {
        if ($payment->getPaymentType() !== PaymentType::MANIFEST_ACCESS) {
            throw new \InvalidArgumentException('Payment must be a manifest access payment');
        }

        $manifest = $payment->getManifest();
        $validator = $payment->getValidatedBy();

        if (!$validator) {
            throw new \InvalidArgumentException('Payment must have a validator set before transaction');
        }

        $this->logger->info('Starting manifest access payment verification transaction', [
            'payment_id' => $payment->getId(),
            'manifest_id' => $manifest->getId(),
            'manifest_number' => $manifest->getManifestNumber()
        ]);

        $this->transactionService->executeInTransactionWithRetry(
            function() use ($payment, $manifest, $validator) {
                // Step 1: Verify payment (already done by caller, just persist)
                $this->entityManager->persist($payment);
                
                // Step 2: Transition manifest state to PAYMENT_VERIFIED
                // This will also create workflow history entry
                $this->workflowOrchestrator->transitionState(
                    $manifest,
                    WorkflowState::PAYMENT_VERIFIED,
                    $validator,
                    'Manifest access payment verified'
                );
                
                // Flush all changes
                $this->entityManager->flush();
                
                // Step 3: Invalidate authorization cache
                $this->authorizationService->invalidateManifestCache($manifest);
                
                $this->logger->info('Manifest access payment verification transaction completed', [
                    'payment_id' => $payment->getId(),
                    'manifest_id' => $manifest->getId()
                ]);
            },
            'verify_manifest_access_payment'
        );
    }
    
    /**
     * Log and notify after EDO generation (called after transaction completes)
     * 
     * @param ElectronicDeliveryOrder $edo The generated EDO
     * @param Payment $payment The verified payment
     * @param Manifest $manifest The manifest
     * @param User $validator The user who validated the payment
     */
    private function logAndNotifyEDOGeneration(
        ElectronicDeliveryOrder $edo,
        Payment $payment,
        Manifest $manifest,
        User $validator
    ): void {
        try {
            // Log EDO generation to audit log
            $this->auditService->logAction(
                $validator,
                'edo_generation',
                'ElectronicDeliveryOrder',
                $edo->getId(),
                [
                    'edo_number' => $edo->getEdoNumber(),
                    'manifest_id' => $manifest->getId(),
                    'manifest_number' => $manifest->getManifestNumber(),
                    'payment_id' => $payment->getId()
                ]
            );

            // Log to activity log for notifications
            $this->activityLogService->logEDOGeneration($validator, $manifest);

            // Notify broker and consignee about EDO generation
            $this->notificationService->notifyEDOGenerated($edo);
            
            $this->logger->info('EDO generation logged and notifications sent', [
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber()
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the operation since transaction already completed
            $this->logger->error('Failed to log or notify EDO generation', [
                'edo_id' => $edo->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
