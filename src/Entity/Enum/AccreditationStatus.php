<?php

namespace App\Entity\Enum;

enum AccreditationStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case DENIED = 'DENIED';
    case REJECTED = 'REJECTED';
    case COMPLIANCE_REQUIRED = 'COMPLIANCE_REQUIRED';
}
