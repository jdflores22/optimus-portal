<?php

namespace App\Entity\Enum;

enum ReportType: string
{
    case PRE_ADVICE_STATISTICS = 'pre_advice_statistics';
    case TERMINAL_UTILIZATION = 'terminal_utilization';
    case APPROVAL_ANALYTICS = 'approval_analytics';
    case COMPREHENSIVE = 'comprehensive';
}