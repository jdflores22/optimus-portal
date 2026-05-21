<?php

namespace App\Security\Voter;

use App\Entity\EDOPaymentReceipt;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for eDO Payment Receipt access control
 * 
 * Requirement 12.4: Restrict payment confirmation to Shipping_Lines_Accounting
 */
class PaymentVoter extends Voter
{
    public const CONFIRM = 'confirm';
    public const REJECT = 'reject';
    public const VIEW = 'view';
    public const SUBMIT = 'submit';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // SUBMIT can work without a subject
        if ($attribute === self::SUBMIT) {
            return $subject === null || $subject === 'Payment';
        }

        return in_array($attribute, [self::CONFIRM, self::REJECT, self::VIEW])
            && $subject instanceof EDOPaymentReceipt;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::CONFIRM => $this->canConfirm($subject, $user),
            self::REJECT => $this->canReject($subject, $user),
            self::VIEW => $this->canView($subject, $user),
            self::SUBMIT => $this->canSubmit($user),
            default => false,
        };
    }

    /**
     * Check if user can confirm payment
     * 
     * Requirement 12.4: Only Shipping_Lines_Accounting can confirm payments
     */
    private function canConfirm(?EDOPaymentReceipt $payment, User $user): bool
    {
        // Only ACCOUNTING can confirm payments (Requirement 12.4)
        return $user->getRole()->value === 'ACCOUNTING';
    }

    /**
     * Check if user can reject payment
     * 
     * Requirement 12.4: Only Shipping_Lines_Accounting can reject payments
     */
    private function canReject(?EDOPaymentReceipt $payment, User $user): bool
    {
        // Only ACCOUNTING can reject payments (Requirement 12.4)
        return $user->getRole()->value === 'ACCOUNTING';
    }

    /**
     * Check if user can view payment receipt
     */
    private function canView(?EDOPaymentReceipt $payment, User $user): bool
    {
        $role = $user->getRole()->value;

        // Shipping line accounting and admins can view all payments
        if (in_array($role, ['SYSTEM_ADMIN', 'ACCOUNTING'])) {
            return true;
        }

        // Consignees and Brokers can view their own payment receipts
        if ($payment) {
            $edo = $payment->getBilling()->getRegenerationRequest()->getEdo();
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

    /**
     * Check if user can submit payment receipt
     */
    private function canSubmit(User $user): bool
    {
        $role = $user->getRole()->value;

        // Brokers and Consignees can submit payment receipts
        return in_array($role, ['BROKER', 'CONSIGNEE']);
    }
}
