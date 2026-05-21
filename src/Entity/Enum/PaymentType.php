<?php

namespace App\Entity\Enum;

enum PaymentType: string
{
    case MANIFEST_ACCESS = 'manifest_access';
    case FINAL_PAYMENT = 'final_payment';

    public function getFixedAmount(): ?float
    {
        return match($this) {
            self::MANIFEST_ACCESS => null, // Dynamic - configured by SYSTEM_ADMIN
            self::FINAL_PAYMENT => null, // Variable based on billing
        };
    }
}
