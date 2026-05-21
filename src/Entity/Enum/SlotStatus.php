<?php

namespace App\Entity\Enum;

enum SlotStatus: string
{
    case AVAILABLE = 'available';
    case FULL = 'full';
    case BLOCKED = 'blocked';
}