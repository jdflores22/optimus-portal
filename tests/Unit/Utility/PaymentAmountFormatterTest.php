<?php

namespace App\Tests\Unit\Utility;

use App\Entity\Billing;
use App\Entity\Payment;
use App\Utility\PaymentAmountFormatter;
use PHPUnit\Framework\TestCase;

class PaymentAmountFormatterTest extends TestCase
{
    public function testFormatPhpAmount(): void
    {
        $this->assertSame('₱1,234.56', PaymentAmountFormatter::format(1234.56, 'PHP'));
    }

    public function testFormatUsdAmount(): void
    {
        $this->assertSame('$99.00', PaymentAmountFormatter::format(99, 'USD'));
    }

    public function testFormatPaymentUsesPaymentCurrency(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAmount')->willReturn(500.0);
        $payment->method('getCurrency')->willReturn('USD');

        $this->assertSame('$500.00', PaymentAmountFormatter::formatPayment($payment));
    }

    public function testFormatBillingUsesUsdWhenOriginalCurrencyIsUsd(): void
    {
        $billing = $this->createMock(Billing::class);
        $billing->method('getOriginalCurrency')->willReturn('USD');
        $billing->method('getTotalAmountUsd')->willReturn(1500.25);
        $billing->method('getTotalAmount')->willReturn(85000.0);

        $this->assertSame('$1,500.25', PaymentAmountFormatter::formatBilling($billing));
    }

    public function testFormatBillingChargePrefersUsdField(): void
    {
        $billing = $this->createMock(Billing::class);
        $billing->method('getOriginalCurrency')->willReturn('USD');

        $this->assertSame(
            '$200.00',
            PaymentAmountFormatter::formatBillingCharge($billing, 11000.0, 200.0)
        );
    }
}
