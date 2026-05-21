<?php

namespace App\Security\Voter;

use App\Entity\PreAdviceRequest;
use App\Entity\TerminalTeamUser;
use App\Entity\Trucker;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PreAdviceVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const VERIFY = 'verify';
    public const REJECT = 'reject';
    public const GENERATE_EDO = 'generate_edo';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::VERIFY, self::REJECT, self::GENERATE_EDO])
            && $subject instanceof PreAdviceRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var PreAdviceRequest $preAdviceRequest */
        $preAdviceRequest = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($preAdviceRequest, $user),
            self::EDIT => $this->canEdit($preAdviceRequest, $user),
            self::VERIFY => $this->canVerify($preAdviceRequest, $user),
            self::REJECT => $this->canReject($preAdviceRequest, $user),
            self::GENERATE_EDO => $this->canGenerateEdo($preAdviceRequest, $user),
            default => false,
        };
    }

    private function canView(PreAdviceRequest $preAdviceRequest, User $user): bool
    {
        // Debug logging to file
        file_put_contents('var/log/pre_advice_debug.log', "=== VOTER canView CALLED ===\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "User type: " . get_class($user) . "\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "User ID: " . $user->getId() . "\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "User roles: " . implode(', ', $user->getRoles()) . "\n", FILE_APPEND);
        
        // Truckers can view their own pre-advice requests
        if ($user instanceof Trucker && $preAdviceRequest->getTrucker() === $user) {
            file_put_contents('var/log/pre_advice_debug.log', "VOTER: Trucker viewing own request - GRANTED\n", FILE_APPEND);
            return true;
        }

        // Terminal Team (StaffUser with ROLE_TERMINAL_TEAM or TerminalTeamUser) can view pre-advice requests for their shipping line
        $hasTerminalTeamRole = in_array('ROLE_TERMINAL_TEAM', $user->getRoles());
        if ($hasTerminalTeamRole || $user instanceof TerminalTeamUser) {
            file_put_contents('var/log/pre_advice_debug.log', "VOTER: User has ROLE_TERMINAL_TEAM or is TerminalTeamUser\n", FILE_APPEND);
            
            // Get the shipping line scope
            $shippingLine = $user->getShippingLineScope();
            file_put_contents('var/log/pre_advice_debug.log', "VOTER: User shipping line scope: " . ($shippingLine ? $shippingLine->getId() : 'NULL') . "\n", FILE_APPEND);
            
            // If no shipping line scope, deny access
            if ($shippingLine === null) {
                file_put_contents('var/log/pre_advice_debug.log', "VOTER: No shipping line scope - DENIED\n", FILE_APPEND);
                return false;
            }
            
            // Get pre-advice shipping line
            $preAdviceShippingLine = $preAdviceRequest->getShippingLine();
            file_put_contents('var/log/pre_advice_debug.log', "VOTER: Pre-advice shipping line: " . ($preAdviceShippingLine ? $preAdviceShippingLine->getId() : 'NULL') . "\n", FILE_APPEND);
            
            // Check if the pre-advice request belongs to the same shipping line
            if ($preAdviceShippingLine && $preAdviceShippingLine->getId() === $shippingLine->getId()) {
                file_put_contents('var/log/pre_advice_debug.log', "VOTER: Shipping lines match - GRANTED\n", FILE_APPEND);
                return true;
            }
            
            file_put_contents('var/log/pre_advice_debug.log', "VOTER: Shipping lines don't match - DENIED\n", FILE_APPEND);
            return false;
        }

        file_put_contents('var/log/pre_advice_debug.log', "VOTER: User doesn't have ROLE_TERMINAL_TEAM - DENIED\n", FILE_APPEND);
        return false;
    }

    private function canEdit(PreAdviceRequest $preAdviceRequest, User $user): bool
    {
        // Only truckers can edit their own pending pre-advice requests
        if ($user instanceof Trucker 
            && $preAdviceRequest->getTrucker() === $user 
            && $preAdviceRequest->getStatus()->value === 'pending') {
            return true;
        }

        return false;
    }

    private function canVerify(PreAdviceRequest $preAdviceRequest, User $user): bool
    {
        // Debug logging
        file_put_contents('var/log/pre_advice_debug.log', "=== VOTER canVerify CALLED ===\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "User type: " . get_class($user) . "\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "User ID: " . $user->getId() . "\n", FILE_APPEND);
        file_put_contents('var/log/pre_advice_debug.log', "Pre-advice status: " . $preAdviceRequest->getStatus()->value . "\n", FILE_APPEND);
        
        // Only Terminal Team can verify pre-advice requests
        $hasTerminalTeamRole = in_array('ROLE_TERMINAL_TEAM', $user->getRoles());
        if (!$hasTerminalTeamRole && !$user instanceof TerminalTeamUser) {
            file_put_contents('var/log/pre_advice_debug.log', "VOTER: User doesn't have ROLE_TERMINAL_TEAM - DENIED\n", FILE_APPEND);
            return false;
        }

        // Can only verify pending requests
        if ($preAdviceRequest->getStatus()->value !== 'pending') {
            file_put_contents('var/log/pre_advice_debug.log', "VOTER: Pre-advice is not pending - DENIED\n", FILE_APPEND);
            return false;
        }

        // For now, allow all terminal team users to verify
        // TODO: Implement proper permission checking once user permissions are set up
        file_put_contents('var/log/pre_advice_debug.log', "VOTER: Access GRANTED\n", FILE_APPEND);
        return true;
        
        // Check if user has verification permission
        // return $user->hasTerminalPermission('verify_pre_advice');
    }

    private function canReject(PreAdviceRequest $preAdviceRequest, User $user): bool
    {
        // Only Terminal Team can reject pre-advice requests
        $hasTerminalTeamRole = in_array('ROLE_TERMINAL_TEAM', $user->getRoles());
        if (!$hasTerminalTeamRole && !$user instanceof TerminalTeamUser) {
            return false;
        }

        // Can only reject pending requests
        if ($preAdviceRequest->getStatus()->value !== 'pending') {
            return false;
        }

        // For now, allow all terminal team users to reject
        // TODO: Implement proper permission checking once user permissions are set up
        return true;
        
        // Check if user has rejection permission
        // return $user->hasTerminalPermission('reject_pre_advice');
    }

    private function canGenerateEdo(PreAdviceRequest $preAdviceRequest, User $user): bool
    {
        // Only Terminal Team can generate EDOs
        $hasTerminalTeamRole = in_array('ROLE_TERMINAL_TEAM', $user->getRoles());
        if (!$hasTerminalTeamRole && !$user instanceof TerminalTeamUser) {
            return false;
        }

        // Can only generate EDO for verified requests
        if ($preAdviceRequest->getStatus()->value !== 'verified') {
            return false;
        }

        // For now, allow all terminal team users to generate EDOs
        // TODO: Implement proper permission checking once user permissions are set up
        return true;
        
        // Check if user has EDO generation permission
        // return $user->hasTerminalPermission('generate_edo');
    }
}