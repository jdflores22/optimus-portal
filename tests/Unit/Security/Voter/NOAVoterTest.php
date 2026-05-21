<?php

namespace App\Tests\Unit\Security\Voter;

use App\Entity\NOA;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Security\Voter\NOAVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class NOAVoterTest extends TestCase
{
    private NOAVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new NOAVoter();
    }

    public function testShippingLinesTerminalTeamCanCreateNOA(): void
    {
        $user = $this->createUser(UserRole::TERMINAL_TEAM);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'NOA', ['create']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testBrokerCannotCreateNOA(): void
    {
        $user = $this->createUser(UserRole::BROKER);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'NOA', ['create']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testConsigneeCannotCreateNOA(): void
    {
        $user = $this->createUser(UserRole::CONSIGNEE);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'NOA', ['create']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testSystemAdminCanViewNOA(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $noa = $this->createMock(NOA::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $noa, ['view']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testShippingLinesTerminalTeamCanEditNOA(): void
    {
        $user = $this->createUser(UserRole::TERMINAL_TEAM);
        $noa = $this->createMock(NOA::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $noa, ['edit']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testBrokerCannotEditNOA(): void
    {
        $user = $this->createUser(UserRole::BROKER);
        $noa = $this->createMock(NOA::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $noa, ['edit']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    private function createUser(UserRole $role): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn($role);
        return $user;
    }

    private function createToken(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }
}
