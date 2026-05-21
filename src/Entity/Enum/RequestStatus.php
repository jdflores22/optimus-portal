<?php

namespace App\Entity\Enum;

enum RequestStatus: string
{
    case SUBMITTED = 'submitted';
    case ROUTED_TO_ACCOUNTING = 'routed_to_accounting';
    case BILLING_GENERATED = 'billing_generated';
    case PAYMENT_SUBMITTED = 'payment_submitted';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function getDisplayName(): string
    {
        return match($this) {
            self::SUBMITTED => 'Submitted',
            self::ROUTED_TO_ACCOUNTING => 'Routed to Accounting',
            self::BILLING_GENERATED => 'Billing Generated',
            self::PAYMENT_SUBMITTED => 'Payment Submitted',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }
}
