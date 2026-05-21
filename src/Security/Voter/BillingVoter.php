<?php

namespace App\Security\Voter;

use App\Entity\EDOBilling;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for eDO Billing access control
 * 
 * Requirement 12.3: Restrict billing generation to Shipping_Lines_Accounting
 */
class BillingVoter extends Voter
{
    public const GENERATE = 'generate';
    public const VIEW = 'view';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // GENERATE can work with or without a subject
        if ($attribute === self::GENERATE) {
            return $subject === null || $subject === 'Billing' || $subject instanceof EDOBilling;
        }

        return $attribute === self::VIEW && $subject instanceof EDOBilling;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::GENERATE => $this->canGenerate($user),
            self::VIEW => $this->canView($subject, $user),
            default => false,
        };
    }

    /**
     * Check if user can generate billing
     * 
     * Requirement 12.3: Only Shipping_Lines_Accounting can generate billing
     */
    private function canGenerate(User $user): bool
    {
        // Only ACCOUNTING can generate billing (Requirement 12.3)
        return $user->getRole()->value === 'ACCOUNTING';
    }

    /**
     * Check if user can view billing
     */
    private function canView(?EDOBilling $billing, User $user): bool
    {
        $role = $user->getRole()->value;

        // Shipping line accounting and admins can view all billings
        if (in_array($role, ['SYSTEM_ADMIN', 'ACCOUNTING'])) {
            return true;
        }

        // Consignees and Brokers can view billings for their eDOs
        if ($billing) {
            $edo = $billing->getRegenerationRequest()->getEdo();
            $manifest = $edo->getManifest();

            if ($role === 'CONSIGNEE' && $manifest->getConsignee() && $manifest->getConsignee()->getUser() === $user) {
                return true;
            }

            if ($role === 'BROKER' && $manifest->getBroker() === $user) {
                return true;
            }
        }

        return false;
    }
}
