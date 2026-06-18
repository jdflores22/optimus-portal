<?php

namespace App\Entity\Enum;

enum AccreditationStatus: string
{
    case PENDING = 'PENDING';
    /** Evaluator recommended approval; waiting for Shipping Lines Admin final sign-off */
    case AWAITING_FINAL_APPROVAL = 'AWAITING_FINAL_APPROVAL';
    case APPROVED = 'APPROVED';
    case DENIED = 'DENIED';
    case REJECTED = 'REJECTED';
    case COMPLIANCE_REQUIRED = 'COMPLIANCE_REQUIRED';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Review',
            self::AWAITING_FINAL_APPROVAL => 'Awaiting Final Approval',
            self::APPROVED => 'Approved',
            self::DENIED => 'Denied',
            self::REJECTED => 'Rejected',
            self::COMPLIANCE_REQUIRED => 'Compliance Required',
        };
    }
}
