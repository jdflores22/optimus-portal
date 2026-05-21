<?php

namespace App\Entity\Enum;

enum EDOPaymentReceiptStatus: string
{
    case SUBMITTED = 'submitted';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';

    public function getDisplayName(): string
    {
        return match($this) {
            self::SUBMITTED => 'Submitted',
            self::CONFIRMED => 'Confirmed',
            self::REJECTED => 'Rejected',
        };
    }
}
