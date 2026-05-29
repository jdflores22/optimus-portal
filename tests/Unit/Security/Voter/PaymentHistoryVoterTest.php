<?php

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Enum\UserRole;
use App\Entity\Manifest;
use App\Entity\Payment;
use App\Entity\User;
use App\Security\Voter\PaymentHistoryVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class PaymentHistoryVoterTest extends TestCase
{
    private PaymentHistoryVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PaymentHistoryVoter();
    }

    public function testSupportsViewPaymentHistoryAttribute(): void
    {
        $manifest = $this->createMock(Manifest::class);
        
        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('supports');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest);
        
        $this->assertTrue($result);
    }

    public function testSupportsViewAttribute(): void
    {
        $payment = $this->createMock(Payment::class);
        
        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('supports');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $payment);
        
        $this->assertTrue($result);
    }

    public function testDoesNotSupportInvalidAttribute(): void
    {
        $manifest = $this->createMock(Manifest::class);
        
        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('supports');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->voter, 'invalid_attribute', $manifest);
        
        $this->assertFalse($result);
    }

    public function testDoesNotSupportInvalidSubject(): void
    {
        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('supports');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, new \stdClass());
        
        $this->assertFalse($result);
    }

    public function testAccountingCanViewPaymentHistory(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::ACCOUNTING);

        $manifest = $this->createMock(Manifest::class);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertTrue($result);
    }

    public function testSystemAdminCanViewPaymentHistory(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $manifest = $this->createMock(Manifest::class);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertTrue($result);
    }

    public function testShippingLinesAdminCanViewPaymentHistory(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SHIPPING_LINES_ADMIN);

        $manifest = $this->createMock(Manifest::class);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertTrue($result);
    }

    public function testBrokerCanViewOwnManifestPaymentHistory(): void
    {
        $broker = $this->createMock(\App\Entity\Broker::class);
        $broker->method('getRole')->willReturn(UserRole::BROKER);
        $broker->method('getId')->willReturn(1);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getBroker')->willReturn($broker);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($broker);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertTrue($result);
    }

    public function testBrokerCannotViewOtherBrokerManifestPaymentHistory(): void
    {
        $broker1 = $this->createMock(\App\Entity\Broker::class);
        $broker1->method('getRole')->willReturn(UserRole::BROKER);
        $broker1->method('getId')->willReturn(1);

        $broker2 = $this->createMock(\App\Entity\Broker::class);
        $broker2->method('getRole')->willReturn(UserRole::BROKER);
        $broker2->method('getId')->willReturn(2);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getBroker')->willReturn($broker2);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($broker1);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertFalse($result);
    }

    public function testConsigneeCanViewOwnManifestPaymentHistory(): void
    {
        $consignee = $this->createMock(\App\Entity\Consignee::class);
        $consignee->method('getRole')->willReturn(UserRole::CONSIGNEE);
        $consignee->method('getId')->willReturn(1);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getConsignee')->willReturn($consignee);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($consignee);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertTrue($result);
    }

    public function testConsigneeCannotViewOtherConsigneeManifestPaymentHistory(): void
    {
        $consignee1 = $this->createMock(\App\Entity\Consignee::class);
        $consignee1->method('getRole')->willReturn(UserRole::CONSIGNEE);
        $consignee1->method('getId')->willReturn(1);

        $consignee2 = $this->createMock(\App\Entity\Consignee::class);
        $consignee2->method('getRole')->willReturn(UserRole::CONSIGNEE);
        $consignee2->method('getId')->willReturn(2);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getConsignee')->willReturn($consignee2);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($consignee1);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertFalse($result);
    }

    public function testAccountingCanViewAnyPayment(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::ACCOUNTING);

        $manifest = $this->createMock(Manifest::class);

        $payment = $this->createMock(Payment::class);
        $payment->method('getManifest')->willReturn($manifest);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $payment, $token, null);

        $this->assertTrue($result);
    }

    public function testBrokerCanViewOwnManifestPayment(): void
    {
        $broker = $this->createMock(\App\Entity\Broker::class);
        $broker->method('getRole')->willReturn(UserRole::BROKER);
        $broker->method('getId')->willReturn(1);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getBroker')->willReturn($broker);

        $payment = $this->createMock(Payment::class);
        $payment->method('getManifest')->willReturn($manifest);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($broker);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $payment, $token, null);

        $this->assertTrue($result);
    }

    public function testBrokerCannotViewOtherBrokerPayment(): void
    {
        $broker1 = $this->createMock(\App\Entity\Broker::class);
        $broker1->method('getRole')->willReturn(UserRole::BROKER);
        $broker1->method('getId')->willReturn(1);

        $broker2 = $this->createMock(\App\Entity\Broker::class);
        $broker2->method('getRole')->willReturn(UserRole::BROKER);
        $broker2->method('getId')->willReturn(2);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getBroker')->willReturn($broker2);

        $payment = $this->createMock(Payment::class);
        $payment->method('getManifest')->willReturn($manifest);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($broker1);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $payment, $token, null);

        $this->assertFalse($result);
    }

    public function testConsigneeCanViewOwnManifestPayment(): void
    {
        $consignee = $this->createMock(\App\Entity\Consignee::class);
        $consignee->method('getRole')->willReturn(UserRole::CONSIGNEE);
        $consignee->method('getId')->willReturn(1);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getConsignee')->willReturn($consignee);

        $payment = $this->createMock(Payment::class);
        $payment->method('getManifest')->willReturn($manifest);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($consignee);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $payment, $token, null);

        $this->assertTrue($result);
    }

    public function testTruckerCannotViewPaymentHistory(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::TRUCKER);

        $manifest = $this->createMock(Manifest::class);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertFalse($result);
    }

    public function testNonUserCannotViewPaymentHistory(): void
    {
        $manifest = $this->createMock(Manifest::class);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW_PAYMENT_HISTORY, $manifest, $token, null);

        $this->assertFalse($result);
    }

    // ========================================================================
    // Payment Version Access Tests
    // These tests verify that payment versions follow the same authorization
    // rules as the original payment
    // ========================================================================

    public function testAccountingCanViewPaymentVersion(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::ACCOUNTING);

        $manifest = $this->createMock(Manifest::class);

        // Create a payment version (v2) with a previous payment
        $previousPayment = $this->createMock(Payment::class);
        $previousPayment->method('getVersion')->willReturn(1);

        $paymentVersion = $this->createMock(Payment::class);
        $paymentVersion->method('getManifest')->willReturn($manifest);
        $paymentVersion->method('getVersion')->willReturn(2);
        $paymentVersion->method('getPreviousPayment')->willReturn($previousPayment);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $paymentVersion, $token, null);

        $this->assertTrue($result, 'Accounting should be able to view payment versions');
    }

    public function testBrokerCanViewOwnPaymentVersion(): void
    {
        $broker = $this->createMock(\App\Entity\Broker::class);
        $broker->method('getRole')->willReturn(UserRole::BROKER);
        $broker->method('getId')->willReturn(1);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getBroker')->willReturn($broker);

        // Create a payment version (v3) with a previous payment
        $previousPayment = $this->createMock(Payment::class);
        $previousPayment->method('getVersion')->willReturn(2);

        $paymentVersion = $this->createMock(Payment::class);
        $paymentVersion->method('getManifest')->willReturn($manifest);
        $paymentVersion->method('getVersion')->willReturn(3);
        $paymentVersion->method('getPreviousPayment')->willReturn($previousPayment);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($broker);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $paymentVersion, $token, null);

        $this->assertTrue($result, 'Broker should be able to view their own payment versions');
    }

    public function testBrokerCannotViewOtherBrokerPaymentVersion(): void
    {
        $broker1 = $this->createMock(\App\Entity\Broker::class);
        $broker1->method('getRole')->willReturn(UserRole::BROKER);
        $broker1->method('getId')->willReturn(1);

        $broker2 = $this->createMock(\App\Entity\Broker::class);
        $broker2->method('getRole')->willReturn(UserRole::BROKER);
        $broker2->method('getId')->willReturn(2);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getBroker')->willReturn($broker2);

        // Create a payment version (v2) belonging to broker2
        $previousPayment = $this->createMock(Payment::class);
        $previousPayment->method('getVersion')->willReturn(1);

        $paymentVersion = $this->createMock(Payment::class);
        $paymentVersion->method('getManifest')->willReturn($manifest);
        $paymentVersion->method('getVersion')->willReturn(2);
        $paymentVersion->method('getPreviousPayment')->willReturn($previousPayment);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($broker1);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $paymentVersion, $token, null);

        $this->assertFalse($result, 'Broker should not be able to view other broker payment versions');
    }

    public function testConsigneeCanViewOwnPaymentVersion(): void
    {
        $consignee = $this->createMock(\App\Entity\Consignee::class);
        $consignee->method('getRole')->willReturn(UserRole::CONSIGNEE);
        $consignee->method('getId')->willReturn(1);

        $manifest = $this->createMock(Manifest::class);
        $manifest->method('getConsignee')->willReturn($consignee);

        // Create a payment version (v2)
        $previousPayment = $this->createMock(Payment::class);
        $previousPayment->method('getVersion')->willReturn(1);

        $paymentVersion = $this->createMock(Payment::class);
        $paymentVersion->method('getManifest')->willReturn($manifest);
        $paymentVersion->method('getVersion')->willReturn(2);
        $paymentVersion->method('getPreviousPayment')->willReturn($previousPayment);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($consignee);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $paymentVersion, $token, null);

        $this->assertTrue($result, 'Consignee should be able to view their own payment versions');
    }

    public function testSystemAdminCanViewAnyPaymentVersion(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);

        $manifest = $this->createMock(Manifest::class);

        // Create a payment version (v4)
        $previousPayment = $this->createMock(Payment::class);
        $previousPayment->method('getVersion')->willReturn(3);

        $paymentVersion = $this->createMock(Payment::class);
        $paymentVersion->method('getManifest')->willReturn($manifest);
        $paymentVersion->method('getVersion')->willReturn(4);
        $paymentVersion->method('getPreviousPayment')->willReturn($previousPayment);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $paymentVersion, $token, null);

        $this->assertTrue($result, 'System admin should be able to view any payment version');
    }

    public function testShippingLinesAdminCanViewAnyPaymentVersion(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn(UserRole::SHIPPING_LINES_ADMIN);

        $manifest = $this->createMock(Manifest::class);

        // Create a payment version (v2)
        $previousPayment = $this->createMock(Payment::class);
        $previousPayment->method('getVersion')->willReturn(1);

        $paymentVersion = $this->createMock(Payment::class);
        $paymentVersion->method('getManifest')->willReturn($manifest);
        $paymentVersion->method('getVersion')->willReturn(2);
        $paymentVersion->method('getPreviousPayment')->willReturn($previousPayment);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $reflection = new \ReflectionClass($this->voter);
        $method = $reflection->getMethod('voteOnAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->voter, PaymentHistoryVoter::VIEW, $paymentVersion, $token, null);

        $this->assertTrue($result, 'Shipping lines admin should be able to view any payment version');
    }
}

