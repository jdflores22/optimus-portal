<?php

namespace App\Tests\Security;

use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\UserRole;
use App\Entity\PreAdviceRequest;
use App\Entity\Terminal;
use App\Entity\TerminalTeamUser;
use App\Entity\Trucker;
use App\Security\Voter\PreAdviceVoter;
use App\Security\Voter\TerminalTeamDashboardVoter;
use App\Security\Voter\TerminalVoter;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * **Feature: terminal-team-pre-advice, Property 2: Role-based access control**
 * **Validates: Requirements 1.3**
 * 
 * Property: For any Terminal Team user and any non-pre-advice system function, 
 * the user should be denied access to that function
 */
class RoleBasedAccessControlPropertyTest extends TestCase
{
    use TestTrait;

    private PreAdviceVoter $preAdviceVoter;
    private TerminalVoter $terminalVoter;
    private TerminalTeamDashboardVoter $dashboardVoter;
    private AccessDecisionManager $accessDecisionManager;

    protected function setUp(): void
    {
        $this->preAdviceVoter = new PreAdviceVoter();
        $this->terminalVoter = new TerminalVoter();
        $this->dashboardVoter = new TerminalTeamDashboardVoter();
        
        $this->accessDecisionManager = new AccessDecisionManager([
            $this->preAdviceVoter,
            $this->terminalVoter,
            $this->dashboardVoter
        ]);
    }

    /**
     * Property: Terminal Team users should only have access to pre-advice related functions
     */
    public function testTerminalTeamAccessControlProperty(): void
    {
        $this->forAll(
            Generator\choose(0, 100), // Random seed for user generation
            Generator\elements(['verify_pre_advice', 'reject_pre_advice', 'generate_edo', 'manage_slots', 'view_metrics']),
            Generator\elements([PreAdviceStatus::PENDING, PreAdviceStatus::VERIFIED, PreAdviceStatus::REJECTED])
        )->then(function (int $seed, string $permission, PreAdviceStatus $requestStatus) {
            // Generate Terminal Team user with random permissions
            $terminalTeamUser = $this->createTerminalTeamUser($seed, [$permission]);
            $token = new UsernamePasswordToken($terminalTeamUser, 'main', $terminalTeamUser->getRoles());

            // Create test entities
            $preAdviceRequest = $this->createPreAdviceRequest($requestStatus);
            $terminal = $this->createTerminal();

            // Test PreAdvice operations
            $this->assertPreAdviceAccessControl($token, $preAdviceRequest, $terminalTeamUser, $permission, $requestStatus);

            // Test Terminal operations
            $this->assertTerminalAccessControl($token, $terminal, $terminalTeamUser, $permission);

            // Test Dashboard operations
            $this->assertDashboardAccessControl($token, $terminalTeamUser, $permission);
        });
    }

