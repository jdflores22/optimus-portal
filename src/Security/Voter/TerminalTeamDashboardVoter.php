<?php

namespace App\Security\Voter;

use App\Entity\TerminalTeamUser;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class TerminalTeamDashboardVoter extends Voter
{
    public const ACCESS_DASHBOARD = 'access_dashboard';
    public const VIEW_PENDING_REQUESTS = 'view_pending_requests';
    public const VIEW_STATISTICS = 'view_statistics';
    public const EXPORT_REPORTS = 'export_reports';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::ACCESS_DASHBOARD,
            self::VIEW_PENDING_REQUESTS,
            self::VIEW_STATISTICS,
            self::EXPORT_REPORTS
        ]) && $subject === null; // Dashboard permissions don't need a specific subject
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof TerminalTeamUser) {
            return false;
        }

        return match ($attribute) {
            self::ACCESS_DASHBOARD => $this->canAccessDashboard($user),
            self::VIEW_PENDING_REQUESTS => $this->canViewPendingRequests($user),
            self::VIEW_STATISTICS => $this->canViewStatistics($user),
            self::EXPORT_REPORTS => $this->canExportReports($user),
            default => false,
        };
    }

    private function canAccessDashboard(TerminalTeamUser $user): bool
    {
        // All Terminal Team users can access the dashboard
        return true;
    }

    private function canViewPendingRequests(TerminalTeamUser $user): bool
    {
        // Check if user has permission to view pending requests
        return $user->hasTerminalPermission('view_pending_requests');
    }

    private function canViewStatistics(TerminalTeamUser $user): bool
    {
        // Check if user has permission to view statistics
        return $user->hasTerminalPermission('view_statistics');
    }

    private function canExportReports(TerminalTeamUser $user): bool
    {
        // Check if user has permission to export reports
        return $user->hasTerminalPermission('export_reports');
    }
}