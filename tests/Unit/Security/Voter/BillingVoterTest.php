<?php

namespace App\Tests\Unit\Security\Voter;

use App\Entity\EDOBilling;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Security\Voter\BillingVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class BillingVoterTest extends TestCase
{
    private BillingVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new BillingVoter();
    }

    public function testShippingLinesAccountingCanGenerateBilling(): void
    {
        $user = $this->createUser(UserRole::ACCOUNTING);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'Billing', ['generate']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testBrokerCannotGenerateBilling(): void
    {
        $user = $this->createUser(UserRole::BROKER);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'Billing', ['generate']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testConsigneeCannotGenerateBilling(): void
    {
        $user = $this->createUser(UserRole::CONSIGNEE);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'Billing', ['generate']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testSystemAdminCannotGenerateBilling(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'Billing', ['generate']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testShippingLinesAccountingCanViewBilling(): void
    {
        $user = $this->createUser(UserRole::ACCOUNTING);
        $billing = $this->createMock(EDOBilling::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $billing, ['view']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testSystemAdminCanViewBilling(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $billing = $this->createMock(EDOBilling::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $billing, ['view']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
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
