<?php

namespace App\Entity\Enum;

enum UserRole: string
{
    case CONSIGNEE = 'CONSIGNEE';
    case BROKER = 'BROKER';
    case EVALUATOR = 'EVALUATOR';
    case SHIPPING_LINES_ADMIN = 'SHIPPING_LINES_ADMIN';
    case SL_STAFF = 'SL_STAFF';
    case ACCOUNTING = 'ACCOUNTING';
    case SYSTEM_ADMIN = 'SYSTEM_ADMIN';
    case TRUCKER = 'TRUCKER';
    case TERMINAL_TEAM = 'TERMINAL_TEAM';
}
