<?php

namespace App\Entity\Enum;

enum FormStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
