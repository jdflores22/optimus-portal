<?php

namespace App\Security\Voter;

use App\Entity\NOA;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for NOA (Notice of Arrival) access control
 * 
 * Requirement 12.1: Restrict NOA creation to Shipping_Lines_Terminal_Team
 */
class NOAVoter extends Voter
{
    public const CREATE = 'create';
    public const VIEW = 'view';
    public const EDIT = 'edit';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // CREATE doesn't need a subject
        if ($attribute === self::CREATE) {
            return $subject === null || $subject === 'NOA';
        }

        return in_array($attribute, [self::VIEW, self::EDIT])
            && $subject instanceof NOA;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::CREATE => $this->canCreate($user),
            self::VIEW => $this->canView($subject, $user),
            self::EDIT => $this->canEdit($subject, $user),
            default => false,
        };
    }

    /**
     * Check if user can create NOA
     * 
     * Requirement 12.1: Only Shipping_Lines_Terminal_Team and SL_STAFF can create NOAs
     */
    private function canCreate(User $user): bool
    {
        // TERMINAL_TEAM and SL_STAFF can create NOAs (Requirement 12.1)
        return in_array($user->getRole()->value, ['TERMINAL_TEAM', 'SL_STAFF']);
    }

    /**
     * Check if user can view NOA
     */
    private function canView(?NOA $noa, User $user): bool
    {
        $role = $user->getRole()->value;

        // Shipping line staff, accounting, and admins can view all NOAs
        if (in_array($role, ['SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN', 'EVALUATOR', 'SL_STAFF', 'TERMINAL_TEAM', 'ACCOUNTING'])) {
            return true;
        }

        // Consignees can view their own NOAs
        if ($role === 'CONSIGNEE' && $noa && $noa->getConsignee() === $user) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can edit NOA
     */
    private function canEdit(?NOA $noa, User $user): bool
    {
        // Shipping line terminal team and SL_STAFF can edit NOAs
        return in_array($user->getRole()->value, ['TERMINAL_TEAM', 'SL_STAFF']);
    }
}
