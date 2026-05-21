<?php

namespace App\Entity\Enum;

enum AuditEventType: string
{
    case EDO_CREATED = 'edo_created';
    case EDO_EXPIRED = 'edo_expired';
    case REGENERATION_REQUESTED = 'regeneration_requested';
    case BILLING_GENERATED = 'billing_generated';
    case PAYMENT_SUBMITTED = 'payment_submitted';
    case PAYMENT_CONFIRMED = 'payment_confirmed';
    case PAYMENT_REJECTED = 'payment_rejected';
    case ADMIN_UNLOCKED = 'admin_unlocked';
    case EDO_RELEASED = 'edo_released';
    case BATCH_GENERATION_STARTED = 'batch_generation_started';
    case BATCH_GENERATION_COMPLETED = 'batch_generation_completed';
    case BATCH_GENERATION_CANCELLED = 'batch_generation_cancelled';

    public function getDisplayName(): string
    {
        return match($this) {
            self::EDO_CREATED => 'eDO Created',
            self::EDO_EXPIRED => 'eDO Expired',
            self::REGENERATION_REQUESTED => 'Regeneration Requested',
            self::BILLING_GENERATED => 'Billing Generated',
            self::PAYMENT_SUBMITTED => 'Payment Submitted',
            self::PAYMENT_CONFIRMED => 'Payment Confirmed',
            self::PAYMENT_REJECTED => 'Payment Rejected',
            self::ADMIN_UNLOCKED => 'Admin Unlocked',
            self::EDO_RELEASED => 'eDO Released',
            self::BATCH_GENERATION_STARTED => 'Batch Generation Started',
            self::BATCH_GENERATION_COMPLETED => 'Batch Generation Completed',
            self::BATCH_GENERATION_CANCELLED => 'Batch Generation Cancelled',
        };
    }
}
