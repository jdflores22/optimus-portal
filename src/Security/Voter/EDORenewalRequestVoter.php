<?php

namespace App\Security\Voter;

use App\Entity\EDORenewalRequest;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for EDO Renewal Request access control
 * 
 * Requirements: 9.1, 9.2, 9.3, 14.4
 */
class EDORenewalRequestVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const GENERATE_EDO = 'generate_edo';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::GENERATE_EDO])
            && $subject instanceof EDORenewalRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var EDORenewalRequest $renewalRequest */
        $renewalRequest = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($renewalRequest, $user),
            self::EDIT => $this->canEdit($renewalRequest, $user),
            self::GENERATE_EDO => $this->canGenerateEDO($renewalRequest, $user),
            default => false,
        };
    }

    /**
     * Check if user can view the renewal request
     * 
     * Requirement 14.4: Brokers can view their own renewal requests
     * SL Staff can view all renewal requests for their shipping line
     */
    private function canView(EDORenewalRequest $renewalRequest, User $user): bool
    {
        $role = $user->getRole()->value;

        // System admins, shipping line staff, and accounting can view all renewal requests
        if (in_array($role, ['SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN', 'SL_STAFF', 'ACCOUNTING'])) {
            return true;
        }

        // Brokers can view their own renewal requests (Requirement 14.4)
        if ($role === 'BROKER' && $renewalRequest->getRequestedBy() === $user) {
            return true;
        }

        // Consignees can view renewal requests for their eDOs
        $expiredEdo = $renewalRequest->getExpiredEdo();
        if ($role === 'CONSIGNEE' && $expiredEdo->getManifest()->getConsignee() && 
            $expiredEdo->getManifest()->getConsignee()->getUser() === $user) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can edit the renewal request
     * 
     * Only SL Staff can edit renewal requests
     */
    private function canEdit(EDORenewalRequest $renewalRequest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only shipping line staff can edit renewal requests
        return in_array($role, ['SHIPPING_LINES_ADMIN', 'SL_STAFF']);
    }

    /**
     * Check if user can generate a new eDO from the renewal request
     * 
     * Requirement 9.1: Only SL Staff can generate new eDOs
     * Requirement 9.2: Payment must be verified if detention charges are required
     * Requirement 9.3: Request must be in appropriate status
     */
    private function canGenerateEDO(EDORenewalRequest $renewalRequest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only SL Staff can generate eDOs (Requirement 9.1)
        if (!in_array($role, ['SHIPPING_LINES_ADMIN', 'SL_STAFF'])) {
            return false;
        }

        // Check if new eDO already generated
        if ($renewalRequest->getNewEdo() !== null) {
            return false;
        }

        // Verify shipping line matches
        $expiredEdo = $renewalRequest->getExpiredEdo();
        $userShippingLine = $user->getShippingLineScope();
        
        if (!$userShippingLine || $expiredEdo->getShippingLine()->getId() !== $userShippingLine->getId()) {
            return false;
        }

        // If detention charges are required, payment must be verified (Requirement 9.2)
        if ($renewalRequest->getDetentionChargeAmount() > 0 && !$renewalRequest->isPaymentVerified()) {
            return false;
        }

        // Request must be in appropriate status (Requirement 9.3)
        $status = $renewalRequest->getStatus()->value;
        $allowedStatuses = ['payment_verified', 'ready_for_generation', 'pending_review'];
        
        // If no charges required, can generate from pending_review
        if ($renewalRequest->getDetentionChargeAmount() == 0 && $status === 'pending_review') {
            return true;
        }

        // If charges required, must be payment_verified or ready_for_generation
        return in_array($status, $allowedStatuses);
    }
}
