<?php

namespace App\Security\Voter;

use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Payment History access control
 * 
 * This voter handles authorization for:
 * - Viewing payment history for a manifest (all versions)
 * - Viewing individual payment records (including all payment versions)
 * 
 * Authorization Rules:
 * - Brokers can view payment history and all payment versions for their own manifests
 * - Accounting staff can view all payment history and all payment versions
 * - Consignees can view payment history and all payment versions for their manifests
 * - System admins have full access
 * 
 * Note: Payment versions are Payment entities with version numbers and previousPayment
 * relationships. This voter applies the same authorization rules to all payment versions
 * as it does to the original payment, ensuring consistent access control across the
 * payment version chain.
 */
class PaymentHistoryVoter extends Voter
{
    public const VIEW_PAYMENT_HISTORY = 'view_payment_history';
    public const VIEW = 'view';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::VIEW_PAYMENT_HISTORY) {
            return $subject instanceof Manifest;
        }

        if ($attribute === self::VIEW) {
            return $subject instanceof Payment;
        }

        return false;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW_PAYMENT_HISTORY => $this->canViewPaymentHistory($subject, $user),
            self::VIEW => $this->canViewPayment($subject, $user),
            default => false,
        };
    }

    /**
     * Check if user can view payment history for a manifest
     * 
     * @param Manifest $manifest
     * @param User $user
     * @return bool
     */
    private function canViewPaymentHistory(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Accounting and admins can view all payment history
        if (in_array($role, ['ACCOUNTING', 'SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN'])) {
            return true;
        }

        // Brokers can only view their own manifest's payment history
        if ($role === 'BROKER') {
            $broker = $manifest->getBroker();
            return $broker !== null && $broker->getId() === $user->getId();
        }

        // Consignees can view payment history for their manifests
        if ($role === 'CONSIGNEE') {
            $consignee = $manifest->getConsignee();
            return $consignee !== null && $consignee->getId() === $user->getId();
        }

        return false;
    }

    /**
     * Check if user can view a specific payment (including payment versions)
     * 
     * This method handles authorization for viewing any payment record, including
     * all payment versions in a version chain. The same authorization rules apply
     * to all versions of a payment.
     * 
     * @param Payment $payment The payment to check access for (can be any version)
     * @param User $user The user requesting access
     * @return bool True if user can view the payment, false otherwise
     */
    private function canViewPayment(Payment $payment, User $user): bool
    {
        $role = $user->getRole()->value;
        $manifest = $payment->getManifest();

        // Accounting and admins can view all payments
        if (in_array($role, ['ACCOUNTING', 'SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN'])) {
            return true;
        }

        // Brokers can view payments for their own manifests
        if ($role === 'BROKER') {
            $broker = $manifest->getBroker();
            return $broker !== null && $broker->getId() === $user->getId();
        }

        // Consignees can view payments for their manifests
        if ($role === 'CONSIGNEE') {
            $consignee = $manifest->getConsignee();
            return $consignee !== null && $consignee->getId() === $user->getId();
        }

        return false;
    }
}
