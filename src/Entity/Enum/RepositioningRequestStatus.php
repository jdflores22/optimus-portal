<?php

namespace App\Entity\Enum;

enum RepositioningRequestStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case IN_TRANSIT = 'in_transit';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::IN_TRANSIT => 'In Transit to Port',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning',
            self::APPROVED => 'badge-info',
            self::REJECTED => 'badge-error',
            self::IN_TRANSIT => 'badge-primary',
            self::COMPLETED => 'badge-success',
            self::CANCELLED => 'badge-neutral',
        };
    }
}
