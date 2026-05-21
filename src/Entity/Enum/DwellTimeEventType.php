<?php

namespace App\Entity\Enum;

enum DwellTimeEventType: string
{
    case ARRIVAL = 'arrival';
    case PAUSE = 'pause';
    case RESUME = 'resume';
    case NOTIFICATION_60_DAY = 'notification_60_day';
    case AUTOMATIC_RETURN = 'automatic_return';
    case MANUAL_CALCULATION = 'manual_calculation';
    case STATUS_CHANGE = 'status_change';
}