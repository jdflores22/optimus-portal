<?php

namespace App\Entity\Enum;

enum AccountStatus: string
{
    case PENDING = 'PENDING';
    case EMAIL_UNVERIFIED = 'EMAIL_UNVERIFIED';
    case APPROVED = 'APPROVED';
    case DENIED = 'DENIED';
    case LOCKED = 'LOCKED';
}
