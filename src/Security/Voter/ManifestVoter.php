<?php

namespace App\Security\Voter;

use App\Entity\Manifest;
use App\Entity\User;
use App\Repository\ConsigneeBrokerRelationshipRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Voter for Manifest access control with workspace context
 */
class ManifestVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const UPLOAD_BL = 'upload_bl';
    public const SUBMIT_PAYMENT = 'submit_payment';
    public const TRANSFER = 'transfer';
    public const CREATE = 'create';

    public function __construct(
        private ConsigneeBrokerRelationshipRepository $relationshipRepository,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // CREATE doesn't need a subject
        if ($attribute === self::CREATE) {
            return $subject === null || $subject === 'Manifest';
        }

        return in_array($attribute, [
            self::VIEW,
            self::EDIT,
            self::UPLOAD_BL,
            self::SUBMIT_PAYMENT,
            self::TRANSFER
        ]) && $subject instanceof Manifest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Manifest $manifest */
        $manifest = $subject;

        return match ($attribute) {
            self::CREATE => $this->canCreate($user),
            self::VIEW => $this->canView($manifest, $user),
            self::EDIT => $this->canEdit($manifest, $user),
            self::UPLOAD_BL => $this->canUploadBL($manifest, $user),
            self::SUBMIT_PAYMENT => $this->canSubmitPayment($manifest, $user),
            self::TRANSFER => $this->canTransfer($manifest, $user),
            default => false,
        };
    }

    /**
     * Check if user can create Manifest
     * 
     * Requirement 12.2: Only Broker role can create Manifests
     */
    private function canCreate(User $user): bool
    {
        // Only Brokers can create Manifests (Requirement 12.2)
        return $user->getRole()->value === 'BROKER';
    }

    private function canView(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // System admins and shipping line staff can view all manifests
        if (in_array($role, ['SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN', 'SL_STAFF', 'EVALUATOR', 'ACCOUNTING'])) {
            return true;
        }

        // Consignees can view their own manifests
        if ($role === 'CONSIGNEE' && $manifest->getConsignee() === $user) {
            return true;
        }

        // Brokers can view manifests in their active workspace
        if ($role === 'BROKER') {
            return $this->canBrokerAccessManifest($manifest, $user);
        }

        return false;
    }

    private function canEdit(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only brokers and shipping line staff can edit manifests
        if ($role === 'SL_STAFF' || $role === 'SHIPPING_LINES_ADMIN') {
            return true;
        }

        // Brokers can edit manifests in their active workspace
        if ($role === 'BROKER') {
            // Check if broker is assigned to this manifest
            if ($manifest->getBroker() !== $user) {
                $this->logger->warning('Broker attempted to edit manifest not assigned to them', [
                    'user_id' => $user->getId(),
                    'manifest_id' => $manifest->getId(),
                    'assigned_broker_id' => $manifest->getBroker()?->getId(),
                ]);
                return false;
            }

            // Check workspace context
            return $this->canBrokerAccessManifest($manifest, $user);
        }

        return false;
    }

    private function canUploadBL(Manifest $manifest, User $user): bool
    {
        // Same rules as edit
        return $this->canEdit($manifest, $user);
    }

    private function canSubmitPayment(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Consignees can submit payment for their own manifests
        if ($role === 'CONSIGNEE' && $manifest->getConsignee() === $user) {
            return true;
        }

        // Brokers can submit payment for manifests in their active workspace
        if ($role === 'BROKER') {
            if ($manifest->getBroker() !== $user) {
                return false;
            }

            return $this->canBrokerAccessManifest($manifest, $user);
        }

        return false;
    }

    private function canTransfer(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only consignees can request transfers for their own manifests
        if ($role === 'CONSIGNEE' && $manifest->getConsignee() === $user) {
            return true;
        }

        // SL_STAFF can approve/reject transfers
        if ($role === 'SL_STAFF' || $role === 'SHIPPING_LINES_ADMIN') {
            return true;
        }

        return false;
    }

    /**
     * Check if broker can access manifest based on workspace context
     */
    private function canBrokerAccessManifest(Manifest $manifest, User $broker): bool
    {
        // CRITICAL: Broker must be explicitly assigned to this manifest
        if ($manifest->getBroker() !== $broker) {
            $this->logger->warning('Broker attempted to access manifest not assigned to them', [
                'user_id' => $broker->getId(),
                'manifest_id' => $manifest->getId(),
                'assigned_broker_id' => $manifest->getBroker()?->getId(),
            ]);
            return false;
        }

        // Get active workspace from session
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            $this->logger->error('No current request available for workspace check');
            return false;
        }

        $session = $request->getSession();
        $activeWorkspaceId = $session->get('active_workspace_consignee_id');

        if (!$activeWorkspaceId) {
            $this->logger->warning('Broker attempted to access manifest without active workspace', [
                'user_id' => $broker->getId(),
                'manifest_id' => $manifest->getId(),
            ]);
            return false;
        }

        // Check if manifest belongs to the active workspace consignee
        $manifestConsignee = $manifest->getConsignee();
        if (!$manifestConsignee || $manifestConsignee->getId() !== $activeWorkspaceId) {
            $this->logger->warning('Broker attempted to access manifest outside active workspace', [
                'user_id' => $broker->getId(),
                'manifest_id' => $manifest->getId(),
                'active_workspace_id' => $activeWorkspaceId,
                'manifest_consignee_id' => $manifestConsignee?->getId(),
            ]);
            return false;
        }

        // Verify active relationship exists
        $relationship = $this->relationshipRepository->findActiveRelationship($manifestConsignee, $broker);
        if (!$relationship) {
            $this->logger->warning('Broker attempted to access manifest without active relationship', [
                'user_id' => $broker->getId(),
                'manifest_id' => $manifest->getId(),
                'consignee_id' => $manifestConsignee->getId(),
            ]);
            return false;
        }

        return true;
    }
}
