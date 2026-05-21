<?php

namespace App\Entity\Enum;

enum EDOStatus: string
{
    case PENDING_RELEASE = 'pending_release';
    case PENDING_VALIDATION = 'pending_validation';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case LOCKED = 'locked';
    case RELEASED = 'released';
    case REJECTED = 'rejected';
    case SUPERSEDED = 'superseded';

    public function getDisplayName(): string
    {
        return match($this) {
            self::PENDING_RELEASE => 'Pending Release',
            self::PENDING_VALIDATION => 'Pending Validation',
            self::ACTIVE => 'Active',
            self::EXPIRED => 'Expired',
            self::LOCKED => 'Locked',
            self::RELEASED => 'Released',
            self::REJECTED => 'Rejected',
            self::SUPERSEDED => 'Superseded',
        };
    }
}
