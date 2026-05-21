<?php

namespace App\Security\Voter;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Electronic Delivery Order (eDO) access control
 * 
 * Requirements: 12.5, 12.6, 12.7, 12.8
 */
class EDOVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const UNLOCK = 'unlock';
    public const SUBMIT_PAYMENT = 'submit_payment';
    public const DOWNLOAD = 'download';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::UNLOCK, self::SUBMIT_PAYMENT, self::DOWNLOAD])
            && $subject instanceof ElectronicDeliveryOrder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var ElectronicDeliveryOrder $edo */
        $edo = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($edo, $user),
            self::EDIT => $this->canEdit($edo, $user),
            self::UNLOCK => $this->canUnlock($edo, $user),
            self::SUBMIT_PAYMENT => $this->canSubmitPayment($edo, $user),
            self::DOWNLOAD => $this->canDownload($edo, $user),
            default => false,
        };
    }

    /**
     * Check if user can view the eDO
     * 
     * Requirement 12.6: Consignee can view their own eDOs
     * Requirement 12.7: Broker can view eDOs for their assigned manifests
     */
    private function canView(ElectronicDeliveryOrder $edo, User $user): bool
    {
        $role = $user->getRole()->value;

        // System admins and shipping line staff can view all eDOs
        if (in_array($role, ['SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN', 'SL_STAFF', 'ACCOUNTING'])) {
            return true;
        }

        $manifest = $edo->getManifest();

        // Consignees can view their own eDOs (Requirement 12.6)
        if ($role === 'CONSIGNEE' && $manifest->getConsignee() && $manifest->getConsignee()->getUser() === $user) {
            return true;
        }

        // Brokers can view eDOs for their assigned manifests (Requirement 12.7)
        if ($role === 'BROKER' && $manifest->getBroker() === $user) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can edit the eDO
     * 
     * Only shipping line staff can edit eDOs
     */
    private function canEdit(ElectronicDeliveryOrder $edo, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only shipping line staff can edit eDOs
        return in_array($role, ['SHIPPING_LINES_ADMIN', 'SL_STAFF']);
    }

    /**
     * Check if user can unlock the eDO
     * 
     * Requirement 12.5: Only System_Admin can unlock eDOs
     */
    private function canUnlock(ElectronicDeliveryOrder $edo, User $user): bool
    {
        // Only System_Admin can unlock eDOs (Requirement 12.5)
        return $user->getRole()->value === 'SYSTEM_ADMIN';
    }

    /**
     * Check if user can submit payment for the eDO
     * 
     * Requirement 15.1: Only brokers can submit eDO payments
     * Requirement 15.5: Broker can only submit payment for eDOs from their manifests
     */
    private function canSubmitPayment(ElectronicDeliveryOrder $edo, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only brokers can submit payments (Requirement 15.1)
        if ($role !== 'BROKER') {
            return false;
        }

        $manifest = $edo->getManifest();

        // Broker must own the manifest (Requirement 15.5)
        return $manifest->getBroker() === $user;
    }

    /**
     * Check if user can download the eDO PDF
     * 
     * Requirement 15.5: Broker can download eDO only if they own the manifest AND eDO is RELEASED
     */
    private function canDownload(ElectronicDeliveryOrder $edo, User $user): bool
    {
        $role = $user->getRole()->value;

        // System admins and shipping line staff can download any eDO
        if (in_array($role, ['SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN', 'SL_STAFF', 'ACCOUNTING'])) {
            return true;
        }

        $manifest = $edo->getManifest();

        // Brokers can download only if they own the manifest AND eDO is RELEASED (Requirement 15.5)
        if ($role === 'BROKER') {
            return $manifest->getBroker() === $user && $edo->getStatus()->value === 'RELEASED';
        }

        // Consignees can download their own released eDOs
        if ($role === 'CONSIGNEE' && $manifest->getConsignee() && $manifest->getConsignee()->getUser() === $user) {
            return $edo->getStatus()->value === 'RELEASED';
        }

        return false;
    }
}
