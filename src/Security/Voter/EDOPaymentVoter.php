<?php

namespace App\Security\Voter;

use App\Entity\EDOPayment;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for EDO Payment access control
 * 
 * Requirements: 15.2, 15.3
 */
class EDOPaymentVoter extends Voter
{
    public const APPROVE = 'approve';
    public const REJECT = 'reject';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::APPROVE, self::REJECT])
            && $subject instanceof EDOPayment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var EDOPayment $payment */
        $payment = $subject;

        return match ($attribute) {
            self::APPROVE => $this->canApprove($payment, $user),
            self::REJECT => $this->canReject($payment, $user),
            default => false,
        };
    }

    /**
     * Check if user can approve the payment
     * 
     * Requirement 15.2: Only System_Admin can approve eDO payments
     */
    private function canApprove(EDOPayment $payment, User $user): bool
    {
        // Only System_Admin can approve payments (Requirement 15.2)
        return $user->getRole()->value === 'SYSTEM_ADMIN';
    }

    /**
     * Check if user can reject the payment
     * 
     * Requirement 15.3: Only System_Admin can reject eDO payments
     */
    private function canReject(EDOPayment $payment, User $user): bool
    {
        // Only System_Admin can reject payments (Requirement 15.3)
        return $user->getRole()->value === 'SYSTEM_ADMIN';
    }
}
