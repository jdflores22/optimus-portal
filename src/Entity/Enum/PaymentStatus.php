<?php

namespace App\Entity\Enum;

enum PaymentStatus: string
{
    case PENDING_VALIDATION = 'pending_validation';
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';
}
