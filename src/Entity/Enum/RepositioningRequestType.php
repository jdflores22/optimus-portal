<?php

namespace App\Entity\Enum;

enum RepositioningRequestType: string
{
    case EXPORT = 'export';
    case REPOSITIONING = 'repositioning';

    public function label(): string
    {
        return match ($this) {
            self::EXPORT => 'Export',
            self::REPOSITIONING => 'Repositioning',
        };
    }
}
