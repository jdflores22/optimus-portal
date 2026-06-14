<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\WorkflowStateHistory;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\WorkflowState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for orchestrating workflow state transitions with history logging and notifications
 */
class WorkflowOrchestrator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManifestNotificationService $notificationService,
        private AuditService $auditService,
        private ActivityLogService $activityLogService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Transition manifest to a new state with history logging and notifications
     * 
     * @param Manifest $manifest The manifest to transition
     * @param WorkflowState $newState The target state
     * @param User $actor The user performing the transition
     * @param string|null $reason Optional reason for the transition
     * @throws \InvalidArgumentException If transition is invalid
     */
    public function transitionState(
        Manifest $manifest,
        WorkflowState $newState,
        User $actor,
        ?string $reason = null
    ): void {
        $oldState = $manifest->getWorkflowState();
        
        // Validate transition
        if (!$manifest->canTransitionTo($newState)) {
            $this->logger->error('Invalid state transition attempted', [
                'manifest_id' => $manifest->getId(),
                'from_state' => $oldState->value,
                'to_state' => $newState->value,
                'actor_id' => $actor->getId(),
                'actor_role' => $actor->getRole()->value
            ]);
            
            throw new \InvalidArgumentException(
                "Invalid state transition from {$oldState->value} to {$newState->value}"
            );
        }

        $this->logger->info('Transitioning manifest state', [
            'manifest_id' => $manifest->getId(),
            'manifest_number' => $manifest->getManifestNumber(),
            'from_state' => $oldState->value,
            'to_state' => $newState->value,
            'actor_id' => $actor->getId(),
            'actor_role' => $actor->getRole()->value,
            'reason' => $reason
        ]);

        // Perform state transition
        $manifest->transitionTo($newState);
        
        // Create workflow history entry
        $this->logStateTransition($manifest, $oldState, $newState, $actor, $reason);
        
        // Log to audit service
        $this->auditService->logAction(
            $actor,
            'state_transition',
            'Manifest',
            $manifest->getId(),
            [
                'from_state' => $oldState->value,
                'to_state' => $newState->value,
                'actor_role' => $actor->getRole()->value,
                'reason' => $reason
            ]
        );
        
        // Log to activity log for notifications
        $this->activityLogService->logManifestStateTransition(
            $actor,
            $manifest,
            $oldState->value,
            $newState->value
        );
        
        // Trigger notifications based on new state
        $this->triggerStateChangeNotifications($manifest, $newState);
        
        $this->logger->info('Manifest state transition completed', [
            'manifest_id' => $manifest->getId(),
            'new_state' => $newState->value
        ]);
    }

    /**
     * Transition manifest to EDO_RELEASED when every eDO on the manifest is released.
     */
    public function transitionToEdoReleasedWhenComplete(
        Manifest $manifest,
        User $actor,
        ?string $reason = null
    ): bool {
        if ($manifest->getWorkflowState() === WorkflowState::EDO_RELEASED) {
            return false;
        }

        if (!$this->allEdosReleased($manifest)) {
            return false;
        }

        if (!$manifest->canTransitionTo(WorkflowState::EDO_RELEASED)) {
            $this->logger->warning('Cannot transition manifest to edo_released', [
                'manifest_id' => $manifest->getId(),
                'current_state' => $manifest->getWorkflowState()->value,
            ]);

            return false;
        }

        $this->transitionState(
            $manifest,
            WorkflowState::EDO_RELEASED,
            $actor,
            $reason ?? 'All eDOs released'
        );

        $manifest->markAsCompleted($actor);

        return true;
    }

    private function allEdosReleased(Manifest $manifest): bool
    {
        $edos = $manifest->getEdos();
        if ($edos->isEmpty()) {
            return false;
        }

        foreach ($edos as $edo) {
            if ($edo->getStatus() !== EDOStatus::RELEASED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transition manifest to EDO_GENERATED when batch generation completes (idempotent).
     */
    public function transitionToEdoGeneratedIfNeeded(
        Manifest $manifest,
        User $actor,
        ?string $reason = null
    ): void {
        if ($manifest->getWorkflowState() === WorkflowState::EDO_GENERATED
            || $manifest->getWorkflowState() === WorkflowState::EDO_RELEASED) {
            return;
        }

        if (!$manifest->canTransitionTo(WorkflowState::EDO_GENERATED)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot transition manifest %d from %s to edo_generated',
                $manifest->getId(),
                $manifest->getWorkflowState()->value
            ));
        }

        $this->transitionState(
            $manifest,
            WorkflowState::EDO_GENERATED,
            $actor,
            $reason ?? 'Batch eDO generation'
        );
    }

    /**
     * Record workflow history when a new manifest is linked from NOA generation.
     */
    public function recordNoaGeneratedWorkflow(
        Manifest $manifest,
        User $actor,
        ?string $reason = null
    ): void {
        if ($manifest->getWorkflowState() !== WorkflowState::MANIFEST_UPLOADED) {
            return;
        }

        if (!$manifest->canTransitionTo(WorkflowState::NOA_GENERATED)) {
            return;
        }

        $this->transitionState(
            $manifest,
            WorkflowState::NOA_GENERATED,
            $actor,
            $reason ?? 'NOA generated and linked to manifest'
        );
    }

    /**
     * Record workflow history when manifest/BL PDF is generated from an NOA.
     */
    public function recordBlGeneratedWorkflow(
        Manifest $manifest,
        User $actor,
        ?string $reason = null
    ): void {
        if (in_array($manifest->getWorkflowState(), [WorkflowState::BL_GENERATED, WorkflowState::EDO_RELEASED], true)) {
            return;
        }

        if ($manifest->getWorkflowState() === WorkflowState::MANIFEST_UPLOADED
            && $manifest->canTransitionTo(WorkflowState::NOA_GENERATED)) {
            $this->transitionState(
                $manifest,
                WorkflowState::NOA_GENERATED,
                $actor,
                'NOA linked to manifest'
            );
        }

        if ($manifest->canTransitionTo(WorkflowState::BL_GENERATED)) {
            $this->transitionState(
                $manifest,
                WorkflowState::BL_GENERATED,
                $actor,
                $reason ?? 'Manifest/BL generated'
            );
        }
    }

    /**
     * Log state transition to workflow history
     */
    private function logStateTransition(
        Manifest $manifest,
        WorkflowState $fromState,
        WorkflowState $toState,
        User $actor,
        ?string $reason
    ): void {
        $history = new WorkflowStateHistory();
        $history->setManifest($manifest);
        $history->setFromState($fromState->value);
        $history->setToState($toState->value);
        $history->setActor($actor);
        $history->setActorRole($actor->getRole()->value);
        $history->setTransitionReason($reason);
        
        $this->entityManager->persist($history);
    }

    /**
     * Trigger appropriate notifications based on state change
     */
    private function triggerStateChangeNotifications(Manifest $manifest, WorkflowState $newState): void
    {
        try {
            match($newState) {
                WorkflowState::NOA_GENERATED => $this->notifyNOAGenerated($manifest),
                WorkflowState::BILLING_GENERATED => $this->notifyBillingGenerated($manifest),
                WorkflowState::EDO_GENERATED => $this->notifyEDOGenerated($manifest),
                WorkflowState::EDO_RELEASED => $this->notifyEDOReleased($manifest),
                default => null // No notification for other states
            };
        } catch (\Exception $e) {
            // Log notification failure but don't fail the transaction
            $this->logger->error('Failed to send state change notification', [
                'manifest_id' => $manifest->getId(),
                'state' => $newState->value,
                'exception' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send NOA generated notification
     */
    private function notifyNOAGenerated(Manifest $manifest): void
    {
        $noa = $manifest->getNoaDocument();
        if ($noa) {
            $this->notificationService->notifyNOAGenerated($manifest, $noa->getPdfPath());
        }
    }

    /**
     * Send billing generated notification
     */
    private function notifyBillingGenerated(Manifest $manifest): void
    {
        $billing = $manifest->getBilling();
        if ($billing) {
            $this->notificationService->notifyBillingGenerated($manifest, $billing);
        }
    }

    /**
     * Send eDO generated notification
     */
    private function notifyEDOGenerated(Manifest $manifest): void
    {
        $edo = $manifest->getEdo();
        if ($edo) {
            $this->notificationService->notifyEDOGenerated($edo);
        }
    }

    /**
     * Send eDO released notification
     */
    private function notifyEDOReleased(Manifest $manifest): void
    {
        $edo = $manifest->getEdo();
        if ($edo) {
            $this->notificationService->notifyEDOReleased($edo);
        }
    }

    /**
     * Get workflow history for a manifest
     * 
     * @param Manifest $manifest The manifest
     * @return array Array of WorkflowStateHistory entries
     */
    public function getWorkflowHistory(Manifest $manifest): array
    {
        return $this->entityManager->getRepository(WorkflowStateHistory::class)
            ->createQueryBuilder('wsh')
            ->where('wsh.manifest = :manifest')
            ->setParameter('manifest', $manifest)
            ->orderBy('wsh.createdAt', 'DESC')
            ->addOrderBy('wsh.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
