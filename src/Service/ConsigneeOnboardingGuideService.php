<?php

namespace App\Service;

use App\Entity\Consignee;

class ConsigneeOnboardingGuideService
{
    /** @return string[] */
    public function getDashboardSteps(Consignee $user, int $accreditationCount, int $approvedBrokerCount): array
    {
        $steps = [];

        if ($accreditationCount === 0 && !$user->hasCompletedGuideStep('submit_accreditation')) {
            $steps[] = 'submit_accreditation';
        }

        if ($approvedBrokerCount === 0 && !$user->hasCompletedGuideStep('link_brokers')) {
            $steps[] = 'link_brokers';
        }

        return $steps;
    }

    /** @return string[] */
    public function getReferralCodeSteps(Consignee $user): array
    {
        $steps = [];

        if (!$user->hasCompletedGuideStep('generate_new_code')) {
            $steps[] = 'generate_new_code';
        }

        if (!$user->hasCompletedGuideStep('generate_referral_code_modal')) {
            $steps[] = 'generate_referral_code_modal';
        }

        return $steps;
    }

    /** @return string[] */
    public function getManifestListSteps(Consignee $user): array
    {
        $steps = [];

        if (!$user->hasCompletedGuideStep('view_manifest_list')) {
            $steps[] = 'view_manifest_list';
        }

        return $steps;
    }

    public function shouldShowGuideSuccessModal(Consignee $user): bool
    {
        if ($user->hasCompletedGuideStep('guide_tour_success')) {
            return false;
        }

        return $user->hasCompletedGuideStep('view_manifest_list');
    }
}
