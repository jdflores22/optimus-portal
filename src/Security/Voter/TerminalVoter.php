<?php

namespace App\Security\Voter;

use App\Entity\Terminal;
use App\Entity\TerminalTeamUser;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class TerminalVoter extends Voter
{
    public const VIEW = 'view';
    public const MANAGE_SLOTS = 'manage_slots';
    public const VIEW_METRICS = 'view_metrics';
    public const CONFIGURE = 'configure';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE_SLOTS, self::VIEW_METRICS, self::CONFIGURE])
            && $subject instanceof Terminal;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Terminal $terminal */
        $terminal = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($terminal, $user),
            self::MANAGE_SLOTS => $this->canManageSlots($terminal, $user),
            self::VIEW_METRICS => $this->canViewMetrics($terminal, $user),
            self::CONFIGURE => $this->canConfigure($terminal, $user),
            default => false,
        };
    }

    private function canView(Terminal $terminal, User $user): bool
    {
        // Terminal Team can view all terminals
        if ($user instanceof TerminalTeamUser) {
            return true;
        }

        return false;
    }

    private function canManageSlots(Terminal $terminal, User $user): bool
    {
        // Only Terminal Team can manage slots
        if (!$user instanceof TerminalTeamUser) {
            return false;
        }

        // Check if user has slot management permission
        return $user->hasTerminalPermission('manage_slots');
    }

    private function canViewMetrics(Terminal $terminal, User $user): bool
    {
        // Only Terminal Team can view metrics
        if (!$user instanceof TerminalTeamUser) {
            return false;
        }

        // Check if user has metrics viewing permission
        return $user->hasTerminalPermission('view_metrics');
    }

    private function canConfigure(Terminal $terminal, User $user): bool
    {
        // Only Terminal Team can configure terminals
        if (!$user instanceof TerminalTeamUser) {
            return false;
        }

        // Check if user has configuration permission
        return $user->hasTerminalPermission('configure_terminal');
    }
}