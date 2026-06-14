<?php

namespace App\Utility;

use App\Entity\Billing;
use App\Entity\Payment;

class PaymentAmountFormatter
{
    public static function format(float $amount, ?string $currency = 'PHP'): string
    {
        $currency = strtoupper($currency ?: 'PHP');
        $prefix = $currency === 'USD' ? '$' : '₱';

        return $prefix . number_format($amount, 2);
    }

    public static function formatPayment(Payment $payment): string
    {
        return self::format($payment->getAmount(), $payment->getCurrency());
    }

    public static function formatBilling(Billing $billing): string
    {
        if ($billing->getOriginalCurrency() === 'USD') {
            return self::format($billing->getTotalAmountUsd() ?? $billing->getTotalAmount(), 'USD');
        }

        return self::format($billing->getTotalAmount(), 'PHP');
    }

    public static function formatBillingCharge(Billing $billing, float $phpAmount, ?float $usdAmount = null): string
    {
        if ($billing->getOriginalCurrency() === 'USD' && $usdAmount !== null) {
            return self::format($usdAmount, 'USD');
        }

        return self::format($phpAmount, 'PHP');
    }

    public static function formatEdoPaymentAmount(float $amount): string
    {
        return self::format($amount, 'PHP');
    }
}
