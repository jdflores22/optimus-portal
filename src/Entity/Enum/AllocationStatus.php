<?php

namespace App\Entity\Enum;

enum AllocationStatus: string
{
    case PRE_FORECAST = 'pre_forecast';
    case ALLOCATED = 'allocated';
    case RELEASED = 'released';
}
