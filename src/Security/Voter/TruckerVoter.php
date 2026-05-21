<?php

namespace App\Security\Voter;

use App\Entity\PreAdviceRequest;
use App\Entity\Trucker;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Trucker specific permissions
 */
class TruckerVoter extends Voter
{
    public const VIEW_OWN_PRE_ADVICE = 'view_own_pre_advice';
    public const CREATE_PRE_ADVICE = 'create_pre_advice';
    public const UPLOAD_PHOTOS = 'upload_photos';
    public const DOWNLOAD_EDO = 'download_edo';
    public const VIEW_DASHBOARD = 'view_trucker_dashboard';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW_OWN_PRE_ADVICE,
            self::CREATE_PRE_ADVICE,
            self::UPLOAD_PHOTOS,
            self::DOWNLOAD_EDO,
            self::VIEW_DASHBOARD
        ]) && (
            $subject instanceof PreAdviceRequest ||
            $subject === null // For dashboard access or general permissions
        );
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        // User must be a Trucker
        if (!$user instanceof Trucker) {
            return false;
        }

        return match ($attribute) {
            self::VIEW_DASHBOARD => $this->canViewDashboard($user),
            self::CREATE_PRE_ADVICE => $this->canCreatePreAdvice($user),
            self::VIEW_OWN_PRE_ADVICE => $this->canViewOwnPreAdvice($user, $subject),
            self::UPLOAD_PHOTOS => $this->canUploadPhotos($user, $subject),
            self::DOWNLOAD_EDO => $this->canDownloadEdo($user, $subject),
            default => false
        };
    }

    private function canViewDashboard(Trucker $user): bool
    {
        // All authenticated truckers can view their dashboard
        return true;
    }

    private function canCreatePreAdvice(Trucker $user): bool
    {
        // All authenticated truckers can create pre-advice requests
        return true;
    }

    private function canViewOwnPreAdvice(Trucker $user, ?PreAdviceRequest $preAdvice): bool
    {
        if (!$preAdvice) {
            return true; // Can view list of own pre-advice requests
        }

        // Can only view own pre-advice requests
        return $preAdvice->getTrucker() === $user;
    }

    private function canUploadPhotos(Trucker $user, ?PreAdviceRequest $preAdvice): bool
    {
        if (!$preAdvice) {
            return false;
        }

        // Can only upload photos to own pre-advice requests
        if ($preAdvice->getTrucker() !== $user) {
            return false;
        }

        // Can only upload photos to pending requests
        return $preAdvice->getStatus()->value === 'pending';
    }

    private function canDownloadEdo(Trucker $user, ?PreAdviceRequest $preAdvice): bool
    {
        if (!$preAdvice) {
            return false;
        }

        // Can only download EDO for own pre-advice requests
        if ($preAdvice->getTrucker() !== $user) {
            return false;
        }

        // Can only download EDO for completed requests
        return $preAdvice->getStatus()->value === 'completed' && $preAdvice->getEdoNumber();
    }
}