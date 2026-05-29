<?php

namespace App\Entity\Enum;

enum RenewalRequestStatus: string
{
    case PENDING_REVIEW = 'pending_review';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PAYMENT_SUBMITTED = 'payment_submitted';
    case PAYMENT_VERIFIED = 'payment_verified';
    case READY_FOR_GENERATION = 'ready_for_generation';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function getDisplayName(): string
    {
        return match($this) {
            self::PENDING_REVIEW => 'Pending Review',
            self::AWAITING_PAYMENT => 'Awaiting Payment',
            self::PAYMENT_SUBMITTED => 'Payment Submitted',
            self::PAYMENT_VERIFIED => 'Payment Verified',
            self::READY_FOR_GENERATION => 'Ready for Generation',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }
}
