<?php

namespace App\Entity\Enum;

enum ContainerStatus: string
{
    case PENDING = 'pending';
    case AVAILABLE_FOR_RETURN = 'available_for_return';
    case PA_APPROVED = 'pa_approved';
    case IN_TRANSIT = 'in_transit';
    case AT_TERMINAL = 'at_terminal';
    case RETURNED = 'returned';
    case MAINTENANCE = 'maintenance';
    case ALERT = 'alert';
}