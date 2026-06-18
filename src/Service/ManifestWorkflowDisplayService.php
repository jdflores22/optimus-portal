<?php

namespace App\Service;

use App\Entity\Enum\WorkflowState;
use App\Entity\Manifest;
use App\Entity\NOA;
use App\Entity\User;

class ManifestWorkflowDisplayService
{
    /** Roles that use /manifest-workflow/noa/{id} as the centralized workflow hub. */
    private const WORKFLOW_HUB_ROLES = [
        'SYSTEM_ADMIN',
        'SHIPPING_LINES_ADMIN',
        'EVALUATOR',
        'SL_STAFF',
        'TERMINAL_TEAM',
        'ACCOUNTING',
    ];
    public const TOTAL_STEPS = 8;

    public const ORDER_TEXT = '1. Create NOA → 2. Generate BL → 3. Upload BL → 4. Generate Billing → 5. Submit Payment → 6. Validate Payment → 7. Generate eDO → 8. Release eDO';

    /**
     * Returns the highest completed workflow step (1–8).
     * When no manifest exists yet, step 1 (NOA created) is considered complete.
     */
    public function getCurrentStep(?Manifest $manifest): int
    {
        if ($manifest === null) {
            return 1;
        }

        return match ($manifest->getWorkflowState()) {
            WorkflowState::MANIFEST_UPLOADED, WorkflowState::NOA_GENERATED => 1,
            WorkflowState::BL_GENERATED => 2,
            WorkflowState::BL_UPLOADED => 3,
            WorkflowState::BILLING_GENERATED => 4,
            WorkflowState::PAYMENT_SUBMITTED => 5,
            WorkflowState::PAYMENT_VERIFIED => 6,
            WorkflowState::EDO_GENERATED => 7,
            WorkflowState::EDO_RELEASED => 8,
        };
    }

    public function isComplete(?Manifest $manifest): bool
    {
        return $this->getCurrentStep($manifest) >= self::TOTAL_STEPS;
    }

    public function usesNoaDetailAsWorkflowHub(User $user): bool
    {
        return in_array($user->getRole()->value, self::WORKFLOW_HUB_ROLES, true);
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    public function resolveWorkflowDetailRoute(Manifest $manifest, User $user): array
    {
        $noa = $manifest->getNoa();
        if ($noa instanceof NOA && $this->usesNoaDetailAsWorkflowHub($user)) {
            return ['manifest_workflow_noa_detail', ['id' => $noa->getId()]];
        }

        return ['manifest_workflow_detail', ['id' => $manifest->getId()]];
    }

    public function shouldRedirectManifestDetailToNoa(Manifest $manifest, User $user): bool
    {
        if (!$manifest->getNoa() instanceof NOA) {
            return false;
        }

        return $this->usesNoaDetailAsWorkflowHub($user);
    }
}
