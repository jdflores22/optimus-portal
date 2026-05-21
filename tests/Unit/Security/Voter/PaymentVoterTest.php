<?php

namespace App\Tests\Unit\Security\Voter;

use App\Entity\EDOPaymentReceipt;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Security\Voter\PaymentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class PaymentVoterTest extends TestCase
{
    private PaymentVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PaymentVoter();
    }

    public function testShippingLinesAccountingCanConfirmPayment(): void
    {
        $user = $this->createUser(UserRole::ACCOUNTING);
        $payment = $this->createMock(EDOPaymentReceipt::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $payment, ['confirm']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testBrokerCannotConfirmPayment(): void
    {
        $user = $this->createUser(UserRole::BROKER);
        $payment = $this->createMock(EDOPaymentReceipt::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $payment, ['confirm']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testShippingLinesAccountingCanRejectPayment(): void
    {
        $user = $this->createUser(UserRole::ACCOUNTING);
        $payment = $this->createMock(EDOPaymentReceipt::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $payment, ['reject']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testConsigneeCannotRejectPayment(): void
    {
        $user = $this->createUser(UserRole::CONSIGNEE);
        $payment = $this->createMock(EDOPaymentReceipt::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $payment, ['reject']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testBrokerCanSubmitPayment(): void
    {
        $user = $this->createUser(UserRole::BROKER);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'Payment', ['submit']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testConsigneeCanSubmitPayment(): void
    {
        $user = $this->createUser(UserRole::CONSIGNEE);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'Payment', ['submit']);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testSystemAdminCannotSubmitPayment(): void
    {
        $user = $this->createUser(UserRole::SYSTEM_ADMIN);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, 'Payment', ['submit']);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testShippingLinesAccountingCanViewPayment(): void
    {
        $user = $this->createUser(UserRole::ACCOUNTING);
        $payment = $this->createMock(EDOPaymentReceipt::class);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $payment, ['view']);

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
