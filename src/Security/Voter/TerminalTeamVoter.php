<?php

namespace App\Security\Voter;

use App\Entity\PreAdviceRequest;
use App\Entity\TerminalTeamUser;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Terminal Team specific permissions
 */
class TerminalTeamVoter extends Voter
{
    public const VIEW_PRE_ADVICE = 'view_pre_advice';
    public const VERIFY_PRE_ADVICE = 'verify_pre_advice';
    public const REJECT_PRE_ADVICE = 'reject_pre_advice';
    public const GENERATE_EDO = 'generate_edo';
    public const MANAGE_SLOTS = 'manage_slots';
    public const VIEW_DASHBOARD = 'view_terminal_dashboard';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW_PRE_ADVICE,
            self::VERIFY_PRE_ADVICE,
            self::REJECT_PRE_ADVICE,
            self::GENERATE_EDO,
            self::MANAGE_SLOTS,
            self::VIEW_DASHBOARD
        ]) && (
            $subject instanceof PreAdviceRequest ||
            $subject === null // For dashboard access
        );
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        // User must be a Terminal Team member
        if (!$user instanceof TerminalTeamUser) {
            return false;
        }

        return match ($attribute) {
            self::VIEW_DASHBOARD => $this->canViewDashboard($user),
            self::VIEW_PRE_ADVICE => $this->canViewPreAdvice($user, $subject),
            self::VERIFY_PRE_ADVICE => $this->canVerifyPreAdvice($user, $subject),
            self::REJECT_PRE_ADVICE => $this->canRejectPreAdvice($user, $subject),
            self::GENERATE_EDO => $this->canGenerateEdo($user, $subject),
            self::MANAGE_SLOTS => $this->canManageSlots($user),
            default => false
        };
    }

    private function canViewDashboard(TerminalTeamUser $user): bool
    {
        // All Terminal Team users can view the dashboard
        return true;
    }

    private function canViewPreAdvice(TerminalTeamUser $user, ?PreAdviceRequest $preAdvice): bool
    {
        if (!$preAdvice) {
            return true; // Can view list of pre-advice requests
        }

        // Check if user has permission for the specific terminal
        $terminal = $preAdvice->getSelectedTerminal();
        if (!$terminal) {
            return true; // No terminal restriction
        }

        // If user has specific terminal permissions, check them
        $terminalPermissions = $user->getTerminalPermissions();
        if (empty($terminalPermissions)) {
            return true; // No restrictions, can view all
        }

        return $user->hasTerminalPermission($terminal->getType()->value);
    }

    private function canVerifyPreAdvice(TerminalTeamUser $user, ?PreAdviceRequest $preAdvice): bool
    {
        if (!$preAdvice) {
            return false;
        }

        // Can only verify pending pre-advice requests
        if ($preAdvice->getStatus()->value !== 'pending') {
            return false;
        }

        // Check terminal permissions
        return $this->canViewPreAdvice($user, $preAdvice);
    }

    private function canRejectPreAdvice(TerminalTeamUser $user, ?PreAdviceRequest $preAdvice): bool
    {
        if (!$preAdvice) {
            return false;
        }

        // Can only reject pending pre-advice requests
        if ($preAdvice->getStatus()->value !== 'pending') {
            return false;
        }

        // Check terminal permissions
        return $this->canViewPreAdvice($user, $preAdvice);
    }

    private function canGenerateEdo(TerminalTeamUser $user, ?PreAdviceRequest $preAdvice): bool
    {
        if (!$preAdvice) {
            return false;
        }

        // Can only generate EDO for verified pre-advice requests
        if ($preAdvice->getStatus()->value !== 'verified') {
            return false;
        }

        // Check terminal permissions
        return $this->canViewPreAdvice($user, $preAdvice);
    }

    private function canManageSlots(TerminalTeamUser $user): bool
    {
        // All Terminal Team users can manage slots
        // Could be extended to check specific permissions if needed
        return true;
    }
}