    /**
     * Property: Truckers should only have access to their own pre-advice requests
     */
    public function testTruckerAccessControlProperty(): void
    {
        $this->forAll(
            Generator\choose(0, 100), // Random seed for user generation
            Generator\elements([PreAdviceStatus::PENDING, PreAdviceStatus::VERIFIED, PreAdviceStatus::REJECTED])
        )->then(function (int $seed, PreAdviceStatus $requestStatus) {
            // Generate Trucker user
            $trucker = $this->createTrucker($seed);
            $otherTrucker = $this->createTrucker($seed + 1);
            $token = new UsernamePasswordToken($trucker, 'main', $trucker->getRoles());

            // Create pre-advice requests
            $ownRequest = $this->createPreAdviceRequestForTrucker($trucker, $requestStatus);
            $otherRequest = $this->createPreAdviceRequestForTrucker($otherTrucker, $requestStatus);

            // Truckers should be able to view their own requests
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $ownRequest, [PreAdviceVoter::VIEW]) === VoterInterface::ACCESS_GRANTED,
                'Trucker should be able to view their own pre-advice request'
            );

            // Truckers should NOT be able to view other truckers' requests
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $otherRequest, [PreAdviceVoter::VIEW]) === VoterInterface::ACCESS_DENIED,
                'Trucker should NOT be able to view other truckers\' pre-advice requests'
            );

            // Truckers should NOT be able to verify any requests
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $ownRequest, [PreAdviceVoter::VERIFY]) === VoterInterface::ACCESS_DENIED,
                'Trucker should NOT be able to verify pre-advice requests'
            );

            // Truckers should NOT be able to generate EDOs
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $ownRequest, [PreAdviceVoter::GENERATE_EDO]) === VoterInterface::ACCESS_DENIED,
                'Trucker should NOT be able to generate EDOs'
            );
        });
    }

    /**
     * Property: Users without proper permissions should be denied access
     */
    public function testPermissionBasedAccessControlProperty(): void
    {
        $this->forAll(
            Generator\choose(0, 100), // Random seed
            Generator\elements(['verify_pre_advice', 'reject_pre_advice', 'generate_edo', 'manage_slots']),
            Generator\elements([PreAdviceStatus::PENDING, PreAdviceStatus::VERIFIED])
        )->then(function (int $seed, string $requiredPermission, PreAdviceStatus $requestStatus) {
            // Create user WITHOUT the required permission
            $terminalTeamUser = $this->createTerminalTeamUser($seed, []); // No permissions
            $token = new UsernamePasswordToken($terminalTeamUser, 'main', $terminalTeamUser->getRoles());

            $preAdviceRequest = $this->createPreAdviceRequest($requestStatus);
            $terminal = $this->createTerminal();

            // Map permissions to voter actions
            $actionMap = [
                'verify_pre_advice' => PreAdviceVoter::VERIFY,
                'reject_pre_advice' => PreAdviceVoter::REJECT,
                'generate_edo' => PreAdviceVoter::GENERATE_EDO,
                'manage_slots' => TerminalVoter::MANAGE_SLOTS,
            ];

            if (isset($actionMap[$requiredPermission])) {
                $action = $actionMap[$requiredPermission];
                
                // Determine subject and voter based on action
                if (in_array($action, [PreAdviceVoter::VERIFY, PreAdviceVoter::REJECT, PreAdviceVoter::GENERATE_EDO])) {
                    $subject = $preAdviceRequest;
                    $voter = $this->preAdviceVoter;
                } else {
                    $subject = $terminal;
                    $voter = $this->terminalVoter;
                }

                // User without permission should be denied access
                $this->assertTrue(
                    $voter->vote($token, $subject, [$action]) === VoterInterface::ACCESS_DENIED,
                    "User without '{$requiredPermission}' permission should be denied access to {$action}"
                );
            }
        });
    }

    private function assertPreAdviceAccessControl(
        UsernamePasswordToken $token,
        PreAdviceRequest $preAdviceRequest,
        TerminalTeamUser $user,
        string $permission,
        PreAdviceStatus $status
    ): void {
        // Test verification access
        if ($permission === 'verify_pre_advice' && $status === PreAdviceStatus::PENDING) {
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $preAdviceRequest, [PreAdviceVoter::VERIFY]) === VoterInterface::ACCESS_GRANTED,
                'Terminal Team user with verify permission should be able to verify pending requests'
            );
        } else {
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $preAdviceRequest, [PreAdviceVoter::VERIFY]) === VoterInterface::ACCESS_DENIED,
                'Terminal Team user should not be able to verify non-pending requests or without permission'
            );
        }

        // Test EDO generation access
        if ($permission === 'generate_edo' && $status === PreAdviceStatus::VERIFIED) {
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $preAdviceRequest, [PreAdviceVoter::GENERATE_EDO]) === VoterInterface::ACCESS_GRANTED,
                'Terminal Team user with EDO permission should be able to generate EDO for verified requests'
            );
        } else {
            $this->assertTrue(
                $this->preAdviceVoter->vote($token, $preAdviceRequest, [PreAdviceVoter::GENERATE_EDO]) === VoterInterface::ACCESS_DENIED,
                'Terminal Team user should not be able to generate EDO for non-verified requests or without permission'
            );
        }
    }

    private function assertTerminalAccessControl(
        UsernamePasswordToken $token,
        Terminal $terminal,
        TerminalTeamUser $user,
        string $permission
    ): void {
        // Test slot management access
        if ($permission === 'manage_slots') {
            $this->assertTrue(
                $this->terminalVoter->vote($token, $terminal, [TerminalVoter::MANAGE_SLOTS]) === VoterInterface::ACCESS_GRANTED,
                'Terminal Team user with manage_slots permission should be able to manage slots'
            );
        } else {
            $this->assertTrue(
                $this->terminalVoter->vote($token, $terminal, [TerminalVoter::MANAGE_SLOTS]) === VoterInterface::ACCESS_DENIED,
                'Terminal Team user without manage_slots permission should not be able to manage slots'
            );
        }

        // Test metrics viewing access
        if ($permission === 'view_metrics') {
            $this->assertTrue(
                $this->terminalVoter->vote($token, $terminal, [TerminalVoter::VIEW_METRICS]) === VoterInterface::ACCESS_GRANTED,
                'Terminal Team user with view_metrics permission should be able to view metrics'
            );
        } else {
            $this->assertTrue(
                $this->terminalVoter->vote($token, $terminal, [TerminalVoter::VIEW_METRICS]) === VoterInterface::ACCESS_DENIED,
                'Terminal Team user without view_metrics permission should not be able to view metrics'
            );
        }
    }

    private function assertDashboardAccessControl(
        UsernamePasswordToken $token,
        TerminalTeamUser $user,
        string $permission
    ): void {
        // All Terminal Team users should be able to access dashboard
        $this->assertTrue(
            $this->dashboardVoter->vote($token, null, [TerminalTeamDashboardVoter::ACCESS_DASHBOARD]) === VoterInterface::ACCESS_GRANTED,
            'All Terminal Team users should be able to access dashboard'
        );
    }

    private function createTerminalTeamUser(int $seed, array $permissions): TerminalTeamUser
    {
        $user = new TerminalTeamUser();
        $user->setId($seed);
        $user->setEmail("terminal{$seed}@example.com");
        $user->setRole(UserRole::TERMINAL_TEAM);
        $user->setStatus(AccountStatus::APPROVED);
        $user->setPasswordHash('hashed_password');
        $user->setFirstName("Terminal{$seed}");
        $user->setLastName("User");
        $user->setDepartment("Terminal Operations");
        $user->setTerminalPermissions($permissions);
        
        return $user;
    }

    private function createTrucker(int $seed): Trucker
    {
        $trucker = new Trucker();
        $trucker->setId($seed);
        $trucker->setEmail("trucker{$seed}@example.com");
        $trucker->setRole(UserRole::TRUCKER);
        $trucker->setStatus(AccountStatus::APPROVED);
        $trucker->setPasswordHash('hashed_password');
        $trucker->setFirstName("Trucker{$seed}");
        $trucker->setLastName("User");
        
        return $trucker;
    }

    private function createPreAdviceRequest(PreAdviceStatus $status): PreAdviceRequest
    {
        $request = new PreAdviceRequest();
        $request->setStatus($status);
        $request->setPaymentReference('PAY123');
        
        return $request;
    }

    private function createPreAdviceRequestForTrucker(Trucker $trucker, PreAdviceStatus $status): PreAdviceRequest
    {
        $request = $this->createPreAdviceRequest($status);
        $request->setTrucker($trucker);
        
        return $request;
    }

    private function createTerminal(): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName('Test Terminal');
        $terminal->setType(\App\Entity\Enum\TerminalType::CY);
        $terminal->setLocation('Test Location');
        $terminal->setDailyCapacity(100);
        
        return $terminal;
    }
}