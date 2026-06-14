<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Billing;
use App\Utility\PaymentAmountFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class PaymentAmountExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_money', [PaymentAmountFormatter::class, 'format']),
            new TwigFilter('format_payment', [PaymentAmountFormatter::class, 'formatPayment']),
            new TwigFilter('format_billing', [PaymentAmountFormatter::class, 'formatBilling']),
            new TwigFilter('format_billing_charge', [$this, 'formatBillingCharge']),
        ];
    }

    public function formatBillingCharge(Billing $billing, float $phpAmount, ?float $usdAmount = null): string
    {
        return PaymentAmountFormatter::formatBillingCharge($billing, $phpAmount, $usdAmount);
    }
}
