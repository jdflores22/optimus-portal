<?php

namespace App\Entity\Enum;

enum ReportFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
}