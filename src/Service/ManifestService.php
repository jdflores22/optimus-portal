<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\User;
use App\Entity\Consignee;
use App\Entity\Enum\WorkflowState;
use App\Exception\ManifestValidationException;
use Doctrine\ORM\EntityManagerInterface;

class ManifestService implements ManifestServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditService $auditService,
        private WorkflowOrchestrator $workflowOrchestrator,
        private ActivityLogService $activityLogService,
        private ManifestNotificationService $notificationService,
        private ?EDOGenerationServiceInterface $edoGenerationService = null
    ) {
    }

    public function uploadManifest(array $data, User $slStaff): Manifest
    {
        // Check for duplicate manifest number
        $existing = $this->entityManager->getRepository(Manifest::class)
            ->findOneBy(['manifestNumber' => $data['manifestNumber']]);
        
        if ($existing) {
            throw new \InvalidArgumentException('Manifest number already exists');
        }

        // Get shipping line from SL_STAFF user
        $shippingLine = $slStaff->getShippingLineScope();
        if (!$shippingLine) {
            throw new \InvalidArgumentException('User is not associated with a shipping line');
        }

        $manifest = new Manifest();
        $manifest->setManifestNumber($data['manifestNumber']);
        $manifest->setVesselName($data['vesselName'] ?? null);
        $manifest->setVoyageNumber($data['voyageNumber'] ?? null);
        
        if (isset($data['arrivalDate'])) {
            $manifest->setArrivalDate(new \DateTime($data['arrivalDate']));
        }
        
        $manifest->setCreatedBy($slStaff);
        $manifest->setShippingLine($shippingLine);
        $manifest->setWorkflowState(WorkflowState::MANIFEST_UPLOADED);

        $this->entityManager->persist($manifest);
        $this->entityManager->flush();

        // Log the upload
        $this->auditService->logAction(
            $slStaff,
            'manifest_upload',
            'Manifest',
            $manifest->getId(),
            [
                'manifest_number' => $manifest->getManifestNumber(),
                'shipping_line_id' => $shippingLine->getId(),
                'workflow_state' => WorkflowState::MANIFEST_UPLOADED->value
            ]
        );

        // Log to activity log for notifications
        $this->activityLogService->logManifestUpload(
            $slStaff,
            $manifest,
            $data['filename'] ?? 'manifest.xlsx'
        );

        return $manifest;
    }

    public function createManifestWithEDO(array $data, User $broker): Manifest
    {
        // Validate required fields
        if (!isset($data['noaId'])) {
            throw new ManifestValidationException('NOA ID is required');
        }
        if (!isset($data['blNumber'])) {
            throw new ManifestValidationException('BL number is required');
        }
        if (!isset($data['blFilePath'])) {
            throw new ManifestValidationException('BL file is required');
        }

        // Get NOA
        $noa = $this->entityManager->getRepository(\App\Entity\NOA::class)->find($data['noaId']);
        if (!$noa) {
            throw new ManifestValidationException('NOA not found');
        }

        // Validate BL number matches NOA BL number
        if ($data['blNumber'] !== $noa->getBlNumber()) {
            throw new ManifestValidationException('BL number does not match NOA BL number');
        }

        // Check for duplicate manifest number if provided
        if (isset($data['manifestNumber'])) {
            $existing = $this->entityManager->getRepository(Manifest::class)
                ->findOneBy(['manifestNumber' => $data['manifestNumber']]);
            
            if ($existing) {
                throw new ManifestValidationException('Manifest number already exists');
            }
        }

        // Get shipping line from broker's scope or NOA
        $shippingLine = $broker->getShippingLineScope();
        if (!$shippingLine) {
            throw new ManifestValidationException('Broker is not associated with a shipping line');
        }

        // Create manifest
        $manifest = new Manifest();
        $manifest->setManifestNumber($data['manifestNumber'] ?? $this->generateManifestNumber());
        $manifest->setBlNumber($data['blNumber']);
        $manifest->setBlFilePath($data['blFilePath']);
        $manifest->setArrivalDate($noa->getEta()); // Confirm exact date from NOA
        $manifest->setVesselName($noa->getVesselNumber());
        $manifest->setConsignee($noa->getConsignee());
        $manifest->setCreatedBy($broker);
        $manifest->setShippingLine($shippingLine);
        $manifest->setNoa($noa); // Link to NOA
        $manifest->setWorkflowState(WorkflowState::MANIFEST_UPLOADED);

        $this->entityManager->persist($manifest);
        $this->entityManager->flush();

        // Generate eDOs for all containers in the NOA
        if ($this->edoGenerationService) {
            try {
                $edos = $this->edoGenerationService->generateEDOsForManifest($manifest);
                
                // Log eDO generation
                $this->auditService->logAction(
                    $broker,
                    'edos_generated',
                    'Manifest',
                    $manifest->getId(),
                    [
                        'manifest_number' => $manifest->getManifestNumber(),
                        'noa_id' => $noa->getId(),
                        'edo_count' => count($edos)
                    ]
                );
            } catch (\Exception $e) {
                // If eDO generation fails, rollback manifest creation
                $this->entityManager->remove($manifest);
                $this->entityManager->flush();
                throw new \RuntimeException('Failed to generate eDOs: ' . $e->getMessage(), 0, $e);
            }
        }

        // Log the manifest creation
        $this->auditService->logAction(
            $broker,
            'manifest_created_with_edo',
            'Manifest',
            $manifest->getId(),
            [
                'manifest_number' => $manifest->getManifestNumber(),
                'noa_id' => $noa->getId(),
                'bl_number' => $data['blNumber'],
                'shipping_line_id' => $shippingLine->getId()
            ]
        );

        return $manifest;
    }

    /**
     * Generate a unique manifest number
     */
    private function generateManifestNumber(): string
    {
        $date = (new \DateTime())->format('Ymd');
        $random = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
        return sprintf('MAN-%s-%s', $date, $random);
    }

    public function declareConsignee(int $manifestId, int $consigneeId, User $slStaff): void
    {
        $manifest = $this->getManifestById($manifestId);
        if (!$manifest) {
            throw new \InvalidArgumentException('Manifest not found');
        }

        $consignee = $this->entityManager->getRepository(Consignee::class)->find($consigneeId);
        if (!$consignee) {
            throw new \InvalidArgumentException('Consignee not found');
        }

        $manifest->setConsignee($consignee);

        // NOTE: Broker is NOT auto-assigned anymore
        // Consignee must explicitly assign a broker from their approved list
        // This ensures proper access control and workflow

        $this->entityManager->flush();

        // Log the consignee declaration
        $this->auditService->logAction(
            $slStaff,
            'consignee_declaration',
            'Manifest',
            $manifest->getId(),
            [
                'consignee_id' => $consigneeId,
                'consignee_name' => $consignee->getBusinessName()
            ]
        );

        // Log to activity log for notifications
        $this->activityLogService->logManifestConsigneeDeclaration(
            $slStaff,
            $manifest,
            [
                [
                    'id' => $consigneeId,
                    'email' => $consignee->getEmail(),
                    'business_name' => $consignee->getBusinessName()
                ]
            ]
        );

        // Send notifications to consignee and broker
        try {
            error_log('DEBUG: About to call notifyConsigneeDeclared for manifest ID: ' . $manifest->getId());
            $this->notificationService->notifyConsigneeDeclared($manifest);
            error_log('DEBUG: Finished calling notifyConsigneeDeclared');
        } catch (\Exception $e) {
            // Log notification error but don't fail the entire operation
            error_log('ERROR: Failed to send consignee declaration notifications: ' . $e->getMessage());
            // Optionally log to a monitoring service
        }
    }

    public function getManifestById(int $id): ?Manifest
    {
        /** @var \App\Repository\ManifestRepository $repo */
        $repo = $this->entityManager->getRepository(Manifest::class);
        return $repo->findWithRelations($id);
    }

    public function getPrimaryManifestForNoa(NOA $noa): ?Manifest
    {
        /** @var \App\Repository\ManifestRepository $repo */
        $repo = $this->entityManager->getRepository(Manifest::class);

        return $repo->findPrimaryForNoa($noa);
    }

    /**
     * Get manifest by ID with minimal relations (optimized for BL upload page)
     * 10x faster than getManifestById() - only loads consignee and broker
     */
    public function getManifestForBLUpload(int $id): ?Manifest
    {
        /** @var \App\Repository\ManifestRepository $repo */
        $repo = $this->entityManager->getRepository(Manifest::class);
        return $repo->findForBLUpload($id);
    }

    public function getManifestByBlNumber(string $blNumber): ?Manifest
    {
        return $this->entityManager->getRepository(Manifest::class)
            ->findOneBy(['blNumber' => $blNumber]);
    }

    public function canViewManifest(Manifest $manifest, User $user): bool
    {
        // SL_STAFF and SYSTEM_ADMIN can always view
        $role = $user->getRole()->value;
        if (in_array($role, ['SL_STAFF', 'SYSTEM_ADMIN', 'ACCOUNTING'])) {
            return true;
        }

        // Broker and Consignee can view if they are associated with the manifest
        // and consignee has been declared (immediate access after consignee declaration)
        if ($role === 'BROKER' && $manifest->getBroker()?->getId() === $user->getId()) {
            return $manifest->getConsignee() !== null;
        }

        if ($role === 'CONSIGNEE' && $manifest->getConsignee()?->getId() === $user->getId()) {
            return true;
        }

        return false;
    }

    public function transitionState(Manifest $manifest, WorkflowState $newState, User $actor): void
    {
        // Delegate to WorkflowOrchestrator for state transition with history logging and notifications
        $this->workflowOrchestrator->transitionState($manifest, $newState, $actor);
        $this->entityManager->flush();
    }

    public function recordNoaGeneratedWorkflow(Manifest $manifest, User $actor, ?string $reason = null): void
    {
        $this->workflowOrchestrator->recordNoaGeneratedWorkflow($manifest, $actor, $reason);
        $this->entityManager->flush();
    }

    public function recordBlGeneratedWorkflow(Manifest $manifest, User $actor, ?string $reason = null): void
    {
        $this->workflowOrchestrator->recordBlGeneratedWorkflow($manifest, $actor, $reason);
        $this->entityManager->flush();
    }
}
